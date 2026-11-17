<?php
/**
 * AIFEC — pruebas del esquema y la validación de bloques.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cms\Blocks\BlockSchema;

/** @return array<string,mixed> */
function cmsbBlock(string $type, array $attrs = [], array $children = []): array
{
    return ['type' => $type, 'attrs' => $attrs, 'children' => $children];
}

test('types() describe los 25 tipos del modelo', function (): void {
    $types = BlockSchema::types();
    $expected = [
        'paragraph', 'heading', 'image', 'gallery', 'list', 'quote', 'table', 'columns', 'column', 'group', 'cover',
        'buttons', 'separator', 'spacer', 'embed', 'video', 'details', 'code', 'html', 'products', 'product', 'posts', 'form', 'toc', 'company',
    ];
    same(count($expected), count($types));
    foreach ($expected as $type) {
        check(isset($types[$type]), 'falta el tipo ' . $type);
        check(isset($types[$type]['attrs'], $types[$type]['required'], $types[$type]['children']), 'estructura de ' . $type);
    }
    same(['html'], $types['paragraph']['required']);
    same('columns', $types['columns']['children']);
    check(BlockSchema::isContainer('group') && BlockSchema::isContainer('details') && !BlockSchema::isContainer('paragraph'), 'contenedores');
});

test('un párrafo con una imagen con onerror sale sin nada ejecutable', function (): void {
    $out = BlockSchema::validate([cmsbBlock('paragraph', ['html' => 'Hola <img src=x onerror=alert(1)> <a href="javascript:alert(2)">enlace</a> <script>alert(3)</script>'])]);
    $json = json_encode($out) ?: '';
    $html = $out[0]['attrs']['html'];
    notContains('<img', $html);
    notContains('onerror', $json);
    notContains('javascript', $json);
    notContains('<script', $html);
    contains('Hola', $html);
    contains('enlace', $html);
});

test('un bloque html con script sale sin nada ejecutable, pero conserva el vídeo permitido', function (): void {
    $out = BlockSchema::validate([cmsbBlock('html', [
        'html' => '<div onclick="x()">ok</div><script>alert(1)</script><iframe src="https://www.youtube.com/embed/abcdefghijk"></iframe><iframe src="https://malo.example/x"></iframe>',
    ])]);
    $html = $out[0]['attrs']['html'];
    notContains('<script', $html);
    notContains('onclick', $html);
    notContains('malo.example', $html);
    contains('youtube', $html);
    contains('ok', $html);
});

test('los esquemas de dirección peligrosos se quitan de imágenes, botones, vídeos y portadas', function (): void {
    $out = BlockSchema::validate([
        cmsbBlock('image', ['src' => 'javascript:alert(1)', 'href' => 'data:text/html;base64,AAAA']),
        cmsbBlock('buttons', ['items' => [['text' => 'Ir', 'href' => 'jav&#x09;ascript:alert(1)'], ['text' => 'Bien', 'href' => 'https://ok.example/']]]),
        cmsbBlock('video', ['src' => 'vbscript:x', 'poster' => 'https://ok.example/p.jpg']),
        cmsbBlock('cover', ['src' => 'JaVaScRiPt:alert(1)']),
        cmsbBlock('embed', ['url' => 'javascript:alert(1)']),
    ]);
    check(!isset($out[0]['attrs']['src']) && !isset($out[0]['attrs']['href']), 'imagen');
    check(!isset($out[1]['attrs']['items'][0]['href']), 'botón peligroso');
    same('https://ok.example/', $out[1]['attrs']['items'][1]['href']);
    check(!isset($out[2]['attrs']['src']), 'vídeo');
    same('https://ok.example/p.jpg', $out[2]['attrs']['poster']);
    check(!isset($out[3]['attrs']['src']), 'portada');
    check(!isset($out[4]['attrs']['url']), 'embed');
});

test('un tipo desconocido se convierte en html con review', function (): void {
    $out = BlockSchema::validate([cmsbBlock('inventado', ['html' => '<p>algo <script>x</script></p>', 'otro' => 1])]);
    same('html', $out[0]['type']);
    same(true, $out[0]['attrs']['review']);
    notContains('<script', $out[0]['attrs']['html']);
    same(['html', 'review'], array_keys($out[0]['attrs']));
});

test('validate no lanza con basura y devuelve bloques utilizables', function (): void {
    $garbage = [
        [['type' => 5]],
        [['type' => null]],
        [['type' => ['paragraph']]],
        [['attrs' => ['html' => 'sin tipo']]],
        [['type' => 'paragraph']],
        [['type' => 'paragraph', 'attrs' => 'no es un array']],
        [['type' => 'paragraph', 'attrs' => ['html' => ['no', 'texto']]]],
        [['type' => 'heading', 'attrs' => ['level' => 'xx', 'html' => 3.5]]],
        [['type' => 'list', 'attrs' => ['items' => 'no']]],
        [['type' => 'table', 'attrs' => ['rows' => [1, 'a', [null, ['x']]]]]],
        ['texto', 5, null, true],
        [['type' => 'group', 'children' => 'no']],
        [['type' => 'columns', 'children' => [['type' => 'paragraph'], 'raro', 7]]],
        [],
    ];
    foreach ($garbage as $i => $blocks) {
        $out = BlockSchema::validate($blocks);
        check(is_array($out), 'caso ' . $i);
        foreach ($out as $b) {
            check(isset($b['type'], $b['attrs'], $b['children']) && is_array($b['attrs']) && is_array($b['children']), 'forma del bloque, caso ' . $i);
        }
        same($out, BlockSchema::validate($out), 'idempotencia, caso ' . $i);
    }
    $p = BlockSchema::validate([cmsbBlock('paragraph')]);
    same(['html' => ''], $p[0]['attrs']);
    $e = BlockSchema::validate([['type' => 5]]);
    same('html', $e[0]['type']);
    same(true, $e[0]['attrs']['review']);
});

test('validate acota una profundidad de 30 sin lanzar', function (): void {
    $block = cmsbBlock('paragraph', ['html' => 'fondo']);
    for ($i = 0; $i < 30; ++$i) {
        $block = cmsbBlock('group', [], [$block]);
    }
    $out = BlockSchema::validate([$block]);
    $depth = 0;
    $cur = $out;
    while ($cur !== []) {
        ++$depth;
        $cur = $cur[0]['children'];
    }
    same(BlockSchema::MAX_DEPTH, $depth);
    same($out, BlockSchema::validate($out));
    $errors = BlockSchema::errors([$block]);
    check(count($errors) >= 1, 'errors() avisa de la profundidad');
});

test('validate es idempotente con un documento variado', function (): void {
    $doc = [
        cmsbBlock('heading', ['level' => 12, 'html' => 'Título <b>x</b>', 'anchor' => '1mal', 'align' => 'CENTER']),
        cmsbBlock('image', ['src' => 'https://e.com/a.jpg', 'alt' => 'Tom &amp; <b>Jerry</b>', 'width' => '300', 'height' => -5, 'caption' => '<p>pie</p>']),
        cmsbBlock('gallery', ['images' => [['src' => 'https://e.com/1.jpg'], ['alt' => 'sin src'], 'x', ['media_id' => '7', 'caption' => 'c']], 'columns' => 99]),
        cmsbBlock('list', ['ordered' => 'true', 'items' => ['uno', ['html' => 'dos', 'items' => [['html' => 'dos-uno']], 'ordered' => 1], 3]]),
        cmsbBlock('quote', ['html' => '<p>cita</p><script>x</script>', 'cite' => '<i>yo</i>']),
        cmsbBlock('table', ['head' => [['A', 'B']], 'rows' => [['<b>1</b>', 2], ['x']], 'caption' => 'pie']),
        cmsbBlock('columns', [], [cmsbBlock('paragraph', ['html' => 'suelto']), cmsbBlock('column', ['width' => '33.333'], [cmsbBlock('separator')])]),
        cmsbBlock('cover', ['src' => 'https://e.com/c.jpg', 'overlay' => '#123', 'dim' => 150, 'min_height' => 'alto'], [cmsbBlock('spacer', ['height' => 9999])]),
        cmsbBlock('products', ['mode' => 'CATEGORY', 'ids' => [3, '4', 'x', 3, -1], 'category_id' => 9, 'limit' => 1000, 'columns' => 0]),
        cmsbBlock('form', ['form_id' => 'contacto']),
        cmsbBlock('toc', ['max_level' => 9]),
        cmsbBlock('code', ['code' => "<script>a()</script>\n\tfin", 'language' => 'PHP']),
    ];
    $once = BlockSchema::validate($doc);
    same($once, BlockSchema::validate($once));
    same($once, BlockSchema::validate(BlockSchema::validate($once)));
});

test('los números y los niveles se acotan', function (): void {
    $out = BlockSchema::validate([
        cmsbBlock('heading', ['level' => 12, 'html' => 'a']),
        cmsbBlock('heading', ['level' => -3, 'html' => 'b']),
        cmsbBlock('heading', ['level' => 'tres', 'html' => 'c']),
        cmsbBlock('spacer', ['height' => 9999]),
        cmsbBlock('spacer', ['height' => 0]),
        cmsbBlock('gallery', ['images' => [['src' => 'https://e.com/a.jpg']], 'columns' => 99]),
        cmsbBlock('products', ['mode' => 'latest', 'limit' => 1000, 'columns' => 0, 'ids' => [3, '4', 'x', 3, -1]]),
        cmsbBlock('cover', ['dim' => 150, 'min_height' => -20]),
        cmsbBlock('column', ['width' => 250]),
        cmsbBlock('toc', ['max_level' => 99]),
    ]);
    same(6, $out[0]['attrs']['level']);
    same(1, $out[1]['attrs']['level']);
    same(2, $out[2]['attrs']['level'], 'un nivel que no es número vuelve al de por defecto');
    same(400, $out[3]['attrs']['height']);
    same(1, $out[4]['attrs']['height']);
    same(6, $out[5]['attrs']['columns']);
    same(['mode' => 'latest', 'ids' => [3, 4], 'limit' => 100, 'columns' => 1], $out[6]['attrs']);
    same(100, $out[7]['attrs']['dim']);
    same(0, $out[7]['attrs']['min_height']);
    same('group', $out[8]['type'], 'una columna suelta es un grupo');
    same(6, $out[9]['attrs']['max_level']);
});

test('las columnas solo admiten columnas: lo demás se envuelve', function (): void {
    $out = BlockSchema::validate([cmsbBlock('columns', [], [
        cmsbBlock('paragraph', ['html' => 'suelto']),
        cmsbBlock('column', ['width' => 40], [cmsbBlock('paragraph', ['html' => 'dentro'])]),
    ])]);
    same(['column', 'column'], array_map(static function (array $c): string {
        return $c['type'];
    }, $out[0]['children']));
    same('suelto', $out[0]['children'][0]['children'][0]['attrs']['html']);
    same(40, $out[0]['children'][1]['attrs']['width']);
});

test('un bloque que no admite hijos los pierde', function (): void {
    $out = BlockSchema::validate([cmsbBlock('paragraph', ['html' => 'a'], [cmsbBlock('paragraph', ['html' => 'b'])])]);
    same([], $out[0]['children']);
});

test('los atributos desconocidos se quitan y los conocidos se conservan en el orden del esquema', function (): void {
    $out = BlockSchema::validate([cmsbBlock('image', ['zeta' => 1, 'align' => 'right', 'src' => 'https://e.com/i.png', 'onclick' => 'x()', 'alt' => 'a'])]);
    same(['src', 'alt', 'align'], array_keys($out[0]['attrs']));
});

test('el estilo CSS se sanea con la lista blanca del saneador', function (): void {
    $out = BlockSchema::validate([
        cmsbBlock('paragraph', ['html' => 'x', 'style' => 'color: red; background-image:url(http://x/y.png); behavior:url(x); font-size:20px; width:expression(alert(1))']),
        cmsbBlock('group', ['style' => 'padding:10px 20px;margin-top:-5px;position:fixed']),
        cmsbBlock('cover', ['overlay' => 'red;background:url(x)', 'src' => 'https://e.com/c.jpg']),
        cmsbBlock('cover', ['overlay' => 'rgba(0, 0, 0, .5)']),
    ]);
    same('color:red;font-size:20px', $out[0]['attrs']['style']);
    same('padding:10px 20px;margin-top:-5px', $out[1]['attrs']['style']);
    check(!isset($out[2]['attrs']['overlay']), 'color con inyección');
    same('rgba(0, 0, 0, .5)', $out[3]['attrs']['overlay']);
});

test('el código se guarda tal cual: sin etiquetas interpretadas', function (): void {
    $code = "<script>alert('a & b')</script>\n\tfin";
    $out = BlockSchema::validate([cmsbBlock('code', ['code' => $code, 'language' => 'HTML'])]);
    same($code, $out[0]['attrs']['code']);
    same('html', $out[0]['attrs']['language']);
});

test('el texto con «&», comillas, tildes, eñes y emojis sobrevive a validate', function (): void {
    $text = 'Tom &amp; Jerry: «ñandú», “año” 😀';
    $out = BlockSchema::validate([cmsbBlock('paragraph', ['html' => $text]), cmsbBlock('image', ['src' => 'https://e.com/i.png', 'alt' => 'Tom & Jerry “ñ” 😀'])]);
    same($text, $out[0]['attrs']['html']);
    same('Tom & Jerry “ñ” 😀', $out[1]['attrs']['alt']);
    same($out, BlockSchema::validate($out));
});

test('los atributos de texto plano pierden las etiquetas', function (): void {
    $out = BlockSchema::validate([cmsbBlock('image', ['src' => 'https://e.com/i.png', 'alt' => "Una <b>foto</b>\n  con <script>x()</script> saltos"])]);
    same('Una foto con saltos', $out[0]['attrs']['alt']);
});

test('errors() cuenta obligatorios que faltan, hijos indebidos y tipos raros', function (): void {
    $errors = BlockSchema::errors([
        cmsbBlock('paragraph'),
        cmsbBlock('image', ['alt' => 'sin foto']),
        cmsbBlock('heading', ['html' => 'sin nivel']),
        cmsbBlock('paragraph', ['html' => 'a'], [cmsbBlock('separator')]),
        cmsbBlock('columns', [], [cmsbBlock('paragraph', ['html' => 'x'])]),
        cmsbBlock('column'),
        cmsbBlock('inventado'),
        ['attrs' => []],
        'texto',
        cmsbBlock('embed', ['url' => '']),
        cmsbBlock('products'),
    ]);
    $all = implode("\n", $errors);
    contains('blocks[0] (paragraph): missing required attribute "html"', $all);
    contains('blocks[1] (image): needs at least one of "src", "media_id"', $all);
    contains('blocks[2] (heading): missing required attribute "level"', $all);
    contains('blocks[3] (paragraph): this block cannot have children', $all);
    contains('only column blocks can be inside columns', $all);
    contains('blocks[5] (column): column must be inside columns', $all);
    contains('unknown block type "inventado"', $all);
    contains('blocks[7]: missing block type', $all);
    contains('blocks[8]: is not a block', $all);
    contains('blocks[9] (embed): missing required attribute "url"', $all);
    contains('blocks[10] (products): missing required attribute "mode"', $all);
});

test('errors() no se queja de un documento correcto', function (): void {
    $errors = BlockSchema::errors([
        cmsbBlock('paragraph', ['html' => '']),
        cmsbBlock('heading', ['level' => 2, 'html' => 'T']),
        cmsbBlock('image', ['media_id' => 5]),
        cmsbBlock('columns', [], [cmsbBlock('column', [], [cmsbBlock('separator')])]),
        cmsbBlock('list', ['items' => []]),
        cmsbBlock('table', ['rows' => []]),
    ]);
    same([], $errors);
    same([], BlockSchema::errors([]));
});
