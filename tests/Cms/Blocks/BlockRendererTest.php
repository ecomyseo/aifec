<?php
/**
 * AIFEC — pruebas del pintado de bloques a HTML.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cms\Blocks\BlockRenderer;
use Aifec\Cms\Blocks\BlockSchema;
use Aifec\Text\HtmlSanitizer;

/** @return array<string,mixed> */
function cmsbR(string $type, array $attrs = [], array $children = []): array
{
    return ['type' => $type, 'attrs' => $attrs, 'children' => $children];
}

function cmsbRender(array $blocks, array $dynamic = []): string
{
    return (new BlockRenderer($dynamic))->render($blocks);
}

test('párrafo y encabezado llevan las clases estables y su ancla', function (): void {
    $html = cmsbRender([
        cmsbR('paragraph', ['html' => 'Hola <strong>mundo</strong>', 'align' => 'center']),
        cmsbR('heading', ['level' => 3, 'html' => 'Mi título', 'anchor' => 'mi-titulo']),
        cmsbR('heading', ['level' => 2, 'html' => 'Otro título']),
    ]);
    contains('<p class="af-b af-b-paragraph" style="text-align:center">Hola <strong>mundo</strong></p>', $html);
    contains('<h3 class="af-b af-b-heading" id="mi-titulo">Mi título</h3>', $html);
    contains('<h2 class="af-b af-b-heading" id="otro-titulo">Otro título</h2>', $html);
});

test('una imagen: figura, carga diferida, alt escapado, enlace y pie', function (): void {
    $html = cmsbRender([cmsbR('image', ['src' => 'https://e.com/a.jpg', 'alt' => 'Tom & "Jerry" <b>', 'caption' => 'Pie <em>x</em>', 'href' => 'https://e.com/d', 'width' => 300, 'height' => 200, 'align' => 'right'])]);
    contains('<figure class="af-b af-b-image af-align-right">', $html);
    contains('<a href="https://e.com/d"><img src="https://e.com/a.jpg" alt="Tom &amp; &quot;Jerry&quot; &lt;b&gt;" width="300" height="200" loading="lazy" decoding="async"></a>', $html);
    contains('<figcaption>Pie <em>x</em></figcaption>', $html);
});

test('una imagen con media_id usa el pintor de imágenes; sin pintor ni dirección no pinta nada', function (): void {
    $painter = static function (array $attrs): string {
        return '<img src="/media/' . (int) $attrs['media_id'] . '.webp" alt="' . e($attrs['alt'] ?? '') . '">';
    };
    $html = cmsbRender([cmsbR('image', ['media_id' => 7, 'alt' => 'Foto', 'caption' => 'Pie'])], ['image' => $painter]);
    contains('<figure class="af-b af-b-image"><img src="/media/7.webp" alt="Foto"><figcaption>Pie</figcaption></figure>', $html);

    same('', cmsbRender([cmsbR('image', ['media_id' => 7])]));
    // Con pintor pero también con dirección: gana el pintor para media_id.
    $both = cmsbRender([cmsbR('image', ['media_id' => 3, 'src' => 'https://e.com/x.jpg'])], ['image' => $painter]);
    contains('/media/3.webp', $both);
    notContains('e.com/x.jpg', $both);
});

test('el pintor de imágenes no puede colar nada ejecutable', function (): void {
    $evil = static function (array $attrs): string {
        return '<img src="x" onerror="alert(1)"><script>alert(2)</script>';
    };
    $html = cmsbRender([cmsbR('image', ['media_id' => 1])], ['image' => $evil]);
    notContains('onerror', $html);
    notContains('<script', $html);
});

test('una galería pinta una figura por imagen y salta las que no tienen nada', function (): void {
    $html = cmsbRender([cmsbR('gallery', ['columns' => 2, 'images' => [
        ['src' => 'https://e.com/1.jpg', 'alt' => 'uno', 'caption' => 'Pie 1'],
        ['alt' => 'sin nada'],
        ['src' => 'https://e.com/2.jpg'],
    ]])]);
    contains('<div class="af-b af-b-gallery af-cols-2">', $html);
    same(2, substr_count($html, '<figure class="af-b-gallery-item">'));
    contains('<figcaption>Pie 1</figcaption>', $html);
    same('', cmsbRender([cmsbR('gallery', ['images' => []])]));
});

test('listas anidadas con el tipo de lista de cada nivel', function (): void {
    $html = cmsbRender([cmsbR('list', ['ordered' => false, 'items' => [
        ['html' => 'a', 'items' => [['html' => 'a1'], ['html' => 'a2', 'items' => [['html' => 'a2x']], 'ordered' => false]], 'ordered' => true],
        ['html' => 'b'],
    ]])]);
    same('<ul class="af-b af-b-list"><li>a<ol><li>a1</li><li>a2<ul><li>a2x</li></ul></li></ol></li><li>b</li></ul>', $html);
});

test('citas, tablas y detalles', function (): void {
    $html = cmsbRender([
        cmsbR('quote', ['html' => 'Una cita', 'cite' => 'Yo']),
        cmsbR('quote', ['html' => '<p>Uno</p><p>Dos</p>']),
        cmsbR('table', ['head' => [['A', 'B']], 'rows' => [['1', '<b>2</b>']], 'caption' => 'Pie']),
        cmsbR('details', ['summary' => '¿Qué?'], [cmsbR('paragraph', ['html' => 'Eso.'])]),
    ]);
    contains('<blockquote class="af-b af-b-quote"><p>Una cita</p><cite>Yo</cite></blockquote>', $html);
    contains('<blockquote class="af-b af-b-quote"><p>Uno</p><p>Dos</p></blockquote>', $html);
    contains('<figure class="af-b af-b-table"><table><thead><tr><th scope="col">A</th><th scope="col">B</th></tr></thead><tbody><tr><td>1</td><td><b>2</b></td></tr></tbody></table><figcaption>Pie</figcaption></figure>', $html);
    contains('<details class="af-b af-b-details"><summary>¿Qué?</summary><p class="af-b af-b-paragraph">Eso.</p></details>', $html);
});

test('columnas con su ancho en flex-basis y grupos con su etiqueta', function (): void {
    $html = cmsbRender([
        cmsbR('columns', [], [
            cmsbR('column', ['width' => 33], [cmsbR('paragraph', ['html' => 'a'])]),
            cmsbR('column', ['width' => 33.33], [cmsbR('paragraph', ['html' => 'b'])]),
            cmsbR('column', [], [cmsbR('paragraph', ['html' => 'c'])]),
        ]),
        cmsbR('group', ['tag' => 'section', 'class_hint' => 'wide', 'style' => 'padding:10px'], [cmsbR('separator')]),
    ]);
    contains('<div class="af-b af-b-columns"><div class="af-b-column" style="flex-basis:33%">', $html);
    contains('<div class="af-b-column" style="flex-basis:33.33%">', $html);
    contains('<div class="af-b-column"><p class="af-b af-b-paragraph">c</p></div>', $html);
    contains('<section class="af-b af-b-group af-wide" style="padding:10px"><hr class="af-b af-b-separator"></section>', $html);
});

test('una portada: imagen, capa de color, mínimo de altura y contenido', function (): void {
    $html = cmsbRender([cmsbR('cover', ['src' => 'https://e.com/c.jpg', 'overlay' => '#112233', 'dim' => 60, 'min_height' => 400, 'class_hint' => 'full'], [
        cmsbR('heading', ['level' => 1, 'html' => 'Hola']),
        cmsbR('buttons', ['items' => [['text' => 'Ir', 'href' => 'https://e.com/x']]]),
    ])]);
    contains('<div class="af-b af-b-cover af-full" style="min-height:400px"><img class="af-b-cover-img" src="https://e.com/c.jpg" alt="" loading="lazy" decoding="async">', $html);
    contains('<span class="af-b-cover-overlay" style="background-color:#112233;opacity:0.6"></span>', $html);
    contains('<div class="af-b-cover-inner"><h1 class="af-b af-b-heading" id="hola">Hola</h1>', $html);
    contains('<a class="af-btn af-btn-fill" href="https://e.com/x">Ir</a>', $html);
});

test('botones: relleno, contorno, nueva pestaña con rel y sin enlace', function (): void {
    $html = cmsbRender([cmsbR('buttons', ['align' => 'center', 'items' => [
        ['text' => 'Uno', 'href' => 'https://e.com/1', 'variant' => 'fill'],
        ['text' => 'Dos', 'href' => 'https://e.com/2', 'variant' => 'outline', 'target' => '_blank'],
        ['text' => 'Tres'],
        ['text' => 'Malo', 'href' => 'javascript:alert(1)'],
    ]])]);
    contains('<div class="af-b af-b-buttons" style="text-align:center">', $html);
    contains('<a class="af-btn af-btn-fill" href="https://e.com/1">Uno</a>', $html);
    contains('<a class="af-btn af-btn-outline" href="https://e.com/2" target="_blank" rel="noopener noreferrer">Dos</a>', $html);
    contains('<span class="af-btn af-btn-fill">Tres</span>', $html);
    notContains('javascript', $html);
});

test('embed de YouTube y Vimeo con la dirección sin cookies y carga diferida', function (): void {
    $urls = [
        'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
        'https://youtu.be/dQw4w9WgXcQ',
        'https://www.youtube.com/shorts/dQw4w9WgXcQ',
        'https://www.youtube.com/embed/dQw4w9WgXcQ',
    ];
    foreach ($urls as $url) {
        $html = cmsbRender([cmsbR('embed', ['provider' => 'youtube', 'url' => $url, 'caption' => 'Pie'])]);
        contains('<iframe src="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ" title="Pie" loading="lazy" allowfullscreen', $html, $url);
        contains('<figcaption>Pie</figcaption>', $html);
    }
    $vi = cmsbRender([cmsbR('embed', ['provider' => 'vimeo', 'url' => 'https://vimeo.com/123456789'])]);
    contains('<iframe src="https://player.vimeo.com/video/123456789?dnt=1" loading="lazy"', $vi);
    // Sin proveedor declarado se reconoce por la dirección.
    $auto = cmsbRender([cmsbR('embed', ['url' => 'https://youtu.be/dQw4w9WgXcQ'])]);
    contains('youtube-nocookie.com/embed/dQw4w9WgXcQ', $auto);
});

test('un embed que no es de vídeo, o con un identificador raro, queda como enlace', function (): void {
    $other = cmsbRender([cmsbR('embed', ['provider' => 'other', 'url' => 'https://ejemplo.com/p?a=1&b=2'])]);
    contains('<a href="https://ejemplo.com/p?a=1&amp;b=2" rel="noopener">https://ejemplo.com/p?a=1&amp;b=2</a>', $other);
    notContains('<iframe', $other);
    $weird = cmsbRender([cmsbR('embed', ['provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v="><script>x</script>'])]);
    notContains('<iframe', $weird);
    notContains('<script', $weird);
    same('', cmsbRender([cmsbR('embed', ['url' => 'javascript:alert(1)'])]));
});

test('vídeo, código y separador', function (): void {
    $html = cmsbRender([
        cmsbR('video', ['src' => 'https://e.com/v.mp4', 'poster' => 'https://e.com/p.jpg', 'caption' => 'Demo']),
        cmsbR('code', ['code' => "if (a < b && c) { echo \"<b>\"; }", 'language' => 'php']),
        cmsbR('code', ['code' => 'x', 'language' => 'a" onload="x']),
        cmsbR('separator'),
        cmsbR('spacer', ['height' => 40]),
    ]);
    contains('<figure class="af-b af-b-video"><video controls preload="metadata" src="https://e.com/v.mp4" poster="https://e.com/p.jpg"></video><figcaption>Demo</figcaption></figure>', $html);
    contains('<pre class="af-b af-b-code"><code class="language-php">if (a &lt; b &amp;&amp; c) { echo &quot;&lt;b&gt;&quot;; }</code></pre>', $html);
    contains('<pre class="af-b af-b-code"><code>x</code></pre>', $html);
    contains('<hr class="af-b af-b-separator">', $html);
    contains('<div class="af-b af-b-spacer" style="height:40px" aria-hidden="true"></div>', $html);
});

test('los bloques dinámicos usan su pintor y, sin pintor, no pintan nada', function (): void {
    $seen = [];
    $dynamic = [
        'products' => static function (array $attrs) use (&$seen): string {
            $seen[] = $attrs;

            return '<ul class="lista"><li>producto</li></ul>';
        },
        'form' => static function (array $attrs): string {
            return '<form id="f' . (int) $attrs['form_id'] . '"></form>';
        },
    ];
    $blocks = [
        cmsbR('products', ['mode' => 'latest', 'limit' => 4]),
        cmsbR('product', ['id' => 9]),
        cmsbR('posts', ['limit' => 3]),
        cmsbR('form', ['form_id' => 2]),
    ];
    $html = cmsbRender($blocks, $dynamic);
    contains('<div class="af-b af-b-products"><ul class="lista"><li>producto</li></ul></div>', $html);
    contains('<div class="af-b af-b-form"><form id="f2"></form></div>', $html);
    notContains('af-b-product"', $html);
    notContains('af-b-posts', $html);
    same([['mode' => 'latest', 'limit' => 4]], $seen);
    same('', cmsbRender($blocks));
});

test('el índice enlaza a los encabezados y genera las anclas que faltan sin repetirlas', function (): void {
    $blocks = [
        cmsbR('toc', ['max_level' => 3]),
        cmsbR('heading', ['level' => 2, 'html' => 'Cómo empezar']),
        cmsbR('heading', ['level' => 3, 'html' => 'Cómo empezar']),
        cmsbR('group', [], [cmsbR('heading', ['level' => 2, 'html' => 'Fin', 'anchor' => 'cierre'])]),
        cmsbR('heading', ['level' => 2, 'html' => 'Otro', 'anchor' => 'como-empezar']),
        cmsbR('heading', ['level' => 4, 'html' => 'Muy hondo']),
    ];
    $html = cmsbRender($blocks);
    preg_match_all('/<h[1-6][^>]* id="([^"]+)"/', $html, $ids);
    same(['como-empezar-2', 'como-empezar-3', 'cierre', 'como-empezar', 'muy-hondo'], $ids[1]);
    same(count($ids[1]), count(array_unique($ids[1])), 'ninguna ancla repetida');
    preg_match_all('/<a href="#([^"]+)">/', $html, $links);
    same(['como-empezar-2', 'como-empezar-3', 'cierre', 'como-empezar'], $links[1]);
    foreach ($links[1] as $link) {
        contains(' id="' . $link . '"', $html);
    }
    contains('<li class="af-toc-l3"><a href="#como-empezar-3">Cómo empezar</a></li>', $html);
    notContains('Muy hondo</a>', $html);
    same('', cmsbRender([cmsbR('toc')]), 'sin encabezados no hay índice');
});

test('el pintado no se fía de los bloques: sanea aunque no se hayan validado', function (): void {
    $blocks = [
        ['type' => 'paragraph', 'attrs' => ['html' => 'a <img src=x onerror=alert(1)><script>alert(2)</script> <a href="javascript:alert(3)">b</a>', 'style' => 'background:url(javascript:alert(4))']],
        ['type' => 'html', 'attrs' => ['html' => '<script>alert(5)</script><p onclick="x()">ok</p>']],
        ['type' => 'image', 'attrs' => ['src' => 'javascript:alert(6)', 'media_id' => 0]],
        ['type' => 'quote', 'attrs' => ['html' => '<p onmouseover="x()">q</p>', 'cite' => '<script>1</script>c']],
        ['type' => 'table', 'attrs' => ['rows' => [['<img src=x onerror=1>y']], 'caption' => '<script>2</script>']],
        ['type' => 'inventado', 'attrs' => ['html' => '<script>alert(7)</script>']],
    ];
    $html = cmsbRender($blocks);
    foreach (['<script', 'onerror', 'onclick', 'onmouseover', 'javascript:', 'alert('] as $bad) {
        notContains($bad, $html);
    }
    contains('ok', $html);
});

test('el HTML pintado es el mismo con los bloques validados y sin validar', function (): void {
    $blocks = [
        cmsbR('heading', ['level' => 2, 'html' => 'T']),
        cmsbR('paragraph', ['html' => 'Tom &amp; Jerry «ñ» 😀']),
        cmsbR('list', ['items' => [['html' => 'x']]]),
    ];
    same(cmsbRender($blocks), cmsbRender(BlockSchema::validate($blocks)));
    contains('Tom &amp; Jerry «ñ» 😀', cmsbRender($blocks));
});

test('repintar con el mismo pintor da lo mismo y las anclas no se acumulan entre llamadas', function (): void {
    $r = new BlockRenderer();
    $blocks = [cmsbR('heading', ['level' => 2, 'html' => 'Uno']), cmsbR('heading', ['level' => 2, 'html' => 'Uno'])];
    $a = $r->render($blocks);
    $b = $r->render($blocks);
    same($a, $b);
    contains('id="uno-2"', $a);
});

test('lo que se pinta sigue siendo HTML estable al pasarlo por el saneador de contenido', function (): void {
    $html = cmsbRender([
        cmsbR('paragraph', ['html' => 'a <b>b</b>']),
        cmsbR('list', ['items' => [['html' => 'x', 'items' => [['html' => 'y']]]]]),
        cmsbR('table', ['rows' => [['1', '2']]]),
    ]);
    same(HtmlSanitizer::clean($html, 'content'), HtmlSanitizer::clean(HtmlSanitizer::clean($html, 'content'), 'content'));
    contains('<li>x<ul><li>y</li></ul></li>', HtmlSanitizer::clean($html, 'content'));
});
