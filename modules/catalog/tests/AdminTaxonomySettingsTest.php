<?php
/**
 * AIFEC — pruebas de los ajustes del catálogo (listados y palabras de las direcciones), del buscador de órdenes y del menú.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/AdminTaxonomyKitTest.php';

/**
 * Campos del formulario de ajustes del catálogo como los manda un navegador (los interruptores siempre viajan).
 *
 * @param array<string,mixed> $over
 *
 * @return array<string,mixed>
 */
function taxSettingsForm(array $over = []): array
{
    return $over + [
        'catalog.per_page' => '24', 'catalog.show_out_of_stock' => '1', 'catalog.new_days' => '30', 'catalog.low_stock' => '5',
        'urls.product' => 'producto', 'urls.category' => 'categoria', 'urls.brand' => 'marca',
    ];
}

/**
 * @param array<string,mixed> $form
 */
function taxSaveSettings(TestClient $web, array $form): Response
{
    return taxPost($web, '/admin/settings/catalog', '/admin/settings/catalog', ['f' => $form, '_tab' => 'cgurls']);
}

test('los ajustes del catálogo se abren con sus valores de fábrica, se guardan y se vuelven a leer', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $r = $web->get('/admin/settings/catalog');
    same(200, $r->status);
    contains('data-af-tab="cglisting"', $r->body);
    contains('data-af-tab="cgurls"', $r->body);
    same('24', inputValue($r->body, 'f[catalog.per_page]'));
    same('30', inputValue($r->body, 'f[catalog.new_days]'));
    same('5', inputValue($r->body, 'f[catalog.low_stock]'));
    same('categoria', inputValue($r->body, 'f[urls.category]'));
    same(24, $app->settings()->int('catalog.per_page'), 'el valor de fábrica');

    $r = taxSaveSettings($web, taxSettingsForm(['catalog.per_page' => '12', 'catalog.show_out_of_stock' => '0', 'catalog.new_days' => '15', 'catalog.low_stock' => '2']));
    same(302, $r->status);
    contains('#tab=cgurls', $r->headers['Location'], 'vuelve a la misma pestaña');
    same(12, $app->settings()->int('catalog.per_page'));
    same(false, $app->settings()->bool('catalog.show_out_of_stock', true));
    same(15, $app->settings()->int('catalog.new_days'));
    same(2, $app->settings()->int('catalog.low_stock'));
    $again = $web->get('/admin/settings/catalog')->body;
    same('12', inputValue($again, 'f[catalog.per_page]'), 'al recargar sale lo guardado');
});

test('los ajustes validan los números y las palabras de las direcciones y no guardan nada si algo falla', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    foreach ([['catalog.per_page' => '0'], ['catalog.per_page' => '500'], ['catalog.new_days' => '-1'], ['catalog.low_stock' => 'mucho'],
        ['urls.category' => ''], ['urls.category' => 'Con Espacios'], ['urls.product' => 'a/b']] as $bad) {
        $r = taxSaveSettings($web, taxSettingsForm($bad));
        same(422, $r->status, 'no vale ' . json_encode($bad));
    }
    same(24, $app->settings()->int('catalog.per_page'));
    same('categoria', $app->settings()->str('urls.category', 'categoria'));
});

test('cambiar la palabra de las categorías mueve todas sus direcciones y las antiguas redirigen', function (): void {
    $app = taxApp();
    $app->entity('category', static function (Request $request, App $app, array $params): Response {
        return Response::text('categoría ' . $params['id']);
    });
    $web = adminClient($app);
    $muebles = taxMakeCategory($web, 'Muebles');
    $sillas = taxMakeCategory($web, 'Sillas', $muebles);
    catalogNewRequest($app);
    contains('categoria/muebles', aifecCatalog($app)->tree('es')[0]['url'], 'el árbol guarda las direcciones en la caché');

    $r = taxSaveSettings($web, taxSettingsForm(['urls.category' => 'familia']));
    same(302, $r->status);
    same('familia/muebles', $app->slugs()->path('category', $muebles, 'es'));
    same('familia/muebles/sillas', $app->slugs()->path('category', $sillas, 'es'), 'la hija lleva la de su madre con la palabra nueva');

    $front = new TestClient($app);
    $old = $front->get('/categoria/muebles/sillas');
    same(301, $old->status, 'la antigua redirige');
    contains('/familia/muebles/sillas', $old->headers['Location']);
    same(200, $front->get('/familia/muebles')->status);
    catalogNewRequest($app);
    contains('familia/muebles', aifecCatalog($app)->tree('es')[0]['url'], 'y la caché del catálogo se ha renovado');

    // Una categoría nueva ya nace con la palabra nueva.
    $nueva = taxMakeCategory($web, 'Alfombras');
    same('familia/alfombras', $app->slugs()->path('category', $nueva, 'es'));
    // Volver a la palabra de antes también funciona.
    taxSaveSettings($web, taxSettingsForm(['urls.category' => 'categoria']));
    same('categoria/muebles/sillas', $app->slugs()->path('category', $sillas, 'es'));
    same(301, $front->get('/familia/muebles/sillas')->status);
});

test('cambiar la palabra de los productos y de las marcas solo mueve las direcciones de esa clase', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $muebles = taxMakeCategory($web, 'Muebles');
    $brand = aifecBrand($app, 'Ikea');
    $product = aifecProduct($app, ['name' => 'Silla ergo', 'slug' => 'silla-ergo']);

    taxSaveSettings($web, taxSettingsForm(['urls.product' => 'articulo']));
    same('articulo/silla-ergo', $app->slugs()->path('product', $product, 'es'));
    same('marca/ikea', $app->slugs()->path('brand', $brand, 'es'), 'las marcas no se tocan');
    same('categoria/muebles', $app->slugs()->path('category', $muebles, 'es'), 'las categorías tampoco');
    $old = $app->slugs()->resolve('es', 'producto/silla-ergo');
    check($old !== null && $old['canonical'] === false && $old['path'] === 'articulo/silla-ergo', 'la dirección antigua del producto redirige');

    taxSaveSettings($web, taxSettingsForm(['urls.product' => 'articulo', 'urls.brand' => 'fabricante']));
    same('fabricante/ikea', $app->slugs()->path('brand', $brand, 'es'));
    same('articulo/silla-ergo', $app->slugs()->path('product', $product, 'es'));

    // Una marca nueva ya usa la palabra nueva.
    taxPost($web, '/admin/r/brands/new', '/admin/r/brands/save', ['f' => ['name' => 'Bosch', 'slug' => '', 'description' => '', 'website' => '', 'media_id' => '', 'active' => '1', 'position' => '0']]);
    $bosch = (int) $app->db()->value('SELECT MAX(id) FROM {brands}');
    same('fabricante/bosch', $app->slugs()->path('brand', $bosch, 'es'));
});

test('con dos idiomas, cambiar la palabra recalcula las direcciones de los dos', function (): void {
    $app = taxApp([], true);
    $web = adminClient($app);
    $id = taxMakeCategory($web, 'Muebles', null, ['name' => ['es' => 'Muebles', 'en' => 'Furniture']]);
    taxSaveSettings($web, taxSettingsForm(['urls.category' => 'familia']));
    same('familia/muebles', $app->slugs()->path('category', $id, 'es'));
    same('familia/furniture', $app->slugs()->path('category', $id, 'en'));
});

test('el buscador de órdenes encuentra categorías y marcas por nombre, solo para quien puede ver el catálogo', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $muebles = taxMakeCategory($web, 'Muebles de jardín');
    $app->db()->insert('brands', ['name' => 'Muebles Vega', 'active' => 1, 'position' => 0, 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);
    $brand = (int) $app->db()->value('SELECT MAX(id) FROM {brands}');
    taxMakeCategory($web, 'Alfombras');

    $web->get('/admin/palette.json?q=mueb');
    $items = $web->json()['items'];
    $found = [];
    foreach ($items as $item) {
        $found[$item['label']] = $item['url'];
    }
    contains('/admin/r/categories/' . $muebles, $found['Muebles de jardín'], 'la categoría');
    contains('/admin/r/brands/' . $brand, $found['Muebles Vega'], 'la marca');
    check(!isset($found['Alfombras']), 'solo lo que coincide');
    $groups = array_column($items, 'group', 'label');
    same('Categorías', $groups['Muebles de jardín']);
    same('Marcas', $groups['Muebles Vega']);

    $web->get('/admin/palette.json?q=%25');
    $labels = array_column($web->json()['items'], 'label');
    check(!in_array('Alfombras', $labels, true), 'un «%» no es un comodín');

    $support = adminClient($app, 'support');
    $support->get('/admin/palette.json?q=mueb');
    $labels = array_column($support->json()['items'], 'label');
    check(!in_array('Muebles de jardín', $labels, true) && !in_array('Muebles Vega', $labels, true), 'sin permiso no se enseñan');
});

test('el menú y los ajustes enseñan el catálogo a quien tiene permiso y lo ocultan a quien no', function (): void {
    $app = taxApp();
    $admin = adminClient($app);
    $body = $admin->get('/admin')->body;
    foreach (['/admin/r/categories', '/admin/r/brands', '/admin/r/attributes', '/admin/settings/catalog'] as $link) {
        contains('href="' . $app->url()->plain($link) . '"', $body, 'el menú enlaza a ' . $link);
    }
    foreach (['Categorías', 'Marcas', 'Atributos'] as $label) {
        contains($label, $body);
    }
    // Los tipos de producto son una pestaña de «Atributos» (P102): se llega desde la barra de pestañas de esa pantalla.
    $attributes = $admin->get('/admin/r/attributes')->body;
    contains('role="tab" href="' . $app->url()->plain('/admin/r/families') . '"', $attributes, 'la pestaña lleva a los tipos de producto');
    contains('Tipos de producto', $attributes);
    contains('Catálogo', $admin->get('/admin/settings')->body, 'los ajustes del catálogo salen en la lista');

    $support = adminClient($app, 'support');
    $body = $support->get('/admin')->body;
    $token = aifecToken($support);
    notContains('/admin/r/categories', $body);
    notContains('/admin/settings/catalog', $body);
    same(403, $support->get('/admin/settings/catalog')->status);
    same(403, $support->post('/admin/settings/catalog', ['_token' => $token, 'f' => taxSettingsForm(['catalog.per_page' => '3'])])->status);
    same(24, $app->settings()->int('catalog.per_page'), 'no se guardó nada');

    $viewer = taxViewer($app);
    same(200, $viewer->get('/admin/r/categories')->status);
    same(403, $viewer->get('/admin/settings/catalog')->status, 'ver el catálogo no es cambiar sus ajustes');
});

test('desde la consola, cambiar la palabra de las direcciones también las mueve', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $muebles = taxMakeCategory($web, 'Muebles');
    $product = aifecProduct($app, ['name' => 'Silla ergo', 'slug' => 'silla-ergo']);
    $commands = require $app->root() . '/core/cli.php';

    same(0, $commands['setting:set']['run']($app, ['urls.product', 'articulo'], new \Aifec\Cli\Console(false)));
    same('articulo/silla-ergo', $app->slugs()->path('product', $product, 'es'), 'el gancho del catálogo se oye también sin pasar por las rutas del panel');
    same('categoria/muebles', $app->slugs()->path('category', $muebles, 'es'), 'las categorías no se tocan');
    $old = $app->slugs()->resolve('es', 'producto/silla-ergo');
    check($old !== null && $old['canonical'] === false && $old['path'] === 'articulo/silla-ergo', 'la dirección antigua del producto redirige');
});
