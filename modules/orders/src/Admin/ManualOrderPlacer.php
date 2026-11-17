<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\Orders;

/**
 * «Crear el pedido» del pedido a mano. El pedido lo crea Orders::place(), el mismo de la caja: existencias, cupones, número,
 * factura cuando se cobra, correos, eventos para el ERP y la medición del servidor salen igual que en una compra de la tienda.
 * Lo único propio es el origen (source = admin) y la atribución (channel = admin, quién lo hizo).
 *
 * Antes de crear nada:
 *   1. Un reenvío del formulario de un pedido ya creado (la misma ficha que el último creado) lleva a ese pedido.
 *   2. El resumen se vuelve a calcular: si falta algo o hay un problema (existencias, un cupón que caducó), no se crea.
 *   3. La ficha que se enseñó tiene que seguir siendo la de ahora; si no, no se crea y se dice qué cambió.
 *   4. ManualOrderDraft::claim() se queda con el carrito con una sentencia condicionada: de dos envíos a la vez, solo uno sigue.
 * Con la casilla del correo desmarcada, ManualOrderMailGate retiene el correo de confirmación al cliente de ESE pedido.
 */
final class ManualOrderPlacer
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @return array{ok:bool,error:string,order_id:int,number:string,changes:array<int,array{key:string,text:string}>,already:bool}
     */
    public function place(ManualOrderDraft $draft, string $token, string $note, bool $notify): array
    {
        $app = $this->app;
        $draft->setNote($note, $notify);

        $last = $app->session('admin')->get(ManualOrderDraft::LAST);
        if ($token !== '' && is_array($last) && isset($last['token'], $last['id']) && hash_equals((string) $last['token'], $token)) {
            return self::result(true, '', (int) $last['id'], '', [], true);
        }

        $quote = ManualOrderQuote::of($draft);
        if (!$quote['ready']) {
            if ($quote['problems'] === [] && $quote['missing'] !== []) {
                return self::result(false, (string) reset($quote['missing']));
            }

            return self::result(false, t('The order cannot be created as it is: check what is marked.'), 0, '', ManualOrderQuote::changes(null, $quote));
        }
        if ($token === '' || !hash_equals((string) $quote['token'], $token)) {
            return self::result(false, t('Something changed since the total you saw: the order was not created. Check what is marked and confirm again.'), 0, '', ManualOrderQuote::changes($draft->seen($token), $quote));
        }

        if ($draft->claim() === null) {
            return self::result(false, t('This order is already being created. Look for it in the list of orders before trying again.'));
        }

        $customer = $draft->customer();
        if ($customer === null) {
            return self::result(false, t('Choose the customer.'));
        }
        $email = (string) $customer['email'];
        $admin = $app->auth()->admin();
        $userId = $admin !== null ? (int) $admin['id'] : null;
        $userName = $admin !== null ? (string) $admin['name'] : '';
        $checkout = [
            'email' => $email, 'billing' => $this->billing($draft, $customer), 'shipping' => null,
            'note' => '', 'accept_terms' => true, 'marketing' => false,
        ];
        $options = ['source' => 'admin', 'attribution' => ['channel' => 'admin', 'user_id' => $userId, 'user' => $userName]];
        $orders = $this->orders();
        $placed = ManualOrderMailGate::run($app, $email, $notify, static function () use ($orders, $checkout, $options): array {
            return $orders->place($checkout, $options);
        });
        if (empty($placed['ok'])) {
            $id = (int) $placed['id'];
            if ($id > 0) {
                // El pedido nació, pero su cobro no pudo empezar: queda fallido y el borrador empieza de nuevo.
                $draft->reset();
            }

            return self::result(false, (string) $placed['error'] !== '' ? (string) $placed['error'] : t('The order could not be created.'), $id, (string) $placed['number']);
        }

        $id = (int) $placed['id'];
        $number = (string) $placed['number'];
        $state = $draft->state();
        $orders->addNote($id, t('Created in the panel by {name}.', ['name' => $userName]), false, $userId);
        if ((string) $state['note'] !== '') {
            $orders->addNote($id, (string) $state['note'], false, $userId);
        }
        if ((string) $state['pay'] === 'paid') {
            $orders->addNote($id, t('Paid in person: {how}', ['how' => (string) $state['paid_with']]), false, $userId);
        }
        foreach ($quote['lines'] as $line) {
            if ($line['price'] === null) {
                continue;
            }
            $name = ManualOrderPrices::lineName(['name' => $line['name'], 'variant_label' => $line['variant']]);
            $orders->addNote($id, t('Price of this sale for {product}: {price} instead of {catalog}. Reason: {reason}', [
                'product' => $name, 'price' => (string) $line['price_formatted'], 'catalog' => (string) $line['unit_formatted'], 'reason' => (string) $line['reason'],
            ]), false, $userId);
            Admin::audit($app, 'order.price', 'order', (string) $id, $number, [
                'product' => [null, $name], 'unit_price' => [(string) $line['unit'], (string) $line['price']], 'reason' => [null, (string) $line['reason']],
            ]);
        }
        Admin::audit($app, 'order.create', 'order', (string) $id, $number, [
            'source' => [null, 'admin'], 'total' => [null, (string) $quote['total']], 'payment' => [null, (string) $state['pay']], 'notify' => [null, $notify ? '1' : '0'],
        ]);
        $app->session('admin')->set(ManualOrderDraft::LAST, ['token' => $token, 'id' => $id]);
        $draft->reset();

        return self::result(true, '', $id, $number);
    }

    /**
     * La dirección de facturación del pedido: la elegida o, si no hace falta enviar nada y no se eligió, el nombre del cliente.
     *
     * @param array<string,mixed> $customer
     *
     * @return array<string,string>
     */
    private function billing(ManualOrderDraft $draft, array $customer): array
    {
        $address = $draft->address();
        $keys = ['first_name', 'last_name', 'company', 'vat_id', 'phone', 'line1', 'line2', 'postcode', 'city', 'region', 'country'];
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = $address !== null && isset($address[$key]) && $address[$key] !== null ? (string) $address[$key] : '';
        }
        if ($address === null) {
            $out['first_name'] = (string) $customer['first_name'];
            $out['last_name'] = (string) $customer['last_name'];
            $destination = $draft->summary()['destination'];
            $out['country'] = is_array($destination) && isset($destination['country']) ? (string) $destination['country'] : '';
        }
        if ($out['phone'] === '' && isset($customer['phone']) && $customer['phone'] !== null) {
            $out['phone'] = (string) $customer['phone'];
        }

        return $out;
    }

    private function orders(): Orders
    {
        $orders = $this->app->service('orders');
        if (!$orders instanceof Orders) {
            throw new \LogicException('The orders service is not available');
        }

        return $orders;
    }

    /**
     * @param array<int,array{key:string,text:string}> $changes
     *
     * @return array{ok:bool,error:string,order_id:int,number:string,changes:array<int,array{key:string,text:string}>,already:bool}
     */
    private static function result(bool $ok, string $error, int $id = 0, string $number = '', array $changes = [], bool $already = false): array
    {
        return ['ok' => $ok, 'error' => $error, 'order_id' => $id, 'number' => $number, 'changes' => $changes, 'already' => $already];
    }
}
