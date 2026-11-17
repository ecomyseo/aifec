<?php
/**
 * AIFEC — las existencias de un producto variable de WooCommerce: el control puede estar en las variaciones, en el padre, en los dos o en
 * ninguno, y aquí el control es del producto y la cifra, de cada variante. La suma del padre es la de sus variantes; con existencias
 * compartidas, cada variante recibe la cifra entera y queda apuntado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Readers\WooCommerce\Mapper as WooStockMapper;
use Aifec\Modules\Import\Writers\ProductsWriter;

require_once __DIR__ . '/Readers/WooCommerce/fixtures/WooTestKit.php';
require_once __DIR__ . '/Writers/WritersKit.php';

/**
 * La silla variable de ejemplo (tres variaciones con 9, 5 y 2 unidades) con el control de existencias que se diga.
 *
 * @param bool|string     $parent el control del padre (true, false)
 * @param array<int,bool|string|null> $own el control de cada variación, en orden (true, false o 'parent')
 *
 * @return array<string,mixed> el producto neutro
 */
function vsProduct($parent, array $own, ?int $parentQty = null): array
{
    $mapper = new WooStockMapper(['locale' => 'es_ES', 'timezone' => 'Europe/Madrid', 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'prices_include_tax' => false, 'site_url' => 'https://tienda.test']);
    $routes = WooTestKit::routes();
    $terms = [1 => $routes['/wp-json/wc/v3/products/attributes/1/terms'], 2 => $routes['/wp-json/wc/v3/products/attributes/2/terms']];
    $p = $routes['/wp-json/wc/v3/products'][1];
    $p['manage_stock'] = $parent;
    $p['stock_quantity'] = $parent === true ? $parentQty : null;
    $variations = $routes['/wp-json/wc/v3/products/102/variations'];
    foreach ($variations as $i => $variation) {
        $variations[$i]['manage_stock'] = $own[$i];
        if ($own[$i] !== true) {
            $variations[$i]['stock_quantity'] = null;
        }
    }

    return $mapper->product($p, $variations, $terms);
}

/** @return int[] */
function vsStocks(array $product): array
{
    return array_map(static function (array $variant): int {
        return (int) $variant['stock'];
    }, $product['variants']);
}

test('existencias: control solo en las variaciones: el producto controla y su cifra es la suma de las variantes (9 + 5 + 2)', function (): void {
    $p = vsProduct(false, [true, true, true]);
    same([], Neutral::check('products', $p));
    same(true, $p['manage_stock']);
    same(16, $p['stock'], 'la suma del padre es la de sus variantes');
    same([9, 5, 2], vsStocks($p), 'cada variante con la suya');
    same(false, isset($p['extra']['shared_stock']), 'no hay existencias compartidas');
    same(false, isset($p['extra']['variants_without_stock_control']));
});

test('existencias: control solo en las variaciones y una variación sin control propio: queda a 0 y se apunta; el padre suma las demás', function (): void {
    $p = vsProduct(false, [true, false, true]);
    same(true, $p['manage_stock']);
    same([9, 0, 2], vsStocks($p));
    same(11, $p['stock']);
    same(1, $p['extra']['variants_without_stock_control']);
});

test('existencias: una variación que «sigue al padre» cuenta como sin control propio', function (): void {
    $p = vsProduct(false, [true, 'parent', 'parent']);
    same([9, 0, 0], vsStocks($p));
    same(9, $p['stock']);
    same(2, $p['extra']['variants_without_stock_control']);
});

test('existencias: control solo en el padre: existencias compartidas; cada variación recibe la cifra entera y queda apuntado', function (): void {
    $p = vsProduct(true, [false, 'parent', 'parent'], 20);
    same([], Neutral::check('products', $p));
    same(true, $p['manage_stock']);
    same(20, $p['stock'], 'el padre conserva la suya');
    same([20, 20, 20], vsStocks($p), 'cada variación puede venderse hasta esa cantidad');
    same(['quantity' => 20, 'variants' => 3], $p['extra']['shared_stock']);
});

test('existencias: control en el padre y en una variación: la que tiene el suyo lo conserva y las demás comparten el del padre', function (): void {
    $p = vsProduct(true, [true, false, 'parent'], 20);
    same(true, $p['manage_stock']);
    same([9, 20, 20], vsStocks($p));
    same(['quantity' => 20, 'variants' => 2], $p['extra']['shared_stock']);
});

test('existencias: control en el padre y en todas las variaciones: cada una con la suya y nada compartido', function (): void {
    $p = vsProduct(true, [true, true, true], 20);
    same(true, $p['manage_stock']);
    same([9, 5, 2], vsStocks($p));
    same(false, isset($p['extra']['shared_stock']));
});

test('existencias: sin control en ninguno: sin control aquí tampoco y las cifras a 0', function (): void {
    $p = vsProduct(false, [false, false, false]);
    same(false, $p['manage_stock']);
    same(0, $p['stock']);
    same([0, 0, 0], vsStocks($p));
    same(false, isset($p['extra']['shared_stock']) || isset($p['extra']['variants_without_stock_control']));
});

test('existencias: un producto sin variaciones no cambia (el control y la cifra son los suyos)', function (): void {
    $mapper = new WooStockMapper(['locale' => 'es_ES', 'timezone' => 'Europe/Madrid', 'weight_unit' => 'kg', 'dimension_unit' => 'cm', 'prices_include_tax' => false, 'site_url' => 'https://tienda.test']);
    $p = $mapper->product(WooTestKit::routes()['/wp-json/wc/v3/products'][0]);
    same(true, $p['manage_stock']);
    same(14, $p['stock']);
});

/** Un sitio con el catálogo y una silla con dos variantes de existencias compartidas (20) o propias. */
function vsWrite(array $record): array
{
    $app = writersApp();
    $ctx = writersContext($app);
    wrCatalogOrigin($app, $ctx);
    $ctx->notes = [];
    $writer = new ProductsWriter($app);
    $out = $writer->write($record, $ctx);
    check(in_array($out['result'], ['created', 'updated'], true), 'se escribe: ' . $out['result'] . ' ' . (isset($out['reason']) ? $out['reason'] : ''));

    return [$app, $ctx, $writer, (int) $out['id']];
}

/** @return array<string,mixed> */
function vsRecord(bool $shared): array
{
    $variant = static function (string $id, string $value, int $stock): array {
        return ['id' => $id, 'sku' => 'VS-' . $id, 'price' => 5000, 'compare_price' => null, 'cost' => null, 'stock' => $stock, 'weight' => 0, 'image_index' => null,
            'active' => true, 'position' => 0, 'values' => [['attribute_id' => 'a1', 'value_id' => $value]]];
    };

    return wr('products', [
        'id' => 'vs1', 'sku' => 'VS1', 'name' => ['es' => 'Silla variable'], 'slug' => ['es' => 'silla-variable'], 'summary' => [], 'description' => [], 'old_paths' => [],
        'price_includes_tax' => false, 'manage_stock' => true, 'stock' => $shared ? 20 : 12, 'compare_price' => null, 'sale_price' => null, 'sale_from' => null, 'sale_to' => null,
        'cost' => null, 'price_micros' => null, 'compare_price_micros' => null, 'sale_price_micros' => null, 'images' => [], 'related_ids' => [], 'attributes' => [], 'tags' => [],
        'meta_title' => [], 'meta_description' => [],
        'variants' => $shared ? [$variant('1', '21', 20), $variant('2', '22', 20)] : [$variant('1', '21', 7), $variant('2', '22', 5)],
        'extra' => $shared ? ['shared_stock' => ['quantity' => 20, 'variants' => 2]] : [],
    ]);
}

test('existencias: el escritor deja el producto con control, las cifras por variante y apunta lo compartido una sola vez', function (): void {
    [$app, $ctx, , $id] = vsWrite(vsRecord(true));
    $row = $app->db()->row('SELECT manage_stock, stock, has_variants FROM {products} WHERE id = ?', [$id]);
    same([1, 20, 1], [(int) $row['manage_stock'], (int) $row['stock'], (int) $row['has_variants']]);
    same([20, 20], array_map('intval', array_column($app->db()->select('SELECT stock FROM {variants} WHERE product_id = ? ORDER BY id', [$id]), 'stock')));
    $notes = array_filter($ctx->noteTexts('products'), static function (string $text): bool {
        return strpos($text, 'shared by all the variations') !== false;
    });
    same(1, count($notes), 'una nota');
});

test('existencias: la cifra de existencias de aquí cuenta lo compartido UNA vez, como el origen (20 y no 40)', function (): void {
    [, $ctx, $writer] = vsWrite(vsRecord(true));
    same(20, $writer->checksums($ctx)['stock_total']);
});

test('existencias: con cifras propias por variante la cifra es la suma (7 + 5) y no se apunta nada de compartir', function (): void {
    [, $ctx, $writer] = vsWrite(vsRecord(false));
    same(12, $writer->checksums($ctx)['stock_total']);
    same([], array_filter($ctx->noteTexts('products'), static function (string $text): bool {
        return strpos($text, 'shared') !== false;
    }));
});
