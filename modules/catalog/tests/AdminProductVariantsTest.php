<?php
/**
 * AIFEC — pruebas de la pantalla de variantes: generar combinaciones, editar la tabla, desactivar en vez de borrar, tope y permisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/AdminProductKitTest.php';

/**
 * Un producto sin variantes y dos atributos que las crean: color (rojo, verde) y talla (s, m, l).
 *
 * @return array{0:int,1:int,2:int} id del producto, del atributo color y del atributo talla
 */
function prodVariantSetup(App $app, int $price = 2000): array
{
    $color = aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo & <b>', 'verde' => 'Verde'], ['variant' => 1]);
    $size = aifecAttribute($app, 'talla', 'Talla', ['s', 'm', 'l'], ['variant' => 1]);

    return [aifecProduct($app, ['name' => 'Camiseta', 'price' => $price, 'stock' => 3]), $color, $size];
}

/**
 * Pide generar las combinaciones de todos los valores de dos atributos (o de los que se digan).
 *
 * @param array<int,string[]>|null $chosen id del atributo => códigos de valores
 */
function prodGenerate(TestClient $web, int $productId, ?array $chosen = null): \Aifec\Kernel\Response
{
    $app = $web->app;
    if ($chosen === null) {
        $chosen = [];
        foreach ($app->db()->select('SELECT id FROM {attributes} WHERE variant = 1 ORDER BY id') as $attribute) {
            $chosen[(int) $attribute['id']] = array_map('strval', $app->db()->column('SELECT code FROM {attribute_values} WHERE attribute_id = ? ORDER BY id', [(int) $attribute['id']]));
        }
    }
    $gen = [];
    foreach ($chosen as $attributeId => $codes) {
        foreach ($codes as $code) {
            $gen[$attributeId][] = (string) prodValue($app, (int) $attributeId, $code);
        }
    }
    $web->get('/admin/catalog/products/' . $productId . '/variants');

    return prodPost($web, '/admin/catalog/products/' . $productId . '/variants', ['op' => 'generate', 'gen' => $gen]);
}

/**
 * Envía la tabla de variantes con los campos de cada una (lo que no se diga queda como estaba en la base).
 *
 * @param array<int,array<string,string>> $rows id de la variante => campos
 */
function prodSaveVariants(TestClient $web, int $productId, array $rows): \Aifec\Kernel\Response
{
    $web->get('/admin/catalog/products/' . $productId . '/variants');
    $post = [];
    foreach ($rows as $id => $fields) {
        $current = $web->app->db()->row('SELECT * FROM {variants} WHERE id = ?', [$id]);
        $post[$id] = $fields + [
            'sku' => (string) $current['sku'], 'gtin' => (string) $current['gtin'], 'price' => $current['price'] !== null ? number_format(((int) $current['price']) / 100, 2, ',', '') : '',
            'compare_price' => '', 'stock' => (string) $current['stock'], 'stock0' => (string) $current['stock'], 'weight' => '', 'media_id' => '', 'active' => '1',
        ];
    }

    return prodPost($web, '/admin/catalog/products/' . $productId . '/variants', ['op' => 'save', 'v' => $post]);
}

test('generar 2 × 3 crea 6 variantes con sus valores y volver a generar no duplica', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    list($id, $color, $size) = prodVariantSetup($app);

    $screen = $web->get('/admin/catalog/products/' . $id . '/variants');
    same(200, $screen->status);
    contains('Generar combinaciones', $screen->body);
    contains(e('Rojo & <b>'), $screen->body, 'los valores salen escapados');
    notContains('Rojo & <b>', $screen->body);
    contains('Este producto todavía no tiene variantes', $screen->body);
    same(0, prodScalar($app, 'SELECT has_variants FROM {products} WHERE id = ?', [$id]));

    $r = prodGenerate($web, $id);
    same(302, $r->status);
    same(6, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$id]));
    same(12, prodScalar($app, 'SELECT COUNT(*) FROM {variant_values}'), 'cada variante tiene un color y una talla');
    same(1, prodScalar($app, 'SELECT has_variants FROM {products} WHERE id = ?', [$id]));
    contains('6 variantes creadas', $web->follow()->body);
    $screen = $web->get('/admin/catalog/products/' . $id . '/variants')->body;
    contains(e('Rojo & <b> / S'), $screen, 'la etiqueta se calcula con los valores');
    contains('Verde / L', $screen);

    // Volver a generar lo mismo no duplica nada.
    prodGenerate($web, $id);
    same(6, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$id]));
    contains('Todas las combinaciones ya existen', $web->follow()->body);

    // Un valor nuevo solo crea las combinaciones que faltan.
    aifecAttribute($app, 'color', 'Color', ['azul']);
    $app->invalidate(['catalog']);
    prodGenerate($web, $id);
    same(9, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$id]), 'azul × 3 tallas');
    same(3, prodScalar($app, 'SELECT COUNT(*) FROM {variant_values} WHERE value_id = ?', [prodValue($app, $color, 'azul')]));

    // Sin elegir nada, no hace nada y lo dice.
    $web->get('/admin/catalog/products/' . $id . '/variants');
    same(302, prodPost($web, '/admin/catalog/products/' . $id . '/variants', ['op' => 'generate'])->status);
    contains('Elige al menos un valor', $web->follow()->body);
    same(9, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$id]));
});

test('editar precios y existencias en la tabla: catalog->product() las enseña con «desde» si los precios difieren', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    list($id) = prodVariantSetup($app, 2000);
    prodGenerate($web, $id, [aifecAttribute($app, 'talla') => ['s', 'm']]);
    $variants = array_map('intval', $app->db()->column('SELECT id FROM {variants} WHERE product_id = ? ORDER BY position', [$id]));
    same(2, count($variants));
    $catalog = aifecCatalog($app);
    $before = $catalog->product($id);
    same(false, $before['price']['from'], 'sin precios propios, todas valen lo mismo');

    $r = prodSaveVariants($web, $id, [
        $variants[0] => ['price' => '15,00', 'compare_price' => '25,00', 'stock' => '8', 'sku' => 'CAM-S', 'gtin' => '8400000000017', 'weight' => '250'],
        $variants[1] => ['stock' => '3'],
    ]);
    same(302, $r->status);
    contains('Variantes guardadas', $web->follow()->body);
    $first = $app->db()->row('SELECT * FROM {variants} WHERE id = ?', [$variants[0]]);
    same('1500', (string) $first['price']);
    same('2500', (string) $first['compare_price']);
    same('CAM-S', $first['sku']);
    same('250', (string) $first['weight']);
    same('8', (string) $first['stock']);
    same(null, $app->db()->value('SELECT price FROM {variants} WHERE id = ?', [$variants[1]]), 'un precio vacío es el del producto');

    $moves = $app->db()->select('SELECT variant_id, delta, stock_after, reason FROM {stock_moves} WHERE product_id = ? ORDER BY id', [$id]);
    same(2, count($moves), 'las existencias se apuntan como movimientos');
    same((string) $variants[0], (string) $moves[0]['variant_id']);
    same('8', (string) $moves[0]['delta']);
    same('manual', $moves[0]['reason']);

    $product = $catalog->product($id);
    same(true, $product['price']['from'], 'los precios difieren: «desde»');
    same(1500, $product['price']['amount'], 'el más bajo');
    same(2, count($product['variants']));
    $byId = [];
    foreach ($product['variants'] as $variant) {
        $byId[$variant['id']] = $variant;
    }
    same(1500, $byId[$variants[0]]['price']['amount']);
    same(2000, $byId[$variants[1]]['price']['amount'], 'la otra, con el precio del producto');
    same('CAM-S', $byId[$variants[0]]['sku']);
    same(1500, $catalog->purchasable($id, $variants[0])['unit_price'], 'lo que ve el carrito');
    same(11, array_sum(array_map(static function (array $v): int {
        return (int) $v['availability']['qty'];
    }, $product['variants'])), 'las existencias de cada variante');

    // Desactivar una la saca del producto y, sin variantes activas, el producto vuelve a ser simple.
    prodSaveVariants($web, $id, [$variants[0] => ['active' => '0']]);
    same(1, count($catalog->product($id)['variants']));
    prodSaveVariants($web, $id, [$variants[1] => ['active' => '0']]);
    same(0, prodScalar($app, 'SELECT has_variants FROM {products} WHERE id = ?', [$id]), 'sin variantes activas el producto no cuenta con ellas');
});

test('una variante que está en pedidos se desactiva en vez de borrarse; las demás se borran con sus valores', function (): void {
    $app = prodApp();
    prodOrderItems($app);
    $web = adminClient($app);
    list($id) = prodVariantSetup($app);
    prodGenerate($web, $id, [aifecAttribute($app, 'talla') => ['s', 'm', 'l']]);
    $v = array_map('intval', $app->db()->column('SELECT id FROM {variants} WHERE product_id = ? ORDER BY position', [$id]));
    $app->db()->insert('order_items', ['order_id' => 1, 'product_id' => $id, 'variant_id' => $v[0]]);

    $web->get('/admin/catalog/products/' . $id . '/variants');
    contains('En pedidos', $web->get('/admin/catalog/products/' . $id . '/variants')->body, 'la pantalla marca la que está en pedidos');
    $r = prodPost($web, '/admin/catalog/products/' . $id . '/variants', ['op' => 'save', 'v' => [$v[0] => ['delete' => '1'], $v[1] => ['delete' => '1']]]);
    same(302, $r->status);
    $row = $app->db()->row('SELECT active FROM {variants} WHERE id = ?', [$v[0]]);
    check($row !== null, 'la de los pedidos sigue existiendo');
    same('0', (string) $row['active'], 'pero apagada');
    same(null, $app->db()->row('SELECT id FROM {variants} WHERE id = ?', [$v[1]]), 'la otra se borró');
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {variant_values} WHERE variant_id = ?', [$v[1]]), 'con sus valores');
    contains('se ha apagado en vez de borrarla', $web->follow()->body);
    same(1, prodScalar($app, 'SELECT has_variants FROM {products} WHERE id = ?', [$id]), 'queda una activa');
    $catalog = aifecCatalog($app);
    same(1, count($catalog->product($id)['variants']), 'la tienda solo enseña la activa');

    // Borrar la que queda activa y apagar la de los pedidos: el producto vuelve a ser simple.
    prodPost($web, '/admin/catalog/products/' . $id . '/variants', ['op' => 'save', 'v' => [$v[2] => ['delete' => '1']]]);
    same(0, prodScalar($app, 'SELECT has_variants FROM {products} WHERE id = ?', [$id]));
});

test('un producto no pasa de 200 variantes y se avisa', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $a = aifecAttribute($app, 'a', 'A', array_map(static function (int $i): string {
        return 'a' . $i;
    }, range(1, 15)), ['variant' => 1]);
    $b = aifecAttribute($app, 'b', 'B', array_map(static function (int $i): string {
        return 'b' . $i;
    }, range(1, 14)), ['variant' => 1]);
    $id = aifecProduct($app, ['name' => 'Muchas']);
    $r = prodGenerate($web, $id);
    same(302, $r->status);
    same(200, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$id]), '15 × 14 = 210, pero el tope es 200');
    $body = $web->follow()->body;
    contains('200 variantes como máximo', $body);
    contains('200 variantes creadas', $body);
    check($a > 0 && $b > 0);
});

test('la tabla de variantes no guarda nada si un importe, unas existencias o una referencia no valen', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    list($id) = prodVariantSetup($app);
    $app->db()->update('products', ['sku' => 'PROD-1'], ['id' => $id]);
    prodGenerate($web, $id, [aifecAttribute($app, 'talla') => ['s', 'm']]);
    $v = array_map('intval', $app->db()->column('SELECT id FROM {variants} WHERE product_id = ? ORDER BY position', [$id]));

    $r = prodSaveVariants($web, $id, [$v[0] => ['price' => 'abc', 'stock' => '5'], $v[1] => ['stock' => '4']]);
    same(422, $r->status);
    contains($app->i18n()->t('Enter an amount, for example 12,50'), $r->body);
    contains('No se ha guardado nada', $r->body);
    same(0, prodScalar($app, 'SELECT SUM(stock) FROM {variants} WHERE product_id = ?', [$id]), 'ni siquiera lo que estaba bien');
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {stock_moves}'));
    contains('value="abc"', $r->body, 'lo escrito se conserva en la pantalla');

    same(422, prodSaveVariants($web, $id, [$v[0] => ['stock' => '-1']])->status, 'las existencias no pueden ser negativas');
    same(422, prodSaveVariants($web, $id, [$v[0] => ['stock' => '1,5']])->status);
    same(422, prodSaveVariants($web, $id, [$v[0] => ['sku' => 'IGUAL'], $v[1] => ['sku' => 'IGUAL']])->status, 'dos variantes no comparten referencia');
    same(422, prodSaveVariants($web, $id, [$v[0] => ['sku' => 'PROD-1']])->status, 'ni la del producto');
    same(302, prodSaveVariants($web, $id, [$v[0] => ['sku' => 'UNICA']])->status);
    same(422, prodSaveVariants($web, $id, [$v[1] => ['sku' => 'UNICA']])->status, 'ni la de otra variante ya guardada');
    same(302, prodSaveVariants($web, $id, [$v[0] => ['sku' => 'UNICA', 'price' => '9,99']])->status, 'la suya propia no choca consigo misma');
});

test('las variantes se ven con permiso de ver y solo se cambian con permiso de cambiar', function (): void {
    $app = prodApp();
    $admin = adminClient($app);
    list($id) = prodVariantSetup($app);
    prodGenerate($admin, $id, [aifecAttribute($app, 'talla') => ['s', 'm']]);
    $variant = prodScalar($app, 'SELECT MIN(id) FROM {variants} WHERE product_id = ?', [$id]);

    $support = adminClient($app, 'support');
    $token = aifecToken($support);
    same(403, $support->get('/admin/catalog/products/' . $id . '/variants')->status);
    same(403, $support->post('/admin/catalog/products/' . $id . '/variants', ['_token' => $token, 'op' => 'save', 'v' => [$variant => ['delete' => '1']]])->status);

    $viewer = prodViewer($app);
    $token = aifecToken($viewer);
    $r = $viewer->get('/admin/catalog/products/' . $id . '/variants');
    same(200, $r->status);
    contains('Camiseta', $r->body);
    contains('<strong>S</strong>', $r->body, 'se ven');
    notContains('name="v[', $r->body, 'sin campos para cambiar');
    notContains('Generar combinaciones', $r->body);
    same(403, $viewer->post('/admin/catalog/products/' . $id . '/variants', ['_token' => $token, 'op' => 'save', 'v' => [$variant => ['delete' => '1']]])->status);
    same(403, $viewer->post('/admin/catalog/products/' . $id . '/variants', ['_token' => $token, 'op' => 'generate', 'gen' => []])->status);
    same(2, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$id]), 'no se tocó nada');
    same(404, $admin->get('/admin/catalog/products/99999/variants')->status);
});
