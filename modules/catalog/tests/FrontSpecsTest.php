<?php
/**
 * AIFEC — la tabla de características de la ficha: un valor numérico sale con su unidad UNA vez («130 kg», no «130 kg kg»).
 * Visto en las capturas de la tienda. Y el dato suelto (número y unidad) sigue disponible para las máquinas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('la ficha enseña «130 kg» y «36 meses» una sola vez, y el producto lleva el número y la unidad por separado', function (): void {
    $app = catalogApp();
    aifecAttribute($app, 'peso_max', 'Peso máximo', [], ['type' => 'number', 'unit' => 'kg']);
    aifecAttribute($app, 'garantia', 'Garantía', [], ['type' => 'number', 'unit' => 'meses']);
    aifecAttribute($app, 'largo', 'Largo', [], ['type' => 'number', 'unit' => '']);
    $id = aifecProduct($app, [
        'name' => 'Silla Ergo Pro', 'slug' => 'silla-ergo-pro', 'price' => 24900, 'stock' => 5,
        'attributes' => ['peso_max' => 130, 'garantia' => 36, 'largo' => 1.5, 'material' => 'Malla'],
    ]);

    $html = (new TestClient($app))->get('/producto/silla-ergo-pro')->body;
    contains('130 kg', $html);
    notContains('kg kg', $html, 'la unidad no se repite');
    contains('36 meses', $html);
    notContains('meses meses', $html);

    $product = aifecCatalog($app)->product($id);
    $byCode = [];
    foreach ($product['attributes'] as $attribute) {
        $byCode[$attribute['code']] = $attribute;
    }
    same('130 kg', $byCode['peso_max']['value'], 'el texto legible lleva la unidad');
    same(130.0, $byCode['peso_max']['number'], 'y el número va suelto, para JSON-LD y feeds');
    same('kg', $byCode['peso_max']['unit']);
    same('number', $byCode['peso_max']['type']);
    same(1.5, $byCode['largo']['number']);
    same('', $byCode['largo']['unit']);
    same(null, $byCode['material']['number'], 'un atributo que no es número no tiene número');
    same('', $byCode['material']['unit']);
});
