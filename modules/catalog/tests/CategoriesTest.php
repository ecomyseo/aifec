<?php
/**
 * AIFEC — pruebas de categorías (ficha, migas, hijas, árbol) y de marcas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('la ficha de una categoría: textos, dirección, migas y hijas con su número de productos', function (): void {
    $app = catalogApp();
    $root = aifecCategory($app, 'Sillas', null, ['description' => '<p>Para sentarse</p>']);
    $mid = aifecCategory($app, 'Oficina', $root);
    $leaf = aifecCategory($app, 'Ergonómicas', $mid, ['product_count' => 4]);
    $second = aifecCategory($app, 'Gaming', $mid, ['product_count' => 2, 'position' => 1]);
    $category = aifecCatalog($app)->category($mid);
    same(['id', 'name', 'description', 'url', 'parent_id', 'crumbs', 'children', 'image', 'facts', 'seo'], array_keys($category));
    same('Oficina', $category['name']);
    same('', $category['description']);
    same('/categoria/sillas/oficina', $category['url']);
    same($root, $category['parent_id']);
    same([['name' => 'Sillas', 'url' => '/categoria/sillas'], ['name' => 'Oficina', 'url' => '/categoria/sillas/oficina']], $category['crumbs']);
    same([
        ['id' => $leaf, 'name' => 'Ergonómicas', 'url' => '/categoria/sillas/oficina/ergonomicas', 'image' => null, 'product_count' => 4],
        ['id' => $second, 'name' => 'Gaming', 'url' => '/categoria/sillas/oficina/gaming', 'image' => null, 'product_count' => 2],
    ], $category['children']);
    same(null, $category['image']);
    same([], $category['facts']);
    same(['title' => 'Oficina', 'description' => ''], $category['seo']);
    $top = aifecCatalog($app)->category($root);
    same(null, $top['parent_id']);
    same([['name' => 'Sillas', 'url' => '/categoria/sillas']], $top['crumbs']);
    same('<p>Para sentarse</p>', $top['description']);
    same('Para sentarse', $top['seo']['description'], 'sin meta descripción, la descripción sin HTML');
});

test('una categoría desactivada o inexistente es null, y sus hijas desactivadas no salen', function (): void {
    $app = catalogApp();
    $root = aifecCategory($app, 'Sillas');
    $off = aifecCategory($app, 'Apagada', $root, ['active' => 0]);
    $on = aifecCategory($app, 'Encendida', $root);
    $catalog = aifecCatalog($app);
    same(null, $catalog->category($off));
    same(null, $catalog->category(424242));
    same([$on], array_map(static function (array $c): int {
        return $c['id'];
    }, $catalog->category($root)['children']));
});

test('las migas funcionan también cuando la ruta guardada no lleva el identificador propio', function (): void {
    $app = catalogApp();
    $root = aifecCategory($app, 'Sillas');
    $child = aifecCategory($app, 'Oficina', $root);
    $app->db()->update('categories', ['path' => (string) $root], ['id' => $child]);
    $crumbs = aifecCatalog($app)->category($child)['crumbs'];
    same(['Sillas', 'Oficina'], array_map(static function (array $c): string {
        return $c['name'];
    }, $crumbs));
    // Y el listado por esa categoría sigue encontrando a los de sus hijas.
    $grand = aifecCategory($app, 'Gaming', $child);
    $app->db()->update('categories', ['path' => $root . '/' . $child], ['id' => $grand]);
    aifecProduct($app, ['name' => 'En nieta', 'categories' => [$grand]]);
    same(['En nieta'], catalogNames(aifecCatalog($app)->listing(['category_id' => $root])));
});

test('categoría en otro idioma, y con respaldo al principal si no está traducida', function (): void {
    $app = catalogApp(['i18n.languages' => ['es', 'en']]);
    $both = aifecCategory($app, 'Sillas', null, ['i18n' => ['en' => ['name' => 'Chairs', 'description' => 'Sit down']]]);
    $onlyEs = aifecCategory($app, 'Mesas');
    $catalog = aifecCatalog($app);
    same('Chairs', $catalog->category($both, 'en')['name']);
    same('Sit down', $catalog->category($both, 'en')['description']);
    same('/en/categoria/chairs', $catalog->category($both, 'en')['url']);
    same('Sillas', $catalog->category($both, 'es')['name']);
    same('Mesas', $catalog->category($onlyEs, 'en')['name']);
    same('/en/categoria/mesas', $catalog->category($onlyEs, 'en')['url'], 'sin dirección en inglés: la del principal, sin salir del inglés (14/11/2026)');
});

test('la imagen de la categoría y la de sus hijas, con el módulo de medios', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $media = aifecMedia($app, 'Sillas');
    $childMedia = aifecMedia($app, 'Oficina');
    $root = aifecCategory($app, 'Sillas', null, ['media_id' => $media]);
    aifecCategory($app, 'Oficina', $root, ['media_id' => $childMedia]);
    $category = aifecCatalog($app)->category($root);
    same('Sillas', $category['image']['alt']);
    same(['id', 'url', 'alt', 'width', 'height', 'thumb', 'card', 'large'], array_keys($category['image']));
    same('Oficina', $category['children'][0]['image']['alt']);
});

test('el árbol: solo las activas, anidadas, por posición, y una rama bajo una categoría apagada no sale', function (): void {
    $app = catalogApp();
    $b = aifecCategory($app, 'Mesas', null, ['position' => 2]);
    $a = aifecCategory($app, 'Sillas', null, ['position' => 1]);
    $a1 = aifecCategory($app, 'Oficina', $a);
    $a2 = aifecCategory($app, 'Gaming', $a, ['position' => 1]);
    $off = aifecCategory($app, 'Apagada', null, ['active' => 0, 'position' => 3]);
    aifecCategory($app, 'Hija de apagada', $off);
    same([
        ['id' => $a, 'name' => 'Sillas', 'url' => '/categoria/sillas', 'children' => [
            ['id' => $a1, 'name' => 'Oficina', 'url' => '/categoria/sillas/oficina', 'children' => []],
            ['id' => $a2, 'name' => 'Gaming', 'url' => '/categoria/sillas/gaming', 'children' => []],
        ]],
        ['id' => $b, 'name' => 'Mesas', 'url' => '/categoria/mesas', 'children' => []],
    ], aifecCatalog($app)->tree());
});

test('el árbol está en caché y se rehace al invalidar «catalog»', function (): void {
    $app = catalogApp();
    $id = aifecCategory($app, 'Sillas');
    $catalog = aifecCatalog($app);
    $catalog->tree();
    $before = $app->db()->queries;
    $catalog->tree();
    same($before, $app->db()->queries, 'la segunda vez, sin consultas');
    $app->db()->update('category_i18n', ['name' => 'Asientos'], ['category_id' => $id]);
    same('Sillas', $catalog->tree()[0]['name']);
    $app->hooks()->fire('admin.resource.saved', 'categories', $id, [], null);
    same('Asientos', $catalog->tree()[0]['name']);
    same('Asientos', $catalog->category($id)['name']);
});

test('la ficha de una categoría se guarda por «category:<id>»', function (): void {
    $app = catalogApp();
    $id = aifecCategory($app, 'Sillas');
    $catalog = aifecCatalog($app);
    $catalog->category($id);
    $before = $app->db()->queries;
    $catalog->category($id);
    same($before, $app->db()->queries);
    $app->db()->update('category_i18n', ['name' => 'Asientos'], ['category_id' => $id]);
    $app->invalidate(['category:' . $id]);
    same('Asientos', $catalog->category($id)['name']);
});

test('el árbol en otro idioma, con las direcciones de ese idioma', function (): void {
    $app = catalogApp(['i18n.languages' => ['es', 'en']]);
    aifecCategory($app, 'Sillas', null, ['i18n' => ['en' => ['name' => 'Chairs']]]);
    $tree = aifecCatalog($app)->tree('en');
    same('Chairs', $tree[0]['name']);
    same('/en/categoria/chairs', $tree[0]['url']);
    same('Sillas', aifecCatalog($app)->tree('es')[0]['name']);
});

test('marcas: la ficha, la lista de las activas por orden y las desactivadas fuera', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $media = aifecMedia($app, 'Logo');
    $b = aifecBrand($app, 'Zeta', ['position' => 2]);
    $a = aifecBrand($app, 'Nórdica', ['position' => 1, 'description' => 'Muebles', 'website' => 'https://nordica.example', 'media_id' => $media]);
    $off = aifecBrand($app, 'Apagada', ['active' => 0]);
    $catalog = aifecCatalog($app);
    $brand = $catalog->brand($a);
    same(['id', 'name', 'description', 'website', 'image', 'url', 'url_abs'], array_keys($brand));
    same('Nórdica', $brand['name']);
    same('Muebles', $brand['description']);
    same('https://nordica.example', $brand['website']);
    same('/marca/nordica', $brand['url']);
    same('https://tienda.test/marca/nordica', $brand['url_abs']);
    same('Logo', $brand['image']['alt']);
    same(null, $catalog->brand($off));
    same(null, $catalog->brand(424242));
    same(null, $catalog->brand($b)['image']);
    same(['Nórdica', 'Zeta'], array_map(static function (array $item): string {
        return $item['name'];
    }, $catalog->brands()));
});

test('brands() se guarda en caché y cambia al invalidar «catalog»', function (): void {
    $app = catalogApp();
    $id = aifecBrand($app, 'Nórdica');
    $catalog = aifecCatalog($app);
    $catalog->brands();
    $before = $app->db()->queries;
    $catalog->brands();
    same($before, $app->db()->queries);
    $app->db()->update('brands', ['name' => 'Nordic'], ['id' => $id]);
    $app->invalidate(['catalog']);
    same('Nordic', $catalog->brands()[0]['name']);
});

test('el servicio catalog está publicado y los ganchos de dinero están sellados', function (): void {
    $app = catalogApp();
    check($app->has('catalog'), 'el servicio catalog existe');
    check(aifecCatalog($app) === $app->service('catalog'), 'siempre el mismo');
    $hooks = $app->hooks();
    $hooks->setCurrentModule('intruso');
    $hooks->filter('product.price', static function (array $b): array {
        return $b;
    });
    $hooks->filter('product.availability', static function (array $a): array {
        return $a;
    });
    $hooks->filter('product.extra', static function (array $e): array {
        return $e;
    });
    $names = [];
    foreach ($hooks->errors() as $error) {
        $names[] = $error['hook'];
    }
    same(['product.price', 'product.availability'], $names, 'precio y disponibilidad rechazados; product.extra no está sellado');
    $hooks->setCurrentModule('core');
});
