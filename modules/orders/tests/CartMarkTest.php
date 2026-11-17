<?php
/**
 * AIFEC — la marca del carrito: junto a la cookie del carrito (que el navegador no deja leer) viaja otra sin ningún
 * secreto que el JavaScript del tema sí puede ver. Con ella sabe, en una página servida desde la caché, si tiene que
 * pedir el resumen del carrito para pintar el contador. Sin ella el contador no salía nunca al cargar una página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('al crear el carrito salen dos cookies: la de verdad, que no se deja leer, y la marca, que sí', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 1210, 'stock' => 10]);
    $web = new TestClient($app);

    $r = $web->postJson('/carrito/add', ['product_id' => $p, 'variant_id' => 0, 'qty' => 1]);
    same(200, $r->status);
    $token = $app->cookieName('k');
    $mark = $app->cookieName('ck');
    check(isset($r->cookies[$token], $r->cookies[$mark]), 'la respuesta trae las dos');
    same(true, $r->cookies[$token]['options']['httponly'], 'la cookie del carrito no se deja leer desde JavaScript');
    same(false, $r->cookies[$mark]['options']['httponly'], 'la marca sí: es lo que mira el tema');
    same('1', $r->cookies[$mark]['value'], 'la marca no lleva ningún secreto');
    notContains($r->cookies[$token]['value'], $r->cookies[$mark]['value']);
    same($r->cookies[$token]['options']['path'], $r->cookies[$mark]['options']['path'], 'las dos con la ruta de la tienda');
    same('Lax', $r->cookies[$mark]['options']['samesite']);

    // Añadir más no vuelve a mandar cookies (la respuesta de una página no se «ensucia»).
    $r = $web->postJson('/carrito/add', ['product_id' => $p, 'variant_id' => 0, 'qty' => 1]);
    check(!isset($r->cookies[$mark]) && !isset($r->cookies[$token]), 'con el carrito ya creado no se repiten');
});

test('al vaciar el carrito se van las dos cookies', function (): void {
    $app = aifecCartApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 1210, 'stock' => 10]);
    $web = new TestClient($app);
    $web->postJson('/carrito/add', ['product_id' => $p, 'variant_id' => 0, 'qty' => 1]);
    $mark = $app->cookieName('ck');
    check(isset($web->cookies[$mark]), 'hay marca');
    $key = array_keys($web->json()['line'] !== null ? [$web->json()['line']['key'] => 1] : [])[0];

    $web->postJson('/carrito/update', ['key' => $key, 'qty' => 0]);
    check(!isset($web->cookies[$app->cookieName('k')]), 'se olvida la cookie del carrito');
    check(!isset($web->cookies[$mark]), 'y su marca: el tema deja de pedir el resumen');
});

test('el tema base mira la marca del carrito, no la cookie que no puede leer', function (): void {
    $app = aifecCartApp();
    $layout = (string) file_get_contents($app->root() . '/themes/base/templates/layout.php');
    contains("data-cart-cookie=\"<?= e(app()->cookieName('ck')) ?>\"", $layout);
    notContains("cookieName('k')", $layout);
    $web = new TestClient($app);
    $html = $web->get('/')->body;
    contains('data-cart-cookie="' . $app->cookieName('ck') . '"', $html, 'la página lleva el nombre de la marca');
});
