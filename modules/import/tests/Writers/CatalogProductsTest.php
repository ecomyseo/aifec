<?php
/**
 * AIFEC — escritor de productos: lo que devuelve catalog->product() tras importar el producto de ejemplo coincide campo a campo con lo esperado;
 * los precios se convierten a como se escriben aquí; las variantes, las imágenes, los datos, las etiquetas y los relacionados llegan; lo que ya
 * existía no se duplica; una referencia que falta no tumba el producto y un registro que revienta no deja filas a medias.
 *
 * Las cifras: con el 21 % de la clase de serie «standard», 10661 sin impuestos son 12900; la oferta de 8900 son 10769; 13140 son 15899.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\HttpResponse;
use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\ProductsWriter;
use Aifec\Modules\Import\Writers\TaxClassesWriter;

require_once __DIR__ . '/WritersKit.php';
require_once __DIR__ . '/../../../catalog/tests/helpers.php';

/** El producto de ejemplo con cambios; sin direcciones antiguas (para que su dirección sea la nueva) salvo que se pidan. */
function wrProduct(array $over = []): array
{
    return wr('products', $over + ['old_paths' => []]);
}

/** Un producto sencillo (sin variantes ni imágenes) con precio y existencias. */
function wrSimple(array $over = []): array
{
    return wrProduct($over + [
        'id' => '201', 'sku' => 'SENCILLO', 'name' => ['es' => 'Producto sencillo'], 'summary' => [], 'description' => [], 'slug' => ['es' => 'producto-sencillo'],
        'price' => 8264, 'price_includes_tax' => false, 'compare_price' => null, 'sale_price' => null, 'sale_from' => null, 'sale_to' => null, 'cost' => null,
        'variants' => [], 'images' => [], 'related_ids' => [], 'attributes' => [], 'tags' => [], 'meta_title' => [], 'meta_description' => [], 'extra' => [],
    ]);
}

/** Dos imágenes con fichero (la segunda es la de portada) dentro de una carpeta nueva; devuelve la carpeta. */
function wrImages(): string
{
    $dir = tmpDir();
    wrPng($dir, 'p/1.png', 4);
    wrPng($dir, 'p/2.png', 5);

    return $dir;
}

/** Un sitio con lo que el producto necesita ya escrito (marca, categorías, atributos, clase de impuesto) y su contexto. */
function wrProductSite(array $settings = [], array $options = [], string $filesDir = ''): array
{
    $app = writersApp($settings);
    $ctx = writersContext($app, $options, [], $filesDir);
    wrCatalogOrigin($app, $ctx);
    $ctx->unmapped = [];
    $ctx->notes = [];

    return [$app, $ctx, new ProductsWriter($app)];
}

test('el producto de ejemplo se escribe completo y catalog->product() lo devuelve campo a campo', function (): void {
    $dir = wrImages();
    list($app, $ctx, $writer) = wrProductSite(['i18n.languages' => ['es', 'en']], [], $dir);
    $record = wrProduct(['images' => [['path' => 'p/1.png', 'alt' => ['es' => 'Mesa auxiliar de roble'], 'position' => 0, 'cover' => false], ['path' => 'p/2.png', 'position' => 1, 'cover' => true]]]);
    $out = $writer->write($record, $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $id = (int) $out['id'];
    $catalog = aifecCatalog($app);
    $p = $catalog->product($id, 'es');

    same('Mesa auxiliar de roble', $p['name']);
    same('Oak side table', $catalog->product($id, 'en')['name'], 'el nombre en inglés');
    same('<p>Redonda, de una pata.</p>', $p['summary']);
    same('<p>Roble macizo con acabado al agua.</p>', $p['description']);
    same(['MESA-ROBLE', '8400000000017', 'physical'], [$p['sku'], $p['gtin'], $p['kind']]);
    same('Nordika', $p['brand']['name']);
    same(['Mesas'], array_column($p['categories'], 'name'));
    same('Mesas', $p['main_category']['name']);
    same(['auxiliar', 'roble'], $p['tags'], 'las etiquetas, por orden alfabético');
    same([6500, 1, 400, 400, 480], [$p['weight'], $p['min_qty'], (int) $p['dimensions']['length'], (int) $p['dimensions']['width'], (int) $p['dimensions']['height']]);
    same(['Mesa auxiliar de roble macizo', 'Mesa auxiliar redonda de roble.'], [$p['seo']['title'], $p['seo']['description']]);
    contains('producto/mesa-auxiliar-roble', $p['url']);
    same(true, $p['has_variants']);
    same(2, count($p['images']), 'las dos imágenes');
    same('Mesa auxiliar de roble', $p['images'][0]['alt'] !== '' ? 'Mesa auxiliar de roble' : '', 'la de portada va primero (esa no lleva texto alternativo: se rellena con el nombre)');

    $names = array_column($p['attributes'], 'value', 'name');
    same('Roble macizo', $names['Material']);
    same('48 cm', $names['Altura']);
    same(48.0, array_column($p['attributes'], 'number', 'name')['Altura']);
});

test('las variantes llegan con su etiqueta, su precio final, sus existencias y su imagen', function (): void {
    $dir = wrImages();
    list($app, $ctx, $writer) = wrProductSite([], [], $dir);
    $record = wrProduct(['images' => [['path' => 'p/1.png', 'position' => 0, 'cover' => true], ['path' => 'p/2.png', 'position' => 1]]]);
    $id = (int) $writer->write($record, $ctx)['id'];
    $p = aifecCatalog($app)->product($id, 'es');

    same(2, count($p['variants']));
    contains('Pequeña', $p['variants'][0]['label']);
    contains('Grande', $p['variants'][1]['label']);
    same([12900, 15899], [$p['variants'][0]['price']['amount'], $p['variants'][1]['price']['amount']], 'precio final de cada variante, con el 21 % dentro');
    same([9, 5], [$p['variants'][0]['availability']['qty'], $p['variants'][1]['availability']['qty']]);
    same(['MESA-ROBLE-P', 'MESA-ROBLE-G'], [$p['variants'][0]['sku'], $p['variants'][1]['sku']]);
    $images = array_column($p['images'], 'id');
    same([$images[0], $images[1]], [$p['variants'][0]['image_id'], $p['variants'][1]['image_id']], 'cada variante con la imagen de su posición');
    same(['size' => ['pequena', 'grande']], array_map(static function (array $o): array {
        return array_column($o['values'], 'code');
    }, array_column($p['options'], null, 'code')), 'las opciones para elegir');
    same(2, wrCount($app, 'import_map', "type = 'variants'"), 'las variantes quedan casadas por su identificador de origen');
    same(1, $ctx->localId('variants', '501') > 0 ? 1 : 0);
});

test('repetir el producto no cambia ninguna tabla; cambiarlo en el origen lo actualiza en su sitio', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $record = wrProduct();
    $id = (int) $writer->write($record, $ctx)['id'];
    $counts = wrCounts($app);

    $again = $writer->write($record, $ctx);
    same(TypeWriter::UNCHANGED, $again['result']);
    same($id, $again['id']);
    same($counts, wrCounts($app), 'ni una fila nueva en ninguna tabla');

    $changed = $writer->write(wrProduct(['name' => ['es' => 'Mesa de roble']]), $ctx);
    same(TypeWriter::UPDATED, $changed['result']);
    same($counts, wrCounts($app), 'se actualiza en su sitio');
    same('Mesa de roble', aifecCatalog($app)->product($id, 'es')['name'], 'y la caché del producto se vació: se ve el nombre nuevo');
});

test('un producto editado aquí después de traerlo choca y no se toca; overwrite_local lo pisa', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $id = (int) $writer->write(wrProduct(), $ctx)['id'];
    $app->clock()->advance(300);
    $app->db()->update('products', ['featured' => 0, 'weight' => 1234, 'updated_at' => $app->clock()->sql()], ['id' => $id]);
    $app->clock()->advance(300);

    $newer = wrProduct(['weight' => 7000]);
    $out = $writer->write($newer, $ctx);
    same(TypeWriter::CONFLICT, $out['result']);
    same(1234, (int) wrValue($app, 'SELECT weight FROM {products} WHERE id = ?', [$id]), 'el dato de aquí queda intacto');

    $over = writersContext($app, ['overwrite_local' => true]);
    same(TypeWriter::UPDATED, $writer->write($newer, $over)['result']);
    same(7000, (int) wrValue($app, 'SELECT weight FROM {products} WHERE id = ?', [$id]));
});

test('precios: sin impuestos en el origen y con impuestos aquí (82,64 son 99,99), con y con, y con impuestos aquí sin ellos', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $net = (int) $writer->write(wrSimple(['price' => 8264, 'price_includes_tax' => false]), $ctx)['id'];
    $both = (int) $writer->write(wrSimple(['id' => '202', 'sku' => 'S2', 'name' => ['es' => 'Con impuestos'], 'slug' => ['es' => 'con-impuestos'], 'price' => 10000, 'price_includes_tax' => true]), $ctx)['id'];
    same(9999, (int) wrValue($app, 'SELECT price FROM {products} WHERE id = ?', [$net]), '8264 sin impuestos, con el 21 %: 8264 + 1735 = 9999');
    same(10000, (int) wrValue($app, 'SELECT price FROM {products} WHERE id = ?', [$both]), 'los dos con impuestos: el mismo importe');

    $off = writersApp(['tax.prices_include_tax' => false]);
    $offCtx = writersContext($off);
    wrCatalogOrigin($off, $offCtx);
    $offWriter = new ProductsWriter($off);
    $a = (int) $offWriter->write(wrSimple(['price' => 10000, 'price_includes_tax' => true]), $offCtx)['id'];
    $b = (int) $offWriter->write(wrSimple(['id' => '202', 'sku' => 'S2', 'name' => ['es' => 'Sin'], 'slug' => ['es' => 'sin'], 'price' => 8264, 'price_includes_tax' => false]), $offCtx)['id'];
    same(8264, (int) wrValue($off, 'SELECT price FROM {products} WHERE id = ?', [$a]), 'aquí sin impuestos: 10000 con el 21 % son 8264');
    same(8264, (int) wrValue($off, 'SELECT price FROM {products} WHERE id = ?', [$b]), 'los dos sin impuestos: el mismo importe');
});

test('precio anterior, oferta, coste y variantes se convierten igual que el precio; una clase reducida usa su tipo', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    (new TaxClassesWriter($app))->write(wr('tax_classes', ['id' => '2', 'code' => 'reduced', 'name' => 'Reducido', 'is_default' => false]), $ctx);
    $id = (int) $writer->write(wrSimple(['tax_class_id' => '2', 'price' => 1000, 'compare_price' => 2000, 'sale_price' => 500, 'cost' => 400]), $ctx)['id'];
    $row = $app->db()->find('products', ['id' => $id]);

    same([1100, 2200, 550, 440], [(int) $row['price'], (int) $row['compare_price'], (int) $row['sale_price'], (int) $row['cost']], 'con el 10 % de la clase reducida');
    same((int) wrValue($app, "SELECT id FROM {tax_classes} WHERE code = 'reduced'"), (int) $row['tax_class_id']);

    $withVariants = (int) $writer->write(wrProduct(['id' => '300', 'sku' => 'V300', 'name' => ['es' => 'Con variantes'], 'slug' => ['es' => 'con-variantes'], 'images' => [], 'variants' => [
        ['id' => '900', 'sku' => 'V300-A', 'price' => 10000, 'compare_price' => 12100, 'cost' => 6000, 'stock' => 3, 'weight' => 0, 'image_index' => null, 'active' => true, 'position' => 0, 'values' => [['attribute_id' => 'a1', 'value_id' => '21']]],
    ]]), $ctx)['id'];
    $variant = $app->db()->row('SELECT * FROM {variants} WHERE product_id = ?', [$withVariants]);
    same([12100, 14641, 7260], [(int) $variant['price'], (int) $variant['compare_price'], (int) $variant['cost']]);
    same(null, $variant['weight'], 'un peso de 0 es «el del producto»');
});

test('la oferta cuenta según la fecha: vigente, todavía no empezada o ya acabada', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $catalog = aifecCatalog($app);
    $mk = function (string $id, ?string $from, ?string $to) use ($writer, $ctx, $app, $catalog): array {
        $out = $writer->write(wrSimple(['id' => $id, 'sku' => 'O' . $id, 'name' => ['es' => 'Oferta ' . $id], 'slug' => ['es' => 'oferta-' . $id], 'price' => 10000, 'price_includes_tax' => true, 'sale_price' => 7000, 'sale_from' => $from, 'sale_to' => $to]), $ctx);

        return $catalog->product((int) $out['id'], 'es')['price'];
    };
    $live = $mk('1', '2026-11-01T00:00:00Z', '2026-12-01T00:00:00Z');
    same([7000, 10000, true], [$live['amount'], $live['compare'], $live['on_sale']]);
    $future = $mk('2', '2026-12-01T00:00:00Z', null);
    same([10000, null, false], [$future['amount'], $future['compare'], $future['on_sale']], 'empieza en diciembre: hoy no');
    $over = $mk('3', null, '2026-11-05T00:00:00Z');
    same([10000, false], [$over['amount'], $over['on_sale']], 'acabó el 5 de noviembre');
    same('2026-11-01 00:00:00', wrValue($app, 'SELECT sale_from FROM {products} WHERE sku = ?', ['O1']), 'las fechas se guardan como aquí');
});

test('en la segunda importación una variante que ya no viene se desactiva, no se borra, y si vuelve se reactiva', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $record = wrProduct();
    $id = (int) $writer->write($record, $ctx)['id'];
    $second = (int) $ctx->localId('variants', '502');
    same(2, wrCount($app, 'variants', 'product_id = ?', [$id]));

    $fewer = wrProduct(['variants' => [$record['variants'][0]]]);
    same(TypeWriter::UPDATED, $writer->write($fewer, $ctx)['result']);
    same(2, wrCount($app, 'variants', 'product_id = ?', [$id]), 'sigue ahí (puede estar en pedidos)');
    same(0, (int) wrValue($app, 'SELECT active FROM {variants} WHERE id = ?', [$second]));
    same(1, count(aifecCatalog($app)->product($id, 'es')['variants']), 'la tienda enseña una sola');

    $writer->write(wrProduct(['name' => ['es' => 'Mesa auxiliar de roble (otra vez)']]), $ctx);
    same(1, (int) wrValue($app, 'SELECT active FROM {variants} WHERE id = ?', [$second]), 'al volver, se reactiva');
    same(2, wrCount($app, 'variants', 'product_id = ?', [$id]), 'y sin duplicarse');
});

test('un producto con variantes sin ningún atributo de variante resoluble entra como sencillo y con su nota', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $broken = wrProduct(['variants' => [
        ['id' => '501', 'sku' => 'X-1', 'price' => 10661, 'stock' => 9, 'active' => true, 'position' => 0, 'values' => [['attribute_id' => 'no-vino', 'value_id' => '1']]],
    ], 'images' => []]);
    $out = $writer->write($broken, $ctx);

    same(TypeWriter::CREATED, $out['result']);
    same(0, wrCount($app, 'variants'));
    same(0, (int) wrValue($app, 'SELECT has_variants FROM {products} WHERE id = ?', [$out['id']]));
    check(count(array_filter($ctx->noteTexts('products'), static function (string $text): bool {
        return strpos($text, 'brought as a simple one') !== false;
    })) === 1, 'se apunta');
});

test('un producto que ya existía aquí con ese sku (único) se casa en vez de duplicarse; con dos con ese sku, no', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $mine = aifecProduct($app, ['sku' => 'MESA-ROBLE', 'name' => 'Mi mesa']);
    $products = wrCount($app, 'products');
    $out = $writer->write(wrProduct(), $ctx);

    same(TypeWriter::UPDATED, $out['result'], 'casar por lo natural se trata como actualización');
    same($mine, $out['id']);
    same($products, wrCount($app, 'products'));
    same('Mesa auxiliar de roble', aifecCatalog($app)->product($mine, 'es', true)['name']);

    $twice = writersApp();
    $c2 = writersContext($twice);
    wrCatalogOrigin($twice, $c2);
    aifecProduct($twice, ['sku' => 'MESA-ROBLE', 'name' => 'Una']);
    aifecProduct($twice, ['sku' => 'MESA-ROBLE', 'name' => 'Otra']);
    $before = wrCount($twice, 'products');
    same(TypeWriter::CREATED, (new ProductsWriter($twice))->write(wrProduct(), $c2)['result'], 'con el sku repetido aquí no se sabe cuál es: se crea');
    same($before + 1, wrCount($twice, 'products'));
});

test('una categoría o una marca que no vino no tumba el producto: entra sin ellas y se apunta', function (): void {
    $app = writersApp();
    $ctx = writersContext($app);
    $writer = new ProductsWriter($app);
    $out = $writer->write(wrSimple(['brand_id' => 'no-vino', 'category_ids' => ['no-vino', 'otra'], 'main_category_id' => 'no-vino', 'tax_class_id' => 'tampoco']), $ctx);

    same(TypeWriter::CREATED, $out['result']);
    same(null, wrValue($app, 'SELECT brand_id FROM {products} WHERE id = ?', [$out['id']]));
    same(0, wrCount($app, 'product_categories', 'product_id = ?', [$out['id']]));
    $texts = implode(' | ', $ctx->noteTexts('products'));
    contains('brand', $texts);
    contains('categories', $texts);
    contains('tax class', $texts);
});

test('un registro que revienta a mitad se devuelve «skipped» con su motivo y no deja filas a medias', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $counts = wrCounts($app);
    $app->db()->pdo()->exec('DROP TABLE af_product_tags');
    $out = $writer->write(wrProduct(), $ctx);

    same(TypeWriter::SKIPPED, $out['result']);
    same(null, $out['id']);
    contains('could not be written', $out['reason']);
    same(0, wrCount($app, 'products'), 'ni el producto');
    same(0, wrCount($app, 'product_i18n'), 'ni sus textos');
    same(0, wrCount($app, 'variants'), 'ni sus variantes');
    same(0, wrCount($app, 'import_map', "type IN ('products', 'variants')"), 'ni su pareja');
    unset($counts['af_product_tags']);
    $after = wrCounts($app);
    unset($after['af_product_tags']);
    same($counts, $after, 'ninguna otra tabla cambió');
});

test('nada de existencias ni precios negativos: se dejan en 0 y se apunta', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $out = $writer->write(wrSimple(['price' => -500, 'sale_price' => -1, 'stock' => -4]), $ctx);
    $row = $app->db()->find('products', ['id' => $out['id']]);

    same([0, 0, 0], [(int) $row['price'], (int) $row['sale_price'], (int) $row['stock']]);
    $texts = $ctx->noteTexts('products');
    same(1, count(array_filter($texts, static function (string $text): bool {
        return strpos($text, 'negative price') !== false;
    })), 'una nota por registro y no una por cada importe');
    same(1, count(array_filter($texts, static function (string $text): bool {
        return strpos($text, 'negative stock') !== false;
    })));
    same(0, wrCount($app, 'stock_moves'), 'y sin existencias no hay movimiento');
});

test('el HTML peligroso de una descripción llega saneado y el «&» de un nombre se conserva', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $out = $writer->write(wrSimple([
        'name' => ['es' => 'Mesas & sillas'], 'description' => ['es' => '<p onclick="x()">Hola <b>mundo</b></p><script>alert(1)</script><iframe src="https://malo.test"></iframe>'],
    ]), $ctx);
    $row = $app->db()->row('SELECT name, description FROM {product_i18n} WHERE product_id = ?', [$out['id']]);

    same('Mesas & sillas', $row['name']);
    contains('<b>mundo</b>', (string) $row['description']);
    notContains('<script', (string) $row['description']);
    notContains('onclick', (string) $row['description']);
    notContains('malo.test', (string) $row['description']);
});

test('un idioma que el sitio no tiene se cuenta una sola vez por producto y no se escribe', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $writer->write(wrSimple(['name' => ['es' => 'Uno', 'fr' => 'Un', 'en' => 'One'], 'description' => ['es' => '<p>x</p>', 'fr' => '<p>y</p>', 'en' => '<p>z</p>'], 'tags' => ['es' => ['a'], 'fr' => ['b']]]), $ctx);

    same(1, $ctx->unmappedCount('language', 'fr'));
    same(1, $ctx->unmappedCount('language', 'en'));
    same(1, wrCount($app, 'product_i18n'), 'solo el español');
    same(1, wrCount($app, 'tags'));

    $writer->write(wrSimple(['id' => '202', 'sku' => 'S2', 'slug' => ['es' => 'dos'], 'name' => ['es' => 'Dos', 'fr' => 'Deux']]), $ctx);
    same(2, $ctx->unmappedCount('language', 'fr'), 'un producto más con francés: dos en total');
});

test('las imágenes van en su orden con la de portada primero y repetir no las duplica en la biblioteca', function (): void {
    $dir = wrImages();
    list($app, $ctx, $writer) = wrProductSite([], [], $dir);
    $record = wrProduct(['variants' => [], 'images' => [['path' => 'p/1.png', 'position' => 0, 'cover' => false], ['path' => 'p/2.png', 'position' => 1, 'cover' => true]]]);
    $id = (int) $writer->write($record, $ctx)['id'];

    $order = array_map('intval', $app->db()->column('SELECT media_id FROM {product_media} WHERE product_id = ? ORDER BY position', [$id]));
    same(2, count($order));
    same($order[0], (int) wrValue($app, 'SELECT main_media_id FROM {products} WHERE id = ?', [$id]));
    $cover = $ctx->localId('_image', md5('file:' . str_replace('\\', '/', (string) realpath($dir . '/p/2.png'))));
    same($cover, $order[0], 'la de portada (la segunda del registro) es la primera');
    $media = wrCount($app, 'media');
    $writer->write(wrProduct(['variants' => [], 'images' => $record['images'], 'weight' => 6600]), $ctx);
    same($media, wrCount($app, 'media'), 'repetir no baja otra vez las imágenes');
    same(2, wrCount($app, 'product_media', 'product_id = ?', [$id]));
});

test('si las imágenes no se pueden traer el producto entra igual y se apunta; con import_images apagado no hay aviso', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $out = $writer->write(wrProduct(['variants' => []]), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    same(0, wrCount($app, 'product_media'));
    check(count(array_filter($ctx->noteTexts('products'), static function (string $text): bool {
        return strpos($text, 'images of the product could not be brought') !== false;
    })) === 1, 'se apunta una vez');

    list($quiet, $quietCtx, $quietWriter) = wrProductSite([], ['import_images' => false]);
    $quietWriter->write(wrProduct(['variants' => []]), $quietCtx);
    same([], array_filter($quietCtx->noteTexts('products'), static function (string $text): bool {
        return strpos($text, 'image') !== false;
    }), 'si no se piden, no se avisa');
});

test('la dirección antigua responde 200 con keep_urls y redirige (301) sin él', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $id = (int) $writer->write(wr('products', ['variants' => []]), $ctx)['id'];
    $client = new TestClient($app);
    $old = $client->get('/mesas/101-mesa-auxiliar-roble.html');
    same(200, $old->status, 'con keep_urls la dirección antigua sirve la ficha');
    same('mesas/101-mesa-auxiliar-roble.html', $app->slugs()->path('product', $id, 'es'));

    list($other, $otherCtx, $otherWriter) = wrProductSite([], ['keep_urls' => false]);
    $otherId = (int) $otherWriter->write(wr('products', ['variants' => []]), $otherCtx)['id'];
    $moved = (new TestClient($other))->get('/mesas/101-mesa-auxiliar-roble.html');
    same(301, $moved->status, 'sin keep_urls redirige a la nueva');
    contains('/producto/mesa-auxiliar-roble', $moved->headers['Location']);
    same('producto/mesa-auxiliar-roble', $other->slugs()->path('product', $otherId, 'es'));
});

test('los relacionados se ponen en la segunda pasada, cuando ya están todos los productos', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $a = wrProduct(['variants' => [], 'related_ids' => ['102'], 'upsell_ids' => ['102'], 'cross_sell_ids' => ['999']]);
    $b = wrSimple(['id' => '102', 'sku' => 'MESA-DOS', 'name' => ['es' => 'Mesa dos'], 'slug' => ['es' => 'mesa-dos'], 'related_ids' => []]);
    $idA = (int) $writer->write($a, $ctx)['id'];
    $idB = (int) $writer->write($b, $ctx)['id'];
    same(0, wrCount($app, 'product_relations'), 'en la primera pasada aún no');

    $writer->link($a, $ctx);
    $writer->link($b, $ctx);
    same([[$idB, 'related'], [$idB, 'upsell']], array_map(static function (array $r): array {
        return [(int) $r['related_id'], (string) $r['kind']];
    }, $app->db()->select('SELECT related_id, kind FROM {product_relations} WHERE product_id = ? ORDER BY kind', [$idA])));
    same([$idB], aifecCatalog($app)->product($idA, 'es')['related']);
    check(count(array_filter($ctx->noteTexts('products'), static function (string $text): bool {
        return strpos($text, 'related products were not brought') !== false;
    })) === 1, 'el relacionado que no vino se apunta');
    $writer->link($a, $ctx);
    same(2, wrCount($app, 'product_relations', 'product_id = ?', [$idA]), 'repetir la segunda pasada no duplica');
});

test('las existencias se apuntan como movimientos «import» y no disparan el aviso de stock', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $fired = 0;
    $app->hooks()->on('stock.changed', static function () use (&$fired): void {
        ++$fired;
    });
    $id = (int) $writer->write(wrSimple(['stock' => 12]), $ctx)['id'];
    $writer->write(wrSimple(['stock' => 7]), $ctx);

    same([12, -5], array_map('intval', $app->db()->column('SELECT delta FROM {stock_moves} WHERE product_id = ? ORDER BY id', [$id])));
    same(['import', 'import'], array_map('strval', $app->db()->column('SELECT reason FROM {stock_moves} WHERE product_id = ? ORDER BY id', [$id])));
    same(7, (int) wrValue($app, 'SELECT stock FROM {products} WHERE id = ?', [$id]));
    same(0, $fired, 'importar existencias no avisa a nadie');
});

test('un producto digital baja su fichero de su dirección y, si no se puede, entra igual y se apunta', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $app->http()->when('GET', 'tienda.test/descargas/manual.pdf', static function () {
        return new HttpResponse(200, [], "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n");
    });
    $files = [['name' => 'manual.pdf', 'url' => 'https://tienda.test/descargas/manual.pdf', 'version' => '2.1', 'bytes' => 30], ['name' => 'roto.zip', 'url' => 'https://tienda.test/descargas/no-existe.zip'], ['name' => 'local.zip', 'path' => '/etc/passwd']];
    $out = $writer->write(wrSimple(['kind' => 'digital', 'files' => $files]), $ctx);

    same(TypeWriter::CREATED, $out['result']);
    same(1, wrCount($app, 'product_files', 'product_id = ?', [$out['id']]), 'solo el que se pudo bajar');
    $row = $app->db()->row('SELECT * FROM {product_files} WHERE product_id = ?', [$out['id']]);
    same(['manual.pdf', '2.1'], [$row['name'], $row['version']]);
    check((int) $row['bytes'] > 0 && strpos((string) $row['path'], 'products/') === 0, 'en la carpeta privada del catálogo');
    same(2, count(array_filter($ctx->noteTexts('products'), static function (string $text): bool {
        return strpos($text, 'could not be brought') !== false;
    })), 'los otros dos quedan apuntados');
    $writer->write(wrSimple(['kind' => 'digital', 'files' => $files, 'weight' => 1]), $ctx);
    same(1, wrCount($app, 'product_files', 'product_id = ?', [$out['id']]), 'repetir no lo duplica');
});

test('un producto privado entra como borrador y se cuenta; la visibilidad y el destacado se copian', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $private = $writer->write(wrSimple(['status' => 'private', 'visibility' => 'hidden', 'featured' => true]), $ctx);
    $row = $app->db()->find('products', ['id' => $private['id']]);

    same(['draft', 'hidden', 1], [$row['status'], $row['visibility'], (int) $row['featured']]);
    same(1, $ctx->unmappedCount('feature', 'Private products'));
    $published = $writer->write(wrSimple(['id' => '202', 'sku' => 'P2', 'slug' => ['es' => 'p2'], 'status' => 'published', 'visibility' => 'search', 'published_at' => '2025-02-12T09:00:00Z']), $ctx);
    $row = $app->db()->find('products', ['id' => $published['id']]);
    same(['published', 'search', '2025-02-12 09:00:00'], [$row['status'], $row['visibility'], $row['published_at']]);
});

test('el recuento de productos de cada categoría (y el de sus madres) se actualiza como en el panel', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $writer->write(wrProduct(['variants' => []]), $ctx);
    $mesas = (int) $ctx->localId('categories', '12');
    $muebles = (int) $ctx->localId('categories', '2');

    same(1, (int) wrValue($app, 'SELECT product_count FROM {categories} WHERE id = ?', [$mesas]));
    same(1, (int) wrValue($app, 'SELECT product_count FROM {categories} WHERE id = ?', [$muebles]), 'la madre cuenta los de sus hijas');
    $writer->write(wrProduct(['variants' => [], 'status' => 'draft']), $ctx);
    same(0, (int) wrValue($app, 'SELECT product_count FROM {categories} WHERE id = ?', [$mesas]), 'un borrador no cuenta');
});

test('el extra del origen se guarda en products.extra bajo «import», sin secretos, junto a lo que ya hubiera', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $id = (int) $writer->write(wrProduct(['variants' => [], 'reviews_allowed' => false]), $ctx)['id'];
    $extra = json_decode((string) wrValue($app, 'SELECT extra FROM {products} WHERE id = ?', [$id]), true);

    same(['ecotax' => 0, 'supplier_reference' => 'NRD-44', 'reviews_allowed' => false], $extra['import']);
    $app->db()->update('products', ['extra' => json_encode($extra + ['mio' => ['label' => 'Mío', 'value' => 'x', 'type' => 'text', 'public' => true]])], ['id' => $id]);
    $app->clock()->advance(300);
    $writer->write(wrProduct(['variants' => [], 'reviews_allowed' => false, 'extra' => ['ecotax' => 1]]), writersContext($app, ['overwrite_local' => true]));
    $after = json_decode((string) wrValue($app, 'SELECT extra FROM {products} WHERE id = ?', [$id]), true);
    same(['ecotax' => 1, 'reviews_allowed' => false], $after['import']);
    same('x', $after['mio']['value'], 'lo que ya había no se pierde');
});

test('la comprobación final cuenta productos, variantes y existencias de lo traído', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $writer->write(wrProduct(), $ctx);
    $writer->write(wrSimple(['stock' => 20]), $ctx);

    same(['products_count' => 2, 'variants_count' => 2, 'stock_total' => 9 + 5 + 20], $writer->checksums($ctx), 'las existencias de un producto con variantes son las de sus variantes');
});

test('simulacro de productos: se decide lo mismo y no cambia ninguna tabla', function (): void {
    list($app, $ctx, $writer) = wrProductSite();
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    $record = wrProduct();

    $out = $writer->write($record, $dry);
    same(TypeWriter::CREATED, $out['result']);
    same(null, $out['id']);
    $writer->link($record, $dry);
    same($counts, wrCounts($app), 'todas las tablas, iguales');
    same(0, wrCount($app, 'stock_moves'));
});
