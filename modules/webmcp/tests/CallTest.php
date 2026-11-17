<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * POST /webmcp/call/{herramienta}: cada herramienta se ejecuta sobre el carrito de quien llama, solo desde la propia web,
 * con límite de uso, sin caché y sin dejar que se haga el pedido.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\RateLimiter;
use Aifec\Modules\Webmcp\WebmcpController;

require_once __DIR__ . '/helpers.php';

test('search_products por su dirección devuelve productos del catálogo y la respuesta no se guarda en ninguna caché', function (): void {
    $app = webmcpApp();
    assistantShop($app);
    $web = new TestClient($app);

    $r = $web->postJson('/webmcp/call/search_products', ['query' => 'silla']);
    same(200, $r->status);
    contains('application/json', $r->contentType());
    contains('no-store', $r->headers['Cache-Control']);
    $data = $web->json();
    check(isset($data['items']) && count($data['items']) >= 1, 'hay resultados: ' . $r->body);
    same(0, $r->cacheTtl, 'no se guarda en la caché de página');
});

test('cart_add y cart_view trabajan sobre el carrito del propio navegador, y otro navegador no lo ve', function (): void {
    $app = webmcpApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);

    $add = $web->postJson('/webmcp/call/cart_add', ['product_id' => $ids['mesa'], 'qty' => 2]);
    same(200, $add->status, $add->body);
    check(!isset($web->json()['error']), 'sin error: ' . $add->body);
    $lines = array_values(assistantCartLines($app, $web));
    same(1, count($lines), 'una línea en el carrito de este navegador');
    same(2, (int) $lines[0]['qty']);

    $view = $web->postJson('/webmcp/call/cart_view', []);
    same(200, $view->status);
    contains('Mesa', $view->body);

    $other = new TestClient($app);
    same([], assistantCartLines($app, $other), 'el carrito de otro navegador sigue vacío');
    $otherView = $other->postJson('/webmcp/call/cart_view', []);
    notContains('Mesa', $otherView->body);
});

test('cart_update cambia la cantidad de una línea y con 0 la quita', function (): void {
    $app = webmcpApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    $web->postJson('/webmcp/call/cart_add', ['product_id' => $ids['mesa'], 'qty' => 1]);
    $key = (string) array_values(assistantCartLines($app, $web))[0]['key'];

    same(200, $web->postJson('/webmcp/call/cart_update', ['key' => $key, 'qty' => 4])->status);
    same(4, (int) array_values(assistantCartLines($app, $web))[0]['qty']);
    same(200, $web->postJson('/webmcp/call/cart_update', ['key' => $key, 'qty' => 0])->status);
    same([], assistantCartLines($app, $web), 'con cantidad 0 la línea sale');
});

test('una entrada que no cumple el esquema da 422 con el motivo y no cambia nada', function (): void {
    $app = webmcpApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);

    foreach ([['product_id' => $ids['mesa'], 'qty' => 0], ['product_id' => $ids['mesa'], 'qty' => 500], ['qty' => 1], ['product_id' => $ids['mesa'], 'precio' => 1]] as $input) {
        $r = $web->postJson('/webmcp/call/cart_add', $input);
        same(422, $r->status, json_encode($input));
        check(isset($web->json()['error']) && $web->json()['error'] !== '', 'dice el motivo');
    }
    same([], assistantCartLines($app, $web), 'el carrito sigue vacío');
});

test('una herramienta que no existe da 404 y checkout_place no se ejecuta nunca por aquí: el pedido lo confirma la persona', function (): void {
    $app = webmcpApp();
    assistantShop($app);
    $web = new TestClient($app);

    $unknown = $web->postJson('/webmcp/call/borrar_tienda', []);
    same(404, $unknown->status);
    contains('Herramienta desconocida', $unknown->body);

    $place = $web->postJson('/webmcp/call/checkout_place', []);
    same(403, $place->status);
    contains('confirma la persona', $place->body);
    same(0, aifecOrderCount($app, 'orders'), 'no se ha hecho ningún pedido');
    // Tampoco con una conversación abierta o con la confirmación escrita en la entrada.
    $web->postJson('/asistente/mensaje', ['message' => 'Hola']);
    same(403, $web->postJson('/webmcp/call/checkout_place', ['confirmed' => true, 'confirm' => 'sí'])->status);
    same(0, aifecOrderCount($app, 'orders'));
});

test('la caja se prepara por herramientas: checkout_status dice qué falta del carrito del navegador', function (): void {
    $app = webmcpApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    $web->postJson('/webmcp/call/cart_add', ['product_id' => $ids['mesa'], 'qty' => 1]);

    $r = $web->postJson('/webmcp/call/checkout_status', []);
    same(200, $r->status, $r->body);
    check(!isset($web->json()['error']), $r->body);
    $review = $web->postJson('/webmcp/call/checkout_review', []);
    check(in_array($review->status, [200, 422], true), 'checkout_review responde: ' . $review->status);
    same(0, aifecOrderCount($app, 'orders'), 'preparar la caja no hace ningún pedido');
});

test('solo se acepta desde la propia web: una petición de otro sitio recibe 403 y no toca el carrito', function (): void {
    $app = webmcpApp();
    $ids = assistantShop($app);
    $web = new TestClient($app);
    $web->headers['sec-fetch-site'] = 'cross-site';

    $r = $web->postJson('/webmcp/call/cart_add', ['product_id' => $ids['mesa'], 'qty' => 1]);
    same(403, $r->status);
    $web->headers['sec-fetch-site'] = 'same-origin';
    same([], assistantCartLines($app, $web), 'el carrito sigue vacío');

    // Sin Sec-Fetch-Site, manda el Origin.
    unset($web->headers['sec-fetch-site']);
    $web->headers['origin'] = 'https://otra-web.test';
    same(403, $web->postJson('/webmcp/call/cart_add', ['product_id' => $ids['mesa'], 'qty' => 1])->status);
    $web->headers['origin'] = 'https://tienda.test';
    same(200, $web->postJson('/webmcp/call/cart_add', ['product_id' => $ids['mesa'], 'qty' => 1])->status);
});

test('el límite de uso corta a quien llama demasiadas veces, con Retry-After, y lo cuenta por dirección IP', function (): void {
    $app = webmcpApp();
    assistantShop($app);
    $web = new TestClient($app);
    $limiter = new RateLimiter($app->db(), $app->clock());
    for ($i = 0; $i < WebmcpController::PER_IP; $i++) {
        $limiter->hit('webmcp:ip:127.0.0.1', WebmcpController::PER_IP, WebmcpController::WINDOW);
    }

    $r = $web->postJson('/webmcp/call/store_info', ['topic' => 'all']);
    same(429, $r->status);
    same((string) WebmcpController::WINDOW, $r->headers['Retry-After']);
});

test('un cuerpo enorme se rechaza antes de ejecutar nada', function (): void {
    $app = webmcpApp();
    assistantShop($app);
    $web = new TestClient($app);

    $r = $web->postJson('/webmcp/call/search_products', ['query' => str_repeat('a', 70000)]);
    same(413, $r->status);
});
