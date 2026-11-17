<?php
/**
 * AIFEC — pruebas de las rutas del carrito: añadir por JSON y por formulario, envíos desde otra web, cantidades, cupones,
 * destino, el resumen del minicarrito y la página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('POST add por JSON: contesta {ok, error, count, line, totals} y no cambia la caché del navegador', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 1210, 'tax_class_id' => 'standard', 'stock' => 10]);
    $web = new TestClient($app);

    $r = $web->postJson('/carrito/add', ['product_id' => $p, 'variant_id' => 0, 'qty' => 2, 'data' => ['grabado' => 'Ana']]);
    same(200, $r->status);
    $json = $web->json();
    same(true, $json['ok']);
    same('', $json['error']);
    same(2, $json['count']);
    same(['items_total' => 2420, 'total' => 2420], $json['totals'], 'artículos y total: el mismo que enseña la página (sin envío mientras no hay destino)');
    $money = static function (int $cents) use ($app): string {
        return $app->i18n()->money($cents, 'EUR');
    };
    same('Camiseta', $json['line']['name']);
    same(2, $json['line']['qty']);
    same(1210, $json['line']['unit_price']);
    same($money(1210), $json['line']['unit_price_formatted']);
    same($money(2420), $json['line']['total_formatted']);
    contains('no-store', $r->headers['Cache-Control']);
    check(isset($web->cookies[$app->cookieName('k')]), 'la respuesta trae la cookie del carrito');

    // Lo que falla contesta 422 con el motivo y sin tocar el carrito (otra línea: sin los datos de antes, aún no hay unidades suyas).
    $bad = $web->postJson('/carrito/add', ['product_id' => $p, 'qty' => 50]);
    same(422, $bad->status);
    $badJson = $web->json();
    same(false, $badJson['ok']);
    same('Solo quedan 10 unidades', $badJson['error']);
    same(2, $badJson['count']);
    same(null, $badJson['line']);
});

test('POST add por formulario: redirige al carrito, y solo si hay error deja el aviso en la sesión de cliente', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['stock' => 3]);
    $web = new TestClient($app);
    $cartUrl = route('cart');

    $ok = $web->post('/carrito/add', ['product_id' => (string) $p, 'qty' => '2']);
    same(302, $ok->status);
    contains('/carrito', $ok->headers['Location']);
    same($cartUrl, $ok->headers['Location']);
    same(2, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));
    $sessionCookies = array_filter(array_keys($web->cookies), static function (string $name): bool {
        return strpos($name, 'af_c_') === 0;
    });
    same([], array_values($sessionCookies), 'sin error no se abre sesión: no hay cookie de cliente');

    $bad = $web->post('/carrito/add', ['product_id' => (string) $p, 'qty' => '9']);
    same(302, $bad->status);
    same($cartUrl, $bad->headers['Location']);
    $flash = aifecCartRun($web, static function ($cart, \Aifec\Kernel\App $app): array {
        return $app->session('customer')->get('_flash', []);
    });
    same(['cart_error'], array_keys($flash));
    contains('Solo quedan 3 unidades', $flash['cart_error']);
    same(2, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }), 'el error no toca el carrito');
});

test('un envío desde otra web se rechaza con 403 y no crea carrito', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['stock' => 3]);
    $web = new TestClient($app);

    foreach (['/carrito/add', '/carrito/update', '/carrito/remove', '/carrito/coupon', '/carrito/destination'] as $path) {
        $r = $web->post($path, ['product_id' => $p, 'qty' => 1, 'key' => 'x', 'code' => 'X', 'country' => 'ES'], ['sec-fetch-site' => 'cross-site']);
        same(403, $r->status, $path);
    }
    $viaJson = $web->send('POST', '/carrito/add', ['product_id' => $p, 'qty' => 1], ['origin' => 'https://otra-web.test', 'sec-fetch-site' => 'cross-site']);
    same(403, $viaJson->status);
    same(0, shippingCount($app, 'carts'));
    same([], $web->cookies);
});

test('POST update: cambia la cantidad, con 0 quita la línea y al quedar vacío el carrito se va con su cookie', function (): void {
    $app = aifecCartApp();
    $a = aifecProduct($app, ['stock' => 10]);
    $web = new TestClient($app);
    $key = '';
    $web->postJson('/carrito/add', ['product_id' => $a, 'qty' => 1]);
    $key = (string) $web->json()['line']['key'];

    $web->postJson('/carrito/update', ['key' => $key, 'qty' => 4]);
    same(true, $web->json()['ok']);
    same(4, $web->json()['count']);
    same(4, $web->json()['line']['qty']);

    $web->postJson('/carrito/update', ['key' => $key, 'qty' => 0]);
    same(true, $web->json()['ok']);
    same(0, $web->json()['count']);
    same(null, $web->json()['line']);
    same(0, shippingCount($app, 'carts'));
    check(!isset($web->cookies[$app->cookieName('k')]), 'la cookie desaparece');

    $web->postJson('/carrito/update', ['key' => $key, 'qty' => 2]);
    same(422, $web->last->status, 'actualizar lo que ya no está es un error');
    same('Tu carrito está vacío', $web->json()['error']);

    $web->postJson('/carrito/add', ['product_id' => $a, 'qty' => 1]);
    $key = (string) $web->json()['line']['key'];
    $web->postJson('/carrito/remove', ['key' => $key]);
    same(true, $web->json()['ok']);
    same(0, $web->json()['count']);
    same(0, shippingCount($app, 'carts'));
});

test('POST coupon: aplica un código, rechaza el que no vale con su motivo y quita el que se pide', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 1000, 'kind' => 'digital', 'stock' => 10]);
    couponsMake($app);
    couponsMake($app, ['code' => 'VIEJO', 'ends_at' => '2026-01-01 00:00:00']);
    $web = new TestClient($app);

    $web->postJson('/carrito/coupon', ['code' => 'otono']);
    same(422, $web->last->status);
    same('Tu carrito está vacío', $web->json()['error']);

    $web->postJson('/carrito/add', ['product_id' => $p, 'qty' => 1]);
    $web->postJson('/carrito/coupon', ['code' => ' otono ']);
    same(200, $web->last->status);
    same(true, $web->json()['ok']);
    same(900, $web->json()['totals']['total']);

    $web->postJson('/carrito/coupon', ['code' => 'VIEJO']);
    same(422, $web->last->status);
    same('Este cupón ha caducado. El cupón OTONO sigue aplicado.', $web->json()['error'], 'y dice que el que ya estaba sigue');
    same(900, $web->json()['totals']['total'], 'el cupón bueno sigue puesto');

    $web->postJson('/carrito/coupon', ['code' => 'NOEXISTE']);
    same('Este código de cupón no es válido. El cupón OTONO sigue aplicado.', $web->json()['error']);

    $web->postJson('/carrito/coupon', ['remove' => 'OTONO']);
    same(true, $web->json()['ok']);
    same(1000, $web->json()['totals']['total']);

    // Por formulario: error → aviso en la sesión y vuelta al carrito.
    $form = $web->post('/carrito/coupon', ['code' => 'NOEXISTE']);
    same(302, $form->status);
    $flash = aifecCartRun($web, static function ($cart, \Aifec\Kernel\App $app): array {
        return $app->session('customer')->get('_flash', []);
    });
    same(['cart_error' => 'Este código de cupón no es válido'], $flash);
});

test('POST destination: el país, el código postal y el transportista cambian los impuestos y el envío', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['price' => 1070, 'tax_class_id' => 'standard', 'weight' => 100, 'stock' => 10]);
    $web = new TestClient($app);
    $web->postJson('/carrito/add', ['product_id' => $p, 'qty' => 1]);
    $web->post('/carrito/destination', ['country' => 'es', 'postcode' => '35001', 'city' => 'Las Palmas']);
    same(302, $web->last->status);

    $s = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(['country' => 'ES', 'region' => '', 'postcode' => '35001', 'city' => 'Las Palmas'], $s['destination']);
    same(700, $s['lines'][array_keys($s['lines'])[0]]['tax_rate'], 'IGIC en Canarias');
    same(1070 + 495, $s['totals']['total']);

    // Lo que no viene se queda como estaba; un país raro se ignora.
    $web->post('/carrito/destination', ['postcode' => '28001']);
    $again = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(['country' => 'ES', 'region' => '', 'postcode' => '28001', 'city' => 'Las Palmas'], $again['destination']);
    $web->post('/carrito/destination', ['country' => 'ESPAÑA']);
    same('ES', aifecCartRun($web, static function ($cart): string {
        return $cart->summary()['destination']['country'];
    }), 'un país que no son dos letras vuelve al de por defecto');

    // Sin carrito, ni crea cookie ni fila.
    $empty = new TestClient($app);
    $empty->post('/carrito/destination', ['country' => 'PT']);
    same([], $empty->cookies);
    same(1, shippingCount($app, 'carts'));
});

test('GET summary.json: sin cookie contesta {count: 0, lines: []} sin consultar nada ni poner cookies; con carrito, importes formateados', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['name' => 'Taza', 'price' => 1210, 'tax_class_id' => 'standard', 'stock' => 10, 'weight' => 100]);
    $web = new TestClient($app);

    $before = $app->db()->queries;
    $r = $web->get('/carrito/summary.json');
    same(200, $r->status);
    same(['count' => 0, 'lines' => []], $web->json());
    same([], $r->cookies, 'ninguna cookie en la respuesta');
    same($before, $app->db()->queries, 'ninguna consulta nueva');
    contains('no-store', $r->headers['Cache-Control']);

    $web->postJson('/carrito/add', ['product_id' => $p, 'qty' => 2]);
    $web->get('/carrito/summary.json');
    $json = $web->json();
    $money = static function (int $cents) use ($app): string {
        return $app->i18n()->money($cents, 'EUR');
    };
    same(2, $json['count']);
    same(1, count($json['lines']));
    $line = $json['lines'][0];
    same('Taza', $line['name']);
    same(2, $line['qty']);
    same(1210, $line['unit_price']);
    same($money(1210), $line['unit_price_formatted']);
    same(2420, $line['total']);
    same($money(2420), $line['total_formatted']);
    same(2420, $json['totals']['items_total']);
    same($money(2420), $json['totals']['items_total_formatted']);
    same(2420, $json['totals']['total'], 'sin destino el total va sin envío, como en la página del carrito');
    same($money(2420), $json['totals']['total_formatted']);
    same(true, $json['totals']['shipping_pending']);
    same('EUR', $json['currency']);
});

test('el minicarrito enseña los importes como pide el ajuste de la tienda: sin impuestos, la base', function (): void {
    $app = aifecCartApp(['settings' => ['tax.display_with_tax' => false]]);
    $p = aifecProduct($app, ['name' => 'Taza', 'price' => 1210, 'tax_class_id' => 'standard', 'kind' => 'digital', 'stock' => 10]);
    $web = new TestClient($app);
    $web->postJson('/carrito/add', ['product_id' => $p, 'qty' => 1]);
    $web->get('/carrito/summary.json');
    $json = $web->json();
    $money = static function (int $cents) use ($app): string {
        return $app->i18n()->money($cents, 'EUR');
    };
    same(false, $json['display_with_tax']);
    same(1210, $json['lines'][0]['unit_price'], 'el precio «tal como se escribe» no cambia');
    same($money(1000), $json['lines'][0]['unit_price_formatted'], 'pero se enseña la base');
    same($money(1000), $json['lines'][0]['total_formatted']);
    same($money(1000), $json['totals']['items_total_formatted']);
    same(1210, $json['totals']['total'], 'y lo que se paga sigue siendo con impuestos');
});

test('la página del carrito la pinta la plantilla orders::front/cart, y un tema puede sustituirla', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['stock' => 10]);
    $web = new TestClient($app);
    $web->postJson('/carrito/add', ['product_id' => $p, 'qty' => 3]);

    $r = $web->get('/carrito');
    same(200, $r->status);
    contains('af-cart-line', $r->body);
    contains('no-store', $r->headers['Cache-Control']);

    $dir = tmpDir('aifec-cart-tpl');
    mkdir($dir . '/modules/orders/front', 0777, true);
    file_put_contents($dir . '/modules/orders/front/cart.php', '<h1>Carrito de <?= e((string) $cart[\'count\']) ?> uds · <?= e($page[\'title\']) ?></h1>');
    $app->view()->setThemeDirs([$dir]);

    $page = $web->get('/carrito');
    same(200, $page->status);
    contains('<h1>Carrito de 3 uds · Tu carrito</h1>', $page->body);
    contains('no-store', $page->headers['Cache-Control']);
});

test('las rutas llevan los nombres del contrato y respetan la dirección del carrito del ajuste urls.cart', function (): void {
    $app = aifecCartApp(['settings' => ['urls.cart' => 'cesta']]);
    $p = aifecProduct($app, ['stock' => 10]);
    foreach (['cart', 'cart.add', 'cart.update', 'cart.remove', 'cart.coupon', 'cart.summary', 'cart.destination'] as $name) {
        check($app->router()->has($name), 'la ruta ' . $name . ' existe');
    }
    same('/cesta', $app->router()->path('cart'));
    same('/cesta/summary.json', $app->router()->path('cart.summary'));
    $web = new TestClient($app);
    $web->postJson('/cesta/add', ['product_id' => $p, 'qty' => 1]);
    same(true, $web->json()['ok']);
    $web->post('/carrito/add', ['product_id' => $p, 'qty' => 1]);
    same(404, $web->last->status, 'la dirección vieja ya no existe');
});
