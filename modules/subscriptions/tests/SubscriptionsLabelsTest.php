<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Subscriptions\Labels;

require_once __DIR__ . '/helpers.php';

test('los estados de suscripción mantienen su contexto con las pasarelas cargadas y colisiones de textos genéricos', function (): void {
    $modules = ['subscriptions', 'payments-paypal', 'payments-redsys', 'payments-simulated'];
    $app = aifecOrderApp(['modules' => $modules, 'settings' => ['modules.active' => array_merge(['orders', 'mail', 'media', 'jobs'], $modules)]]);
    $app->makeCurrent();
    // Cualquier módulo o traducción del sitio puede usar el masculino para un estado de pago.
    $collision = tmpDir('subscription-label-collision');
    file_put_contents($collision . '/es.php', "<?php\nreturn ['Active' => 'Activo', 'Cancelled' => 'Cancelado', 'Suspended' => 'Suspendido', 'Expired' => 'Caducado'];\n");
    $app->i18n()->setSiteDir($collision);
    same('Activo', t('Active'), 'la reproducción introduce realmente la colisión de una clave genérica');
    $expected = ['setup_failed' => 'Alta sin completar', 'starting' => 'Preparando el pago', 'pending' => 'Pendiente de aprobación',
        'active' => 'Activa', 'past_due' => 'Pago pendiente', 'suspended' => 'Suspendida', 'cancelled' => 'Cancelada', 'expired' => 'Finalizada'];
    foreach ($expected as $state => $text) {
        same($text, Labels::status($state));
    }
    same('Estado desconocido', Labels::status('unsupported'));
});

test('la semilla del plan mensual se traduce sin cargar el módulo sites', function (): void {
    [$app] = subsFixture();
    $app->makeCurrent();
    check(!$app->has('sites'), 'la aplicación de la prueba no carga sites');
    $name = t('Example monthly service');
    $description = t('Fictitious plan for testing monthly payments. No real service is provided and no money is charged.');
    same('Servicio mensual de ejemplo', $name);
    same('Plan ficticio para probar las cuotas mensuales. No se presta ningún servicio real ni se cobra dinero.', $description);
    $id = $app->service('subscriptions')->savePlan(['name' => $name, 'description' => $description, 'amount' => 1000]);
    same($name, $app->service('subscriptions')->plan($id)['name']);
    same($description, $app->service('subscriptions')->plan($id)['description']);
});

test('el modo de suscripción identifica simulación, sandbox y producción sin códigos crudos ni cobros inventados', function (): void {
    [$app] = subsFixture();
    $app->makeCurrent();
    same('Suscripción simulada (sin dinero real)', Labels::paymentMode('simulated', 'simulated'));
    same('PayPal en sandbox (sin dinero real)', Labels::paymentMode('paypal', 'sandbox'));
    same('PayPal en producción', Labels::paymentMode('paypal', 'live'));
    same('Modo de pago de la suscripción desconocido', Labels::paymentMode('paypal', 'invalid'));
    same('Modo de pago de la suscripción desconocido', Labels::paymentMode('invalid', 'live'));
});

test('el panel y la cuenta muestran el modo simulado con una etiqueta humana', function (): void {
    [$app] = $fixture = subsFixture();
    $sub = subsStart($fixture);
    $admin = adminClient($app);
    $page = $admin->get('/admin/subscriptions/' . $sub['id']);
    same(200, $page->status);
    contains('Suscripción simulada (sin dinero real)', $page->body);
    notContains('simulated (simulated)', $page->body);
    $customer = new TestClient($app);
    frontLogin($customer);
    $account = $customer->get('/mi-cuenta/suscripciones');
    same(200, $account->status);
    contains('Suscripción simulada (sin dinero real)', $account->body);
    contains('Activa', $account->body);
});
