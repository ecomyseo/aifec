<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Orders\Sequence;

require_once __DIR__ . '/helpers.php';

/** @return array{0:\Aifec\Kernel\App,1:array<string,mixed>} */
function recurringOrderFixture(): array
{
    $app = aifecCartApp();
    $customer = aifecCartCustomer($app);

    return [$app, [
        'customer_id' => $customer, 'email' => 'ana@cliente.test', 'billing' => ['first_name' => 'Ana', 'country' => 'ES'],
        'currency' => 'EUR', 'amount' => 1210, 'name' => 'Servicio mensual de prueba', 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %',
        'method' => 'subscription_simulated', 'reference' => 'cuota-de-prueba-1', 'test' => true,
        'subscription_id' => 7, 'paid_at' => $app->clock()->sql(),
    ]];
}

test('una cuota confirmada crea un pedido de servicio con IVA exacto, sin carrito ni envío', function (): void {
    [$app, $snapshot] = recurringOrderFixture();
    $events = 0;
    $app->hooks()->on('order.paid', static function () use (&$events): void { ++$events; });
    $made = $app->service('orders')->createRecurringPayment($snapshot, 'simulated:cuota-1');
    $order = $app->service('orders')->find($made['id']);
    same('paid', $order['payment_status']);
    same('Pago mensual simulado', $order['payment_title']);
    same('completed', $order['status']);
    same('not_needed', $order['fulfillment_status']);
    same(1210, $order['total']);
    same(1000, $order['total_net']);
    same(210, $order['total_tax']);
    same(1210, $order['paid_total']);
    same(0, $order['shipping_total']);
    same($snapshot['paid_at'], $order['paid_at']);
    same(1, count($order['items']));
    same(1, count($order['payments']));
    same(1, $events);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
    same(0, (int) $app->db()->value('SELECT total_spent FROM {customers} WHERE id = ?', [$snapshot['customer_id']]), 'la prueba no suma ventas reales');
});

test('repetir el aviso de una cuota devuelve el mismo pedido y no repite el cobro ni los eventos', function (): void {
    [$app, $snapshot] = recurringOrderFixture();
    $events = 0;
    $app->hooks()->on('order.paid', static function () use (&$events): void { ++$events; });
    $first = $app->service('orders')->createRecurringPayment($snapshot, 'simulated:cuota-1');
    $again = $app->service('orders')->createRecurringPayment($snapshot, 'simulated:cuota-1');
    same($first, $again);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {order_payments}'));
    same(1, $events);
    same(1, (new Sequence($app))->current('order'));
    $snapshot['amount'] = 2420;
    throws(static function () use ($app, $snapshot): void {
        $app->service('orders')->createRecurringPayment($snapshot, 'simulated:cuota-1');
    }, \InvalidArgumentException::class);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
});

test('una referencia ya cobrada no se asigna a otra cuota ni deja pedido, clave o contador huérfanos', function (): void {
    [$app, $snapshot] = recurringOrderFixture();
    $app->service('orders')->createRecurringPayment($snapshot, 'simulated:cuota-1');
    throws(static function () use ($app, $snapshot): void {
        $app->service('orders')->createRecurringPayment($snapshot, 'simulated:otra-cuota');
    }, \LogicException::class);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {order_recurring_payments}'));
    same(1, (new Sequence($app))->current('order'));
});

test('una cuota con otro cliente, decimales o una fecha futura se rechaza antes de escribir', function (): void {
    [$app, $snapshot] = recurringOrderFixture();
    $cases = [
        ['email' => 'otra@cliente.test'], ['amount' => 12.10], ['tax_rate' => 21.0],
        ['paid_at' => '2099-01-01 00:00:00'], ['reference' => str_repeat('x', 121)], ['tax_included' => false],
    ];
    foreach ($cases as $changes) {
        $bad = array_replace($snapshot, $changes);
        throws(static function () use ($app, $bad): void {
            $app->service('orders')->createRecurringPayment($bad, 'simulated:rechazada');
        }, \InvalidArgumentException::class);
    }
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {order_recurring_payments}'));
});
