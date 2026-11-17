<?php
/**
 * AIFEC — pruebas del formato de paquete de demostración: qué vale y qué se rechaza, y con qué mensaje.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Modules\Sites\DemoPackageException;
use Aifec\Modules\Sites\Package;

require_once __DIR__ . '/helpers.php';

/** El mensaje de lo que falla al cargar un paquete (cadena vacía si carga). */
function aifecSitesLoadError(string $dir, string $name = 'minimo'): string
{
    try {
        Package::load($dir, $name, Autoload::root());
    } catch (DemoPackageException $e) {
        return $e->getMessage();
    }

    return '';
}

test('el paquete mínimo se valida y trae seis productos, dos categorías, dos páginas, un menú y un transportista', function (): void {
    $pkg = Package::load(Autoload::root() . '/demos', 'minimo', Autoload::root());
    same('minimo', $pkg->name());
    same(6, count($pkg->data('products.json')));
    same(2, count($pkg->data('categories.json')));
    same(2, count($pkg->data('pages.json')));
    same(1, count($pkg->data('menus.json')));
    same(1, count($pkg->data('carriers.json')['carriers']));
    same('es', $pkg->locale());
    $modules = $pkg->modules(Autoload::root());
    foreach (['catalog', 'cms', 'media', 'orders', 'payments-simulated', 'themes'] as $slug) {
        check(in_array($slug, $modules, true), 'el paquete enciende ' . $slug);
    }
    check(!in_array('payments-cod', $modules, true) && !in_array('sites', $modules, true), 'ni pago real ni el módulo de sitios');
    foreach ($pkg->data('products.json') as $product) {
        check($pkg->mediaPath(is_array($product['images'][0]) ? $product['images'][0]['file'] : $product['images'][0]) !== null, 'la imagen de ' . $product['sku'] . ' existe');
    }
});

test('un producto sin precio da un error que dice el fichero, el producto y el motivo', function (): void {
    $dir = aifecSitesPackages();
    aifecSitesEditJson($dir . '/minimo/products.json', static function (array $products): array {
        unset($products[4]['price']);

        return $products;
    });
    $message = aifecSitesLoadError($dir);
    contains('products.json', $message);
    contains('DEMO-MESA-02', $message);
    contains('the price is required', $message);
    contains('Invalid demo package «minimo»', $message);
});

test('una imagen que no existe da un error que dice cuál es y dónde', function (): void {
    $dir = aifecSitesPackages();
    unlink($dir . '/minimo/media/silla-bosque.svg');
    $message = aifecSitesLoadError($dir);
    contains('products.json', $message);
    contains('DEMO-SILLA-02', $message);
    contains('silla-bosque.svg', $message);
    contains('does not exist in media/', $message);
});

test('dos productos con la misma imagen de portada se rechazan: cada uno tiene la suya', function (): void {
    $dir = aifecSitesPackages();
    aifecSitesEditJson($dir . '/minimo/products.json', static function (array $products): array {
        $products[5]['images'] = ['silla-bosque.svg'];

        return $products;
    });
    $message = aifecSitesLoadError($dir);
    contains('DEMO-MESA-03', $message);
    contains('is already the cover of another product', $message);
});

test('un código de barras sin el prefijo de demostración o con la cifra de control mal se rechaza', function (): void {
    $dir = aifecSitesPackages();
    aifecSitesEditJson($dir . '/minimo/products.json', static function (array $products): array {
        $products[0]['gtin'] = '8410000000014';   // un prefijo real
        $products[1]['gtin'] = '2990000000027';   // el 299 pero con la última cifra mal
        $products[2]['gtin'] = '299';

        return $products;
    });
    $message = aifecSitesLoadError($dir);
    contains('DEMO-SILLA-01', $message);
    contains('DEMO-SILLA-02', $message);
    contains('DEMO-SILLA-03', $message);
    contains('invented EAN-13 starting with 299', $message);
});

test('un paquete no puede encender un pago real, el módulo de plugins ni tocar el correo o el modo noindex', function (): void {
    $dir = aifecSitesPackages();
    aifecSitesEditJson($dir . '/minimo/package.json', static function (array $meta): array {
        $meta['modules'][] = 'payments-cod';
        $meta['modules'][] = 'plugins';
        $meta['settings']['mail.mode'] = 'live';
        $meta['settings']['seo.noindex_site'] = false;

        return $meta;
    });
    $message = aifecSitesLoadError($dir);
    contains('«payments-cod» is a real payment', $message);
    contains('the module «plugins» cannot be turned on in a demo', $message);
    contains('the setting «mail.mode» cannot be set by a demo package', $message);
    contains('the setting «seo.noindex_site» cannot be set by a demo package', $message);
});

test('un paquete que no existe, con un nombre raro o con el JSON roto da un error claro', function (): void {
    $dir = aifecSitesPackages();
    contains('does not exist', aifecSitesLoadError($dir, 'no-existe'));
    contains('name is not valid', aifecSitesLoadError($dir, '../minimo'));
    contains('name is not valid', aifecSitesLoadError($dir, 'Minimo'));
    file_put_contents($dir . '/minimo/categories.json', '[{"code": "sillas", ');
    $message = aifecSitesLoadError($dir);
    contains('categories.json', $message);
    contains('not valid JSON', $message);
});

test('un bloque de página que enlaza una categoría o una imagen que no existen se rechaza', function (): void {
    $dir = aifecSitesPackages();
    aifecSitesEditJson($dir . '/minimo/pages.json', static function (array $pages): array {
        $pages[1]['blocks'][] = ['type' => 'products', 'attrs' => ['mode' => 'category', 'category_code' => 'no-existe']];
        $pages[1]['blocks'][] = ['type' => 'image', 'attrs' => ['media_file' => 'fantasma.svg']];

        return $pages;
    });
    $message = aifecSitesLoadError($dir);
    contains('pages.json', $message);
    contains('unknown category «no-existe»', $message);
});

test('la lista de paquetes enseña los válidos con su descripción y los rotos con su motivo', function (): void {
    $dir = aifecSitesPackages();
    unlink($dir . '/dos/products.json');
    $list = Package::available($dir, Autoload::root());
    same(['dos', 'minimo', 'uno'], array_keys($list));
    same('muebles', $list['minimo']['sector']);
    check(isset($list['dos']['error']) && strpos((string) $list['dos']['error'], 'products.json') !== false, 'el paquete roto trae su motivo');
});
