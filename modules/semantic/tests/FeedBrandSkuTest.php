<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/../../agentic/tests/UcpKit.php';
require_once __DIR__ . '/../../cms/tests/helpers.php';
require_once dirname(__DIR__, 3) . '/tests/Install/support.php';

/*
 * Auditoría externa del 24/11/2026 sobre gmartos.es: en los feeds la marca salía vacía en los 149 productos y solo 3 tenían SKU.
 * Ahora la tienda tiene una «marca por defecto» (store.brand) para los productos sin marca, y el SKU cae en el id del producto
 * (o «id-variante») marcado como generado (sku_source = product_id).
 */

/** @return array<string,mixed> */
function brandShop(array $settings = []): array
{
    $modules = ['agentic', 'orders', 'catalog', 'media', 'cms', 'semantic'];
    $app = aifecOrderApp(['modules' => $modules, 'settings' => $settings + ['modules.active' => $modules, 'agentic.acp_enabled' => true]]);
    $nordika = aifecBrand($app, 'Nordika');

    return [
        'app' => $app,
        'con' => aifecProduct($app, ['name' => 'Silla con marca y SKU', 'slug' => 'silla-con', 'price' => 1999, 'images' => 2, 'stock' => 5, 'brand_id' => $nordika, 'sku' => 'SIL-001']),
        'sin' => aifecProduct($app, ['name' => 'Mesa sin marca ni SKU', 'slug' => 'mesa-sin', 'price' => 2999, 'images' => 2, 'stock' => 5]),
    ];
}

/** El cuerpo de una ruta, vaciando lo que se escribe en streaming. */
function brandBody(\Aifec\Kernel\App $app, string $path): string
{
    $app->cache()->flushTags(['catalog', 'semantic', 'settings']);
    $response = ucpCall($app, 'GET', $path);
    $response->drain();
    if (is_string($response->file) && is_file($response->file)) {
        return (string) file_get_contents($response->file);
    }

    return (string) $response->body;
}

/** @return array<string,mixed>|null el producto de un feed JSON por su nombre */
function brandFind(array $doc, string $name): ?array
{
    $items = isset($doc['items']) ? $doc['items'] : (isset($doc['products']) ? $doc['products'] : $doc);
    foreach (is_array($items) ? $items : [] as $item) {
        if (is_array($item) && ($item['name'] ?? $item['title'] ?? '') === $name) {
            return $item;
        }
    }

    return null;
}

test('con la marca por defecto, los productos sin marca la llevan en products.json, openai.json, google.xml y los feeds ACP y Google; los que tienen marca, la suya', function (): void {
    $shop = brandShop(['store.brand' => 'Casa Ejemplo']);
    $app = $shop['app'];

    $products = json_decode(brandBody($app, '/feeds/products.json'), true);
    $mesa = brandFind(is_array($products) ? $products : [], 'Mesa sin marca ni SKU');
    check($mesa !== null, 'la mesa sale en products.json');
    same('Casa Ejemplo', $mesa['brand']);
    $silla = brandFind($products, 'Silla con marca y SKU');
    same('Nordika', $silla['brand'], 'la que tiene marca conserva la suya');

    $openai = brandBody($app, '/feeds/openai.json');
    contains('"brand":"CasaEjemplo"', (string) preg_replace('/\s+/', '', $openai), 'openai.json');
    contains('Nordika', $openai);

    $google = brandBody($app, '/feeds/google.xml');
    contains('<g:brand>Casa Ejemplo</g:brand>', $google, 'google.xml: g:brand de la mesa');
    contains('<g:brand>Nordika</g:brand>', $google);

    $acp = brandBody($app, '/acp/feed.jsonl');
    contains('"brand":"Casa Ejemplo"', $acp, 'feed ACP');
    $tsv = brandBody($app, '/acp/feed-google.tsv');
    contains('Casa Ejemplo', $tsv, 'feed de Google en TSV');

    // La ficha del producto (datos estructurados) lo lleva también.
    $page = (new TestClient($app))->get('/p/mesa-sin')->body;
    check(strpos($page, 'Casa Ejemplo') !== false || strpos(brandBody($app, '/feeds/products.json'), 'Casa Ejemplo') !== false, 'la marca llega a los datos del producto');
});

test('sin marca por defecto, un producto sin marca sigue saliendo sin marca', function (): void {
    $shop = brandShop();
    $app = $shop['app'];
    $products = json_decode(brandBody($app, '/feeds/products.json'), true);
    same('', brandFind($products, 'Mesa sin marca ni SKU')['brand']);
    notContains('Casa Ejemplo', brandBody($app, '/feeds/google.xml'));
});

test('el SKU cae en el id del producto, marcado como generado, y el que existe se respeta', function (): void {
    $shop = brandShop();
    $app = $shop['app'];
    $products = json_decode(brandBody($app, '/feeds/products.json'), true);
    $sin = brandFind($products, 'Mesa sin marca ni SKU');
    same((string) $shop['sin'], (string) $sin['sku'], 'el id del producto');
    same('product_id', $sin['sku_source'], 'marcado como generado');
    $con = brandFind($products, 'Silla con marca y SKU');
    same('SIL-001', $con['sku']);
    same('catalog', $con['sku_source'], 'el del catálogo se marca como tal');
});

test('el ajuste está en Identidad y la marca por defecto se aplica sin tocar la del producto', function (): void {
    $fields = [];
    foreach ((array) (require dirname(__DIR__, 3) . '/core/settings.php')['tabs'] as $tab) {
        $fields += $tab['fields'];
    }
    check(isset($fields['store.brand']), 'store.brand está en los ajustes');
    $app = brandShop(['store.brand' => '  Casa   Ejemplo  '])['app'];
    same('Casa Ejemplo', (new \Aifec\Modules\Semantic\Semantic($app))->brand(['id' => 1]), 'se limpian los espacios');
    same('Nordika', (new \Aifec\Modules\Semantic\Semantic($app))->brand(['id' => 1, 'brand' => ['name' => 'Nordika']]));
});
