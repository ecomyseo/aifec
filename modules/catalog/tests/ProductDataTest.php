<?php
/**
 * AIFEC — pruebas de ProductData y ProductCard: todas las claves del contrato, textos por idioma, atributos legibles,
 * imágenes, variantes y opciones, quién se ve y quién no, SEO, tarjetas e insignias.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('ProductData lleva todas las claves del contrato, en su orden', function (): void {
    $app = catalogApp();
    $product = aifecCatalog($app)->product(aifecProduct($app));
    same([
        'id', 'kind', 'sku', 'gtin', 'mpn', 'name', 'url', 'url_abs', 'summary', 'description', 'facts', 'faqs', 'brand', 'categories',
        'main_category', 'tags', 'images', 'image', 'price', 'availability', 'badge_list', 'has_variants', 'options', 'variants', 'attributes',
        'rating', 'weight', 'dimensions', 'min_qty', 'max_qty', 'related', 'seo', 'published_at', 'updated_at', 'extra',
    ], array_keys($product));
    same(['length', 'width', 'height'], array_keys($product['dimensions']));
    same(['title', 'description'], array_keys($product['seo']));
});

test('una ficha completa: textos, direcciones, marca, categorías, etiquetas, preguntas, datos y relacionados', function (): void {
    $app = catalogApp();
    $brand = aifecBrand($app, 'Nórdica');
    $root = aifecCategory($app, 'Sillas');
    $child = aifecCategory($app, 'Oficina', $root);
    $related = aifecProduct($app, ['name' => 'Cojín']);
    $draft = aifecProduct($app, ['name' => 'Borrador', 'status' => 'draft']);
    $id = aifecProduct($app, [
        'name' => 'Silla Ergo', 'summary' => '<p>Cómoda &amp; ligera</p>', 'description' => '<p>Respaldo de malla.</p>',
        'facts' => [['label' => 'Material', 'value' => 'Roble macizo'], ['label' => '', 'value' => 'sin etiqueta']],
        'sku' => 'SILLA-1', 'gtin' => '8412345678905', 'mpn' => 'E-100', 'brand_id' => $brand, 'weight' => 7500,
        'length' => 600, 'width' => 550, 'height' => 900, 'min_qty' => 2, 'max_qty' => 6,
        'categories' => [$child, $root], 'tags' => ['ergonómica', 'oficina'], 'related' => [$related, $draft, 99999],
        'faqs' => [['question' => '¿Lleva ruedas?', 'answer' => 'Sí, de goma.']],
    ]);
    $p = aifecCatalog($app)->product($id);
    same($id, $p['id']);
    same('physical', $p['kind']);
    same('Silla Ergo', $p['name']);
    same('/producto/silla-ergo', $p['url']);
    same('https://tienda.test/producto/silla-ergo', $p['url_abs']);
    same('<p>Cómoda &amp; ligera</p>', $p['summary']);
    same('<p>Respaldo de malla.</p>', $p['description']);
    same('SILLA-1', $p['sku']);
    same('8412345678905', $p['gtin']);
    same('E-100', $p['mpn']);
    same([['label' => 'Material', 'value' => 'Roble macizo']], $p['facts'], 'un dato sin etiqueta se descarta');
    same([['question' => '¿Lleva ruedas?', 'answer' => 'Sí, de goma.']], $p['faqs']);
    same(['id' => $brand, 'name' => 'Nórdica', 'url' => '/marca/nordica'], $p['brand']);
    same([['id' => $child, 'name' => 'Oficina', 'url' => '/categoria/sillas/oficina'], ['id' => $root, 'name' => 'Sillas', 'url' => '/categoria/sillas']], $p['categories'], 'la principal primero');
    same($child, $p['main_category']['id']);
    same(['ergonómica', 'oficina'], $p['tags']);
    same([$related], $p['related'], 'solo relacionados que se ven');
    same(7500, $p['weight']);
    same(['length' => 600, 'width' => 550, 'height' => 900], $p['dimensions']);
    same(2, $p['min_qty']);
    same(6, $p['max_qty']);
    same(false, $p['has_variants']);
    same([], $p['variants']);
    same([], $p['options']);
    same('2026-11-12 12:00:00', $p['updated_at']);
    same(null, $p['published_at']);
});

test('sin marca, sin categorías y sin nada más, todo sale vacío pero presente', function (): void {
    $app = catalogApp();
    $p = aifecCatalog($app)->product(aifecProduct($app));
    same(null, $p['brand']);
    same([], $p['categories']);
    same(null, $p['main_category']);
    same([], $p['tags']);
    same([], $p['faqs']);
    same([], $p['facts']);
    same([], $p['related']);
    same([], $p['images']);
    same(null, $p['image']);
    same([], $p['attributes']);
    same(null, $p['rating']);
    same(null, $p['sku']);
    same([], $p['extra']);
});

test('textos en otro idioma, y respaldo al principal cuando falta la traducción', function (): void {
    $app = catalogApp(['i18n.languages' => ['es', 'en']]);
    $both = aifecProduct($app, ['name' => 'Silla', 'summary' => 'Cómoda', 'i18n' => ['en' => ['name' => 'Chair', 'summary' => 'Comfortable']]]);
    $onlyEs = aifecProduct($app, ['name' => 'Mesa', 'summary' => 'Robusta']);
    $catalog = aifecCatalog($app);
    same('Chair', $catalog->product($both, 'en')['name']);
    same('Comfortable', $catalog->product($both, 'en')['summary']);
    same('Silla', $catalog->product($both, 'es')['name']);
    same('Mesa', $catalog->product($onlyEs, 'en')['name'], 'sin traducción: el principal');
    same('Robusta', $catalog->product($onlyEs, 'en')['summary']);
    same('/en/producto/chair', $catalog->product($both, 'en')['url'], 'la dirección lleva el prefijo del idioma');
    same('/producto/silla', $catalog->product($both, 'es')['url']);
    same('Silla', $catalog->product($both, 'fr')['name'], 'un idioma que la tienda no tiene: el principal');
});

test('atributos visibles con su valor ya legible: lista, número con unidad, sí/no y varios valores', function (): void {
    $app = catalogApp();
    aifecAttribute($app, 'material', 'Material', ['roble' => 'Roble macizo', 'pino' => 'Pino'], ['position' => 1]);
    aifecAttribute($app, 'ancho', 'Ancho', [], ['type' => 'number', 'unit' => 'cm', 'position' => 2]);
    aifecAttribute($app, 'plegable', 'Plegable', [], ['type' => 'bool', 'position' => 3]);
    aifecAttribute($app, 'colores', 'Colores', ['rojo' => 'Rojo', 'azul' => 'Azul'], ['type' => 'multiselect', 'position' => 4]);
    aifecAttribute($app, 'interno', 'Interno', ['x' => 'X'], ['visible' => 0, 'position' => 5]);
    aifecAttribute($app, 'nota', 'Nota', [], ['type' => 'text', 'position' => 6]);
    $id = aifecProduct($app, ['attributes' => [
        'material' => 'roble', 'ancho' => 12.5, 'plegable' => true, 'colores' => ['rojo', 'azul'], 'interno' => 'x', 'nota' => 'Hecho a mano',
    ]]);
    $attributes = aifecCatalog($app)->product($id)['attributes'];
    same([
        ['code' => 'material', 'name' => 'Material', 'value' => 'Roble macizo', 'type' => 'select', 'number' => null, 'unit' => ''],
        ['code' => 'ancho', 'name' => 'Ancho', 'value' => '12,5 cm', 'type' => 'number', 'number' => 12.5, 'unit' => 'cm'],
        ['code' => 'plegable', 'name' => 'Plegable', 'value' => 'Sí', 'type' => 'bool', 'number' => null, 'unit' => ''],
        ['code' => 'colores', 'name' => 'Colores', 'value' => 'Rojo, Azul', 'type' => 'multiselect', 'number' => null, 'unit' => ''],
        ['code' => 'nota', 'name' => 'Nota', 'value' => 'Hecho a mano', 'type' => 'text', 'number' => null, 'unit' => ''],
    ], $attributes, 'el atributo no visible no sale');
});

test('un atributo sí/no a falso se lee «No», un número entero sin decimales y el valor en inglés si se pide', function (): void {
    $app = catalogApp(['i18n.languages' => ['es', 'en']]);
    aifecAttribute($app, 'plegable', 'Plegable', [], ['type' => 'bool', 'i18n' => ['en' => 'Foldable']]);
    aifecAttribute($app, 'alto', 'Alto', [], ['type' => 'number', 'unit' => 'mm']);
    $id = aifecProduct($app, ['attributes' => ['plegable' => false, 'alto' => 1000]]);
    $catalog = aifecCatalog($app);
    same('No', $catalog->product($id)['attributes'][0]['value']);
    same('1.000 mm', $catalog->product($id)['attributes'][1]['value']);
    $en = $catalog->product($id, 'en')['attributes'];
    same('Foldable', $en[0]['name']);
    same('1,000 mm', $en[1]['value']);
});

test('un borrador, un archivado o uno que se publicará mañana no salen; con $any, sí', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    $draft = aifecProduct($app, ['status' => 'draft']);
    $archived = aifecProduct($app, ['status' => 'archived']);
    $later = aifecProduct($app, ['published_at' => '2026-11-13 00:00:00']);
    $past = aifecProduct($app, ['published_at' => '2026-11-01 00:00:00']);
    same(null, $catalog->product($draft));
    same(null, $catalog->product($archived));
    same(null, $catalog->product($later));
    same($past, $catalog->product($past)['id']);
    same($draft, $catalog->product($draft, null, true)['id']);
    same($later, $catalog->product($later, null, true)['id']);
    same(null, $catalog->product(424242), 'un producto que no existe');
    same(null, $catalog->product(424242, null, true));
});

test('un producto oculto no sale en listados pero su ficha sí se abre', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['visibility' => 'hidden']);
    same($id, aifecCatalog($app)->product($id)['id']);
    same(0, aifecCatalog($app)->listing([])['total']);
});

test('products() devuelve los que se ven, en el orden pedido y sin los que no existen', function (): void {
    $app = catalogApp();
    $a = aifecProduct($app, ['name' => 'A']);
    $b = aifecProduct($app, ['name' => 'B']);
    $c = aifecProduct($app, ['name' => 'C']);
    $draft = aifecProduct($app, ['name' => 'D', 'status' => 'draft']);
    $found = aifecCatalog($app)->products([$c, $a, 99999, $draft, $b, $a]);
    same([$c, $a, $b], array_keys($found));
    same('C', $found[$c]['name']);
});

test('SEO: título y descripción propios, o el nombre y el resumen sin HTML', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    $own = aifecProduct($app, ['name' => 'Silla', 'meta_title' => 'Silla ergonómica | Tienda', 'meta_description' => 'La mejor silla.', 'summary' => 'otro']);
    same(['title' => 'Silla ergonómica | Tienda', 'description' => 'La mejor silla.'], $catalog->product($own)['seo']);
    $plain = aifecProduct($app, ['name' => 'Mesa', 'summary' => '<p>Hola &amp; <b>adiós</b></p><p>Segunda línea</p>']);
    same(['title' => 'Mesa', 'description' => 'Hola & adiós Segunda línea'], $catalog->product($plain)['seo']);
    $long = aifecProduct($app, ['name' => 'Larga', 'summary' => '<p>' . str_repeat('palabra ', 40) . '</p>']);
    $description = $catalog->product($long)['seo']['description'];
    check(mb_strlen($description, 'UTF-8') <= 155, 'recortada a 155 como mucho: ' . mb_strlen($description, 'UTF-8'));
    check(mb_substr($description, -1, 1, 'UTF-8') === '…', 'con puntos suspensivos');
    $noSummary = aifecProduct($app, ['name' => 'Sin resumen', 'description' => '<p>Solo descripción</p>']);
    same('Solo descripción', $catalog->product($noSummary)['seo']['description'], 'sin resumen, la descripción');
    $empty = aifecProduct($app, ['name' => 'Vacía']);
    same('', $catalog->product($empty)['seo']['description']);
});

test('la valoración es la media de rating_sum entre rating_count, o nula', function (): void {
    $app = catalogApp();
    $rated = aifecProduct($app, ['rating_sum' => 23, 'rating_count' => 5]);
    $none = aifecProduct($app, []);
    same(['average' => 4.6, 'count' => 5], aifecCatalog($app)->product($rated)['rating']);
    same(null, aifecCatalog($app)->product($none)['rating']);
});

test('product.extra y product.data: lo que añaden los plugins llega a la ficha', function (): void {
    $app = catalogApp();
    $seen = null;
    $app->hooks()->filter('product.extra', static function (array $extra, array $product) use (&$seen): array {
        $seen = $product['name'];
        $extra['origin'] = ['label' => 'Origen', 'value' => 'España', 'type' => 'text', 'public' => true];

        return $extra;
    });
    $app->hooks()->filter('product.data', static function (array $data): array {
        $data['custom'] = $data['name'] . '!';

        return $data;
    });
    $id = aifecProduct($app, ['name' => 'Taburete', 'extra' => ['peso_envio' => ['label' => 'Peso de envío', 'value' => 3, 'type' => 'number', 'public' => false]]]);
    $p = aifecCatalog($app)->product($id);
    same('Taburete', $seen);
    same(['label' => 'Origen', 'value' => 'España', 'type' => 'text', 'public' => true], $p['extra']['origin']);
    same(['label' => 'Peso de envío', 'value' => 3, 'type' => 'number', 'public' => false], $p['extra']['peso_envio'], 'lo guardado en el producto también sale');
    same('Taburete!', $p['custom']);
});

test('variantes y opciones: valores por código, etiquetas y la imagen de cada una', function (): void {
    $app = catalogApp();
    aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo', 'azul' => 'Azul'], ['variant' => 1, 'position' => 1, 'colors' => ['rojo' => '#ff0000']]);
    aifecAttribute($app, 'talla', 'Talla', ['m' => 'M', 'l' => 'L'], ['variant' => 1, 'position' => 2]);
    $id = aifecProduct($app, ['sku' => 'CAM', 'variants' => [
        ['sku' => 'CAM-R-M', 'stock' => 3, 'values' => ['color' => 'rojo', 'talla' => 'm']],
        ['sku' => 'CAM-R-L', 'stock' => 3, 'values' => ['color' => 'rojo', 'talla' => 'l'], 'media_id' => 77],
        ['sku' => 'CAM-A-M', 'stock' => 0, 'values' => ['color' => 'azul', 'talla' => 'm'], 'label' => 'Edición especial'],
    ]]);
    $p = aifecCatalog($app)->product($id);
    same(true, $p['has_variants']);
    same([
        // «available»: el azul solo está en una variante agotada (14/11/2026: la ficha dice qué valores quedan).
        ['code' => 'color', 'name' => 'Color', 'values' => [['code' => 'rojo', 'label' => 'Rojo', 'color' => '#ff0000', 'available' => true], ['code' => 'azul', 'label' => 'Azul', 'color' => null, 'available' => false]]],
        ['code' => 'talla', 'name' => 'Talla', 'values' => [['code' => 'm', 'label' => 'M', 'color' => null, 'available' => true], ['code' => 'l', 'label' => 'L', 'color' => null, 'available' => true]]],
    ], $p['options']);
    same(3, count($p['variants']));
    same(['id', 'sku', 'gtin', 'label', 'values', 'price', 'availability', 'image_id'], array_keys($p['variants'][0]));
    same('CAM-R-M', $p['variants'][0]['sku']);
    same(null, $p['variants'][0]['gtin']);
    same('Rojo / M', $p['variants'][0]['label']);
    same(['color' => 'rojo', 'talla' => 'm'], $p['variants'][0]['values']);
    same(null, $p['variants'][0]['image_id']);
    same(77, $p['variants'][1]['image_id']);
    same('Edición especial', $p['variants'][2]['label'], 'la etiqueta propia manda');
});

test('imágenes: por orden, la principal primero, con las claves del contrato y el texto alternativo', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $id = aifecProduct($app, ['name' => 'Lámpara', 'images' => 3]);
    $p = aifecCatalog($app)->product($id);
    same(3, count($p['images']));
    same(['id', 'url', 'alt', 'width', 'height', 'thumb', 'card', 'large'], array_keys($p['images'][0]));
    same($p['images'][0], $p['image']);
    contains('foto-', $p['image']['thumb']);
    contains('-thumb.webp', $p['image']['thumb']);
    contains('-card.webp', $p['image']['card']);
    contains('-large.webp', $p['image']['large']);
    same('Foto 1 de Lámpara', $p['image']['alt']);
    same(1600, $p['image']['width']);
    $ids = array_map(static function (array $image): int {
        return $image['id'];
    }, $p['images']);
    $sorted = $ids;
    sort($sorted);
    same($sorted, $ids, 'en el orden de product_media');
});

test('sin el módulo de medios no hay imágenes, y una imagen sin texto alternativo usa el nombre del producto', function (): void {
    $app = catalogApp();
    same([], aifecCatalog($app)->product(aifecProduct($app, ['images' => 2]))['images']);
    $withMedia = catalogApp([], ['catalog', 'media']);
    $mediaId = aifecMedia($withMedia, '');
    $id = aifecProduct($withMedia, ['name' => 'Mesa', 'images' => [$mediaId]]);
    same('Mesa', aifecCatalog($withMedia)->product($id)['image']['alt']);
});

test('la tarjeta: claves del contrato, imagen, precio, disponibilidad resumida y marca', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $brand = aifecBrand($app, 'Nórdica');
    $id = aifecProduct($app, ['name' => 'Silla', 'price' => 1999, 'brand_id' => $brand, 'images' => 2, 'rating_sum' => 8, 'rating_count' => 2, 'stock' => 3]);
    $card = aifecCatalog($app)->card($id);
    same(['id', 'name', 'url', 'image', 'image2', 'price', 'availability', 'brand', 'rating', 'badges', 'badge_list', 'has_variants', 'buy'], array_keys($card));
    check(is_array($card['image2']) && $card['image2']['url'] !== $card['image']['url'], 'con dos fotos, la segunda va en «image2»');
    $single = aifecCatalog($app)->card(aifecProduct($app, ['name' => 'Taburete', 'price' => 900, 'images' => 1, 'stock' => 3]));
    same(null, $single['image2'], 'con una sola foto no hay segunda');
    same('Silla', $card['name']);
    same('/producto/silla', $card['url']);
    same(1999, $card['price']['amount']);
    same(['status' => 'low_stock', 'label' => 'Quedan 3', 'purchasable' => true, 'show_price' => true], $card['availability']);
    same('Nórdica', $card['brand']);
    same(['average' => 4.0, 'count' => 2], $card['rating']);
    same(false, $card['has_variants']);
    contains('-card.webp', $card['image']['card']);
    same(null, aifecCatalog($app)->card(424242));
    same(null, aifecCatalog($app)->card(aifecProduct($app, ['status' => 'draft'])));
});

test('insignias de la tarjeta: oferta, nuevo (menos de 30 días) y agotado', function (): void {
    $app = catalogApp();
    $catalog = aifecCatalog($app);
    // «Ahora»: 12/11/2026 12:00. Hace 10 días = 02/11 12:00; hace exactamente 30 días = 13/10 12:00 (ya no es nuevo).
    $sale = aifecProduct($app, ['price' => 2000, 'sale_price' => 1500, 'created_at' => '2026-01-01 00:00:00']);
    $new = aifecProduct($app, ['published_at' => '2026-11-02 12:00:00']);
    $edge = aifecProduct($app, ['published_at' => '2026-10-13 12:00:00']);
    $old = aifecProduct($app, ['published_at' => '2026-10-12 12:00:00']);
    $out = aifecProduct($app, ['stock' => 0, 'created_at' => '2026-01-01 00:00:00']);
    $backorder = aifecProduct($app, ['stock' => 0, 'backorders' => 'yes', 'created_at' => '2026-01-01 00:00:00']);
    same(['sale'], $catalog->card($sale)['badges']);
    same(['new'], $catalog->card($new)['badges']);
    same([], $catalog->card($edge)['badges'], 'justo 30 días: ya no');
    same([], $catalog->card($old)['badges']);
    same(['out'], $catalog->card($out)['badges']);
    same([], $catalog->card($backorder)['badges'], 'bajo pedido no es agotado');
});

test('catalog.new_days cambia cuánto dura la insignia «nuevo»', function (): void {
    $app = catalogApp(['catalog.new_days' => 5]);
    $recent = aifecProduct($app, ['published_at' => '2026-11-09 12:00:00']);
    $older = aifecProduct($app, ['published_at' => '2026-11-06 12:00:00']);
    same(['new'], aifecCatalog($app)->card($recent)['badges']);
    same([], aifecCatalog($app)->card($older)['badges']);
});

test('textos con comillas, emojis y tildes llegan intactos', function (): void {
    $app = catalogApp();
    $id = aifecProduct($app, ['name' => 'Silla "Ñandú" 🪑 & más', 'summary' => '¡Tilde, ñ y emoji 😀!']);
    $p = aifecCatalog($app)->product($id);
    same('Silla "Ñandú" 🪑 & más', $p['name']);
    same('¡Tilde, ñ y emoji 😀!', $p['summary']);
});

test('un dato guardado que repite el precio o la disponibilidad no se enseña: la ficha no se contradice', function (): void {
    // Caso real (16/11/2026): el chorizo de la demo decía «En stock» arriba y «Disponibilidad: Agotado» en la tabla de datos,
    // porque la importación guardó como dato la disponibilidad del día en que se leyó la ficha de origen.
    $app = catalogApp();
    $id = aifecProduct($app, ['name' => 'Chorizo & pan', 'facts' => [
        ['label' => 'Referencia', 'value' => 'CHO-020'],
        ['label' => 'Precio', 'value' => '11,90 € (impuestos incluidos)'],
        ['label' => 'Disponibilidad', 'value' => 'Agotado'],
        ['label' => 'STOCK', 'value' => '0'],
        ['label' => 'Availability', 'value' => 'Out of stock'],
        ['label' => 'Disponibilité', 'value' => 'Épuisé'],
        ['label' => 'Curación', 'value' => '3 meses'],
        ['label' => 'Precio por kilo', 'value' => '23,80 €'],
    ]]);
    $labels = array_column(aifecCatalog($app)->product($id)['facts'], 'label');
    same(['Referencia', 'Curación', 'Precio por kilo'], $labels, 'se van el precio y la disponibilidad en cualquier idioma; lo demás se queda');
    // Lo guardado no se toca: en el panel se sigue viendo y editando entero.
    same(8, count(\Aifec\Modules\Catalog\Texts::facts((string) $app->db()->value('SELECT facts FROM {product_i18n} WHERE product_id = ?', [$id]))));
});
