<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Subscriptions\Subscriptions;

require_once __DIR__ . '/helpers.php';

test('la suscripción simulada exige consentimiento, importe exacto y dirección propia', function (): void {
    $fixture = subsFixture();
    [$app, $plan, $customer, $address] = $fixture;
    $service = $app->service('subscriptions');
    $quote = $service->quote($plan, $customer, $address);
    same(1210, $quote['amount']);
    throws(static function () use ($service, $plan, $customer, $address): void { $service->start($plan, $customer, $address, false, 1210, str_repeat('1', 32)); }, \InvalidArgumentException::class);
    throws(static function () use ($service, $plan, $customer, $address): void { $service->start($plan, $customer, $address, true, 1209, str_repeat('1', 32)); }, \InvalidArgumentException::class);
    $other = aifecCartCustomer($app, 'otra@cliente.test');
    throws(static function () use ($service, $plan, $other, $address): void { $service->quote($plan, $other, $address); }, \InvalidArgumentException::class);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {subscriptions}'));
});

test('doble confirmación crea un solo mandato, cuota y pedido y calcula los impuestos de la tienda', function (): void {
    $fixture = subsFixture();
    [$app] = $fixture;
    $first = subsStart($fixture);
    $again = subsStart($fixture);
    same($first['id'], $again['id']);
    same('active', $again['status']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {subscriptions}'));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {subscription_payments}'));
    $order = $app->service('orders')->find((int) $app->service('subscriptions')->payments((int) $first['id'])[0]['order_id']);
    same(1210, (int) $order['total']);
    same('paid', $order['payment_status']);
    same(0, (int) $order['shipping_total']);
});

test('las cuotas congelan el precio y cada renovación simulada tiene un solo pedido', function (): void {
    $fixture = subsFixture();
    [$app, $plan] = $fixture;
    $sub = subsStart($fixture);
    $service = $app->service('subscriptions');
    $due = (string) $sub['next_payment_at'];
    $app->db()->update('subscription_plans', ['amount' => 9999], ['id' => $plan]);
    $service->simulate((int) $sub['id'], 'paid', true, $due);
    throws(static function () use ($service, $sub, $due): void { $service->simulate((int) $sub['id'], 'paid', true, $due); }, \InvalidArgumentException::class);
    same(2, count($service->payments((int) $sub['id'])));
    $secondDue = $service->find((int) $sub['id'])['next_payment_at'];
    check($secondDue > $due, 'el vencimiento avanza incluso en la simulación adelantada');
    $service->simulate((int) $sub['id'], 'paid', true, $secondDue);
    same(3, count($service->payments((int) $sub['id'])));
    check($service->find((int) $sub['id'])['next_payment_at'] > $secondDue, 'dos renovaciones avanzan dos meses');
    foreach ($service->payments((int) $sub['id']) as $payment) {
        same(1210, (int) $payment['amount']);
    }
});

test('un fallo simulado no crea pedidos y la cancelación impide nuevas cuotas', function (): void {
    $fixture = subsFixture();
    [$app, , $customer] = $fixture;
    $sub = subsStart($fixture);
    $service = $app->service('subscriptions');
    $due = (string) $sub['next_payment_at'];
    $service->simulate((int) $sub['id'], 'failed', true, $due);
    same('past_due', $service->find((int) $sub['id'])['status']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    $service->change((int) $sub['id'], 'cancel', $customer);
    $service->change((int) $sub['id'], 'cancel', $customer);
    same('cancelled', $service->find((int) $sub['id'])['status']);
    throws(static function () use ($service, $sub, $due): void { $service->simulate((int) $sub['id'], 'paid', true, $due); }, \InvalidArgumentException::class);
    throws(static function () use ($service, $sub): void { $service->change((int) $sub['id'], 'activate'); }, \InvalidArgumentException::class);
});

test('las cuotas rechazan moneda, importe y reutilización de referencia entre clientes', function (): void {
    $fixture = subsFixture();
    [$app] = $fixture;
    $one = subsStart($fixture);
    $two = subsStart($fixture, str_repeat('2', 32));
    $service = $app->service('subscriptions');
    $payment = $service->payments((int) $one['id'])[0];
    throws(static function () use ($app, $service, $one): void { $service->recordPayment((int) $one['id'], 'wrong-currency', 1210, 'USD', $app->clock()->sql()); }, \InvalidArgumentException::class);
    throws(static function () use ($app, $service, $one): void { $service->recordPayment((int) $one['id'], 'wrong-amount', 1, 'EUR', $app->clock()->sql()); }, \InvalidArgumentException::class);
    throws(static function () use ($app, $service, $two, $payment): void { $service->recordPayment((int) $two['id'], $payment['reference'], 1210, 'EUR', $app->clock()->sql()); }, \InvalidArgumentException::class);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
});

test('el simulador nunca se ofrece en una tienda real sin depuración y no admite cobros adelantados implícitos', function (): void {
    [$app] = $fixture = subsFixture();
    $sub = subsStart($fixture);
    $service = $app->service('subscriptions');
    throws(static function () use ($service, $sub): void { $service->simulate((int) $sub['id'], 'paid'); }, \InvalidArgumentException::class);
    $app->site()->config['debug'] = false;
    same(false, $service->simulationAllowed());
    same(false, $service->available());
});

test('el calendario mensual conserva el día ancla en febrero y los importes nunca usan coma flotante', function (): void {
    same('2027-02-28 12:00:00', Subscriptions::nextMonth('2027-01-31 12:00:00', 31));
    same('2027-03-31 12:00:00', Subscriptions::nextMonth('2027-02-28 12:00:00', 31));
    same('2028-02-29 12:00:00', Subscriptions::nextMonth('2028-01-31 12:00:00', 31));
    same(1210, Subscriptions::minor('12.10', 'EUR'));
    same(1200, Subscriptions::minor('12', 'EUR'));
    same(12, Subscriptions::minor('12', 'JPY'));
    throws(static function (): void { Subscriptions::minor('12.345', 'EUR'); }, \InvalidArgumentException::class);
});
