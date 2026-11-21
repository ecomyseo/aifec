<?php
/**
 * AIFEC — UCP: el perfil, la sesión de compra sobre la caja de la tienda y la confirmación en la tienda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\PaymentsRedsys\GooglePayCredential;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

require_once __DIR__ . '/UcpKit.php';

test('UCP: el perfil anuncia la API y la entrega con pago en tienda; apagado, 404', function (): void {
    [$app] = ucpApp();
    $r = ucpCall($app, 'GET', '/.well-known/ucp');
    same(200, $r->status, 'el perfil se sirve: ' . substr($r->body, 0, 200));
    $p = ucpJson($r)['ucp'];
    same('2026-08-25', $p['version'], 'la versión estable de UCP');
    same('https://tienda.test/ucp/v1', $p['services']['dev.ucp.shopping'][0]['endpoint'], 'la dirección de la API');
    same('rest', $p['services']['dev.ucp.shopping'][0]['transport'], 'por REST');
    check(isset($p['capabilities']['dev.ucp.shopping.checkout'][0]['version']), 'la compra');
    same('dev.ucp.shopping.checkout', $p['capabilities']['dev.ucp.shopping.fulfillment'][0]['extends'], 'la entrega amplía la compra');
    contains('"payment_handlers":{}', $r->body, 'el perfil no anuncia cobro desde la API');

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
    same('requires_escalation', $v['status'], 'con comprador, dirección y envío está lista: ' . json_encode($v['messages']));
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
    same('requires_escalation', $conTelefono['status'], 'con el teléfono del comprador, lista');
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

test('UCP: completar exige autorización y nunca solicita un cargo directo', function (): void {
    [$app, $p] = ucpApp();
    $view = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p)));
    $path = '/ucp/v1/checkout-sessions/' . $view['id'] . '/complete';
    same(401, ucpCall($app, 'POST', $path, ucpPayment())->status, 'la operación exige autorización');
    $completed = ucpJson(ucpCall($app, 'POST', $path, ucpPayment(), ['authorization' => 'Bearer ' . ucpKey($app)]));
    same('requires_escalation', $completed['status'], 'el usuario continúa en la tienda');
    same([], ucpHttp($app)->requests, 'no se llama al banco');
    same([], ucpOrders($app), 'todavía no hay pedido');
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

test('UCP: la caja abre el carrito y la cancelación bloquea los enlaces aún no usados', function (): void {
    [$app, $p] = ucpApp();
    $v = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 1)));
    $ruta = (string) parse_url((string) $v['continue_url'], PHP_URL_PATH);
    $r = ucpCall($app, 'GET', $ruta);
    same(302, $r->status, 'continue_url redirige');
    contains($app->url()->route('checkout'), (string) $r->headers['Location'], 'a la caja');
    same('no-referrer', $r->headers['Referrer-Policy'], 'sin pasar la ficha a nadie');

    same(409, ucpCall($app, 'POST', '/ucp/v1/checkout-sessions/' . $v['id'] . '/cancel')->status, 'la plataforma no cancela el pago que tiene abierto el comprador');
    $other = ucpJson(ucpCall($app, 'POST', '/ucp/v1/checkout-sessions', ucpBody($p, 1)));
    $path = '/ucp/v1/checkout-sessions/' . $other['id'];
    $c = ucpJson(ucpCall($app, 'POST', $path . '/cancel'));
    same('canceled', $c['status'], 'cancelada antes de abrir la caja');
    same(1, count($c['line_items']), 'con lo que tenía');
    same(409, ucpCall($app, 'PUT', $path, ucpBody($p, 2))->status, 'ya no se puede cambiar');
    same(410, ucpCall($app, 'GET', (string) parse_url($other['continue_url'], PHP_URL_PATH))->status, 'el enlace deja de funcionar');
});
