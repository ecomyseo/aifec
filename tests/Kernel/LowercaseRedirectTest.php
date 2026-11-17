<?php
/**
 * AIFEC — la dirección amigable de una entidad escrita con mayúsculas redirige (301) a la misma en minúsculas (la visita de SEO del
 * 13/11/2026: «/Categoria/hogar-y-muebles» y «/CATEGORIA/HOGAR-Y-MUEBLES» contestaban 200). Lo demás no se toca: un número de pedido
 * o una clave pueden llevar mayúsculas de verdad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/modules/catalog/tests/helpers.php';

test('una categoría con mayúsculas en la dirección redirige a minúsculas con su consulta; el resto de direcciones no se toca', function (): void {
    $app = testApp(['modules' => '*']);
    $cat = aifecCategory($app, 'Hogar');
    aifecProduct($app, ['name' => 'Silla', 'price' => 1000, 'categories' => [$cat]]);
    $r = (new TestClient($app))->get('/Categoria/HOGAR?sort=price_asc');
    same(301, $r->status);
    same('/categoria/hogar?sort=price_asc', $r->headers['Location'] ?? '', 'a la misma en minúsculas, con su consulta');
    same(200, (new TestClient($app))->get('/categoria/hogar')->status, 'en minúsculas, se sirve');
    foreach (['/mi-cuenta/pedidos/T-001', '/admin/Login', '/api/v1/Products', '/cron/AbCdEf123', '/NoExiste'] as $path) {
        $other = (new TestClient($app))->get($path);
        check($other->status !== 301 || stripos((string) ($other->headers['Location'] ?? ''), strtolower($path)) === false, 'no se pasa a minúsculas: ' . $path);
    }
});
