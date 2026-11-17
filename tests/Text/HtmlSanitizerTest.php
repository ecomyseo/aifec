<?php
/**
 * AIFEC — pruebas del saneador de HTML y del filtro de direcciones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Text\HtmlSanitizer;
use Aifec\Text\UrlFilter;

/**
 * Vectores de ataque: en ninguno puede sobrevivir una etiqueta script, un manejador on…= ni
 * un esquema javascript:.
 *
 * @return string[]
 */
function textAttackVectors(): array
{
    return [
        '<script>alert(1)</script>',
        '<ScRiPt>alert(1)</sCrIpT>',
        '<scr<script>ipt>alert(1)</scr</script>ipt>',
        '<img src=x onerror=alert(1)>',
        '<img src="x"onerror="alert(1)">',
        '<img src="x" onerror="alert(1)" onload="alert(2)">',
        '<a href="jav&#x09;ascript:alert(1)">x</a>',
        '<a href="jav&Tab;ascript:alert(1)">x</a>',
        '<a href="&#106;avascript:alert(1)">x</a>',
        '<a href="JaVaScRiPt:alert(1)">x</a>',
        '<a href=javascript:alert(1)>x</a>',
        '<a href="  javascript:alert(1)">x</a>',
        "<a href=\"\x01javascript:alert(1)\">x</a>",
        "<a href=\"java\nscript:alert(1)\">x</a>",
        '<a href="java&#0;script:alert(1)">x</a>',
        '<a href="vbscript:msgbox(1)">x</a>',
        '<a href="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">x</a>',
        '<img src="data:image/svg+xml;base64,PHN2ZyBvbmxvYWQ9YWxlcnQoMSk+">',
        '<svg onload=alert(1)><circle/></svg>',
        '<svg><script>alert(1)</script></svg>',
        '<math><mi xlink:href="javascript:alert(1)">x</mi></math>',
        '<iframe src="//evil.example/x"></iframe>',
        '<iframe src="javascript:alert(1)"></iframe>',
        '<p style="background:url(javascript:alert(1))">x</p>',
        '<p style="width:expression(alert(1))">x</p>',
        '<style>@import "javascript:alert(1)";</style><p>x</p>',
        '<!--[if gte IE 4]><script>alert(1)</script><![endif]--><p>x</p>',
        '<body onload=alert(1)>x',
        '<div onmouseover="alert(1)">x</div>',
        '<details open ontoggle=alert(1)>x</details>',
        '<input onfocus=alert(1) autofocus>',
        '<form action="javascript:alert(1)"><button formaction="javascript:alert(1)">x</button></form>',
        '<object data="javascript:alert(1)"></object>',
        '<embed src="javascript:alert(1)">',
        '<link rel="stylesheet" href="javascript:alert(1)">',
        '<meta http-equiv="refresh" content="0;url=javascript:alert(1)">',
        '<base href="javascript:alert(1)//">',
        '<video poster="javascript:alert(1)"><source src="javascript:alert(1)" onerror="alert(1)"></video>',
        '<img srcset="javascript:alert(1) 1x, https://ok.example/a.png 2x">',
        '<div><p>sin cerrar <b>negrita <a href="javascript:alert(1)">x',
        '<img src=x onerror=alert(1)//',
        '<a href="x" data-onclick="alert(1)" data-hx-on:click="alert(1)">x</a>',
        '<textarea><script>alert(1)</script></textarea>',
        '<template><script>alert(1)</script></template>',
    ];
}

/** Comprueba la salida con expresiones y mirando el árbol de lo que ha salido. */
function textAssertSafe(string $out, string $vector): void
{
    $why = 'vector «' . str_replace("\n", ' ', $vector) . '» → «' . $out . '»';
    check(preg_match('/<\s*script/i', $out) !== 1, 'script en ' . $why);
    check(preg_match('/\bon[a-z]+\s*=/i', $out) !== 1, 'manejador on…= en ' . $why);
    check(preg_match('/javascript\s*:/i', $out) !== 1, 'javascript: en ' . $why);

    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $out . '</body>');
    libxml_clear_errors();
    $forbidden = ['script', 'style', 'iframe', 'object', 'embed', 'form', 'input', 'button', 'svg', 'math', 'link', 'meta', 'base'];
    foreach ($doc->getElementsByTagName('*') as $el) {
        check(!in_array(strtolower($el->nodeName), $forbidden, true), 'elemento prohibido ' . $el->nodeName . ' en ' . $why);
        foreach ($el->attributes as $attr) {
            check(strncasecmp($attr->nodeName, 'on', 2) !== 0, 'atributo ' . $attr->nodeName . ' en ' . $why);
            check(preg_match('/^\s*(javascript|vbscript|data):/i', (string) $attr->value) !== 1 || $attr->nodeName === 'src', 'URL peligrosa en ' . $why);
        }
    }
}

test('los vectores de ataque no dejan script, manejadores ni javascript:', function (): void {
    $vectors = textAttackVectors();
    check(count($vectors) >= 25, 'hacen falta al menos 25 vectores');
    foreach ($vectors as $vector) {
        foreach (['inline', 'content', 'embed'] as $profile) {
            textAssertSafe(HtmlSanitizer::clean($vector, $profile), $vector);
        }
    }
});

test('limpiar dos veces da lo mismo que limpiar una', function (): void {
    $samples = array_merge(textAttackVectors(), [
        '<p style="font-size:14px;letter-spacing:2px;text-transform:uppercase">Ñandú &amp; 😀 &nbsp;&lt;b&gt;</p>',
        '<a href="https://a.example/?x=1&amp;y=2" target="_blank" rel="nofollow">x</a>',
        '<p>uno</p><p>dos <u>tres</u></p>texto suelto',
        '<table><tr><td colspan="2" scope="col">a</td></tr></table>',
        '<img src="a.png" srcset="a.png 1x, b.png 2x" alt="" width="10" loading="lazy">',
        '<video controls muted src="a.mp4"></video><ol start="3" reversed><li>x</li></ol>',
        '<pre>  a   b' . "\n" . 'c</pre>',
    ]);
    foreach ($samples as $sample) {
        foreach (['inline', 'content', 'embed'] as $profile) {
            $once = HtmlSanitizer::clean($sample, $profile);
            same($once, HtmlSanitizer::clean($once, $profile), 'no es idempotente (' . $profile . ') con «' . $sample . '»');
        }
    }
    $embed = '<iframe src="https://www.youtube.com/embed/abc" width="560" allowfullscreen></iframe>';
    $once = HtmlSanitizer::clean($embed, 'embed');
    same($once, HtmlSanitizer::clean($once, 'embed'));
});

test('un enlace dentro de otro se aplana y la salida es estable', function (): void {
    $out = HtmlSanitizer::clean('<a href="/a">uno <a href="/b">dos</a> tres</a>');
    same('<a href="/a">uno </a><a href="/b">dos</a> tres', $out);
    same($out, HtmlSanitizer::clean($out));
    // Con una etiqueta por medio el analizador sí deja un enlace dentro de otro.
    same('<a href="/a"><span>q</span></a>', HtmlSanitizer::clean('<a href="/a"><span><a href="/b">q</a></span></a>'));
    same('<a href="/a"><h1>q</h1></a>', HtmlSanitizer::clean('<a href="/a"><h1><a href="/b">q</a></h1></a>'));
    foreach (['<a href="x<p>y"><b>z</a></b>', '<a href="/a"><div><a href="/b">q</a></div></a>', '<a href="/a"><p>1</p><a href="/b"><p>2</p></a></a>', '<a href="/a"><ul><li><a href="/b">q</a></li></ul></a>'] as $odd) {
        $once = HtmlSanitizer::clean($odd);
        same($once, HtmlSanitizer::clean($once), $odd);
    }
});

test('el HTML que el analizador recoloca al leerlo da una salida estable', function (): void {
    // Hallados con un fuzzer: la primera lectura no coincide con la segunda (li dentro de li,
    // p con una lista dentro, caption con col…).
    $odd = [
        '<a href="x"></details><span><table><br></a><li></a><ul><li><caption><b></b><a href="x"><col>',
        '<a href="x"><summary></p><dd><div></td></tr></table><p><figure><ul><li><a href="x"><li><a href="x"><caption></li></ul>',
        '<dd><a href="x"><dd><ul><li><p><a href="x"><a href="x"><ul><li><span></h1><colgroup><video>',
        '<summary></td></tr></table><td></pre></a><a href="x"><em><dd><li><picture><caption><a href="x"><col>',
    ];
    foreach ($odd as $html) {
        foreach (['inline', 'content', 'embed'] as $profile) {
            $once = HtmlSanitizer::clean($html, $profile);
            same($once, HtmlSanitizer::clean($once, $profile), $profile . ': ' . $html);
            textAssertSafe($once, $html);
        }
    }
});

test('conserva tildes, eñes, ampersand, emojis y entidades', function (): void {
    $out = HtmlSanitizer::clean('<p>Cañón &amp; niño 😀 «ñ» &lt;b&gt; &nbsp;fin &copy;</p>');
    same('<p>Cañón &amp; niño 😀 «ñ» &lt;b&gt; &nbsp;fin ©</p>', $out);
    same('Ñandú', HtmlSanitizer::clean('Ñandú'));
    contains('a=1&amp;b=2', HtmlSanitizer::clean('<a href="/p?a=1&b=2">x</a>'));
});

test('un bloque de WordPress conserva las tres propiedades de estilo', function (): void {
    $out = HtmlSanitizer::clean('<p class="has-small-font-size" style="font-size:14px;letter-spacing:2px;text-transform:uppercase">Texto</p>');
    same('<p class="has-small-font-size" style="font-size:14px;letter-spacing:2px;text-transform:uppercase">Texto</p>', $out);
});

test('el estilo descarta lo peligroso y lo que no está en la lista', function (): void {
    $out = HtmlSanitizer::clean('<p style="color:red;position:fixed;background-color:#fff;width:100%!important;margin-top: 4px ;padding:url(x)">x</p>');
    same('<p style="color:red;background-color:#fff;margin-top:4px">x</p>', $out);
    same('<p>x</p>', HtmlSanitizer::clean('<p style="background:url(a.png);color:expression(x)">x</p>'));
    same('<p>x</p>', HtmlSanitizer::clean('<p style="color:\\72 ed">x</p>'));
    same('<p style="font-weight:bold">x</p>', HtmlSanitizer::clean('<p style="FONT-WEIGHT : bold">x</p>'));
});

test('el perfil inline convierte <p>hola</p> en hola y deja el formato', function (): void {
    same('hola', HtmlSanitizer::clean('<p>hola</p>', 'inline'));
    same('a b', HtmlSanitizer::clean('<p>a</p><p>b</p>', 'inline'));
    same('<b>x</b> <a href="/y">z</a>', HtmlSanitizer::clean('<div><b>x</b> <a href="/y">z</a><img src="a.png"></div>', 'inline'));
    contains('<p>', HtmlSanitizer::clean('<p>x</p>', 'content'));
});

test('lo no permitido se quita pero se conserva su texto', function (): void {
    same('<p>hola mundo</p>', HtmlSanitizer::clean('<p><font color="red">hola</font> <big>mundo</big></p>'));
    same('texto', HtmlSanitizer::clean('<article><nav>texto</nav></article>'));
    same('<p>a</p>', HtmlSanitizer::clean('<p>a<script>alert(1)</script><style>p{}</style><button>b</button><select><option>o</option></select></p>'));
    same('', HtmlSanitizer::clean('<svg><text>oculto</text></svg><math>x</math>'));
    same('<p>ab</p>', HtmlSanitizer::clean('<p>a<!-- comentario -->b</p>'));
});

test('target _blank añade rel noopener noreferrer y respeta el rel existente', function (): void {
    same('<a href="https://x.example" target="_blank" rel="noopener noreferrer">x</a>', HtmlSanitizer::clean('<a href="https://x.example" target="_blank">x</a>'));
    same('<a href="/x" target="_blank" rel="nofollow noopener noreferrer">x</a>', HtmlSanitizer::clean('<a href="/x" target="_blank" rel="nofollow">x</a>'));
    same('<a href="/x" target="_blank" rel="noopener noreferrer">x</a>', HtmlSanitizer::clean('<a href="/x" target="_blank" rel="noopener">x</a>'));
    same('<a href="/x">x</a>', HtmlSanitizer::clean('<a href="/x" target="otro">x</a>'));
});

test('los enlaces solo admiten esquemas seguros, rutas relativas y anclas', function (): void {
    foreach (['https://a.example/x', 'http://a.example', 'mailto:a@b.example', 'tel:+34999000000', '/ruta/x', 'ruta/x.html', '../x', '#ancla', '?q=1', '//cdn.example/x'] as $ok) {
        same('<a href="' . $ok . '">x</a>', HtmlSanitizer::clean('<a href="' . $ok . '">x</a>'), $ok);
    }
    foreach (['javascript:alert(1)', 'data:text/html,hola', 'vbscript:x', 'file:///etc/passwd', 'ftp://a.example/x', 'x:y'] as $bad) {
        same('<a>x</a>', HtmlSanitizer::clean('<a href="' . $bad . '">x</a>'), $bad);
    }
});

test('una imagen data: solo pasa si es png, jpeg, gif o webp en base64', function (): void {
    contains('src="data:image/png;base64,iVBORw0KGgo="', HtmlSanitizer::clean('<img src="data:image/png;base64,iVBORw0KGgo=" alt="x">'));
    contains('src="data:image/webp;base64,AAAA"', HtmlSanitizer::clean('<img src="data:image/webp;base64,AAAA">'));
    notContains('data:', HtmlSanitizer::clean('<img src="data:image/svg+xml;base64,AAAA">'));
    notContains('data:', HtmlSanitizer::clean('<img src="data:image/png;charset=x,<script>">'));
    notContains('data:', HtmlSanitizer::clean('<a href="data:image/png;base64,AAAA">x</a>'));
    notContains('data:', HtmlSanitizer::clean('<video poster="data:image/png;base64,AAAA"></video>'));
});

test('iframe: solo en el perfil embed, por https y de un dominio permitido', function (): void {
    $good = '<iframe src="https://www.youtube.com/embed/abc" width="560" height="315" allowfullscreen title="V"></iframe>';
    same('<iframe src="https://www.youtube.com/embed/abc" width="560" height="315" allowfullscreen title="V" loading="lazy"></iframe>', HtmlSanitizer::clean($good, 'embed'));
    same('', HtmlSanitizer::clean($good, 'content'));
    same('', HtmlSanitizer::clean($good, 'inline'));
    contains('player.vimeo.com', HtmlSanitizer::clean('<iframe src="https://player.vimeo.com/video/1"></iframe>', 'embed'));
    contains('youtube-nocookie.com', HtmlSanitizer::clean('<iframe src="https://www.youtube-nocookie.com/embed/x"></iframe>', 'embed'));
    foreach ([
        'http://www.youtube.com/embed/x', '//www.youtube.com/embed/x', 'https://evil.example/x',
        'https://www.youtube.com.evil.example/x', 'https://www.youtube.com@evil.example/x',
        'https://evil.example\\@www.youtube.com/x', 'https://www.youtube.com:8443/x', 'javascript:alert(1)',
        'data:text/html,x', '/embed/x', '',
    ] as $bad) {
        same('', HtmlSanitizer::clean('<iframe src="' . $bad . '"></iframe>', 'embed'), $bad);
    }
    same('<p>a</p>', HtmlSanitizer::clean('<p>a</p><iframe src="https://evil.example/"></iframe>', 'embed'));
});

test('srcset conserva los candidatos buenos y descarta los malos', function (): void {
    same('<img srcset="a.png 1x, https://c.example/b.png 2x">', HtmlSanitizer::clean('<img srcset="a.png 1x,javascript:alert(1) 2x ,https://c.example/b.png 2x">'));
    same('<img>', HtmlSanitizer::clean('<img srcset="javascript:alert(1) 1x">'));
    same('<img srcset="a.png 480w, b.png 800w" sizes="(max-width: 600px) 480px, 800px">', HtmlSanitizer::clean('<img srcset="a.png 480w, b.png 800w" sizes="(max-width: 600px) 480px, 800px">'));
});

test('id y name solo admiten letras, cifras, guion y guion bajo', function (): void {
    same('<h2 id="mi-id_1">x</h2>', HtmlSanitizer::clean('<h2 id="mi-id_1">x</h2>'));
    same('<h2 id="miid">x</h2>', HtmlSanitizer::clean('<h2 id="mi id&quot;&gt;">x</h2>'));
    same('<h2>x</h2>', HtmlSanitizer::clean('<h2 id="  ">x</h2>'));
    same('<a name="ancla1">x</a>', HtmlSanitizer::clean('<a name="ancla.1">x</a>'));
});

test('atributos globales, aria y data se conservan; los data de manejadores no', function (): void {
    $out = HtmlSanitizer::clean('<div class="a b" id="x" title="t" lang="es" dir="rtl" role="note" aria-label="q" data-id="7" data-onclick="x" data-hx-get="/y" hidden contenteditable="true" tabindex="1">x</div>');
    same('<div class="a b" id="x" title="t" lang="es" dir="rtl" role="note" aria-label="q" data-id="7">x</div>', $out);
});

test('tablas, listas, vídeo y detalles mantienen sus atributos permitidos', function (): void {
    same('<table><tbody><tr><th scope="col" colspan="2">a</th><td rowspan="2" headers="x y">b</td></tr></tbody></table>',
        HtmlSanitizer::clean('<table><tbody><tr><th scope="col" colspan="2" bgcolor="red">a</th><td rowspan="2" headers="x y" onclick="x()">b</td></tr></tbody></table>'));
    same('<ol start="3" reversed type="a"><li>x</li></ol>', HtmlSanitizer::clean('<ol start="3" reversed="reversed" type="a"><li>x</li></ol>'));
    same('<video src="a.mp4" controls muted poster="p.png" preload="none" width="640"></video>',
        HtmlSanitizer::clean('<video src="a.mp4" controls muted poster="p.png" preload="none" width="640" autoplay></video>'));
    same('<details open><summary>s</summary>x</details>', HtmlSanitizer::clean('<details open="open"><summary>s</summary>x</details>'));
    same('<img src="a.png" alt="" width="10" height="20%" loading="lazy" decoding="async">',
        HtmlSanitizer::clean('<img src="a.png" alt="" width="10" height="20%" loading="lazy" decoding="async" ismap>'));
    same('<img src="a.png">', HtmlSanitizer::clean('<img src="a.png" width="ancho" loading="rápido">'));
});

test('el perfil desconocido lanza una excepción', function (): void {
    throws(function (): void {
        HtmlSanitizer::clean('<p>x</p>', 'todo');
    }, \InvalidArgumentException::class);
});

test('la entrada vacía, solo espacios o con bytes raros no rompe nada', function (): void {
    same('', HtmlSanitizer::clean(''));
    same('', HtmlSanitizer::clean("  \n\t "));
    same('', HtmlSanitizer::clean('<!-- nada -->'));
    same('<p>ab</p>', HtmlSanitizer::clean("<p>a\x00b</p>"));
    same('a?b', HtmlSanitizer::clean("a\xFFb"));
    same('', HtmlSanitizer::text(''));
});

test('text() da texto plano con saltos entre bloques', function (): void {
    same("Título\na & b\nc\nx\ny", HtmlSanitizer::text('<h1>Título</h1><p>a &amp; b<br>c</p><ul><li>x</li><li>y</li></ul><script>1</script><style>p{}</style>'));
    same('uno dos tres', HtmlSanitizer::text("<p>uno \n  dos\t\ttres</p>"));
    same("a b\nc", HtmlSanitizer::text('<table><tr><td>a</td><td>b</td></tr><tr><td>c</td></tr></table>'));
    same('Cañón & 😀 <b>', HtmlSanitizer::text('<p>Cañón &amp; 😀 &lt;b&gt;&nbsp;</p>'));
    same('x', HtmlSanitizer::text('<p>x</p><!-- c --><iframe src="https://evil.example"></iframe><svg>y</svg>'));
});

test('UrlFilter: devuelve la dirección sin caracteres de control o null', function (): void {
    same('https://a.example/x', UrlFilter::safe("  https://a.example/x \n"));
    same('https://a.example/x', UrlFilter::safe("https://a.exa\tmple/x"), 'el navegador quita los tabuladores');
    same(null, UrlFilter::safe(''));
    same(null, UrlFilter::safe('   '));
    same(null, UrlFilter::safe("java\tscript:alert(1)"));
    same(null, UrlFilter::safe("java\u{200B}script:alert(1)"));
    same(null, UrlFilter::safe('&#x6A;avascript:alert(1)'));
    same(null, UrlFilter::safe('jav&Tab;ascript:alert(1)'));
    same(null, UrlFilter::safe("\xFF\xFEjavascript:x"));
    same('data:image/gif;base64,R0lGODlh', UrlFilter::safe("data:image/gif;base64,\nR0lGODlh", true));
    same(null, UrlFilter::safe('data:image/gif;base64,R0lGODlh', false));
    same(null, UrlFilter::safe('data:image/svg+xml;base64,AAAA', true));
});
