<?php
/**
 * AIFEC — pruebas del panel de categorías: alta con dos idiomas, direcciones con la de las madres, mover, ciclos, borrado,
 * árbol del listado, recuento de productos, caché del árbol, textos con «&», datos clave y permisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Catalog\Admin\CategoryAdmin;

require_once __DIR__ . '/AdminTaxonomyKitTest.php';

/** @return array<string,mixed>|null */
function taxCategoryRow(App $app, int $id): ?array
{
    return $app->db()->row('SELECT * FROM {categories} WHERE id = ?', [$id]);
}

test('una categoría con nombre en dos idiomas guarda su fila, sus textos y sus direcciones', function (): void {
    $app = taxApp([], true);
    $web = adminClient($app);
    $r = taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Muebles', 'en' => 'Furniture'], 'slug' => ['es' => '', 'en' => '']]));
    same(302, $r->status);
    $id = (int) $app->db()->value('SELECT MAX(id) FROM {categories}');

    $row = taxCategoryRow($app, $id);
    check($row !== null, 'hay fila');
    same(null, $row['parent_id']);
    same((string) $id, $row['path'], 'la ruta de una raíz es su propio identificador');
    same('0', (string) $row['depth']);
    same('1', (string) $row['active']);
    same('1', (string) $row['in_menu']);
    $names = [];
    foreach ($app->db()->select('SELECT lang, name FROM {category_i18n} WHERE category_id = ?', [$id]) as $text) {
        $names[$text['lang']] = $text['name'];
    }
    ksort($names);
    same(['en' => 'Furniture', 'es' => 'Muebles'], $names);
    same('categoria/muebles', $app->slugs()->path('category', $id, 'es'));
    same('categoria/furniture', $app->slugs()->path('category', $id, 'en'));
    contains('/en/categoria/furniture', $app->url()->entity('category', $id, 'en'));
    contains('Creado', $web->follow()->body, 'el aviso de que se ha creado');
});

test('una traducción sin nombre no se guarda y la tienda enseña el idioma principal', function (): void {
    $app = taxApp([], true);
    $web = adminClient($app);
    $id = taxMakeCategory($web, 'Muebles', null, ['name' => ['es' => 'Muebles', 'en' => '']]);
    same(1, taxScalar($app, 'SELECT COUNT(*) FROM {category_i18n} WHERE category_id = ?', [$id]), 'solo el idioma principal');
    same(null, $app->slugs()->path('category', $id, 'en'), 'sin nombre en inglés no hay dirección en inglés');
    contains('/categoria/muebles', $app->url()->entity('category', $id, 'en'), 'la dirección en inglés cae en la principal');

    taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Muebles', 'en' => 'Furniture']]), $id);
    same(2, taxScalar($app, 'SELECT COUNT(*) FROM {category_i18n} WHERE category_id = ?', [$id]));
    same('categoria/furniture', $app->slugs()->path('category', $id, 'en'));

    taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Muebles', 'en' => '']]), $id);
    same(1, taxScalar($app, 'SELECT COUNT(*) FROM {category_i18n} WHERE category_id = ?', [$id]), 'quitar el nombre quita la traducción');
});

test('una categoría hija lleva la dirección de su madre, y su ruta y nivel', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $muebles = taxMakeCategory($web, 'Muebles');
    $sillas = taxMakeCategory($web, 'Sillas', $muebles);
    $ergo = taxMakeCategory($web, 'Ergonómicas', $sillas);

    same('categoria/muebles/sillas', $app->slugs()->path('category', $sillas, 'es'));
    same('categoria/muebles/sillas/ergonomicas', $app->slugs()->path('category', $ergo, 'es'));
    $row = taxCategoryRow($app, $sillas);
    same($muebles . '/' . $sillas, $row['path']);
    same('1', (string) $row['depth']);
    same((string) $muebles, (string) $row['parent_id']);
    $row = taxCategoryRow($app, $ergo);
    same($muebles . '/' . $sillas . '/' . $ergo, $row['path']);
    same('2', (string) $row['depth']);
});

test('mover la categoría madre recalcula las direcciones de las hijas y las antiguas redirigen con un 301', function (): void {
    $app = taxApp();
    $app->entity('category', static function (Request $request, App $app, array $params): Response {
        return Response::text('categoría ' . $params['id']);
    });
    $web = adminClient($app);
    $muebles = taxMakeCategory($web, 'Muebles');
    $hogar = taxMakeCategory($web, 'Hogar');
    $sillas = taxMakeCategory($web, 'Sillas', $muebles);
    $ergo = taxMakeCategory($web, 'Ergonómicas', $sillas);

    $r = taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Sillas'], 'parent_id' => (string) $hogar, 'slug' => ['es' => 'sillas']]), $sillas);
    same(302, $r->status);

    same('categoria/hogar/sillas', $app->slugs()->path('category', $sillas, 'es'));
    same('categoria/hogar/sillas/ergonomicas', $app->slugs()->path('category', $ergo, 'es'), 'la nieta sigue a su abuela');
    same($hogar . '/' . $sillas, taxCategoryRow($app, $sillas)['path']);
    same($hogar . '/' . $sillas . '/' . $ergo, taxCategoryRow($app, $ergo)['path']);
    same('1', (string) taxCategoryRow($app, $sillas)['depth']);
    same('2', (string) taxCategoryRow($app, $ergo)['depth']);

    $front = new TestClient($app);
    $old = $front->get('/categoria/muebles/sillas');
    same(301, $old->status, 'la dirección antigua de la hija redirige');
    contains('/categoria/hogar/sillas', $old->headers['Location']);
    $oldDeep = $front->get('/categoria/muebles/sillas/ergonomicas');
    same(301, $oldDeep->status);
    contains('/categoria/hogar/sillas/ergonomicas', $oldDeep->headers['Location']);
    $now = $front->get('/categoria/hogar/sillas');
    same(200, $now->status);
    contains('categoría ' . $sillas, $now->body);
});

test('una categoría no se puede mover dentro de sí misma ni de sus descendientes', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $a = taxMakeCategory($web, 'Muebles');
    $b = taxMakeCategory($web, 'Sillas', $a);
    $c = taxMakeCategory($web, 'Ergonómicas', $b);

    foreach ([$b, $c, $a] as $target) {
        $r = taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Muebles'], 'parent_id' => (string) $target]), $a);
        same(422, $r->status, 'el destino ' . $target . ' no vale');
        contains('no se puede mover dentro de sí misma', $r->body);
    }
    same(null, taxCategoryRow($app, $a)['parent_id'], 'no se movió');
    same('categoria/muebles', $app->slugs()->path('category', $a, 'es'));

    $options = CategoryAdmin::parentOptions($app, $a);
    same([], array_keys($options), 'al editar la raíz no hay madre posible (ella y su rama quedan fuera)');
    $options = CategoryAdmin::parentOptions($app, $c);
    same([(string) $a, (string) $b], array_map('strval', array_keys($options)), 'para la nieta, su madre y su abuela, sin ella misma');
    contains('— Sillas', implode('|', $options), 'la lista va sangrada por nivel');

    $r = taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'X'], 'parent_id' => '99999']));
    same(422, $r->status, 'una madre que no existe tampoco');
});

test('no se borra una categoría con subcategorías o con productos, y una vacía se borra con todo lo suyo', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $muebles = taxMakeCategory($web, 'Muebles');
    $sillas = taxMakeCategory($web, 'Sillas', $muebles);
    $web->get('/admin/r/categories');
    $token = aifecToken($web);

    $r = $web->post('/admin/r/categories/' . $muebles . '/delete', ['_token' => $token]);
    same(302, $r->status);
    contains('1 subcategoría', $web->follow()->body, 'el mensaje dice cuántas tiene');
    check(taxCategoryRow($app, $muebles) !== null, 'sigue ahí');

    aifecProduct($app, ['categories' => [$sillas]]);
    aifecProduct($app, ['name' => 'Otra', 'categories' => [$sillas], 'status' => 'draft']);
    $web->get('/admin/r/categories');
    $r = $web->post('/admin/r/categories/' . $sillas . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('2 productos', $web->follow()->body, 'el mensaje dice cuántos productos tiene, también los borradores');
    check(taxCategoryRow($app, $sillas) !== null, 'sigue ahí');

    $vacia = taxMakeCategory($web, 'Vacía');
    $web->get('/admin/r/categories');
    $r = $web->post('/admin/r/categories/' . $vacia . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(null, taxCategoryRow($app, $vacia), 'se borró la fila');
    same(0, taxScalar($app, 'SELECT COUNT(*) FROM {category_i18n} WHERE category_id = ?', [$vacia]), 'y sus textos');
    same(0, taxScalar($app, "SELECT COUNT(*) FROM {slugs} WHERE type = 'category' AND entity_id = ?", [$vacia]), 'y sus direcciones');
});

test('el listado es un árbol por posición, con el número de productos, el estado, el sangrado y la búsqueda', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $beta = taxMakeCategory($web, 'Beta', null, ['position' => '0']);
    $alfa = taxMakeCategory($web, 'Alfa', null, ['position' => '1']);
    $b2 = taxMakeCategory($web, 'Beta dos', $beta, ['position' => '0']);
    $b1 = taxMakeCategory($web, 'Beta uno', $beta, ['position' => '1', 'active' => '0']);
    aifecProduct($app, ['categories' => [$b2]]);
    CategoryAdmin::recount($app);

    $r = $web->get('/admin/r/categories');
    same(200, $r->status);
    $body = $r->body;
    $order = [];
    foreach (['Beta<', 'Beta dos', 'Beta uno', 'Alfa'] as $needle) {
        $order[$needle] = strpos($body, '<strong>' . rtrim($needle, '<') . '</strong>');
        check($order[$needle] !== false, 'sale ' . $needle);
    }
    check($order['Beta<'] < $order['Beta dos'] && $order['Beta dos'] < $order['Beta uno'] && $order['Beta uno'] < $order['Alfa'], 'orden de árbol: cada una tras su madre y por posición');
    contains('— ', $body, 'las hijas van sangradas');
    contains('<td class="af-num af-hide-sm">1</td>', $body, 'el número de productos');
    contains('<span class="af-badge af-badge-warn">No</span>', $body, 'la desactivada se ve');
    contains('/admin/r/categories/new', $body);

    $found = $web->get('/admin/r/categories?q=beta')->body;
    contains('Beta uno', $found);
    notContains('<strong>Alfa</strong>', $found, 'la búsqueda filtra por nombre');
    $none = $web->get('/admin/r/categories?q=zzz')->body;
    contains('Sin resultados', $none);

    $flat = CategoryAdmin::tree($app);
    same([$beta, $b2, $b1, $alfa], array_map(static function (array $n): int {
        return $n['id'];
    }, $flat));
    same([0, 1, 1, 0], array_map(static function (array $n): int {
        return $n['depth'];
    }, $flat));
});

test('el recuento cuenta los productos publicados de la categoría y de sus descendientes, y se rehace al guardar', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $padre = taxMakeCategory($web, 'Muebles');
    $hija = taxMakeCategory($web, 'Sillas', $padre);
    aifecProduct($app, ['name' => 'P1', 'categories' => [$hija]]);
    aifecProduct($app, ['name' => 'P2', 'categories' => [$padre]]);
    aifecProduct($app, ['name' => 'P3', 'categories' => [$hija], 'status' => 'draft']);
    aifecProduct($app, ['name' => 'P4', 'categories' => [$padre, $hija]]);

    CategoryAdmin::recount($app);
    same('3', (string) taxCategoryRow($app, $padre)['product_count'], 'la madre: P1, P2 y P4 (P4 una sola vez), sin el borrador');
    same('2', (string) taxCategoryRow($app, $hija)['product_count'], 'la hija: P1 y P4');

    aifecProduct($app, ['name' => 'P5', 'categories' => [$hija]]);
    taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Sillas'], 'parent_id' => (string) $padre]), $hija);
    same('4', (string) taxCategoryRow($app, $padre)['product_count'], 'al guardar una categoría se recalcula');
    same('3', (string) taxCategoryRow($app, $hija)['product_count']);
});

test('el árbol del catálogo se renueva al guardar una categoría', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $catalog = aifecCatalog($app);
    same([], $catalog->tree('es'), 'sin categorías, árbol vacío (y ya guardado en la caché)');

    $id = taxMakeCategory($web, 'Muebles');
    catalogNewRequest($app);
    $names = array_map(static function (array $n): string {
        return $n['name'];
    }, aifecCatalog($app)->tree('es'));
    same(['Muebles'], $names, 'la categoría nueva ya sale');

    taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Hogar']]), $id);
    catalogNewRequest($app);
    $names = array_map(static function (array $n): string {
        return $n['name'];
    }, aifecCatalog($app)->tree('es'));
    same(['Hogar'], $names, 'el cambio de nombre también');

    taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Hogar'], 'active' => '0']), $id);
    same([], aifecCatalog($app)->tree('es'), 'desactivada deja de salir');
});

test('un nombre con «&» y comillas se guarda tal cual y se vuelve a ver escapado', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $name = 'Tom & "Jerry\'s" <b>';
    $id = taxMakeCategory($web, $name, null, ['description' => ['es' => '<p>Hola & adiós</p><script>alert(1)</script>']]);

    same($name, $app->db()->value('SELECT name FROM {category_i18n} WHERE category_id = ?', [$id]), 'se guarda tal cual');
    $description = (string) $app->db()->value('SELECT description FROM {category_i18n} WHERE category_id = ?', [$id]);
    contains('adiós', $description);
    notContains('<script', $description, 'el HTML se sanea');

    $list = $web->get('/admin/r/categories')->body;
    contains(e($name), $list, 'el listado lo escapa');
    notContains($name, $list, 'y no se pinta sin escapar');
    $edit = $web->get('/admin/r/categories/' . $id);
    same($name, inputValue($edit->body, 'f[name][es]'), 'la ficha lo devuelve igual');
    contains(e($name), $edit->body);
});

test('los datos clave y el SEO se guardan por idioma y salen en la ficha pública', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $id = taxMakeCategory($web, 'Muebles', null, [
        'meta_title' => ['es' => 'Muebles de roble'], 'meta_description' => ['es' => 'Los mejores muebles.'],
        'facts_es' => [
            ['label' => 'Material', 'value' => 'Roble'],
            ['label' => '', 'value' => 'sin etiqueta'],
            ['label' => 'Alto', 'value' => '80 cm'],
        ],
    ]);
    $row = $app->db()->row('SELECT * FROM {category_i18n} WHERE category_id = ?', [$id]);
    same('Muebles de roble', $row['meta_title']);
    same('Los mejores muebles.', $row['meta_description']);
    same([['label' => 'Material', 'value' => 'Roble'], ['label' => 'Alto', 'value' => '80 cm']], json_decode((string) $row['facts'], true), 'las filas incompletas se descartan');

    $public = aifecCatalog($app)->category($id, 'es');
    check($public !== null, 'la ficha pública existe');
    same('Muebles de roble', $public['seo']['title']);
    same('Material', $public['facts'][0]['label']);

    $edit = $web->get('/admin/r/categories/' . $id)->body;
    contains('value="Roble"', $edit, 'al editar vuelven los datos clave');
    contains('value="Muebles de roble"', $edit);

    taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Muebles'], 'facts_es' => []]), $id);
    same(null, $app->db()->value('SELECT facts FROM {category_i18n} WHERE category_id = ?', [$id]), 'sin filas, sin datos clave');
});

test('la dirección escrita a mano se respeta, la vacía se saca del nombre y una repetida lleva sufijo', function (): void {
    $app = taxApp();
    $web = adminClient($app);
    $a = taxMakeCategory($web, 'Muebles', null, ['slug' => ['es' => 'sofas-y-sillones']]);
    same('categoria/sofas-y-sillones', $app->slugs()->path('category', $a, 'es'));
    $b = taxMakeCategory($web, 'Sofás y sillones');
    same('categoria/sofas-y-sillones-2', $app->slugs()->path('category', $b, 'es'), 'otra categoría no se queda con la misma dirección');

    taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Sofás'], 'slug' => ['es' => '']]), $a);
    same('categoria/sofas', $app->slugs()->path('category', $a, 'es'), 'vacía: se saca del nombre');
    $old = $app->slugs()->resolve('es', 'categoria/sofas-y-sillones');
    check($old !== null && $old['canonical'] === false && $old['path'] === 'categoria/sofas', 'la antigua queda redirigiendo');

    $r = taxSaveCategory($web, taxCategoryForm(['name' => ['es' => 'Sofás'], 'slug' => ['es' => 'Con Espacios']]), $a);
    same(422, $r->status, 'una dirección con mayúsculas y espacios no vale');
    contains('minúsculas', $r->body);
});

test('el formulario de categoría tiene dos pestañas con los campos pedidos', function (): void {
    $app = taxApp([], true);
    $web = adminClient($app);
    $body = $web->get('/admin/r/categories/new')->body;
    contains('data-af-tab="ctmain"', $body);
    contains('data-af-tab="ctseo"', $body);
    foreach (['f[name][es]', 'f[name][en]', 'f[parent_id]', 'f[active]', 'f[in_menu]', 'f[media_id]', 'f[position]', 'f[slug][es]', 'f[slug][en]',
        'f[description][es]', 'f[meta_title][en]', 'f[meta_description][es]'] as $field) {
        contains('name="' . $field . '"', $body, 'campo ' . $field);
    }
    contains('f[facts_es]', $body, 'datos clave en español');
    contains('f[facts_en]', $body, 'y en inglés');
});

test('las categorías piden permiso para verse y para cambiarse', function (): void {
    $app = taxApp();
    $id = taxMakeCategory(adminClient($app), 'Muebles');

    $support = adminClient($app, 'support');
    $token = aifecToken($support);
    same(403, $support->get('/admin/r/categories')->status);
    same(403, $support->get('/admin/r/categories/new')->status);
    same(403, $support->get('/admin/r/categories/' . $id)->status);
    same(403, $support->post('/admin/r/categories/save', ['_token' => $token, 'f' => taxCategoryForm(['name' => ['es' => 'Colada']])])->status);
    same(403, $support->post('/admin/r/categories/' . $id . '/delete', ['_token' => $token])->status);
    same(1, taxScalar($app, 'SELECT COUNT(*) FROM {categories}'), 'no se creó ni se borró nada');

    $viewer = taxViewer($app);
    $token = aifecToken($viewer);
    $r = $viewer->get('/admin/r/categories');
    same(200, $r->status, 'con «ver», el árbol se abre');
    contains('Muebles', $r->body);
    notContains('/admin/r/categories/new', $r->body, 'pero sin el botón de crear');
    notContains('/delete', $r->body, 'ni el de borrar');
    same(403, $viewer->get('/admin/r/categories/new')->status);
    same(403, $viewer->post('/admin/r/categories/' . $id . '/save', ['_token' => $token, 'f' => taxCategoryForm(['name' => ['es' => 'Cambiada']])])->status);
    same(403, $viewer->post('/admin/r/categories/' . $id . '/delete', ['_token' => $token])->status);
    same('Muebles', $app->db()->value('SELECT name FROM {category_i18n} WHERE category_id = ?', [$id]), 'no se tocó');
});
