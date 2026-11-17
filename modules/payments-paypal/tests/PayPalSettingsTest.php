<?php
/**
 * AIFEC — pruebas de los ajustes de PayPal y de su catálogo de textos: la pantalla (pestañas y dirección de aviso), el guardado de
 * los secretos cifrados sin enseñarlos nunca, lo que se rechaza, y que ningún secreto salga en el registro ni en lo que se guarda
 * del cobro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\PaymentsPaypal\PayPalSettings;

require_once __DIR__ . '/PayPalKit.php';

// ppForm(), ppSourcesForm() y ppSave() están en PayPalKit.php (también las usa PayPalSourcesTest.php).

test('la pantalla de ajustes abre con sus dos pestañas y la dirección de aviso lista para copiar', function (): void {
    $app = ppApp([], false);
    $web = adminClient($app);
    $r = $web->get('/admin/settings/payments-paypal');

    same(200, $r->status);
    contains('data-af-tab="ppmain"', $r->body);
    contains('data-af-tab="ppkeys"', $r->body);
    contains('data-af-copy="https://tienda.test/pago/paypal/aviso"', $r->body);
    contains('value="https://tienda.test/pago/paypal/aviso"', $r->body);
    contains('PAYMENT.CAPTURE.COMPLETED', $r->body, 'dice qué eventos marcar');
    contains('PAYMENT.CAPTURE.DENIED', $r->body);
    contains('PAYMENT.CAPTURE.REFUNDED', $r->body);
    contains('/admin/settings/payments-paypal', $web->get('/admin/settings')->body, 'aparece en el índice de ajustes');
});

test('un secreto se guarda cifrado y la pantalla no lo enseña nunca, ni el de pruebas ni el de producción', function (): void {
    $app = ppApp([], false);
    $web = adminClient($app);

    same(302, ppSave($web, ppForm([PayPalSettings::SECRET_SANDBOX => PP_SECRET, PayPalSettings::SECRET_LIVE => PP_LIVE_SECRET]), 'ppkeys')->status);
    $app->settings()->reload();
    same(PP_SECRET, $app->secret(PayPalSettings::SECRET_SANDBOX), 'la tienda lo lee descifrado');
    same(PP_LIVE_SECRET, $app->secret(PayPalSettings::SECRET_LIVE));
    same(0, strpos($app->settings()->str(PayPalSettings::SECRET_SANDBOX), 'enc:'), 'y en la base de datos va cifrado');
    notContains(PP_SECRET, $app->settings()->str(PayPalSettings::SECRET_SANDBOX));
    notContains(PP_LIVE_SECRET, $app->settings()->str(PayPalSettings::SECRET_LIVE));

    $page = $web->get('/admin/settings/payments-paypal')->body;
    notContains(PP_SECRET, $page);
    notContains(PP_LIVE_SECRET, $page);
    contains('Guardada. Déjala vacía para conservarla', $page, 'solo dice que está guardado');
    same(true, $app->service('payments')->get('paypal')->available(['currency' => 'EUR']), 'y con él ya se ofrece');
});

test('guardar con el secreto vacío conserva el de antes, y datos con mala forma se rechazan sin guardar nada', function (): void {
    $app = ppApp();
    $web = adminClient($app);
    same(PP_SECRET, $app->secret(PayPalSettings::SECRET_SANDBOX));

    same(302, ppSave($web, ppForm([PayPalSettings::TITLE => 'Pago seguro']), 'ppmain')->status);
    $app->settings()->reload();
    same(PP_SECRET, $app->secret(PayPalSettings::SECRET_SANDBOX), 'el secreto sigue');
    same('Pago seguro', $app->settings()->str(PayPalSettings::TITLE));

    foreach ([
        [PayPalSettings::SECRET_SANDBOX, 'corto'], [PayPalSettings::SECRET_SANDBOX, '<script>alert(1)</script>'], [PayPalSettings::SECRET_LIVE, str_repeat('a', 300)],
        [PayPalSettings::CLIENT_ID_SANDBOX, 'con espacios y <etiquetas>'], [PayPalSettings::WEBHOOK_ID_SANDBOX, 'no vale!'], [PayPalSettings::ENVIRONMENT, 'otro'],
    ] as $case) {
        $r = ppSave($web, ppForm([$case[0] => $case[1], PayPalSettings::TITLE => 'No debe guardarse']), 'ppkeys');
        same(422, $r->status, 'se rechaza ' . $case[0] . ' = ' . $case[1]);
        $app->settings()->reload();
        same(PP_SECRET, $app->secret(PayPalSettings::SECRET_SANDBOX));
        same('Pago seguro', $app->settings()->str(PayPalSettings::TITLE), 'y no se guarda nada del resto');
        same(PP_CLIENT, $app->settings()->str(PayPalSettings::CLIENT_ID_SANDBOX));
    }
});

test('el entorno, los identificadores y el título se guardan, vuelven a pintarse y el «&» se conserva tal cual', function (): void {
    $app = ppApp([], false);
    $web = adminClient($app);

    $r = ppSave($web, ppForm([
        PayPalSettings::ENVIRONMENT => 'live', PayPalSettings::TITLE => 'PayPal & tarjeta', PayPalSettings::CLIENT_ID_LIVE => PP_LIVE_CLIENT,
        PayPalSettings::WEBHOOK_ID_LIVE => PP_LIVE_WEBHOOK,
    ]), 'ppmain');
    same(302, $r->status);
    contains('#tab=ppmain', $r->headers['Location'], 'vuelve a la misma pestaña');
    $app->settings()->reload();
    same('live', $app->settings()->str(PayPalSettings::ENVIRONMENT));
    same('PayPal & tarjeta', $app->settings()->str(PayPalSettings::TITLE), 'el «&» se guarda tal cual');
    same(PP_LIVE_CLIENT, $app->settings()->str(PayPalSettings::CLIENT_ID_LIVE));
    same(PP_LIVE_WEBHOOK, $app->settings()->str(PayPalSettings::WEBHOOK_ID_LIVE));
    $page = $web->get('/admin/settings/payments-paypal')->body;
    same(PP_LIVE_CLIENT, inputValue($page, 'f[' . PayPalSettings::CLIENT_ID_LIVE . ']'));
    contains('PayPal &amp; tarjeta', $page);
    same(false, $app->service('payments')->get('paypal')->isTest(), 'en producción no es de pruebas');
});

test('solo quien tiene sesión del panel ve la pantalla de ajustes', function (): void {
    $app = ppApp();
    same(302, (new TestClient($app))->get('/admin/settings/payments-paypal')->status, 'sin sesión no se abre');
});

test('ningún secreto sale en la pantalla de ajustes, en el registro ni en lo que se guarda del cobro (con la captura hecha de punta a punta)', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    $order = ppOrderRow($app, $placed);
    // Una captura que, además, lleva datos del comprador y el secreto en un campo cualquiera.
    $capture = ppCapturedOrder($paypalOrder, \Aifec\Commerce\Money::decimal((int) $order['total']), 'EUR', (string) $order['number']);
    $capture['debug'] = PP_SECRET . ' ' . PP_TOKEN;
    $fake->captureResponse = ppJson(201, $capture);
    $web = new TestClient($app);
    same(302, ppBack($web, $placed)->status);
    same('paid', ppOrderRow($app, $placed)['payment_status']);

    // Y un cobro que falla, para que también pase por los mensajes de error.
    $fake->createResponse = ppJson(401, ['name' => 'AUTHENTICATION_FAILURE', 'message' => 'bad credentials ' . PP_SECRET . ' ' . PP_TOKEN, 'debug_id' => 'dbg-x']);
    ppPlace($app, new TestClient($app), $p);

    $everything = json_encode([
        'log' => $app->log()->records,
        'payments' => $app->db()->select('SELECT * FROM {order_payments}'),
        'history' => $app->db()->select('SELECT * FROM {order_history}'),
        'orders' => $app->db()->select('SELECT * FROM {orders}'),
        'mail' => $app->db()->select('SELECT * FROM {mail_messages}'),
    ], JSON_UNESCAPED_UNICODE);
    foreach ([PP_SECRET, PP_LIVE_SECRET, PP_TOKEN, base64_encode(PP_CLIENT . ':' . PP_SECRET)] as $secret) {
        notContains($secret, (string) $everything, 'no aparece ' . $secret);
    }
    $raw = $app->db()->select("SELECT raw FROM {order_payments} WHERE kind = 'charge' AND status = 'ok'");
    same(1, count($raw));
    notContains('comprador@example.test', (string) $raw[0]['raw'], 'ni el correo del comprador');
    notContains('QYR5Z8XDVJNXQ', (string) $raw[0]['raw'], 'ni su identificador en PayPal');

    $page = adminClient($app)->get('/admin/settings/payments-paypal')->body;
    notContains(PP_SECRET, $page);
    notContains(PP_LIVE_SECRET, $page);
});

test('el catálogo castellano traduce todo lo que el módulo enseña, y el manifiesto declara lo que usa', function (): void {
    $dir = dirname(__DIR__);
    $es = require $dir . '/lang/es.php';
    check(is_array($es) && count($es) > 30, 'el catálogo tiene sus cadenas');
    foreach ($es as $english => $spanish) {
        check(is_string($spanish) && $spanish !== '', 'sin traducir: ' . $english);
    }
    $manifest = json_decode((string) file_get_contents($dir . '/module.json'), true);
    // P101: el guion de PayPal en la página de pago (www.paypalobjects.com) y el de Google Pay (pay.google.com).
    same(['api-m.paypal.com', 'api-m.sandbox.paypal.com', 'www.paypal.com', 'www.sandbox.paypal.com', 'www.paypalobjects.com', 'pay.google.com'], $manifest['contract']['hosts']);
    same(['email', 'name', 'address'], $manifest['contract']['personal_data']);
    same(['orders'], $manifest['requires']['modules']);
    same(false, $manifest['default']);
    same(false, $manifest['essential']);
    same([], $manifest['contract']['tables']);
    check(is_file($dir . '/settings.php') && is_file($dir . '/routes.php') && is_file($dir . '/hooks.php'), 'los tres ficheros de arranque');
});
