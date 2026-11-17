<?php
/**
 * AIFEC — pruebas de crear una demostración y de cargar un paquete en un sitio: el sitio en su subcarpeta, el catálogo con
 * sus imágenes, la portada, el menú, y que repetir la carga no duplica nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\SiteResolver;
use Aifec\Modules\Catalog\Catalog;
use Aifec\Modules\Sites\DemoBuilder;

require_once __DIR__ . '/helpers.php';

test('crear una demostración deja el sitio en su subcarpeta y su portada responde', function (): void {
    $w = aifecSitesWorld();
    $rows = SiteResolver::all((string) $w['data']);
    same(['demo-minimo'], array_values(array_map(static function (array $r): string {
        return $r['id'];
    }, array_filter($rows, static function (array $r): bool {
        return $r['id'] !== 'main';
    }))));
    $demo = array_values(array_filter($rows, static function (array $r): bool {
        return $r['id'] === 'demo-minimo';
    }))[0];
    same('/demos/minimo', $demo['path']);
    same('https://tienda.test/demos/minimo', $w['url']);
    $config = require $w['data'] . '/demo-minimo/config.php';
    same(true, $config['demo']);
    check(empty($config['master']), 'la demostración no es un sitio maestro');

    $home = aifecSitesFront($w, '/demos/minimo/');
    same(200, $home->status);
    contains('Muebles de madera para todos los días', $home->body);
    contains('Taller de ejemplo', $home->body);
    same(301, aifecSitesFront($w, '/demos/minimo')->status, 'sin la barra final redirige a la portada');
});

test('el catálogo de la demostración tiene los seis productos, cada uno con imagen, y el maestro sigue sin productos', function (): void {
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $catalog = $demo->service('catalog');
    check($catalog instanceof Catalog, 'el servicio catalog existe');
    $listing = $catalog->listing(['per_page' => 50]);
    same(6, $listing['total']);
    same(6, count($listing['items']));
    foreach ($listing['items'] as $card) {
        check($card['image'] !== null, 'el producto «' . $card['name'] . '» tiene imagen');
    }
    same(0, (int) $w['master']->db()->value('SELECT COUNT(*) FROM {products}'), 'el maestro no tiene productos');
    same(0, (int) $w['master']->db()->value('SELECT COUNT(*) FROM {categories}'), 'ni categorías');
    same(0, (int) $w['master']->db()->value('SELECT COUNT(*) FROM {media}'), 'ni medios');
    aifecSitesRelease($demo);
});

test('cada producto tiene su propia imagen de portada y ninguna se repite entre productos', function (): void {
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $covers = $demo->db()->column('SELECT main_media_id FROM {products} ORDER BY id');
    same(6, count($covers));
    same(6, count(array_unique($covers)), 'seis portadas distintas');
    foreach ($covers as $id) {
        check($id !== null && (int) $id > 0, 'cada producto tiene portada');
    }
    $media = $demo->service('media')->image((int) $covers[0]);
    check($media !== null && $media['alt'] !== '', 'la imagen lleva texto alternativo');
    check(is_dir($w['public'] . '/media/demo-minimo'), 'los medios están en la carpeta de ese sitio');
    check(!is_dir($w['public'] . '/media/main'), 'y no en la del maestro');
    aifecSitesRelease($demo);
});

test('la portada, el menú, las categorías y la ficha de producto salen en la demostración', function (): void {
    $w = aifecSitesWorld();
    $home = aifecSitesFront($w, '/demos/minimo/');
    contains('/demos/minimo/categoria/sillas', $home->body, 'el menú enlaza la categoría dentro de la subcarpeta');
    contains('Sobre la demo', $home->body);
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    same(4, count($demo->service('cms')->menu('main')), 'el menú principal tiene sus cuatro elementos');
    $cms = $demo->service('cms');
    check($cms->homePageId() !== null, 'hay página de portada');
    $url = $demo->url()->entity('product', (int) $demo->db()->value('SELECT id FROM {products} WHERE sku = ?', ['DEMO-MESA-01']));
    aifecSitesRelease($demo);
    $page = aifecSitesFront($w, $url);
    same(200, $page->status);
    contains('Mesa de comedor de roble', $page->body);
    $category = aifecSitesFront($w, '/demos/minimo/categoria/sillas');
    same(200, $category->status);
    contains('Silla Aldea', $category->body);
});

test('el producto con variantes trae sus dos variantes y sus valoraciones, y los datos clave y las preguntas', function (): void {
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $id = (int) $demo->db()->value('SELECT id FROM {products} WHERE sku = ?', ['DEMO-SILLA-01']);
    $product = $demo->service('catalog')->product($id);
    check($product !== null, 'el producto se lee');
    same(true, $product['has_variants']);
    same(2, count($product['variants']));
    same(['Natural', 'Negro'], array_map(static function (array $v): string {
        return $v['label'];
    }, $product['variants']));
    same(3, count($product['facts']));
    same(2, count($product['faqs']));
    same(['average' => 4.5, 'count' => 2], $product['rating']);
    same('2990000000019', $product['gtin']);
    same(3990, $product['price']['amount'], 'el precio de oferta manda');
    same('Roble', $product['attributes'][0]['value']);
    aifecSitesRelease($demo);
});

test('los envíos, el cupón y los impuestos del paquete quedan cargados', function (): void {
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    same(1, (int) $demo->db()->value('SELECT COUNT(*) FROM {carriers}'));
    same(2 * 4, (int) $demo->db()->value('SELECT COUNT(*) FROM {carrier_rates}'), 'dos tramos en cada una de las cuatro zonas de serie');
    same(1000, (int) $demo->db()->value('SELECT percent FROM {coupons} WHERE code = ?', ['DEMO10']));
    $options = $demo->service('shipping')->options(['weight' => 3000, 'items_total' => 5000, 'qty' => 1, 'needs_shipping' => true], ['country' => 'ES', 'postcode' => '28001']);
    same(1, count($options));
    same(495, $options[0]['price']);
    same(true, $demo->settings()->bool('tax.prices_include_tax'));
    same('ES', $demo->settings()->str('tax.base_country'));
    same(12, $demo->settings()->int('catalog.per_page'), 'los ajustes del paquete se aplican');
    aifecSitesRelease($demo);
});

test('repetir la carga no duplica productos, categorías, páginas, menús ni medios', function (): void {
    $w = aifecSitesWorld();
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    $tables = ['products', 'variants', 'categories', 'brands', 'attributes', 'attribute_values', 'pages', 'menus', 'menu_items', 'media', 'product_media', 'carriers', 'carrier_rates', 'coupons', 'slugs'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = (int) $demo->db()->value('SELECT COUNT(*) FROM {' . $table . '}');
    }
    aifecSitesRelease($demo);

    $summary = DemoBuilder::for($w['master'])->build('demo-minimo', 'minimo');
    $summary2 = DemoBuilder::for($w['master'])->build('demo-minimo', 'minimo');
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    foreach ($tables as $table) {
        same($before[$table], (int) $demo->db()->value('SELECT COUNT(*) FROM {' . $table . '}'), 'no cambia el recuento de ' . $table);
    }
    same(6, $summary['counts']['products']);
    same(0, $summary['created']['products'], 'la segunda carga no crea productos');
    same(0, $summary['created']['categories']);
    same(0, $summary2['created']['pages']);
    same(0, $summary2['created']['carriers']);
    same(0, $summary2['created']['coupons']);
    same(1, (int) $demo->db()->value("SELECT COUNT(*) FROM {users} WHERE role = 'demo'"), 'una sola persona de demostración');
    aifecSitesRelease($demo);
});

test('una demostración que se construye lleva los módulos de IA de su paquete y hereda la IA del maestro (también su clave)', function (): void {
    // 13/11/2026: las demos de moda y la mínima no tenían ni asistente ni IA, y una demo rehecha cada noche perdía la IA.
    $w = aifecSitesWorld();
    $master = $w['master'];
    $master->settings()->set('ai.enabled', true);
    $master->settings()->set('ai.economy.provider', 'gemini');
    $master->settings()->set('ai.economy.model', 'gemini-flash-lite-latest');
    $master->setSecret('ai.economy.key', 'clave-de-prueba-123');
    $setup = \Aifec\Modules\Sites\Demos::aiSetup($master);
    same('clave-de-prueba-123', $setup['secrets']['ai.economy.key']);
    DemoBuilder::for($master)->build('demo-minimo', 'minimo', ['ai' => $setup]);
    $demo = aifecSitesDemoApp($w, 'demo-minimo');
    foreach (['ai', 'assistant', 'copilot', 'semantic'] as $module) {
        check(in_array($module, (array) $demo->settings()->get('modules.active'), true), 'la demo lleva el módulo ' . $module);
    }
    same(true, $demo->settings()->bool('ai.enabled', false), 'la IA encendida');
    same('gemini-flash-lite-latest', $demo->settings()->str('ai.economy.model'));
    same('clave-de-prueba-123', $demo->secret('ai.economy.key'), 'con la clave del maestro, descifrable en la demo');
    aifecSitesRelease($demo);
    $master->settings()->set('ai.enabled', false);
    same([], \Aifec\Modules\Sites\Demos::aiSetup($master), 'con la IA del maestro apagada no se hereda nada');
    $master->setSecret('ai.economy.key', '');
});

test('crear con un nombre de subcarpeta inválido, repetido o con un paquete que no vale devuelve el error y no toca nada', function (): void {
    $w = aifecSitesFresh();
    $demos = $w['demos'];
    $before = (string) file_get_contents($w['data'] . '/sites.php');
    foreach (['Moda', 'a b', '-x', '', 'x/y', str_repeat('a', 31)] as $bad) {
        $r = $demos->create(['package' => 'minimo', 'slug' => $bad]);
        same(false, $r['ok'], 'rechaza la subcarpeta «' . $bad . '»');
        contains('folder name', (string) $r['error']);
    }
    $r = $demos->create(['package' => 'minimo', 'slug' => 'x', 'password' => 'corta']);
    contains('at least 10 characters', (string) $r['error']);
    $r = $demos->create(['package' => 'no-existe', 'slug' => 'x']);
    contains('does not exist', (string) $r['error']);
    same($before, (string) file_get_contents($w['data'] . '/sites.php'), 'sites.php no cambia');
    same([], $demos->registry(), 'ni queda ficha de nada');
});
