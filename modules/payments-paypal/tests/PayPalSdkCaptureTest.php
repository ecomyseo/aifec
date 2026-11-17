<?php
/**
 * AIFEC — pruebas de la captura de las formas del guion de PayPal (otra orden, rechazos, fallos pasajeros, 3-D Secure, en
 * revisión), el aviso tardío, la vuelta de 3-D Secure fuera de la página, el fichero de Apple Pay, que los datos de la
 * tarjeta no pasan por la tienda, el guion propio, las devoluciones y los ajustes de las formas nuevas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Money;
use Aifec\Http\HttpException;
use Aifec\Modules\PaymentsPaypal\PayPalPayment;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;

require_once __DIR__ . '/PayPalSdkKit.php';

test('una orden de PayPal de otro pedido no se captura con este (404)', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('google_pay');
    list(, $cfg) = ppkOpen($web, $placed);
    // Otro pedido del mismo comprador, con su orden de PayPal.
    $other = ppPlaceWith($app, $web, $p, 'paypal_google_pay', ['billing' => ppBuyer('ES')]);
    list(, $otherCfg) = ppkOpen($web, $other);
    $otherOrder = (string) ppkPost($web, $otherCfg['create'], ['_token' => $otherCfg['token']])[1]['id'];

    $r = $web->post($cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $otherOrder]);
    same(404, $r->status);
    same(404, $web->post($cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => ''])->status, 'sin orden, tampoco');
    same([], ppCalls($fake, 'POST', '/capture'));
    same('pending', ppOrderRow($app, $placed)['payment_status']);
});

test('una captura denegada deja el pedido sin pagar, lo dice y deja volver a intentarlo con otra orden', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('card_fields');
    list(, $cfg) = ppkOpen($web, $placed);
    $first = (string) ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[1]['id'];
    $order = ppOrderRow($app, $placed);
    $fake->captureResponse = ppJson(201, ppCapturedOrder($first, Money::decimal((int) $order['total']), 'EUR', (string) $order['number'], 'DECLINED'));

    list($status, $data) = ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $first]);
    same(422, $status);
    same(false, $data['ok']);
    $order = ppOrderRow($app, $placed);
    same('pending', $order['payment_status'], 'sin pagar');
    same('pending_payment', $order['status'], 'y se puede volver a intentar');
    same('failed', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    same(1, count(ppNotes($order, 'PayPal ha denegado el pago (DECLINED)')), 'el historial lo dice');

    $fake->captureResponse = null;
    $second = (string) ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[1]['id'];
    check($second !== $first, 'otra orden');
    same(true, ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $second])[1]['ok']);
    same('paid', ppOrderRow($app, $placed)['payment_status']);
    same(1, count(ppPayments(ppOrderRow($app, $placed), 'charge')));
});

test('un «no» de PayPal al capturar (instrumento rechazado) también deja el pedido sin pagar y la orden fallida', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('apple_pay');
    list(, $cfg) = ppkOpen($web, $placed);
    $id = (string) ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[1]['id'];
    $fake->captureResponse = ppJson(422, ['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The instrument presented was declined.', 'debug_id' => 'dbg-ap', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]]);

    list($status) = ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $id]);
    same(422, $status);
    $order = ppOrderRow($app, $placed);
    same('pending_payment', $order['status']);
    same('failed', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    same(1, count(ppNotes($order, 'INSTRUMENT_DECLINED')));
});

test('PayPal caído al capturar: 503 y la orden libre; al reintentar se cobra una vez', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('paylater', 'ES', [], 3000);
    list(, $cfg) = ppkOpen($web, $placed);
    $id = (string) ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[1]['id'];
    $fake->captureResponse = new HttpException('connection reset');

    same(503, ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $id])[0]);
    same('pending', ppAttempt($app, $placed)['status']);
    $fake->captureResponse = null;
    same(true, ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $id])[1]['ok']);
    same('paid', ppOrderRow($app, $placed)['payment_status']);
    same(1, count(ppPayments(ppOrderRow($app, $placed), 'charge')));
});

test('tarjeta en la página: con 3-D Secure fallido (NO, inscrita) no se captura; con POSSIBLE, sí', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('card_fields');
    list(, $cfg) = ppkOpen($web, $placed);
    $id = (string) ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[1]['id'];
    $fake->getResponse = ppJson(200, ppkThreeDs($id, 'NO', 'Y', 'N'));

    list($status, $data) = ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $id]);
    same(422, $status);
    same(false, $data['ok']);
    same([], ppCalls($fake, 'POST', '/capture'), 'sin captura');
    $order = ppOrderRow($app, $placed);
    same('pending_payment', $order['status']);
    same(1, count(ppNotes($order, '3-D Secure no ha autenticado la tarjeta (NO/Y/N)')));

    $second = (string) ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[1]['id'];
    $fake->getResponse = ppJson(200, ppkThreeDs($second, 'POSSIBLE', 'Y', 'Y'));
    same(true, ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $second])[1]['ok']);
    same(1, count(ppCalls($fake, 'POST', '/v2/checkout/orders/' . $second . '/capture')));
    same('paid', ppOrderRow($app, $placed)['payment_status']);
});

test('Google Pay con 3-D Secure UNKNOWN no se captura; Apple Pay no consulta 3-D Secure', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('google_pay');
    list(, $cfg) = ppkOpen($web, $placed);
    $id = (string) ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[1]['id'];
    $fake->getResponse = ppJson(200, ppkThreeDs($id, 'UNKNOWN', 'U', '', 'google_pay'));
    same(422, ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $id])[0]);
    same([], ppCalls($fake, 'POST', '/capture'));

    list($app2, $fake2, $placed2) = ppkPaid('apple_pay');
    same([], ppCalls($fake2, 'GET', '/v2/checkout/orders/'), 'Apple Pay: sin consulta previa');
    same('paid', ppOrderRow($app2, $placed2)['payment_status']);
});

test('una captura en revisión deja el pedido en espera, sin pagar; su página de pago ya no deja pagar otra vez', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('google_pay');
    list(, $cfg) = ppkOpen($web, $placed);
    $id = (string) ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[1]['id'];
    $order = ppOrderRow($app, $placed);
    $fake->captureResponse = ppJson(201, ppCapturedOrder($id, Money::decimal((int) $order['total']), 'EUR', (string) $order['number'], 'PENDING'));

    same(true, ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $id])[1]['ok']);
    $order = ppOrderRow($app, $placed);
    same('on_hold', $order['status']);
    same('pending', $order['payment_status']);
    same('review', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    same(302, $web->get(ppkPayUrl($placed))->status, 'la página lleva al pedido');
    same(409, ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[0], 'y no se crea otra orden');

    same('OK', ppNotify($app, $fake, ppCompleted($order, $id, 'CAP-' . $id))->body);
    same('paid', ppOrderRow($app, $placed)['payment_status'], 'el aviso la cierra');
});

test('el aviso tardío de PayPal paga un pedido de Google Pay aunque el navegador no llegara a capturar', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('google_pay');
    list(, $cfg) = ppkOpen($web, $placed);
    $id = (string) ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[1]['id'];
    $order = ppOrderRow($app, $placed);

    same('OK', ppNotify($app, $fake, ppCompleted($order, $id, 'CAP-' . $id))->body);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same('paypal_google_pay', ppPayments($order, 'charge')[0]['method']);
    same('captured', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    same(1, count(ppNotes($order, 'Pagado con Google Pay por PayPal.')));
    // Si después llega la captura del navegador, no cobra otra vez.
    same(true, ppkPost($web, $cfg['capture'], ['_token' => $cfg['token'], 'paypal_order' => $id])[1]['ok']);
    same([], ppCalls($fake, 'POST', '/capture'));
    same(1, count(ppPayments(ppOrderRow($app, $placed), 'charge')));
});

test('la vuelta de 3-D Secure fuera de la página tampoco captura una tarjeta sin respaldo del banco', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('card_fields');
    list(, $cfg) = ppkOpen($web, $placed);
    $id = (string) ppkPost($web, $cfg['create'], ['_token' => $cfg['token']])[1]['id'];
    $fake->getResponse = ppJson(200, ppkThreeDs($id, 'UNKNOWN', 'Y', 'U'));

    $r = ppBack($web, $placed, $id);
    same(302, $r->status);
    same('/finalizar-compra', $r->headers['Location']);
    same([], ppCalls($fake, 'POST', '/capture'));
    same('failed', ppOrderRow($app, $placed)['payment_status']);
});

// ------------------------------------------------------------------ Apple Pay: el fichero del dominio

test('el fichero de Apple Pay se sirve tal cual en /.well-known, como binario, y da 404 si está vacío', function (): void {
    $app = ppApp([PayPalSettings::APPLE_PAY_FILE => PPK_APPLE_FILE]);
    $web = new TestClient($app);
    $r = $web->get('/.well-known/apple-developer-merchantid-domain-association');
    same(200, $r->status);
    same(PPK_APPLE_FILE, $r->body);
    same(0, strpos((string) $r->headers['Content-Type'], 'application/octet-stream'));
    same(false, isset($r->headers['Location']), 'sin redirecciones');

    $app->settings()->set(PayPalSettings::APPLE_PAY_FILE, "  \n ");
    same(404, (new TestClient($app))->get('/.well-known/apple-developer-merchantid-domain-association')->status);
    same(404, (new TestClient(ppApp()))->get('/.well-known/apple-developer-merchantid-domain-association')->status, 'sin pegar nada, 404');
});

// ------------------------------------------------------------------ los datos de la tarjeta no pasan por la tienda

test('los datos de la tarjeta no llegan a la tienda: la página no tiene campos de tarjeta y lo que se mande de más no se guarda', function (): void {
    list($app, $fake, $p, $placed, $web) = ppkPlaced('card_fields');
    list($r, $cfg) = ppkOpen($web, $placed);
    same(0, preg_match('/<input[^>]+(card|cvv|cvc|expiry|number)/i', $r->body), 'ningún <input> de tarjeta: los campos los pinta PayPal');
    $card = ['_token' => $cfg['token'], 'number' => '4111111111111111', 'card_number' => '4111111111111111', 'cvv' => '123', 'expiry' => '12/30'];
    $id = (string) ppkPost($web, $cfg['create'], $card)[1]['id'];
    ppkPost($web, $cfg['capture'], $card + ['paypal_order' => $id]);
    same('paid', ppOrderRow($app, $placed)['payment_status']);

    $everything = json_encode([
        'log' => $app->log()->records, 'payments' => $app->db()->select('SELECT * FROM {order_payments}'),
        'history' => $app->db()->select('SELECT * FROM {order_history}'), 'orders' => $app->db()->select('SELECT * FROM {orders}'),
        'paypal' => array_map(static function ($req): string {
            return (string) $req->body;
        }, $fake->http->requests),
    ], JSON_UNESCAPED_UNICODE);
    notContains('4111111111111111', (string) $everything, 'ni en la base, ni en el registro, ni en lo que se manda a PayPal');
    notContains('"cvv"', (string) $everything);
});

test('pay.js solo manda a la tienda la ficha y la orden de PayPal, cabe en 9 000 bytes y no lleva textos ni direcciones de fuera', function (): void {
    $js = ppkScript();
    check(strlen($js) <= 9000, 'pesa ' . strlen($js) . ' bytes');
    same(1, preg_match_all('/_token=/', $js), 'una sola forma de montar lo que se manda');
    preg_match_all('/[\'"]&?([a-z_]+)=[\'"]/', $js, $fields);
    same(['_token', 'paypal_order'], array_values(array_unique(array_map(static function (string $f): string {
        return ltrim($f, '&');
    }, $fields[1]))), 'solo la ficha y la orden de PayPal');
    same(0, preg_match('#https?://#', $js), 'las direcciones de fuera las da el servidor');
    // Ningún texto para una persona: las cadenas del guion no llevan espacios entre palabras (los textos, en data-msg-*).
    preg_match_all("/'([^'\\\\]*)'/", $js, $strings);
    foreach (array_diff($strings[1], ['use strict']) as $s) {
        if (strpos($s, ' ') === false) {
            continue;
        }
        // Con espacios solo se admiten listas de clases con guion («af-btn af-btn-lg»): nunca una frase.
        foreach (explode(' ', $s) as $token) {
            same(1, preg_match('/^[a-z][a-z0-9]*(-[a-z0-9]+)+$/', $token), 'sin frases dentro del guion: «' . $s . '»');
        }
    }
    notContains('innerHTML', $js, 'nada se pinta como HTML');
    notContains('eval(', $js);
    contains('data-csp-nonce', $js, 'el guion de PayPal recibe el nonce de la política de contenido');
});

// ------------------------------------------------------------------ devoluciones

foreach ([['paylater', 'ES', [], 3000], ['card_fields', 'ES', [], 2000], ['google_pay', 'ES', [], 2000], ['apple_pay', 'ES', [], 2000], ['venmo', 'US', ['shop.currency' => 'USD'], 2000]] as $case) {
    test('un pedido pagado con ' . $case[0] . ' se devuelve por PayPal sobre su captura', function () use ($case): void {
        list($app, $fake, $placed, $web, $paypalOrder, $captured) = ppkPaid($case[0], $case[1], $case[2], $case[3]);
        same(true, $captured[1]['ok']);
        $app->service('orders')->refund((int) $placed['id'], 500, [], 'Devolución parcial', true, false);
        $calls = ppCalls($fake, 'POST', '/v2/payments/captures/CAP-' . $paypalOrder . '/refund');
        same(1, count($calls));
        same('5.00', ppBody($calls[0])['amount']['value']);
        $refunds = ppPayments(ppOrderRow($app, $placed), 'refund');
        same(1, count($refunds));
        same('paypal_' . $case[0], $refunds[0]['method']);
    });
}

// ------------------------------------------------------------------ ajustes

test('las casillas, el orden, el mensaje de plazos y el fichero de Apple Pay se guardan como los manda un navegador', function (): void {
    $app = ppApp([], false);
    $web = adminClient($app);
    $form = ppForm([
        PayPalSettings::positionKey('google_pay') => '1', PayPalSettings::positionKey('apple_pay') => '2',
        PayPalSettings::PAYLATER_MESSAGE => '1', PayPalSettings::APPLE_PAY_FILE => PPK_APPLE_FILE,
    ] + ppSourcesForm(['paypal', 'card', 'google_pay', 'apple_pay', 'paylater']));

    same(302, ppSave($web, $form, 'ppsources')->status);
    $app->settings()->reload();
    $settings = new PayPalSettings($app);
    foreach (['google_pay' => true, 'apple_pay' => true, 'paylater' => true, 'card_fields' => false, 'venmo' => false] as $source => $on) {
        same($on, $settings->enabled($source), $source);
    }
    same(1, $settings->position('google_pay'));
    same(2, $settings->position('apple_pay'));
    same(true, $settings->payLaterMessage());
    same(PPK_APPLE_FILE, $settings->applePayFile());

    $page = $web->get('/admin/settings/payments-paypal')->body;
    check(preg_match('/<input type="checkbox"[^>]*name="f\[payments\.paypal\.source_google_pay\]"[^>]*checked/', $page) === 1, 'Google Pay sale marcada');
    check(preg_match('/<input type="checkbox"[^>]*name="f\[payments\.paypal\.source_venmo\]"[^>]*>/', $page, $venmo) === 1 && strpos($venmo[0], 'checked') === false, 'Venmo, sin marcar');
    same('1', inputValue($page, 'f[' . PayPalSettings::positionKey('google_pay') . ']'));
    contains(PPK_APPLE_FILE, $page, 'el fichero vuelve a la pantalla');

    // Desmarcado: el navegador solo manda el oculto «0».
    same(302, ppSave($web, ppForm(), 'ppsources')->status);
    $app->settings()->reload();
    same(false, (new PayPalSettings($app))->enabled('google_pay'));
    same(false, (new PayPalSettings($app))->payLaterMessage());
});

test('la pantalla explica paso a paso cómo registrar el dominio para Apple Pay, con la guía de PayPal, la dirección que hay que comprobar y cómo está ahora', function (): void {
    $page = adminClient(ppApp([], false))->get('/admin/settings/payments-paypal')->body;
    contains('Apple Pay: registrar el dominio, paso a paso', $page);
    same(1, preg_match('~<ol class="af-hint" data-af-applepay-steps>(.*?)</ol>~s', $page, $list), 'los pasos van en una lista numerada');
    same(6, substr_count($list[1], '<li>'), 'seis pasos');
    // En orden: activar en PayPal, descargar, pegar, comprobar, registrar y encender.
    $order = ['«Apps &amp; Credentials»', 'Descarga el fichero de asociación del dominio', 'pégalo en «Fichero del dominio para Apple Pay»', 'Comprueba que tu tienda lo sirve',
        '«Add Domain»', 'Enciende «Apple Pay»'];
    $last = -1;
    foreach ($order as $piece) {
        $at = strpos($list[1], $piece);
        check($at !== false && $at > $last, 'está y va en su sitio: ' . $piece);
        $last = (int) $at;
    }
    contains('«Features», marca «Apple Pay»', $list[1]);
    contains('pulsa «Manage»', $list[1]);
    contains('pulsa «Register Domain»', $list[1]);
    contains('href="https://developer.paypal.com/docs/checkout/apm/apple-pay/" target="_blank" rel="noopener noreferrer"', $list[1], 'la guía de PayPal, de donde se descarga el fichero');
    contains('href="https://tienda.test/.well-known/apple-developer-merchantid-domain-association"', $list[1], 'la dirección de ESTA tienda que hay que comprobar');
    contains('se registra por separado', $page);
    contains('No hace falta una cuenta de desarrollador de Apple', $page);
    contains('Ahora: la tienda todavía no tiene fichero del dominio', $page, 'sin fichero, lo dice');
    notContains('Esta tienda vive en una carpeta del dominio', $page, 'en la raíz del dominio no sale el aviso de la carpeta');

    $with = adminClient(ppApp([PayPalSettings::APPLE_PAY_FILE => PPK_APPLE_FILE], false))->get('/admin/settings/payments-paypal')->body;
    contains('Ahora: la tienda tiene un fichero del dominio y lo sirve.', $with, 'con fichero, también');
    notContains('todavía no tiene fichero del dominio', $with);
});

test('la pestaña «Formas de pago» enseña las del guion, qué hay que activar y que el pago contra factura aún no se ofrece', function (): void {
    $page = adminClient(ppApp([], false))->get('/admin/settings/payments-paypal')->body;
    contains('Con el guion de PayPal, en la página de pago', $page);
    contains('Paga más tarde con PayPal', $page);
    contains('Tarjeta de crédito o débito en la página de pago', $page);
    contains('Activa Google Pay en tu aplicación de PayPal y acepta las condiciones de Google Pay.', $page);
    contains('registra este dominio en PayPal y pega abajo el fichero del dominio', $page);
    contains('Activa los pagos avanzados con tarjeta en tu cuenta de PayPal.', $page);
    contains('36 países', $page);
    contains('Aún no se ofrecen:', $page);
    contains('Pago contra factura', $page);
    contains('la caja no pide', $page, 'y por qué');
    contains('/.well-known/apple-developer-merchantid-domain-association', $page);
    contains('cargan el guion de PayPal solo en la página donde paga el cliente', $page);
});
