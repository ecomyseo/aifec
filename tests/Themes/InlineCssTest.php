<?php
/**
 * AIFEC — pruebas de las hojas de estilo escritas dentro de la página.
 *
 * Nace de un informe de PageSpeed que pasó el usuario el 13/11/2026 sobre una ficha de producto: «las solicitudes están
 * bloqueando el renderizado inicial» (theme.css, 150 ms) y una cadena de peticiones críticas de cuatro hojas (theme.css,
 * assistant.css, consent.css y reviews.css; 271 ms). En producción cada hoja propia va dentro de un <style>: la página se
 * pinta sin esperar a ninguna otra petición. En las demás pruebas las hojas van enlazadas (tests/support.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Front\ThemeVars;

test('de serie (producción) la hoja del tema va dentro de la página: ninguna petición bloquea el primer pintado', function (): void {
    $web = new TestClient(testApp(['config' => ['inline_css' => true]]));
    $r = $web->get('/');
    same(200, $r->status);
    same(0, preg_match_all('#<link[^>]+rel="stylesheet"#', $r->body), 'la portada no enlaza ninguna hoja de estilo');
    check(preg_match('#<style data-af-css="[^"]*/assets/t/base/[^"]*/css/theme\.css">(.+?)</style>#s', $r->body, $m) === 1, 'la hoja del tema va en un <style> que dice de dónde sale');
    $css = (string) $m[1];
    check(strlen($css) > 5000, 'trae la hoja entera');
    contains('.af-header', $css);
    notContains('/*', $css, 'sin comentarios');
    notContains("\n", $css, 'sin saltos de línea');
    check(strpos($r->body, '<style data-af-css') < strpos($r->body, '</head>'), 'va en la cabecera, antes del contenido');
    check(isset($r->headers['Content-Security-Policy']) && strpos((string) $r->headers['Content-Security-Policy'], "style-src 'self' 'unsafe-inline'") !== false, 'la política de contenido deja pintar los estilos de la página');
    check(isset($r->headers['X-Cache']), 'la página, con su hoja dentro, se sigue guardando en la caché');
});

test('quien instala puede volver a las hojas enlazadas con inline_css => false, y sin la clave van dentro', function (): void {
    $linked = new TestClient(testApp(['config' => ['inline_css' => false]]));
    $body = $linked->get('/')->body;
    check(preg_match('#<link rel="stylesheet" href="[^"]*/assets/t/base/[^"]*/css/theme\.css">#', $body) === 1, 'enlazada');
    notContains('<style data-af-css', $body);

    $app = testApp(['config' => ['inline_css' => true]]);
    contains('<style data-af-css="', ThemeVars::styleTags($app));
    // La clave no viene en la configuración de una instalación normal: sin ella, dentro.
    $support = (string) file_get_contents(Autoload::root() . '/tests/support.php');
    contains("'inline_css' => false,", $support, 'solo las pruebas las enlazan');
    $source = (string) file_get_contents(Autoload::root() . '/core/src/Front/ThemeVars.php');
    contains("\$app->site()->get('inline_css', true) !== false", $source, 'de serie, dentro de la página');
});

test('las hojas de los módulos que se pintan en la tienda usan la misma pieza (ni una en la cadena de peticiones críticas)', function (): void {
    $root = Autoload::root();
    foreach ([
        'modules/reviews/views/front/product.php' => "styleTag(\$af_app, 'm', 'reviews', 'reviews.css')",
        'modules/reviews/views/front/write.php' => "styleTag(app(), 'm', 'reviews', 'reviews.css')",
        'modules/consent/views/front/notice.php' => "styleTag(app(), 'm', 'consent', 'consent.css')",
    ] as $file => $call) {
        $view = (string) file_get_contents($root . '/' . $file);
        contains($call, $view, $file);
        notContains('rel="stylesheet"', $view, $file . ': no enlaza su hoja a mano');
    }
    $app = testApp(['config' => ['inline_css' => true]]);
    foreach (['reviews' => 'reviews.css', 'consent' => 'consent.css'] as $module => $sheet) {
        $tag = ThemeVars::styleTag($app, 'm', $module, $sheet);
        check(strpos($tag, '<style data-af-css="') === 0 && substr($tag, -8) === '</style>', $module . ': dentro de la página');
        check(strlen($tag) < 8000, $module . ': una hoja pequeña');
    }
});

test('inlineCss: quita comentarios y espacios, respeta las cadenas, y no escribe en la página lo que no conviene', function (): void {
    $dir = tmpDir('aifec-css');
    $ok = $dir . '/ok.css';
    file_put_contents($ok, "/* cabecera */\n.a  {  color : red ;\n  content: \"a  /* no */  b\" ; }\n\n.b > .c ,\n.d { margin : 0 auto ; }\n@media (max-width: 600px) { .a { display: none; } }\n");
    $css = (string) ThemeVars::inlineCss($ok);
    same('.a{color:red;content:"a  /* no */  b"}.b > .c,.d{margin:0 auto}@media (max-width:600px){.a{display:none}}', $css);
    // Con el interruptor «Minificar el CSS» apagado, la hoja va tal cual.
    same((string) file_get_contents($ok), (string) ThemeVars::inlineCss($ok, ThemeVars::INLINE_CSS_MAX, false));

    $cases = [
        'cierre.css' => '.a::after { content: "</style><script>alert(1)</script>"; }',
        'importa.css' => '@import "otra.css"; .a { color: red; }',
        'relativa.css' => '.a { background: url(../img/fondo.png); }',
        'relativa2.css' => ".a { background: url( 'img/fondo.png' ); }",
    ];
    foreach ($cases as $name => $content) {
        file_put_contents($dir . '/' . $name, $content);
        same(null, ThemeVars::inlineCss($dir . '/' . $name), $name . ': se enlaza, no se escribe en la página');
    }
    file_put_contents($dir . '/datos.css', '.a { background: url(data:image/svg+xml;base64,AAAA) no-repeat; } .b { background: url("/media/x.png"); }');
    check(is_string(ThemeVars::inlineCss($dir . '/datos.css')), 'una dirección completa o de datos no estorba');
    file_put_contents($dir . '/grande.css', str_repeat('.a { color: red; } ', 4000));
    same(null, ThemeVars::inlineCss($dir . '/grande.css'), 'por encima del tope se enlaza: el navegador la guarda en su caché');
    same(null, ThemeVars::inlineCss($dir . '/no-existe.css'));

    $app = testApp(['config' => ['inline_css' => true]]);
    foreach (['../core', 'a/b', 'Módulo'] as $slug) {
        check(strpos(ThemeVars::styleTag($app, 'm', $slug, 'x.css'), '<link rel="stylesheet"') === 0, 'un nombre de módulo raro no lee ningún fichero');
    }
    check(strpos(ThemeVars::styleTag($app, 'm', 'reviews', '../../../config.php'), '<link rel="stylesheet"') === 0, 'no se sale de la carpeta de recursos');
    check(strpos(ThemeVars::styleTag($app, 'x', 'reviews', 'reviews.css'), '<link rel="stylesheet"') === 0, 'un tipo que no existe se enlaza');
});

test('todas las hojas de los temas y de los módulos de la tienda caben dentro de la página', function (): void {
    $root = Autoload::root();
    $sheets = array_merge(glob($root . '/themes/*/assets/css/theme.css') ?: [], [$root . '/modules/reviews/assets/reviews.css', $root . '/modules/consent/assets/consent.css', $root . '/modules/assistant/assets/assistant.css']);
    check(count($sheets) >= 6, 'se han mirado las hojas');
    foreach ($sheets as $sheet) {
        $name = str_replace($root . '/', '', str_replace('\\', '/', $sheet));
        check(is_string(ThemeVars::inlineCss($sheet)), $name . ': no se puede escribir dentro de la página (pasa de ' . ThemeVars::INLINE_CSS_MAX . ' bytes, o trae url() relativas o @import)');
    }
});
