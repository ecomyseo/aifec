<?php
/**
 * AIFEC — pruebas del precio: el vigente (oferta, variantes), el bloque con impuestos y el filtro sellado product.price.
 * Los precios se escriben con el IVA dentro (lo de serie): 1999 céntimos = 19,99 € con IVA. Cada cifra se ha hecho a mano.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('sin oferta: el precio escrito, con su IVA 21 % separado', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['price' => 1999]);
    $price = aifecCatalog($app)->product($id)['price'];
    // 1999 con IVA: base = 1999 / 1,21 = 1652,07 → 1652; cuota = 1999 − 1652 = 347.
    same(1999, $price['amount']);
    same(true, $price['with_tax']);
    same(1652, $price['net']);
    same(347, $price['tax']);
    same(1999, $price['gross']);
    same(2100, $price['tax_rate']);
    same('IVA 21 %', $price['tax_name']);
    same('EUR', $price['currency']);
    same(null, $price['compare']);
    same(false, $price['on_sale']);
    same(null, $price['discount_percent']);
    same(null, $price['sale_until']);
    same(false, $price['from']);
});

test('el bloque de precio tiene exactamente las claves del contrato', function (): void {
    $app = catalogApp();
    $price = aifecCatalog($app)->product(aifecProduct($app))['price'];
    same(['amount', 'with_tax', 'net', 'tax', 'gross', 'tax_rate', 'tax_name', 'currency', 'compare', 'on_sale', 'discount_percent', 'sale_until', 'from'], array_keys($price));
});

test('oferta vigente: precio de oferta, el normal como comparación y el porcentaje redondeado', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['price' => 2500, 'sale_price' => 2000, 'sale_to' => '2026-12-01 00:00:00']);
    $price = aifecCatalog($app)->product($id)['price'];
    // Oferta 2000 con IVA: base 2000 / 1,21 = 1652,89 → 1653; cuota 347. Descuento (2500 − 2000) × 100 / 2500 = 20.
    same(2000, $price['amount']);
    same(1653, $price['net']);
    same(347, $price['tax']);
    same(2500, $price['compare']);
    same(true, $price['on_sale']);
    same(20, $price['discount_percent']);
    same('2026-12-01 00:00:00', $price['sale_until']);
});

test('el porcentaje de descuento se redondea al entero más cercano', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['price' => 1500, 'sale_price' => 1000]);
    // (1500 − 1000) × 100 / 1500 = 33,33 → 33.
    same(33, aifecCatalog($app)->product($id)['price']['discount_percent']);
    $id = aifecProduct($app, ['price' => 1500, 'sale_price' => 500]);
    // (1500 − 500) × 100 / 1500 = 66,67 → 67.
    same(67, aifecCatalog($app)->product($id)['price']['discount_percent']);
});

test('oferta caducada, futura y con los límites exactos (el instante de empezar y de acabar cuentan)', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    // «Ahora» en las pruebas son las 12:00:00 del 12/11/2026.
    $expired = aifecProduct($app, ['price' => 2500, 'sale_price' => 2000, 'sale_to' => '2026-11-12 11:59:59']);
    $future = aifecProduct($app, ['price' => 2500, 'sale_price' => 2000, 'sale_from' => '2026-11-12 12:00:01']);
    $starts = aifecProduct($app, ['price' => 2500, 'sale_price' => 2000, 'sale_from' => '2026-11-12 12:00:00']);
    $ends = aifecProduct($app, ['price' => 2500, 'sale_price' => 2000, 'sale_to' => '2026-11-12 12:00:00']);
    same(2500, $catalog->product($expired)['price']['amount'], 'caducada: precio normal');
    same(null, $catalog->product($expired)['price']['compare']);
    same(2500, $catalog->product($future)['price']['amount'], 'futura: precio normal');
    same(2000, $catalog->product($starts)['price']['amount'], 'empieza ahora: ya vale');
    same(2000, $catalog->product($ends)['price']['amount'], 'acaba ahora: aún vale');
});

test('un precio de oferta que no es menor que el normal se ignora', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    $equal = aifecProduct($app, ['price' => 1000, 'sale_price' => 1000]);
    $higher = aifecProduct($app, ['price' => 1000, 'sale_price' => 1200]);
    same(1000, $catalog->product($equal)['price']['amount']);
    same(null, $catalog->product($equal)['price']['compare']);
    same(1000, $catalog->product($higher)['price']['amount']);
    same(false, $catalog->product($higher)['price']['on_sale']);
});

test('compare_price solo cuenta si es mayor que el vigente', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    $more = aifecProduct($app, ['price' => 1000, 'compare_price' => 1500]);
    $less = aifecProduct($app, ['price' => 1000, 'compare_price' => 900]);
    $same = aifecProduct($app, ['price' => 1000, 'compare_price' => 1000]);
    same(1500, $catalog->product($more)['price']['compare']);
    same(33, $catalog->product($more)['price']['discount_percent']); // 500 × 100 / 1500 = 33,33
    same(null, $catalog->product($less)['price']['compare']);
    same(null, $catalog->product($same)['price']['compare']);
});

test('con oferta, la comparación es el precio normal y no compare_price', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['price' => 2000, 'compare_price' => 3000, 'sale_price' => 1500]);
    same(2000, aifecCatalog($app)->product($id)['price']['compare']);
});

test('una variante con precio propio usa el suyo y su comparación; con precio nulo hereda el del producto con su oferta', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['price' => 1000, 'sale_price' => 900, 'variants' => [
        ['sku' => 'A', 'price' => 800, 'compare_price' => 1200, 'stock' => 5],
        ['sku' => 'B', 'stock' => 5],
        ['sku' => 'C', 'price' => 800, 'compare_price' => 700, 'stock' => 5],
    ]]);
    $variants = aifecCatalog($app)->product($id)['variants'];
    same(800, $variants[0]['price']['amount']);
    same(1200, $variants[0]['price']['compare']);
    same(33, $variants[0]['price']['discount_percent']); // 400 × 100 / 1200 = 33,33
    same(900, $variants[1]['price']['amount'], 'sin precio propio: la oferta del producto');
    same(1000, $variants[1]['price']['compare']);
    same(800, $variants[2]['price']['amount']);
    same(null, $variants[2]['price']['compare'], 'comparación menor que el precio: se ignora');
});

test('from: con variantes de precios distintos se enseña la más barata; con todas iguales, no', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    $different = aifecProduct($app, ['price' => 1000, 'variants' => [
        ['price' => 1200, 'stock' => 1], ['price' => 800, 'compare_price' => 1000, 'stock' => 1], ['price' => 1000, 'stock' => 1],
    ]]);
    $price = $catalog->product($different)['price'];
    same(800, $price['amount']);
    same(true, $price['from']);
    same(1000, $price['compare'], 'la comparación es la de la variante más barata');
    $equal = aifecProduct($app, ['price' => 1000, 'variants' => [['stock' => 1], ['price' => 1000, 'stock' => 1]]]);
    same(false, $catalog->product($equal)['price']['from']);
    same(1000, $catalog->product($equal)['price']['amount']);
});

test('una variante inactiva no cuenta para el precio «desde»', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['price' => 1000, 'variants' => [
        ['price' => 500, 'stock' => 1, 'active' => false], ['price' => 900, 'stock' => 1], ['price' => 900, 'stock' => 1],
    ]]);
    $price = aifecCatalog($app)->product($id)['price'];
    same(900, $price['amount']);
    same(false, $price['from']);
});

test('precios sin impuestos dentro: se suman al enseñarlos', function (): void {
    $app = catalogApp(['tax.prices_include_tax' => false]);
    $id = aifecProduct($app, ['price' => 1000, 'compare_price' => 1500]);
    $price = aifecCatalog($app)->product($id)['price'];
    // Base 1000 + 21 % (210) = 1210. Comparación 1500 + 315 = 1815. Descuento (1815 − 1210) × 100 / 1815 = 33,33 → 33.
    same(1210, $price['amount']);
    same(1000, $price['net']);
    same(210, $price['tax']);
    same(1210, $price['gross']);
    same(1815, $price['compare']);
    same(33, $price['discount_percent']);
});

test('enseñar sin impuestos con los precios escritos con IVA', function (): void {
    $app = catalogApp(['tax.display_with_tax' => false]);
    $id = aifecProduct($app, ['price' => 1999, 'compare_price' => 2500]);
    $price = aifecCatalog($app)->product($id)['price'];
    // Base de 1999 = 1652; base de 2500 = 2500 / 1,21 = 2066,12 → 2066. La cifra grande es la base.
    same(1652, $price['amount']);
    same(false, $price['with_tax']);
    same(1999, $price['gross']);
    same(2066, $price['compare']);
});

test('enseñar sin impuestos con los precios escritos sin ellos: el precio escrito tal cual', function (): void {
    $app = catalogApp(['tax.prices_include_tax' => false, 'tax.display_with_tax' => false]);
    $id = aifecProduct($app, ['price' => 1000]);
    $price = aifecCatalog($app)->product($id)['price'];
    same(1000, $price['amount']);
    same(false, $price['with_tax']);
    same(210, $price['tax']);
    same(1210, $price['gross']);
});

test('la clase de impuesto del producto: reducido 10 %', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['price' => 1100, 'tax_class_id' => 'reduced']);
    $price = aifecCatalog($app)->product($id)['price'];
    // 1100 con IVA 10 %: base 1000, cuota 100.
    same(1000, $price['net']);
    same(100, $price['tax']);
    same(1000, $price['tax_rate']);
    same('IVA 10 %', $price['tax_name']);
});

test('price() con destino Canarias: IGIC 7 %; Ceuta: sin IVA; y sin destino, el de la tienda', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    $id = aifecProduct($app, ['price' => 1070]);
    $pu = $catalog->purchasable($id);
    $canarias = $catalog->price($pu, ['country' => 'ES', 'region' => '35', 'postcode' => '35001', 'city' => 'Las Palmas']);
    // 1070 con IGIC 7 %: base 1070 / 1,07 = 1000, cuota 70.
    same(1000, $canarias['net']);
    same(70, $canarias['tax']);
    same(700, $canarias['tax_rate']);
    same('IGIC 7 %', $canarias['tax_name']);
    $ceuta = $catalog->price($pu, ['country' => 'ES', 'postcode' => '51001']);
    same(1070, $ceuta['net']);
    same(0, $ceuta['tax']);
    same(0, $ceuta['tax_rate']);
    $base = $catalog->price($pu);
    same(2100, $base['tax_rate'], 'sin destino: el de la tienda (península)');
    // 1070 / 1,21 = 884,30 → 884.
    same(884, $base['net']);
});

test('price() de un cliente exento: se le enseña la base, sin impuestos', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    $pu = $catalog->purchasable(aifecProduct($app, ['price' => 1210]));
    $normal = $catalog->price($pu, null, ['tax_exempt' => false]);
    same(1210, $normal['amount']);
    same(2100, $normal['tax_rate']);
    // Exento: 1210 con IVA 21 % es una base de 1000 y no se le suma nada.
    $exempt = $catalog->price($pu, null, ['tax_exempt' => true]);
    same(1000, $exempt['amount']);
    same(false, $exempt['with_tax']);
    same(0, $exempt['tax']);
    same(1000, $exempt['gross']);
    same(0, $exempt['tax_rate']);
    same('', $exempt['tax_name']);
});

test('product.price: sellado, solo entra un módulo con la capacidad «pricing»', function (): void {
    $app = catalogApp();
    $hooks = $app->hooks();
    $hooks->setCurrentModule('intruso');
    $hooks->filter('product.price', static function (array $block): array {
        $block['amount'] = 1;

        return $block;
    });
    $id = aifecProduct($app, ['price' => 1999]);
    same(1999, aifecCatalog($app)->product($id)['price']['amount'], 'un módulo sin capacidad no cambia el precio');
    $rejected = false;
    foreach ($hooks->errors() as $error) {
        if ($error['module'] === 'intruso' && $error['hook'] === 'product.price') {
            $rejected = true;
        }
    }
    check($rejected, 'el intento queda anotado');
    $hooks->setCurrentModule('catalog');
    $seen = [];
    $hooks->filter('product.price', static function (array $block, array $product) use (&$seen): array {
        $seen = $product;
        $block['amount'] = 777;

        return $block;
    });
    $other = aifecProduct($app, ['price' => 1999, 'sku' => 'X-1']);
    same(777, aifecCatalog($app)->product($other)['price']['amount'], 'con capacidad, sí');
    same($other, $seen['id']);
    same('X-1', $seen['sku']);
    same(1999, $seen['price']);
    $hooks->setCurrentModule('core');
});
