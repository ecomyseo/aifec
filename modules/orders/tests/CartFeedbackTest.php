<?php
/**
 * AIFEC — lo que la tienda le dice a quien compra sin JavaScript (ronda 3 de visitantes, 14/11/2026): «Añadir al carrito» sin
 * elegir la talla vuelve a la ficha con el aviso junto a las opciones (antes, a un carrito vacío), y un cupón que no vale dice
 * que el que ya estaba puesto sigue.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('sin elegir la talla, el formulario vuelve a la ficha con el aviso de qué falta, y por JSON sigue el error', function (): void {
    $app = aifecCartApp();
    aifecAttribute($app, 'talla', 'Talla', ['m' => 'M', 'l' => 'L']);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 1000, 'variants' => [
        ['sku' => 'CAM-M', 'stock' => 4, 'values' => ['talla' => 'm']],
        ['sku' => 'CAM-L', 'stock' => 4, 'values' => ['talla' => 'l']],
    ]]);
    $web = new TestClient($app);
    $r = $web->post('/carrito/add', ['product_id' => (string) $p, 'qty' => '1', 'opt' => ['talla' => '']]);
    same(302, $r->status);
    contains('/producto/camiseta?falta=opcion#af-opciones', (string) $r->headers['Location']);
    $page = $web->get('/producto/camiseta?falta=opcion');
    same(200, $page->status);
    contains('data-af-option-missing', $page->body);
    contains('Elige primero una opción: Talla', $page->body);
    notContains('data-af-option-missing', $web->get('/producto/camiseta')->body, 'sin la vuelta, sin aviso');
    $json = $web->postJson('/carrito/add', ['product_id' => $p, 'qty' => 1, 'opt' => ['talla' => '']]);
    same(422, $json->status);
    same('Elige primero una opción', $web->json()['error']);
});

test('un cupón que no vale dice que el que ya estaba puesto sigue aplicado', function (): void {
    $app = aifecCartApp(['modules' => ['coupons']]);
    $p = aifecProduct($app, ['price' => 2000, 'stock' => 5]);
    $now = $app->clock()->sql();
    $app->db()->insert('coupons', [
        'code' => 'BIENVENIDA10', 'kind' => 'percent', 'percent' => 1000, 'amount' => 0, 'min_subtotal' => 0, 'max_uses' => 0,
        'max_uses_per_customer' => 0, 'uses' => 0, 'first_order_only' => 0, 'exclude_sale' => 0, 'combinable' => 0, 'active' => 1,
        'created_at' => $now, 'updated_at' => $now,
    ]);
    $web = new TestClient($app);
    $web->postJson('/carrito/add', ['product_id' => $p, 'qty' => 1]);
    $web->postJson('/carrito/coupon', ['code' => 'BIENVENIDA10']);
    same(true, $web->json()['ok'], 'el bueno entra: ' . $web->json()['error']);
    $web->postJson('/carrito/coupon', ['code' => 'NOEXISTE99']);
    same(false, $web->json()['ok']);
    same('Este código de cupón no es válido. El cupón BIENVENIDA10 sigue aplicado.', $web->json()['error']);
});
