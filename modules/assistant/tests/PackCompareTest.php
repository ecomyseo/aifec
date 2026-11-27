<?php
/** AIFEC — aritmética de los paquetes a precios de catálogo y opciones parciales inequívocas. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';

test('el comparador distingue precio unitario, ahorro del pack y referencia retirada', function (): void {
    $app = assistantApp();
    $single = aifecProduct($app, ['name' => 'Una libreta', 'price' => 350]);
    $pack = aifecProduct($app, ['name' => 'Tres libretas', 'price' => 900]);
    $app->db()->update('products', ['pack_units' => 3, 'pack_single_id' => $single], ['id' => $pack]);
    $shop = new \Aifec\Modules\Assistant\ShopTools($app);
    $comparison = $shop->compareProducts(['ids' => [$single, $pack]], []);
    $row = $comparison['products'][1];
    same(300, $row['unit_price_cents']);
    same(150, $row['pack_saving_cents']);
    $app->db()->update('products', ['price' => 200], ['id' => $single]);
    $app->invalidate(['product:' . $single, 'catalog']);
    $row = $shop->compareProducts(['ids' => [$pack, $single]], [])['products'][0];
    check(!isset($row['pack_saving_cents']));
});

test('el prefijo final de color selecciona solo si hay un único valor posible', function (): void {
    $base = ['product_id' => 1, 'name' => 'Muestra', 'variants' => [], 'options' => [['name' => 'Color', 'values' => []]]];
    foreach (['Rubí', 'Rojo'] as $i => $label) {
        $base['variants'][] = ['id' => $i + 1, 'available' => true, 'label' => $label, 'value_labels' => [$label]];
        $base['options'][0]['values'][] = ['label' => $label, 'available' => true];
    }
    $pick = \Aifec\Modules\Assistant\VariantPick::fromNeed($base, 1);
    same(1, \Aifec\Modules\Assistant\VariantPick::resolve($pick, 'Color: RU')['variant']);
    $base['options'][0]['values'][] = ['label' => 'Rutenio', 'available' => false];
    $pick = \Aifec\Modules\Assistant\VariantPick::fromNeed($base, 1);
    same(0, \Aifec\Modules\Assistant\VariantPick::resolve($pick, 'Color: RU')['variant']);
});
