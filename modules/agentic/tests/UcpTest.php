<?php
/**
 * AIFEC — UCP: el perfil, la sesión de compra sobre la caja de la tienda y el cobro con Google Pay por Redsys.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\PaymentsRedsys\GooglePayCredential;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

require_once __DIR__ . '/UcpKit.php';

test('UCP: el perfil anuncia la API, la entrega y Google Pay por Redsys; apagado, 404', function (): void {
    [$app] = ucpApp();
    $r = ucpCall($app, 'GET', '/.well-known/ucp');
    same(200, $r->status, 'el perfil se sirve: ' . substr($r->body, 0, 200));
    $p = ucpJson($r)['ucp'];
    same('2026-04-08', $p['version'], 'la versión de UCP que usa Google');
    same('https://tienda.test/ucp/v1', $p['services']['dev.ucp.shopping'][0]['endpoint'], 'la dirección de la API');
    same('rest', $p['services']['dev.ucp.shopping'][0]['transport'], 'por REST');
    check(isset($p['capabilities']['dev.ucp.shopping.checkout'][0]['version']), 'la compra');
    same('dev.ucp.shopping.checkout', $p['capabilities']['dev.ucp.shopping.fulfillment'][0]['extends'], 'la entrega amplía la compra');
    $gpay = $p['payment_handlers']['com.google.pay'][0];
    same(RedsysSettings::GOOGLEPAY_HANDLER_ID, $gpay['id'], 'el manejador de Google Pay de la tienda');
    same('redsys', $gpay['config']['allowed_payment_methods'][0]['tokenization_specification']['parameters']['gateway'], 'cobra Redsys');
    same(RDS_MERCHANT, $gpay['config']['allowed_payment_methods'][0]['tokenization_specification']['parameters']['gatewayMerchantId'], 'con el código de comercio');
    same('TEST', $gpay['config']['environment'], 'en el entorno de pruebas');

    [$sin] = ucpApp([RedsysSettings::GOOGLEPAY => false]);
    $body = ucpCall($sin, 'GET', '/.well-known/ucp')->body;
    contains('"payment_handlers":{}', $body, 'sin Google Pay no se anuncia ninguna forma de pago (objeto vacío, no lista)');

    [$apagado] = ucpApp(['agentic.ucp_enabled' => false]);
    same(404, ucpCall($apagado, 'GET', '/.well-known/ucp')->status, 'UCP apagado: el perfil da 404');
    same(404, ucpCall($apagado, 'POST', '/ucp/v1/checkout-sessions', ucpBody(1))->status, 'y la API también');
});

test('UCP: crear la compra calcula líneas, envío y total con la caja de la tienda', function (): void {
    [$app, $p] = ucpApp();
    $r = ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 2));
    same(201, $r->status, 'la sesión se crea: ' . substr($r->body, 0, 300));
    $v = ucpJson($r);
    check(preg_match('/^chk_[a-f0-9]{32}$/D', (string) $v['id']) === 1, 'con un identificador al azar');
    same('ready_for_complete', $v['status'], 'con comprador, dirección y envío está lista: ' . json_encode($v['messages']));
    same('EUR', $v['currency'], 'en euros');
    same((string) $p, $v['line_items'][0]['item']['id'], 'el artículo es el id del catálogo (el mismo del feed de Google)');
    same('line_1', $v['line_items'][0]['id'], 'la línea');
    same(2, $v['line_items'][0]['quantity'], 'dos unidades');
    same(2000, $v['line_items'][0]['item']['price'], 'a 20 €');
    same(4000, ucpTotal($v, 'subtotal'), 'subtotal 40 €');
    same(495, ucpTotal($v, 'fulfillment'), 'el envío de la tarifa');
    same(4495, ucpTotal($v, 'total'), 'el total es el de la caja');
    same(null, ucpTotal($v, 'tax'), 'con precios con IVA no hay línea de impuestos aparte');
    same('Subtotal (impuestos incluidos)', $v['totals'][0]['display_text'], 'y el subtotal dice que lo lleva, en el idioma de la tienda');
    $method = $v['fulfillment']['methods'][0];
    same('Calle de Ejemplo 1', $method['destinations'][0]['street_address'], 'la dirección guardada');
    same('28013', $method['destinations'][0]['postal_code'], 'con su código postal');
    check(preg_match('/^carrier_\d+$/D', (string) $method['groups'][0]['selected_option_id']) === 1, 'y la entrega elegida');
    same('ana@cliente.test', $v['buyer']['email'], 'el comprador');
    contains('https://tienda.test/ucp/continue/', (string) $v['continue_url'], 'siempre hay una dirección para terminar en la tienda');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {ucp_checkouts}'), 'la sesión queda guardada');

    $leida = ucpJson(ucpCall($app, 'GET', '/ucp/v1/checkout-sessions/' . $v['id']));
    same($v['totals'], $leida['totals'], 'leerla otra vez da lo mismo');
    same(404, ucpCall($app, 'GET', '/ucp/v1/checkout-sessions/chk_' . str_repeat('0', 32))->status, 'una sesión que no existe: 404');
});

test('UCP: lo que falta se dice con su aviso, y nada se crea si no se puede comprar ningún artículo', function (): void {
    [$app, $p] = ucpApp();
    $v = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 1, false)));
    same('incomplete', $v['status'], 'sin correo no está lista');
    check(in_array('missing_buyer_info', ucpCodes($v), true), 'y lo dice: ' . json_encode($v['messages']));

    $r = ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ['line_items' => [['item' => ['id' => '999999'], 'quantity' => 1]]]);
    same(200, $r->status, 'un error de negocio va con 200');
    $e = ucpJson($r);
    same('error', $e['ucp']['status'], 'con el estado de error de UCP');
    same('unrecoverable', $e['messages'][0]['severity'], 'irrecuperable');
    check(isset($e['continue_url']), 'y la dirección de la tienda');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {ucp_checkouts}'), 'no se ha guardado una sesión vacía');

    // Sin teléfono no hay entrega: lo exige el transporte (16/11/2026). Se dice en su campo y, con él, queda lista.
    $sinTelefono = ucpBody($p, 1);
    unset($sinTelefono['fulfillment']['methods'][0]['destinations'][0]['phone_number']);
    $t = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', $sinTelefono));
    same('incomplete', $t['status'], 'sin teléfono no está lista');
    $paths = array_column($t['messages'], 'path');
    check(in_array('$.fulfillment.methods[0].destinations[0].phone_number', $paths, true), 'y dice que falta el teléfono: ' . json_encode($t['messages']));
    $conTelefono = ucpJson(ucpCall($app, 'PUT', '/ucp/v1/checkout-sessions/' . $t['id'], ['buyer' => ['email' => 'ana@cliente.test', 'phone_number' => '999000202']]));
    same('ready_for_complete', $conTelefono['status'], 'con el teléfono del comprador, lista');
    $app->db()->delete('ucp_checkouts', ['public_id' => (string) $t['id']]);

    same(400, ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', 'no es json')->status, 'un cuerpo que no es JSON: 400');
    same(400, ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ['line_items' => []])->status, 'sin líneas: 400');
});

test('UCP: cambiar la compra sustituye las líneas y elige la entrega', function (): void {
    [$app, $p] = ucpApp();
    $fast = ucpFast($app);
    $v = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 2)));
    $r = ucpCall($app, 'PUT', '/ucp/v1/checkout-sessions/' . $v['id'], [
        'line_items' => [['id' => 'line_1', 'item' => ['id' => (string) $p], 'quantity' => 1]],
        'fulfillment' => ['methods' => [['groups' => [['selected_option_id' => 'carrier_' . $fast]]]]],
    ]);
    same(200, $r->status, 'se cambia: ' . substr($r->body, 0, 300));
    $u = ucpJson($r);
    same(1, count($u['line_items']), 'una línea');
    same(1, $u['line_items'][0]['quantity'], 'con una unidad');
    same('carrier_' . $fast, $u['fulfillment']['methods'][0]['groups'][0]['selected_option_id'], 'el urgente elegido');
    same(2000 + 1295, ucpTotal($u, 'total'), 'el total con el urgente');
    $mal = ucpJson(ucpCall($app, 'PUT', '/ucp/v1/checkout-sessions/' . $v['id'], ['fulfillment' => ['methods' => [['groups' => [['selected_option_id' => 'carrier_999']]]]]]));
    check(in_array('missing_fulfillment_info', ucpCodes($mal), true), 'una entrega que no existe se dice');
});

test('UCP: completar con Google Pay cobra por Redsys y devuelve el pedido; sin la clave, 401', function (): void {
    [$app, $p] = ucpApp();
    $http = ucpHttp($app);
    $http->when('POST', 'trataPeticionREST', ucpBank('0000'));
    $v = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 2)));
    $sinClave = ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $v['id'] . '/complete', ucpPayment());
    same(401, $sinClave->status, 'sin la clave que la tienda dio a la plataforma no se cierra');
    same([], $http->requests, 'y no se ha llamado al banco');
    $soloLectura = ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $v['id'] . '/complete', ucpPayment(), ['authorization' => 'Bearer ' . ucpKey($app, ['catalog:read'])]);
    same(401, $soloLectura->status, 'una clave sin el permiso tampoco');

    $key = ucpKey($app);
    $r = ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $v['id'] . '/complete', ucpPayment(), ['authorization' => 'Bearer ' . $key]);
    same(200, $r->status, 'se completa: ' . substr($r->body, 0, 400));
    $c = ucpJson($r);
    same('completed', $c['status'], 'pedido hecho: ' . json_encode(isset($c['messages']) ? $c['messages'] : []));
    $orders = ucpOrders($app);
    same(1, count($orders), 'un pedido');
    same((string) $orders[0]['number'], $c['order']['id'], 'el número del pedido');
    contains('https://tienda.test/', (string) $c['order']['permalink_url'], 'con la dirección de su página');
    same('paid', (string) $orders[0]['payment_status'], 'pagado');
    same('googlepay', (string) $orders[0]['payment_method'], 'con Google Pay');
    same('ucp', (string) $orders[0]['source'], 'que vino por UCP');
    contains('plataforma.test', (string) $orders[0]['attribution'], 'atribuido a la plataforma');
    same('198.51.100.23', (string) $orders[0]['ip'], 'con la dirección del comprador que manda la plataforma');
    same(4495, (int) $orders[0]['total'], 'por el total de la caja');
    same(4495, ucpTotal($c, 'total'), 'el mismo que vio la plataforma');

    $sent = json_decode((string) $http->last()->body, true);
    $params = json_decode((string) base64_decode((string) $sent['Ds_MerchantParameters']), true);
    same('Google', $params['DS_XPAYTYPE'], 'el banco recibe un pago de Google Pay');
    contains('cifrado-de-mentira', (string) base64_decode((string) $params['DS_XPAYDATA']), 'con el token de la plataforma');
    same('4495', $params['DS_MERCHANT_AMOUNT'], 'por el total');
    same(null, GooglePayCredential::current(), 'el token no se queda en memoria');
    notContains('cifrado-de-mentira', (string) json_encode($c), 'ni sale en la respuesta');
    notContains('cifrado-de-mentira', (string) $app->db()->value('SELECT snapshot FROM {ucp_checkouts}'), 'ni se guarda en la sesión');
    same('pi_1', $c['payment']['instruments'][0]['id'], 'el instrumento se devuelve sin la credencial');

    $otra = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $v['id'] . '/complete', ucpPayment(), ['authorization' => 'Bearer ' . $key]));
    same('completed', $otra['status'], 'repetirla devuelve la misma compra');
    same(1, count(ucpOrders($app)), 'sin un segundo pedido');
    same(1, count($http->requests), 'ni un segundo cobro');
    same('completed', ucpJson(ucpCall($app, 'GET', '/ucp/v1/checkout-sessions/' . $v['id']))['status'], 'y leerla dice lo mismo');
});

test('UCP: un pago rechazado deja la compra abierta para otra tarjeta; con 3D Secure, a la caja de la tienda', function (): void {
    [$app, $p] = ucpApp();
    $key = ucpKey($app);
    $http = ucpHttp($app);
    $respuestas = ['0190', '0000'];
    $http->when('POST', 'trataPeticionREST', static function ($request) use (&$respuestas) {
        $answer = ucpBank((string) array_shift($respuestas));

        return $answer($request);
    });
    $v = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 1)));
    $no = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $v['id'] . '/complete', ucpPayment(), ['authorization' => 'Bearer ' . $key]));
    check(in_array('payment_declined', ucpCodes($no), true), 'rechazado lo dice: ' . json_encode($no['messages']));
    check($no['status'] !== 'completed', 'no hay compra');
    same(1, count($no['line_items']), 'el carrito sigue ahí');
    same(null, GooglePayCredential::current(), 'el token no se queda en memoria');
    $si = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $v['id'] . '/complete', ucpPayment(), ['authorization' => 'Bearer ' . $key]));
    same('completed', $si['status'], 'con otra tarjeta sale: ' . json_encode(isset($si['messages']) ? $si['messages'] : []));
    $pagados = array_filter(ucpOrders($app), static function (array $o): bool {
        return (string) $o['payment_status'] === 'paid';
    });
    same(1, count($pagados), 'un solo pedido pagado');

    [$app2, $p2] = ucpApp();
    ucpHttp($app2)->when('POST', 'trataPeticionREST', ucpBank('0195'));
    $w = ucpJson(ucpCall($app2, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p2, 1)));
    $tds = ucpJson(ucpCall($app2, 'POST', '/ucp/v1/checkout-sessions/' . $w['id'] . '/complete', ucpPayment(), ['authorization' => 'Bearer ' . ucpKey($app2)]));
    same('requires_escalation', $tds['status'], 'si el banco pide autenticar, se termina en la tienda');
    contains('/ucp/continue/', (string) $tds['continue_url'], 'con la dirección para hacerlo');
});

test('UCP: sin Google Pay la compra se termina en la caja de la tienda, sin llamar al banco', function (): void {
    [$app, $p] = ucpApp([RedsysSettings::GOOGLEPAY => false]);
    $http = ucpHttp($app);
    $v = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 1)));
    same('requires_escalation', $v['status'], 'sin forma de pago para plataformas, a la tienda');
    $c = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $v['id'] . '/complete', ucpPayment(), ['authorization' => 'Bearer ' . ucpKey($app)]));
    same('requires_escalation', $c['status'], 'completar tampoco cobra');
    same([], ucpOrders($app), 'ningún pedido');
    same([], $http->requests, 'ni ninguna llamada al banco');

    [$app2, $p2] = ucpApp();
    $w = ucpJson(ucpCall($app2, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p2, 1)));
    $otro = ucpJson(ucpCall($app2, 'POST', '/ucp/v1/checkout-sessions/' . $w['id'] . '/complete', ucpPayment('otro-manejador'), ['authorization' => 'Bearer ' . ucpKey($app2)]));
    same('requires_escalation', $otro['status'], 'un instrumento de otro manejador no se cobra');
    check(in_array('payment_ineligible', ucpCodes($otro), true), 'y se dice');
});

test('UCP: Idempotency-Key repite la respuesta; con otro cuerpo es un conflicto', function (): void {
    [$app, $p] = ucpApp();
    $a = ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 1), ['idempotency-key' => 'clave-1']);
    $b = ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 1), ['idempotency-key' => 'clave-1']);
    same(201, $b->status, 'la misma respuesta');
    same(ucpJson($a)['id'], ucpJson($b)['id'], 'la misma sesión');
    same('true', isset($b->headers['Idempotent-Replayed']) ? $b->headers['Idempotent-Replayed'] : '', 'marcada como repetida');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {ucp_checkouts}'), 'una sola sesión');
    same(409, ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 3), ['idempotency-key' => 'clave-1'])->status, 'otro cuerpo con la misma clave: 409');
});

test('UCP: cancelar cierra la compra; continue_url abre el carrito en la caja y, hecho el pedido, su página', function (): void {
    [$app, $p] = ucpApp();
    $v = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 1)));
    $ruta = (string) parse_url((string) $v['continue_url'], PHP_URL_PATH);
    $r = ucpCall($app, 'GET', $ruta);
    same(302, $r->status, 'continue_url redirige');
    contains($app->url()->route('checkout'), (string) $r->headers['Location'], 'a la caja');
    same('no-referrer', $r->headers['Referrer-Policy'], 'sin pasar la ficha a nadie');

    $c = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $v['id'] . '/cancel'));
    same('canceled', $c['status'], 'cancelada');
    same(1, count($c['line_items']), 'con lo que tenía');
    same(409, ucpCall($app, 'PUT', '/ucp/v1/checkout-sessions/' . $v['id'], ucpBody($p, 2))->status, 'ya no se puede cambiar');
    same(409, ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $v['id'] . '/complete', ucpPayment(), ['authorization' => 'Bearer ' . ucpKey($app)])->status, 'ni completar');
    same(404, ucpCall($app, 'GET', '/ucp/continue/' . str_repeat('a', 48))->status, 'un carrito que no existe: 404');

    [$app2, $p2] = ucpApp();
    ucpHttp($app2)->when('POST', 'trataPeticionREST', ucpBank('0000'));
    $w = ucpJson(ucpCall($app2, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p2, 1)));
    ucpCall($app2, 'POST', '/ucp/v1/checkout-sessions/' . $w['id'] . '/complete', ucpPayment(), ['authorization' => 'Bearer ' . ucpKey($app2)]);
    $hecho = ucpCall($app2, 'GET', (string) parse_url((string) $w['continue_url'], PHP_URL_PATH));
    same(302, $hecho->status, 'hecho el pedido, también redirige');
    contains((string) ucpOrders($app2)[0]['number'], (string) $hecho->headers['Location'], 'a la página del pedido');
});
