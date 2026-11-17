<?php
/**
 * AIFEC — pruebas del conversor de Markdown a HTML.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Text\Markdown;

test('encabezados de nivel 1 a 6 con identificador sin tildes y sin repetir', function (): void {
    same('<h1 id="titulo">Título</h1>', Markdown::toHtml('# Título'));
    same('<h6 id="seis">Seis</h6>', Markdown::toHtml('###### Seis'));
    same('<h2 id="cancion-del-nino-n">Canción del niño ñ</h2>', Markdown::toHtml('## Canción del niño ñ ##'));
    same("<h2 id=\"intro\">Intro</h2>\n<h3 id=\"intro-2\">Intro</h3>\n<h3 id=\"intro-3\">intro</h3>", Markdown::toHtml("## Intro\n### Intro\n### intro"));
    same('<h2 id="section">!!!</h2>', Markdown::toHtml('## !!!'));
    same('<h1 id="negrita-y-codigo"><strong>Negrita</strong> y <code>código</code></h1>', Markdown::toHtml('# **Negrita** y `código`'));
    same("<p>####### siete</p>\n<p>#hashtag</p>", Markdown::toHtml("####### siete\n\n#hashtag"));
    same('<h1 id="a">a</h1>', Markdown::toHtml('#   a   '));
});

test('encabezados subrayados con = y con -', function (): void {
    same("<h1 id=\"uno\">Uno</h1>\n<h2 id=\"dos-lineas\">Dos\nlíneas</h2>", Markdown::toHtml("Uno\n===\n\nDos\nlíneas\n---"));
    same("<p>a</p>\n<hr>", Markdown::toHtml("a\n\n---"));
});

test('párrafos, saltos suaves y saltos duros', function (): void {
    same("<p>uno\ndos</p>\n<p>tres</p>", Markdown::toHtml("uno\ndos\n\n\n\ntres"));
    same("<p>a<br>\nb</p>", Markdown::toHtml("a  \nb"));
    same("<p>a<br>\nb</p>", Markdown::toHtml("a\\\nb"));
    same("<p>a<br>\nb<br>\nc</p>", Markdown::toHtml("a    \n    b\\\n  c"));
    same("<p>fin</p>", Markdown::toHtml("fin  "));
    same('', Markdown::toHtml(''));
    same('', Markdown::toHtml("  \n\n\t\n"));
});

test('negrita, cursiva y combinaciones', function (): void {
    same('<p><strong>b</strong> <em>i</em> <em>i</em> <strong>b</strong></p>', Markdown::toHtml('**b** *i* _i_ __b__'));
    same('<p><em><strong>x</strong></em></p>', Markdown::toHtml('***x***'));
    same('<p><strong>a <em>b</em> c</strong></p>', Markdown::toHtml('**a *b* c**'));
    same('<p>snake_case_word y 2 * 3 * 4 y a*b</p>', Markdown::toHtml('snake_case_word y 2 * 3 * 4 y a*b'));
    same('<p>x<em>y</em>z</p>', Markdown::toHtml('x*y*z'));
    same('<p>** no ** cierra</p>', Markdown::toHtml('** no ** cierra'));
    same('<p>*sin cierre</p>', Markdown::toHtml('*sin cierre'));
    same('<p><em>Ñandú</em> y <strong>niño</strong></p>', Markdown::toHtml('*Ñandú* y **niño**'));
});

test('código en línea y bloques con vallas', function (): void {
    same('<p>usa <code>a &lt; b</code> y <code>x`y</code></p>', Markdown::toHtml('usa `a < b` y ``x`y``'));
    same('<p><code>*no* **negrita**</code></p>', Markdown::toHtml('`*no* **negrita**`'));
    same("<pre><code class=\"language-php\">&lt;?php echo \"a\" &amp; 'b';\n\nfoo();\n</code></pre>", Markdown::toHtml("```php\n<?php echo \"a\" & 'b';\n\nfoo();\n```"));
    same("<pre><code>sin lenguaje\n</code></pre>", Markdown::toHtml("~~~\nsin lenguaje\n~~~"));
    same("<pre><code>sin cierre\n# no es encabezado\n</code></pre>", Markdown::toHtml("```\nsin cierre\n# no es encabezado"));
    same("<pre><code class=\"language-js\">a\n```\nb\n</code></pre>", Markdown::toHtml("````js\na\n```\nb\n````"));
    same('<pre><code></code></pre>', Markdown::toHtml("```\n```"));
    same("<p>texto</p>\n<pre><code>x\n</code></pre>\n<p>más</p>", Markdown::toHtml("texto\n```\nx\n```\nmás"));
});

test('citas, también anidadas y con continuación perezosa', function (): void {
    same("<blockquote>\n<p>uno\ndos</p>\n</blockquote>", Markdown::toHtml("> uno\n> dos"));
    same("<blockquote>\n<p>uno\ndos</p>\n</blockquote>", Markdown::toHtml("> uno\ndos"));
    same("<blockquote>\n<p>a</p>\n<blockquote>\n<p>b</p>\n</blockquote>\n</blockquote>", Markdown::toHtml("> a\n>\n> > b"));
    same("<blockquote>\n<h2 id=\"t\">T</h2>\n<ul>\n<li>x</li>\n</ul>\n</blockquote>", Markdown::toHtml("> ## T\n> - x"));
    same("<blockquote>\n<p>a</p>\n</blockquote>\n<blockquote>\n<p>b</p>\n</blockquote>", Markdown::toHtml("> a\n\n> b"));
});

test('listas sin ordenar y ordenadas, cortas y separadas', function (): void {
    same("<ul>\n<li>a</li>\n<li>b</li>\n</ul>", Markdown::toHtml("- a\n- b"));
    same("<ul>\n<li>a</li>\n<li>b</li>\n</ul>", Markdown::toHtml("* a\n* b"));
    same("<ol>\n<li>a</li>\n<li>b</li>\n</ol>", Markdown::toHtml("1. a\n2. b"));
    same("<ol start=\"3\">\n<li>c</li>\n<li>d</li>\n</ol>", Markdown::toHtml("3. c\n4. d"));
    same("<ul>\n<li>\n<p>a</p>\n</li>\n<li>\n<p>b</p>\n</li>\n</ul>", Markdown::toHtml("- a\n\n- b"));
    same("<ul>\n<li>\n<p>a</p>\n<p>b</p>\n</li>\n</ul>", Markdown::toHtml("- a\n\n  b"));
    same("<ul>\n<li>a\nb</li>\n</ul>", Markdown::toHtml("- a\nb"));
    same("<p>texto</p>\n<ul>\n<li>x</li>\n</ul>", Markdown::toHtml("texto\n- x"));
    same("<p>en\n2018. fue</p>", Markdown::toHtml("en\n2018. fue"));
    same("<ul>\n<li>a</li>\n</ul>\n<ul>\n<li>b</li>\n</ul>", Markdown::toHtml("- a\n+ b"));
    same("<ul>\n<li></li>\n</ul>", Markdown::toHtml('-'));
    same("<ul>\n<li>a</li>\n</ul>\n<p>fuera</p>", Markdown::toHtml("- a\n\nfuera"));
    same("<ul>\n<li>x\n<pre><code>c\n</code></pre>\n</li>\n</ul>", Markdown::toHtml("- x\n  ```\n  c\n  ```"));
});

test('lista anidada de tres niveles', function (): void {
    $html = Markdown::toHtml("- uno\n  - dos\n    - tres\n- cuatro");
    same("<ul>\n<li>uno\n<ul>\n<li>dos\n<ul>\n<li>tres</li>\n</ul>\n</li>\n</ul>\n</li>\n<li>cuatro</li>\n</ul>", $html);
    $mixed = Markdown::toHtml("1. a\n   - b\n     1. c\n2. d");
    same("<ol>\n<li>a\n<ul>\n<li>b\n<ol>\n<li>c</li>\n</ol>\n</li>\n</ul>\n</li>\n<li>d</li>\n</ol>", $mixed);
    $sloppy = Markdown::toHtml("- a\n    - b\n        - c");
    same(3, substr_count($sloppy, '<ul>'));
});

test('enlaces, imágenes y enlaces automáticos', function (): void {
    same('<p><a href="https://a.example/x?a=1&amp;b=2" title="Título">texto <em>cursiva</em></a></p>', Markdown::toHtml('[texto *cursiva*](https://a.example/x?a=1&b=2 "Título")'));
    same('<p><a href="/ruta">a</a> <a href="#ancla">b</a> <a href="mailto:a@b.example">c</a></p>', Markdown::toHtml('[a](/ruta) [b](#ancla) [c](mailto:a@b.example)'));
    same('<p><img src="a.png" alt="alt x" title="t"></p>', Markdown::toHtml('![alt *x*](a.png \'t\')'));
    same('<p><a href="https://a.example/a%20b">x</a> <a href="https://a.example/a(b)">y</a></p>', Markdown::toHtml('[x](<https://a.example/a b>) [y](https://a.example/a(b))'));
    same('<p><a href="https://a.example/x">https://a.example/x</a> <a href="mailto:a@b.example">a@b.example</a></p>', Markdown::toHtml('<https://a.example/x> <a@b.example>'));
    same('<p><a href="https://a.example"><img src="i.png" alt="i"></a></p>', Markdown::toHtml('[![i](i.png)](https://a.example)'));
    same('<p>[sin cierre](a y [x] y ![y]</p>', Markdown::toHtml('[sin cierre](a y [x] y ![y]'));
    same('<p><a href="x">a [b](y) c</a></p>', Markdown::toHtml('[a [b](y) c](x)'));
});

test('un enlace con javascript: sale sin href y nunca queda un esquema peligroso', function (): void {
    same('<p><a>x</a></p>', Markdown::toHtml('[x](javascript:alert(1))'));
    same('<p><a title="t">x</a></p>', Markdown::toHtml('[x](JaVaScRiPt:alert(1) "t")'));
    same('<p><a>x</a></p>', Markdown::toHtml('[x](&#106;avascript:alert(1))'));
    same('<p><a>x</a></p>', Markdown::toHtml('[x](data:text/html;base64,PHNjcmlwdD4=)'));
    same('<p><a>x</a></p>', Markdown::toHtml('[x](<vbscript:foo>)'));
    same('<p>alt</p>', Markdown::toHtml('![alt](javascript:alert(1))'));
    same('<p>&lt;javascript:alert(1)&gt;</p>', Markdown::toHtml('<javascript:alert(1)>'));
    foreach (['[x](javascript:alert(1))', '![x](javascript:alert(1))', '<javascript:alert(1)>', '[x]( javascript:alert(1))', "[x](jav\tascript:alert(1))"] as $md) {
        notContains('href="javascript', Markdown::toHtml($md));
        notContains('src="javascript', Markdown::toHtml($md));
    }
});

test('el HTML incrustado sale escapado y no se interpreta', function (): void {
    same('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>', Markdown::toHtml('<script>alert(1)</script>'));
    same('<p>&lt;b onclick="x()"&gt;negrita&lt;/b&gt; y <strong>md</strong></p>', Markdown::toHtml('<b onclick="x()">negrita</b> y **md**'));
    same("<p>&lt;div&gt;\nhola\n&lt;/div&gt;</p>", Markdown::toHtml("<div>\nhola\n</div>"));
    same('<p>&lt;img src=x onerror=alert(1)&gt;</p>', Markdown::toHtml('<img src=x onerror=alert(1)>'));
    same('<p>&lt;!-- c --&gt;</p>', Markdown::toHtml('<!-- c -->'));
    same('<p>&lt;script&gt; &amp;amp; © © "</p>', Markdown::toHtml('&lt;script&gt; &amp;amp; &copy; &#169; &quot;'));
    same('<h1 id="script-x-script">&lt;script&gt;x&lt;/script&gt;</h1>', Markdown::toHtml('# <script>x</script>'));
    same('<pre><code>&lt;script&gt;x&lt;/script&gt;'."\n".'</code></pre>', Markdown::toHtml("```\n<script>x</script>\n```"));
});

test('el HTML escapado de las entidades se decodifica una sola vez', function (): void {
    same('<p>&lt;b&gt; y &amp; y © y "q"</p>', Markdown::toHtml('&lt;b&gt; y &amp; y &copy; y &quot;q&quot;'));
    same('<p>a &amp; b &amp;copy no; AT&amp;T</p>', Markdown::toHtml('a & b &copy no; AT&T'));
    same('<p>&lt;</p>', Markdown::toHtml('&#60;'));
});

test('tabla con alineación, escapes de barra y filas cortas', function (): void {
    $html = Markdown::toHtml("| Izq | Centro | Der |\n|:---|:---:|---:|\n| a | b | c |\n| solo | `x\\|y` |");
    same("<table>\n<thead>\n<tr>\n<th style=\"text-align:left\">Izq</th>\n<th style=\"text-align:center\">Centro</th>\n<th style=\"text-align:right\">Der</th>\n</tr>\n</thead>\n<tbody>\n"
        . "<tr>\n<td style=\"text-align:left\">a</td>\n<td style=\"text-align:center\">b</td>\n<td style=\"text-align:right\">c</td>\n</tr>\n"
        . "<tr>\n<td style=\"text-align:left\">solo</td>\n<td style=\"text-align:center\"><code>x|y</code></td>\n<td style=\"text-align:right\"></td>\n</tr>\n</tbody>\n</table>", $html);
    same("<table>\n<thead>\n<tr>\n<th>A</th>\n<th>B</th>\n</tr>\n</thead>\n</table>\n<p>texto</p>", Markdown::toHtml("A | B\n--|--\n\ntexto"));
    same("<p>a | b\nc | d</p>", Markdown::toHtml("a | b\nc | d"));
    same("<p>a | b\n| x | y | z |</p>", Markdown::toHtml("a | b\n| x | y | z |"));
    contains('<td><strong>1</strong></td>', Markdown::toHtml("|A|\n|-|\n|**1**|"));
});

test('reglas horizontales y escapes con barra invertida', function (): void {
    same("<hr>\n<hr>\n<hr>", Markdown::toHtml("---\n\n***\n\n_ _ _"));
    same('<p>*no cursiva* # no encabezado [no](enlace) `no código` &lt;no&gt;</p>', Markdown::toHtml('\*no cursiva\* # no encabezado \[no\](enlace) \`no código\` \<no\>'));
    same("<p>1. no lista</p>", Markdown::toHtml('1\. no lista'));
    same("<p># no encabezado</p>", Markdown::toHtml('\# no encabezado'));
    same('<p>barra \ suelta y \\</p>', Markdown::toHtml('barra \ suelta y \\\\'));
});

test('conserva tildes, eñes, emojis, comillas y ampersand', function (): void {
    same('<p>Cañón &amp; niño 😀 "comillas" «ñ» it\'s</p>', Markdown::toHtml('Cañón & niño 😀 "comillas" «ñ» it\'s'));
    same('<h2 id="ano-nuevo">Año nuevo 😀</h2>', Markdown::toHtml('## Año nuevo 😀'));
    same('<p><strong>😀</strong> y <em>ñ</em></p>', Markdown::toHtml('**😀** y *ñ*'));
});

test('el texto con tabuladores, saltos de Windows y bytes raros se normaliza', function (): void {
    same("<ul>\n<li>a\n<ul>\n<li>b</li>\n</ul>\n</li>\n</ul>", Markdown::toHtml("-\ta\r\n\t- b\r\n"));
    same('<p>ab</p>', Markdown::toHtml("a\x00b"));
    same('<p>a?b</p>', Markdown::toHtml("a\xFFb"));
    same('<p>hola</p>', Markdown::toHtml("\xEF\xBB\xBFhola"));
});

test('un anidamiento enorme ni se cuelga ni deja pasar HTML', function (): void {
    $html = Markdown::toHtml(str_repeat('> ', 400) . '<script>x</script>');
    notContains('<script', $html);
    contains('&lt;script&gt;', $html);
    $deep = Markdown::toHtml(str_repeat('[', 300) . 'x' . str_repeat('](y)', 300));
    notContains('<script', $deep);
    $long = Markdown::toHtml(str_repeat('*a ', 3000));
    check(strlen($long) > 0);
    $lists = Markdown::toHtml(implode("\n", array_map(static fn (int $i): string => str_repeat('  ', $i) . '- x', range(0, 60))));
    contains('<li>x', $lists);
});

test('la salida del Markdown pasa el saneador sin perder estructura', function (): void {
    $md = "# T\n\ntexto **b** [e](https://a.example)\n\n- a\n  - b\n\n| A |\n|---|\n| 1 |\n\n```php\nx\n```\n";
    $html = Markdown::toHtml($md);
    $clean = \Aifec\Text\HtmlSanitizer::clean($html);
    foreach (['<h1 id="t">', '<strong>b</strong>', '<a href="https://a.example">', '<ul>', '<table>', '<th>A</th>', '<code class="language-php">'] as $piece) {
        contains($piece, $clean);
    }
});
