<?php
/**
 * AIFEC — pruebas de las combinaciones escritas: el intérprete de textos (castellano e inglés, rangos de tallas de letras y de
 * números, separadores), la tabla de combinaciones que saldrían (con las que ya existen marcadas), la creación al confirmar y la
 * IA de mentira cuando las reglas no entienden. Nada se crea sin confirmar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Admin\VariantParser;

require_once __DIR__ . '/../../ai/tests/helpers.php';
require_once __DIR__ . '/AdminProductKitTest.php';

/**
 * Atributos de mentira para el intérprete: color (1), talla de letras (2) y número de calzado (3), con nombres y valores también en
 * inglés como «aliases», que es como los reúne VariantAdmin::parserAttributes().
 *
 * @return array<int,array<string,mixed>>
 */
function typedAttributes(bool $withXxl = true): array
{
    $values = static function (array $list): array {
        $out = [];
        foreach ($list as $id => $item) {
            $out[$id] = ['label' => $item[0], 'code' => $item[1], 'aliases' => isset($item[2]) ? [$item[2]] : []];
        }

        return $out;
    };
    $sizes = [20 => ['XS', 'xs'], 21 => ['S', 's'], 22 => ['M', 'm'], 23 => ['L', 'l'], 24 => ['XL', 'xl']];
    if ($withXxl) {
        $sizes[25] = ['XXL', 'xxl'];
    }

    return [
        1 => ['id' => 1, 'name' => 'Color', 'code' => 'color', 'aliases' => ['Colour', 'Colores'], 'values' => $values([
            10 => ['Negro', 'negro', 'Black'], 11 => ['Gris', 'gris', 'Grey'], 12 => ['Rojo', 'rojo', 'Red'], 13 => ['Azul', 'azul', 'Blue'], 14 => ['Blanco', 'blanco', 'White'],
            15 => ['Azul marino', 'azul-marino', 'Navy'],
        ])],
        2 => ['id' => 2, 'name' => 'Talla', 'code' => 'talla', 'aliases' => ['Size', 'Tallas'], 'values' => $values($sizes)],
        3 => ['id' => 3, 'name' => 'Número', 'code' => 'numero', 'aliases' => ['Shoe size'], 'values' => $values([
            30 => ['36', '36'], 31 => ['37', '37'], 32 => ['38', '38'], 33 => ['38,5', '38-5'], 34 => ['39', '39'], 35 => ['40', '40'], 36 => ['41', '41'],
            37 => ['42', '42'], 38 => ['43', '43'], 39 => ['44', '44'],
        ])],
    ];
}

test('el intérprete entiende «negro y gris, tallas S a L»: dos atributos, sin «;» y con un rango de letras', function (): void {
    $r = VariantParser::parse('negro y gris, tallas S a L', typedAttributes());
    same([1 => [10, 11], 2 => [21, 22, 23]], $r['chosen']);
    same([], $r['unknown']);
});

test('el intérprete entiende «rojo/azul; 38, 39, 40»: valores con barra y números sueltos sin decir el atributo', function (): void {
    $r = VariantParser::parse('rojo/azul; 38, 39, 40', typedAttributes());
    same([1 => [12, 13], 3 => [32, 34, 35]], $r['chosen']);
    same([], $r['unknown']);
    // Con comas y sin «;» da lo mismo.
    same($r['chosen'], VariantParser::parse('rojo, azul, 38, 39, 40', typedAttributes())['chosen']);
});

test('el intérprete entiende lo mismo en inglés', function (): void {
    $r = VariantParser::parse('black and grey; sizes S to XL', typedAttributes());
    same([1 => [10, 11], 2 => [21, 22, 23, 24]], $r['chosen']);
    same([], $r['unknown']);
    $r = VariantParser::parse('colours: red & blue, size: M', typedAttributes());
    same([1 => [12, 13], 2 => [22]], $r['chosen']);
    $r = VariantParser::parse('shoe size 38 to 40', typedAttributes());
    same([3 => [32, 33, 34, 35]], $r['chosen'], 'el rango de números incluye el 38,5');
});

test('los rangos de tallas de letras se escriben con «a», «to», «hasta» o un guion, en cualquier orden y con la escalera de tallas', function (): void {
    foreach (['S a XL', 's-xl', 'S – XL', 'S—XL', 'de S hasta XL', 'S to XL', 'tallas S-XL', 'XL a S'] as $text) {
        same([2 => [21, 22, 23, 24]], VariantParser::parse($text, typedAttributes())['chosen'], '«' . $text . '»');
    }
    same([2 => [20, 21, 22]], VariantParser::parse('XS a M', typedAttributes())['chosen']);
    same([2 => [24, 25]], VariantParser::parse('XL, 2XL', typedAttributes())['chosen'], '2XL es XXL');
    $r = VariantParser::parse('S a XXL', typedAttributes(false));
    same([2 => [21, 22, 23, 24]], $r['chosen'], 'lo que el atributo no tiene no se inventa');
    same(['XXL'], $r['unknown'], 'y se dice');
});

test('los rangos de números toman los valores numéricos del atributo que caen entre los dos extremos', function (): void {
    $all = [30, 31, 32, 33, 34, 35, 36, 37, 38, 39];
    foreach (['36 a 44', '36-44', '36 – 44', 'del 36 al 44', '36 to 44', '44 a 36', 'de 36 hasta 44'] as $text) {
        same([3 => $all], VariantParser::parse($text, typedAttributes())['chosen'], '«' . $text . '»');
    }
    same([3 => [31, 32, 33, 34]], VariantParser::parse('37 a 39', typedAttributes())['chosen'], 'con el 38,5 en medio');
    same([3 => [34, 36]], VariantParser::parse('39 y 41', typedAttributes())['chosen']);
});

test('los textos se comparan sin mayúsculas ni tildes y se aceptan los saltos de línea y los puntos finales', function (): void {
    $r = VariantParser::parse("NEGRO / AZÚL\nTALLA M.", typedAttributes());
    same([1 => [10, 13], 2 => [22]], $r['chosen']);
    same([], $r['unknown']);
    same([1 => [15]], VariantParser::parse('azul marino', typedAttributes())['chosen'], 'un valor con dos palabras');
    same([1 => [15]], VariantParser::parse('azul-marino', typedAttributes())['chosen'], 'y con guion no se toma por un rango');
    same([1 => [12, 13]], VariantParser::parse('rojo-azul', typedAttributes())['chosen'], 'dos valores unidos con un guion, no un rango de colores');
    same([1 => [10, 11, 12]], VariantParser::parse('negro a rojo', typedAttributes())['chosen'], 'con «a» sí es un rango por el orden del atributo');
});

test('lo que no se entiende se devuelve aparte, con las mayúsculas que se escribieron, y no se mezcla con lo entendido', function (): void {
    $r = VariantParser::parse('negro, Verde Pistacho; talla M', typedAttributes());
    same([1 => [10], 2 => [22]], $r['chosen']);
    same(['Verde Pistacho'], $r['unknown']);
    $r = VariantParser::parse('qué raro es esto', typedAttributes());
    same([], $r['chosen']);
    check($r['unknown'] !== [], 'queda dicho');
    foreach (['', '   ', ';;;', ',,,', "\n\n"] as $empty) {
        same(['chosen' => [], 'unknown' => []], VariantParser::parse($empty, typedAttributes()), 'texto vacío «' . addcslashes($empty, "\n") . '»');
    }
    $none = VariantParser::parse('negro', []);
    same([], $none['chosen'], 'sin atributos no hay nada que elegir');
    same(['negro'], $none['unknown'], 'y lo escrito queda como no entendido');
    same([1 => [10]], VariantParser::parse('negro, negro y NEGRO', typedAttributes())['chosen'], 'sin repetidos');
    $marked = VariantParser::parse('<b>negro</b>', typedAttributes());
    same([], $marked['chosen'], 'el marcado no se toma por un valor');
    check($marked['unknown'] !== [], 'y queda dicho');
});

/**
 * Tienda con color y talla para variantes, y un producto.
 *
 * @return array{0:App,1:TestClient,2:int,3:array<string,int>} aplicación, navegador, producto y los ids de los valores por código
 */
function typedShop(?App $app = null): array
{
    $app = $app !== null ? $app : prodApp();
    $web = adminClient($app);
    $color = aifecAttribute($app, 'color', 'Color', ['negro' => 'Negro', 'gris' => 'Gris', 'rojo' => 'Rojo'], ['variant' => 1, 'i18n' => ['en' => 'Colour']]);
    $size = aifecAttribute($app, 'talla', 'Talla', ['s' => 'S', 'm' => 'M', 'l' => 'L', 'xl' => 'XL'], ['variant' => 1]);
    $ids = [];
    foreach ([['negro', $color], ['gris', $color], ['rojo', $color], ['s', $size], ['m', $size], ['l', $size], ['xl', $size]] as $pair) {
        $ids[$pair[0]] = prodValue($app, $pair[1], $pair[0]);
    }
    $ids['color'] = $color;
    $ids['size'] = $size;
    $product = prodMake($web, 'Camiseta');

    return [$app, $web, $product, $ids];
}

/** Pide la vista previa de unas combinaciones escritas (el navegador sin JavaScript). */
function typedParse(TestClient $web, int $product, string $text): \Aifec\Kernel\Response
{
    $web->get('/admin/catalog/products/' . $product . '/variants');

    return $web->post('/admin/catalog/products/' . $product . '/variants', ['_token' => aifecToken($web), 'op' => 'parse', 'typed' => $text]);
}

/**
 * Las filas de la tabla de vista previa: nombre => «new» | «exists» | «capped».
 *
 * @return array<string,string>
 */
function typedRows(string $html): array
{
    $rows = [];
    if (preg_match_all('#<tr>\s*<td><strong>(.*?)</strong></td>\s*<td><span class="af-badge[^"]*">(.*?)</span></td>\s*</tr>#s', $html, $m, PREG_SET_ORDER) > 0) {
        foreach ($m as $row) {
            $state = $row[2] === 'Nuevo' ? 'new' : ($row[2] === 'Ya existe' ? 'exists' : 'capped');
            $rows[html_entity_decode($row[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')] = $state;
        }
    }

    return $rows;
}

test('«Escríbelas» enseña la tabla exacta de combinaciones y no crea nada', function (): void {
    list($app, $web, $product) = typedShop();
    $r = typedParse($web, $product, 'negro y gris, tallas S a M');
    same(200, $r->status);
    same(['Negro / S' => 'new', 'Negro / M' => 'new', 'Gris / S' => 'new', 'Gris / M' => 'new'], typedRows($r->body), 'cuatro combinaciones, todas nuevas, en el orden de la tienda');
    contains('Crear 4 combinaciones', $r->body);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$product]), 'ver la vista previa no crea nada');
    contains('value="negro y gris, tallas S a M"', str_replace('>negro y gris, tallas S a M<', ' value="negro y gris, tallas S a M"<', $r->body), 'el texto escrito se conserva');
    notContains('type="hidden" name="gen[', typedParse($web, $product, 'nada que ver 123')->body, 'sin nada entendido no hay botón de crear');
});

test('las combinaciones que ya existen salen marcadas y no se vuelven a crear', function (): void {
    list($app, $web, $product, $ids) = typedShop();
    $web->get('/admin/catalog/products/' . $product . '/variants');
    $web->post('/admin/catalog/products/' . $product . '/variants', ['_token' => aifecToken($web), 'op' => 'generate', 'gen' => [$ids['color'] => [$ids['negro']], $ids['size'] => [$ids['s']]]]);
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$product]));

    $r = typedParse($web, $product, 'negro y gris; S, M');
    same(['Negro / S' => 'exists', 'Negro / M' => 'new', 'Gris / S' => 'new', 'Gris / M' => 'new'], typedRows($r->body));
    contains('Crear 3 combinaciones', $r->body);
    contains('Ya existe 1 y no se repite.', $r->body);
    same(1, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$product]), 'sigue habiendo una');

    $r = typedParse($web, $product, 'negro; S');
    same(['Negro / S' => 'exists'], typedRows($r->body));
    contains($app->i18n()->t('Every combination already exists.'), $r->body);
    notContains('type="hidden" name="gen[', $r->body, 'si no hay nada nuevo, no hay botón de crear');
});

test('al confirmar se crean exactamente las que se vieron, una sola vez', function (): void {
    list($app, $web, $product, $ids) = typedShop();
    $r = typedParse($web, $product, 'negro y gris; tallas S a L');
    $rows = typedRows($r->body);
    same(6, count($rows));
    // El formulario de confirmación lleva escondidos los valores entendidos, como los manda el navegador.
    preg_match_all('/type="hidden" name="gen\[(\d+)\]\[\]" value="(\d+)"/', $r->body, $m, PREG_SET_ORDER);
    $gen = [];
    foreach ($m as $hidden) {
        $gen[(int) $hidden[1]][] = (int) $hidden[2];
    }
    same([$ids['color'] => [$ids['negro'], $ids['gris']], $ids['size'] => [$ids['s'], $ids['m'], $ids['l']]], $gen, 'los valores entendidos viajan al confirmar');
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$product]));

    $r = $web->post('/admin/catalog/products/' . $product . '/variants', ['_token' => aifecToken($web), 'op' => 'generate', 'gen' => $gen]);
    same(302, $r->status);
    same(6, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$product]));
    same(1, prodScalar($app, 'SELECT has_variants FROM {products} WHERE id = ?', [$product]));
    $labels = [];
    foreach (\Aifec\Modules\Catalog\Admin\VariantAdmin::rows($app, $product) as $row) {
        $labels[] = $row['display'];
    }
    sort($labels);
    $expected = array_keys($rows);
    sort($expected);
    same($expected, $labels, 'lo creado es lo que se enseñó');

    $web->get('/admin/catalog/products/' . $product . '/variants');
    $web->post('/admin/catalog/products/' . $product . '/variants', ['_token' => aifecToken($web), 'op' => 'generate', 'gen' => $gen]);
    same(6, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$product]), 'confirmar dos veces no repite');
    $again = typedRows(typedParse($web, $product, 'negro y gris; tallas S a L')->body);
    same(['exists'], array_values(array_unique($again)), 'y la vista previa lo da todo por existente');
});

test('lo que no se entiende se avisa, lo entendido se enseña y un texto con marcado sale escapado', function (): void {
    list($app, $web, $product) = typedShop();
    $r = typedParse($web, $product, 'negro, verde pistacho & <b>x</b>');
    contains('No se ha entendido y se deja fuera:', $r->body);
    contains('verde pistacho', $r->body);
    notContains('<b>x</b>', $r->body, 'lo escrito nunca sale como HTML');
    contains('&lt;b&gt;', $r->body);
    notContains('&amp;amp;', $r->body, 'y no se escapa dos veces');

    $r = typedParse($web, $product, 'nada de nada');
    contains('No lo he entendido', $r->body);
    notContains('Crear ', $r->body);
    $r = typedParse($web, $product, '');
    contains('No lo he entendido', $r->body);
});

test('«Escríbelas» solo para quien puede cambiar el catálogo, y sin atributos para variantes no sale', function (): void {
    list($app, $web, $product) = typedShop();
    $viewer = prodViewer($app);
    $page = $viewer->get('/admin/catalog/products/' . $product . '/variants');
    same(200, $page->status);
    notContains('name="typed"', $page->body, 'quien solo ve no tiene el cuadro');
    $r = $viewer->post('/admin/catalog/products/' . $product . '/variants', ['_token' => aifecToken($viewer), 'op' => 'parse', 'typed' => 'negro']);
    same(403, $r->status);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$product]));

    $bare = prodApp();
    $bareWeb = adminClient($bare);
    $other = prodMake($bareWeb, 'Sin atributos');
    notContains('name="typed"', $bareWeb->get('/admin/catalog/products/' . $other . '/variants')->body, 'sin atributos que sirvan para variantes, nada que escribir');
    contains('name="typed"', $web->get('/admin/catalog/products/' . $product . '/variants')->body);
});

test('con la IA encendida y reglas que no entienden, pregunta a la IA y enseña igualmente la vista previa antes de crear nada', function (): void {
    $app = aiApp([], ['es']);
    list(, $web, $product, $ids) = typedShop($app);
    aiFake($app)->script(aiJson(['choices' => [
        ['attribute' => $ids['color'], 'values' => [$ids['negro'], $ids['rojo']]],
        ['attribute' => $ids['size'], 'values' => [$ids['l']]],
    ]]));
    $r = typedParse($web, $product, 'la camiseta en el tono más oscuro y el más vivo, en grande');
    same(200, $r->status);
    same(1, count(aiFake($app)->requests), 'una sola llamada, al nivel económico');
    same(['Negro / L' => 'new', 'Rojo / L' => 'new'], typedRows($r->body));
    contains('La IA lo ha entendido así. Compruébalo antes de crear nada.', $r->body);
    same(0, prodScalar($app, 'SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$product]), 'nada se crea sin confirmar');
    $request = aiFake($app)->requests[0];
    contains('la camiseta en el tono más oscuro', json_encode($request->messages[0]->content, JSON_UNESCAPED_UNICODE) ?: '', 'la IA recibe el texto');
    contains('Negro', (string) $request->messages[0]->content, 'y los atributos con sus valores');
});

test('con la IA, lo que las reglas ya entienden del todo no gasta ninguna llamada', function (): void {
    $app = aiApp([], ['es']);
    list(, $web, $product) = typedShop($app);
    $r = typedParse($web, $product, 'negro y gris; S a L');
    same(0, count(aiFake($app)->requests), 'sin llamada a la IA');
    same(6, count(typedRows($r->body)));
    notContains('La IA lo ha entendido', $r->body);
});

test('la IA no puede inventar identificadores; si falla o no ayuda, se ve lo que entendieron las reglas', function (): void {
    $app = aiApp([], ['es']);
    list(, $web, $product, $ids) = typedShop($app);
    aiFake($app)->script(aiJson(['choices' => [
        ['attribute' => 9999, 'values' => [1, 2]],
        ['attribute' => $ids['color'], 'values' => [$ids['negro'], 424242, 'x']],
        ['attribute' => $ids['size'], 'values' => [$ids['negro']]],
    ]]));
    $r = typedParse($web, $product, 'algo raro');
    $rows = typedRows($r->body);
    same(['Negro' => 'new'], $rows, 'solo el valor que existe en su atributo');
    // Lo que ya entendían las reglas se conserva si la IA no sabe nada más.
    aiFake($app)->script(aiJson(['choices' => []]));
    $r = typedParse($web, $product, 'negro, verde pistacho');
    same(['Negro' => 'new'], typedRows($r->body));
    contains('No se ha entendido y se deja fuera: verde pistacho', $r->body);

    $failing = aiApp([], ['es']);
    list(, $webFail, $productFail) = typedShop($failing);
    $failing->bind('ai.provider.economy', static function (): AiFailingProvider {
        return new AiFailingProvider('proveedor caído');
    });
    $r = typedParse($webFail, $productFail, 'negro, verde pistacho');
    same(200, $r->status, 'un fallo de la IA no tumba la pantalla');
    contains('La IA no ha podido ayudar ahora mismo.', $r->body);
    same(['Negro' => 'new'], typedRows($r->body), 'y lo que entendieron las reglas sigue ahí');
    notContains('proveedor caído', $r->body, 'el motivo del proveedor no sale en pantalla');
});

test('con la IA apagada o sin permiso de IA no se llama a nadie y el cuadro no habla de ella', function (): void {
    $app = aiApp(['ai.enabled' => false], ['es']);
    list(, $web, $product) = typedShop($app);
    $page = $web->get('/admin/catalog/products/' . $product . '/variants');
    notContains('te ayuda la IA', $page->body);
    $r = typedParse($web, $product, 'texto ilegible');
    same(0, count(aiFake($app)->requests));
    contains('No lo he entendido', $r->body);

    $on = aiApp([], ['es']);
    list(, $webOn, $productOn) = typedShop($on);
    contains('Si no queda claro, te ayuda la IA.', $webOn->get('/admin/catalog/products/' . $productOn . '/variants')->body);
    $on->db()->insert('roles', ['code' => 'catalogeditor', 'name' => 'Catalog editor', 'caps' => json_encode(['dashboard.view', 'catalog.view', 'catalog.edit']), 'system' => 0]);
    $editor = adminClient($on, 'catalogeditor');
    $r = typedParse($editor, $productOn, 'texto ilegible');
    same(200, $r->status);
    same(0, count(aiFake($on)->requests), 'quien no puede usar la IA no la gasta');
    notContains('te ayuda la IA', $r->body);
});
