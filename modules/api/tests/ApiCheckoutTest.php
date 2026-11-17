<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La compra entera por API, hasta el pedido: el total exacto, la confirmación de la persona, la clave con su ámbito y los topes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('la compra entera por API: carrito, datos, envío, pago, revisión y pedido, con el total exacto y el cobro en manos de la persona', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 2]]);
    $h = ['x-cart-token' => $token];

    $status = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/status', null, $h))['data'];
    same(false, $status['ready']);
    check(in_array('contact', $status['missing'], true), 'faltan los datos: ' . implode(',', $status['missing']));
    same(11980 + 495, $status['cart']['total'], 'el total del carrito, en céntimos y con el envío que le toca por defecto');

    $review = apiBuy($app, $token, 'transfer');
    same(true, $review['ready']);
    $options = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/shipping-options', null, $h))['data']['options'];
    $expected = 2 * 5990 + (int) $options[0]['price'];
    same(495, (int) $options[0]['price'], 'el envío de la tarifa');
    same($expected, $review['total'], 'el total es la suma exacta de líneas y envío');
    contains('124,75', $review['total_formatted']);
    same('EUR', $review['currency']);
    same(64, strlen($review['confirm_token']));
    check(strpos(json_encode($review), 'Calle Mayor 1') === false && strpos(json_encode($review), 'ana@correo.test') === false, 'los datos personales salen enmascarados');
    same(2, $review['lines'][0]['qty']);
    same('transfer', $review['payment']['id']);
    $before = count(apiOrders($app));
    same(0, $before, 'revisar no hace nada');

    $r = apiPlace($app, $token, $key, ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true]);
    $placed = apiJson($r);

    same(201, $r->status, $r->body);
    $data = $placed['data'];
    same($expected, $data['total']);
    contains('124,75', $data['total_formatted']);
    check(preg_match('/^\S+$/', $data['number']) === 1 && $data['order_page'] !== '', 'trae el número y la página del pedido');
    contains('https://tienda.test/', $data['order_page']);
    same('instructions', $data['payment']['type'], 'la transferencia da instrucciones');
    check($data['payment']['instructions'] !== '' && $data['payment']['pending'] === true, 'con el texto de cómo pagar');

    $orders = apiOrders($app);
    same(1, count($orders));
    same($expected, (int) $orders[0]['total'], 'el pedido guarda el mismo total que se revisó');
    same('api', $orders[0]['source']);
    same($data['number'], $orders[0]['number']);
    $attribution = json_decode((string) $orders[0]['attribution'], true);
    same('api', $attribution['channel']);
    same('Agente de pruebas', $attribution['key_name']);
    same('ana@correo.test', $orders[0]['email']);
    $audit = $app->db()->row("SELECT * FROM {audit} WHERE action = 'order.place'");
    same('API: Agente de pruebas', $audit['user_name'], 'queda en el registro de actividad con la clave que lo hizo');
    same((string) $orders[0]['id'], $audit['entity_id']);
    $note = $app->db()->row("SELECT * FROM {order_history} WHERE order_id = ? AND kind = 'note'", [$orders[0]['id']]);
    contains('Agente de pruebas', (string) $note['note']);
    same(0, (int) $app->db()->value('SELECT is_public FROM {order_history} WHERE id = ?', [$note['id']]), 'la nota no la ve el cliente');
    check((int) $app->db()->value('SELECT order_id FROM {carts} WHERE token = ?', [hash('sha256', $token)]) > 0, 'el carrito queda convertido en pedido');
});

test('con una pasarela, la respuesta trae la dirección donde paga la persona: el programa no cobra nada', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    $token = apiCart($app, [[$ids['mesa'], 0, 1]]);
    $review = apiBuy($app, $token, 'gw');

    $r = apiPlace($app, $token, $key, ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true]);
    $data = apiJson($r)['data'];

    same(201, $r->status, $r->body);
    same('redirect', $data['payment']['type']);
    contains('https://pasarela.test/pagar/', $data['payment']['redirect']['url']);
    same('get', $data['payment']['redirect']['method']);
    same((string) $data['order_page'] . '/pay', (string) $data['payment']['pay_url'], 'la dirección que la persona abre para pagar');
    same(true, $data['payment']['pending']);
    $order = apiOrders($app)[0];
    same('pending', $order['payment_status'], 'sigue sin pagar: lo paga la persona');
    same(29900 + 495, (int) $order['total']);
    same(1, count(aifecOrderPayment($app)->started), 'se le pidió a la pasarela empezar el cobro, y nada más');
});

test('con el ajuste en «solo con clave»: sin clave, 401; con una clave sin el ámbito orders:create, 403; ni una ni otra hacen un pedido', function (): void {
    $app = apiApp(['api.agent_orders' => 'key']);
    $ids = assistantShop($app);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $review = apiBuy($app, $token);
    $body = ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true];

    $none = apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body, ['x-cart-token' => $token]);
    same(401, $none->status);
    $weak = apiPlace($app, $token, apiKey($app, [], 'Solo lectura'), $body);
    same(403, $weak->status);
    contains('orders:create', apiJson($weak)['error']['message']);
    $other = apiPlace($app, $token, apiKey($app, ['otra:cosa'], 'Otra'), $body);
    same(403, $other->status);
    same(401, apiPlace($app, $token, 'aifec_api_mentira', $body)->status, 'una clave que no existe');
    same(0, count(apiOrders($app)), 'ninguna hizo un pedido');

    $good = apiPlace($app, $token, apiKey($app, ['orders:create'], 'Con ámbito'), $body);
    same(201, $good->status, 'con el ámbito, sí: ' . $good->body);
});

test('sin buyer_confirmed verdadero no hay pedido: ni falso, ni ausente, ni como texto; y sin ficha de confirmación, tampoco', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $review = apiBuy($app, $token);
    $good = $review['confirm_token'];

    foreach ([['confirm_token' => $good], ['confirm_token' => $good, 'buyer_confirmed' => false], ['confirm_token' => $good, 'buyer_confirmed' => 'true'], ['confirm_token' => $good, 'buyer_confirmed' => 1]] as $body) {
        $r = apiPlace($app, $token, $key, $body);
        same(400, $r->status, json_encode($body));
        contains('buyer_confirmed', apiJson($r)['error']['message']);
    }
    foreach ([['buyer_confirmed' => true], ['buyer_confirmed' => true, 'confirm_token' => ''], ['buyer_confirmed' => true, 'confirm_token' => 'abc'], ['buyer_confirmed' => true, 'confirm_token' => ['x']]] as $body) {
        same(400, apiPlace($app, $token, $key, $body)->status, json_encode($body));
    }
    same(0, count(apiOrders($app)), 'ningún pedido');
    same(201, apiPlace($app, $token, $key, ['confirm_token' => $good, 'buyer_confirmed' => true])->status, 'y con todo, sí');
});

test('una ficha vieja no vale: si cambia la cantidad, la dirección, el envío o el pago después de revisar, no se crea nada', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    chatFast($app);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $h = ['x-cart-token' => $token];
    $review = apiBuy($app, $token);
    $old = $review['confirm_token'];
    $lineKey = $review['lines'][0]['key'];

    same(200, apiCall($app, 'PATCH', '/api/v1/carts/current/items/' . $lineKey, ['qty' => 3], $h)->status);
    $r = apiPlace($app, $token, $key, ['confirm_token' => $old, 'buyer_confirmed' => true]);
    same(409, $r->status, 'cambió una cantidad');
    contains('revisaste', apiJson($r)['error']['message']);
    same(0, count(apiOrders($app)));

    $again = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h))['data'];
    check($again['confirm_token'] !== $old, 'otra revisión, otra ficha');
    same(3 * 5990 + (int) $review['shipping']['price'], $again['total']);
    same(200, apiCall($app, 'POST', '/api/v1/carts/checkout/payment', ['method' => 'gw'], $h)->status);
    same(409, apiPlace($app, $token, $key, ['confirm_token' => $again['confirm_token'], 'buyer_confirmed' => true])->status, 'cambió el pago');

    $third = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h))['data'];
    $fast = null;
    foreach (apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/shipping-options', null, $h))['data']['options'] as $option) {
        if (!$option['selected']) {
            $fast = $option;
        }
    }
    check($fast !== null, 'hay un segundo envío');
    same(200, apiCall($app, 'POST', '/api/v1/carts/checkout/shipping', ['carrier_id' => $fast['id']], $h)->status);
    same(409, apiPlace($app, $token, $key, ['confirm_token' => $third['confirm_token'], 'buyer_confirmed' => true])->status, 'cambió el envío');

    same(200, apiCall($app, 'POST', '/api/v1/carts/checkout/customer', ['line1' => 'Calle Alta 2', 'postcode' => '08001', 'city' => 'Barcelona'], $h)->status);
    $fourth = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h))['data'];
    same(200, apiCall($app, 'POST', '/api/v1/carts/checkout/customer', ['line1' => 'Calle Baja 9'], $h)->status);
    same(409, apiPlace($app, $token, $key, ['confirm_token' => $fourth['confirm_token'], 'buyer_confirmed' => true])->status, 'cambió la dirección');
    same(0, count(apiOrders($app)), 'en ninguno de los casos hubo pedido');

    $last = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h))['data'];
    same(201, apiPlace($app, $token, $key, ['confirm_token' => $last['confirm_token'], 'buyer_confirmed' => true])->status, 'con la ficha al día, sí');
    same(1, count(apiOrders($app)));
});

test('la ficha de otro carrito no sirve: con la ficha de uno no se hace el pedido de otro', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    $mine = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $other = apiCart($app, [[$ids['mesa'], 0, 1]]);
    $reviewMine = apiBuy($app, $mine);
    apiBuy($app, $other);

    $r = apiPlace($app, $other, $key, ['confirm_token' => $reviewMine['confirm_token'], 'buyer_confirmed' => true]);

    same(409, $r->status);
    same(0, count(apiOrders($app)));
});

test('el tope de importe: por encima de api.max_order_cents la revisión no da ficha y el pedido no se hace, ni con una ficha de antes', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $h = ['x-cart-token' => $token];
    $review = apiBuy($app, $token);
    same(6485, $review['total']);

    $app->settings()->set('api.max_order_cents', 5000);
    $over = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h))['data'];
    same(true, $over['over_limit']);
    check(!isset($over['confirm_token']), 'sin ficha');
    // La dirección para la persona es la que abre ESTE carrito en su navegador (la caja a secas le saldría vacía).
    same('https://tienda.test/carrito/retomar/' . $token, $over['checkout_url']);
    same(6485, $over['total']);

    $r = apiPlace($app, $token, $key, ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true]);
    same(422, $r->status);
    contains('/carrito/retomar/' . $token, apiJson($r)['error']['message']);
    same(0, count(apiOrders($app)));

    $app->settings()->set('api.max_order_cents', 6485);
    $edge = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h))['data'];
    check(!isset($edge['over_limit']), 'justo en el tope, vale');
    same(201, apiPlace($app, $token, $key, ['confirm_token' => $edge['confirm_token'], 'buyer_confirmed' => true])->status);
});

test('el máximo de pedidos por clave y hora: pasado, 429 y ningún pedido más; otra clave y la hora siguiente sí', function (): void {
    $app = apiApp(['api.max_orders_per_hour' => 2]);
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create'], 'Una');
    $other = apiKey($app, ['orders:create'], 'Otra');
    $place = static function (string $key) use ($app, $ids): \Aifec\Kernel\Response {
        $token = apiCart($app, [[$ids['mesa'], 0, 1]]);
        $review = apiBuy($app, $token);

        return apiPlace($app, $token, $key, ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true]);
    };

    same(201, $place($key)->status);
    same(201, $place($key)->status);
    $third = $place($key);
    same(429, $third->status);
    same(2, count(apiOrders($app)), 'el tercero no se hizo');
    same(201, $place($other)->status, 'otra clave tiene su propio contador');
    $app->clock()->advance(3601);
    same(201, $place($key)->status, 'pasada la hora, vuelve a poder');
    same(4, count(apiOrders($app)));
});

test('dos place seguidos hacen un solo pedido: el segundo encuentra el carrito ya convertido', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $review = apiBuy($app, $token);
    $body = ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true];

    $first = apiPlace($app, $token, $key, $body);
    $second = apiPlace($app, $token, $key, $body);

    same(201, $first->status);
    same(409, $second->status);
    contains('already', apiJson($second)['error']['message']);
    same(1, count(apiOrders($app)));
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'order.place'"));
    same(9, aifecOrderStock($app, $ids['silla_roja']), 'se descontó una sola vez (había 10)');
    same(409, apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, ['x-cart-token' => $token])->status, 'ese carrito ya no se toca');
});

test('revisar un pedido al que le falta algo dice qué falta, y no da ficha; un carrito vacío da error', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);

    $empty = apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, ['x-cart-token' => apiCart($app)]);
    same(422, $empty->status);

    $token = apiCart($app, [[$ids['mesa'], 0, 1]]);
    $r = apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, ['x-cart-token' => $token]);
    $data = apiJson($r)['data'];
    same(200, $r->status);
    same(false, $data['ready']);
    check(in_array('contact', $data['missing'], true) && !isset($data['confirm_token']), 'falta el contacto y no hay ficha');
    same(422, apiPlace($app, $token, $key, ['confirm_token' => str_repeat('a', 64), 'buyer_confirmed' => true])->status, 'con datos que faltan y una ficha inventada no se hace nada');
    same(0, count(apiOrders($app)));
});

test('los pasos de la caja son las herramientas del asistente: guardan los datos, enmascaran lo personal y dan errores claros', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $h = ['x-cart-token' => $token];

    $none = apiCall($app, 'POST', '/api/v1/carts/checkout/customer', ['text' => 'hola, quiero la silla'], $h);
    same(422, $none->status, 'sin datos personales en el texto, error');
    $early = apiCall($app, 'POST', '/api/v1/carts/checkout/shipping-options', null, $h);
    same(422, $early->status, 'sin dirección no hay opciones de envío');

    $saved = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/customer', ['text' => CHAT_DATA], $h))['data'];
    check(count($saved['saved']) >= 3, 'guardó lo entendido');
    foreach (['Ana Ruiz', 'ana@correo.test', '999000001', 'Calle Mayor 1'] as $private) {
        notContains($private, (string) json_encode($saved), 'no devuelve «' . $private . '» entero');
    }
    $options = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/shipping-options', null, $h))['data'];
    same(495, (int) $options['options'][0]['price']);
    contains('4,95', $options['options'][0]['price_formatted']);
    same(422, apiCall($app, 'POST', '/api/v1/carts/checkout/shipping', ['carrier_id' => 9999], $h)->status);
    same(422, apiCall($app, 'POST', '/api/v1/carts/checkout/payment', ['method' => 'no-existe'], $h)->status);
    $pay = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/payment-options', null, $h))['data']['options'];
    $payIds = array_column($pay, 'id');
    sort($payIds);
    same(['gw', 'transfer'], $payIds);
    $kinds = array_column($pay, 'kind', 'id');
    same('test', $kinds['gw'], 'la pasarela de pruebas se paga en línea');
    same('offline', $kinds['transfer']);
});

test('con la compra en el chat apagada, la compra por API tampoco existe: 404 en la caja, y el carrito sigue funcionando', function (): void {
    $app = apiApp(['assistant.checkout' => false]);
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $h = ['x-cart-token' => $token];

    foreach (['status', 'customer', 'shipping-options', 'shipping', 'payment-options', 'payment', 'review'] as $step) {
        same(404, apiCall($app, 'POST', '/api/v1/carts/checkout/' . $step, null, $h)->status, $step);
    }
    same(404, apiPlace($app, $token, $key, ['confirm_token' => str_repeat('a', 64), 'buyer_confirmed' => true])->status);
    same(200, apiCall($app, 'GET', '/api/v1/carts/current', null, $h)->status);
    same(200, apiCall($app, 'GET', '/api/v1/carts/current/checkout-link', null, $h)->status, 'y la persona puede pagar en la web');
});

// ---------------------------------------------------------------------------------------------------------------
// Pedidos hechos por agentes sin clave: la tienda valida a la persona con un código que le manda por correo (BuyerCode).

/**
 * El último código que la tienda ha mandado por correo, leído del propio correo (su asunto empieza por él).
 *
 * @return array{code:string,to:string,status:string,count:int}
 */
function apiBuyerMail(\Aifec\Kernel\App $app): array
{
    $rows = $app->db()->select("SELECT to_email, subject, status FROM {mail_messages} WHERE template = 'buyer_code' ORDER BY id");
    $last = $rows !== [] ? $rows[count($rows) - 1] : ['to_email' => '', 'subject' => '', 'status' => ''];
    preg_match('/^([0-9]{6}) /', (string) $last['subject'], $m);

    return ['code' => isset($m[1]) ? $m[1] : '', 'to' => (string) $last['to_email'], 'status' => (string) $last['status'], 'count' => count($rows)];
}

test('cualquier agente, sin clave: la revisión manda un código al comprador y se lo dice al programa; el pedido solo se hace con ese código', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $h = ['x-cart-token' => $token];
    $review = apiBuy($app, $token);

    same('buyer_code', $review['confirmation']['method']);
    same(true, $review['confirmation']['sent'], 'el código ha salido');
    contains('verification code', $review['next'], 'la tienda le DICE al programa que ha mandado un código');
    contains('WAIT', $review['next'], 'y que espere a que la persona se lo dé');
    notContains('ana@correo.test', (string) json_encode($review), 'la dirección no vuelve entera');
    $mail = apiBuyerMail($app);
    same('ana@correo.test', $mail['to'], 'va a la dirección del comprador');
    check(!in_array($mail['status'], ['held', 'failed', ''], true), 'sale aunque el correo de la tienda esté retenido: ' . $mail['status']);
    same(6, strlen($mail['code']), 'el asunto trae el código');
    notContains($mail['code'], (string) json_encode($review), 'el código NO va en la respuesta al programa: solo lo tiene la persona');

    $body = ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true];
    $none = apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body, $h);
    same(400, $none->status, 'sin código no hay pedido');
    contains('buyer_code', apiJson($none)['error']['message']);
    $wrong = apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body + ['buyer_code' => $mail['code'] === '000000' ? '000001' : '000000'], $h);
    same(403, $wrong->status, 'con un código inventado, tampoco');
    same(400, apiCall($app, 'POST', '/api/v1/carts/checkout/place', ['buyer_confirmed' => false] + $body + ['buyer_code' => $mail['code']], $h)->status, 'el código no quita la declaración');
    same(0, count(apiOrders($app)), 'nada de lo anterior hizo un pedido');

    $good = apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body + ['buyer_code' => $mail['code']], $h);
    same(201, $good->status, 'con el código de la persona, sí: ' . $good->body);
    $orders = apiOrders($app);
    same(1, count($orders));
    $attribution = json_decode((string) $orders[0]['attribution'], true);
    same('buyer_code', $attribution['confirmed_by'], 'el pedido dice quién lo confirmó');
    same(0, (int) $attribution['key_id']);
    check(apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body + ['buyer_code' => $mail['code']], $h)->status !== 201, 'el mismo código no hace otro pedido');
    same(1, count(apiOrders($app)));
});

test('el código es de ESE pedido: si cambia algo, ni la ficha vieja ni el código viejo valen, y la revisión nueva manda otro', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $h = ['x-cart-token' => $token];
    $first = apiBuy($app, $token);
    $oldCode = apiBuyerMail($app)['code'];

    same(201, apiCall($app, 'POST', '/api/v1/carts/current/items', ['product_id' => $ids['mesa'], 'variant_id' => 0, 'qty' => 1], $h)->status);
    $stale = apiCall($app, 'POST', '/api/v1/carts/checkout/place', ['confirm_token' => $first['confirm_token'], 'buyer_confirmed' => true, 'buyer_code' => $oldCode], $h);
    same(409, $stale->status, 'la ficha de antes ya no vale: ' . $stale->body);

    $second = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h))['data'];
    check($second['confirm_token'] !== $first['confirm_token'], 'otra ficha');
    same(2, apiBuyerMail($app)['count'], 'y otro correo, con el total nuevo');
    $body = ['confirm_token' => $second['confirm_token'], 'buyer_confirmed' => true];
    if ($oldCode !== apiBuyerMail($app)['code']) {
        same(403, apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body + ['buyer_code' => $oldCode], $h)->status, 'el código del pedido anterior no sirve para este');
    }
    same(0, count(apiOrders($app)));
    same(201, apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body + ['buyer_code' => apiBuyerMail($app)['code']], $h)->status);
});

test('revisar dos veces el mismo pedido no manda dos correos; el código caduca; y tras cinco códigos malos esa ficha se bloquea', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $h = ['x-cart-token' => $token];
    $review = apiBuy($app, $token);
    $again = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h))['data'];
    same(true, $again['confirmation']['sent']);
    same(true, $again['confirmation']['already_sent']);
    same(1, apiBuyerMail($app)['count'], 'un solo correo para el mismo pedido');
    $code = apiBuyerMail($app)['code'];
    $body = ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true];

    $app->clock()->advance(2 * 900 + 1);
    same(403, apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body + ['buyer_code' => $code], $h)->status, 'pasado su tiempo, el código ya no vale');
    $fresh = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h))['data'];
    same(2, apiBuyerMail($app)['count'], 'la revisión de después manda uno nuevo');
    $code = apiBuyerMail($app)['code'];
    $body = ['confirm_token' => $fresh['confirm_token'], 'buyer_confirmed' => true];

    $bad = $code === '111111' ? '222222' : '111111';
    for ($i = 0; $i < 4; $i++) {
        same(403, apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body + ['buyer_code' => $bad], $h)->status, 'malo ' . $i);
    }
    $locked = apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body + ['buyer_code' => $code], $h);
    same(429, $locked->status, 'el primer malo fue el caducado: con cinco, ni el bueno entra ya');
    contains('/carrito/retomar/', $locked->body, 'y le da la dirección que abre este carrito para que la persona termine en la tienda');
    same(0, count(apiOrders($app)));
});

test('el ajuste «Pedidos hechos por agentes»: apagado nadie hace pedidos ni se manda código; solo con clave, tampoco se manda', function (): void {
    foreach (['off', 'key'] as $mode) {
        $app = apiApp(['api.agent_orders' => $mode]);
        $ids = assistantShop($app);
        $key = apiKey($app, ['orders:create']);
        $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
        $review = apiBuy($app, $token);
        check(!isset($review['confirmation']), $mode . ': la revisión no habla de ningún código');
        same(0, apiBuyerMail($app)['count'], $mode . ': no sale ningún correo');
        contains('checkout page', $review['next'], $mode . ': dice que la persona puede acabar en la web');
        // Y le da la dirección que abre ESTE carrito: la de la caja a secas enseñaría a la persona una caja vacía.
        contains('https://tienda.test/carrito/retomar/' . $token, $review['next'], $mode . ': con la dirección de este carrito');
        $body = ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true, 'buyer_code' => '123456'];
        $none = apiCall($app, 'POST', '/api/v1/carts/checkout/place', $body, ['x-cart-token' => $token]);
        same($mode === 'off' ? 403 : 401, $none->status, $mode . ': sin clave');
        $with = apiPlace($app, $token, $key, $body);
        same($mode === 'off' ? 403 : 201, $with->status, $mode . ': con la clave buena');
        same($mode === 'off' ? 0 : 1, count(apiOrders($app)), $mode);
    }
});

test('una clave con el ámbito orders:create no necesita código, y si la manda al revisar no se envía ningún correo', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $key = apiKey($app, ['orders:create']);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $h = ['x-cart-token' => $token, 'authorization' => 'Bearer ' . $key];
    apiBuy($app, $token);
    $before = apiBuyerMail($app)['count'];
    $app->clock()->advance(901);
    $review = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h))['data'];
    check(!isset($review['confirmation']), 'con la clave, la revisión no manda código');
    same($before, apiBuyerMail($app)['count']);
    same(201, apiPlace($app, $token, $key, ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true])->status);
});

test('los códigos no se pueden usar para llenar de correo a nadie: cinco por dirección y hora, y la revisión lo dice', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $sent = 0;
    $last = [];
    $token = '';
    for ($i = 0; $i < 7; $i++) {
        $token = apiCart($app, [[$ids['silla_roja'], 0, 1 + $i]]);
        $last = apiBuy($app, $token);
        $sent += $last['confirmation']['sent'] ? 1 : 0;
    }
    same(5, $sent);
    same(5, apiBuyerMail($app)['count']);
    same(false, $last['confirmation']['sent']);
    contains('No code was sent', $last['next']);
    // «Manda a la persona a la caja» va con la dirección que abre ESE carrito en su navegador: con la de la caja a secas
    // la persona se encontraba una caja vacía («mi carrito sale vacío en el enlace», 16/11/2026).
    contains('https://tienda.test/carrito/retomar/' . $token, $last['next']);
    same('https://tienda.test/carrito/retomar/' . $token, $last['confirmation']['checkout_url']);
});

test('el tope de códigos por dirección se cambia en los ajustes, pero nunca queda sin tope', function (): void {
    // Una tienda de pruebas en la que todas las compras van al mismo buzón necesita más de cinco por hora.
    $send = static function (array $ajustes, int $veces): int {
        $app = apiApp($ajustes);
        $ids = assistantShop($app);
        $sent = 0;
        for ($i = 0; $i < $veces; $i++) {
            $review = apiBuy($app, apiCart($app, [[$ids['silla_roja'], 0, 1 + $i]]));
            $sent += $review['confirmation']['sent'] ? 1 : 0;
        }

        return $sent;
    };
    same(2, $send(['api.codes_per_email_hour' => 2], 4), 'con el tope a 2, salen 2');
    same(8, $send(['api.codes_per_email_hour' => 8], 9), 'con el tope a 8, salen 8');
    same(1, $send(['api.codes_per_email_hour' => 0], 3), 'un 0 no quita el tope: queda en 1');
});

test('el código sale de la ficha, del tramo de tiempo y de la clave del sitio: otro pedido u otro momento dan otro código', function (): void {
    $app = apiApp();
    $app->boot();
    $byToken = [];
    $bySlot = [];
    $token = hash('sha256', 'una ficha');
    for ($i = 0; $i < 40; $i++) {
        $byToken[] = \Aifec\Modules\Orders\Checkout\BuyerCode::code($app, hash('sha256', 'ficha ' . $i), 1000);
        $bySlot[] = \Aifec\Modules\Orders\Checkout\BuyerCode::code($app, $token, 1000 + $i);
    }
    check(count(array_unique($byToken)) >= 38, 'fichas distintas dan códigos distintos: ' . count(array_unique($byToken)));
    check(count(array_unique($bySlot)) >= 38, 'tramos distintos dan códigos distintos: ' . count(array_unique($bySlot)));
    foreach (array_merge($byToken, $bySlot) as $code) {
        check(preg_match('/^[0-9]{6}$/D', $code) === 1, 'seis cifras: ' . $code);
    }
    same(\Aifec\Modules\Orders\Checkout\BuyerCode::code($app, $token, 5), \Aifec\Modules\Orders\Checkout\BuyerCode::code($app, strtoupper($token), 5), 'la ficha en mayúsculas es la misma ficha');
});

// ---------------------------------------------------------------------------------------------------------------
// El código del comprador también por WhatsApp, por la pasarela que la tienda configura.

/**
 * Una tienda con la pasarela de WhatsApp puesta; $gateway apunta lo que le llega y contesta con el estado dado.
 *
 * @param array<string,mixed>                       $ajustes
 * @param array<int,\Aifec\Http\HttpRequest>         $llegado por referencia: las peticiones que recibe la pasarela
 */
function apiWhatsappApp(array $ajustes, array &$llegado, int $estado = 200): \Aifec\Kernel\App
{
    $app = apiApp($ajustes + ['api.code_whatsapp' => true, 'api.whatsapp_url' => 'https://pasarela.test/api/v1/messages', 'api.whatsapp_token' => 'clave-de-mentira']);
    $app->http()->when('POST', 'pasarela.test', static function (\Aifec\Http\HttpRequest $r) use (&$llegado, $estado): \Aifec\Http\HttpResponse {
        $llegado[] = $r;

        return new \Aifec\Http\HttpResponse($estado, [], '{"ok":true}');
    });

    return $app;
}

test('con la pasarela encendida el código sale también por WhatsApp al teléfono del comprador, y la tienda le dice al programa por dónde', function (): void {
    $got = [];
    $app = apiWhatsappApp([], $got);
    $ids = assistantShop($app);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $review = apiBuy($app, $token);

    same(['email', 'whatsapp'], $review['confirmation']['channels']);
    same(true, $review['confirmation']['sent']);
    contains('by email and by WhatsApp', $review['next'], 'la tienda dice por dónde ha mandado el código');
    contains('WAIT', $review['next']);
    same('***001', $review['confirmation']['sent_to_phone'], 'el teléfono vuelve tapado');
    notContains('999000001', (string) json_encode($review), 'y nunca entero');

    same(1, count($got), 'una sola llamada a la pasarela');
    same('Bearer clave-de-mentira', $got[0]->headers['Authorization'], 'con la clave de la pasarela');
    $sent = json_decode((string) $got[0]->body, true);
    same('34999000001', $sent['to'], 'el teléfono lleva el prefijo del país');
    $mail = apiBuyerMail($app);
    contains($mail['code'], $sent['text'], 'el WhatsApp lleva el mismo código que el correo');
    contains((string) $review['total_formatted'], $sent['text'], 'y el total exacto de la revisión');
    notContains($mail['code'], (string) json_encode($review), 'el código no va en la respuesta al programa');

    $good = apiCall($app, 'POST', '/api/v1/carts/checkout/place', ['confirm_token' => $review['confirm_token'], 'buyer_confirmed' => true, 'buyer_code' => $mail['code']], ['x-cart-token' => $token]);
    same(201, $good->status, $good->body);
});

test('el WhatsApp no sustituye a nada: si la pasarela falla el código va por correo; apagado, sin dirección o sin clave, ni se la llama', function (): void {
    $got = [];
    $app = apiWhatsappApp([], $got, 500);
    $ids = assistantShop($app);
    $review = apiBuy($app, apiCart($app, [[$ids['silla_roja'], 0, 1]]));
    same(1, count($got), 'se intentó');
    same(['email'], $review['confirmation']['channels'], 'pero solo salió el correo');
    same(true, $review['confirmation']['sent']);
    contains('by email', $review['next']);
    notContains('WhatsApp', $review['next'], 'y no se dice que salió por donde no salió');
    check(!isset($review['confirmation']['sent_to_phone']));

    foreach ([['api.code_whatsapp' => false], ['api.whatsapp_url' => ''], ['api.whatsapp_token' => ''], ['api.whatsapp_url' => 'ftp://pasarela.test/x']] as $off) {
        $got = [];
        $app = apiWhatsappApp($off, $got);
        $ids = assistantShop($app);
        $review = apiBuy($app, apiCart($app, [[$ids['silla_roja'], 0, 1]]));
        same(0, count($got), 'no se llama a la pasarela con ' . json_encode($off));
        same(['email'], $review['confirmation']['channels']);
    }
});

test('el teléfono para la pasarela: solo cifras, con el prefijo de la tienda si no trae el suyo, y vacío si no es un teléfono', function (): void {
    $app = apiApp(['api.whatsapp_prefix' => '34']);
    $app->boot();
    $phone = static function (string $raw) use ($app): string {
        return \Aifec\Modules\Orders\Checkout\BuyerCode::phone($app, $raw);
    };
    same('34999000001', $phone('999 000 001'));
    same('34999000001', $phone('+34 999-000-001'));
    same('34999000001', $phone('0034 999000001'));
    same('447700900123', $phone('+44 7700 900123'), 'un número de otro país se deja como viene');
    same('', $phone('12345'), 'demasiado corto');
    same('', $phone(''), 'sin teléfono');
    same('', $phone('1234567890123456'), 'demasiado largo');
    // Otro prefijo. El 999 no es de ningún país (la UIT lo tiene reservado), así que el número que sale no existe.
    $other = apiApp(['api.whatsapp_prefix' => '+999']);
    $other->boot();
    same('999999000124', \Aifec\Modules\Orders\Checkout\BuyerCode::phone($other, '999 000 124'), 'el prefijo es el que pone la tienda');
});

test('con 360dialog el código va en una plantilla aprobada de Meta: su cabecera, sus huecos en el orden de la tienda y el botón de copiar; sin plantilla no se envía', function (): void {
    $settings = [
        'api.code_whatsapp' => true, 'api.whatsapp_provider' => '360dialog', 'api.whatsapp_token' => 'clave-360', 'api.whatsapp_template' => 'codigo_pedido',
        'api.whatsapp_template_lang' => 'es_ES', 'api.whatsapp_template_vars' => 'code, total', 'api.whatsapp_template_button' => true,
    ];
    $got = [];
    $app = apiApp($settings);
    $app->http()->when('POST', 'waba-v2.360dialog.io', static function (\Aifec\Http\HttpRequest $r) use (&$got): \Aifec\Http\HttpResponse {
        $got[] = $r;

        return new \Aifec\Http\HttpResponse(201, [], '{"messages":[{"id":"wamid.prueba"}]}');
    });
    $ids = assistantShop($app);
    $review = apiBuy($app, apiCart($app, [[$ids['silla_roja'], 0, 1]]));

    same(['email', 'whatsapp'], $review['confirmation']['channels']);
    same(1, count($got));
    same('https://waba-v2.360dialog.io/messages', $got[0]->url, 'la dirección de 360dialog, sin que la tienda la escriba');
    same('clave-360', $got[0]->headers['D360-API-KEY'], 'la clave va en la cabecera de 360dialog');
    check(!isset($got[0]->headers['Authorization']), 'y no como Bearer');
    $sent = json_decode((string) $got[0]->body, true);
    $code = apiBuyerMail($app)['code'];
    same('whatsapp', $sent['messaging_product']);
    same('34999000001', $sent['to']);
    same('template', $sent['type'], 'Meta no deja empezar una conversación con texto libre');
    same('codigo_pedido', $sent['template']['name']);
    same('es_ES', $sent['template']['language']['code']);
    same('body', $sent['template']['components'][0]['type']);
    same(2, count($sent['template']['components'][0]['parameters']), 'tantos valores como huecos dijo la tienda');
    same($code, $sent['template']['components'][0]['parameters'][0]['text'], '{{1}} es el código');
    same((string) $review['total_formatted'], $sent['template']['components'][0]['parameters'][1]['text'], '{{2}} es el total exacto de la revisión');
    same('button', $sent['template']['components'][1]['type']);
    same($code, $sent['template']['components'][1]['parameters'][0]['text'], 'el botón de copiar lleva el código');
    check(!isset($sent['text']), 'no va texto libre');

    $got = [];
    $app = apiApp(['api.whatsapp_template' => '', 'api.whatsapp_template_button' => false] + $settings);
    $app->http()->when('POST', '360dialog', static function (\Aifec\Http\HttpRequest $r) use (&$got): \Aifec\Http\HttpResponse {
        $got[] = $r;

        return new \Aifec\Http\HttpResponse(201, [], '{}');
    });
    $ids = assistantShop($app);
    $review = apiBuy($app, apiCart($app, [[$ids['silla_roja'], 0, 1]]));
    same(0, count($got), 'sin plantilla no se llama a 360dialog');
    same(['email'], $review['confirmation']['channels']);
});

if (!class_exists('ApiFlakyTransport')) {
    /** Transporte que falla las primeras veces (un DNS que no contesta, como en producción el 16/11/2026) y después envía. */
    final class ApiFlakyTransport implements \Aifec\Mail\Transport
    {
        /** @var int */
        public $failures;

        /** @var int */
        public $calls = 0;

        /** @var int */
        public $sent = 0;

        public function __construct(int $failures)
        {
            $this->failures = $failures;
        }

        public function send(\Aifec\Mail\MimeMessage $message): void
        {
            ++$this->calls;
            if ($this->calls <= $this->failures) {
                throw new \Aifec\Mail\MailException('Could not connect to mail.tienda.test:465 (0) getaddrinfo failed: Temporary failure in name resolution');
            }
            ++$this->sent;
        }
    }
}

test('el correo del código falla una vez por un fallo pasajero: se reintenta en el momento, llega y la tienda dice que lo ha enviado', function (): void {
    \Aifec\Modules\Mail\Mailer::$quickRetryPauseMs = 0;
    $flaky = new ApiFlakyTransport(1);
    $app = chatApp([], ['modules' => ['api']]);
    $app->bind('mail.transport', static function () use ($flaky): \Aifec\Mail\Transport {
        return $flaky;
    });
    $app->boot();
    $out = \Aifec\Modules\Orders\Checkout\BuyerCode::send($app, hash('sha256', 'una ficha'), 'ana@correo.test', '64,85 €', 'a***@correo.test');
    same(true, $out['sent'], json_encode($out));
    same(['email'], $out['channels']);
    same(2, $flaky->calls, 'un intento fallido y un reintento inmediato');
    same(1, $flaky->sent);
    $row = $app->db()->row("SELECT status, attempts, error FROM {mail_messages} WHERE template = 'buyer_code'");
    same('sent', (string) $row['status']);
    same(1, (int) $row['attempts'], 'los reintentos inmediatos no gastan los intentos de la cola');
    same(null, $row['error']);
    \Aifec\Modules\Mail\Mailer::$quickRetryPauseMs = 400;
});

test('el lector de un asistente de chat no cuenta en el tope por dirección de red: todas las compras de Claude salen de las mismas direcciones', function (): void {
    // 17/11/2026: a la decimosexta compra con Claude en una hora, la tienda dejó de mandar el código a todos («sent: false»):
    // el tope de 15 por dirección de red lo compartían todos sus clientes. Una persona en su navegador sigue con ese tope.
    $app = chatApp([], ['modules' => ['api']]);
    $app->boot();
    $app->request()->headers['user-agent'] = 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; Claude-User/1.0; +Claude-User@anthropic.com';
    $app->request()->ip = '160.79.104.10';
    for ($i = 1; $i <= 17; $i++) {
        $out = \Aifec\Modules\Orders\Checkout\BuyerCode::send($app, hash('sha256', 'ficha claude ' . $i), 'cliente' . $i . '@correo.test', '10,00 €', 'c***@correo.test');
        same(true, $out['sent'], 'compra ' . $i . ' por Claude: ' . json_encode($out));
    }
    $app->request()->headers['user-agent'] = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/140.0 Safari/537.36';
    $app->request()->ip = '203.0.113.7';
    $last = [];
    for ($i = 1; $i <= 16; $i++) {
        $last = \Aifec\Modules\Orders\Checkout\BuyerCode::send($app, hash('sha256', 'ficha web ' . $i), 'web' . $i . '@correo.test', '10,00 €', 'w***@correo.test');
    }
    same(false, $last['sent'], 'desde una misma dirección de una persona, el tope por red sigue');
    contains('Too many verification codes', (string) $last['message']);
});

test('si el correo del código no sale ni reintentando, la tienda NO dice que lo ha enviado, no lo deja en cola para más tarde y la revisión siguiente lo manda', function (): void {
    \Aifec\Modules\Mail\Mailer::$quickRetryPauseMs = 0;
    $flaky = new ApiFlakyTransport(3); // el primer intento y los dos reintentos fallan; después funciona
    $app = chatApp([], ['modules' => ['api']]);
    $app->bind('mail.transport', static function () use ($flaky): \Aifec\Mail\Transport {
        return $flaky;
    });
    $app->boot();
    $token = hash('sha256', 'una ficha');
    $out = \Aifec\Modules\Orders\Checkout\BuyerCode::send($app, $token, 'ana@correo.test', '64,85 €', 'a***@correo.test');
    same(false, $out['sent'], 'antes decía «enviado» con el correo parado cinco minutos en la cola');
    same([], $out['channels']);
    contains('could not send the verification code right now', $out['message']);
    contains('open the review again', $out['message'], 'y dice qué hacer');
    same(3, $flaky->calls, 'el intento y dos reintentos');
    same('failed', (string) $app->db()->value("SELECT status FROM {mail_messages} WHERE template = 'buyer_code'"), 'retirado: no llega después, cuando ya nadie lo espera');
    same(['sent' => 0, 'failed' => 0, 'held' => 0], $app->service('mailer')->sendPending(), 'la cola no tiene nada que mandar');
    same(3, $flaky->calls);

    // La revisión siguiente (el transporte ya funciona) lo manda: el fallo no ha gastado el envío de esa ficha ni el cupo del buzón.
    $again = \Aifec\Modules\Orders\Checkout\BuyerCode::send($app, $token, 'ana@correo.test', '64,85 €', 'a***@correo.test');
    same(true, $again['sent'], json_encode($again));
    same(false, isset($again['already_sent']), 'no es un «ya te lo mandé»');
    same(1, $flaky->sent);
    same(['failed', 'sent'], array_map('strval', $app->db()->column("SELECT status FROM {mail_messages} WHERE template = 'buyer_code' ORDER BY id")));
    \Aifec\Modules\Mail\Mailer::$quickRetryPauseMs = 400;
});

test('una demo no envía correo, salvo el código del comprador y solo por el buzón que le pone la instalación; lo demás sigue retenido', function (): void {
    $make = static function (array $config): array {
        $app = chatApp([], ['modules' => ['api']]);
        foreach ($config as $name => $value) {
            $app->site()->config[$name] = $value; // lo que la instalación escribe en el config.php del sitio
        }
        $array = new \Aifec\Mail\ArrayTransport();
        $app->bind('mail.transport', static function () use ($array): \Aifec\Mail\Transport {
            return $array;
        });
        $app->boot();

        return [$app, $array];
    };
    $send = static function (\Aifec\Kernel\App $app): array {
        return \Aifec\Modules\Orders\Checkout\BuyerCode::send($app, hash('sha256', 'una ficha'), 'ana@correo.test', '64,85 €', 'a***@correo.test');
    };

    [$app, $array] = $make(['demo' => true]);
    $out = $send($app);
    same(false, $out['sent'], 'sin buzón de la instalación, una demo no manda nada');
    contains('could not send', $out['message']);
    same(0, count($array->sent));
    same('held', (string) $app->db()->value("SELECT status FROM {mail_messages} WHERE template = 'buyer_code' ORDER BY id DESC"));

    [$app, $array] = $make(['demo' => true, 'code_mail' => ['host' => 'correo.instalacion.test', 'port' => 465, 'encryption' => 'ssl', 'user' => 'codigo@instalacion.test', 'password' => 'clave-de-mentira', 'from' => 'codigo@instalacion.test']]);
    $out = $send($app);
    same(true, $out['sent'], 'con el buzón de la instalación, el código sí sale');
    same(['email'], $out['channels']);
    same(1, count($array->sent));
    contains('codigo@instalacion.test', $array->sent[0]->toString(), 'y sale con el remitente de ese buzón, no con el de los ajustes de la demo');
    $app->service('mailer')->queue('mail_test', 'ana@correo.test', [], ['now' => true, 'approved' => true]);
    $app->service('mailer')->sendPending(50);
    same(1, count($array->sent), 'ningún otro correo sale de la demo, ni liberado a mano');
    same('held', (string) $app->db()->value("SELECT status FROM {mail_messages} WHERE template = 'mail_test' ORDER BY id DESC"));
});

test('con la lista de teléfonos permitidos, el WhatsApp sale solo a los teléfonos permitidos: a otro comprador, el código le llega solo por correo', function (): void {
    // CHAT_DATA lleva un teléfono de relleno (999000001): justo el caso del 15/11/2026, cuando el código le llegó a un desconocido.
    $got = [];
    $app = apiWhatsappApp(['api.whatsapp_only' => "34999500118\n+34 999 222 333"], $got);
    $ids = assistantShop($app);
    $review = apiBuy($app, apiCart($app, [[$ids['silla_roja'], 0, 1]]));
    same(0, count($got), 'no se llama a la pasarela para un teléfono que no está en la lista');
    same(['email'], $review['confirmation']['channels']);
    same(true, $review['confirmation']['sent'], 'el código sigue llegando por correo');
    notContains('WhatsApp', $review['next']);

    $app->boot();
    $allowed = static function (string $number) use ($app): bool {
        return \Aifec\Modules\Orders\Checkout\BuyerCode::allowed($app, $number);
    };
    same(true, $allowed('34999500118'));
    same(true, $allowed('34999222333'), 'la lista se entiende con espacios, saltos de línea y el signo +');
    same(false, $allowed('34999000001'));
    same(false, $allowed('3469900011'), 'un número parecido no es el número');

    $got = [];
    $open = apiWhatsappApp([], $got);
    $ids = assistantShop($open);
    apiBuy($open, apiCart($open, [[$ids['silla_roja'], 0, 1]]));
    same(1, count($got), 'sin lista, la tienda manda a cualquier comprador (es lo que hace una tienda real)');
});
