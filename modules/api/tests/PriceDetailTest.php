<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * El precio dicho de las dos maneras (base sin impuestos y total con ellos, con divisa, tipo y país) en la ficha, en la versión en texto,
 * en la API, en MCP y en los feeds. Los importes son los de PricingTest: 1999 con IVA del 21 % = base 1652 + cuota 347.
 */

declare(strict_types=1);

use Aifec\Modules\Catalog\PriceDetail;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../semantic/tests/helpers.php';

/** @param array<string,mixed> $settings */
function pdApp(array $settings = []): \Aifec\Kernel\App
{
    return apiApp($settings, ['semantic', 'catalog', 'media']);
}

test('PriceDetail: base, cuota y total con la divisa, el tipo en % y el país del tipo', function (): void {
    $app = pdApp();
    $id = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 1999]);
    $price = aifecCatalog($app)->product($id)['price'];
    $d = PriceDetail::of($app, $price);
    same(['currency' => 'EUR', 'net' => 1652, 'tax' => 347, 'gross' => 1999, 'tax_rate' => 21, 'tax_name' => 'IVA', 'shown' => 'gross', 'country' => 'ES'], $d);
    $line = PriceDetail::line($app, $d);
    contains('Base 16,52', $line);
    contains('IVA 21 % 3,47', $line);
    contains('Total con IVA 19,99', $line);
    contains('(ES)', $line);
    same(null, PriceDetail::of($app, ['hidden' => true, 'net' => 1, 'tax' => 1, 'gross' => 2]), 'un precio que no se enseña no sale');
    same(null, PriceDetail::of($app, ['amount' => 1999]), 'sin los importes, nada');
    $reduced = PriceDetail::of($app, ['net' => 1000, 'tax' => 100, 'gross' => 1100, 'tax_rate' => 1000, 'tax_name' => 'IVA 10 %', 'with_tax' => true, 'currency' => 'EUR']);
    same(10, $reduced['tax_rate']);
    $half = PriceDetail::of($app, ['net' => 1000, 'tax' => 55, 'gross' => 1055, 'tax_rate' => 550, 'tax_name' => 'IVA 5,5 %', 'with_tax' => false, 'currency' => 'EUR']);
    same(5.5, $half['tax_rate']);
    same('net', $half['shown'], 'si la tienda enseña precios sin impuestos, lo dice');
    $none = PriceDetail::of($app, ['net' => 1000, 'tax' => 0, 'gross' => 1000, 'tax_rate' => 0, 'tax_name' => '', 'with_tax' => true, 'currency' => 'EUR']);
    contains('sin Impuesto', PriceDetail::line($app, $none), 'sin impuesto, lo dice');
});

test('el país de referencia sale del ajuste de impuestos de la tienda', function (): void {
    $app = pdApp(['tax.base_country' => 'pt']);
    $id = aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'price' => 1999]);
    same('PT', PriceDetail::of($app, aifecCatalog($app)->product($id)['price'])['country']);
});

test('la ficha, la versión en texto, la API, MCP y los feeds llevan la base y el total', function (): void {
    $app = pdApp();
    $id = aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo', 'price' => 1999, 'images' => 1]);
    $web = new TestClient($app);

    // La ficha, en el hueco que ponen todos los temas.
    $page = $web->get('/producto/silla-ergo')->body;
    contains('data-af-price-detail', $page);
    contains('Base 16,52', $page);
    contains('Total con IVA 19,99', $page);

    // La versión en texto.
    $md = $web->get('/producto/silla-ergo.md')->body;
    contains("\nBase 16,52", $md);
    contains('Total con IVA 19,99', $md);

    // La API: la ficha y la tarjeta.
    $product = apiJson(apiCall($app, 'GET', '/api/v1/products/' . $id))['data'];
    same(1652, $product['pricing']['detail']['net']);
    same(1999, $product['pricing']['detail']['gross']);
    same(21, $product['pricing']['detail']['tax_rate']);
    same('ES', $product['pricing']['detail']['country']);
    same('EUR', $product['pricing']['detail']['currency']);
    $list = apiJson(apiCall($app, 'GET', '/api/v1/products'))['data'];
    same(1652, $list[0]['price_detail']['net']);
    same(347, $list[0]['price_detail']['tax']);

    // MCP y asistente: la ficha de get_product (y las tarjetas de search_products, que salen del mismo sitio).
    $tool = \Aifec\Modules\Api\Agent::tool($app, 'get_product', ['id' => $id]);
    $json = (string) json_encode($tool);
    contains('"price_detail":{"currency":"EUR","net":1652,"tax":347,"gross":1999,"tax_rate":21,"tax_name":"IVA","shown":"gross","country":"ES"}', $json, 'la herramienta get_product lleva price_detail');

    // El feed público.
    semanticBuildFeeds($app);
    $feed = json_decode(semanticFeed($app, 'products.json'), true);
    same(1652, $feed['products'][0]['price']['detail']['net']);
    same(1999, $feed['products'][0]['price']['detail']['gross']);
    same('ES', $feed['products'][0]['price']['detail']['country']);
});

test('un producto con precio oculto no suelta su desglose, y uno con variantes de precio distinto no pinta una línea que cambiaría', function (): void {
    $app = pdApp();
    $app->hooks()->filter('product.availability', static function (array $a): array {
        $a['show_price'] = false;

        return $a;
    });
    $id = aifecProduct($app, ['name' => 'Silla Oculta', 'slug' => 'silla-oculta', 'price' => 1999, 'images' => 1]);
    $web = new TestClient($app);
    $md = $web->get('/producto/silla-oculta.md')->body;
    notContains('Base 16,52', $md);
    notContains('data-af-price-detail', $web->get('/producto/silla-oculta')->body);
    $product = apiJson(apiCall($app, 'GET', '/api/v1/products/' . $id))['data'];
    check(!isset($product['pricing']['detail']) || $product['pricing']['detail'] === null, 'la API no da el desglose: ' . json_encode($product['pricing']));
});
