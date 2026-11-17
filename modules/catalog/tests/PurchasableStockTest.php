<?php
/**
 * AIFEC — pruebas de purchasable() (lo que necesita el carrito) y de adjustStock() (existencias con sentencia condicionada,
 * movimientos apuntados, caché invalidada y la acción stock.changed).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('purchasable de un producto sencillo: todo lo que pide el carrito', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $c1 = aifecCategory($app, 'Sillas');
    $c2 = aifecCategory($app, 'Oficina');
    $id = aifecProduct($app, [
        'name' => 'Silla Ergo', 'price' => 1999, 'sku' => 'S-1', 'tax_class_id' => 'reduced', 'weight' => 7500, 'min_qty' => 2, 'max_qty' => 5,
        'stock' => 8, 'categories' => [$c2, $c1], 'images' => 2,
    ]);
    $pu = aifecCatalog($app)->purchasable($id);
    foreach (['product_id', 'variant_id', 'name', 'variant_label', 'sku', 'unit_price', 'compare_price', 'tax_class_id', 'weight', 'kind', 'is_digital', 'stock',
        'backorders', 'min_qty', 'max_qty', 'image', 'url', 'category_ids', 'on_sale', 'available', 'reason'] as $key) {
        check(array_key_exists($key, $pu), 'falta ' . $key);
    }
    same($id, $pu['product_id']);
    same(0, $pu['variant_id']);
    same('Silla Ergo', $pu['name']);
    same('', $pu['variant_label']);
    same('S-1', $pu['sku']);
    same(1999, $pu['unit_price']);
    same(null, $pu['compare_price']);
    same((int) $app->db()->value('SELECT id FROM {tax_classes} WHERE code = ?', ['reduced']), $pu['tax_class_id']);
    same(7500, $pu['weight']);
    same('physical', $pu['kind']);
    same(false, $pu['is_digital']);
    same(8, $pu['stock']);
    same('no', $pu['backorders']);
    same(2, $pu['min_qty']);
    same(5, $pu['max_qty']);
    same('/producto/silla-ergo', $pu['url']);
    same([$c2, $c1], $pu['category_ids'], 'la principal primero');
    same(false, $pu['on_sale']);
    same(true, $pu['available']);
    same('', $pu['reason']);
    same(['id', 'url', 'alt', 'width', 'height', 'thumb', 'card', 'large'], array_keys($pu['image']));
    same('Foto 1 de Silla Ergo', $pu['image']['alt']);
});

test('purchasable: el precio ya lleva la oferta aplicada; un producto digital lo dice', function (): void {
    $app = catalogApp();
    $sale = aifecProduct($app, ['price' => 2000, 'sale_price' => 1500, 'sale_to' => '2026-12-01 00:00:00']);
    $pu = aifecCatalog($app)->purchasable($sale);
    same(1500, $pu['unit_price']);
    same(2000, $pu['compare_price']);
    same(true, $pu['on_sale']);
    same('2026-12-01 00:00:00', $pu['sale_until']);
    $digital = aifecCatalog($app)->purchasable(aifecProduct($app, ['kind' => 'digital', 'manage_stock' => 0]));
    same('digital', $digital['kind']);
    same(true, $digital['is_digital']);
    same(null, $digital['stock'], 'sin control de existencias: null');
    same(true, $digital['available']);
    same(null, $digital['image'], 'sin imágenes: null');
    same(null, $digital['tax_class_id'], 'sin clase: la de por defecto, que decide el servicio de impuestos');
});

test('purchasable: un producto que no existe es null; uno sin publicar no se puede comprar y dice por qué', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    same(null, $catalog->purchasable(424242));
    foreach ([['status' => 'draft'], ['status' => 'archived'], ['published_at' => '2026-11-13 00:00:00']] as $datos) {
        $pu = $catalog->purchasable(aifecProduct($app, $datos));
        same(false, $pu['available']);
        same('Este producto no está disponible', $pu['reason']);
    }
    $visible = $catalog->purchasable(aifecProduct($app, ['visibility' => 'hidden']));
    same(true, $visible['available'], 'oculto en los listados no es no comprable');
});

test('purchasable con variantes: hace falta elegir; la variante pone su sku, su etiqueta, su peso y su precio', function (): void {
    $app = catalogApp();
    aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo']);
    aifecAttribute($app, 'talla', 'Talla', ['m' => 'M']);
    $id = aifecProduct($app, ['price' => 1000, 'sku' => 'CAM', 'weight' => 200, 'variants' => [
        ['sku' => 'CAM-R-M', 'price' => 1200, 'compare_price' => 1500, 'stock' => 4, 'weight' => 250, 'values' => ['color' => 'rojo', 'talla' => 'm']],
        ['sku' => 'CAM-X', 'stock' => 4, 'active' => false],
    ]]);
    $variantId = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['CAM-R-M']);
    $inactive = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['CAM-X']);
    $catalog = aifecCatalog($app);

    $none = $catalog->purchasable($id);
    same(false, $none['available']);
    same('Elige primero una opción', $none['reason']);

    $pu = $catalog->purchasable($id, $variantId);
    same(true, $pu['available']);
    same($variantId, $pu['variant_id']);
    same('CAM-R-M', $pu['sku']);
    same('Rojo / M', $pu['variant_label']);
    same(250, $pu['weight']);
    same(1200, $pu['unit_price']);
    same(1500, $pu['compare_price']);
    same(true, $pu['on_sale']);
    same(4, $pu['stock'], 'las existencias de la variante');

    $off = $catalog->purchasable($id, $inactive);
    same(false, $off['available']);
    same('La opción elegida no está disponible', $off['reason']);
    same(false, $catalog->purchasable($id, 999999)['available'], 'una variante que no existe');
});

test('purchasable: una variante de otro producto, o una variante pedida a un producto que no las tiene, no valen', function (): void {
    $app = catalogApp();
    $withVariants = aifecProduct($app, ['variants' => [['sku' => 'V1', 'stock' => 3]]]);
    $simple = aifecProduct($app, []);
    $other = aifecProduct($app, ['variants' => [['sku' => 'V2', 'stock' => 3]]]);
    $v1 = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['V1']);
    $catalog = aifecCatalog($app);
    same(false, $catalog->purchasable($other, $v1)['available'], 'la variante es de otro producto');
    same(false, $catalog->purchasable($simple, $v1)['available'], 'el producto no tiene variantes');
    same(true, $catalog->purchasable($withVariants, $v1)['available']);
});

test('purchasable: agotado no se puede comprar, salvo con pedidos sin existencias', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    $out = $catalog->purchasable(aifecProduct($app, ['stock' => 0]));
    same(false, $out['available']);
    same('Agotado', $out['reason']);
    same(0, $out['stock']);
    same(true, $catalog->purchasable(aifecProduct($app, ['stock' => 0, 'backorders' => 'yes']))['available']);
    same('yes', $catalog->purchasable(aifecProduct($app, ['stock' => 0, 'backorders' => 'yes']))['backorders']);
    same(false, $catalog->purchasable(aifecProduct($app, ['stock' => 0, 'backorders' => 'notify']))['available']);
    same(true, $catalog->purchasable(aifecProduct($app, ['stock' => 0, 'manage_stock' => 0]))['available']);
    $variantOut = aifecProduct($app, ['variants' => [['sku' => 'A', 'stock' => 0], ['sku' => 'B', 'stock' => 2]]]);
    $a = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['A']);
    $b = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['B']);
    same(false, $catalog->purchasable($variantOut, $a)['available']);
    same(true, $catalog->purchasable($variantOut, $b)['available']);
});

test('purchasable no usa la caché: las existencias son las de ahora', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['stock' => 3]);
    $catalog = aifecCatalog($app);
    same(3, $catalog->purchasable($id)['stock']);
    $app->db()->update('products', ['stock' => 1], ['id' => $id]);
    same(1, $catalog->purchasable($id)['stock']);
});

test('purchasable en inglés: los motivos salen sin traducir', function (): void {
    $app = catalogApp(['i18n.languages' => ['es', 'en']]);
    $id = aifecProduct($app, ['status' => 'draft', 'i18n' => ['en' => ['name' => 'Chair']]]);
    $pu = aifecCatalog($app)->purchasable($id, 0, 'en');
    same('Chair', $pu['name']);
    same('This product is not available', $pu['reason']);
    same('/en/producto/chair', $pu['url']);
});

test('adjustStock descuenta, apunta el movimiento y deja el resto', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['stock' => 5]);
    same(true, aifecCatalog($app)->adjustStock($id, 0, -2, 'order #1001'));
    same(3, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$id]));
    $moves = $app->db()->select('SELECT * FROM {stock_moves} WHERE product_id = ?', [$id]);
    same(1, count($moves));
    same(-2, (int) $moves[0]['delta']);
    same(3, (int) $moves[0]['stock_after']);
    same(0, (int) $moves[0]['variant_id']);
    same('order #1001', $moves[0]['reason']);
    same(null, $moves[0]['reference']);
    same('2026-11-12 12:00:00', $moves[0]['created_at']);
});

test('adjustStock: sin existencias bastantes se niega y no cambia nada ni apunta nada', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['stock' => 1]);
    same(false, aifecCatalog($app)->adjustStock($id, 0, -2, 'order'));
    same(1, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$id]));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {stock_moves}'));
});

test('adjustStock: dos descuentos seguidos del último no dejan existencias negativas', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['stock' => 1]);
    $catalog = aifecCatalog($app);
    same(true, $catalog->adjustStock($id, 0, -1, 'order A'));
    same(false, $catalog->adjustStock($id, 0, -1, 'order B'));
    same(0, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$id]));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {stock_moves}'));
});

test('adjustStock: con pedidos sin existencias deja pasar aunque quede en negativo; «avisar» no', function (): void {
    $app = catalogApp();
    $yes = aifecProduct($app, ['stock' => 1, 'backorders' => 'yes']);
    $notify = aifecProduct($app, ['stock' => 1, 'backorders' => 'notify']);
    $catalog = aifecCatalog($app);
    same(true, $catalog->adjustStock($yes, 0, -3, 'order'));
    same(-2, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$yes]));
    same(false, $catalog->adjustStock($notify, 0, -3, 'order'));
});

test('adjustStock: una entrada de mercancía siempre se admite', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['stock' => 0]);
    $catalog = aifecCatalog($app);
    same(true, $catalog->adjustStock($id, 0, 10, 'restock'));
    same(10, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$id]));
    same(true, $catalog->adjustStock($id, 0, 0, 'nada'));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {stock_moves}'), 'un cambio de cero no se apunta');
});

test('adjustStock: sin control de existencias siempre vale y no toca nada', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['manage_stock' => 0, 'stock' => 0]);
    same(true, aifecCatalog($app)->adjustStock($id, 0, -5, 'order'));
    same(0, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$id]));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {stock_moves}'));
});

test('adjustStock con variantes: ajusta la variante, con su motivo y su referencia', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['variants' => [['sku' => 'A', 'stock' => 2], ['sku' => 'B', 'stock' => 9]]]);
    $a = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['A']);
    $b = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['B']);
    $catalog = aifecCatalog($app);
    same(true, $catalog->adjustStock($id, $a, -2, 'order #7', 'PED-7'));
    same(false, $catalog->adjustStock($id, $a, -1, 'order #8'));
    same(0, (int) $app->db()->value('SELECT stock FROM {variants} WHERE id = ?', [$a]));
    same(9, (int) $app->db()->value('SELECT stock FROM {variants} WHERE id = ?', [$b]), 'la otra variante no cambia');
    $move = $app->db()->row('SELECT * FROM {stock_moves} WHERE variant_id = ?', [$a]);
    same(-2, (int) $move['delta']);
    same(0, (int) $move['stock_after']);
    same('PED-7', $move['reference']);
    same(true, $catalog->adjustStock($id, $b, -5, 'order'));
    same(4, (int) $app->db()->value('SELECT stock FROM {variants} WHERE id = ?', [$b]));
});

test('adjustStock con variantes y pedidos sin existencias: la variante puede quedar en negativo', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['backorders' => 'yes', 'variants' => [['sku' => 'A', 'stock' => 1]]]);
    $a = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['A']);
    same(true, aifecCatalog($app)->adjustStock($id, $a, -4, 'order'));
    same(-3, (int) $app->db()->value('SELECT stock FROM {variants} WHERE id = ?', [$a]));
});

test('adjustStock: producto inexistente, variante que no es suya o variante pedida donde no toca: false', function (): void {
    $app = catalogApp();
    $simple = aifecProduct($app, ['stock' => 5]);
    $withVariants = aifecProduct($app, ['variants' => [['sku' => 'A', 'stock' => 5]]]);
    $other = aifecProduct($app, ['variants' => [['sku' => 'B', 'stock' => 5]]]);
    $a = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['A']);
    $catalog = aifecCatalog($app);
    same(false, $catalog->adjustStock(424242, 0, -1, 'x'));
    same(false, $catalog->adjustStock($simple, $a, -1, 'x'), 'un producto sin variantes no admite una');
    same(false, $catalog->adjustStock($withVariants, 0, -1, 'x'), 'un producto con variantes pide la variante');
    same(false, $catalog->adjustStock($other, $a, -1, 'x'), 'la variante es de otro producto');
    same(5, (int) $app->db()->value('SELECT stock FROM {variants} WHERE id = ?', [$a]));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {stock_moves}'));
});

test('adjustStock invalida la ficha: la cantidad que se enseña es la nueva sin vaciar nada a mano', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['stock' => 20]);
    $catalog = aifecCatalog($app);
    same(20, $catalog->product($id)['availability']['qty']);
    same('in_stock', $catalog->card($id)['availability']['status']);
    $catalog->adjustStock($id, 0, -17, 'order');
    same(3, $catalog->product($id)['availability']['qty']);
    same('low_stock', $catalog->card($id)['availability']['status']);
});

test('adjustStock lanza stock.changed con el producto, la variante y lo que queda; si falla, no', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['stock' => 5]);
    $withVariant = aifecProduct($app, ['variants' => [['sku' => 'A', 'stock' => 7]]]);
    $a = (int) $app->db()->value('SELECT id FROM {variants} WHERE sku = ?', ['A']);
    $events = [];
    $app->hooks()->on('stock.changed', static function (int $productId, int $variantId, int $after) use (&$events): void {
        $events[] = [$productId, $variantId, $after];
    });
    $catalog = aifecCatalog($app);
    $catalog->adjustStock($id, 0, -2, 'order');
    $catalog->adjustStock($withVariant, $a, 3, 'restock');
    $catalog->adjustStock($id, 0, -50, 'order');
    same([[$id, 0, 3], [$withVariant, $a, 10]], $events);
});

test('adjustStock dentro de una transacción que se deshace: el stock vuelve a lo de antes', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['stock' => 5]);
    $catalog = aifecCatalog($app);
    try {
        $app->db()->transaction(static function () use ($catalog, $id): void {
            $catalog->adjustStock($id, 0, -2, 'order');
            throw new \RuntimeException('el pago falló');
        });
    } catch (\RuntimeException $e) {
        // Se esperaba: el pedido entero se deshace.
    }
    same(5, (int) $app->db()->value('SELECT stock FROM {products} WHERE id = ?', [$id]));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {stock_moves}'));
});
