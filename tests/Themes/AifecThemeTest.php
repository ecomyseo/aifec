<?php
/**
 * AIFEC — pruebas del tema aifec (el sitio de AIFEC) y del paquete de contenido demos/aifec-sitio: la portada hecha de bloques,
 * su hoja propia (sin la del padre), la cabecera sobria, la barra del móvil, el pie con los principios, las opciones de
 * estructura, las comprobaciones estáticas (peso, direcciones externas, colores, contrato de estilos), el contraste AA calculado
 * y que el paquete se valida y la página larga convertida dice lo mismo que el fichero de bloques de WordPress.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Cms\Blocks\GutenbergParser;
use Aifec\Kernel\App;
use Aifec\Modules\Sites\Package;

require_once dirname(__DIR__, 2) . '/modules/sites/tests/helpers.php';

/** Carpeta del tema aifec. */
function aifecThemeDir(): string
{
    return rtrim(str_replace('\\', '/', Autoload::root()), '/') . '/themes/aifec';
}

/** Carpeta del paquete del sitio. */
function aifecSiteDir(): string
{
    return rtrim(str_replace('\\', '/', Autoload::root()), '/') . '/demos/aifec-sitio';
}

/**
 * Todos los ficheros de una carpeta del tema (recursivo).
 *
 * @return string[]
 */
function aifecThemeFiles(string $sub): array
{
    $files = [];
    $dir = aifecThemeDir() . '/' . $sub;
    if (!is_dir($dir)) {
        return $files;
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }
    sort($files);

    return $files;
}

/** El CSS sin los bloques :root (donde sí se declaran los colores de fábrica). */
function aifecThemeCssWithoutRoot(string $css): string
{
    return (string) preg_replace('/:where\(:root\)\s*\{[^}]*\}|:root\s*\{[^}]*\}/', '', $css);
}

/**
 * Sitio de prueba con el tema aifec activo.
 *
 * @param array<string,mixed> $options opciones del tema que cambia el comerciante
 * @param string[]            $modules
 */
function aifecThemeApp(array $options = [], array $modules = ['cms', 'docs']): App
{
    $settings = ['theme.active' => 'aifec', 'modules.active' => $modules];
    if ($options !== []) {
        $settings['theme.options'] = ['aifec' => $options];
    }
    $app = testApp(['modules' => $modules, 'settings' => $settings]);
    $app->boot();

    return $app;
}

/** Una ruta con nombre que no hace nada, como la registraría un módulo de comercio o el de demos. */
function aifecThemeRoute(App $app, string $name, string $path): void
{
    if (!$app->router()->has($name)) {
        $app->router()->get($path, static function (): string {
            return '';
        })->name($name);
    }
}

/**
 * El sitio de AIFEC construido de verdad desde su paquete (una vez por pasada), dentro del sitio maestro de las pruebas.
 *
 * @return array<string,mixed>
 */
function aifecThemeWorld(): array
{
    static $world = null;
    if ($world === null) {
        $world = aifecSitesFresh();
        $created = $world['demos']->create(['package' => 'aifec-sitio', 'slug' => 'aifec']);
        check(!empty($created['ok']), 'el paquete aifec-sitio se construye: ' . (isset($created['error']) ? (string) $created['error'] : ''));
    }

    return $world;
}

/** Luminancia relativa (WCAG) de un color #rrggbb. */
function aifecThemeLuminance(string $hex): float
{
    $hex = ltrim($hex, '#');
    $channels = [];
    foreach ([0, 2, 4] as $at) {
        $c = hexdec(substr($hex, $at, 2)) / 255;
        $channels[] = $c <= 0.03928 ? $c / 12.92 : pow(($c + 0.055) / 1.055, 2.4);
    }

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

/** Contraste WCAG entre dos colores #rrggbb. */
function aifecThemeContrast(string $a, string $b): float
{
    $la = aifecThemeLuminance($a);
    $lb = aifecThemeLuminance($b);

    return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
}

/**
 * Los colores #rrggbb de las variables del primer bloque :root de la hoja.
 *
 * @return array<string,string>
 */
function aifecThemeRootColors(): array
{
    $css = (string) file_get_contents(aifecThemeDir() . '/assets/css/theme.css');
    preg_match('/:where\(:root\)\s*\{([^}]*)\}/', $css, $block);
    $vars = [];
    preg_match_all('/(--[a-z0-9-]+):(#[0-9a-fA-F]{6})\b/', isset($block[1]) ? $block[1] : '', $m, PREG_SET_ORDER);
    foreach ($m as $hit) {
        $vars[$hit[1]] = strtolower($hit[2]);
    }

    return $vars;
}

/**
 * Cuenta los bloques de una lista, los anidados incluidos.
 *
 * @param array<int,array<string,mixed>> $blocks
 */
function aifecThemeCountBlocks(array $blocks): int
{
    $n = 0;
    foreach ($blocks as $block) {
        ++$n;
        if (isset($block['children']) && is_array($block['children'])) {
            $n += aifecThemeCountBlocks($block['children']);
        }
    }

    return $n;
}

/**
 * Los bloques de tipo «html» de una lista (los anidados incluidos).
 *
 * @param array<int,array<string,mixed>> $blocks
 *
 * @return string[] el HTML de cada uno
 */
function aifecThemeHtmlBlocks(array $blocks): array
{
    $found = [];
    foreach ($blocks as $block) {
        if (isset($block['type']) && $block['type'] === 'html') {
            $found[] = isset($block['attrs']['html']) ? (string) $block['attrs']['html'] : '';
        }
        if (isset($block['children']) && is_array($block['children'])) {
            $found = array_merge($found, aifecThemeHtmlBlocks($block['children']));
        }
    }

    return $found;
}

test('la portada del sitio construido responde 200 con su hoja y sin la del padre, y un solo h1', function (): void {
    $w = aifecThemeWorld();
    $r = aifecSitesFront($w, '/demos/aifec/');
    same(200, $r->status);
    $body = $r->body;
    contains('<!doctype html>', $body);
    contains('<main id="main"', $body);
    contains('/t/aifec/', $body);
    same(1, substr_count($body, '/css/theme.css'), 'una sola hoja de estilos del tema');
    check(preg_match('#/t/aifec/[^"]*/css/theme\.css#', $body) === 1, 'la hoja es la del tema aifec');
    notContains('/t/base/', preg_replace('#/t/base/[^"]*/js/theme\.js#', '', $body), 'ninguna hoja del tema base (el guion sí se hereda)');
    contains('/js/theme.js" defer', $body, 'el guion del tema base se hereda');
    same(1, substr_count($body, '<h1'), 'un solo h1 en la portada');
    contains('<link rel="canonical"', $body);
    contains('<title>AIFEC', $body);
});

test('la portada es una página editable hecha de bloques: cinco bandas, titular con cursiva, registro de una compra, siete puntos, importación y desarrolladores', function (): void {
    $body = aifecSitesFront(aifecThemeWorld(), '/demos/aifec/')->body;
    same(5, substr_count($body, 'af-b af-b-group'), 'cinco bandas');
    same(3, substr_count($body, 'af-b-group af-wide'), 'tres bandas blancas');
    same(2, substr_count($body, 'af-b-group af-full'), 'dos bandas de fondo suave');
    contains('<em>Tú solo decides</em>', $body, 'la frase clave del titular en cursiva');
    contains('<em>ya funcionan</em>', $body);
    contains('id="pruebalo"', $body);
    contains('id="distingue"', $body);
    contains('id="importar"', $body);
    contains('id="desarrolladores"', $body);
    $list = substr($body, (int) strpos($body, 'af-b-list'));
    $list = substr($list, 0, (int) strpos($list, '</ol>'));
    same(7, substr_count($list, '<li>'), 'siete puntos en la lista numerada');
    contains('af-btn af-btn-fill', $body);
    contains('af-btn af-btn-outline', $body);
    contains('href="/demos"', $body);
    contains('pedido 2026-000043', $body, 'el registro de la compra hecha por un agente');
    contains('af-home-bands', $body, 'las bandas van sin contenedor');
});

test('las demás páginas del paquete responden 200 con su contenido: la larga, las demos, desarrolladores, contacto y legales', function (): void {
    $w = aifecThemeWorld();
    $long = aifecSitesFront($w, '/demos/aifec/que-es-aifec');
    same(200, $long->status);
    contains('<h1 class="af-title">Qué es AIFEC</h1>', $long->body);
    check(substr_count($long->body, '<h2') >= 5, 'la página larga trae sus secciones');
    $demos = aifecSitesFront($w, '/demos/aifec/sobre-las-demos');
    same(200, $demos->status);
    contains('Qué no pasa nunca', $demos->body);
    $dev = aifecSitesFront($w, '/demos/aifec/para-desarrolladores');
    same(200, $dev->status);
    contains('href="/docs"', $dev->body, 'enlaza a la documentación');
    $contact = aifecSitesFront($w, '/demos/aifec/contacto');
    same(200, $contact->status);
    contains('<form', $contact->body, 'el formulario de contacto');
    same(200, aifecSitesFront($w, '/demos/aifec/aviso-legal')->status);
    same(200, aifecSitesFront($w, '/demos/aifec/privacidad')->status);
});

test('el sitio construido lleva los tres menús, la cabecera sobria sin carrito, la barra del móvil y el pie con los principios', function (): void {
    $body = aifecSitesFront(aifecThemeWorld(), '/demos/aifec/')->body;
    contains('<header class="af-header af-header-bar"', $body);
    contains('>Qué es AIFEC</a>', $body);
    contains('>Para desarrolladores</a>', $body);
    contains('>Aviso legal</a>', $body);
    contains('class="af-footer af-footer-columns"', $body);
    notContains('data-af-cart-count', $body, 'un sitio de producto no pinta el carrito aunque el módulo de pedidos esté encendido');
    notContains('id="af-cart"', $body);
    notContains('role="search"', $body);
    contains('<nav class="af-mobile-bar af-show-sm"', $body);
    contains('af-has-bar', $body);
    contains('Pie de página', $body);
    contains('Una sola verdad', substr($body, (int) strpos($body, 'af-principles')));
    notContains('href=""', $body);
});

test('una dirección que no existe da 404 con la página de error dentro del envoltorio del tema', function (): void {
    $r = aifecSitesFront(aifecThemeWorld(), '/demos/aifec/no-existe-esta-pagina');
    same(404, $r->status);
    contains('<header class="af-header', $r->body);
    contains('/t/aifec/', $r->body);
    check(substr_count($r->body, '<h1') === 1, 'un solo h1');
});

test('sin ningún módulo de pedidos ni de demos la cabecera funciona y no enlaza a nada que no exista; la barra del móvil lleva el menú', function (): void {
    $bare = (new TestClient(aifecThemeApp([], ['cms'])))->get('/')->body;
    contains('class="af-logo"', $bare);
    notContains('data-af-cart-count', $bare);
    notContains('role="search"', $bare);
    notContains('af-header-cta', $bare, 'sin la ruta de demos no hay botón');
    contains('<nav class="af-mobile-bar af-show-sm"', $bare);
    contains('data-af-drawer-open="af-menu"', $bare);
    contains('id="af-menu"', $bare);
    same(1, substr_count(substr($bare, (int) strpos($bare, '<nav class="af-mobile-bar'), 3000), 'af-mobile-bar-item'), 'solo el menú');
    notContains('href=""', $bare);
});

test('la barra del móvil trae solo accesos reales: menú, demos, documentación y buscar; el carrito solo si el tema lo muestra', function (): void {
    $app = aifecThemeApp();
    $body = (new TestClient($app))->get('/')->body;
    $bar = substr($body, (int) strpos($body, '<nav class="af-mobile-bar'));
    $bar = substr($bar, 0, (int) strpos($bar, '</nav>'));
    same(3, substr_count($bar, 'af-mobile-bar-item'), 'menú, documentación y buscar en la documentación');
    contains('href="/docs"', $bar);
    contains('href="/docs/buscar"', $bar);
    notContains('Carrito', $bar);

    $app = aifecThemeApp();
    aifecThemeRoute($app, 'demos', '/demos');
    aifecThemeRoute($app, 'cart', '/carrito');
    aifecThemeRoute($app, 'search', '/buscar');
    $site = (new TestClient($app))->get('/')->body;
    $bar = substr($site, (int) strpos($site, '<nav class="af-mobile-bar'));
    $bar = substr($bar, 0, (int) strpos($bar, '</nav>'));
    contains('href="/demos"', $bar, 'con la ruta de demos sale el acceso');
    notContains('data-af-cart-count', $site, 'las rutas de tienda existen, pero el tema no las muestra por defecto');
    notContains('af-q-header', $site);

    $app = aifecThemeApp(['shop_links' => 1]);
    aifecThemeRoute($app, 'cart', '/carrito');
    aifecThemeRoute($app, 'search', '/buscar');
    $shop = (new TestClient($app))->get('/')->body;
    $bar = substr($shop, (int) strpos($shop, '<nav class="af-mobile-bar'));
    $bar = substr($bar, 0, (int) strpos($bar, '</nav>'));
    contains('data-af-drawer-open="af-cart"', $bar, 'encendido el acceso de tienda y con su ruta, sale el carrito');
    contains('data-af-drawer-open="af-search"', $bar);
    contains('data-af-cart-count', $bar, 'con su contador');
    contains('id="af-cart"', $shop);
    contains('role="search"', $shop);
    notContains('href="/docs/buscar"', $bar, 'con el buscador de la tienda no hace falta el de la documentación');

    $app = aifecThemeApp(['shop_links' => 1]);
    $none = (new TestClient($app))->get('/')->body;
    notContains('data-af-cart-count', $none, 'encendido pero sin ruta: ningún enlace roto');
    notContains('id="af-cart"', $none);
});

test('el botón de la cabecera sale solo con una dirección que existe, admite la suya y no deja pasar una peligrosa', function (): void {
    $app = aifecThemeApp([], ['cms']);
    aifecThemeRoute($app, 'demos', '/demos');
    $default = (new TestClient($app))->get('/')->body;
    contains('<div class="af-header-cta">', $default);
    contains('href="/demos">Ver demos</a>', $default);

    $custom = (new TestClient(aifecThemeApp(['header_cta_text' => 'Habla con nosotros & más', 'header_cta_link' => '/contacto'], ['cms'])))->get('/')->body;
    contains('href="/contacto">Habla con nosotros &amp; más</a>', $custom);

    foreach (['javascript:alert(1)', '//evil.test/x', 'ftp://x.test', '/a b', '/<script>'] as $bad) {
        $page = (new TestClient(aifecThemeApp(['header_cta_link' => $bad], ['cms'])))->get('/')->body;
        notContains('af-header-cta', $page, 'el enlace «' . $bad . '» se ignora y, sin ruta de demos, no hay botón');
        notContains($bad, $page);
    }
    $https = (new TestClient(aifecThemeApp(['header_cta_link' => 'https://ejemplo.test/x'], ['cms'])))->get('/')->body;
    contains('href="https://ejemplo.test/x">Ver demos</a>', $https);

    $app = aifecThemeApp(['header_cta' => 0], ['cms']);
    aifecThemeRoute($app, 'demos', '/demos');
    notContains('af-header-cta', (new TestClient($app))->get('/')->body, 'apagado, no sale');
});

test('las opciones de estructura llegan al HTML: cabecera centrada, pie compacto y sin la línea de principios', function (): void {
    $default = (new TestClient(aifecThemeApp([], ['cms'])))->get('/')->body;
    contains('af-header af-header-bar', $default);
    contains('af-footer af-footer-columns', $default);
    contains('class="af-principles"', $default);
    contains('class="af-footer-grid"', $default);

    $centered = (new TestClient(aifecThemeApp(['header_style' => 'centered'], ['cms'])))->get('/')->body;
    contains('af-header af-header-centered', $centered);
    notContains('af-header-bar', $centered);

    $compact = (new TestClient(aifecThemeApp(['footer_style' => 'compact'], ['cms'])))->get('/')->body;
    contains('af-footer af-footer-compact', $compact);
    notContains('af-footer-grid', $compact);
    contains('class="af-principles"', $compact, 'compacto conserva la línea de principios');

    $plain = (new TestClient(aifecThemeApp(['footer_principles' => 0], ['cms'])))->get('/')->body;
    notContains('af-principles', $plain);

    $invalid = (new TestClient(aifecThemeApp(['header_style' => '<x>', 'footer_style' => 'otro'], ['cms'])))->get('/')->body;
    contains('af-header af-header-bar', $invalid, 'un valor que no es una opción vuelve al de fábrica');
});

test('el pie enseña los seis principios en una línea, traducidos, y el acento cambia la variable del <head>', function (): void {
    $body = (new TestClient(aifecThemeApp(['accent' => '#112233'], ['cms'])))->get('/')->body;
    $line = substr($body, (int) strpos($body, 'class="af-principles"'));
    $line = substr($line, 0, (int) strpos($line, '</ul>'));
    same(6, substr_count($line, '<li>'));
    foreach (['Una sola verdad', 'Datos antes que textos', 'Puertas para las máquinas', 'Páginas ligeras', 'IA dentro, una persona al mando', 'Soberanía'] as $principle) {
        contains($principle, $line);
    }
    contains('--accent:#112233', $body);
    $invalid = (new TestClient(aifecThemeApp(['accent' => 'naranja'], ['cms'])))->get('/')->body;
    notContains('--accent:naranja', $invalid);
});

test('peso: theme.css pesa como mucho 45 000 bytes y el guion propio, solo el del desplegable, 25 000 como mucho', function (): void {
    $css = aifecThemeDir() . '/assets/css/theme.css';
    check(is_file($css), 'existe la hoja');
    check(filesize($css) <= 45000, 'theme.css pesa ' . filesize($css));
    $js = aifecThemeDir() . '/assets/js/theme.js';
    check(is_file($js) && filesize($js) <= 25000, 'el guion propio existe y pesa poco');
});

test('ninguna dirección externa en las plantillas, el código ni los recursos del tema, ningún @import y toda imagen con medidas', function (): void {
    $files = array_merge(aifecThemeFiles('templates'), aifecThemeFiles('assets'), [aifecThemeDir() . '/functions.php', aifecThemeDir() . '/theme.json']);
    check(count($files) >= 7, 'se han encontrado las plantillas y los recursos');
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        same(0, preg_match('#https?://(?!www\.w3\.org)#i', $text), basename($file) . ' lleva una dirección externa');
        notContains('@import', $text, basename($file));
        notContains('//cdn', $text, basename($file));
        check(preg_match('/<img\b(?![^>]*\bwidth=)/i', $text) === 0, basename($file) . ' tiene una imagen sin width');
    }
    foreach (glob(aifecSiteDir() . '/media/*.svg') ?: [] as $svg) {
        same(0, preg_match('#https?://(?!www\.w3\.org)#i', (string) file_get_contents($svg)), basename($svg) . ' lleva una dirección externa');
    }
});

test('theme.css: ningún color fuera del bloque :root, y sin fuentes ni iconos de fuera', function (): void {
    $css = (string) file_get_contents(aifecThemeDir() . '/assets/css/theme.css');
    $rest = aifecThemeCssWithoutRoot($css);
    $found = [];
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $rest, $found);
    same([], $found[0], 'colores fijos fuera de :root');
    check(preg_match('/\b(?:rgb|rgba|hsl|hsla)\(/', $rest) === 0, 'colores funcionales fuera de :root');
    notContains('@font-face', $css);
    notContains('url(', $css);
    notContains('!important', preg_replace('/display:none!important|position:absolute!important|animation[^;}]*!important|transition-duration[^;}]*!important|scroll-behavior[^;}]*!important|\.af-hide-sm[^}]*|\.af-show-sm[^}]*/', '', $rest), 'sin !important salvo los de utilidad');
});

test('theme.json es válido: tema hijo de base con hoja propia, tres opciones de estructura como mínimo y cada opción con var en :root', function (): void {
    $json = json_decode((string) file_get_contents(aifecThemeDir() . '/theme.json'), true);
    check(is_array($json), 'JSON válido');
    same('AIFEC', $json['name']);
    same('base', $json['parent']);
    same('replace', $json['css'], 'la hoja del padre no se carga');
    same(['main', 'footer', 'legal'], array_keys($json['menus']));
    same(160, $json['image_sizes']['thumb'][0]);
    check(isset($json['font_stacks']['serif']) && strpos($json['font_stacks']['serif'], "'Iowan Old Style'") === 0, 'la pila serif del tema');
    $css = (string) file_get_contents(aifecThemeDir() . '/assets/css/theme.css');
    preg_match_all('/(?::where\(:root\)|:root)\s*\{[^}]*\}/', $css, $blocks);
    $root = implode("\n", $blocks[0]);
    $vars = [];
    $plain = [];
    foreach ($json['settings'] as $group) {
        foreach ($group['fields'] as $key => $field) {
            if (isset($field['var'])) {
                $vars[$key] = $field['var'];
                contains($field['var'] . ':', $root, 'la opción «' . $key . '» no está en :root');
            } else {
                $plain[] = $key;
            }
        }
    }
    foreach (['--bg', '--surface', '--ink', '--muted', '--line', '--accent', '--accent-ink', '--sale', '--ok', '--warn', '--bad', '--font', '--font-head', '--radius', '--container'] as $var) {
        check(in_array($var, $vars, true), 'falta la opción para ' . $var);
    }
    check(count(array_intersect(['header_style', 'header_cta', 'footer_style', 'footer_principles', 'shop_links'], $plain)) >= 3, 'al menos tres opciones de estructura');
    foreach (['header_style', 'footer_style'] as $select) {
        check(count($json['settings']['structure']['fields'][$select]['options']) >= 2, $select . ' ofrece al menos dos disposiciones');
    }
});

test('todas las plantillas propias existen y empiezan por la cabecera del proyecto', function (): void {
    foreach (['layout', 'home', 'partials/header', 'partials/footer', 'partials/mobile-bar', 'partials/drawers'] as $name) {
        $file = aifecThemeDir() . '/templates/' . $name . '.php';
        check(is_file($file), 'falta templates/' . $name . '.php');
        $head = (string) file_get_contents($file, false, null, 0, 900);
        check(strpos($head, "<?php\n/**\n * AIFEC") === 0, $name . ' no empieza por la cabecera del proyecto');
        contains('@author    Ecom Experts <ecomyseo@gmail.com>', $head);
    }
    foreach (['functions.php', 'lang/es.php', 'assets/css/theme.css', 'theme.json'] as $extra) {
        check(is_file(aifecThemeDir() . '/' . $extra), 'falta ' . $extra);
    }
});

test('theme.css cumple el contrato de estilos y el de móvil, y da estilo a los bloques y a las piezas propias', function (): void {
    $css = (string) file_get_contents(aifecThemeDir() . '/assets/css/theme.css');
    $classes = [
        'af-container', 'af-section', 'af-grid', 'af-cols-2', 'af-cols-3', 'af-cols-4', 'af-cols-5', 'af-cols-6', 'af-stack', 'af-row', 'af-split', 'af-sr',
        'af-hide-sm', 'af-show-sm', 'af-title', 'af-subtitle', 'af-muted', 'af-small', 'af-prose', 'af-btn', 'af-btn-primary', 'af-btn-ghost', 'af-btn-block',
        'af-btn-sm', 'af-btn-lg', 'af-input', 'af-select', 'af-textarea', 'af-field', 'af-hint', 'af-error', 'af-check', 'af-radio-card', 'af-qty', 'af-alert',
        'af-alert-ok', 'af-alert-bad', 'af-alert-info', 'af-alert-warn', 'af-badge', 'af-badge-sale', 'af-badge-new', 'af-badge-out', 'af-stars',
        'af-product-card', 'af-price', 'af-price-now', 'af-price-old', 'af-price-off', 'af-price-note', 'af-listing', 'af-listing-filters', 'af-listing-results',
        'af-filter', 'af-product', 'af-product-gallery', 'af-product-buy', 'af-gallery', 'af-gallery-main', 'af-gallery-thumbs', 'af-variants', 'af-variant',
        'af-availability', 'is-ok', 'is-low', 'is-out', 'af-facts', 'af-tabs', 'af-cart', 'af-cart-line', 'af-totals', 'af-totals-row', 'af-totals-total',
        'af-steps', 'af-summary', 'af-table', 'af-card', 'af-empty', 'af-mobile-bar', 'af-drawer', 'af-drawer-backdrop',
        // lo propio del sitio: los bloques del gestor de contenidos, las bandas de la portada y las piezas del tema
        'af-b-columns', 'af-b-column', 'af-b-buttons', 'af-b-table', 'af-b-list', 'af-b-group', 'af-btn-fill', 'af-btn-outline', 'af-kicker', 'af-principles',
        'af-header-cta', 'af-logo', 'af-home-section', 'af-full',
    ];
    foreach ($classes as $class) {
        check(preg_match('/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/', $css) === 1, 'theme.css no da estilo a .' . $class);
    }
    foreach (['.af-nav-toggle', '.af-cats', '.af-cat-name'] as $piece) {
        contains($piece, $css, 'sin estilo para ' . $piece);
    }
    foreach (['position:sticky', 'env(safe-area-inset-bottom)', 'min-height:44px', 'prefers-reduced-motion', ':focus-visible', 'object-fit:contain', 'line-through',
        'min-width:44px', 'flex-wrap:wrap', 'overflow-x:auto', 'font-style:italic'] as $needle) {
        contains($needle, $css, 'theme.css no contiene «' . $needle . '»');
    }
    check(preg_match('/\.af-header\{[^}]*position:sticky/', $css) === 1, 'la cabecera es pegajosa');
    check(preg_match('/\.af-mobile-bar\{[^}]*position:fixed[^}]*bottom:0/', $css) === 1, 'la barra inferior es fija abajo');
    check(preg_match('/\.af-has-bar\{[^}]*padding-bottom:calc\(var\(--bar-h\) \+ env\(safe-area-inset-bottom\)\)/', $css) === 1, 'el contenido reserva el alto de la barra');
    check(preg_match('/\.af-b-table\{[^}]*overflow-x:auto/', $css) === 1, 'la tabla de la comparativa se desplaza dentro de su caja en móvil');
    check(preg_match('/h1 em,h2 em\{color:var\(--accent\)\}/', $css) === 1, 'la palabra clave de los titulares en cursiva con el color de acento');
    check(preg_match('/\.af-kicker[^{]*\{[^}]*font:[^}]*var\(--font\)/', $css) === 1, 'los rótulos van en la tipografía del texto');
    check(preg_match('/\.af-kicker[^{]*\{[^}]*(?:var\(--mono\)|text-transform:uppercase)/', $css) === 0, 'y no en monoespaciada ni en mayúsculas');
    foreach (['.af-demos-home', '.af-demos-list', '.af-demo-blank'] as $piece) {
        contains($piece, $css, 'sin estilo para ' . $piece);
    }
    check(preg_match('/\[data-section="cms\.home"\] \.af-b-group\.af-wide\{[^}]*background/', $css) === 0, 'el titular de portada va sobre el fondo blanco de la página, sin banda oscura');
});

test('contraste AA calculado: tinta sobre blanco y sobre tarjeta, blanco sobre verde, y los textos de acento y de las bandas oscuras', function (): void {
    $c = aifecThemeRootColors();
    foreach (['--bg', '--surface', '--ink', '--muted', '--accent', '--accent-ink', '--accent-text', '--on-dark', '--accent-on-dark', '--sale', '--ok', '--warn'] as $var) {
        check(isset($c[$var]), 'falta ' . $var . ' en :root');
    }
    same('#ffffff', $c['--bg'], 'la página es blanca');
    same('#0d3b2a', $c['--ink'], 'tinta verde oscuro');
    same('#0b7a43', $c['--accent'], 'verde');
    same('#b9cbbf', $c['--sand'], 'el verde claro de los filetes');
    $aa = static function (string $fg, string $bg, float $min, string $what) use ($c): void {
        $ratio = aifecThemeContrast($c[$fg], $c[$bg]);
        check($ratio >= $min, $what . ': ' . round($ratio, 2) . ' (hace falta ' . $min . ')');
    };
    $aa('--ink', '--bg', 4.5, 'tinta sobre papel');
    $aa('--ink', '--surface', 4.5, 'tinta sobre tarjeta');
    $aa('--ink', '--accent-soft', 4.5, 'tinta sobre el fondo suave del acento');
    $aa('--accent-ink', '--accent', 4.5, 'blanco sobre verde (botones y celda destacada)');
    $aa('--muted', '--bg', 4.5, 'texto secundario sobre papel');
    $aa('--accent-text', '--bg', 4.5, 'enlaces y rótulos verde oscuro sobre blanco');
    $aa('--accent-text', '--surface', 4.5, 'rótulos verde oscuro sobre tarjeta');
    $aa('--on-dark', '--ink', 4.5, 'papel sobre las bandas de tinta');
    $aa('--accent-on-dark', '--ink', 4.5, 'verde claro sobre las bandas de tinta');
    $aa('--sale', '--bg', 4.5, 'precio de oferta sobre papel');
    $aa('--ok', '--bg', 4.5, 'texto de éxito sobre papel');
    $aa('--warn', '--bg', 4.5, 'texto de aviso sobre papel');
    $aa('--accent', '--bg', 3.0, 'el verde de acento en titulares grandes y bordes (texto grande: 3)');
    // El valor de fábrica de cada color del tema.json es el de :root.
    $json = json_decode((string) file_get_contents(aifecThemeDir() . '/theme.json'), true);
    foreach ($json['settings']['colors']['fields'] as $key => $field) {
        same($c[$field['var']], strtolower($field['default']), 'el valor de fábrica de «' . $key . '» coincide con :root');
    }
});

test('el contraste se calcula bien: negro sobre blanco 21 y el mismo color 1', function (): void {
    near(21.0, aifecThemeContrast('#000000', '#ffffff'), 0.001);
    near(1.0, aifecThemeContrast('#c4511d', '#c4511d'), 0.001);
    check(aifecThemeContrast('#767676', '#ffffff') >= 4.5 && aifecThemeContrast('#777777', '#ffffff') < 4.5, 'el umbral clásico de AA está en #767676');
});

test('el paquete aifec-sitio se valida con el esquema: tema aifec, español, páginas y menús, sin catálogo', function (): void {
    $pkg = Package::load(Autoload::root() . '/demos', 'aifec-sitio', Autoload::root());
    same('aifec-sitio', $pkg->name());
    same('es', $pkg->locale());
    same('aifec', $pkg->meta()['theme']);
    same('AIFEC', $pkg->meta()['name']);
    same(11, count($pkg->data('pages.json')), 'siete páginas, dos legales más (cookies y condiciones de compra), «Precio y licencia» y las predicciones');
    same(3, count($pkg->data('menus.json')));
    same(['main', 'footer', 'legal'], array_column($pkg->data('menus.json'), 'code'));
    check(!$pkg->has('products.json') && !$pkg->has('categories.json') && !$pkg->has('carriers.json'), 'sin catálogo');
    $modules = $pkg->modules(Autoload::root());
    foreach (['cms', 'media', 'seo', 'semantic', 'docs'] as $slug) {
        check(in_array($slug, $modules, true), 'el paquete enciende ' . $slug);
    }
    check(!in_array('catalog', $modules, true) && !in_array('sites', $modules, true), 'ni catálogo ni el módulo de sitios');
    check($pkg->mediaPath('esquema.svg') !== null, 'el esquema de la portada existe');
    $home = array_values(array_filter($pkg->data('pages.json'), static function (array $p): bool {
        return !empty($p['home']);
    }));
    same(1, count($home), 'una sola portada');
    same('inicio', $home[0]['code']);
});

test('la página larga tiene más de 100 bloques, ninguno html con script, y es la conversión exacta del fichero de bloques de WordPress', function (): void {
    $pages = json_decode((string) file_get_contents(aifecSiteDir() . '/pages.json'), true);
    $long = null;
    foreach ($pages as $page) {
        if ($page['code'] === 'que-es-aifec') {
            $long = $page;
        }
    }
    check($long !== null, 'la página larga está en pages.json');
    check(aifecThemeCountBlocks($long['blocks']) > 100, 'más de 100 bloques: ' . aifecThemeCountBlocks($long['blocks']));
    foreach ($pages as $page) {
        foreach (aifecThemeHtmlBlocks($page['blocks']) as $html) {
            notContains('<script', strtolower($html), 'un bloque html con script en «' . $page['code'] . '»');
        }
    }
    $source = (string) file_get_contents(aifecSiteDir() . '/pages/que-es-aifec.wp.txt');
    contains('<!-- wp:heading', $source, 'el original está tal cual');
    same(json_encode(GutenbergParser::parse($source)), json_encode($long['blocks']), 'pages.json es la conversión del .wp.txt; vuelve a pasar utiles/aifec/sitio/convertir_wp.php');
    notContains('<script', strtolower($source));
});

test('los textos del paquete llevan su ortografía castellana: ni una palabra de uso común sin tilde en la portada', function (): void {
    $text = (string) file_get_contents(aifecSiteDir() . '/pages.json') . (string) file_get_contents(aifecSiteDir() . '/menus.json');
    foreach (['Documentacion', 'Paginas', 'Que es ', 'Politica', 'Pagina ', 'informacion', 'configuracion'] as $bad) {
        notContains($bad, $text, 'falta una tilde: «' . $bad . '»');
    }
    contains('Qué es AIFEC', $text);
    contains('Política de privacidad', $text);
});
