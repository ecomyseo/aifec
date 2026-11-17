<?php
/**
 * AIFEC — pruebas del conversor de bloques de WordPress al modelo de bloques.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cms\Blocks\GutenbergParser;

/**
 * @return array<int,array<string,mixed>>
 */
function cmsbParse(string $content): array
{
    return GutenbergParser::parse($content);
}

/** Los tipos de todos los bloques, en orden de aparición y recorriendo los hijos. */
function cmsbTypes(array $blocks): array
{
    $out = [];
    foreach ($blocks as $b) {
        $out[] = $b['type'];
        foreach (cmsbTypes($b['children']) as $t) {
            $out[] = $t;
        }
    }

    return $out;
}

test('un párrafo se convierte con su HTML en línea', function (): void {
    $b = cmsbParse('<!-- wp:paragraph --><p>Hola <strong>mundo</strong></p><!-- /wp:paragraph -->');
    same([['type' => 'paragraph', 'attrs' => ['html' => 'Hola <strong>mundo</strong>'], 'children' => []]], $b);
});

test('el espacio de nombres core/ y los saltos de línea no cambian el resultado', function (): void {
    $a = cmsbParse("<!-- wp:core/paragraph -->\n<p>Texto</p>\n<!-- /wp:core/paragraph -->");
    $b = cmsbParse('<!-- wp:paragraph --><p>Texto</p><!-- /wp:paragraph -->');
    same($b, $a);
});

test('el texto con «&», comillas, tildes, eñes y emojis llega intacto', function (): void {
    $b = cmsbParse('<!-- wp:paragraph --><p>Tom &amp; Jerry dicen «hola», “ñandú” y &lt;b&gt; 😀 año</p><!-- /wp:paragraph -->');
    $html = $b[0]['attrs']['html'];
    contains('Tom &amp; Jerry', $html);
    contains('«hola»', $html);
    contains('ñandú', $html);
    contains('😀', $html);
    contains('&lt;b&gt;', $html);
    notContains('<b>', $html);
});

test('la alineación y el estilo salen del JSON del comentario', function (): void {
    $b = cmsbParse('<!-- wp:paragraph {"align":"center","style":{"typography":{"fontSize":"20px","fontWeight":"700"},"color":{"text":"#ff0000","background":"#eeeeee"},"spacing":{"padding":{"top":"10px","bottom":"12px"}}}} --><p class="has-text-align-center">x</p><!-- /wp:paragraph -->');
    $a = $b[0]['attrs'];
    same('center', $a['align']);
    contains('font-size:20px', $a['style']);
    contains('font-weight:700', $a['style']);
    contains('color:#ff0000', $a['style']);
    contains('background-color:#eeeeee', $a['style']);
    contains('padding-top:10px', $a['style']);
    contains('padding-bottom:12px', $a['style']);
    notContains('class', $a['html']);
});

test('sin JSON, la alineación y el color salen de la clase y del style del HTML', function (): void {
    $b = cmsbParse('<!-- wp:paragraph --><p class="has-text-align-right has-small-font-size" style="color:#0000ff;text-align:right">x</p><!-- /wp:paragraph -->');
    same('right', $b[0]['attrs']['align']);
    same('color:#0000ff', $b[0]['attrs']['style']);
    same('x', $b[0]['attrs']['html']);
});

test('los valores de la paleta de WordPress sin equivalente se ignoran', function (): void {
    $b = cmsbParse('<!-- wp:paragraph {"textColor":"vivid-red","style":{"color":{"text":"var:preset|color|primary"}}} --><p>x</p><!-- /wp:paragraph -->');
    same(['html' => 'x'], $b[0]['attrs']);
});

test('un encabezado conserva el nivel, el id como ancla y la alineación', function (): void {
    $b = cmsbParse('<!-- wp:heading {"level":3,"textAlign":"center"} --><h3 class="wp-block-heading has-text-align-center" id="mi-ancla">Título</h3><!-- /wp:heading -->');
    same(['level' => 3, 'html' => 'Título', 'anchor' => 'mi-ancla', 'align' => 'center'], $b[0]['attrs']);
    $c = cmsbParse('<!-- wp:heading --><h2 class="wp-block-heading">Sin ancla</h2><!-- /wp:heading -->');
    same(['level' => 2, 'html' => 'Sin ancla'], $c[0]['attrs']);
});

test('una imagen con enlace, pie, medidas y alineación', function (): void {
    $b = cmsbParse('<!-- wp:image {"id":12,"width":"300px","align":"right"} --><figure class="wp-block-image alignright is-resized"><a href="https://ejemplo.com/destino"><img src="https://ejemplo.com/a.jpg" alt="Una foto" class="wp-image-12" style="width:300px"/></a><figcaption class="wp-element-caption">Pie <em>de foto</em></figcaption></figure><!-- /wp:image -->');
    same('image', $b[0]['type']);
    same([
        'src' => 'https://ejemplo.com/a.jpg',
        'alt' => 'Una foto',
        'caption' => 'Pie <em>de foto</em>',
        'href' => 'https://ejemplo.com/destino',
        'width' => 300,
        'align' => 'right',
    ], $b[0]['attrs']);
});

test('una imagen sin dirección no se pierde: va a html para revisar', function (): void {
    $b = cmsbParse('<!-- wp:image --><figure class="wp-block-image"><figcaption>solo pie</figcaption></figure><!-- /wp:image -->');
    same('html', $b[0]['type']);
    same(true, $b[0]['attrs']['review']);
});

test('una galería con bloques de imagen anidados y otra en formato antiguo', function (): void {
    $nuevo = cmsbParse('<!-- wp:gallery {"columns":2} --><figure class="wp-block-gallery has-nested-images columns-2"><!-- wp:image --><figure class="wp-block-image"><img src="https://e.com/1.jpg" alt="uno"/><figcaption>Primera</figcaption></figure><!-- /wp:image --><!-- wp:image --><figure class="wp-block-image"><img src="https://e.com/2.jpg" alt="dos"/></figure><!-- /wp:image --></figure><!-- /wp:gallery -->');
    same('gallery', $nuevo[0]['type']);
    same(2, $nuevo[0]['attrs']['columns']);
    same(2, count($nuevo[0]['attrs']['images']));
    same('Primera', $nuevo[0]['attrs']['images'][0]['caption']);
    same('https://e.com/2.jpg', $nuevo[0]['attrs']['images'][1]['src']);

    $viejo = cmsbParse('<!-- wp:gallery --><figure class="wp-block-gallery columns-3 is-cropped"><ul class="blocks-gallery-grid"><li class="blocks-gallery-item"><figure><img src="https://e.com/a.jpg" alt="a"/><figcaption>Pie A</figcaption></figure></li><li class="blocks-gallery-item"><figure><img src="https://e.com/b.jpg" alt="b"/></figure></li></ul></figure><!-- /wp:gallery -->');
    same(3, $viejo[0]['attrs']['columns']);
    same('Pie A', $viejo[0]['attrs']['images'][0]['caption']);
    check(!isset($viejo[0]['attrs']['images'][1]['caption']), 'la segunda imagen no tiene pie y no debe heredar el de la primera');
});

test('una lista anidada de tres niveles dentro de columnas', function (): void {
    $wp = '<!-- wp:columns --><div class="wp-block-columns"><!-- wp:column {"width":"33.33%"} --><div class="wp-block-column" style="flex-basis:33.33%">'
        . '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Uno'
        . '<!-- wp:list {"ordered":true} --><ol class="wp-block-list"><!-- wp:list-item --><li>Uno-uno'
        . '<!-- wp:list --><ul class="wp-block-list"><!-- wp:list-item --><li>Uno-uno-uno</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
        . '</li><!-- /wp:list-item --></ol><!-- /wp:list -->'
        . '</li><!-- /wp:list-item --><!-- wp:list-item --><li>Dos</li><!-- /wp:list-item --></ul><!-- /wp:list -->'
        . '</div><!-- /wp:column --><!-- wp:column --><div class="wp-block-column"><!-- wp:paragraph --><p>Otra</p><!-- /wp:paragraph --></div><!-- /wp:column --></div><!-- /wp:columns -->';
    $b = cmsbParse($wp);
    same('columns', $b[0]['type']);
    same(2, count($b[0]['children']));
    $col = $b[0]['children'][0];
    same('column', $col['type']);
    same(33.33, $col['attrs']['width']);
    $list = $col['children'][0];
    same('list', $list['type']);
    same(false, $list['attrs']['ordered']);
    $items = $list['attrs']['items'];
    same(2, count($items));
    same('Uno', $items[0]['html']);
    same('Dos', $items[1]['html']);
    same(true, $items[0]['ordered']);
    $second = $items[0]['items'][0];
    same('Uno-uno', $second['html']);
    same(false, $second['ordered']);
    same('Uno-uno-uno', $second['items'][0]['html']);
    same('paragraph', $b[0]['children'][1]['children'][0]['type']);
});

test('una lista en HTML antiguo, sin bloques list-item, también se anida', function (): void {
    $b = cmsbParse('<!-- wp:list {"ordered":true} --><ol><li>a<ul><li>b</li><li>c<ol><li>d</li></ol></li></ul></li><li>e</li></ol><!-- /wp:list -->');
    $list = $b[0]['attrs'];
    same(true, $list['ordered']);
    same('a', $list['items'][0]['html']);
    same(false, $list['items'][0]['ordered']);
    same('b', $list['items'][0]['items'][0]['html']);
    same('d', $list['items'][0]['items'][1]['items'][0]['html']);
    same('e', $list['items'][1]['html']);
});

test('citas: con párrafos dentro, en HTML antiguo y pullquote', function (): void {
    $a = cmsbParse('<!-- wp:quote --><blockquote class="wp-block-quote"><!-- wp:paragraph --><p>Cita uno</p><!-- /wp:paragraph --><!-- wp:paragraph --><p>Cita dos</p><!-- /wp:paragraph --><cite>Autora</cite></blockquote><!-- /wp:quote -->');
    same('quote', $a[0]['type']);
    same('<p>Cita uno</p><p>Cita dos</p>', $a[0]['attrs']['html']);
    same('Autora', $a[0]['attrs']['cite']);

    $b = cmsbParse('<!-- wp:quote --><blockquote class="wp-block-quote"><p>Vieja</p><cite>Alguien</cite></blockquote><!-- /wp:quote -->');
    same('<p>Vieja</p>', $b[0]['attrs']['html']);
    same('Alguien', $b[0]['attrs']['cite']);

    $c = cmsbParse('<!-- wp:pullquote --><figure class="wp-block-pullquote"><blockquote><p>Destacada</p><cite>Fuente</cite></blockquote></figure><!-- /wp:pullquote -->');
    same('quote', $c[0]['type']);
    same('<p>Destacada</p>', $c[0]['attrs']['html']);
    same('Fuente', $c[0]['attrs']['cite']);
});

test('una tabla separa cabecera, cuerpo y pie', function (): void {
    $b = cmsbParse('<!-- wp:table --><figure class="wp-block-table"><table><thead><tr><th>A</th><th>B</th></tr></thead><tbody><tr><td>1</td><td><strong>2</strong></td></tr><tr><td>3</td><td>4</td></tr></tbody></table><figcaption class="wp-element-caption">Pie de tabla</figcaption></figure><!-- /wp:table -->');
    same('table', $b[0]['type']);
    same([['A', 'B']], $b[0]['attrs']['head']);
    same([['1', '<strong>2</strong>'], ['3', '4']], $b[0]['attrs']['rows']);
    same('Pie de tabla', $b[0]['attrs']['caption']);
});

test('una tabla sin cabecera solo trae filas', function (): void {
    $b = cmsbParse('<!-- wp:table --><figure class="wp-block-table"><table><tbody><tr><td>x</td></tr></tbody></table></figure><!-- /wp:table -->');
    same(['rows' => [['x']]], $b[0]['attrs']);
});

test('una portada con botones dentro', function (): void {
    $wp = '<!-- wp:cover {"url":"https://e.com/fondo.jpg","dimRatio":60,"customOverlayColor":"#112233","minHeight":400,"align":"full"} -->'
        . '<div class="wp-block-cover alignfull" style="min-height:400px"><span aria-hidden="true" class="wp-block-cover__background has-background-dim-60 has-background-dim" style="background-color:#112233"></span>'
        . '<img class="wp-block-cover__image-background" alt="" src="https://e.com/fondo.jpg" data-object-fit="cover"/><div class="wp-block-cover__inner-container">'
        . '<!-- wp:heading {"level":1} --><h1 class="wp-block-heading">Bienvenida</h1><!-- /wp:heading -->'
        . '<!-- wp:buttons --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link wp-element-button" href="https://e.com/tienda">Ver tienda</a></div><!-- /wp:button -->'
        . '<!-- wp:button {"className":"is-style-outline"} --><div class="wp-block-button is-style-outline"><a class="wp-block-button__link wp-element-button" href="https://e.com/c" target="_blank" rel="noreferrer noopener">Contacto</a></div><!-- /wp:button --></div><!-- /wp:buttons -->'
        . '</div></div><!-- /wp:cover -->';
    $b = cmsbParse($wp);
    same('cover', $b[0]['type']);
    $a = $b[0]['attrs'];
    same('https://e.com/fondo.jpg', $a['src']);
    same('#112233', $a['overlay']);
    same(60, $a['dim']);
    same(400, $a['min_height']);
    same('full', $a['class_hint']);
    same(['heading', 'buttons'], array_map(static function (array $c): string {
        return $c['type'];
    }, $b[0]['children']));
    $items = $b[0]['children'][1]['attrs']['items'];
    same(['text' => 'Ver tienda', 'href' => 'https://e.com/tienda', 'variant' => 'fill'], $items[0]);
    same(['text' => 'Contacto', 'href' => 'https://e.com/c', 'variant' => 'outline', 'target' => '_blank'], $items[1]);
    // El HTML de relleno de la portada no se cuela como bloques sueltos.
    same(['cover', 'heading', 'buttons'], cmsbTypes($b));
});

test('botones centrados por el diseño del contenedor', function (): void {
    $b = cmsbParse('<!-- wp:buttons {"layout":{"type":"flex","justifyContent":"center"}} --><div class="wp-block-buttons"><!-- wp:button --><div class="wp-block-button"><a class="wp-block-button__link" href="/x">Ir</a></div><!-- /wp:button --></div><!-- /wp:buttons -->');
    same('center', $b[0]['attrs']['align']);
});

test('un grupo con estilo, clase ancha y etiqueta section', function (): void {
    $b = cmsbParse('<!-- wp:group {"tagName":"section","align":"wide","style":{"color":{"background":"#f5f5f5"},"spacing":{"padding":{"top":"20px"}}}} --><section class="wp-block-group alignwide has-background" style="background-color:#f5f5f5;padding-top:20px"><!-- wp:paragraph --><p>Dentro</p><!-- /wp:paragraph --></section><!-- /wp:group -->');
    same('group', $b[0]['type']);
    same('section', $b[0]['attrs']['tag']);
    same('wide', $b[0]['attrs']['class_hint']);
    contains('background-color:#f5f5f5', $b[0]['attrs']['style']);
    contains('padding-top:20px', $b[0]['attrs']['style']);
    same('Dentro', $b[0]['children'][0]['attrs']['html']);
});

test('medios y texto se convierten en dos columnas, y se invierten con mediaPosition', function (): void {
    $wp = '<!-- wp:media-text {"mediaPosition":"right","mediaWidth":40} --><div class="wp-block-media-text has-media-on-the-right is-stacked-on-mobile"><figure class="wp-block-media-text__media"><img src="https://e.com/m.jpg" alt="medio"/></figure><div class="wp-block-media-text__content"><!-- wp:paragraph --><p>Texto al lado</p><!-- /wp:paragraph --></div></div><!-- /wp:media-text -->';
    $b = cmsbParse($wp);
    same('columns', $b[0]['type']);
    $cols = $b[0]['children'];
    same(60, $cols[0]['attrs']['width']);
    same('paragraph', $cols[0]['children'][0]['type']);
    same(40, $cols[1]['attrs']['width']);
    same('image', $cols[1]['children'][0]['type']);
    same('https://e.com/m.jpg', $cols[1]['children'][0]['attrs']['src']);
    same(['columns', 'column', 'paragraph', 'column', 'image'], cmsbTypes($b));
});

test('detalles: el resumen va a summary y el resto a hijos', function (): void {
    $b = cmsbParse('<!-- wp:details --><details class="wp-block-details"><summary>¿Cómo?</summary><!-- wp:paragraph --><p>Así.</p><!-- /wp:paragraph --><!-- wp:list --><ul><li>x</li></ul><!-- /wp:list --></details><!-- /wp:details -->');
    same('details', $b[0]['type']);
    same('¿Cómo?', $b[0]['attrs']['summary']);
    same(['paragraph', 'list'], array_map(static function (array $c): string {
        return $c['type'];
    }, $b[0]['children']));
});

test('código, texto preformateado y verso', function (): void {
    $code = cmsbParse('<!-- wp:code --><pre class="wp-block-code"><code class="language-php">echo &quot;a&quot; &amp;&amp; $b &lt; 3;</code></pre><!-- /wp:code -->');
    same('code', $code[0]['type']);
    same('echo "a" && $b < 3;', $code[0]['attrs']['code']);
    same('php', $code[0]['attrs']['language']);

    $pre = cmsbParse('<!-- wp:preformatted --><pre class="wp-block-preformatted">línea 1<br>línea 2</pre><!-- /wp:preformatted -->');
    same('code', $pre[0]['type']);
    same("línea 1\nlínea 2", $pre[0]['attrs']['code']);

    $verse = cmsbParse("<!-- wp:verse --><pre class=\"wp-block-verse\">Verso uno\nVerso dos</pre><!-- /wp:verse -->");
    same('paragraph', $verse[0]['type']);
    same('Verso uno<br>Verso dos', $verse[0]['attrs']['html']);
});

test('separador, espaciador y bloques que se ignoran', function (): void {
    $b = cmsbParse('<!-- wp:separator --><hr class="wp-block-separator has-alpha-channel-opacity"/><!-- /wp:separator --><!-- wp:spacer {"height":"50px"} --><div style="height:50px" aria-hidden="true" class="wp-block-spacer"></div><!-- /wp:spacer --><!-- wp:more --><!--more--><!-- /wp:more -->');
    same(['separator', 'spacer'], cmsbTypes($b));
    same(50, $b[1]['attrs']['height']);
});

test('embeds de YouTube, Vimeo y uno antiguo con espacio de nombres', function (): void {
    $yt = cmsbParse('<!-- wp:embed {"url":"https://www.youtube.com/watch?v=dQw4w9WgXcQ","providerNameSlug":"youtube"} --><figure class="wp-block-embed is-provider-youtube"><div class="wp-block-embed__wrapper">https://www.youtube.com/watch?v=dQw4w9WgXcQ</div><figcaption class="wp-element-caption">El vídeo</figcaption></figure><!-- /wp:embed -->');
    same(['provider' => 'youtube', 'url' => 'https://www.youtube.com/watch?v=dQw4w9WgXcQ', 'caption' => 'El vídeo'], $yt[0]['attrs']);

    $vi = cmsbParse('<!-- wp:core-embed/vimeo {"url":"https://vimeo.com/123456789"} --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://vimeo.com/123456789</div></figure><!-- /wp:core-embed/vimeo -->');
    same('vimeo', $vi[0]['attrs']['provider']);

    $otro = cmsbParse('<!-- wp:embed --><figure class="wp-block-embed"><div class="wp-block-embed__wrapper">https://ejemplo.com/pagina</div></figure><!-- /wp:embed -->');
    same(['provider' => 'other', 'url' => 'https://ejemplo.com/pagina'], $otro[0]['attrs']);
});

test('un vídeo con cartel y pie', function (): void {
    $b = cmsbParse('<!-- wp:video --><figure class="wp-block-video"><video controls poster="https://e.com/p.jpg" src="https://e.com/v.mp4"></video><figcaption class="wp-element-caption">Demo</figcaption></figure><!-- /wp:video -->');
    same(['src' => 'https://e.com/v.mp4', 'poster' => 'https://e.com/p.jpg', 'caption' => 'Demo'], $b[0]['attrs']);
});

test('últimas entradas pasa a posts', function (): void {
    $b = cmsbParse('<!-- wp:latest-posts {"postsToShow":6,"categories":[{"id":7,"name":"Noticias"}]} /-->');
    same('posts', $b[0]['type']);
    same(['limit' => 6, 'category_id' => 7], $b[0]['attrs']);
});

test('un bloque desconocido va a html con review y su HTML interior', function (): void {
    $b = cmsbParse('<!-- wp:miplugin/calendario {"mes":3} --><div class="cal">Marzo <b>2026</b></div><!-- /wp:miplugin/calendario -->');
    same('html', $b[0]['type']);
    same(true, $b[0]['attrs']['review']);
    contains('Marzo', $b[0]['attrs']['html']);
    contains('<b>2026</b>', $b[0]['attrs']['html']);
    notContains('wp:', $b[0]['attrs']['html']);

    $self = cmsbParse('<!-- wp:woocommerce/cart /-->');
    same('html', $self[0]['type']);
    same(true, $self[0]['attrs']['review']);
});

test('el bloque html se reconoce (sin review) y se sanea', function (): void {
    $b = cmsbParse('<!-- wp:html --><div class="x">Hola</div><script>alert(1)</script><!-- /wp:html -->');
    same('html', $b[0]['type']);
    check(!isset($b[0]['attrs']['review']), 'el bloque html de WordPress no necesita revisión');
    notContains('<script', $b[0]['attrs']['html']);
    contains('Hola', $b[0]['attrs']['html']);
});

test('contenido clásico: párrafo, encabezado y lista', function (): void {
    $b = cmsbParse('<p>a</p><h2>b</h2><ul><li>c</li></ul>');
    same([
        ['type' => 'paragraph', 'attrs' => ['html' => 'a'], 'children' => []],
        ['type' => 'heading', 'attrs' => ['level' => 2, 'html' => 'b'], 'children' => []],
        ['type' => 'list', 'attrs' => ['ordered' => false, 'items' => [['html' => 'c']]], 'children' => []],
    ], $b);
});

test('contenido clásico: texto suelto, citas, tablas, imágenes, líneas y código', function (): void {
    $b = cmsbParse("Primer párrafo suelto con <strong>negrita</strong>.\n\nSegundo párrafo.\n<blockquote><p>Cita</p><cite>Yo</cite></blockquote><table><tr><th>A</th></tr><tr><td>1</td></tr></table><p><a href=\"https://e.com\"><img src=\"https://e.com/i.png\" alt=\"i\" width=\"20\"></a></p><hr><pre><code class=\"language-js\">let a = 1 &lt; 2;</code></pre><ol start=\"3\"><li>z</li></ol>");
    same(['paragraph', 'paragraph', 'quote', 'table', 'image', 'separator', 'code', 'list'], cmsbTypes($b));
    same('Primer párrafo suelto con <strong>negrita</strong>.', $b[0]['attrs']['html']);
    same('Segundo párrafo.', $b[1]['attrs']['html']);
    same('Yo', $b[2]['attrs']['cite']);
    same([['A']], $b[3]['attrs']['head']);
    same([['1']], $b[3]['attrs']['rows']);
    same('https://e.com', $b[4]['attrs']['href']);
    same(20, $b[4]['attrs']['width']);
    same('let a = 1 < 2;', $b[6]['attrs']['code']);
    same('js', $b[6]['attrs']['language']);
    same(true, $b[7]['attrs']['ordered']);
});

test('contenido clásico: lo que no se reconoce va a html con review', function (): void {
    $b = cmsbParse('<p>antes</p><div class="raro"><span>dentro</span></div><iframe src="https://malo.example/x"></iframe>');
    same('paragraph', $b[0]['type']);
    same('html', $b[1]['type']);
    same(true, $b[1]['attrs']['review']);
    contains('dentro', $b[1]['attrs']['html']);
    same('html', $b[2]['type']);
    notContains('iframe', $b[2]['attrs']['html']);
});

test('contenido clásico mezclado con bloques y el bloque freeform', function (): void {
    $b = cmsbParse('<p>suelto</p><!-- wp:paragraph --><p>bloque</p><!-- /wp:paragraph --><!-- wp:freeform --><h3>clásico</h3><!-- /wp:freeform -->');
    same(['paragraph', 'paragraph', 'heading'], cmsbTypes($b));
    same(3, $b[2]['attrs']['level']);
});

test('un código corto pasa a html con review y se lista en shortcodes()', function (): void {
    $a = cmsbParse('<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode -->');
    same('html', $a[0]['type']);
    same(true, $a[0]['attrs']['review']);
    contains('[woocommerce_cart]', $a[0]['attrs']['html']);

    $b = cmsbParse('<!-- wp:paragraph --><p>[woocommerce_checkout]</p><!-- /wp:paragraph -->');
    same('html', $b[0]['type']);
    same(true, $b[0]['attrs']['review']);

    $c = cmsbParse("Texto\n\n[products limit=\"4\" columns=\"2\"]");
    same(['paragraph', 'html'], cmsbTypes($c));
    same(true, $c[1]['attrs']['review']);
    contains('[products limit="4" columns="2"]', $c[1]['attrs']['html']);

    $content = '<!-- wp:shortcode -->[woocommerce_cart]<!-- /wp:shortcode --><p>[products limit="4"] y [WooCommerce_Cart] y [/gallery]</p>';
    same(['woocommerce_cart', 'products', 'gallery'], GutenbergParser::shortcodes($content));
});

test('shortcodes() no mira comentarios, código ni JSON', function (): void {
    $content = '<!-- wp:miplugin {"lista":[abc,def]} --><p>texto</p><!-- /wp:miplugin --><pre>a[abc]</pre><code>[otro]</code><p>[1] [2,3] [x]</p>';
    same([], GutenbergParser::shortcodes($content));
    same([], GutenbergParser::shortcodes(''));
});

test('contenido roto no lanza: cierres sueltos, bloques sin cerrar y JSON inválido', function (): void {
    $a = cmsbParse('<!-- /wp:paragraph --><!-- wp:paragraph {mal json} --><p>Sigue</p>');
    same(['paragraph'], cmsbTypes($a));
    same('Sigue', $a[0]['attrs']['html']);

    $b = cmsbParse('<!-- wp:group --><div class="wp-block-group"><!-- wp:paragraph --><p>Sin cerrar</p>');
    same(['group', 'paragraph'], cmsbTypes($b));

    same([], cmsbParse(''));
    same([], cmsbParse("  \n\t "));
    same([], cmsbParse('<!-- un comentario normal -->'));
});

test('una anidación absurda de grupos se acota y no desborda', function (): void {
    $open = str_repeat('<!-- wp:group --><div class="wp-block-group">', 70);
    $close = str_repeat('</div><!-- /wp:group -->', 70);
    $b = cmsbParse($open . '<!-- wp:paragraph --><p>fondo</p><!-- /wp:paragraph -->' . $close);
    $depth = 0;
    $cur = $b;
    while ($cur !== [] && isset($cur[0]['children'])) {
        ++$depth;
        $cur = $cur[0]['children'];
    }
    check($depth >= 1 && $depth <= \Aifec\Cms\Blocks\BlockSchema::MAX_DEPTH, 'profundidad ' . $depth);
});

test('el resultado ya está validado: validate() no lo cambia', function (): void {
    $b = cmsbParse('<!-- wp:paragraph {"style":{"color":{"text":"#123456"}}} --><p>a</p><!-- /wp:paragraph --><!-- wp:image --><figure class="wp-block-image"><img src="javascript:alert(1)" alt="x"/></figure><!-- /wp:image -->');
    same($b, \Aifec\Cms\Blocks\BlockSchema::validate($b));
    same('image', $b[1]['type']);
    check(!isset($b[1]['attrs']['src']), 'la dirección javascript: no puede sobrevivir');
    notContains('javascript', json_encode($b[1]) ?: '');
});

test('comentarios con llaves en el JSON y comentarios abiertos a medias', function (): void {
    $escaped = chr(92) . 'u002d' . chr(92) . 'u002d>';
    $a = cmsbParse('<!-- wp:paragraph {"nota":"a } b ' . $escaped . '"} --><p>Texto</p><!-- /wp:paragraph -->');
    same('Texto', $a[0]['attrs']['html']);

    $t = microtime(true);
    $b = cmsbParse(str_repeat('<!-- wp:x {"a":', 3000) . '<p>fin</p>');
    check(microtime(true) - $t < 5.0, 'un comentario abierto y sin cerrar no puede ser cuadrático');
    check(is_array($b), 'no lanza');
});

test('un bloque con atributos de estilo malformados no lanza', function (): void {
    $b = cmsbParse('<!-- wp:paragraph {"style":"no es un objeto","align":5,"textColor":["x"]} --><p>a</p><!-- /wp:paragraph --><!-- wp:heading {"level":"9","style":{"typography":{"fontSize":{"x":1}}}} --><h2>b</h2><!-- /wp:heading -->');
    same(['html' => 'a'], $b[0]['attrs']);
    same(2, $b[1]['attrs']['level']);
});
