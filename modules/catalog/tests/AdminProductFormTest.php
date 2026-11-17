<?php
/**
 * AIFEC — pruebas del formulario de producto del panel: alta completa, validaciones, edición, existencias, ficheros digitales,
 * duplicar, borrar, acciones en bloque, escapado y permisos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

require_once __DIR__ . '/AdminProductKitTest.php';

test('una alta completa por el formulario deja todo guardado y catalog->product() lo enseña', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $parent = aifecCategory($app, 'Muebles');
    $child = aifecCategory($app, 'Sillas', $parent);
    $brand = aifecBrand($app, 'Ikea');
    $images = [aifecMedia($app, 'Frente'), aifecMedia($app, 'Lado'), aifecMedia($app, 'Detalle')];
    $other = aifecProduct($app, ['name' => 'Mesa de roble']);
    $material = aifecAttribute($app, 'material', 'Material', ['roble', 'pino']);
    $family = prodFamily($app, 'Muebles', [$material]);

    $form = prodForm([
        'name' => ['es' => 'Silla de roble', 'en' => 'Oak chair'], 'summary' => ['es' => '<p>Una silla</p>', 'en' => ''],
        'status' => 'published', 'brand_id' => (string) $brand, 'family_id' => (string) $family,
        'price' => '149,90', 'compare_price' => '199', 'sale_price' => '129,90', 'sale_from' => '2026-11-10T00:00', 'sale_to' => '2026-11-30T00:00',
        'sku' => 'SILLA-1', 'gtin' => '8400000000017', 'stock' => '7', 'weight' => '4500',
        'images' => $images[2] . ',' . $images[0] . ',' . $images[1],
        'categories' => [(string) $child, (string) $parent], 'main_category' => (string) $parent,
        'tags' => 'roble, silla,  roble',
        'slug' => ['es' => 'silla-roble', 'en' => 'oak-chair'],
        'facts_es' => [['label' => 'Material', 'value' => 'Roble macizo']], 'facts_en' => [['label' => 'Material', 'value' => 'Solid oak']],
        'faqs_es' => [['question' => '¿Se monta fácil?', 'answer' => 'Sí, en diez minutos.']],
        'rel_related' => [['product' => (string) $other]],
    ]);
    $r = prodSave($web, $form);
    same(302, $r->status);
    $id = prodScalar($app, 'SELECT MAX(id) FROM {products}');
    check($id !== $other, 'es un producto nuevo');

    $row = $app->db()->row('SELECT * FROM {products} WHERE id = ?', [$id]);
    same('14990', (string) $row['price']);
    same('19900', (string) $row['compare_price']);
    same('12990', (string) $row['sale_price']);
    same('2026-11-09 23:00:00', $row['sale_from'], 'las fechas se guardan en UTC (la tienda está en Madrid)');
    same('SILLA-1', $row['sku']);
    same('4500', (string) $row['weight']);
    same((string) $brand, (string) $row['brand_id']);
    same((string) $images[2], (string) $row['main_media_id'], 'la primera imagen es la principal');
    check($row['published_at'] !== null, 'al publicarlo se apunta cuándo');

    $moves = $app->db()->select('SELECT delta, stock_after, reason FROM {stock_moves} WHERE product_id = ?', [$id]);
    same(1, count($moves), 'las existencias iniciales quedan apuntadas');
    same('7', (string) $moves[0]['delta']);
    same('manual', $moves[0]['reason']);
    same('7', (string) $row['stock']);

    $catalog = aifecCatalog($app);
    $es = $catalog->product($id, 'es');
    check($es !== null, 'está publicado');
    same('Silla de roble', $es['name']);
    same('Oak chair', $catalog->product($id, 'en')['name'], 'el nombre en inglés');
    same([$images[2], $images[0], $images[1]], array_map(static function (array $image): int {
        return (int) $image['id'];
    }, $es['images']), 'las imágenes, en su orden');
    same('Muebles', $es['main_category']['name'], 'la categoría principal');
    $names = array_map(static function (array $c): string {
        return $c['name'];
    }, $es['categories']);
    sort($names);
    same(['Muebles', 'Sillas'], $names, 'las dos categorías');
    same('Ikea', $es['brand']['name']);
    same([['label' => 'Material', 'value' => 'Roble macizo']], $es['facts']);
    same([['label' => 'Material', 'value' => 'Solid oak']], $catalog->product($id, 'en')['facts']);
    same([['question' => '¿Se monta fácil?', 'answer' => 'Sí, en diez minutos.']], $es['faqs']);
    same(['roble', 'silla'], $es['tags'], 'las etiquetas, sin repetir');
    same([$other], $es['related']);
    same('<p>Una silla</p>', $es['summary']);
    same('/producto/silla-roble', $app->url()->entity('product', $id));
    same('producto/oak-chair', $app->slugs()->path('product', $id, 'en'));

    // Segunda vuelta: ahora la ficha tiene los campos de los atributos del tipo.
    $garantia = aifecAttribute($app, 'garantia', 'Garantía', [], ['type' => 'text']);
    $ancho = aifecAttribute($app, 'ancho', 'Ancho', [], ['type' => 'number', 'unit' => 'cm']);
    $montaje = aifecAttribute($app, 'montaje', 'Montaje', [], ['type' => 'bool']);
    $acabados = aifecAttribute($app, 'acabados', 'Acabados', ['barniz', 'cera'], ['type' => 'multiselect']);
    foreach ([$garantia, $ancho, $montaje, $acabados] as $i => $attributeId) {
        $app->db()->insert('family_attributes', ['family_id' => $family, 'attribute_id' => $attributeId, 'position' => $i + 1]);
    }
    $app->invalidate(['catalog']);
    $edit = $web->get('/admin/r/products/' . $id)->body;
    foreach (['f[attr_' . $material . ']', 'f[attr_' . $garantia . '][es]', 'f[attr_' . $garantia . '][en]', 'f[attr_' . $ancho . ']', 'f[attr_' . $montaje . ']', 'f[attr_' . $acabados . '][]'] as $name) {
        contains('name="' . $name . '"', $edit, 'la ficha pide ' . $name);
    }
    $form2 = $form + [];
    $form2['attrs_family'] = (string) $family;
    $form2['attr_' . $material] = (string) prodValue($app, $material, 'roble');
    $form2['attr_' . $garantia] = ['es' => '2 años', 'en' => '2 years'];
    $form2['attr_' . $ancho] = '45.5';
    $form2['attr_' . $montaje] = '1';
    $form2['attr_' . $acabados] = [(string) prodValue($app, $acabados, 'barniz'), (string) prodValue($app, $acabados, 'cera')];
    same(302, prodSave($web, $form2, $id)->status);
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {stock_moves} WHERE product_id = ?', [$id]), 'las mismas existencias no apuntan otro movimiento');

    $attributes = [];
    foreach (aifecCatalog($app)->product($id, 'es')['attributes'] as $attribute) {
        $attributes[$attribute['code']] = $attribute['value'];
    }
    same('Roble', $attributes['material']);
    same('2 años', $attributes['garantia']);
    contains('45,5', $attributes['ancho']);
    contains('cm', $attributes['ancho']);
    same('Sí', $attributes['montaje']);
    same('Barniz, Cera', $attributes['acabados']);
    $english = [];
    foreach (aifecCatalog($app)->product($id, 'en')['attributes'] as $attribute) {
        $english[$attribute['code']] = $attribute['value'];
    }
    same('2 years', $english['garantia'], 'el texto por idioma');
});

test('la oferta tiene que ser menor que el precio y acabar después de empezar; los máximos, en orden', function (): void {
    $app = prodApp();
    $web = adminClient($app);

    $r = prodSave($web, prodForm(['price' => '10,00', 'sale_price' => '10,00']));
    same(422, $r->status, 'una oferta igual al precio no es una oferta');
    contains('data-af-active="pdprice"', $r->body, 'el error sale en la pestaña del precio');
    contains($app->i18n()->t('The sale price has to be lower than the price.'), $r->body);

    $r = prodSave($web, prodForm(['price' => '10,00', 'sale_price' => '8,00', 'sale_from' => '2026-12-10T00:00', 'sale_to' => '2026-12-01T00:00']));
    same(422, $r->status);
    contains($app->i18n()->t('The sale has to end after it starts.'), $r->body);

    $r = prodSave($web, prodForm(['min_qty' => '5', 'max_qty' => '2']));
    same(422, $r->status);
    contains('data-af-active="pdstock"', $r->body);

    $r = prodSave($web, prodForm(['delivery_min_days' => '5', 'delivery_max_days' => '2']));
    same(422, $r->status);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {products}'), 'no se guardó nada');

    $r = prodSave($web, prodForm(['price' => '10,00', 'sale_price' => '8,00', 'sale_from' => '2026-12-01T00:00', 'sale_to' => '2026-12-10T00:00']));
    same(302, $r->status, 'una oferta bien hecha se guarda');
});

test('la referencia no se puede repetir, pero varias vacías sí', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    prodMake($web, 'Uno', ['sku' => 'REF-1']);
    prodMake($web, 'Sin referencia');
    prodMake($web, 'Otro sin referencia');
    same(2, prodScalar($app, 'SELECT COUNT(*) FROM {products} WHERE sku IS NULL'), 'las vacías se guardan sin valor');

    $r = prodSave($web, prodForm(['name' => ['es' => 'Repetido', 'en' => ''], 'sku' => 'REF-1']));
    same(422, $r->status);
    contains($app->i18n()->t('This value is already in use'), $r->body);
    contains('data-af-active="pdstock"', $r->body, 'el error sale en su pestaña');
    same(3, prodScalar($app, 'SELECT COUNT(*) FROM {products}'));

    // Editar un producto con su propia referencia no choca consigo mismo.
    $id = prodScalar($app, "SELECT id FROM {products} WHERE sku = 'REF-1'");
    same(302, prodSave($web, prodForm(['name' => ['es' => 'Uno', 'en' => ''], 'sku' => 'REF-1', 'price' => '12,00']), $id)->status);
    same('1200', (string) $app->db()->value('SELECT price FROM {products} WHERE id = ?', [$id]));
});

test('un nombre vacío en el idioma principal da error en su pestaña y no guarda nada; en otro idioma puede quedar vacío', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $r = prodSave($web, prodForm(['name' => ['es' => '', 'en' => 'Chair'], 'price' => '9,00']));
    same(422, $r->status);
    contains($app->i18n()->t('This field is required'), $r->body);
    contains('data-af-active="pdmain"', $r->body);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {products}'));

    $id = prodMake($web, 'Solo castellano');
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {product_i18n} WHERE product_id = ?', [$id]), 'sin nombre en inglés no hay traducción');
    same(null, $app->slugs()->path('product', $id, 'en'));
});

test('editar cambia el nombre y la dirección, y la antigua redirige', function (): void {
    $app = prodApp();
    $app->entity('product', static function (Request $request, App $app, array $params): Response {
        return Response::text('producto ' . $params['id']);
    });
    $web = adminClient($app);
    $id = prodMake($web, 'Silla de roble', ['status' => 'published', 'slug' => ['es' => 'silla-roble', 'en' => '']]);
    same('producto/silla-roble', $app->slugs()->path('product', $id, 'es'));

    $r = prodSave($web, prodForm(['name' => ['es' => 'Silla de roble XL', 'en' => ''], 'status' => 'published', 'slug' => ['es' => 'silla-roble-xl', 'en' => '']]), $id);
    same(302, $r->status);
    same('producto/silla-roble-xl', $app->slugs()->path('product', $id, 'es'));
    same('Silla de roble XL', $app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$id, 'es']));

    $front = new TestClient($app);
    $old = $front->get('/producto/silla-roble');
    same(301, $old->status, 'la antigua redirige');
    contains('/producto/silla-roble-xl', $old->headers['Location']);
    same(200, $front->get('/producto/silla-roble-xl')->status);
    contains('value="silla-roble-xl"', $web->get('/admin/r/products/' . $id)->body, 'la ficha enseña la dirección sin el prefijo');

    // Con la dirección vacía se saca del nombre; y cambiar la palabra de los ajustes la deja en el prefijo nuevo.
    prodSave($web, prodForm(['name' => ['es' => 'Silla Nueva', 'en' => ''], 'status' => 'published', 'slug' => ['es' => '', 'en' => '']]), $id);
    same('producto/silla-nueva', $app->slugs()->path('product', $id, 'es'));
});

test('quitar una imagen y cambiar de tipo de producto deja lo que toca', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $images = [aifecMedia($app, 'a'), aifecMedia($app, 'b'), aifecMedia($app, 'c')];
    $color = aifecAttribute($app, 'color', 'Color', ['rojo', 'azul']);
    $talla = aifecAttribute($app, 'talla', 'Talla', ['s', 'm']);
    $familyA = prodFamily($app, 'Ropa', [$color, $talla]);
    $familyB = prodFamily($app, 'Calzado', [$talla]);

    $id = prodMake($web, 'Camiseta', ['family_id' => (string) $familyA, 'images' => implode(',', $images)]);
    same(3, prodScalar($app, 'SELECT COUNT(*) FROM {product_media} WHERE product_id = ?', [$id]));

    // Con el tipo guardado, la ficha trae sus atributos; se rellenan.
    $form = prodForm(['name' => ['es' => 'Camiseta', 'en' => ''], 'family_id' => (string) $familyA, 'attrs_family' => (string) $familyA, 'images' => implode(',', $images),
        'attr_' . $color => (string) prodValue($app, $color, 'rojo'), 'attr_' . $talla => (string) prodValue($app, $talla, 'm')]);
    same(302, prodSave($web, $form, $id)->status);
    same(2, prodScalar($app, 'SELECT COUNT(*) FROM {product_attributes} WHERE product_id = ?', [$id]));

    // Quitar la imagen del medio: la principal pasa a ser la primera que queda.
    $form['images'] = $images[2] . ',' . $images[0];
    same(302, prodSave($web, $form, $id)->status);
    same([(string) $images[2], (string) $images[0]], array_map('strval', $app->db()->column('SELECT media_id FROM {product_media} WHERE product_id = ? ORDER BY position', [$id])));
    same((string) $images[2], (string) $app->db()->value('SELECT main_media_id FROM {products} WHERE id = ?', [$id]));
    same(3, prodScalar($app, 'SELECT COUNT(*) FROM {media}'), 'la imagen sigue en la biblioteca');

    // Cambiar de tipo: el color ya no es del tipo y se quita; la talla se conserva.
    $form['family_id'] = (string) $familyB;
    same(302, prodSave($web, $form, $id)->status);
    same([(string) $talla], array_map('strval', $app->db()->column('SELECT attribute_id FROM {product_attributes} WHERE product_id = ?', [$id])));
    $edit = $web->get('/admin/r/products/' . $id)->body;
    contains('name="f[attr_' . $talla . ']"', $edit, 'la pestaña enseña los atributos del tipo nuevo');
    notContains('name="f[attr_' . $color . ']"', $edit);

    // Sin tipo: no se pierde nada de lo que ya hay y la pestaña lo dice.
    $form['family_id'] = '';
    prodSave($web, $form, $id);
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {product_attributes} WHERE product_id = ?', [$id]), 'quitar el tipo no borra valores');
});

test('cambiar las existencias a mano apunta un movimiento y renueva la caché del producto', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $id = prodMake($web, 'Silla', ['status' => 'published', 'stock' => '5']);
    $catalog = aifecCatalog($app);
    same(5, $catalog->product($id)['availability']['qty']);

    same(302, prodSave($web, prodForm(['name' => ['es' => 'Silla', 'en' => ''], 'status' => 'published', 'stock' => '12']), $id)->status);
    same(12, $catalog->product($id)['availability']['qty'], 'la caché se renovó al guardar');
    $moves = $app->db()->select('SELECT delta, stock_after, reason FROM {stock_moves} WHERE product_id = ? ORDER BY id', [$id]);
    same(2, count($moves));
    same('7', (string) $moves[1]['delta']);
    same('12', (string) $moves[1]['stock_after']);
    same('manual', $moves[1]['reason']);

    prodSave($web, prodForm(['name' => ['es' => 'Silla', 'en' => ''], 'status' => 'published', 'stock' => '3']), $id);
    same('-9', (string) $app->db()->value('SELECT delta FROM {stock_moves} WHERE product_id = ? ORDER BY id DESC', [$id]));
    same(3, $catalog->product($id)['availability']['qty']);

    // Sin controlar las existencias no se apunta nada.
    prodSave($web, prodForm(['name' => ['es' => 'Silla', 'en' => ''], 'status' => 'published', 'manage_stock' => '0', 'stock' => '50']), $id);
    same(3, prodScalar($app, 'SELECT COUNT(*) FROM {stock_moves} WHERE product_id = ?', [$id]), 'sin control no hay movimientos');
});

test('si falla lo asociado, un producto que existía conserva lo que tenía y uno nuevo queda como borrador', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $old = aifecCategory($app, 'Antigua');
    $new = aifecCategory($app, 'Nueva');
    $id = prodMake($web, 'Silla', ['categories' => [(string) $old], 'main_category' => (string) $old, 'tags' => 'uno, dos', 'status' => 'published']);
    same([(string) $old], array_map('strval', $app->db()->column('SELECT category_id FROM {product_categories} WHERE product_id = ?', [$id])));

    // Una de las escrituras del guardado falla a mitad (las preguntas van después de las categorías y las etiquetas): todo vuelve atrás.
    $app->db()->pdo()->exec('CREATE TRIGGER prod_boom BEFORE INSERT ON ' . $app->db()->table('product_faqs') . " BEGIN SELECT RAISE(ABORT, 'boom'); END");
    $web->get('/admin/r/products');
    $r = prodPost($web, '/admin/r/products/' . $id . '/save', ['f' => prodForm(['name' => ['es' => 'Silla renombrada', 'en' => ''], 'status' => 'published', 'categories' => [(string) $new], 'main_category' => (string) $new, 'tags' => 'tres',
        'faqs_es' => [['question' => '¿Algo?', 'answer' => 'Sí.']]])]);
    same(302, $r->status);
    contains($app->i18n()->t('The categories, images and other data of the product could not be saved, so they stay as they were. Try again.'), $web->follow()->body, 'se avisa');
    same([(string) $old], array_map('strval', $app->db()->column('SELECT category_id FROM {product_categories} WHERE product_id = ?', [$id])), 'las categorías siguen como estaban');
    same(['dos', 'uno'], $app->db()->column('SELECT g.name FROM {product_tags} pt JOIN {tags} g ON g.id = pt.tag_id WHERE pt.product_id = ? ORDER BY g.name', [$id]), 'y las etiquetas');

    // Un alta que falla deja el producto como borrador, no a medias y publicado.
    $web->get('/admin/r/products');
    $r = prodPost($web, '/admin/r/products/save', ['f' => prodForm(['name' => ['es' => 'Recién nacida', 'en' => ''], 'status' => 'published', 'faqs_es' => [['question' => '¿Algo?', 'answer' => 'Sí.']]])]);
    same(302, $r->status);
    $created = prodScalar($app, 'SELECT MAX(id) FROM {products}');
    same('draft', $app->db()->value('SELECT status FROM {products} WHERE id = ?', [$created]));
    contains($app->i18n()->t('The product was created as a draft, but its categories, images and other data could not be saved. Open it and save again.'), $web->follow()->body);
});

test('un producto digital sube su fichero fuera de la carpeta pública y quitarlo lo borra', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $pdf = prodFile("%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n", 'manual.pdf');

    notContains('data-af-panel="pdfiles"', $web->get('/admin/r/products/new')->body, 'un producto físico no tiene pestaña de ficheros');
    $r = prodSave($web, prodForm(['name' => ['es' => 'Curso', 'en' => ''], 'kind' => 'digital', 'new_file_name' => 'Manual de montaje', 'new_file_version' => '1.0']), null, prodUpload('new_file', $pdf, 'Manual ñ.pdf'));
    same(302, $r->status);
    $id = prodScalar($app, 'SELECT MAX(id) FROM {products}');
    $file = $app->db()->row('SELECT * FROM {product_files} WHERE product_id = ?', [$id]);
    check($file !== null, 'el fichero se ha dado de alta');
    same('Manual de montaje', $file['name']);
    same('1.0', $file['version']);
    same((string) filesize($pdf), (string) $file['bytes']);
    check(strpos((string) $file['path'], 'products/') === 0, 'va a su zona de la carpeta privada: ' . $file['path']);
    $full = $app->service('files')->path((string) $file['path']);
    check($full !== null && is_file($full), 'existe en disco');
    check(strpos(str_replace('\\', '/', (string) $full), str_replace('\\', '/', $app->publicDir())) !== 0, 'no está en la raíz web');
    contains('data-af-panel="pdfiles"', $web->get('/admin/r/products/' . $id)->body, 'el digital tiene la pestaña');
    contains('Manual de montaje', $web->get('/admin/r/products/' . $id)->body);

    // Un fichero peligroso se rechaza con un aviso y el resto se guarda.
    $php = prodFile('<?php echo 1;', 'shell.php');
    $keep = [['id' => (string) $file['id'], 'name' => 'Manual de montaje', 'version' => '1.1']];
    $r = prodSave($web, prodForm(['name' => ['es' => 'Curso nuevo', 'en' => ''], 'kind' => 'digital', 'files' => $keep]), $id, prodUpload('new_file', $php, 'shell.php'));
    same(302, $r->status);
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {product_files} WHERE product_id = ?', [$id]), 'el fichero peligroso no se guarda');
    same('1.1', $app->db()->value('SELECT version FROM {product_files} WHERE product_id = ?', [$id]));
    same('Curso nuevo', $app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ?', [$id]), 'el resto sí se guardó');
    contains($app->i18n()->t('This file type is not allowed'), $web->follow()->body, 'y se avisa de por qué');

    // Quitar la fila borra el fichero del disco.
    same(302, prodSave($web, prodForm(['name' => ['es' => 'Curso nuevo', 'en' => ''], 'kind' => 'digital']), $id)->status);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {product_files} WHERE product_id = ?', [$id]));
    check(!is_file((string) $full), 'el fichero ya no está en disco');
});

test('duplicar crea un borrador sin referencia ni existencias y con «(copia)» en el nombre', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $category = aifecCategory($app, 'Sillas');
    $images = [aifecMedia($app, 'a')];
    $id = prodMake($web, 'Silla', ['status' => 'published', 'sku' => 'SILLA', 'gtin' => '8400000000017', 'stock' => '9', 'categories' => [(string) $category], 'main_category' => (string) $category,
        'tags' => 'madera', 'images' => (string) $images[0], 'facts_es' => [['label' => 'Alto', 'value' => '90 cm']], 'price' => '49,50']);

    $web->get('/admin/r/products/' . $id);
    $r = $web->post('/admin/catalog/products/' . $id . '/duplicate', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    $copy = prodScalar($app, 'SELECT MAX(id) FROM {products}');
    check($copy !== $id);
    contains('/admin/r/products/' . $copy, $r->headers['Location']);

    $row = $app->db()->row('SELECT * FROM {products} WHERE id = ?', [$copy]);
    same('draft', $row['status']);
    same(null, $row['sku'], 'sin referencia');
    same(null, $row['gtin'], 'sin código de barras');
    same('0', (string) $row['stock'], 'sin existencias');
    same('4950', (string) $row['price'], 'el precio se conserva');
    same('Silla (copia)', $app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ?', [$copy]));
    same('producto/silla-copia', $app->slugs()->path('product', $copy, 'es'));
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {stock_moves} WHERE product_id = ?', [$copy]), 'no se apunta ningún movimiento');
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {product_categories} WHERE product_id = ?', [$copy]), 'se copian las categorías');
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {product_tags} WHERE product_id = ?', [$copy]));
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {product_media} WHERE product_id = ?', [$copy]));
    same([['label' => 'Alto', 'value' => '90 cm']], \Aifec\Modules\Catalog\Texts::facts((string) $app->db()->value('SELECT facts FROM {product_i18n} WHERE product_id = ?', [$copy])));
    same(2, prodScalar($app, 'SELECT COUNT(*) FROM {products}'), 'el original sigue ahí');
    same('SILLA', $app->db()->value('SELECT sku FROM {products} WHERE id = ?', [$id]));
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {categories} WHERE product_count > 1'), 'la copia es borrador y no cuenta en la categoría');
    $web->get('/admin/r/products');
    same(404, $web->post('/admin/catalog/products/99999/duplicate', ['_token' => aifecToken($web)])->status);
});

test('borrar un producto se lleva lo suyo pero no las imágenes de la biblioteca', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $category = aifecCategory($app, 'Sillas');
    $images = [aifecMedia($app, 'a'), aifecMedia($app, 'b')];
    $friend = aifecProduct($app, ['name' => 'Amiga']);
    $id = prodMake($web, 'Silla', ['status' => 'published', 'categories' => [(string) $category], 'main_category' => (string) $category, 'tags' => 'uno', 'images' => implode(',', $images),
        'facts_es' => [['label' => 'Alto', 'value' => '90']], 'faqs_es' => [['question' => '¿Q?', 'answer' => 'A']], 'rel_cross' => [['product' => (string) $friend]], 'stock' => '4']);
    $app->db()->insert('product_relations', ['product_id' => $friend, 'related_id' => $id, 'kind' => 'related', 'position' => 0]);
    $variant = $app->db()->insert('variants', ['product_id' => $id, 'stock' => 1, 'position' => 0, 'active' => 1, 'created_at' => $app->clock()->sql(), 'updated_at' => $app->clock()->sql()]);
    $app->db()->insert('variant_values', ['variant_id' => $variant, 'attribute_id' => aifecAttribute($app, 'color', 'Color', ['rojo']), 'value_id' => aifecValueId($app, 'color', 'rojo')]);
    same(1, prodScalar($app, 'SELECT product_count FROM {categories} WHERE id = ?', [$category]), 'la categoría cuenta el producto publicado');

    $web->get('/admin/r/products/' . $id);
    $r = $web->post('/admin/r/products/' . $id . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    foreach (['products' => 'id', 'product_i18n' => 'product_id', 'product_categories' => 'product_id', 'product_tags' => 'product_id', 'product_media' => 'product_id', 'product_faqs' => 'product_id',
        'product_relations' => 'product_id', 'variants' => 'product_id', 'stock_moves' => 'product_id'] as $table => $column) {
        same(0, prodScalar($app, 'SELECT COUNT(*) FROM {' . $table . '} WHERE ' . $column . ' = ?', [$id]), 'no queda nada en ' . $table);
    }
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {product_relations} WHERE related_id = ?', [$id]), 'ni lo que lo apuntaba');
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {variant_values} WHERE variant_id = ?', [$variant]));
    same(0, prodScalar($app, "SELECT COUNT(*) FROM {slugs} WHERE type = 'product' AND entity_id = ?", [$id]), 'ni su dirección');
    same(2, prodScalar($app, 'SELECT COUNT(*) FROM {media}'), 'las imágenes siguen en la biblioteca');
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {products} WHERE id = ?', [$friend]), 'los demás productos siguen');
    same(0, prodScalar($app, 'SELECT product_count FROM {categories} WHERE id = ?', [$category]), 'la categoría recuenta');
});

test('un producto que aparece en pedidos no se borra: se archiva', function (): void {
    $app = prodApp();
    prodOrderItems($app);
    $web = adminClient($app);
    $sold = prodMake($web, 'Vendida', ['status' => 'published']);
    $free = prodMake($web, 'Sin pedidos', ['status' => 'published']);
    $app->db()->insert('order_items', ['order_id' => 1, 'product_id' => $sold, 'variant_id' => null]);
    $app->db()->insert('order_items', ['order_id' => 2, 'product_id' => $sold, 'variant_id' => null]);

    $web->get('/admin/r/products/' . $sold);
    $r = $web->post('/admin/r/products/' . $sold . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('2 pedidos', $web->follow()->body, 'el mensaje dice en cuántos pedidos está');
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {products} WHERE id = ?', [$sold]), 'sigue ahí');

    $web->get('/admin/r/products');
    $r = $web->post('/admin/r/products/bulk', ['_token' => aifecToken($web), 'action' => 'delete', 'ids' => [(string) $sold, (string) $free]]);
    same(302, $r->status);
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {products} WHERE id = ?', [$sold]), 'el vendido no se borra ni en bloque');
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {products} WHERE id = ?', [$free]), 'el otro sí');

    $web->get('/admin/r/products');
    same(302, $web->post('/admin/r/products/bulk', ['_token' => aifecToken($web), 'action' => 'archive', 'ids' => [(string) $sold]])->status);
    same('archived', $app->db()->value('SELECT status FROM {products} WHERE id = ?', [$sold]), 'se ofrece archivarlo');
});

test('las acciones en bloque publican, pasan a borrador y archivan, y recuentan la categoría', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $category = aifecCategory($app, 'Sillas');
    $a = prodMake($web, 'A', ['categories' => [(string) $category], 'main_category' => (string) $category]);
    $b = prodMake($web, 'B', ['categories' => [(string) $category], 'main_category' => (string) $category]);
    same(0, prodScalar($app, 'SELECT product_count FROM {categories} WHERE id = ?', [$category]), 'son borradores');
    $catalog = aifecCatalog($app);
    same(null, $catalog->product($a), 'un borrador no se ve');

    $web->get('/admin/r/products');
    $r = $web->post('/admin/r/products/bulk', ['_token' => aifecToken($web), 'action' => 'publish', 'ids' => [(string) $a, (string) $b]]);
    same(302, $r->status);
    same(2, prodScalar($app, "SELECT COUNT(*) FROM {products} WHERE status = 'published' AND published_at IS NOT NULL"));
    check($catalog->product($a) !== null, 'ya se ve');
    same(2, prodScalar($app, 'SELECT product_count FROM {categories} WHERE id = ?', [$category]));

    $web->get('/admin/r/products');
    $web->post('/admin/r/products/bulk', ['_token' => aifecToken($web), 'action' => 'draft', 'ids' => [(string) $a]]);
    same('draft', $app->db()->value('SELECT status FROM {products} WHERE id = ?', [$a]));
    same(null, $catalog->product($a), 'la caché se renovó');
    same(1, prodScalar($app, 'SELECT product_count FROM {categories} WHERE id = ?', [$category]));

    $web->get('/admin/r/products');
    $web->post('/admin/r/products/bulk', ['_token' => aifecToken($web), 'action' => 'archive', 'ids' => [(string) $b]]);
    same('archived', $app->db()->value('SELECT status FROM {products} WHERE id = ?', [$b]));
    same(0, prodScalar($app, 'SELECT product_count FROM {categories} WHERE id = ?', [$category]));

    $web->get('/admin/r/products');
    $web->post('/admin/r/products/bulk', ['_token' => aifecToken($web), 'action' => 'delete', 'ids' => [(string) $a, (string) $b]]);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {products}'));
});

test('un nombre con «&», comillas y <b> se guarda tal cual y sale escapado en el listado y en el formulario', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $name = 'Silla <b>"A" & B</b> \'C\'';
    $id = prodMake($web, $name, ['status' => 'published', 'sku' => 'A&B-1']);
    same($name, $app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$id, 'es']), 'se guarda tal cual');

    $list = $web->get('/admin/r/products')->body;
    contains(e($name), $list, 'el listado lo escapa');
    notContains('<b>"A"', $list);
    contains(e('A&B-1'), $list);
    $edit = $web->get('/admin/r/products/' . $id)->body;
    same($name, inputValue($edit, 'f[name][es]'), 'el formulario lo devuelve igual');
    notContains('<b>"A"', $edit);
    notContains('Silla <b>', $edit);
    $title = $web->get('/admin/r/products/' . $id)->body;
    contains(e($name), $title, 'el título de la pantalla también');
});

test('el listado busca por nombre, referencia y código de barras y filtra por estado, categoría, marca y existencias', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $muebles = aifecCategory($app, 'Muebles');
    $sillas = aifecCategory($app, 'Sillas', $muebles);
    $lamparas = aifecCategory($app, 'Lamparas');
    $ikea = aifecBrand($app, 'Ikea');
    $zara = aifecBrand($app, 'Zara');
    aifecProduct($app, ['name' => 'Silla alta', 'sku' => 'SIL-1', 'gtin' => '8400000000017', 'brand_id' => $ikea, 'categories' => [$sillas], 'stock' => 20]);
    aifecProduct($app, ['name' => 'Mesa baja', 'sku' => 'MES-2', 'gtin' => '8400000000024', 'brand_id' => $zara, 'categories' => [$muebles], 'stock' => 2]);
    aifecProduct($app, ['name' => 'Lámpara', 'sku' => 'LAM-3', 'status' => 'draft', 'categories' => [$lamparas], 'stock' => 0]);
    aifecProduct($app, ['name' => 'Sofá', 'sku' => 'SOF-4', 'status' => 'archived', 'stock' => 9]);

    $has = static function (string $url, array $in, array $out) use ($web): void {
        $body = $web->get($url)->body;
        foreach ($in as $text) {
            contains($text, $body, $url . ' tiene que traer ' . $text);
        }
        foreach ($out as $text) {
            notContains($text, $body, $url . ' no puede traer ' . $text);
        }
    };
    $has('/admin/r/products', ['Silla alta', 'Mesa baja', 'Lámpara', 'Sofá'], []);
    $has('/admin/r/products?q=mesa', ['Mesa baja'], ['Silla alta', 'Lámpara']);
    $has('/admin/r/products?q=LAM-3', ['Lámpara'], ['Silla alta', 'Mesa baja']);
    $has('/admin/r/products?q=8400000000017', ['Silla alta'], ['Mesa baja']);
    $has('/admin/r/products?f[status]=draft', ['Lámpara'], ['Silla alta', 'Sofá']);
    $has('/admin/r/products?f[status]=archived', ['Sofá'], ['Silla alta']);
    $has('/admin/r/products?f[brand]=' . $zara, ['Mesa baja'], ['Silla alta']);
    $has('/admin/r/products?f[category]=' . $muebles, ['Silla alta', 'Mesa baja'], ['Lámpara']);
    $has('/admin/r/products?f[category]=' . $sillas, ['Silla alta'], ['Mesa baja']);
    $has('/admin/r/products?f[stock]=low', ['Mesa baja', 'Lámpara'], ['Silla alta', 'Sofá']);
    $has('/admin/r/products?f[stock]=out', ['Lámpara'], ['Mesa baja', 'Silla alta']);
    $has('/admin/r/products?sort=price&dir=asc', ['Silla alta'], []);
    same(200, $web->get('/admin/r/products/export.csv')->status);
    $json = json_decode($web->get('/admin/r/products/search.json?q=mesa')->body, true);
    same('Mesa baja', $json['items'][0]['label'], 'el buscador de relaciones encuentra por nombre');
});

test('los atributos del tipo se piden en su pestaña: lista, varias opciones, texto por idioma, número y sí/no', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $material = aifecAttribute($app, 'material', 'Material', ['roble', 'pino']);
    $garantia = aifecAttribute($app, 'garantia', 'Garantía', [], ['type' => 'text']);
    $ancho = aifecAttribute($app, 'ancho', 'Ancho', [], ['type' => 'number', 'unit' => 'cm']);
    $montaje = aifecAttribute($app, 'montaje', 'Montaje', [], ['type' => 'bool']);
    $acabados = aifecAttribute($app, 'acabados', 'Acabados', ['barniz', 'cera'], ['type' => 'multiselect']);
    $family = prodFamily($app, 'Muebles', [$material, $garantia, $ancho, $montaje, $acabados]);
    $id = prodMake($web, 'Silla', ['family_id' => (string) $family]);

    $edit = $web->get('/admin/r/products/' . $id)->body;
    contains('<select', $edit);
    contains('name="f[attr_' . $material . ']"', $edit);
    contains('name="f[attr_' . $garantia . '][en]"', $edit, 'el texto va por idioma');
    contains('name="f[attr_' . $acabados . '][]"', $edit, 'varias opciones son casillas');
    contains('>cm<', $edit, 'el número lleva su unidad');
    contains('name="f[attrs_family]" value="' . $family . '"', $edit, 'la pantalla dice de qué tipo son los campos');

    $form = prodForm(['name' => ['es' => 'Silla', 'en' => ''], 'family_id' => (string) $family, 'attrs_family' => (string) $family,
        'attr_' . $material => (string) prodValue($app, $material, 'pino'), 'attr_' . $garantia => ['es' => '3 años', 'en' => ''], 'attr_' . $ancho => '', 'attr_' . $montaje => '0']);
    same(302, prodSave($web, $form, $id)->status);
    $rows = $app->db()->select('SELECT attribute_id, value_id, lang, value_text, value_number FROM {product_attributes} WHERE product_id = ? ORDER BY attribute_id', [$id]);
    $byAttribute = [];
    foreach ($rows as $row) {
        $byAttribute[(int) $row['attribute_id']] = $row;
    }
    same((string) prodValue($app, $material, 'pino'), (string) $byAttribute[$material]['value_id']);
    same('es', $byAttribute[$garantia]['lang']);
    same('3 años', $byAttribute[$garantia]['value_text']);
    same('0', (string) (int) $byAttribute[$montaje]['value_number'], 'un «No» se guarda');
    check(!isset($byAttribute[$ancho]), 'un número vacío no se guarda');
    check(!isset($byAttribute[$acabados]), 'sin casillas marcadas no hay valores');

    // Un valor que no es del atributo se rechaza.
    $form['attr_' . $material] = '999999';
    same(422, prodSave($web, $form, $id)->status);
});

test('la categoría principal tiene que estar entre las elegidas y sin categorías no hay principal', function (): void {
    $app = prodApp();
    $web = adminClient($app);
    $a = aifecCategory($app, 'A');
    $b = aifecCategory($app, 'B');
    $c = aifecCategory($app, 'C');
    $id = prodMake($web, 'Silla', ['categories' => [(string) $a, (string) $b], 'main_category' => (string) $c]);
    $rows = $app->db()->select('SELECT category_id, is_main FROM {product_categories} WHERE product_id = ? ORDER BY position', [$id]);
    same(2, count($rows));
    same('1', (string) $rows[0]['is_main'], 'si la principal no está entre las elegidas, lo es la primera');
    same('0', (string) $rows[1]['is_main']);

    prodSave($web, prodForm(['name' => ['es' => 'Silla', 'en' => ''], 'categories' => [(string) $a, (string) $b], 'main_category' => (string) $b]), $id);
    same((string) $b, (string) $app->db()->value('SELECT category_id FROM {product_categories} WHERE product_id = ? AND is_main = 1', [$id]));

    prodSave($web, prodForm(['name' => ['es' => 'Silla', 'en' => ''], 'main_category' => (string) $b]), $id);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {product_categories} WHERE product_id = ?', [$id]), 'una casilla sin marcar no viaja: quedan sin categorías');
});

test('los productos piden permiso para verse y para cambiarse', function (): void {
    $app = prodApp();
    $admin = adminClient($app);
    $id = prodMake($admin, 'Silla', ['status' => 'published']);

    $support = adminClient($app, 'support');
    $token = aifecToken($support);
    same(403, $support->get('/admin/r/products')->status);
    same(403, $support->get('/admin/r/products/new')->status);
    same(403, $support->get('/admin/r/products/' . $id)->status);
    same(403, $support->post('/admin/r/products/save', ['_token' => $token, 'f' => prodForm()])->status);
    same(403, $support->post('/admin/r/products/' . $id . '/delete', ['_token' => $token])->status);
    same(403, $support->post('/admin/r/products/bulk', ['_token' => $token, 'action' => 'publish', 'ids' => [(string) $id]])->status);
    same(403, $support->post('/admin/catalog/products/' . $id . '/duplicate', ['_token' => $token])->status);

    $viewer = prodViewer($app);
    $token = aifecToken($viewer);
    $r = $viewer->get('/admin/r/products');
    same(200, $r->status);
    contains('Silla', $r->body);
    notContains('/admin/r/products/new', $r->body);
    same(403, $viewer->get('/admin/r/products/new')->status);
    same(403, $viewer->post('/admin/r/products/' . $id . '/save', ['_token' => $token, 'f' => prodForm(['name' => ['es' => 'Cambiada', 'en' => '']])])->status);
    same(403, $viewer->post('/admin/catalog/products/' . $id . '/duplicate', ['_token' => $token])->status);
    same('Silla', $app->db()->value('SELECT name FROM {product_i18n} WHERE product_id = ?', [$id]), 'no se tocó');
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {products}'));
});
