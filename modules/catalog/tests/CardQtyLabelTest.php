<?php
/**
 * AIFEC — en un listado, los botones de cantidad de cada tarjeta dicen de qué producto son (13/11/2026: doce «Cantidad»,
 * «Reducir la cantidad» y «Aumentar la cantidad» iguales en una página de doce productos).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('cada selector de cantidad de un listado lleva el nombre de su producto', function (): void {
    $app = testApp(['modules' => '*']);
    $cat = aifecCategory($app, 'Sillas');
    aifecProduct($app, ['name' => 'Silla Roble', 'price' => 5000, 'stock' => 5, 'categories' => [$cat]]);
    aifecProduct($app, ['name' => 'Silla Pino', 'price' => 4000, 'stock' => 5, 'categories' => [$cat]]);
    $body = (new TestClient($app))->get('/categoria/sillas')->body;
    check(strpos($body, 'data-af-qty') !== false, 'las tarjetas llevan selector de cantidad');
    foreach (['Silla Roble', 'Silla Pino'] as $name) {
        contains('aria-label="Cantidad de ' . $name . '"', $body);
        contains('aria-label="Reducir la cantidad de ' . $name . '"', $body);
        contains('aria-label="Aumentar la cantidad de ' . $name . '"', $body);
    }
    notContains('aria-label="Cantidad"', $body, 'ninguno se queda con el nombre genérico');
});
