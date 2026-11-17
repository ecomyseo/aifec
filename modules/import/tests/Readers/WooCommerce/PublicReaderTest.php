<?php
/**
 * AIFEC — el lector público de WooCommerce (sin clave): catálogo y contenidos publicados por wc/store/v1 y wp/v2.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\HttpResponse;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Readers\WooCommerce\PublicReader;
use Aifec\Modules\Import\SourceDenied;

require_once __DIR__ . '/fixtures/WooTestKit.php';

test('lector público: cada registro de cada tipo pasa Neutral::check y hay alguno de cada tipo', function (): void {
    $reader = WooTestKit::shop();
    same(['categories', 'attributes', 'brands', 'products', 'media', 'pages', 'post_categories', 'posts'], $reader->types());
    $result = WooTestKit::checkAll($reader);
    same([], $result['problems']);
    same([], $result['empty']);
    same(['categories' => 4, 'attributes' => 2, 'brands' => 1, 'products' => 3, 'media' => 2, 'pages' => 3, 'post_categories' => 2, 'posts' => 2], $result['counts']);
    same('woocommerce', $reader->source());
    same('public', $reader->mode());
    same('tienda.test', $reader->key());
});

test('lector público: los precios llegan en unidades menores y se respeta currency_minor_unit', function (): void {
    $products = WooTestKit::drain(WooTestKit::shop(), 'products')['records'];
    $mesa = WooTestKit::byId($products, '101');
    same(10661, $mesa['price']);
    same(8900, $mesa['sale_price']);
    same(false, $mesa['price_includes_tax']);
    same(['/producto/mesa-auxiliar-roble/'], $mesa['old_paths']);
    same('9', $mesa['brand_id']);
    same(['12'], $mesa['category_ids']);
    same(['es' => ['roble']], $mesa['tags']);
    // Sin decimales (yenes) y con tres (dinares): siempre céntimos.
    same(1000 * 100, \Aifec\Modules\Import\Readers\WooCommerce\Mapper::minorToCents('1000', 0));
    same(1235, \Aifec\Modules\Import\Readers\WooCommerce\Mapper::minorToCents('12345', 3));
    same(null, \Aifec\Modules\Import\Readers\WooCommerce\Mapper::minorToCents('', 2));
    $includes = WooTestKit::drain(WooTestKit::shop(['prices_include_tax' => 'yes']), 'products')['records'];
    same(true, WooTestKit::byId($includes, '101')['price_includes_tax']);
});

test('lector público: lo que la API pública no da se queda sin rellenar, no se inventa', function (): void {
    $products = WooTestKit::drain(WooTestKit::shop(), 'products')['records'];
    $banco = WooTestKit::byId($products, '105');
    same(false, $banco['manage_stock']);
    same(0, $banco['stock']);
    same('outofstock', $banco['extra']['stock_status']);
    same('physical', $banco['kind']);
    same('published', $banco['status']);
    check(!array_key_exists('cost', $banco), 'sin coste');
    check(!array_key_exists('created_at', $banco), 'sin fechas');
    same([], $banco['images']);
});

test('lector público: las variaciones se piden por producto y cada una trae su precio final y su oferta', function (): void {
    $reader = WooTestKit::shop();
    $silla = WooTestKit::byId(WooTestKit::drain($reader, 'products')['records'], '102');
    same(5000, $silla['price']);
    same(['201', '202', '203'], array_map(static function (array $v): string {
        return $v['id'];
    }, $silla['variants']));
    same([['attribute_id' => '1', 'value_id' => '21']], $silla['variants'][0]['values']);
    same(6300, $silla['variants'][2]['price']);
    same(7000, $silla['variants'][2]['compare_price']);
    same(1, $silla['variants'][0]['image_index']);
    same([['attribute_id' => '2', 'value_ids' => ['25']]], $silla['attributes']);
    $asked = 0;
    foreach (WooTestKit::http()->requests as $unused) {
        ++$asked;
    }
    same(0, $asked, 'un cliente nuevo no ha pedido nada');
});

test('lector público: la paginación sigue X-WP-TotalPages hasta agotar y next es null al final', function (): void {
    $http = WooTestKit::http();
    $reader = new PublicReader($http, ['url' => 'https://tienda.test', 'requests_per_second' => 0]);
    $first = $reader->read('products', '', 2);
    same(2, count($first['records']));
    same('2.2', $first['next']);
    same(2, $first['read']);
    $second = $reader->read('products', (string) $first['next'], 2);
    same(1, count($second['records']));
    same(null, $second['next']);
    $drained = WooTestKit::drain(WooTestKit::shop(), 'products', 1);
    same(3, $drained['batches']);
    same(3, $drained['read']);
    same(['101', '102', '105'], array_map(static function (array $p): string {
        return $p['id'];
    }, $drained['records']));
    $exact = WooTestKit::shop()->read('products', '', 3);
    same(null, $exact['next']);
});

test('lector público: ninguna petición lleva clave y todas van a las API públicas', function (): void {
    $http = WooTestKit::http();
    $reader = new PublicReader($http, ['url' => 'https://tienda.test', 'requests_per_second' => 0]);
    foreach ($reader->types() as $type) {
        WooTestKit::drain($reader, $type);
    }
    check(count($http->requests) > 10, 'hizo peticiones');
    foreach ($http->requests as $request) {
        same('GET', $request->method);
        check(!isset($request->headers['Authorization']), 'sin Authorization: ' . $request->url);
        check(strpos($request->url, '/wp-json/wc/store/v1/') !== false || strpos($request->url, '/wp-json/wp/v2/') !== false, 'ruta pública: ' . $request->url);
    }
});

test('lector público: categorías con su ruta antigua, páginas y entradas publicadas con su contenido', function (): void {
    $reader = WooTestKit::shop();
    $categories = WooTestKit::drain($reader, 'categories')['records'];
    $mesas = WooTestKit::byId($categories, '12');
    same('2', $mesas['parent_id']);
    same(['/categoria/muebles/mesas/'], $mesas['old_paths']);
    same(null, WooTestKit::byId($categories, '2')['parent_id']);
    $pages = WooTestKit::drain($reader, 'pages')['records'];
    same(3, count($pages));
    same(['es' => 'Quiénes somos'], WooTestKit::byId($pages, '9')['title']);
    same(['es' => 'Quiénes somos en Ñandú'], WooTestKit::byId($pages, '9')['meta_title']);
    same('published', $pages[0]['status']);
    same('html', $pages[0]['content_format']);
    $posts = WooTestKit::drain($reader, 'posts')['records'];
    $cuidados = WooTestKit::byId($posts, '230');
    same(['es' => ['roble']], $cuidados['tags']);
    same(['4'], $cuidados['category_ids']);
    same('Gestor de tienda', $cuidados['author']);
    same(['url' => 'https://tienda.test/wp-content/uploads/2026/03/taller.jpg'], $cuidados['image']);
    same('2026-03-04T07:30:00Z', $cuidados['published_at']);
    $attributes = WooTestKit::drain($reader, 'attributes')['records'];
    same(['21', '22', '23'], array_map(static function (array $v): string {
        return $v['id'];
    }, $attributes[0]['values']));
});

test('lector público: probe con productos va bien y una tienda vacía no es un «conecta»', function (): void {
    $ok = WooTestKit::shop()->probe();
    same(true, $ok['ok']);
    same('Connected to the shop', $ok['message']);
    same('EUR', $ok['currency']);
    same(['es'], $ok['languages']);
    same(3, $ok['counts']['products']);
    $empty = WooTestKit::shop([], ['mutate' => static function (string $path, $data) {
        return $path === '/wp-json/wc/store/v1/products' ? [] : $data;
    }])->probe();
    same(false, $empty['ok']);
    contains('no published products', $empty['message']);
    $missing = WooTestKit::shop([], ['fail' => static function (string $path) {
        return $path === '/wp-json/wc/store/v1/products' ? new HttpResponse(404, [], '{}') : null;
    }])->probe();
    same(false, $missing['ok']);
    contains('public store API', $missing['message']);
});

test('lector público: un 401 es SourceDenied y no sale ninguna dirección con clave', function (): void {
    $reader = WooTestKit::shop([], ['fail' => static function (string $path) {
        return new HttpResponse(401, [], '{"code":"woocommerce_rest_cannot_view"}');
    }]);
    throws(function () use ($reader): void {
        $reader->read('products', '', 10);
    }, SourceDenied::class);
    $probe = $reader->probe();
    same(false, $probe['ok']);
    notContains('ck_', json_encode($probe));
});

test('lector público: describe pide la dirección, no abre ninguna conexión y exige un cliente HTTP', function (): void {
    $description = PublicReader::describe();
    same('woocommerce', $description['source']);
    same('public', $description['mode']);
    same(true, $description['fields']['url']['required']);
    check(!isset($description['fields']['key']), 'sin clave');
    throws(function (): void {
        new PublicReader(new stdClass(), ['url' => 'https://tienda.test']);
    }, InvalidArgumentException::class);
    throws(function (): void {
        new PublicReader(WooTestKit::http(), ['url' => '']);
    }, InvalidArgumentException::class);
    same(null, WooTestKit::shop()->count('orders'));
    same(3, WooTestKit::shop()->count('pages'));
    same(['products_count' => 3, 'categories_count' => 4, 'pages_count' => 3, 'posts_count' => 2], WooTestKit::shop()->checksums());
    same([], WooTestKit::shop()->unmapped());
    same([], Neutral::check('products', WooTestKit::drain(WooTestKit::shop(), 'products')['records'][0]));
});
