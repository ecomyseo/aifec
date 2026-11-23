<?php
/**
 * AIFEC — el minificado de guiones y hojas de estilo (JsMinifier, CssMinifier) y los dos interruptores de «Rendimiento».
 *
 * El guion minificado tiene que ser el mismo programa: sintaxis válida con «node --check» en todos los .js de themes/, modules/ y
 * plugins/, los mismos elementos (palabras, números, cadenas, plantillas, expresiones regulares y signos) y, en los casos difíciles, el
 * mismo resultado al ejecutarlo. Las hojas minificadas conservan todas las reglas y declaraciones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Kernel\AssetServer;
use Aifec\Kernel\CssMinifier;
use Aifec\Kernel\JsMinifier;

/**
 * Todos los ficheros de una extensión bajo themes/, modules/ y plugins/.
 *
 * @return string[]
 */
function minifyFiles(string $ext, bool $withCore = false): array
{
    $root = Autoload::root();
    $out = [];
    foreach (array_merge(['themes', 'modules', 'plugins'], $withCore ? ['core/assets'] : []) as $dir) {
        if (!is_dir($root . '/' . $dir)) {
            continue;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
        foreach ($it as $file) {
            $path = str_replace('\\', '/', (string) $file);
            if (substr($path, -strlen($ext) - 1) === '.' . $ext && strpos($path, '/node_modules/') === false) {
                $out[] = $path;
            }
        }
    }
    sort($out);

    return $out;
}

/** Ejecuta node con un guion y devuelve [código de salida, salida]. */
function minifyNode(string $flag, string $file): array
{
    $lines = [];
    exec('node ' . $flag . ' ' . escapeshellarg($file) . ' 2>&1', $lines, $code);

    return [$code, implode("\n", $lines)];
}

/**
 * Reglas y declaraciones de una hoja (sin comentarios, cadenas ni url()).
 *
 * @return array{rules:int,declarations:int}
 */
function minifyCssStats(string $css): array
{
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);
    $css = (string) preg_replace('~"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|url\([^)]*\)~s', '""', $css);
    $css = (string) preg_replace('~\s+~', '', $css);
    $declarations = substr_count($css, ';');
    preg_match_all('~[^;{}]\}~', $css, $m);

    return ['rules' => substr_count($css, '{'), 'declarations' => $declarations + count($m[0])];
}

test('JsMinifier: casos difíciles que no se rompen (expresiones regulares con «/», cadenas con «//», plantillas anidadas, números)', function (): void {
    $cases = [
        'regex con barra' => "var r = /[/]\\//g;\nconsole.log('a/b//c'.replace(r, '-'));\n",
        'cadenas con comentarios' => "console.log(\"http://x.test\" + 'a/*b*/c' + `d//e`);\n",
        'plantillas anidadas' => "var o = {x: 5};\nconsole.log(`a\${ `b\${ 1 + 1 }c` }d\${ o.x }e\${ [1, 2].map(function (v) { return `<\${v}>`; }).join('') }`);\n",
        'números' => "console.log(1.5, 1..toString(), 1.5.toFixed(1), 0x1F, 1e3, .5, 5 .toString(), 2 ** 3);\n",
        'división y regex' => "var a = 8, b = 2, g = 2;\nconsole.log(a / b / g, (a) / b, [a][0] / b, 'x'.replace(/x/g, 'y'), a++ / 2);\n",
        'más y menos seguidos' => "var a = 5;\nconsole.log(1 + +2, 3 - -4, a - -a, a + ++a, a - --a);\n",
        'return y salto de línea' => "function f() {\n  return\n  5;\n}\nfunction g() {\n  return (\n    7\n  );\n}\nfunction h() {\n  return\n  (7)\n}\nfunction k() {\n  return\n  [8]\n}\nconsole.log(f(), g(), h(), k());\n",
        'línea que empieza por paréntesis' => "var a = function () { return 3; }\nvar b = a\n(function () { console.log('iife'); })\nconsole.log(typeof b);\n",
        'línea que empieza por corchete' => "var x = [1, 2]\n;[3, 4].forEach(function (v) { console.log(v); })\nvar y = x\n[0]\nconsole.log(y);\n",
        'incremento tras salto de línea' => "var i = 1, j = 1\ni\n++\nj\nconsole.log(i, j);\n",
        'sin punto y coma' => "var a = 1\nvar b = 2\nlet c = a + b\nconsole.log(c)\nif (c) console.log('si')\nelse console.log('no')\n",
        'palabras clave' => "var o = {a: 1};\nconsole.log('a' in o, typeof /x/, o instanceof Object, void 0);\nfor (const v of [1, 2]) console.log(v);\nvar t = true\n  ? 'a'\n  : 'b';\nconsole.log(t);\n",
        'comentarios' => "/* c */ var a = 1; // fin\n/* varias\n lineas */ var b = 2;\nconsole.log(a + b) // sin salto final",
        'bloques y objetos' => "var f = function () { return {a: 1} }\nvar g = function () {}\nconsole.log(f().a, typeof g)\n",
        'cadena con barra invertida' => "console.log('a\\'b', \"c\\\"d\", 'e\\\\', 'f\\\nlinea');\n",
        'etiquetas y bucles' => "outer: for (var i = 0; i < 3; i++) { for (var j = 0; j < 3; j++) { if (j == 1) continue outer; if (i == 2) break outer; console.log(i, j); } }\n",
        'asíncronas' => "async function f() { return 1 }\n(async () => { console.log(await f()); })();\n",
    ];
    $dir = tmpDir('aifec-jsmin');
    $n = 0;
    foreach ($cases as $name => $src) {
        $min = JsMinifier::minify($src);
        file_put_contents($dir . '/o' . $n . '.js', $src);
        file_put_contents($dir . '/m' . $n . '.js', $min);
        $a = minifyNode('', $dir . '/o' . $n . '.js');
        $b = minifyNode('', $dir . '/m' . $n . '.js');
        check($a[0] === 0, $name . ': el original se ejecuta (' . $a[1] . ')');
        same($a, $b, $name . ': el minificado hace lo mismo: ' . json_encode($min));
        check(strlen($min) <= strlen($src) + 1, $name . ': no crece');
        ++$n;
    }
    // Salidas exactas de los casos que más importan.
    same("function f(){return\n5;}\n", JsMinifier::minify("function f() {\n  return\n  5;\n}\n"));
    same("var a=1\nvar b=2\n", JsMinifier::minify("var a = 1\nvar b = 2\n"));
    same("a=b+c;\n", JsMinifier::minify("a = b\n  + c;\n"));
    same("var r=/[/]\\//g,s='//';\n", JsMinifier::minify("var r = /[/]\\//g, s = '//';\n"));
    same("x=1+ +y-(-z);\n", JsMinifier::minify("x = 1 + +y - (-z);\n"));
    same("a=1 .toString();\n", JsMinifier::minify("a = 1 .toString();\n"));
    same("a=1..toString();\n", JsMinifier::minify("a = 1..toString();\n"));
    same("x=a/ /re/.lastIndex;\n", JsMinifier::minify("x = a / /re/.lastIndex;\n"));
    same("i\n++\nj;\n", JsMinifier::minify("i\n++\nj;\n"), 'seguro: no se junta el ++ con la línea siguiente');
});

test('JsMinifier: un guion que no sabe leer (cadena sin cerrar) se devuelve tal cual', function (): void {
    $broken = "var a = 'sin cerrar;\n";
    same($broken, JsMinifier::minify($broken));
    $unclosed = "if (a) {\n  b();\n";
    same($unclosed, JsMinifier::minify($unclosed));
});

test('todos los .js de themes, modules y plugins minificados son sintaxis válida (node --check) y tienen los mismos elementos que el original', function (): void {
    $files = minifyFiles('js');
    check(count($files) >= 25, 'hay guiones que mirar: ' . count($files));
    [$code] = minifyNode('--version', '');
    check($code === 0, 'node está instalado');
    $dir = tmpDir('aifec-jsmin-all');
    $before = 0;
    $after = 0;
    foreach ($files as $i => $file) {
        $src = (string) file_get_contents($file);
        $min = JsMinifier::minify($src);
        $rel = substr($file, strlen(Autoload::root()) + 1);
        $before += strlen($src);
        $after += strlen($min);
        file_put_contents($dir . '/o' . $i . '.js', $src);
        file_put_contents($dir . '/m' . $i . '.js', $min);
        $original = minifyNode('--check', $dir . '/o' . $i . '.js');
        $minified = minifyNode('--check', $dir . '/m' . $i . '.js');
        same($original[0], $minified[0], $rel . ': node --check del minificado (' . $minified[1] . ')');
        check($min !== $src && strlen($min) < strlen($src), $rel . ': se ha podido leer y pierde peso (' . strlen($src) . ' → ' . strlen($min) . ')');
        // Igualdad de elementos: lo que hay en uno hay en el otro, en el mismo orden, sin contar espacios, saltos ni comentarios.
        $a = JsMinifier::tokenize($src);
        $b = JsMinifier::tokenize($min);
        check($a !== null && $b !== null, $rel . ': los dos se pueden trocear');
        same(array_map(static function (array $t): string {
            return $t['k'] . ' ' . $t['t'];
        }, (array) $a), array_map(static function (array $t): string {
            return $t['k'] . ' ' . $t['t'];
        }, (array) $b), $rel . ': los mismos elementos');
        // Y sin espacios ni saltos fuera de cadenas: el minificado no contiene dos espacios seguidos ni sangrías.
        notContains("\n ", $min, $rel . ': sin sangrías');
    }
    check($after < $before * 0.8, 'en conjunto baja más de un 20 % (' . $before . ' → ' . $after . ')');
});

test('un salto de línea que cambiaría el programa se queda, y el que no, se quita', function (): void {
    $tokens = static function (string $js): string {
        return JsMinifier::minify($js);
    };
    contains("return\n", $tokens("function f(){ return\n x }"), 'tras return');
    contains("break\n", $tokens("a: for(;;){ break\n a }"), 'tras break');
    contains("throw\n", $tokens("function f(){ throw\n new Error }"), 'tras throw');
    contains("i\n++", $tokens("i\n++\nj"), 'antes de ++');
    contains(")\nfoo", $tokens("bar()\nfoo()"), 'entre dos instrucciones sin punto y coma');
    contains("}\nfoo", $tokens("var a = function(){}\nfoo()"), 'tras una función en una expresión');
    notContains("\n", rtrim($tokens("var a = [\n1,\n2\n];"), "\n"), 'dentro de un literal no hace falta');
});

test('CssMinifier: quita lo que sobra, reescribe los valores y no toca lo que no debe', function (): void {
    $css = "/* cabecera */\n.a {\n  color: #ffffff;\n  margin: 0px 0.5em 0.50rem 0;\n  padding: 0.0px;\n  background: #AABBCCDD url( 'x.png' ) no-repeat;\n  opacity: 0.5;\n  width: calc(100% - 0px);\n  height: max(0px, 2px);\n  --gap: 0px;\n  --alpha: 0.5;\n  flex: 1 1 0px;\n  font: 0px/1.0 serif;\n  content: \"  0.5  /* no */ \";\n  transition: opacity 0.30s ease;\n}\n"
        . "@media (min-width: 600px) and (max-width: 0.5px) {\n  .b > .c, .d + .e { margin: -0.5px -0.0px; top: 0%; line-height: 1.0; }\n}\n"
        . "@supports (display: grid) { .g { display: grid; grid-template-columns: repeat(2, minmax(0px, 1fr)); inset: var(--x, 0px) env(safe-area-inset-bottom, 0px); } }\n"
        . ".h { background: url(data:image/svg+xml;utf8,<svg fill='%23ffffff'/>); border-color: #fff #ffffff #123456 #112233; }\n"
        . ".i:hover > .j { color: red !important ; transform: translate(0px, 0px) rotate(0deg); }\n";
    $min = CssMinifier::minify($css);
    foreach ([
        '.a{color:#fff;margin:0 .5em .5rem 0;padding:0;' => 'ceros, #ffffff y 0.5 → .5',
        "background:#ABCD url( 'x.png' ) no-repeat;" => 'color de ocho cifras corto y la cadena, tal cual',
        'opacity:.5;width:calc(100% - 0px);height:max(0px,2px);' => 'calc() y max() no se tocan (solo pierden el espacio tras la coma)',
        '--gap:0px;--alpha:0.5;' => 'las propiedades personalizadas no se tocan',
        'flex:1 1 0px;font:0px/1 serif;' => 'flex y font conservan las unidades',
        'content:"  0.5  /* no */ ";' => 'la cadena no se toca',
        'transition:opacity .3s ease}' => '0.30s → .3s y sin ;}',
        '@media (min-width:600px) and (max-width:0.5px){.b > .c,.d + .e{margin:-.5px 0;top:0%;line-height:1}}' => 'las condiciones y los selectores no se tocan; los valores sí',
        '@supports (display:grid){.g{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));inset:var(--x,0px) env(safe-area-inset-bottom,0px)}}' => '@supports intacto en su condición y var() y env() sin tocar',
        "url(data:image/svg+xml;utf8,<svg fill='%23ffffff'/>)" => 'url() sin comillas intacta',
        'border-color:#fff #fff #123456 #123' => 'colores cortos',
        '.i:hover > .j{color:red!important;transform:translate(0,0) rotate(0deg)}' => '!important pegado y translate(0,0)',
    ] as $needle => $why) {
        contains($needle, $min, $why . ' → ' . $min);
    }
    notContains('/*', str_replace('/* no */', '', $min), 'sin comentarios');
    notContains(';}', $min);
    $a = minifyCssStats($css);
    $b = minifyCssStats($min);
    same($a, $b, 'las mismas reglas y declaraciones');
});

test('todas las hojas de themes, modules, plugins y core minificadas conservan sus reglas y declaraciones y pierden peso', function (): void {
    $files = minifyFiles('css', true);
    check(count($files) >= 10, 'hay hojas que mirar: ' . count($files));
    $before = 0;
    $after = 0;
    foreach ($files as $file) {
        $src = (string) file_get_contents($file);
        $min = CssMinifier::minify($src);
        $rel = substr($file, strlen(Autoload::root()) + 1);
        $before += strlen($src);
        $after += strlen($min);
        same(minifyCssStats($src), minifyCssStats($min), $rel . ': las mismas reglas y declaraciones (' . strlen($src) . ' → ' . strlen($min) . ' bytes)');
        check(strlen($min) <= strlen($src), $rel . ': no crece');
    }
    check($after < $before * 0.95, 'en conjunto baja más de un 5 % (' . $before . ' → ' . $after . ')');
});

test('«Publicar»: con los interruptores encendidos se publican minificados; apagados, idénticos al original; y cambiar uno publica de nuevo', function (): void {
    $root = Autoload::root();
    $on = tmpDir('aifec-pub-on');
    $off = tmpDir('aifec-pub-off');
    $jsOnly = tmpDir('aifec-pub-js');
    $a = AssetServer::publish($root, $on);
    $b = AssetServer::publish($root, $off, ['js' => false, 'css' => false]);
    $c = AssetServer::publish($root, $jsOnly, ['js' => true, 'css' => false]);
    check($a['build'] !== $b['build'] && $a['build'] !== $c['build'] && $b['build'] !== $c['build'], 'cada combinación tiene su huella');
    same($a, AssetServer::publish($root, tmpDir('aifec-pub-on2')), 'la misma combinación da la misma huella');
    same($a['files'], $b['files']);
    foreach ([['themes/gmartos/assets/css/theme.css', 't/gmartos'], ['modules/assistant/assets/assistant.js', 'm/assistant'], ['modules/assistant/assets/assistant.css', 'm/assistant']] as [$rel, $where]) {
        $src = $root . '/' . $rel;
        if (!is_file($src)) {
            continue;
        }
        $file = substr($rel, strpos($rel, '/assets/') + 8);
        $original = (string) file_get_contents($src);
        $published = (string) file_get_contents($off . '/assets/' . $where . '/' . $b['build'] . '/' . $file);
        same($original, $published, $rel . ': apagado, el fichero publicado es idéntico al original');
        $mini = (string) file_get_contents($on . '/assets/' . $where . '/' . $a['build'] . '/' . $file);
        check(strlen($mini) < strlen($original) * 0.97, $rel . ': encendido pierde peso (' . strlen($original) . ' → ' . strlen($mini) . ')');
    }
    // Solo el JavaScript minificado: la hoja queda como el original.
    $sheet = 'modules/assistant/assets/assistant.css';
    if (is_file($root . '/' . $sheet)) {
        same((string) file_get_contents($root . '/' . $sheet), (string) file_get_contents($jsOnly . '/assets/m/assistant/' . $c['build'] . '/assistant.css'));
    }
});

test('los ajustes «Rendimiento»: están encendidos de serie, la hoja de la página sigue los interruptores y cambiarlos vuelve a publicar y vacía la caché', function (): void {
    $app = testApp(['config' => ['inline_css' => true]]);
    $root = Autoload::root();
    $fields = [];
    foreach ((array) (require $root . '/core/settings.php')['tabs'] as $tab) {
        $fields += $tab['fields'];
    }
    foreach (['assets.minify_js', 'assets.minify_css'] as $key) {
        check(isset($fields[$key]) && $fields[$key]['type'] === 'toggle' && $fields[$key]['default'] === true, $key . ': interruptor encendido de serie');
        same(true, $app->settings()->bool($key, true));
    }
    // La hoja que se escribe en la página: minificada o tal cual, según el interruptor.
    $web = new TestClient($app);
    $css = static function (string $body): string {
        return preg_match('#<style data-af-css="[^"]*/assets/t/base/[^"]*/css/theme\.css">(.+?)</style>#s', $body, $m) === 1 ? $m[1] : '';
    };
    $minified = $css($web->get('/')->body);
    check($minified !== '' && strpos($minified, "\n") === false, 'encendido: sin saltos de línea');
    $app->settings()->set('assets.minify_css', false);
    $app->invalidate(['pages']);
    $raw = $css((new TestClient($app))->get('/')->body);
    same((string) file_get_contents($root . '/themes/base/assets/css/theme.css'), $raw, 'apagado: la hoja original, sin tocar');
    $app->settings()->set('assets.minify_css', true);
    $app->invalidate(['pages']);

    // Al guardar el ajuste, los recursos publicados se publican otra vez con la huella nueva.
    $public = $app->publicDir();
    $first = AssetServer::publish($root, $public, AssetServer::optionsFor($app));
    same($first['build'], trim((string) file_get_contents($public . '/assets/build.txt')), 'la publicación apunta su huella');
    $app->settings()->set('assets.minify_js', false);
    $app->hooks()->fire('settings.saved', 'core', ['assets.minify_js'], $app);
    $now = trim((string) file_get_contents($public . '/assets/build.txt'));
    check($now !== $first['build'], 'la huella cambia al apagar el JavaScript (' . $first['build'] . ' → ' . $now . ')');
    $app->settings()->set('assets.minify_js', true);
    $app->hooks()->fire('settings.saved', 'core', ['assets.minify_js'], $app);
    same($first['build'], trim((string) file_get_contents($public . '/assets/build.txt')), 'y vuelve a la de antes al encenderlo');
    // Otro ajuste no publica nada.
    file_put_contents($public . '/assets/build.txt', 'zzzz1');
    $app->hooks()->fire('settings.saved', 'core', ['store.name'], $app);
    same('zzzz1', trim((string) file_get_contents($public . '/assets/build.txt')), 'otro ajuste no publica');
});
