<?php
/**
 * AIFEC — pruebas de las formas de pago del guion de PayPal en la tienda: cuándo se ofrecen, la página de pago (el guion con sus
 * componentes justos, la política de contenido solo ahí, sin guiones en línea), la ruta que crea la orden (el importe del
 * pedido, la sesión y la ficha), la que captura (una sola vez, 3-D Secure, rechazos), el aviso tardío, el fichero de Apple Pay,
 * que los datos de la tarjeta no pasan por la tienda, el guion propio, las devoluciones y los ajustes.
 *
 * Nada habla con PayPal, Google ni Apple: el guion de PayPal no se carga (no hay navegador); se prueba lo que hace el servidor
 * con lo que el guion le mandaría.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Money;
use Aifec\Commerce\PaymentAction;
use Aifec\Http\HttpException;
use Aifec\Kernel\App;
use Aifec\Kernel\Csp;
use Aifec\Kernel\Response;
use Aifec\Modules\PaymentsPaypal\PayPalPayment;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;

require_once __DIR__ . '/PayPalSdkKit.php';

// ------------------------------------------------------------------ cuándo se ofrecen

// [forma, contexto válido, contexto que no vale, por qué no].
foreach ([
    ['paylater', ['country' => 'ES', 'currency' => 'EUR', 'total' => 3000], ['country' => 'ES', 'currency' => 'EUR', 'total' => 1500], 'por debajo de 20 EUR'],
    ['card_fields', ['country' => 'AR', 'currency' => 'EUR'], ['country' => 'ES', 'currency' => 'ARS'], 'en pesos argentinos'],
    ['google_pay', ['country' => 'ES', 'currency' => 'EUR'], ['country' => 'AR', 'currency' => 'EUR'], 'a un comprador de Argentina'],
    ['apple_pay', ['country' => 'ES', 'currency' => 'EUR'], ['country' => 'MX', 'currency' => 'MXN'], 'en México'],
    ['venmo', ['country' => 'US', 'currency' => 'USD'], ['country' => 'ES', 'currency' => 'USD'], 'fuera de Estados Unidos'],
] as $case) {
    test($case[0] . ' se ofrece solo encendida, con claves y a quien la admite (no ' . $case[3] . ')', function () use ($case): void {
        $code = 'paypal_' . $case[0];
        $off = ppApp($case[0] === 'apple_pay' ? [PayPalSettings::APPLE_PAY_FILE => PPK_APPLE_FILE] : []);
        check(!in_array($code, ppkOffered($off, $case[1]), true), 'apagada de serie');
        $on = ppkApp([$case[0]]);
        check(in_array($code, ppkOffered($on, $case[1]), true), 'encendida y con un comprador válido');
        check(!in_array($code, ppkOffered($on, $case[2]), true), 'no ' . $case[3]);
        $noKeys = ppApp([PayPalSettings::enabledKey($case[0]) => 1, PayPalSettings::APPLE_PAY_FILE => PPK_APPLE_FILE], false);
        check(!in_array($code, ppkOffered($noKeys, $case[1]), true), 'sin claves no');
    });
}

test('Apple Pay solo con el fichero del dominio pegado en los ajustes', function (): void {
    $app = ppSourcesApp(['apple_pay']);
    same(false, in_array('paypal_apple_pay', ppkOffered($app, []), true), 'sin fichero, no');
    $app->settings()->set(PayPalSettings::APPLE_PAY_FILE, PPK_APPLE_FILE);
    same(true, in_array('paypal_apple_pay', ppkOffered($app, []), true));
});

test('en la caja cada forma del guion dice que necesita JavaScript, con un nombre que no se confunde con la tarjeta por PayPal', function (): void {
    $app = ppkApp(['paylater', 'card_fields', 'google_pay', 'apple_pay', 'venmo']);
    $p = $app->service('payments');
    same('Tarjeta de crédito o débito', $p->get('paypal_card_fields')->info()['title']);
    same('Tarjeta por PayPal', $p->get('paypal_card')->info()['title']);
    same('Paga más tarde con PayPal', $p->get('paypal_paylater')->info()['title']);
    same('Google Pay', $p->get('paypal_google_pay')->info()['title']);
    same('Apple Pay', $p->get('paypal_apple_pay')->info()['title']);
    same('Venmo', $p->get('paypal_venmo')->info()['title']);
    foreach (['paypal_paylater', 'paypal_card_fields', 'paypal_google_pay', 'paypal_apple_pay', 'paypal_venmo'] as $code) {
        contains('Necesita JavaScript.', $p->get($code)->info()['description'], $code);
    }
    notContains('JavaScript', $p->get('paypal_card')->info()['description'], 'las de redirección no lo necesitan');
});

test('la caja enseña Google Pay con su aviso de JavaScript, y las de redirección siguen ahí', function (): void {
    $app = frontShop(['modules' => ['payments-paypal'], 'settings' => ['modules.active' => ['orders', 'mail', 'media', 'jobs', 'payments-paypal'], PayPalSettings::enabledKey('google_pay') => 1]]);
    $app->settings()->setMany([PayPalSettings::CLIENT_ID_SANDBOX => PP_CLIENT, PayPalSettings::WEBHOOK_ID_SANDBOX => PP_WEBHOOK]);
    $app->setSecret(PayPalSettings::SECRET_SANDBOX, PP_SECRET);
    $web = new TestClient($app);
    frontAdd($web, frontProduct($app), 1);
    $r = $web->get('/finalizar-compra');

    contains('value="paypal_google_pay"', $r->body);
    contains('Paga con Google Pay en el paso siguiente, si tu navegador lo admite. Necesita JavaScript.', $r->body);
    contains('value="paypal"', $r->body);
    contains('value="paypal_card"', $r->body);
    foreach (['script-src', 'frame-src', 'connect-src'] as $directive) {
        foreach (isset(ppkCsp($r)[$directive]) ? ppkCsp($r)[$directive] : [] as $source) {
            notContains('paypal', $source, 'la caja no carga nada de PayPal: el guion va en la página de pago');
        }
    }
});

// ------------------------------------------------------------------ hacer el pedido y la página de pago

test('al hacer el pedido no se habla con PayPal: la caja lleva a la página de pago del módulo', function (): void {
    list($app, $fake, $p, $placed) = ppkPlaced('google_pay');
    same(PaymentAction::REDIRECT, $placed['action']->type);
    same('https://tienda.test' . ppkPayUrl($placed), $placed['action']->url);
    same([], ppCalls($fake, 'POST', '/v2/checkout/orders'), 'ninguna orden aún');
    $order = ppOrderRow($app, $placed);
    same('paypal_google_pay', $order['payment_method']);
    same('Google Pay', $order['payment_title']);
    same('pending_payment', $order['status']);
});

test('la página de pago lleva el guion de PayPal del entorno, con la moneda del pedido y solo el componente de su forma', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('google_pay');
    list($r, $cfg) = ppkOpen($web, $placed);

    same('no-store, private', $r->headers['Cache-Control']);
    same('google_pay', $cfg['source']);
    $params = [];
    parse_str((string) parse_url($cfg['sdk'], PHP_URL_QUERY), $params);
    same(0, strpos($cfg['sdk'], 'https://www.paypal.com/sdk/js?'));
    same(PP_CLIENT, $params['client-id'], 'el identificador del sandbox');
    same('googlepay', $params['components']);
    same('EUR', $params['currency']);
    same('ES', $params['buyer-country']);
    same(['https://pay.google.com/gp/p/js/pay.js'], $cfg['scripts']);
    same('20.00', $cfg['amount']);
    same(true, $cfg['test']);
    same('/pago/paypal/orden/' . $placed['number'] . '/' . $placed['secret'], $cfg['create']);
    same('/pago/paypal/capturar/' . $placed['number'] . '/' . $placed['secret'], $cfg['capture']);
    check(is_string($cfg['token']) && $cfg['token'] !== '', 'la ficha del formulario');
    notContains(PP_SECRET, $r->body, 'el secreto nunca');
    contains('/assets/m/payments-paypal/', $r->body, 'el guion propio, de la tienda');
    contains('pay.js', $r->body);
    contains('Pagar el pedido ' . $placed['number'], $r->body);
});

// [forma, ajustes, componentes, enable-funding, país, ¿mensaje?].
foreach ([
    ['paylater', [], 'buttons', 'paylater', 'ES', false],
    ['paylater', [PayPalSettings::PAYLATER_MESSAGE => 1], 'buttons,messages', 'paylater', 'ES', true],
    ['card_fields', [], 'card-fields', null, 'ES', false],
    ['apple_pay', [], 'applepay', null, 'ES', false],
    ['venmo', ['shop.currency' => 'USD'], 'buttons', 'venmo', 'US', false],
] as $case) {
    test('la página de pago de ' . $case[0] . ($case[5] ? ' con el mensaje de plazos' : '') . ' carga solo ' . $case[2], function () use ($case): void {
        list($app, $fake, $p, $placed, $web) = ppkPlaced($case[0], $case[4], $case[1], 3000);
        list($r, $cfg) = ppkOpen($web, $placed);
        $params = [];
        parse_str((string) parse_url($cfg['sdk'], PHP_URL_QUERY), $params);
        same($case[2], $params['components']);
        same($case[3], isset($params['enable-funding']) ? $params['enable-funding'] : null);
        same([], $cfg['scripts'], 'ningún otro guion');
        same($case[5], $cfg['message']);
        same($case[5], strpos($r->body, 'data-af-pp-message') !== false, 'el hueco del mensaje, solo si se pide');
        same($case[0] === 'card_fields', strpos($r->body, 'data-af-pp-card') !== false, 'los campos de la tarjeta, solo con la tarjeta');
    });
}

test('la política de contenido lleva PayPal (y Google) en la página de pago, con nonce y sin unsafe-inline', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('google_pay');
    list($r, $cfg) = ppkOpen($web, $placed);
    $csp = ppkCsp($r);
    foreach (['script-src', 'frame-src', 'connect-src', 'img-src'] as $directive) {
        check(in_array('https://*.paypal.com', $csp[$directive], true), $directive . ' deja pasar PayPal');
        check(in_array('https://*.paypalobjects.com', $csp[$directive], true), $directive . ' deja pasar paypalobjects');
    }
    check(in_array('https://pay.google.com', $csp['script-src'], true), 'el guion de Google Pay');
    check(in_array('https://pay.google.com', $csp['frame-src'], true));
    check(in_array("'nonce-" . $cfg['nonce'] . "'", $csp['script-src'], true), 'el nonce que pay.js pasa al guion de PayPal');
    check(!in_array("'unsafe-inline'", $csp['script-src'], true), 'nada de unsafe-inline en los guiones');
    check(!in_array('https://*.venmo.com', $csp['script-src'], true), 'Venmo solo con Venmo');
    same("'self'", $csp['default-src'][0]);
});

test('la política de la portada y de la ficha sigue como siempre aunque Google Pay esté encendida', function (): void {
    $app = frontShop(['modules' => ['payments-paypal'], 'settings' => ['modules.active' => ['orders', 'mail', 'media', 'jobs', 'payments-paypal'], PayPalSettings::enabledKey('google_pay') => 1]]);
    $app->settings()->setMany([PayPalSettings::CLIENT_ID_SANDBOX => PP_CLIENT]);
    $app->setSecret(PayPalSettings::SECRET_SANDBOX, PP_SECRET);
    $product = frontProduct($app);
    $web = new TestClient($app);
    $pages = ['/' => $web->get('/')];
    $data = $app->service('catalog')->product($product);
    $pages[(string) $data['url']] = $web->get((string) parse_url((string) $data['url'], PHP_URL_PATH));
    foreach ($pages as $path => $r) {
        same(200, $r->status, $path . ' abre');
        $csp = ppkCsp($r);
        check(isset($csp['script-src']), $path . ' lleva política');
        notContains('paypal', (string) $r->headers['Content-Security-Policy'], $path . ': nada de PayPal');
        notContains('pay.google.com', (string) $r->headers['Content-Security-Policy'], $path . ': nada de Google');
        notContains("'nonce-", (string) $r->headers['Content-Security-Policy']);
    }
});

test('la página de pago no lleva guiones en línea sin huella ni onclick: los datos van en data-config', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('card_fields');
    list($r) = ppkOpen($web, $placed);
    $csp = ppkCsp($r);
    foreach (ppkInlineScripts($r->body) as $js) {
        check(in_array(Csp::hash($js), $csp['script-src'], true), 'un guion en línea con su huella');
    }
    notContains(' onclick=', $r->body);
    notContains(' onsubmit=', $r->body);
    notContains('javascript:', $r->body);
    same(0, preg_match('/<script[^>]+src="https?:\/\//i', $r->body), 'ningún <script> de otra web escrito en la página: lo carga pay.js');
    contains('data-msg-unsupported="Este navegador no puede pagar con Tarjeta de crédito o débito. Elige otra forma de pago."', $r->body, 'los textos, en data-msg-*');
});

test('sin JavaScript la página lo dice y deja elegir otra forma de pago', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('apple_pay');
    list($r) = ppkOpen($web, $placed);
    check(preg_match('/<noscript>.*Esta forma de pago necesita JavaScript.*<\/noscript>/s', $r->body) === 1, 'el aviso sin JavaScript');
    contains('href="/pago/paypal/cancelado/' . $placed['number'] . '/' . $placed['secret'] . '"', $r->body, 'elegir otra forma de pago');
    contains('Elegir otra forma de pago', $r->body);
});

test('la página de pago de un pedido ajeno, con ficha mala o de otra forma de pago da 404; la de uno pagado lleva a gracias', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('google_pay');
    same(404, $web->get('/pago/paypal/pagar/' . $placed['number'] . '/ficha-de-mentira')->status);
    same(404, $web->get('/pago/paypal/pagar/9999-999999/' . $placed['secret'])->status);
    $other = ppPlaceWith($app, new TestClient($app), $p, 'paypal', ['billing' => ppBuyer('ES')]);
    same(404, $web->get(ppkPayUrl($other))->status, 'la cartera va por redirección, no por esta página');
    $app->db()->update('orders', ['payment_status' => 'paid', 'status' => 'completed'], ['id' => (int) $placed['id']]);
    $r = $web->get(ppkPayUrl($placed));
    same(302, $r->status);
    same('/pedido/' . $placed['number'] . '/' . $placed['secret'], $r->headers['Location']);
});

// ------------------------------------------------------------------ crear la orden

test('la ruta que crea la orden usa el importe y la moneda del pedido aunque el navegador mande otros', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('google_pay');
    list(, $cfg) = ppkOpen($web, $placed);
    list($status, $data) = ppkPost($web, $cfg['create'], ['_token' => $cfg['token'], 'amount' => '1', 'currency' => 'USD', 'total' => '0.01']);

    same(200, $status);
    same(true, $data['ok']);
    $body = ppBody(ppCalls($fake, 'POST', '/v2/checkout/orders')[0]);
    same('20.00', $body['purchase_units'][0]['amount']['value'], 'el importe del pedido');
    same('EUR', $body['purchase_units'][0]['amount']['currency_code'], 'la moneda del pedido');
    same((string) $placed['number'], $body['purchase_units'][0]['custom_id']);
    same(false, isset($body['payment_source']), 'Google Pay: lo añade el guion al confirmar');
    $attempt = ppAttempt($app, $placed);
    same(['paypal_google_pay', 'pending', (string) $data['id']], [$attempt['method'], $attempt['status'], $attempt['reference']]);
});

test('sin haber abierto la página de pago en esa sesión, crear y capturar dan 404 y no se habla con PayPal', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('google_pay');
    list(, $cfg) = ppkOpen($web, $placed);
    $stranger = new TestClient($app);
    same(404, $stranger->post($cfg['create'], [])->status, 'otra sesión no crea órdenes');
    same(404, $stranger->post($cfg['capture'], ['paypal_order' => 'PPORDER1'])->status);
    same(403, $stranger->post($cfg['create'], ['_token' => $cfg['token']])->status, 'ni con la ficha de otra sesión');
    same([], ppCalls($fake, 'POST', '/v2/checkout/orders'));
});

test('sin la ficha del formulario, o con una mala, la orden no se crea (403)', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('google_pay');
    list(, $cfg) = ppkOpen($web, $placed);
    same(403, $web->post($cfg['create'], [])->status, 'sin ficha');
    same(403, $web->post($cfg['create'], ['_token' => 'ficha-de-mentira'])->status, 'con una mala');
    same(403, $web->post($cfg['capture'], ['paypal_order' => 'PPORDER1'])->status, 'capturar tampoco');
    same([], ppCalls($fake, 'POST', '/v2/checkout/orders'));
});

test('cada reintento crea su orden con otro identificador de idempotencia, hasta diez; después, 429', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('card_fields');
    list(, $cfg) = ppkOpen($web, $placed);
    $ids = [];
    for ($i = 1; $i <= 10; $i++) {
        list($status, $data) = ppkPost($web, $cfg['create'], ['_token' => $cfg['token']]);
        same(200, $status, 'intento ' . $i);
        $ids[] = (string) $data['id'];
    }
    same(10, count(array_unique($ids)), 'diez órdenes distintas');
    $requestIds = array_map(static function ($r): string {
        return $r->headers['PayPal-Request-Id'];
    }, ppCalls($fake, 'POST', '/v2/checkout/orders'));
    same(10, count(array_unique($requestIds)));
    same(429, ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[0], 'el undécimo, no');
});

test('si PayPal no tiene activada la tarjeta en la página, la ruta lo dice, el historial lo explica y el pedido sigue sin pagar', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('card_fields');
    $fake->createResponse = ppJson(422, ['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'Not enabled ' . PP_SECRET, 'debug_id' => 'dbg-cf', 'details' => [['issue' => 'NOT_ENABLED_FOR_CARD_PROCESSING']]]);
    list(, $cfg) = ppkOpen($web, $placed);
    list($status, $data) = ppkPost($web, $cfg['create'], ['_token' => $cfg['token']]);
    same(502, $status);
    same(false, $data['ok']);
    $order = ppOrderRow($app, $placed);
    same('pending_payment', $order['status']);
    same(1, count(ppNotes($order, 'no está activada en tu cuenta de PayPal')));
    notContains(PP_SECRET, $status . json_encode($data) . json_encode($order['history'], JSON_UNESCAPED_UNICODE));
});

// ------------------------------------------------------------------ capturar

test('capturar marca el pedido pagado una vez, dice con qué se pagó y lleva a la página de gracias', function (): void {
    list($app, $fake, $placed, $web, $paypalOrder, $captured) = ppkPaid('google_pay');
    same(200, $captured[0]);
    same(true, $captured[1]['ok']);
    same('/pedido/' . $placed['number'] . '/' . $placed['secret'], $captured[1]['redirect']);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    $charges = ppPayments($order, 'charge');
    same(1, count($charges));
    same(['paypal_google_pay', 'CAP-' . $paypalOrder], [$charges[0]['method'], $charges[0]['reference']]);
    same('google_pay', $charges[0]['raw']['source']);
    same(1, count(ppNotes($order, 'Pagado con Google Pay por PayPal.')));
    same('captured', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
});

test('la captura repetida no cobra dos veces: PayPal recibe una sola captura', function (): void {
    list($app, $fake, $placed, $web, $paypalOrder, $captured, $cfg) = ppkPaid('paylater', 'ES', [], 3000);
    same(true, $captured[1]['ok']);
    list($status, $again) = ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $paypalOrder]);
    same(200, $status);
    same(true, $again['ok'], 'ya pagado: a la página de gracias');
    same(1, count(ppCalls($fake, 'POST', '/v2/checkout/orders/' . $paypalOrder . '/capture')), 'una captura');
    same(1, count(ppPayments(ppOrderRow($app, $placed), 'charge')));
    same(1, count(ppNotes(ppOrderRow($app, $placed), 'Pagado con Paga más tarde por PayPal.')));
});
