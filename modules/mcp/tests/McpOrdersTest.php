<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La compra entera por MCP, hasta el pedido: el total exacto, la confirmación de la persona, la clave con su ámbito y los topes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/**
 * Una sesión con lo comprado en el carrito y la caja rellena con la forma de pago dada; devuelve la sesión y la revisión.
 *
 * @param array<int,array<int,int>> $lineas
 *
 * @return array{0:string,1:array<string,mixed>}
 */
function mcpBuy(App $app, array $lineas, string $pago = 'transfer'): array
{
    $sid = mcpSession($app);
    foreach ($lineas as $linea) {
        $added = mcpTool($app, 'cart_add', ['product_id' => $linea[0], 'qty' => isset($linea[1]) ? $linea[1] : 1], $sid);
        same(false, $added['isError'], json_encode($added));
    }
    $customer = mcpTool($app, 'checkout_set_customer', ['text' => CHAT_DATA], $sid);
    same(false, $customer['isError'], json_encode($customer));
    $options = mcpTool($app, 'checkout_shipping_options', [], $sid)['structuredContent']['options'];
    $shipping = mcpTool($app, 'checkout_set_shipping', ['carrier_id' => $options[0]['id']], $sid);
    same(false, $shipping['isError'], json_encode($shipping));
    $payment = mcpTool($app, 'checkout_set_payment', ['method' => $pago], $sid);
    same(false, $payment['isError'], json_encode($payment));
    $review = mcpTool($app, 'checkout_review', [], $sid);
    same(false, $review['isError'], json_encode($review));

    return [$sid, $review['structuredContent']];
}

/**
 * @param array<string,mixed> $args
 *
 * @return array<string,mixed>
 */
function mcpPlace(App $app, string $sid, string $key, array $args): array
{
    return mcpTool($app, 'checkout_place', $args, $sid, ['authorization' => 'Bearer ' . $key]);
}

test('la compra entera por MCP: carrito, datos, envío, pago, revisión y pedido, con el total exacto y el cobro en manos de la persona', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create'], 'Asistente de pruebas');
    [$sid, $review] = mcpBuy($app, [[$ids['silla_roja'], 2]]);

    same(true, $review['ready']);
    same(2 * 5990 + 495, $review['total_cents'], 'el total es la suma exacta de líneas y envío');
    contains('124,75', $review['total']);
    same(64, strlen($review['confirm_token']));
    check(strpos(json_encode($review), 'Calle Mayor 1') === false && strpos(json_encode($review), 'ana@correo.test') === false, 'lo personal sale enmascarado');
    same(0, count(apiOrders($app)), 'revisar no hace nada');

    $placed = mcpPlace($app, $sid, $key, ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true]);

    same(false, $placed['isError'], json_encode($placed));
    $data = $placed['structuredContent'];
    same(true, $data['ok']);
    same($review['total_cents'], $data['total_cents']);
    contains('124,75', $data['total']);
    contains('https://tienda.test/', $data['order_page']);
    same('instructions', $data['payment']['type']);
    check($data['payment']['instructions'] !== '', 'cómo paga la persona');
    $orders = apiOrders($app);
    same(1, count($orders));
    same('mcp', $orders[0]['source']);
    same($data['number'], $orders[0]['number']);
    same($review['total_cents'], (int) $orders[0]['total'], 'el pedido guarda el total que se revisó');
    same('mcp', json_decode((string) $orders[0]['attribution'], true)['channel']);
    $audit = $app->db()->row("SELECT * FROM {audit} WHERE action = 'order.place'");
    same('MCP: Asistente de pruebas', $audit['user_name']);
    same((string) $orders[0]['id'], $audit['entity_id']);
    same('mcp', $app->db()->value('SELECT source FROM {carts} WHERE token = ?', [hash('sha256', \Aifec\Modules\Mcp\Sessions::token($sid))]));
    same(0, mcpTool($app, 'cart_view', [], $sid)['structuredContent']['count'], 'la sesión empieza otro carrito vacío');
    same(false, mcpTool($app, 'cart_add', ['product_id' => $ids['mesa']], $sid)['isError'], 'y se puede volver a comprar en la misma sesión');
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
});

test('con una pasarela, el resultado trae la dirección donde paga la persona: el asistente no cobra nada', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    [$sid, $review] = mcpBuy($app, [[$ids['mesa'], 1]], 'gw');

    $data = mcpPlace($app, $sid, $key, ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true])['structuredContent'];

    same(true, $data['ok'], json_encode($data));
    same('redirect', $data['payment']['type']);
    contains('https://pasarela.test/pagar/', $data['payment']['redirect']['url']);
    same((string) $data['order_page'] . '/pay', (string) $data['payment']['pay_url'], 'la dirección que la persona abre para pagar');
    same('pending', apiOrders($app)[0]['payment_status'], 'sigue sin pagar');
});

test('con el ajuste en «solo con clave»: sin clave o con una clave sin el ámbito orders:create, checkout_place da error y no hace pedido', function (): void {
    $app = mcpApp(['api.agent_orders' => 'key']);
    $ids = assistantShop($app);
    [$sid, $review] = mcpBuy($app, [[$ids['silla_roja'], 1]]);
    $args = ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true];

    $none = mcpTool($app, 'checkout_place', $args, $sid);
    same(true, $none['isError']);
    contains('API key', $none['structuredContent']['error']);
    $weak = mcpPlace($app, $sid, apiKey($app, [], 'Solo lectura'), $args);
    same(true, $weak['isError']);
    contains('orders:create', $weak['structuredContent']['error']);
    same(0, count(apiOrders($app)));
    same(false, mcpPlace($app, $sid, apiKey($app, ['orders:create'], 'Buena'), $args)['isError'], 'con el ámbito, sí');
    same(1, count(apiOrders($app)));
});

test('sin buyer_confirmed verdadero no hay pedido: ni falso, ni ausente, ni como texto, ni como número; sin ficha, tampoco', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    [$sid, $review] = mcpBuy($app, [[$ids['silla_roja'], 1]]);
    $good = $review['confirm_token'];

    foreach ([['confirm_token' => $good], ['confirm_token' => $good, 'buyer_confirmed' => false], ['confirm_token' => $good, 'buyer_confirmed' => 'true'], ['confirm_token' => $good, 'buyer_confirmed' => 1], ['confirm_token' => $good, 'buyer_confirmed' => 'yes']] as $args) {
        $r = mcpPlace($app, $sid, $key, $args);
        same(true, $r['isError'], json_encode($args));
        contains('buyer_confirmed', $r['structuredContent']['error']);
    }
    foreach ([['buyer_confirmed' => true], ['buyer_confirmed' => true, 'confirm_token' => 'abc'], ['buyer_confirmed' => true, 'confirm_token' => str_repeat('z', 64)]] as $args) {
        same(true, mcpPlace($app, $sid, $key, $args)['isError'], json_encode($args));
    }
    same(0, count(apiOrders($app)), 'ningún pedido');
    same(false, mcpPlace($app, $sid, $key, ['confirm_token' => $good, 'buyer_confirmed' => true])['isError'], 'con todo, sí');
});

test('una ficha vieja no vale: si cambia algo después de revisar, el pedido no se hace y una nueva revisión da otra ficha', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    [$sid, $review] = mcpBuy($app, [[$ids['silla_roja'], 1]]);
    $old = $review['confirm_token'];

    mcpTool($app, 'cart_update', ['key' => $review['lines'][0]['key'], 'qty' => 2], $sid);
    $r = mcpPlace($app, $sid, $key, ['confirm_token' => $old, 'buyer_confirmed' => true]);

    same(true, $r['isError']);
    contains('revisaste', $r['structuredContent']['error']);
    same(0, count(apiOrders($app)));
    $again = mcpTool($app, 'checkout_review', [], $sid)['structuredContent'];
    check($again['confirm_token'] !== $old, 'otra ficha');
    same(2 * 5990 + 495, $again['total_cents']);
    same(true, mcpPlace($app, $sid, $key, ['confirm_token' => $old, 'buyer_confirmed' => true])['isError'], 'la vieja sigue sin valer');
    same(false, mcpPlace($app, $sid, $key, ['confirm_token' => $again['confirm_token'], 'buyer_confirmed' => true])['isError']);
    same(1, count(apiOrders($app)));
});

test('la ficha de otra sesión no sirve: con la de una no se hace el pedido de otra', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    [$mine, $reviewMine] = mcpBuy($app, [[$ids['silla_roja'], 1]]);
    [$other] = mcpBuy($app, [[$ids['mesa'], 1]]);

    $r = mcpPlace($app, $other, $key, ['confirm_token' => $reviewMine['confirm_token'], 'buyer_confirmed' => true]);

    same(true, $r['isError']);
    same(0, count(apiOrders($app)));
    check($mine !== $other);
});

test('el tope de importe de la API vale también aquí: por encima, la revisión no da ficha y el pedido no se hace', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    [$sid, $review] = mcpBuy($app, [[$ids['silla_roja'], 1]]);
    $app->settings()->set('api.max_order_cents', 5000);

    $over = mcpTool($app, 'checkout_review', [], $sid)['structuredContent'];
    same(true, $over['over_limit']);
    check(!isset($over['confirm_token']), 'sin ficha');
    contains('https://tienda.test/finalizar-compra', $over['checkout_url']);
    $r = mcpPlace($app, $sid, $key, ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true]);
    same(true, $r['isError']);
    contains('finalizar-compra', $r['structuredContent']['error']);
    same(0, count(apiOrders($app)));
});

test('el máximo de pedidos por clave y hora es el mismo por API y por MCP: se cuentan juntos', function (): void {
    $app = mcpApp(['api.max_orders_per_hour' => 2]);
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    $viaMcp = static function () use ($app, $ids, $key): array {
        [$sid, $review] = mcpBuy($app, [[$ids['mesa'], 1]]);

        return mcpPlace($app, $sid, $key, ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true]);
    };

    same(false, $viaMcp()['isError']);
    $token = apiCart($app, [[$ids['mesa'], 0, 1]]);
    $review = apiBuy($app, $token);
    same(201, apiPlace($app, $token, $key, ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true])->status, 'el segundo, por la API');
    $third = $viaMcp();
    same(true, $third['isError'], 'el tercero, por MCP, ya no');
    contains('hour', $third['structuredContent']['error']);
    same(2, count(apiOrders($app)));
    $sources = array_column(apiOrders($app), 'source');
    same(['mcp', 'api'], $sources);
});

test('dos checkout_place seguidos hacen un solo pedido', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    [$sid, $review] = mcpBuy($app, [[$ids['silla_roja'], 1]]);
    $args = ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true];

    $first = mcpPlace($app, $sid, $key, $args);
    $second = mcpPlace($app, $sid, $key, $args);

    same(false, $first['isError']);
    same(true, $second['isError']);
    same(1, count(apiOrders($app)));
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'order.place'"));
    same(9, aifecOrderStock($app, $ids['silla_roja']), 'el stock se descontó una vez');
});

test('con la compra en el chat apagada, checkout_review y checkout_place no existen en el MCP', function (): void {
    $app = mcpApp(['assistant.checkout' => false]);
    assistantShop($app);

    $names = array_column(mcpRpc($app, 'tools/list')['result']['tools'], 'name');

    check(!in_array('checkout_review', $names, true) && !in_array('checkout_place', $names, true) && !in_array('checkout_status', $names, true), 'sin caja');
    check(in_array('cart_add', $names, true) && in_array('checkout_link', $names, true), 'el carrito y el enlace de pago, sí');
    same(-32602, mcpRpc($app, 'tools/call', ['name' => 'checkout_place', 'arguments' => ['confirm_token' => str_repeat('a', 64), 'buyer_confirmed' => true]])['error']['code']);
});

test('revisar un pedido al que le falta algo dice qué falta, y no da ficha; un carrito vacío, error', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $sid = mcpSession($app);

    same(true, mcpTool($app, 'checkout_review', [], $sid)['isError'], 'carrito vacío');
    mcpTool($app, 'cart_add', ['product_id' => $ids['mesa']], $sid);
    $r = mcpTool($app, 'checkout_review', [], $sid);
    same(false, $r['isError']);
    same(false, $r['structuredContent']['ready']);
    check(in_array('contact', $r['structuredContent']['missing'], true) && !isset($r['structuredContent']['confirm_token']), 'falta el contacto y no hay ficha');
});

// ---------------------------------------------------------------------------------------------------------------
// Pedidos hechos por agentes sin clave: la tienda valida a la persona con un código que le manda por correo (BuyerCode).

/**
 * El último código que la tienda ha mandado por correo, leído del propio correo (su asunto empieza por él).
 *
 * @return array{code:string,to:string,count:int}
 */
function mcpBuyerMail(App $app): array
{
    $rows = $app->db()->select("SELECT to_email, subject FROM {mail_messages} WHERE template = 'buyer_code' ORDER BY id");
    $last = $rows !== [] ? $rows[count($rows) - 1] : ['to_email' => '', 'subject' => ''];
    preg_match('/^([0-9]{6}) /', (string) $last['subject'], $m);

    return ['code' => isset($m[1]) ? $m[1] : '', 'to' => (string) $last['to_email'], 'count' => count($rows)];
}

test('cualquier asistente, sin clave: checkout_review manda un código al comprador y se lo dice; checkout_place solo hace el pedido con él', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    [$sid, $review] = mcpBuy($app, [[$ids['silla_roja'], 1]]);

    same('buyer_code', $review['confirmation']['method']);
    same(true, $review['confirmation']['sent']);
    contains('verification code', $review['next'], 'la tienda le DICE al asistente que ha mandado un código');
    contains('WAIT', $review['next']);
    contains('checkout_place', $review['next'], 'y con qué herramienta seguir');
    $mail = mcpBuyerMail($app);
    same('ana@correo.test', $mail['to']);
    same(6, strlen($mail['code']));
    notContains($mail['code'], (string) json_encode($review), 'el código no va en la respuesta al asistente');

    $args = ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true];
    $none = mcpTool($app, 'checkout_place', $args, $sid);
    same(true, $none['isError']);
    contains('buyer_code', $none['structuredContent']['error']);
    contains('wait', strtolower($none['structuredContent']['error']));
    $wrong = mcpTool($app, 'checkout_place', $args + ['buyer_code' => $mail['code'] === '000000' ? '000001' : '000000'], $sid);
    same(true, $wrong['isError']);
    same(true, mcpTool($app, 'checkout_place', ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => false, 'buyer_code' => $mail['code']], $sid)['isError'], 'el código no quita la declaración');
    same(0, count(apiOrders($app)));

    $good = mcpTool($app, 'checkout_place', $args + ['buyer_code' => $mail['code']], $sid);
    same(false, $good['isError'], json_encode($good));
    $orders = apiOrders($app);
    same(1, count($orders));
    same('buyer_code', json_decode((string) $orders[0]['attribution'], true)['confirmed_by']);
    same(true, mcpTool($app, 'checkout_place', $args + ['buyer_code' => $mail['code']], $sid)['isError'], 'el mismo código no hace otro pedido');
    same(1, count(apiOrders($app)));
});

test('el código de otra sesión no sirve, y el ajuste apagado o «solo con clave» no manda ningún código', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    [$one, $reviewOne] = mcpBuy($app, [[$ids['silla_roja'], 1]]);
    $codeOne = mcpBuyerMail($app)['code'];
    [$two, $reviewTwo] = mcpBuy($app, [[$ids['mesa'], 1]]);
    $codeTwo = mcpBuyerMail($app)['code'];
    if ($codeOne !== $codeTwo) {
        same(true, mcpTool($app, 'checkout_place', ['confirm_token' => $reviewTwo['confirm_token'], 'buyer_confirmed' => true, 'buyer_code' => $codeOne], $two)['isError'], 'el código de un pedido no vale para otro');
    }
    same(true, mcpTool($app, 'checkout_place', ['confirm_token' => $reviewOne['confirm_token'], 'buyer_confirmed' => true, 'buyer_code' => $codeOne], $two)['isError'], 'ni la ficha y el código de una sesión en otra');
    same(0, count(apiOrders($app)));

    foreach (['off', 'key'] as $mode) {
        $app = mcpApp(['api.agent_orders' => $mode]);
        $ids = assistantShop($app);
        [$sid, $review] = mcpBuy($app, [[$ids['silla_roja'], 1]]);
        check(!isset($review['confirmation']), $mode);
        same(0, mcpBuyerMail($app)['count'], $mode . ': no sale ningún correo');
        contains('checkout page', $review['next'], $mode);
        $args = ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true, 'buyer_code' => '123456'];
        same(true, mcpTool($app, 'checkout_place', $args, $sid)['isError'], $mode . ': sin clave');
        same($mode === 'off', mcpPlace($app, $sid, apiKey($app, ['orders:create']), $args)['isError'], $mode . ': con la clave buena');
        same($mode === 'off' ? 0 : 1, count(apiOrders($app)), $mode);
    }
});

test('lo que el MCP le cuenta al asistente habla del código: las instrucciones, la herramienta y su esquema', function (): void {
    $app = mcpApp();
    assistantShop($app);
    $init = mcpRpc($app, 'initialize', ['protocolVersion' => '2025-03-26', 'capabilities' => [], 'clientInfo' => ['name' => 'prueba', 'version' => '1']]);
    contains('verification code', $init['result']['instructions']);
    contains('buyer_code', $init['result']['instructions']);
    $tools = [];
    foreach (mcpRpc($app, 'tools/list')['result']['tools'] as $tool) {
        $tools[$tool['name']] = $tool;
    }
    contains('buyer_code', $tools['checkout_place']['description']);
    check(isset($tools['checkout_place']['inputSchema']['properties']['buyer_code']), 'el esquema admite buyer_code');
    same(['confirm_token', 'buyer_confirmed'], $tools['checkout_place']['inputSchema']['required'], 'y no lo exige: con clave no hace falta');
    contains('verification code', $tools['checkout_review']['description']);
});
