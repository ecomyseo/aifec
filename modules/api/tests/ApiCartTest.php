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

test('crear un carrito da su ficha (en el cuerpo y en la cabecera), lo guarda solo como resumen y lo marca como de la API', function (): void {
    $app = apiApp();
    assistantShop($app);

    $r = apiCall($app, 'POST', '/api/v1/carts');
    $json = apiJson($r);

    same(201, $r->status);
    $token = $json['data']['token'];
    check(preg_match('/^[a-f0-9]{48}$/D', $token) === 1, 'la ficha es de 48 cifras hexadecimales');
    same($token, $r->headers['X-Cart-Token']);
    same(0, $json['data']['cart']['count']);
    same(0, $json['data']['cart']['items_total']);
    $row = $app->db()->row('SELECT * FROM {carts}');
    same('api', $row['source']);
    same(hash('sha256', $token), $row['token'], 'solo se guarda el resumen');
    notContains($token, (string) json_encode($row), 'la ficha en claro no está en la base de datos');
    contains('no-store', $r->headers['Cache-Control']);
});

test('añadir, ver, cambiar y quitar líneas: los totales son los del carrito de la tienda', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $token = apiCart($app);
    $h = ['x-cart-token' => $token];

    $add = apiCall($app, 'POST', '/api/v1/carts/current/items', ['product_id' => $ids['silla_roja'], 'qty' => 2], $h);
    $added = apiJson($add)['data'];
    same(201, $add->status);
    same(true, $added['ok']);
    same(2, $added['cart']['count']);
    same(11980, $added['cart']['items_total'], '2 sillas de 59,90 €');
    $key = $added['key'];
    check(preg_match('/^p\d+-v0-[a-f0-9]{6}$/D', $key) === 1, 'la clave de la línea es la del carrito');

    $mesa = apiJson(apiCall($app, 'POST', '/api/v1/carts/current/items', ['product_id' => $ids['mesa']], $h))['data'];
    same(3, $mesa['cart']['count']);
    same(11980 + 29900, $mesa['cart']['items_total']);

    $view = apiJson(apiCall($app, 'GET', '/api/v1/carts/current', null, $h))['data'];
    same(2, count($view['lines']));
    same($key, $view['lines'][0]['key']);
    same(2, $view['lines'][0]['qty']);
    same(5990, $view['lines'][0]['unit_price'], 'el precio unitario es el del catálogo');
    contains('59,90', $view['lines'][0]['unit_price_formatted']);
    same(11980, $view['lines'][0]['total']);
    same(41880, $view['items_total']);
    same('EUR', $view['currency']);

    $patch = apiCall($app, 'PATCH', '/api/v1/carts/current/items/' . $key, ['qty' => 3], $h);
    same(200, $patch->status);
    same(3 * 5990 + 29900, apiJson($patch)['data']['cart']['items_total']);

    $del = apiCall($app, 'DELETE', '/api/v1/carts/current/items/' . $key, null, $h);
    same(200, $del->status);
    same(29900, apiJson($del)['data']['cart']['items_total']);
    same(1, count(apiJson(apiCall($app, 'GET', '/api/v1/carts/current', null, $h))['data']['lines']));
});

test('un carrito vacío sigue siendo el mismo: al quitar la última línea la ficha no se pierde', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $token = apiCart($app, [[$ids['mesa'], 0, 1]]);
    $h = ['x-cart-token' => $token];
    $key = apiJson(apiCall($app, 'GET', '/api/v1/carts/current', null, $h))['data']['lines'][0]['key'];

    same(200, apiCall($app, 'DELETE', '/api/v1/carts/current/items/' . $key, null, $h)->status);
    $empty = apiCall($app, 'GET', '/api/v1/carts/current', null, $h);
    same(200, $empty->status, 'la ficha sigue valiendo');
    same(0, apiJson($empty)['data']['count']);
    same(201, apiCall($app, 'POST', '/api/v1/carts/current/items', ['product_id' => $ids['silla_roja']], $h)->status, 'y se puede volver a llenar');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'sigue habiendo un solo carrito');
});

test('sin ficha o con una ficha que no es de ningún carrito, 401; el carrito de otro no se ve', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $mine = apiCart($app, [[$ids['silla_roja'], 0, 1]]);
    $other = apiCart($app, [[$ids['mesa'], 0, 4]]);

    foreach ([['GET', '/api/v1/carts/current', null], ['POST', '/api/v1/carts/current/items', ['product_id' => $ids['mesa']]], ['GET', '/api/v1/carts/current/checkout-link', null], ['POST', '/api/v1/carts/checkout/status', null]] as $call) {
        same(401, apiCall($app, $call[0], $call[1], $call[2])->status, 'sin ficha: ' . $call[1]);
        same(401, apiCall($app, $call[0], $call[1], $call[2], ['x-cart-token' => str_repeat('a', 48)])->status, 'ficha de mentira: ' . $call[1]);
        same(401, apiCall($app, $call[0], $call[1], $call[2], ['x-cart-token' => 'corta'])->status, 'ficha mal formada: ' . $call[1]);
    }
    $a = apiJson(apiCall($app, 'GET', '/api/v1/carts/current', null, ['x-cart-token' => $mine]))['data'];
    $b = apiJson(apiCall($app, 'GET', '/api/v1/carts/current', null, ['x-cart-token' => $other]))['data'];
    same(1, $a['count']);
    same(4, $b['count']);
    same($ids['silla_roja'], $a['lines'][0]['product_id']);
    same($ids['mesa'], $b['lines'][0]['product_id']);
    same(401, apiJson(apiCall($app, 'GET', '/api/v1/carts/current'))['error']['status']);
});

test('lo que no se puede comprar o está mal pedido sale como error y no toca el carrito', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $token = apiCart($app);
    $h = ['x-cart-token' => $token];

    $sold = apiCall($app, 'POST', '/api/v1/carts/current/items', ['product_id' => $ids['silla_azul']], $h);
    same(422, $sold->status, 'la agotada no se añade');
    check(isset(apiJson($sold)['error']['message']), 'dice por qué');
    same(422, apiCall($app, 'POST', '/api/v1/carts/current/items', ['product_id' => 99999], $h)->status);
    same(422, apiCall($app, 'POST', '/api/v1/carts/current/items', ['product_id' => $ids['mesa'], 'price' => 1], $h)->status, 'no se acepta un precio');
    same(422, apiCall($app, 'POST', '/api/v1/carts/current/items', ['qty' => 1], $h)->status);
    same(400, apiCall($app, 'POST', '/api/v1/carts/current/items', '{esto no es json', $h)->status);
    same(400, apiCall($app, 'POST', '/api/v1/carts/current/items', '[1,2]', $h)->status);
    same(400, apiCall($app, 'PATCH', '/api/v1/carts/current/items/p1-v0-aaaaaa', [], $h)->status);
    same(422, apiCall($app, 'PATCH', '/api/v1/carts/current/items/p1-v0-aaaaaa', ['qty' => 1], $h)->status, 'una línea que no está');
    same(0, apiJson(apiCall($app, 'GET', '/api/v1/carts/current', null, $h))['data']['count']);
});

test('el enlace de pago: una dirección de la tienda que pone la cookie y lleva a la caja con las mismas líneas', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $token = apiCart($app, [[$ids['silla_roja'], 0, 2], [$ids['mesa'], 0, 1]]);

    $link = apiJson(apiCall($app, 'GET', '/api/v1/carts/current/checkout-link', null, ['x-cart-token' => $token]))['data'];

    same('https://tienda.test/carrito/retomar/' . $token, $link['url']);
    same($link['url'], $link['checkout_url'], 'las dos claves dan la dirección de ESTE carrito: la caja a secas le saldría vacía a la persona');
    contains('would show them an empty cart', (string) $link['note']);
    same(2 + 1, $link['cart']['count']);
    $web = new TestClient($app);
    $r = $web->get('/carrito/retomar/' . $token);
    same(302, $r->status);
    contains('/finalizar-compra', $r->headers['Location']);
    contains('no-store', $r->headers['Cache-Control']);
    contains('noindex', $r->headers['X-Robots-Tag']);
    check(isset($web->cookies[$app->cookieName('k')]), 'puso la cookie del carrito');
    $lines = assistantCartLines($app, $web);
    same(2, count($lines));
    $qty = [];
    foreach ($lines as $line) {
        $qty[(int) $line['product_id']] = (int) $line['qty'];
    }
    same([$ids['silla_roja'] => 2, $ids['mesa'] => 1], $qty, 'las mismas líneas que preparó el programa');
    $checkout = $web->get('/finalizar-compra');
    same(200, $checkout->status);
    contains('Silla roja', $checkout->body, 'la caja enseña lo que había en el carrito');

    same(404, (new TestClient($app))->get('/carrito/retomar/' . str_repeat('b', 48))->status, 'una ficha que no existe');
    same(404, (new TestClient($app))->get('/carrito/retomar/corta')->status);
});

test('un carrito vacío no tiene enlace de pago; el que ya es un pedido, tampoco se abre ni se retoma', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $empty = apiCall($app, 'GET', '/api/v1/carts/current/checkout-link', null, ['x-cart-token' => apiCart($app)]);
    same(422, $empty->status);

    $token = apiCart($app, [[$ids['mesa'], 0, 1]]);
    $app->db()->update('carts', ['order_id' => 77], ['token' => hash('sha256', $token)]);
    same(409, apiCall($app, 'GET', '/api/v1/carts/current', null, ['x-cart-token' => $token])->status);
    same(404, (new TestClient($app))->get('/carrito/retomar/' . $token)->status);
});

test('las direcciones del carrito llevan nombre y salen de la tabla de rutas', function (): void {
    $app = apiApp();
    $router = $app->router();

    foreach (['api.carts.create', 'api.carts.show', 'api.carts.items.add', 'api.carts.checkout_link', 'api.checkout.place', 'api.cart.resume', 'api.docs', 'api.openapi'] as $name) {
        check($router->has($name), $name);
    }
    same('/api/v1/carts/current/items/p1-v0-abc', $router->path('api.carts.items.update', ['key' => 'p1-v0-abc']));
    $token = str_repeat('c', 48);
    same('/carrito/retomar/' . $token, $router->path('api.cart.resume', ['token' => $token]));
});
