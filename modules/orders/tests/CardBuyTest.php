<?php
/**
 * AIFEC — pruebas de la compra desde la tarjeta de un listado: los datos «buy» de la tarjeta, el formulario que pintan los
 * temas (opciones, unidades, añadir), lo que cambia al elegir combinación (precio, foto, enlace), la compra sin JavaScript y
 * con él, el presupuesto de consultas y los recursos propios (hoja dentro de la página y guion diferido).
 *
 * Lo pidió el usuario mirando la tienda (13/11/2026): «en los listados se debe poder elegir las combinaciones y unidades;
 * además de cambiar la foto y el precio al cambiar la combinación».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Front\Variants;

require_once __DIR__ . '/FrontKit.php';

/**
 * Una tienda con una categoría «Ropa» y tres productos: una camisa con dos opciones (color y talla) y tres variantes —la azul
 * con su precio y su foto, la roja L agotada—, una taza sin variantes y un producto agotado.
 *
 * @param array<string,mixed> $settings
 *
 * @return array{app:App,cat:int,shirt:int,mug:int,gone:int,photo:int}
 */
function cardBuyShop(array $settings = []): array
{
    $app = frontShop(['settings' => $settings]);
    $cat = aifecCategory($app, 'Ropa');
    aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo', 'azul' => 'Azul'], ['variant' => 1, 'colors' => ['rojo' => '#c00000']]);
    aifecAttribute($app, 'talla', 'Talla', ['m' => 'M', 'l' => 'L'], ['variant' => 1]);
    $main = aifecMedia($app, 'Camisa');
    $photo = aifecMedia($app, 'Camisa azul');
    $shirt = aifecProduct($app, [
        'name' => 'Camisa <b>Oxford</b> & co', 'price' => 2000, 'tax_class_id' => 'standard', 'categories' => [$cat], 'main_media_id' => $main,
        'variants' => [
            ['sku' => 'CAM-R-M', 'stock' => 3, 'values' => ['color' => 'rojo', 'talla' => 'm']],
            ['sku' => 'CAM-R-L', 'stock' => 0, 'values' => ['color' => 'rojo', 'talla' => 'l']],
            ['sku' => 'CAM-A-M', 'stock' => 9, 'price' => 2500, 'media_id' => $photo, 'values' => ['color' => 'azul', 'talla' => 'm']],
        ],
    ]);
    $app->db()->update('products', ['main_media_id' => $main], ['id' => $shirt]);
    $mug = aifecProduct($app, ['name' => 'Taza', 'price' => 1200, 'stock' => 5, 'tax_class_id' => 'standard', 'categories' => [$cat]]);
    $gone = aifecProduct($app, ['name' => 'Agotado', 'price' => 900, 'stock' => 0, 'tax_class_id' => 'standard', 'categories' => [$cat]]);
    $app->invalidate(['catalog']);

    return ['app' => $app, 'cat' => $cat, 'shirt' => $shirt, 'mug' => $mug, 'gone' => $gone, 'photo' => $photo];
}

/** Los identificadores de las variantes de un producto, por su referencia. */
function cardBuyVariantIds(App $app, int $productId): array
{
    $out = [];
    foreach ($app->db()->select('SELECT id, sku FROM {variants} WHERE product_id = ? ORDER BY position, id', [$productId]) as $row) {
        $out[(string) $row['sku']] = (int) $row['id'];
    }

    return $out;
}

test('la tarjeta de un producto sin variantes trae «buy» con su tope de unidades y sin opciones', function (): void {
    $s = cardBuyShop();
    $card = $s['app']->service('catalog')->card($s['mug']);
    check(is_array($card['buy']), 'se puede comprar desde la tarjeta');
    same($s['mug'], $card['buy']['product_id']);
    same(5, $card['buy']['max_qty'], 'como mucho, lo que hay');
    same([], $card['buy']['variants']);
    same([], $card['buy']['options']);
    same(0, $card['buy']['initial']);
    same(false, $card['buy']['too_many']);
});

test('la tarjeta de un producto con variantes trae sus opciones, cada variante con precio, foto y disponibilidad, y la elegida de serie', function (): void {
    $s = cardBuyShop();
    $ids = cardBuyVariantIds($s['app'], $s['shirt']);
    $buy = $s['app']->service('catalog')->card($s['shirt'])['buy'];
    same(['color', 'talla'], array_column($buy['options'], 'code'));
    same('Color', $buy['options'][0]['name']);
    same(['rojo', 'azul'], array_column($buy['options'][0]['values'], 'code'));
    same('Rojo', $buy['options'][0]['values'][0]['label']);
    same(3, count($buy['variants']));
    same($ids['CAM-R-M'], $buy['initial'], 'la primera que se puede comprar');
    $by = [];
    foreach ($buy['variants'] as $variant) {
        $by[$variant['id']] = $variant;
    }
    same(['color' => 'rojo', 'talla' => 'm'], $by[$ids['CAM-R-M']]['values']);
    same(true, $by[$ids['CAM-R-M']]['purchasable']);
    same(3, $by[$ids['CAM-R-M']]['max_qty']);
    same(false, $by[$ids['CAM-R-L']]['purchasable'], 'la agotada no se puede añadir');
    same(2500, $by[$ids['CAM-A-M']]['price']['amount'], 'la azul tiene su precio');
    check($by[$ids['CAM-A-M']]['image'] !== null && strpos((string) $by[$ids['CAM-A-M']]['image']['card'], '-card.webp') !== false, 'y su foto');
    same(null, $by[$ids['CAM-R-M']]['image'], 'la roja usa la del producto');
});

test('un producto agotado no trae «buy»; con más variantes que el tope la tarjeta manda a la ficha', function (): void {
    $s = cardBuyShop();
    same(null, $s['app']->service('catalog')->card($s['gone'])['buy']);

    $few = cardBuyShop(['catalog.card_options_max' => 2]);
    $buy = $few['app']->service('catalog')->card($few['shirt'])['buy'];
    same(true, $buy['too_many'], 'tres variantes con tope de dos');
    same([], $buy['variants']);
    $html = (new TestClient($few['app']))->get('/categoria/ropa')->body;
    contains('Elegir opciones', $html);
    check(preg_match('/data-af-variants-data/', $html) === 0, 'sin datos de variantes');
});

test('con el ajuste apagado la tarjeta no trae «buy» ni la página lleva la hoja ni el guion; al encenderlo salen sin vaciar nada a mano', function (): void {
    $s = cardBuyShop(['catalog.card_options' => false]);
    same(null, $s['app']->service('catalog')->card($s['mug'])['buy']);
    $web = new TestClient($s['app']);
    $html = $web->get('/categoria/ropa')->body;
    check(strpos($html, 'data-af-co') === false && strpos($html, 'card-options.js') === false, 'ni formulario, ni hoja, ni guion');

    $s['app']->settings()->set('catalog.card_options', true);
    $s['app']->hooks()->fire('settings.saved', 'catalog', ['catalog.card_options'], $s['app']);
    check(is_array($s['app']->service('catalog')->card($s['mug'])['buy']), 'la tarjeta guardada con el ajuste apagado no se reutiliza');
    $html = $web->get('/categoria/ropa')->body;
    contains('data-af-co-style', $html, 'la hoja va dentro de la página');
    contains('card-options.js', $html, 'y el guion, al final');
});

test('el listado pinta en cada tarjeta el formulario con opciones, unidades y botón, fuera del enlace, y el precio listo para cambiar', function (): void {
    $s = cardBuyShop();
    $ids = cardBuyVariantIds($s['app'], $s['shirt']);
    $r = (new TestClient($s['app']))->get('/categoria/ropa');
    same(200, $r->status);
    $html = $r->body;
    same([], frontNodes($html, '//a//form'), 'ningún formulario dentro de un enlace');
    same(2, count(frontNodes($html, '//article[@data-af-card]//form[@data-af-co][@data-af-cart-add]')), 'la camisa y la taza; el agotado, no');
    $card = '//article[@data-af-variants][@data-af-card]';
    same(1, count(frontNodes($html, $card)), 'solo la camisa deja elegir');
    same('/producto/camisa-b-oxford-b-co', frontNode($html, $card)->getAttribute('data-af-card-url'));
    same((string) $ids['CAM-R-M'], frontNode($html, $card . '//input[@name="variant_id"][@data-af-variant-id]')->getAttribute('value'), 'sale elegida la primera que se puede comprar');
    same(2, count(frontNodes($html, $card . '//fieldset[@data-af-option]')), 'color y talla');
    same(1, count(frontNodes($html, $card . '//input[@type="radio"][@name="opt[color]"][@value="rojo"][@checked]')));
    same(0, count(frontNodes($html, $card . '//input[@type="radio"][@name="opt[talla]"][@checked]')), 'la talla no se marca de serie: se elige');
    same('1', frontNode($html, $card . '//input[@name="qty"]')->getAttribute('value'));
    same('3', frontNode($html, $card . '//input[@name="qty"]')->getAttribute('max'), 'el tope de la variante elegida');
    same('/catalogo/elegir-variante', frontNode($html, $card . '//button[@data-af-add]')->getAttribute('formaction'), 'sin JavaScript resuelve el servidor');
    same('Añadir Camisa <b>Oxford</b> & co al carrito', frontNode($html, $card . '//button[@data-af-add]')->getAttribute('aria-label'));
    same(1, count(frontNodes($html, $card . '//*[@data-af-price-now]')), 'el precio de la tarjeta se puede actualizar');
    check(strpos($html, '<b>Oxford</b>') === false, 'el nombre sale escapado');
    // La taza: sin opciones, con unidades y sin «elegir variante».
    $mug = '//article[@data-af-card][not(@data-af-variants)]';
    same('0', frontNode($html, $mug . '//input[@name="variant_id"]')->getAttribute('value'));
    same('5', frontNode($html, $mug . '//input[@name="qty"]')->getAttribute('max'));
    same('', frontNode($html, $mug . '//button[@data-af-add]')->getAttribute('formaction'));
    check($r->cacheTtl > 0 && $r->cookies === [], 'la página sigue siendo la misma para todos');
});

test('una opción con muchos valores (las tallas de un zapato) sale como desplegable, con la agotada apagada, y sin JavaScript compra la elegida', function (): void {
    $s = cardBuyShop();
    $sizes = [];
    foreach ([38, 39, 40, 41, 42, 43, 44, 45] as $size) {
        $sizes[] = ['sku' => 'ZAP-T' . $size, 'stock' => $size === 38 || $size === 41 ? 0 : 2, 'values' => ['numero' => (string) $size]];
    }
    aifecAttribute($s['app'], 'numero', 'Número', [], ['variant' => 1]);
    $shoe = aifecProduct($s['app'], ['name' => 'Zapato', 'price' => 8995, 'tax_class_id' => 'standard', 'categories' => [$s['cat']], 'variants' => $sizes]);
    $s['app']->invalidate(['catalog']);
    $ids = cardBuyVariantIds($s['app'], $shoe);
    $web = new TestClient($s['app']);
    $html = $web->get('/categoria/ropa')->body;
    $select = '//article[@data-af-card-url="/producto/zapato"]//select[@name="opt[numero]"][@data-af-option="numero"]';
    same(1, count(frontNodes($html, $select)), 'ocho tallas: un desplegable y no ocho botones');
    same(8, count(frontNodes($html, $select . '/option')));
    same(['38', '41'], array_map(static function (DOMNode $node): string {
        return $node->getAttribute('value');
    }, frontNodes($html, $select . '/option[@disabled]')), 'las agotadas no se pueden elegir');
    same('39', frontNode($html, $select . '/option[@selected]')->getAttribute('value'), 'sale elegida la primera que se puede comprar');
    same((string) $ids['ZAP-T39'], frontNode($html, '//article[@data-af-card-url="/producto/zapato"]//input[@data-af-variant-id]')->getAttribute('value'));
    same([], frontNodes($html, '//article[@data-af-card-url="/producto/zapato"]//input[@type="radio"]'), 'sin botones de opción para esa');

    $r = $web->post('/catalogo/elegir-variante', ['product_id' => (string) $shoe, 'opt' => ['numero' => '43'], 'qty' => '1']);
    check(in_array($r->status, [302, 303], true), 'redirige (' . $r->status . ')');
    $web->get('/carrito/summary.json');
    same($ids['ZAP-T43'], (int) array_values($web->json()['lines'])[0]['variant_id'], 'entra la 43');
});

test('los datos de variantes de la tarjeta llevan precio, disponibilidad, tope y la foto al tamaño de tarjeta, bien escapados', function (): void {
    $s = cardBuyShop();
    $ids = cardBuyVariantIds($s['app'], $s['shirt']);
    $html = (new TestClient($s['app']))->get('/categoria/ropa')->body;
    check(preg_match('#<script type="application/json" data-af-variants-data>(.*?)</script>#s', $html, $m) === 1, 'hay datos de variantes');
    check(strpos($m[1], '<') === false, 'sin «<» dentro del JSON');
    $list = json_decode($m[1], true);
    check(is_array($list) && count($list) === 3, 'tres variantes');
    $by = [];
    foreach ($list as $row) {
        $by[$row['id']] = $row;
    }
    same(['color' => 'azul', 'talla' => 'm'], $by[$ids['CAM-A-M']]['values']);
    same(2500, $by[$ids['CAM-A-M']]['price']['amount']);
    check(strpos((string) $by[$ids['CAM-A-M']]['image']['src'], '-card.webp') !== false, 'la foto de la azul, al tamaño de tarjeta');
    same(null, $by[$ids['CAM-R-M']]['image']);
    same(3, $by[$ids['CAM-R-M']]['max']);
    same('out_of_stock', $by[$ids['CAM-R-L']]['availability']['status'], 'la agotada desactiva el botón');
    same('in_stock', $by[$ids['CAM-A-M']]['availability']['status']);
    same(Variants::cardData(['variants' => []]), []);
});

test('sin JavaScript: el botón envía las opciones marcadas y las unidades, y entra ESA variante', function (): void {
    $s = cardBuyShop();
    $ids = cardBuyVariantIds($s['app'], $s['shirt']);
    $web = new TestClient($s['app']);
    $r = $web->post('/catalogo/elegir-variante', ['product_id' => (string) $s['shirt'], 'variant_id' => (string) $ids['CAM-R-M'], 'opt' => ['color' => 'azul', 'talla' => 'm'], 'qty' => '2']);
    check(in_array($r->status, [302, 303], true), 'redirige (' . $r->status . ')');
    $web->get('/carrito/summary.json');
    $cart = $web->json();
    same(2, (int) $cart['count'], 'dos unidades');
    $line = array_values($cart['lines'])[0];
    same($ids['CAM-A-M'], (int) $line['variant_id'], 'la azul, no la que venía en el campo oculto');
});

test('con JavaScript: se añade la variante del campo oculto con sus unidades y la respuesta dice «Añadido»', function (): void {
    $s = cardBuyShop();
    $ids = cardBuyVariantIds($s['app'], $s['shirt']);
    $web = new TestClient($s['app']);
    same(200, $web->postJson('/carrito/add', ['product_id' => $s['shirt'], 'variant_id' => $ids['CAM-A-M'], 'qty' => 3, 'opt' => ['color' => 'azul', 'talla' => 'm']])->status);
    $json = $web->json();
    same(3, $json['count']);
    same($ids['CAM-A-M'], (int) $json['line']['variant_id']);
    contains('Añadido al carrito: Camisa', (string) $json['message']);
    same(422, $web->postJson('/carrito/add', ['product_id' => $s['shirt'], 'variant_id' => $ids['CAM-R-L'], 'qty' => 1])->status, 'la agotada no entra');
});

test('la ficha sin JavaScript manda a /carrito/add: lo marcado manda sobre el campo oculto y sin talla elegida no entra nada', function (): void {
    $s = cardBuyShop();
    $ids = cardBuyVariantIds($s['app'], $s['shirt']);
    $web = new TestClient($s['app']);
    $none = $web->postJson('/carrito/add', ['product_id' => $s['shirt'], 'variant_id' => $ids['CAM-R-M'], 'qty' => 1, 'opt' => ['color' => 'rojo']]);
    same(422, $none->status, 'falta la talla');
    contains('Elige primero una opción', (string) $none->body);
    same(200, $web->postJson('/carrito/add', ['product_id' => $s['shirt'], 'variant_id' => $ids['CAM-R-M'], 'qty' => 1, 'opt' => ['color' => 'azul', 'talla' => 'm']])->status);
    same($ids['CAM-A-M'], (int) $web->json()['line']['variant_id'], 'la marcada (azul), no la del campo oculto (roja)');
});

test('la ficha sale con la combinación que pide la dirección (?variante=…) y, si no es suya, con la de siempre', function (): void {
    $s = cardBuyShop();
    $ids = cardBuyVariantIds($s['app'], $s['shirt']);
    $web = new TestClient($s['app']);
    $html = $web->get('/producto/camisa-b-oxford-b-co?variante=' . $ids['CAM-A-M'])->body;
    same((string) $ids['CAM-A-M'], frontNode($html, '//main//input[@name="variant_id"][@data-af-variant-id]')->getAttribute('value'));
    same(1, count(frontNodes($html, '//main//input[@type="radio"][@name="opt[color]"][@value="azul"][@checked]')));
    $html = $web->get('/producto/camisa-b-oxford-b-co?variante=999999')->body;
    same((string) $ids['CAM-R-M'], frontNode($html, '//main//input[@name="variant_id"][@data-af-variant-id]')->getAttribute('value'));
    same(null, Variants::byId(['variants' => [['id' => 4]]], 0));
});

test('cargar las tarjetas de un listado cuesta, como mucho, una consulta más con la compra desde la tarjeta encendida', function (): void {
    $count = static function (bool $on): int {
        $s = cardBuyShop(['catalog.card_options' => $on]);
        $ids = [];
        for ($i = 0; $i < 12; ++$i) {
            $ids[] = aifecProduct($s['app'], ['name' => 'Prenda ' . $i, 'price' => 1500, 'variants' => [
                ['stock' => 4, 'values' => ['color' => 'rojo', 'talla' => 'm']], ['stock' => 2, 'values' => ['color' => 'azul', 'talla' => 'l']],
            ]]);
            $ids[] = aifecProduct($s['app'], ['name' => 'Suelto ' . $i, 'price' => 700, 'stock' => 8]);
        }
        $s['app']->invalidate(['catalog']);
        // Los atributos de la tienda se guardan en la caché la primera vez que alguien los pide (una tarjeta con variantes
        // o una ficha): se mide con eso ya hecho, que es como está una tienda en marcha.
        $s['app']->service('catalog')->card($s['shirt']);
        $s['app']->service('catalog')->product($s['shirt']);
        $before = $s['app']->db()->queries;
        $cards = $s['app']->service('catalog')->cards($ids);
        same(24, count($cards));

        return $s['app']->db()->queries - $before;
    };
    $off = $count(false);
    $on = $count(true);
    check($on <= $off + 1, 'con la compra desde la tarjeta: ' . $on . ' consultas; sin ella: ' . $off);
});

test('el guion y la hoja de la tarjeta: pequeños, sin innerHTML, sin direcciones de fuera y sin colores escritos; la hoja entra en el <head>', function (): void {
    $dir = dirname(__DIR__, 2) . '/catalog/assets/';
    $js = (string) file_get_contents($dir . 'card-options.js');
    $css = (string) file_get_contents($dir . 'card-options.css');
    check(strlen($js) <= 6000, 'card-options.js pesa ' . strlen($js));
    check(strlen($css) <= 3000, 'card-options.css pesa ' . strlen($css));
    check(strpos($js, 'innerHTML') === false && strpos($js, 'eval(') === false, 'sin innerHTML ni eval');
    check(preg_match('#https?://#', $js . $css) === 0, 'sin direcciones de fuera');
    check(preg_match('/#[0-9a-fA-F]{3,8}\b/', (string) preg_replace('#/\*.*?\*/#s', '', $css)) === 0, 'sin colores escritos');
    check(strpos($css, '!important') === false, 'sin !important');
    contains("img.af-product-card-img", $js, 'cambia la foto de la tarjeta');
    contains('?variante=', $js, 'y el enlace a la ficha');
    $style = Variants::cardStyle();
    check(strpos($style, '<style data-af-co-style>') === 0 && substr($style, -8) === '</style>', 'una etiqueta style');
    check(strpos($style, '/*') === false && strpos($style, "\n") === false, 'sin comentario ni saltos');
    contains('.af-co-row{', $style);
});

test('las tarjetas de los seis temas incluyen la compra desde la tarjeta y marcan su <article>', function (): void {
    $root = dirname(__DIR__, 3) . '/themes/';
    foreach (['base', 'atelier', 'despensa', 'gmartos', 'taller'] as $theme) {
        $file = (string) file_get_contents($root . $theme . '/templates/partials/product-card.php');
        contains("catalog::front/partials/card-options", $file, $theme . ': incluye el parcial');
        contains('data-af-card data-af-card-url="', $file, $theme . ': marca la tarjeta');
        contains("data-af-variants", $file, $theme . ': y la que deja elegir');
        check(preg_match("/'live' => \\$[a-z]+_live/", $file) === 1, $theme . ': el precio se puede actualizar');
    }
    check(!is_file($root . 'aifec/templates/partials/product-card.php'), 'aifec hereda la tarjeta de base');
});

test('los seis temas resaltan la opción elegida (las opciones son botones de opción: la regla no puede mirar solo aria-pressed)', function (): void {
    // El 13/11/2026 los temas aifec y taller solo tenían «.af-variant[aria-pressed="true"]»: en la ficha (y en la tarjeta) se
    // elegía una talla y no se veía cuál estaba elegida.
    $root = dirname(__DIR__, 3) . '/themes/';
    foreach (['base', 'aifec', 'atelier', 'despensa', 'gmartos', 'taller'] as $theme) {
        $css = (string) file_get_contents($root . $theme . '/assets/css/theme.css');
        check(preg_match('/\.af-variant\.is-on[^{]*\{[^}]+\}/', $css) === 1, $theme . ': estilo de la opción elegida (.af-variant.is-on)');
        check(strpos($css, '.af-variant:has(input:checked)') !== false, $theme . ': y también sin JavaScript (:has(input:checked))');
    }
});

test('con cada tema el formulario de la tarjeta queda fuera del enlace y deja elegir', function (): void {
    foreach (['base', 'aifec', 'atelier', 'despensa', 'gmartos', 'taller'] as $theme) {
        $s = cardBuyShop(['theme.active' => $theme]);
        $r = (new TestClient($s['app']))->get('/categoria/ropa');
        same(200, $r->status, $theme);
        same([], frontNodes($r->body, '//a//form'), $theme . ': ningún formulario dentro de un enlace');
        same(1, count(frontNodes($r->body, '//article[@data-af-variants]//form[@data-af-co]//fieldset[@data-af-option="color"]')), $theme . ': la camisa deja elegir el color');
        same(1, count(frontNodes($r->body, '//article[@data-af-variants]//img[contains(@class, "af-product-card-img")]')), $theme . ': con su foto para cambiar');
        check(count(frontNodes($r->body, '//article[@data-af-card]//form[@data-af-co]//input[@name="qty"]')) >= 2, $theme . ': unidades en la camisa y en la taza');
    }
});
