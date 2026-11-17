<?php
/**
 * AIFEC — pruebas del conversor de HTML a Markdown.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Text\HtmlToMarkdown;
use Aifec\Text\Markdown;

test('encabezados, párrafos y texto suelto', function (): void {
    same("# Uno\n\n## Dos\n\n###### Seis\n\nTexto\n\nOtro", HtmlToMarkdown::convert('<h1>Uno</h1><h2>Dos</h2><h6>Seis</h6><p>Texto</p>Otro'));
    same("Hola mundo", HtmlToMarkdown::convert("<p>\n  Hola\n   mundo\n</p>"));
    same("a\n\nb", HtmlToMarkdown::convert('<div>a</div><div>b</div>'));
    same("a\n\nb\n\nc", HtmlToMarkdown::convert('<div>a<div>b</div>c</div>'));
    same('', HtmlToMarkdown::convert(''));
    same('', HtmlToMarkdown::convert("<p> </p>\n<div></div>"));
    same('# Título ñandú 😀', HtmlToMarkdown::convert('<h1> Título   ñandú 😀 </h1>'));
});

test('negrita, cursiva, código y saltos duros', function (): void {
    same('Hola **a** *b* `c` ***d***', HtmlToMarkdown::convert('<p>Hola <strong>a</strong> <em>b</em> <code>c</code> <b><i>d</i></b></p>'));
    same("a  \nb", HtmlToMarkdown::convert('<p>a<br>b</p>'));
    same('x **y** z', HtmlToMarkdown::convert('<p>x<b> y </b>z</p>'));
    same('`` a`b ``', HtmlToMarkdown::convert('<p><code>a`b</code></p>'));
    same('texto', HtmlToMarkdown::convert('<p><b></b><i> </i>texto<br></p>'));
    same('a *b* c', HtmlToMarkdown::convert('<p><span>a <em>b</em></span> <span>c</span></p>'));
});

test('los caracteres de Markdown del texto salen escapados', function (): void {
    same('a \\* b \\_c\\_ \\[d\\] \\<e\\> \\`f\\` g\\\\h', HtmlToMarkdown::convert('<p>a * b _c_ [d] &lt;e&gt; `f` g\\h</p>'));
    same('\\# no encabezado', HtmlToMarkdown::convert('<p># no encabezado</p>'));
    same('1\\. no lista', HtmlToMarkdown::convert('<p>1. no lista</p>'));
    same('\\- no lista', HtmlToMarkdown::convert('<p>- no lista</p>'));
    same('AT&T y \\&amp; literal', HtmlToMarkdown::convert('<p>AT&amp;T y &amp;amp; literal</p>'));
    same('a | b', HtmlToMarkdown::convert('<p>a | b</p>'));
});

test('listas anidadas de tres niveles y ordenadas', function (): void {
    same("- uno\n  - dos\n    - tres\n- cuatro", HtmlToMarkdown::convert('<ul><li>uno<ul><li>dos<ul><li>tres</li></ul></li></ul></li><li>cuatro</li></ul>'));
    same("1. a\n2. b\n   - x\n   - y\n3. c", HtmlToMarkdown::convert('<ol><li>a</li><li>b<ul><li>x</li><li>y</li></ul></li><li>c</li></ol>'));
    same("3. c\n4. d", HtmlToMarkdown::convert('<ol start="3"><li>c</li><li>d</li></ol>'));
    same("- a\n\n  b\n\n- c", HtmlToMarkdown::convert('<ul><li><p>a</p><p>b</p></li><li><p>c</p></li></ul>'));
    same("- a\n\ntexto", HtmlToMarkdown::convert('<ul><li>a</li></ul><p>texto</p>'));
    same("- a\n\n* b", HtmlToMarkdown::convert('<ul><li>a</li></ul><ul><li>b</li></ul>'));
    same('- b', HtmlToMarkdown::convert('<ul><li></li><li>b</li></ul>'));
    same("- a\n\n  ```\n  c\n  ```", HtmlToMarkdown::convert('<ul><li>a<pre>c</pre></li></ul>'));
});

test('enlaces e imágenes', function (): void {
    same('Ver [la web](https://a.example/x?a=1&b=2 "Título") y ![logo](https://a.example/l.png "L")', HtmlToMarkdown::convert('<p>Ver <a href="https://a.example/x?a=1&amp;b=2" title="Título">la web</a> y <img src="https://a.example/l.png" alt="logo" title="L"></p>'));
    same('[a **b**](/ruta) y [c](#x)', HtmlToMarkdown::convert('<p><a href="/ruta">a <b>b</b></a> y <a href="#x">c</a></p>'));
    same('[![i](i.png)](https://a.example)', HtmlToMarkdown::convert('<a href="https://a.example"><img src="i.png" alt="i"></a>'));
    same('[x](https://a.example/a%20b%28c%29)', HtmlToMarkdown::convert('<a href="https://a.example/a b(c)">x</a>'));
    same('sin enlace y alt', HtmlToMarkdown::convert('<p><a href="javascript:alert(1)">sin enlace</a> y <img src="javascript:x" alt="alt"></p>'));
    same('texto', HtmlToMarkdown::convert('<p><a name="x">texto</a><a href="/y"></a></p>'));
});

test('citas, reglas y bloques de código', function (): void {
    same("> uno\n>\n> dos\n>\n> - x", HtmlToMarkdown::convert('<blockquote><p>uno</p><p>dos</p><ul><li>x</li></ul></blockquote>'));
    same("> > interna", HtmlToMarkdown::convert('<blockquote><blockquote>interna</blockquote></blockquote>'));
    same("a\n\n---\n\nb", HtmlToMarkdown::convert('<p>a</p><hr><p>b</p>'));
    same("```php\n<?php echo 1;\n\nfoo();\n```", HtmlToMarkdown::convert("<pre><code class=\"language-php\">&lt;?php echo 1;\n\nfoo();\n</code></pre>"));
    same("````\na ``` b\n````", HtmlToMarkdown::convert('<pre>a ``` b</pre>'));
    same("```\nx\n```", HtmlToMarkdown::convert("<pre>\nx\n\n</pre>"));
});

test('tablas a tablas Markdown con alineación', function (): void {
    $html = '<table><thead><tr><th align="left">A</th><th style="text-align:center">B</th><th style="text-align: right">C</th></tr></thead>'
        . '<tbody><tr><td>1</td><td>x | y</td><td><b>3</b></td></tr><tr><td>solo</td></tr></tbody></table>';
    same("| A | B | C |\n| :--- | :---: | ---: |\n| 1 | x \\| y | **3** |\n| solo |  |  |", HtmlToMarkdown::convert($html));
    same("| a | b |\n| --- | --- |\n| c | d |", HtmlToMarkdown::convert('<table><tr><td>a</td><td>b</td></tr><tr><td>c</td><td>d</td></tr></table>'));
    same("**Título**\n\n| a |\n| --- |", HtmlToMarkdown::convert('<table><caption>Título</caption><tr><th>a</th></tr></table>'));
    same("| a |  |\n| --- | --- |", HtmlToMarkdown::convert('<table><tr><td colspan="2">a</td></tr></table>'));
});

test('details y summary dan el resumen en negrita y su contenido', function (): void {
    same("**Más información**\n\nContenido oculto\n\n- x", HtmlToMarkdown::convert('<details><summary>Más información</summary><p>Contenido oculto</p><ul><li>x</li></ul></details>'));
    same("**Pregunta**\n\nRespuesta", HtmlToMarkdown::convert('<details open><summary>Pregunta</summary>Respuesta</details>'));
});

test('se descartan nav, script, style, form, button, svg, noscript, aria-hidden y data-nomd', function (): void {
    $html = '<nav><a href="/">Inicio</a></nav><script>alert(1)</script><style>p{}</style><p>Visible</p><form><p>Formulario</p><input></form>'
        . '<button>Pulsa</button><svg><text>Icono</text></svg><noscript>Sin JS</noscript>'
        . '<p aria-hidden="true">Oculto</p><div data-nomd>Cookies</div><p>Fin<span aria-hidden="true"> ×</span></p>'
        . '<template>T</template><iframe src="https://x.example">I</iframe><video>V</video>';
    same("Visible\n\nFin", HtmlToMarkdown::convert($html));
    $out = HtmlToMarkdown::convert('<script>alert(1)</script><p onclick="x()">a</p><a href="javascript:alert(1)">b</a>');
    notContains('alert', $out);
    notContains('javascript', $out);
});

test('los espacios y las líneas en blanco quedan normalizados', function (): void {
    $out = HtmlToMarkdown::convert("<div>\n\n\n<p>a</p>\n\n\n\n<p>b</p>\n<br><br>\n<p>c</p>\n</div>\n\n\n");
    same("a\n\nb\n\nc", $out);
    check(strpos($out, "\n\n\n") === false);
    same('a b c', HtmlToMarkdown::convert("<p>a&nbsp;&nbsp; b\t\n c</p>"));
    same("a  \nb", HtmlToMarkdown::convert("<p>  a  <br>  <br>  b  </p>"));
});

test('ida y vuelta: Markdown a HTML y de nuevo a Markdown', function (): void {
    $md = "# Título\n\n"
        . "Un párrafo con **negrita**, *cursiva*, `código` y [un enlace](https://a.example/x \"t\").\n\n"
        . "![logo](https://a.example/l.png)\n\n"
        . "- uno\n  - dos\n    - tres\n- cuatro\n\n"
        . "1. primero\n2. segundo\n   - sub\n\n"
        . "> una cita\n\n"
        . "```php\n<?php echo 1;\n```\n\n"
        . "| A | B |\n| :--- | ---: |\n| 1 | 2 |\n\n"
        . "---\n\n"
        . "## Final";
    $once = HtmlToMarkdown::convert(Markdown::toHtml($md));
    same($md, $once);
    same($once, HtmlToMarkdown::convert(Markdown::toHtml($once)), 'la segunda vuelta cambia algo');
});

test('ida y vuelta conserva tildes, eñes, emojis, ampersand y escapes', function (): void {
    $md = "Cañón & niño 😀 con \\*asteriscos\\* y \\[corchetes\\] y a\\_b\n\n- 1\\. no es lista dentro\n- b";
    same($md, HtmlToMarkdown::convert(Markdown::toHtml($md)));
    $html = Markdown::toHtml('# <script>alert(1)</script> [x](javascript:alert(1))');
    $back = HtmlToMarkdown::convert($html);
    contains('\\<script\\>alert(1)\\</script\\> x', $back);
    notContains('javascript:', $back);
});
