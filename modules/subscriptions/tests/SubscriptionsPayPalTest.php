<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('PayPal prepara producto, plan mensual y aprobación sin dar por pagada la vuelta', function (): void {
    [$app, , , , $state] = $fixture = subsFixture(true);
    $sub = subsStart($fixture);
    same('pending', $sub['status']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    $again = subsStart($fixture);
    same($sub['id'], $again['id']);
    same(1, $state->createCalls);
    $planBody = null;
    foreach ($app->http()->requests as $request) {
        if (strpos($request->url, '/v1/billing/plans') !== false) {
            $planBody = json_decode((string) $request->body, true);
            check(isset($request->headers['PayPal-Request-Id']), 'cada creación externa lleva una ficha idempotente');
        }
    }
    same('MONTH', $planBody['billing_cycles'][0]['frequency']['interval_unit']);
    same('12.10', $planBody['billing_cycles'][0]['pricing_scheme']['fixed_price']['value']);
    $app->service('subscriptions')->refresh((int) $sub['id']);
    same('active', $app->service('subscriptions')->find((int) $sub['id'])['status']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
});

test('un aviso firmado cobra una sola cuota aunque cambien los identificadores del evento repetido', function (): void {
    [$app] = $fixture = subsFixture(true);
    $sub = subsStart($fixture);
    $event = subsSale($app);
    same(true, subsNotify($app, $event));
    same(true, subsNotify($app, $event));
    $event['id'] = 'WH-ANOTHER';
    same(true, subsNotify($app, $event));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    same(1, count($app->service('subscriptions')->payments((int) $sub['id'])));
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {subscription_events}'));
});

test('firma inválida, identidad ajena, importe y moneda incorrectos no generan pedidos', function (): void {
    [$app, , , , $state] = $fixture = subsFixture(true);
    subsStart($fixture);
    $state->verify = false;
    same(false, subsNotify($app, subsSale($app)));
    $state->verify = true;
    $identity = $state->identity;
    $state->identity = 'S-ANOTHER';
    throws(static function () use ($app): void { subsNotify($app, subsSale($app)); }, \InvalidArgumentException::class);
    $state->identity = $identity;
    $event = subsSale($app);
    $event['resource']['amount']['currency'] = 'USD';
    throws(static function () use ($app, $event): void { subsNotify($app, $event); }, \InvalidArgumentException::class);
    $event['resource']['amount'] = ['total' => '0.01', 'currency' => 'EUR'];
    throws(static function () use ($app, $event): void { subsNotify($app, $event); }, \InvalidArgumentException::class);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
});

test('los eventos fallidos se reflejan sin cuota y una devolución notificada no vuelve a cobrar al proveedor', function (): void {
    [$app] = $fixture = subsFixture(true);
    $sub = subsStart($fixture);
    same(true, subsNotify($app, ['id' => 'WH-FAILED', 'event_type' => 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'resource' => ['id' => 'I-EXAMPLE123']]));
    same('past_due', $app->service('subscriptions')->find((int) $sub['id'])['status']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    same(true, subsNotify($app, subsSale($app)));
    $refund = ['id' => 'WH-REFUND', 'event_type' => 'PAYMENT.SALE.REFUNDED', 'resource' => ['id' => 'REFUND-EXAMPLE', 'sale_id' => 'SALE-EXAMPLE1', 'amount' => ['total' => '12.10', 'currency' => 'EUR']]];
    same(true, subsNotify($app, $refund));
    $refund['id'] = 'WH-REFUND-AGAIN';
    same(true, subsNotify($app, $refund));
    $payment = $app->service('subscriptions')->payments((int) $sub['id'])[0];
    same('refunded', $payment['status']);
    same(1210, (int) $payment['refunded_amount']);
    same(1210, (int) $app->service('orders')->find((int) $payment['order_id'])['refunded_total']);
    foreach ($app->http()->requests as $request) {
        check(strpos($request->url, '/refund') === false, 'el aviso no pide una segunda devolución');
    }
});

test('la redirección de aprobación solo puede ir a PayPal y el entorno live queda fuera de las demos', function (): void {
    [$app, , , , $state] = $fixture = subsFixture(true);
    $state->unsafeUrl = true;
    throws(static function () use ($fixture): void { subsStart($fixture); }, \UnexpectedValueException::class);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    $failedId = (int) $app->db()->value('SELECT id FROM {subscriptions}');
    throws(static function () use ($app, $failedId): void { $app->service('subscriptions')->change($failedId, 'activate'); }, \InvalidArgumentException::class);
    same('setup_failed', $app->service('subscriptions')->find($failedId)['status']);
    $app->site()->config['demo'] = true;
    same('simulated', $app->service('subscriptions')->provider());
    throws(static function () use ($app): void { new \Aifec\Modules\Subscriptions\PayPalGateway($app, 'live'); }, \InvalidArgumentException::class);
});

test('un aviso antiguo de impago no pisa una cuota pagada después y un impago nuevo sí se refleja', function (): void {
    [$app, , , , $state] = $fixture = subsFixture(true);
    $sub = subsStart($fixture);
    $paidAt = $app->clock()->sql();
    $oldAt = str_replace(' ', 'T', $app->clock()->sql($app->clock()->now() - 3600)) . 'Z';
    same(true, subsNotify($app, subsSale($app)));
    $state->billing = ['last_payment' => ['time' => str_replace(' ', 'T', $paidAt) . 'Z'], 'last_failed_payment' => ['time' => $oldAt], 'failed_payments_count' => 0];
    same(true, subsNotify($app, ['id' => 'WH-OLD-FAILURE', 'create_time' => $oldAt, 'event_type' => 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'resource' => ['id' => 'I-EXAMPLE123']]));
    same('active', $app->service('subscriptions')->find((int) $sub['id'])['status']);
    same(0, (int) $app->service('subscriptions')->find((int) $sub['id'])['failures']);
    $app->clock()->advance(3600);
    $newAt = str_replace(' ', 'T', $app->clock()->sql()) . 'Z';
    $state->billing['last_failed_payment']['time'] = $newAt;
    $state->billing['failed_payments_count'] = 1;
    same(true, subsNotify($app, ['id' => 'WH-NEW-FAILURE', 'create_time' => $newAt, 'event_type' => 'BILLING.SUBSCRIPTION.PAYMENT.FAILED', 'resource' => ['id' => 'I-EXAMPLE123']]));
    same('past_due', $app->service('subscriptions')->find((int) $sub['id'])['status']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
});

test('una devolución anterior al cobro queda guardada y se aplica una sola vez cuando llega la cuota', function (): void {
    [$app] = $fixture = subsFixture(true);
    $sub = subsStart($fixture);
    $refund = ['id' => 'WH-EARLY-REFUND', 'event_type' => 'PAYMENT.SALE.REFUNDED', 'resource' => ['id' => 'REFUND-EARLY', 'sale_id' => 'SALE-EXAMPLE1', 'amount' => ['total' => '12.10', 'currency' => 'EUR']]];
    same(true, subsNotify($app, $refund));
    same(true, subsNotify($app, $refund));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {subscription_events} WHERE processed = 0 AND body IS NOT NULL'));
    same(true, subsNotify($app, subsSale($app)));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {subscription_events} WHERE processed = 0'));
    $payment = $app->service('subscriptions')->payments((int) $sub['id'])[0];
    same('refunded', $payment['status']);
    same(1210, (int) $app->service('orders')->find((int) $payment['order_id'])['refunded_total']);
    $refund['id'] = 'WH-EARLY-REFUND-AGAIN';
    same(true, subsNotify($app, $refund));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {order_refunds}'));
});

test('una cancelación no vuelve a activa con un aviso anterior y no permite cargos simulados', function (): void {
    [$app, , $customer, , $state] = $fixture = subsFixture(true);
    $sub = subsStart($fixture);
    $state->status = 'CANCELLED';
    $app->service('subscriptions')->change((int) $sub['id'], 'cancel', $customer);
    $state->status = 'ACTIVE';
    same(true, subsNotify($app, ['id' => 'WH-OLD-ACTIVE', 'event_type' => 'BILLING.SUBSCRIPTION.ACTIVATED', 'resource' => ['id' => 'I-EXAMPLE123']]));
    same('cancelled', $app->service('subscriptions')->find((int) $sub['id'])['status']);
    throws(static function () use ($app, $sub): void { $app->service('subscriptions')->change((int) $sub['id'], 'activate'); }, \InvalidArgumentException::class);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
});
