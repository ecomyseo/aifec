<?php
/**
 * AIFEC — pruebas del validador de temas: cada regla del contrato se rompe a propósito sobre una copia del tema base y tiene
 * que saltar con su identificador, su nivel y su fichero.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Themes\Validator;

require_once __DIR__ . '/ThemesKit.php';

/**
 * Informe de un tema de una raíz de mentira.
 *
 * @return array<string,mixed>
 */
function themesReport(string $root, string $slug, bool $dynamic = false): array
{
    return themesSvc(themesApp($root))->validate($slug, $dynamic);
}

/** El contenido del envoltorio del tema base, para sacar copias cambiadas. */
function themesBaseLayout(string $root): string
{
    return (string) file_get_contents($root . '/themes/base/templates/layout.php');
}

/**
 * La comprobación de un informe con ese id y ese fichero (la primera que falla, o la primera si ninguna falla).
 *
 * @param array<string,mixed> $report
 *
 * @return array<string,mixed>|null
 */
function themesCheck(array $report, string $id, ?string $file = null): ?array
{
    $first = null;
    foreach ($report['checks'] as $check) {
        if ($check['id'] !== $id || ($file !== null && $check['file'] !== $file)) {
            continue;
        }
        if (!$check['ok']) {
            return $check;
        }
        $first = $first === null ? $check : $first;
    }

    return $first;
}

themesTest('el tema base tal cual pasa el validador, también en vivo, y cada comprobación trae su forma', function (): void {
    $root = themesRoot();
    $report = themesReport($root, 'base', true);
    same(true, $report['ok'], json_encode(themesFailed($report)));
    same(0, $report['errors']);
    same([], array_diff(themesFailed($report, 'warning'), ['escaping']), 'del tema base solo se admiten avisos de escape');
    same('base', $report['slug']);
    check($report['score'] >= 80 && $report['score'] <= 100, 'puntuación ' . $report['score']);
    foreach ($report['checks'] as $check) {
        same(['id', 'level', 'ok', 'message', 'file'], array_keys($check));
        check(in_array($check['level'], ['error', 'warning'], true), 'nivel de ' . $check['id']);
        check(is_bool($check['ok']) && $check['message'] !== '', 'mensaje de ' . $check['id']);
    }
    $ids = array_column($report['checks'], 'id');
    foreach (Validator::TEMPLATES as $name) {
        check(in_array('template:' . $name, $ids, true), 'comprueba la plantilla ' . $name);
    }
    foreach (['manifest', 'parent', 'wrapper-head', 'wrapper-main', 'wrapper-mobile-bar', 'css-size', 'js-size', 'external-urls', 'imports', 'external-tags', 'option-vars', 'i18n', 'contrast:text', 'contrast:accent', 'contrast:accent-text', 'contrast:old-price', 'live-home', 'live-error', 'live-head', 'live-links'] as $id) {
        $found = themesCheck($report, $id);
        check($found !== null && $found['ok'], 'pasa ' . $id);
    }
});

themesTest('el envoltorio sin page_head(), sin <main id="main"> o sin la barra del móvil da error', function (): void {
    $root = themesRoot();
    $layout = themesBaseLayout($root);
    themesChild($root, 'sin-head', ['templates/layout.php' => str_replace('page_head(', 'otra_cosa(', $layout)]);
    themesChild($root, 'sin-main', ['templates/layout.php' => str_replace('<main id="main"', '<div id="principal"', $layout)]);
    themesChild($root, 'sin-barra', ['templates/layout.php' => str_replace('partials/mobile-bar', 'partials/otra', $layout)]);
    $head = themesReport($root, 'sin-head');
    same(false, $head['ok']);
    $check = themesCheck($head, 'wrapper-head');
    same('error', $check['level']);
    same('templates/layout.php', $check['file']);
    same(true, themesCheck($head, 'wrapper-main')['ok']);
    $main = themesReport($root, 'sin-main');
    same(false, themesCheck($main, 'wrapper-main')['ok']);
    same(true, themesCheck($main, 'wrapper-head')['ok']);
    $bar = themesReport($root, 'sin-barra');
    same(false, themesCheck($bar, 'wrapper-mobile-bar')['ok']);
    themesChild($root, 'bueno');
    $score = themesReport($root, 'bueno')['score'];
    same(100, $score);
    check($head['score'] < $score, 'un fallo bloqueante baja la puntuación: ' . $head['score'] . ' frente a ' . $score);
});

themesTest('una dirección ajena en una plantilla o en un recurso, un @import o un <script src> externo dan error', function (): void {
    $root = themesRoot();
    themesChild($root, 'ajeno', [
        'templates/partials/footer.php' => "<?php\n?>\n<a href=\"https://rastreador.example/x\">x</a>\n",
        'assets/css/theme.css' => "@import url('otra.css');\nbody{background:url(https://cdn.example/fondo.png)}\n",
        'templates/partials/nav.php' => "<?php\n?>\n<script src=\"//cdn.example/lib.js\"></script>\n",
    ]);
    $report = themesReport($root, 'ajeno');
    same(false, $report['ok']);
    $urls = themesCheck($report, 'external-urls', 'templates/partials/footer.php');
    same(false, $urls['ok']);
    same('error', $urls['level']);
    same(false, themesCheck($report, 'external-urls', 'assets/css/theme.css')['ok']);
    same(false, themesCheck($report, 'imports', 'assets/css/theme.css')['ok']);
    same(false, themesCheck($report, 'external-tags', 'templates/partials/nav.php')['ok']);
    // El espacio de nombres de un SVG no es una petición.
    themesChild($root, 'svg-limpio', ['templates/partials/qty.php' => "<?php\n?>\n<svg xmlns=\"http://www.w3.org/2000/svg\"></svg>\n"]);
    $clean = themesReport($root, 'svg-limpio');
    same([], array_diff(themesFailed($clean), []), json_encode(themesFailed($clean)));
});

themesTest('theme.css de 60 KB y theme.js de 30 KB dan error de peso; en el límite justo pasan', function (): void {
    $root = themesRoot();
    themesChild($root, 'pesado', [
        'assets/css/theme.css' => '/*' . str_repeat('x', 60000) . "*/\n",
        'assets/js/theme.js' => '//' . str_repeat('y', 30000) . "\n",
    ]);
    themesChild($root, 'justo', [
        'assets/css/theme.css' => '/*' . str_repeat('x', 45000 - 5) . "*/\n",
        'assets/js/theme.js' => '//' . str_repeat('y', 25000 - 3) . "\n",
    ]);
    $report = themesReport($root, 'pesado');
    same(false, $report['ok']);
    $css = themesCheck($report, 'css-size');
    same(false, $css['ok']);
    same('assets/css/theme.css', $css['file']);
    contains('60', $css['message']);
    same(false, themesCheck($report, 'js-size')['ok']);
    same(45000, filesize($root . '/themes/justo/assets/css/theme.css'));
    $edge = themesReport($root, 'justo');
    same(true, themesCheck($edge, 'css-size')['ok']);
    same(true, themesCheck($edge, 'js-size')['ok']);
});

themesTest('borrar partials/mobile-bar.php en un tema sin padre da error; en un hijo lo hereda y no pasa nada', function (): void {
    $root = themesRoot();
    themesChild($root, 'hijo');
    unlink($root . '/themes/base/templates/partials/mobile-bar.php');
    $base = themesReport($root, 'base');
    same(false, $base['ok']);
    same(false, themesCheck($base, 'template:partials/mobile-bar')['ok']);
    same(true, themesCheck($base, 'template:partials/header')['ok']);
    $child = themesReport($root, 'hijo');
    same(false, themesCheck($child, 'template:partials/mobile-bar')['ok'], 'el padre tampoco la tiene');
    // Con la pieza en el padre, el hijo no necesita copiarla.
    $root2 = themesRoot();
    themesChild($root2, 'hijo');
    same(true, themesCheck(themesReport($root2, 'hijo'), 'template:partials/mobile-bar')['ok']);
    $own = themesRoot();
    themesChild($own, 'propio', ['templates/partials/mobile-bar.php' => "<?php\n?>\n<nav></nav>\n"]);
    unlink($own . '/themes/base/templates/partials/mobile-bar.php');
    same(true, themesCheck(themesReport($own, 'propio'), 'template:partials/mobile-bar')['ok'], 'la pieza propia basta');
});

themesTest('un <?= $nombre ?> sin escapar es un aviso, no un error; e(), t(), url() y compañía no avisan', function (): void {
    $root = themesRoot();
    themesChild($root, 'sin-escapar', ['templates/partials/price.php' => "<?php\n\$a = 1;\n?>\n<p><?= \$nombre ?></p>\n"]);
    themesChild($root, 'escapado', ['templates/partials/price.php' => "<?php\n\$a = 1;\n?>\n<p><?= e(\$nombre) ?> <?= (int) \$a ?> <?= \$this->partial('x') ?> <?= url('/') ?> <?= t('Cart') ?></p>\n"]);
    $report = themesReport($root, 'sin-escapar');
    same(true, $report['ok'], 'solo un aviso');
    $check = themesCheck($report, 'escaping');
    same(false, $check['ok']);
    same('warning', $check['level']);
    same('templates/partials/price.php', $check['file']);
    contains('4', $check['message'], 'dice en qué línea');
    same(['escaping'], themesFailed($report, 'warning'));
    $fine = themesReport($root, 'escapado');
    same([], themesFailed($fine, 'warning'));
    same(true, themesCheck($fine, 'escaping')['ok']);
});

themesTest('una cadena t() sin traducir en lang/es.php da error, y con su traducción pasa', function (): void {
    $root = themesRoot();
    themesChild($root, 'sin-traducir', ['templates/partials/qty.php' => "<?php\n?>\n<p><?= e(t('Texto sin traducir')) ?></p>\n"]);
    $report = themesReport($root, 'sin-traducir');
    same(false, $report['ok']);
    $check = themesCheck($report, 'i18n');
    same(false, $check['ok']);
    same('error', $check['level']);
    contains('Texto sin traducir', $check['message']);
    same('templates/partials/qty.php', $check['file']);
    themesChild($root, 'traducido', [
        'templates/partials/qty.php' => "<?php\n?>\n<p><?= e(t('Texto sin traducir')) ?></p>\n",
        'lang/es.php' => "<?php\n\ndeclare(strict_types=1);\n\nreturn ['Texto sin traducir' => 'Texto traducido'];\n",
    ]);
    same(true, themesCheck(themesReport($root, 'traducido'), 'i18n')['ok']);
    // Una etiqueta nueva de theme.json también cuenta.
    themesChild($root, 'etiqueta', [], ['settings' => ['extra' => ['label' => 'Grupo nuevo', 'fields' => ['x' => ['type' => 'text', 'label' => 'Campo nuevo']]]]]);
    $label = themesCheck(themesReport($root, 'etiqueta'), 'i18n');
    same(false, $label['ok']);
});

themesTest('texto gris claro sobre blanco es un aviso de contraste (no bloquea); un acento sin contraste también', function (): void {
    $root = themesRoot();
    themesChild($root, 'gris', [], ['settings' => ['colors' => ['fields' => [
        'ink' => ['default' => '#cccccc'], 'bg' => ['default' => '#ffffff'], 'accent' => ['default' => '#ffee00'], 'accent_ink' => ['default' => '#000000'], 'muted' => ['default' => '#dddddd'],
    ]]]]);
    $report = themesReport($root, 'gris');
    same(true, $report['ok'], 'un aviso no bloquea la activación');
    same(['contrast:accent', 'contrast:old-price', 'contrast:text'], themesFailed($report, 'warning'));
    $text = themesCheck($report, 'contrast:text');
    same('warning', $text['level']);
    contains('1,6', str_replace('.', ',', $text['message']), 'dice el contraste que sale');
    same(true, themesCheck($report, 'contrast:accent-text')['ok'], 'texto negro sobre amarillo sí llega');
});

themesTest('un padre que no existe, un ciclo y un theme.json sin nombre dan error', function (): void {
    $root = themesRoot();
    themesChild($root, 'huerfano', [], ['parent' => 'fantasma']);
    themesChild($root, 'ciclo-a', [], ['parent' => 'ciclo-b']);
    themesChild($root, 'ciclo-b', [], ['parent' => 'ciclo-a']);
    themesChild($root, 'sin-nombre', [], ['name' => '']);
    $orphan = themesReport($root, 'huerfano');
    same(false, $orphan['ok']);
    $check = themesCheck($orphan, 'parent');
    same(false, $check['ok']);
    contains('fantasma', $check['message']);
    $cycle = themesReport($root, 'ciclo-a');
    same(false, themesCheck($cycle, 'parent')['ok']);
    $nameless = themesReport($root, 'sin-nombre');
    same(['manifest'], themesFailed($nameless));
    same(1, count($nameless['checks']), 'sin un theme.json válido no se sigue');
    $unknown = themesReport($root, 'no-existe');
    same(['manifest'], themesFailed($unknown));
    same(false, $unknown['ok']);
});

themesTest('la ficha que pone la compra antes que la galería da error; con la galería primero pasa', function (): void {
    $root = themesRoot();
    $wrong = "<?php\n?>\n<div class=\"af-product\"><div class=\"af-product-buy\"></div><div class=\"af-product-gallery\"></div></div>\n";
    $right = "<?php\n?>\n<div class=\"af-product\"><div class=\"af-product-gallery\"></div><div class=\"af-product-buy\"></div></div>\n";
    themesChild($root, 'compra-primero', ['templates/modules/catalog/front/product.php' => $wrong]);
    themesChild($root, 'galeria-primero', ['templates/modules/catalog/front/product.php' => $right]);
    themesChild($root, 'sin-galeria', ['templates/modules/catalog/front/product.php' => "<?php\n?>\n<div class=\"af-product-buy\"></div>\n"]);
    $bad = themesReport($root, 'compra-primero');
    same(false, $bad['ok']);
    $check = themesCheck($bad, 'gallery');
    same('error', $check['level']);
    same('templates/modules/catalog/front/product.php', $check['file']);
    same(true, themesCheck(themesReport($root, 'galeria-primero'), 'gallery')['ok']);
    same(false, themesCheck(themesReport($root, 'sin-galeria'), 'gallery')['ok']);
    check(themesCheck(themesReport($root, 'base'), 'gallery') === null, 'un tema que no sustituye la ficha no se comprueba');
});

themesTest('una opción con variable que ninguna hoja usa es un aviso', function (): void {
    $root = themesRoot();
    $field = ['settings' => ['extra' => ['label' => 'Extra', 'fields' => ['halo' => ['type' => 'color', 'label' => 'Halo', 'default' => '#336699', 'var' => '--halo']]]]];
    $lang = "<?php\n\ndeclare(strict_types=1);\n\nreturn ['Extra' => 'Extra', 'Halo' => 'Halo'];\n";
    themesChild($root, 'sin-usar', ['lang/es.php' => $lang], $field);
    themesChild($root, 'usada', ['lang/es.php' => $lang, 'assets/css/theme.css' => ".af-btn{outline-color:var(--halo)}\n"], $field);
    $unused = themesReport($root, 'sin-usar');
    same(true, $unused['ok']);
    $check = themesCheck($unused, 'option-vars');
    same(false, $check['ok']);
    same('warning', $check['level']);
    contains('halo', $check['message']);
    same(true, themesCheck(themesReport($root, 'usada'), 'option-vars')['ok']);
});

themesTest('las comprobaciones en vivo cazan lo que los ficheros no dicen: una cabecera sin canónica, un enlace vacío y una página de error rota', function (): void {
    $root = themesRoot();
    $layout = themesBaseLayout($root);
    $lang = (string) file_get_contents($root . '/themes/base/lang/es.php');
    themesChild($root, 'sin-canonica', ['lang/es.php' => $lang, 'templates/layout.php' => str_replace('<?= page_head($af_page) ?>', '<?php /* page_head($af_page) */ ?>', $layout)]);
    themesChild($root, 'enlace-vacio', ['templates/home.php' => "<?php\n\$this->layout('layout', ['page' => []]);\n?>\n<a href=\"\">nada</a>\n"]);
    themesChild($root, 'error-roto', ['templates/error.php' => "<?php\nthrow new \\RuntimeException('error roto');\n"]);
    $head = themesReport($root, 'sin-canonica', true);
    same(true, themesCheck($head, 'wrapper-head')['ok'], 'por ficheros parece bien');
    same(false, themesCheck($head, 'live-head')['ok'], 'en vivo se ve que no hay canónica');
    same(false, $head['ok']);
    $link = themesReport($root, 'enlace-vacio', true);
    same(false, themesCheck($link, 'live-links')['ok']);
    $error = themesReport($root, 'error-roto', true);
    same(false, themesCheck($error, 'live-error')['ok']);
    same(false, $error['ok']);
    // Sin las comprobaciones en vivo, nada de esto se ve.
    same([], themesFailed(themesReport($root, 'enlace-vacio', false)));
});

themesTest('un functions.php con funciones sin guardia no se carga dos veces: se omiten las comprobaciones en vivo con un aviso', function (): void {
    $root = themesRoot();
    themesChild($root, 'con-funciones', ['functions.php' => "<?php\n\nfunction funcion_de_un_tema_de_prueba(): string\n{\n    return 'hola';\n}\n\nreturn static function (\$app): void {\n};\n"]);
    $report = themesReport($root, 'con-funciones', true);
    same(true, $report['ok'], 'no hay un fatal ni un error: solo un aviso');
    $check = themesCheck($report, 'live-skipped');
    same('warning', $check['level']);
    same(false, $check['ok']);
    check(themesCheck($report, 'live-home') === null, 'no se ha pedido ninguna página');
    // Con la guardia sí se carga las veces que haga falta.
    themesChild($root, 'con-guardia', ['functions.php' => "<?php\n\nif (!function_exists('funcion_guardada_de_prueba')) {\n    function funcion_guardada_de_prueba(): string\n    {\n        return 'hola';\n    }\n}\n\nreturn static function (\$app): void {\n};\n"]);
    $guarded = themesReport($root, 'con-guardia', true);
    check(themesCheck($guarded, 'live-skipped') === null);
    same(true, themesCheck($guarded, 'live-home')['ok']);
});

themesTest('el validador no toca la aplicación en curso ni deja su tema cambiado, aunque la comprobación en vivo falle', function (): void {
    $root = themesRoot();
    themesChild($root, 'portada-rota', ['templates/home.php' => "<?php\nthrow new \\RuntimeException('rota');\n"]);
    $app = themesApp($root);
    $themes = themesSvc($app);
    $report = $themes->validate('portada-rota', true);
    same(false, $report['ok']);
    same($app, App::current());
    $page = (new TestClient($app))->get('/');
    same(200, $page->status, 'la aplicación sigue con su tema');
    same('base', $app->theme()['slug']);
});
