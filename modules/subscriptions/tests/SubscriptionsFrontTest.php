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

test('un cliente revisa impuestos, acepta la cuota y encuentra su suscripción en su cuenta', function (): void {
    [$app, $plan, $customer, $address] = subsFixture();
    $web = new TestClient($app);
    same(200, $web->get('/suscripciones/planes')->status);
    contains('Servicios mensuales', $web->last->body);
    frontLogin($web);
    $checkout = $web->get('/mi-cuenta/suscripciones/plan/' . $plan);
    same(200, $checkout->status);
    contains('Autorizo un pago recurrente', $checkout->body);
    contains('no se cobra dinero real', $checkout->body);
    $form = ['_token' => inputValue($checkout->body, '_token'), 'nonce' => inputValue($checkout->body, 'nonce'), 'amount' => inputValue($checkout->body, 'amount'), 'address_id' => (string) $address, 'consent' => '1'];
    $result = $web->post('/mi-cuenta/suscripciones/plan/' . $plan, $form);
    same(303, $result->status);
    $account = $web->follow();
    same(200, $account->status);
    contains('Servicio mensual de prueba', $account->body);
    contains('Cancelar los próximos pagos mensuales', $account->body);
    same('no-store, private', $account->headers['Cache-Control']);
    $sub = $app->service('subscriptions')->forCustomer($customer)[0];
    $cancel = $web->post('/mi-cuenta/suscripciones/' . $sub['id'] . '/cancelar', ['_token' => inputValue($account->body, '_token')]);
    same(303, $cancel->status);
    same('cancelled', $app->service('subscriptions')->find((int) $sub['id'])['status']);
    $again = $web->get('/mi-cuenta/suscripciones/plan/' . $plan);
    check(inputValue($again->body, 'nonce') !== $form['nonce'], 'una nueva visita después de cancelar tiene su propia autorización');
    $newForm = ['_token' => inputValue($again->body, '_token'), 'nonce' => inputValue($again->body, 'nonce'), 'amount' => inputValue($again->body, 'amount'), 'address_id' => (string) $address, 'consent' => '1'];
    same(303, $web->post('/mi-cuenta/suscripciones/plan/' . $plan, $newForm)->status);
    same(2, count($app->service('subscriptions')->forCustomer($customer)));
});

test('los formularios privados rechazan fichas ausentes y suscripciones de otro cliente', function (): void {
    [$app, $plan, , $address] = $fixture = subsFixture();
    $sub = subsStart($fixture);
    $web = new TestClient($app);
    frontLogin($web);
    $checkout = $web->get('/mi-cuenta/suscripciones/plan/' . $plan);
    same(403, $web->post('/mi-cuenta/suscripciones/plan/' . $plan, ['nonce' => inputValue($checkout->body, 'nonce'), 'amount' => '1210', 'address_id' => $address, 'consent' => 1])->status);
    aifecCartCustomer($app, 'otra@cliente.test');
    $other = new TestClient($app);
    frontLogin($other, 'otra@cliente.test');
    $page = $other->get('/mi-cuenta/suscripciones');
    notContains($sub['public_id'], $page->body);
    $account = new \Aifec\Modules\Customers\Front\Account($app);
    $otherToken = aifecCartRun($other, static function ($cart, $app): string { return (new \Aifec\Modules\Customers\Front\Account($app))->token(); });
    same(404, $other->post('/mi-cuenta/suscripciones/' . $sub['id'] . '/cancelar', ['_token' => $otherToken])->status);
});

test('el administrador ve planes, cuotas y simulación y un perfil sin permisos no puede cambiarlos', function (): void {
    [$app] = $fixture = subsFixture();
    $sub = subsStart($fixture);
    $admin = adminClient($app);
    same(200, $admin->get('/admin/subscriptions')->status);
    contains('Dirección de aviso de PayPal', $admin->last->body);
    same(200, $admin->get('/admin/r/subscription_plans')->status);
    same(200, $admin->get('/admin/r/subscription_plans/new')->status);
    same(200, $admin->get('/admin/settings/subscriptions')->status);
    same(200, $admin->get('/admin/subscriptions/' . $sub['id'])->status);
    contains('Simular el próximo pago mensual', $admin->last->body);
    $token = aifecToken($admin);
    same(303, $admin->post('/admin/subscriptions/' . $sub['id'] . '/paid', ['_token' => $token, 'due' => $sub['next_payment_at']])->status);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {subscription_payments}'));
    $editor = adminClient($app, 'editor');
    $editorToken = aifecToken($editor);
    same(403, $editor->get('/admin/subscriptions')->status);
    same(403, $editor->post('/admin/subscriptions/' . $sub['id'] . '/cancel', ['_token' => $editorToken])->status);
});
