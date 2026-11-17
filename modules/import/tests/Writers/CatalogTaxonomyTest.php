<?php
/**
 * AIFEC — escritores de marcas, categorías y atributos: crean, repiten sin cambios, actualizan, chocan con lo editado aquí, no duplican lo que ya
 * existe, escriben el árbol como el panel (rutas, niveles, direcciones y contadores) y no escriben nada en un simulacro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\AttributesWriter;
use Aifec\Modules\Import\Writers\BrandsWriter;
use Aifec\Modules\Import\Writers\CategoriesWriter;

require_once __DIR__ . '/WritersKit.php';
require_once __DIR__ . '/../../../catalog/tests/helpers.php';

test('una marca se crea, se repite sin cambios y se actualiza al cambiar', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new BrandsWriter($app);
    $record = wr('brands', ['old_paths' => []]);

    $first = $writer->write($record, $ctx);
    same(TypeWriter::CREATED, $first['result']);
    $row = $app->db()->find('brands', ['id' => $first['id']]);
    same(['Nordika', '<p>Madera con certificado.</p>', 'https://nordika.test', 1], [$row['name'], $row['description'], $row['website'], (int) $row['active']]);
    same('marca/nordika', $app->slugs()->path('brand', (int) $first['id'], 'es'));

    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    same(1, wrCount($app, 'brands'), 'ni una marca nueva');
    same(TypeWriter::UPDATED, $writer->write(wr('brands', ['old_paths' => [], 'website' => 'https://nordika.example']), $ctx)['result']);
    same('https://nordika.example', wrValue($app, 'SELECT website FROM {brands} WHERE id = ?', [$first['id']]));
});

test('una marca editada aquí después de traerla choca y no se toca; overwrite_local la pisa', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new BrandsWriter($app);
    $id = (int) $writer->write(wr('brands', ['old_paths' => []]), $ctx)['id'];

    $app->clock()->advance(300);
    $app->db()->update('brands', ['website' => 'https://editada.aqui', 'updated_at' => $app->clock()->sql()], ['id' => $id]);
    $app->clock()->advance(300);
    $out = $writer->write(wr('brands', ['old_paths' => [], 'website' => 'https://nordika.example']), $ctx);

    same(TypeWriter::CONFLICT, $out['result']);
    same($id, $out['id']);
    same('https://editada.aqui', wrValue($app, 'SELECT website FROM {brands} WHERE id = ?', [$id]), 'el dato de aquí queda intacto');

    $over = writersContext($app, ['overwrite_local' => true]);
    same(TypeWriter::UPDATED, $writer->write(wr('brands', ['old_paths' => [], 'website' => 'https://nordika.example']), $over)['result']);
    same('https://nordika.example', wrValue($app, 'SELECT website FROM {brands} WHERE id = ?', [$id]));
});

test('una marca con el mismo nombre no se duplica, y su dirección antigua sigue valiendo con keep_urls o redirige sin ella', function (): void {
    $keep = writersApp();
    $mine = aifecBrand($keep, 'Nordika');
    $out = (new BrandsWriter($keep))->write(wr('brands'), writersContext($keep));
    same(TypeWriter::UPDATED, $out['result'], 'casar por lo natural se trata como actualización');
    same($mine, $out['id']);
    same(1, wrCount($keep, 'brands'));
    same('9_nordika', $keep->slugs()->path('brand', $mine, 'es'), 'con keep_urls la antigua es la de ahora');
    $found = $keep->slugs()->resolve('es', 'marca/nordika');
    same(false, $found['canonical'], 'y la nueva sigue resolviendo, redirigida a la antigua');

    $redirect = writersApp();
    $id = (int) (new BrandsWriter($redirect))->write(wr('brands'), writersContext($redirect, ['keep_urls' => false]))['id'];
    same('marca/nordika', $redirect->slugs()->path('brand', $id, 'es'));
    same('/marca/nordika', wrValue($redirect, "SELECT target FROM {redirects} WHERE source = '9_nordika'"), 'la antigua redirige a la nueva');
});

test('el logotipo de la marca se trae a la biblioteca y los textos de buscadores, que aquí no existen, se cuentan', function (): void {
    $app = writersApp();
    $dir = tmpDir();
    wrPng($dir, 'm/9.png', 2);
    $ctx = writersContext($app, [], [], $dir);
    $out = (new BrandsWriter($app))->write(wr('brands', ['old_paths' => [], 'logo' => ['path' => 'm/9.png'], 'meta_title' => ['es' => 'Nordika madera']]), $ctx);

    $mediaId = (int) wrValue($app, 'SELECT media_id FROM {brands} WHERE id = ?', [$out['id']]);
    check($mediaId > 0 && wrCount($app, 'media', 'id = ?', [$mediaId]) === 1, 'el logotipo está en la biblioteca');
    same(1, $ctx->unmappedCount('seo', 'Brand meta_title'));
});

test('simulacro de marcas: se decide y no cambia ninguna tabla', function (): void {
    $app = writersApp();
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    same(TypeWriter::CREATED, (new BrandsWriter($app))->write(wr('brands'), $dry)['result']);
    same($counts, wrCounts($app), 'todas las tablas, iguales');
});

test('una categoría se crea con sus textos en los idiomas del sitio y su dirección, y se repite sin cambios', function (): void {
    $app = writersApp(['i18n.languages' => ['es', 'en']]);
    $ctx = writersContext($app);
    $writer = new CategoriesWriter($app);
    $record = wr('categories', ['parent_id' => null, 'old_paths' => []]);

    $first = $writer->write($record, $ctx);
    same(TypeWriter::CREATED, $first['result']);
    $id = (int) $first['id'];
    same('Mesas', wrValue($app, "SELECT name FROM {category_i18n} WHERE category_id = ? AND lang = 'es'", [$id]));
    same('Tables', wrValue($app, "SELECT name FROM {category_i18n} WHERE category_id = ? AND lang = 'en'", [$id]));
    same('<p>Mesas de comedor y auxiliares.</p>', wrValue($app, "SELECT description FROM {category_i18n} WHERE category_id = ? AND lang = 'es'", [$id]));
    same('Mesas de madera', wrValue($app, "SELECT meta_title FROM {category_i18n} WHERE category_id = ? AND lang = 'es'", [$id]));
    same('categoria/mesas', $app->slugs()->path('category', $id, 'es'));
    same('categoria/tables', $app->slugs()->path('category', $id, 'en'));
    same((string) $id, wrValue($app, 'SELECT path FROM {categories} WHERE id = ?', [$id]));

    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    same(1, wrCount($app, 'categories'), 'ni una categoría nueva');
    same(TypeWriter::UPDATED, $writer->write(wr('categories', ['parent_id' => null, 'old_paths' => [], 'position' => 4]), $ctx)['result']);
    same(4, (int) wrValue($app, 'SELECT position FROM {categories} WHERE id = ?', [$id]));
});

test('el padre se pone en la segunda pasada aunque venga después: ruta, nivel y dirección con la de la madre delante', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new CategoriesWriter($app);
    $child = wr('categories', ['id' => '12', 'parent_id' => '2', 'old_paths' => []]);
    $parent = wr('categories', ['id' => '2', 'parent_id' => null, 'name' => ['es' => 'Muebles'], 'slug' => ['es' => 'muebles'], 'old_paths' => [], 'meta_title' => [], 'meta_description' => []]);

    $c = $writer->write($child, $ctx);
    $p = $writer->write($parent, $ctx);
    same(null, wrValue($app, 'SELECT parent_id FROM {categories} WHERE id = ?', [$c['id']]), 'en la primera pasada aún no se sabe');

    $writer->link($child, $ctx);
    $writer->link($parent, $ctx);
    same($p['id'], (int) wrValue($app, 'SELECT parent_id FROM {categories} WHERE id = ?', [$c['id']]));
    same($p['id'] . '/' . $c['id'], wrValue($app, 'SELECT path FROM {categories} WHERE id = ?', [$c['id']]));
    same(1, (int) wrValue($app, 'SELECT depth FROM {categories} WHERE id = ?', [$c['id']]));
    same('categoria/muebles/mesas', $app->slugs()->path('category', (int) $c['id'], 'es'));
    same('categoria/muebles', $app->slugs()->path('category', (int) $p['id'], 'es'));
    same([], $ctx->noteTexts('categories'), 'sin avisos');
});

test('el árbol de tres niveles recalcula rutas y direcciones de las descendientes al colgar la madre', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new CategoriesWriter($app);
    $mk = static function (string $id, ?string $parent, string $name, string $slug): array {
        return wr('categories', ['id' => $id, 'parent_id' => $parent, 'name' => ['es' => $name], 'slug' => ['es' => $slug], 'old_paths' => [], 'meta_title' => [], 'meta_description' => []]);
    };
    $records = [$mk('c', 'b', 'Hoja', 'hoja'), $mk('b', 'a', 'Rama', 'rama'), $mk('a', null, 'Raíz', 'raiz')];
    $ids = [];
    foreach ($records as $record) {
        $ids[$record['id']] = (int) $writer->write($record, $ctx)['id'];
    }
    foreach ($records as $record) {
        $writer->link($record, $ctx);
    }

    same($ids['a'] . '/' . $ids['b'] . '/' . $ids['c'], wrValue($app, 'SELECT path FROM {categories} WHERE id = ?', [$ids['c']]));
    same(2, (int) wrValue($app, 'SELECT depth FROM {categories} WHERE id = ?', [$ids['c']]));
    same('categoria/raiz/rama/hoja', $app->slugs()->path('category', $ids['c'], 'es'));
});

test('una categoría con el mismo nombre y el mismo padre no se duplica; con otro padre, sí es otra', function (): void {
    $app = writersApp();
    $parent = aifecCategory($app, 'Muebles');
    $mine = aifecCategory($app, 'Mesas', $parent);
    $ctx = writersContext($app);
    $writer = new CategoriesWriter($app);
    $muebles = wr('categories', ['id' => '2', 'parent_id' => null, 'name' => ['es' => 'Muebles'], 'slug' => ['es' => 'muebles'], 'old_paths' => [], 'meta_title' => [], 'meta_description' => []]);
    $mesas = wr('categories', ['old_paths' => []]);
    $writer->write($muebles, $ctx);
    $before = wrCount($app, 'categories');
    $out = $writer->write($mesas, $ctx);

    same(TypeWriter::UPDATED, $out['result']);
    same($mine, $out['id']);
    same($before, wrCount($app, 'categories'));
    same($parent, $ctx->localId('categories', '2'), 'la madre también casó');

    $other = wr('categories', ['id' => '30', 'parent_id' => null, 'name' => ['es' => 'Mesas'], 'slug' => ['es' => 'mesas-sueltas'], 'old_paths' => [], 'meta_title' => [], 'meta_description' => []]);
    same(TypeWriter::CREATED, $writer->write($other, $ctx)['result'], 'sin padre es otra categoría');
});

test('una categoría editada aquí choca y no se toca; overwrite_local la pisa', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new CategoriesWriter($app);
    $record = wr('categories', ['parent_id' => null, 'old_paths' => []]);
    $id = (int) $writer->write($record, $ctx)['id'];
    $app->clock()->advance(300);
    $app->db()->update('categories', ['position' => 77, 'updated_at' => $app->clock()->sql()], ['id' => $id]);
    $app->clock()->advance(300);

    $changed = wr('categories', ['parent_id' => null, 'old_paths' => [], 'position' => 3]);
    same(TypeWriter::CONFLICT, $writer->write($changed, $ctx)['result']);
    same(77, (int) wrValue($app, 'SELECT position FROM {categories} WHERE id = ?', [$id]));
    same(TypeWriter::UPDATED, $writer->write($changed, writersContext($app, ['overwrite_local' => true]))['result']);
    same(3, (int) wrValue($app, 'SELECT position FROM {categories} WHERE id = ?', [$id]));
});

test('un padre que no vino, o que es una de sus descendientes, deja la categoría arriba y se apunta', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new CategoriesWriter($app);
    $orphan = wr('categories', ['id' => '12', 'parent_id' => 'no-vino', 'old_paths' => []]);
    $writer->write($orphan, $ctx);
    $writer->link($orphan, $ctx);
    same(null, wrValue($app, 'SELECT parent_id FROM {categories} WHERE id = ?', [$ctx->localId('categories', '12')]));
    check(count($ctx->noteTexts('categories')) === 1, 'se apunta');

    $a = wr('categories', ['id' => 'a', 'parent_id' => 'b', 'name' => ['es' => 'Uno'], 'slug' => ['es' => 'uno'], 'old_paths' => [], 'meta_title' => [], 'meta_description' => []]);
    $b = wr('categories', ['id' => 'b', 'parent_id' => 'a', 'name' => ['es' => 'Dos'], 'slug' => ['es' => 'dos'], 'old_paths' => [], 'meta_title' => [], 'meta_description' => []]);
    $writer->write($a, $ctx);
    $writer->write($b, $ctx);
    $writer->link($a, $ctx);
    $writer->link($b, $ctx);
    $parents = [wrValue($app, 'SELECT parent_id FROM {categories} WHERE id = ?', [$ctx->localId('categories', 'a')]), wrValue($app, 'SELECT parent_id FROM {categories} WHERE id = ?', [$ctx->localId('categories', 'b')])];
    check(in_array(null, $parents, true), 'un bucle nunca llega a hacerse: una de las dos queda arriba');
});

test('los idiomas que el sitio no tiene se cuentan una sola vez por categoría y la comprobación final cuenta las categorías', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new CategoriesWriter($app);
    $writer->write(wr('categories', ['parent_id' => null, 'old_paths' => []]), $ctx);

    same(1, $ctx->unmappedCount('language', 'en'), 'nombre, descripción y dirección traen «en» pero se cuenta una vez');
    same(['categories_count' => 1], $writer->checksums($ctx));
    same(0, wrCount($app, 'category_i18n', "lang = 'en'"), 'y no se escribe');
});

test('simulacro de categorías: no cambia ninguna tabla, ni siquiera en la segunda pasada', function (): void {
    $app = writersApp();
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    $writer = new CategoriesWriter($app);
    $record = wr('categories');
    same(TypeWriter::CREATED, $writer->write($record, $dry)['result']);
    $writer->link($record, $dry);
    same($counts, wrCounts($app), 'todas las tablas, iguales');
});

test('un atributo con valores se crea, se repite sin cambios y se actualiza; un valor nuevo se añade sin duplicar los demás', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new AttributesWriter($app);
    $record = wr('attributes');

    $first = $writer->write($record, $ctx);
    same(TypeWriter::CREATED, $first['result']);
    $id = (int) $first['id'];
    $row = $app->db()->find('attributes', ['id' => $id]);
    same(['size', 'select', 1, 1], [$row['code'], $row['type'], (int) $row['variant'], (int) $row['filterable']]);
    same('Tamaño', wrValue($app, "SELECT name FROM {attribute_i18n} WHERE attribute_id = ? AND lang = 'es'", [$id]));
    same(['Pequeña', 'Grande'], array_map('strval', $app->db()->column("SELECT i.label FROM {attribute_values} v JOIN {attribute_value_i18n} i ON i.value_id = v.id AND i.lang = 'es' WHERE v.attribute_id = ? ORDER BY v.position", [$id])));

    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    same(2, wrCount($app, 'attribute_values', 'attribute_id = ?', [$id]));
    $more = wr('attributes', ['values' => array_merge($record['values'], [['id' => '23', 'label' => ['es' => 'Mediana'], 'position' => 2]])]);
    same(TypeWriter::UPDATED, $writer->write($more, $ctx)['result']);
    same(3, wrCount($app, 'attribute_values', 'attribute_id = ?', [$id]));
    same(1, wrCount($app, 'attributes'), 'un solo atributo');
});

test('un atributo que ya existe con el mismo código no se duplica y sus valores se casan por etiqueta', function (): void {
    $app = writersApp();
    $mine = aifecAttribute($app, 'size', 'Tamaño', ['pequena' => 'Pequeña']);
    $values = wrCount($app, 'attribute_values');
    $out = (new AttributesWriter($app))->write(wr('attributes'), writersContext($app));

    same(TypeWriter::UPDATED, $out['result']);
    same($mine, $out['id']);
    same(1, wrCount($app, 'attributes'));
    same($values + 1, wrCount($app, 'attribute_values'), 'solo se crea «Grande»: «Pequeña» ya estaba');
});

test('un atributo sin código casa por el nombre, y el código nuevo sale del nombre sin repetirse', function (): void {
    $app = writersApp();
    $mine = aifecAttribute($app, 'color-base', 'Color', []);
    $ctx = writersContext($app);
    $writer = new AttributesWriter($app);
    $out = $writer->write(wr('attributes', ['id' => 'a7', 'code' => '', 'name' => ['es' => 'color'], 'values' => []]), $ctx);
    same($mine, $out['id'], 'mismo nombre, otro código: es el mismo');

    $new = $writer->write(wr('attributes', ['id' => 'a8', 'code' => '', 'name' => ['es' => 'Acabado'], 'values' => []]), $ctx);
    same('acabado', wrValue($app, 'SELECT code FROM {attributes} WHERE id = ?', [$new['id']]));
    $again = $writer->write(wr('attributes', ['id' => 'a9', 'code' => 'acabado', 'name' => ['es' => 'Otro'], 'values' => []]), $ctx);
    same('acabado-2', wrValue($app, 'SELECT code FROM {attributes} WHERE id = ?', [$again['id']]), 'otro atributo del origen con el mismo código: es otro, y su código no se repite');
});

test('«sirve para variantes» solo se admite en los atributos de lista; en un texto se apaga y se apunta', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $out = (new AttributesWriter($app))->write(wr('attributes', ['id' => 'f1', 'code' => 'nota', 'name' => ['es' => 'Nota'], 'type' => 'text', 'variant' => true, 'values' => [['id' => '1', 'label' => ['es' => 'x'], 'position' => 0]]]), $ctx);

    same(0, (int) wrValue($app, 'SELECT variant FROM {attributes} WHERE id = ?', [$out['id']]));
    same(0, wrCount($app, 'attribute_values', 'attribute_id = ?', [$out['id']]), 'un texto no lleva lista de valores');
    check(count($ctx->noteTexts('attributes')) === 1);
});

test('simulacro de atributos: no cambia ninguna tabla', function (): void {
    $app = writersApp();
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    same(TypeWriter::CREATED, (new AttributesWriter($app))->write(wr('attributes'), $dry)['result']);
    same($counts, wrCounts($app), 'todas las tablas, iguales');
});
