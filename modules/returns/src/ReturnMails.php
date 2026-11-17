<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Returns;

use Aifec\Kernel\App;
use Aifec\Modules\Orders\OrderMails;

/**
 * Los correos de una devolución (plantillas declaradas en emails.php, vistas en views/emails/).
 *
 * Los del cliente salen en el idioma del pedido; el de la tienda, en el idioma principal del sitio. A la cola del correo solo va lo
 * imprescindible: el nombre, los números, las líneas, el motivo y los enlaces. Nunca una dirección postal. Un fallo del correo se
 * anota y no tumba lo que se estaba haciendo: una devolución aceptada no se deshace porque no salga el aviso.
 */
final class ReturnMails
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Encola un correo al cliente. Devuelve el id del mensaje, o 0 si no hay servicio de correo, está en modo silencioso o ha fallado.
     *
     * @param array<string,mixed> $return la devolución completa (Returns::find)
     * @param array<string,mixed> $order  el pedido completo (Orders::find)
     * @param array<string,mixed> $extra  datos de la plantilla: instructions, reject_reason, amount…
     */
    public function toCustomer(array $return, array $order, string $template, array $extra = []): int
    {
        if (!$this->app->has('mailer')) {
            return 0;
        }
        try {
            $payload = $this->payload($return, $order, $extra);

            return (int) $this->app->service('mailer')->queue($template, (string) $return['email'], $payload, [
                'locale' => (string) $order['lang'], 'to_name' => (string) $payload['name'], 'related' => 'return:' . (int) $return['id'],
            ]);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'returns.mail:' . $template);

            return 0;
        }
    }

    /**
     * Avisa a la tienda de una devolución nueva (ajuste store.email), en el idioma principal del sitio.
     *
     * @param array<string,mixed> $return
     * @param array<string,mixed> $order
     */
    public function toShop(array $return, array $order): int
    {
        $to = trim($this->app->settings()->str('store.email'));
        if ($to === '' || !$this->app->has('mailer')) {
            return 0;
        }
        try {
            $payload = $this->payload($return, $order, ['comment' => mb_substr((string) $return['comment'], 0, 1000, 'UTF-8')]);
            $payload['panel_url'] = $this->app->url()->abs($this->app->url()->plain('/admin/returns/' . (int) $return['id']));

            return (int) $this->app->service('mailer')->queue('return_requested_shop', $to, $payload, [
                'locale' => $this->app->defaultLang(), 'related' => 'return:' . (int) $return['id'],
            ]);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'returns.mail:return_requested_shop');

            return 0;
        }
    }

    /**
     * Lo que ven las plantillas. Las claves sueltas (number, order_number, name) también sirven en el asunto.
     *
     * @param array<string,mixed> $return
     * @param array<string,mixed> $order
     * @param array<string,mixed> $extra
     *
     * @return array<string,mixed>
     */
    public function payload(array $return, array $order, array $extra = []): array
    {
        $billing = isset($order['billing']) && is_array($order['billing']) ? $order['billing'] : [];
        $name = trim((isset($billing['first_name']) ? (string) $billing['first_name'] : '') . ' ' . (isset($billing['last_name']) ? (string) $billing['last_name'] : ''));
        $lines = [];
        foreach (isset($return['items']) && is_array($return['items']) ? $return['items'] : [] as $line) {
            $lines[] = ['name' => (string) $line['name'], 'variant_label' => (string) ($line['variant_label'] !== null ? $line['variant_label'] : ''), 'qty' => (int) $line['qty']];
        }
        $reasons = (new ReturnPolicy($this->app))->reasons();
        $code = (string) $return['reason'];
        $reason = $code === ReturnPolicy::WITHDRAWAL ? 'Withdrawal' : (isset($reasons[$code]) ? $reasons[$code] : $code);

        return $extra + [
            'name' => $name !== '' ? $name : (string) $return['email'],
            'number' => (string) $return['number'],
            'order_number' => (string) $order['number'],
            'order_url' => (new OrderMails($this->app))->orderUrl($order),
            'return_url' => $this->returnUrl($order),
            'kind' => (string) $return['kind'],
            'reason' => $reason,
            'currency' => (string) $return['currency'],
            'lines' => $lines,
        ];
    }

    /**
     * La página de la devolución para el cliente: la del pedido con su ficha. Cadena vacía si el pedido no tiene ficha del sitio
     * (un pedido traído de otra tienda): entonces el correo no lleva enlace.
     *
     * @param array<string,mixed> $order
     */
    public function returnUrl(array $order): string
    {
        if (!$this->app->router()->has('returns.order')) {
            return '';
        }
        $secret = $this->app->service('orders')->viewTokenOf((string) $order['number']);
        if ($secret === '') {
            return '';
        }
        try {
            return $this->app->url()->origin() . $this->app->url()->route('returns.order', ['number' => (string) $order['number'], 'secret' => $secret]);
        } catch (\InvalidArgumentException $e) {
            return '';
        }
    }

    /**
     * Datos de ejemplo para la vista previa de las plantillas en el panel.
     *
     * @return array<string,mixed>
     */
    public static function sample(): array
    {
        return [
            'name' => 'Ana Ruiz', 'number' => 'D-2026-000001', 'order_number' => '2026-000123', 'kind' => 'return',
            'order_url' => 'https://tienda.test/pedido/2026-000123', 'return_url' => 'https://tienda.test/devolucion/2026-000123/abc',
            'panel_url' => 'https://tienda.test/admin/returns/1', 'reason' => 'Defective or damaged', 'currency' => 'EUR',
            'lines' => [['name' => 'Producto de ejemplo', 'variant_label' => '', 'qty' => 1]],
            'instructions' => 'Envía el paquete a Calle Mayor 1, 35001 Las Palmas, con el número de devolución en el exterior.',
            'reject_reason' => 'El producto está usado.', 'amount' => 1500, 'comment' => 'La cremallera se ha roto.',
        ];
    }
}
