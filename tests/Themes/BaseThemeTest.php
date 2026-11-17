<?php
/**
 * AIFEC — pruebas del tema base: envoltorio, portada, error, página de muestra, piezas, menús, opciones del tema y
 * las comprobaciones estáticas del presupuesto de peso y del contrato de estilos y de móvil.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Kernel\App;

/** Carpeta del tema base. */
function baseThemeDir(): string
{
    return rtrim(str_replace('\\', '/', Autoload::root()), '/') . '/themes/base';
}

/**
 * Todos los ficheros de una carpeta del tema (recursivo).
 *
 * @return string[]
 */
function baseThemeFiles(string $sub): array
{
    $files = [];
    $dir = baseThemeDir() . '/' . $sub;
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
function baseThemeCssWithoutRoot(string $css): string
{
    return (string) preg_replace('/:where\(:root\)\s*\{[^}]*\}|:root\s*\{[^}]*\}/', '', $css);
}

/** Aplicación con el tema montado y lista para pintar piezas sueltas. */
function baseThemeBooted(array $options = []): App
{
    $app = testApp($options);
    $app->boot();

    return $app;
}

/** Rutas de las que dependen la cabecera y la barra del móvil, registradas como lo haría un módulo de comercio. */
function baseThemeShopRoutes(App $app): void
{
    $noop = static function (): string {
        return '';
    };
    $app->router()->get('/buscar', $noop)->name('search');
    $app->router()->get('/carrito', $noop)->name('cart');
    $app->router()->get('/mi-cuenta', $noop)->name('account');
    $app->router()->get('/finalizar-compra', $noop)->name('checkout');
}

test('la portada responde 200 con el envoltorio, un solo h1, la hoja y el guion del tema, canónica y robots, y se guarda en caché', function (): void {
    $web = new TestClient(testApp());
    $r = $web->get('/');
    same(200, $r->status);
    $body = $r->body;
    contains('<!doctype html>', $body);
    contains('<html lang="es"', $body);
    contains('data-currency="EUR"', $body);
    contains('data-locale="es"', $body);
    contains('<meta charset="utf-8">', $body);
    contains('name="viewport"', $body);
    contains('<main id="main"', $body);
    contains('class="af-skip" href="#main"', $body);
    contains('/css/theme.css', $body);
    contains('/js/theme.js" defer', $body);
    contains('<link rel="canonical"', $body);
    contains('<meta name="robots"', $body);
    contains('<header class="af-header', $body);
    contains('<footer class="af-footer', $body);
    check(substr_count($body, '<h1') <= 1, 'un solo h1 en la portada');
    check(isset($r->headers['X-Cache']), 'la portada se marca para la caché de página');
});

test('la portada pinta las secciones de los módulos en su orden dentro de su envoltorio', function (): void {
    $app = testApp();
    $app->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'second', 'order' => 20, 'html' => '<section><h2>Segunda</h2></section>'];
        $sections[] = ['id' => 'first', 'order' => 10, 'html' => '<section><h1>Primera</h1></section>'];

        return $sections;
    });
    $body = (new TestClient($app))->get('/')->body;
    contains('<div class="af-home-section af-container" data-section="first">', $body);
    contains('data-section="second"', $body);
    check(strpos($body, 'Primera') < strpos($body, 'Segunda'), 'las secciones salen por su orden, no por el de alta');
    same(1, substr_count($body, '<h1'), 'si una sección trae su h1, el tema no pone otro');
});

test('la portada sin secciones enseña un bloque amable y el h1 en su cabecera (el nombre de la tienda)', function (): void {
    $body = (new TestClient(testApp()))->get('/')->body;
    contains('class="af-empty"', $body);
    contains('<h1 class="af-hero-title" id="af-hero-title">AIFEC</h1>', $body);
    same(1, substr_count($body, '<h1'));
});

test('una dirección que no existe da 404 con la página de error dentro del envoltorio y noindex', function (): void {
    $r = (new TestClient(testApp()))->get('/esto-no-existe');
    same(404, $r->status);
    $body = $r->body;
    contains('<main id="main"', $body);
    contains('<header class="af-header', $body);
    contains('class="af-empty af-error"', $body);
    contains('<p class="af-error-code">404</p>', $body);
    contains('noindex', $body);
    same(1, substr_count($body, '<h1'));
    contains('href="/"', $body);
    // Una dirección que no existe no se declara canónica de sí misma (lo vio la visita de SEO del 13/11/2026 en /caja).
    notContains('rel="canonical"', $body);
    notContains('og:url', $body);
    contains('rel="canonical" href="', (new TestClient(testApp()))->get('/')->body, 'las páginas que existen sí la llevan');
});

test('la página de error escapa el mensaje y no deja pasar HTML', function (): void {
    $app = baseThemeBooted();
    $html = $app->view()->render('error', ['status' => 500, 'title' => 'Server error', 'message' => '<script>alert(1)</script>']);
    contains('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
    notContains('<script>alert(1)', $html);
});

test('la muestra del tema da 200 y contiene cada pieza y cada clase del contrato', function (): void {
    $r = (new TestClient(testApp()))->get('/tema/muestra');
    same(200, $r->status);
    $body = $r->body;
    $needles = [
        'af-product-card', 'af-badge-sale', 'af-badge-new', 'af-badge-out', 'af-price-old', 'af-price-off', 'af-price-from', 'af-price-lg',
        'af-btn-primary', 'af-btn-ghost', 'af-btn-block', 'af-btn-sm', 'af-btn-lg', 'af-field', 'af-hint', 'af-error', 'af-check', 'af-radio-card',
        'af-input', 'af-select', 'af-textarea', 'af-qty', 'af-alert-ok', 'af-alert-bad', 'af-alert-info', 'af-alert-warn', 'af-stars', 'af-breadcrumbs',
        'af-pagination', 'af-tabs', 'af-product-gallery', 'af-product-buy', 'af-gallery-thumbs', 'af-variants', 'af-variant', 'af-availability',
        'af-facts', 'af-cart-line', 'af-totals-total', 'af-summary', 'af-steps', 'af-table', 'af-prose', 'af-empty', 'af-card', 'af-grid af-cols-4',
        'af-listing-results', 'af-split', 'af-noimage', 'data-af-gallery', 'data-af-variants', 'data-af-qty', 'data-af-tabs', 'data-af-toggle',
        'data-af-cart-add', 'data-af-autosubmit', 'data-af-price-now', 'data-af-availability', 'data-af-variant-id',
    ];
    foreach ($needles as $needle) {
        contains($needle, $body, 'la muestra no enseña «' . $needle . '»');
    }
    same(8, substr_count($body, '<article class="af-product-card'), 'ocho tarjetas de producto');
    same(1, substr_count($body, '<h1'), 'un solo h1 en la muestra');
    notContains('href=""', $body);
    $imgs = [];
    preg_match_all('/<img\b[^>]*>/', $body, $imgs);
    check($imgs[0] !== [], 'la muestra tiene imágenes');
    foreach ($imgs[0] as $img) {
        check(strpos($img, ' width="') !== false && strpos($img, ' height="') !== false, 'toda imagen lleva ancho y alto: ' . substr($img, 0, 80));
    }
});

test('con filtros=1 la muestra pinta el botón de filtros de la barra inferior y su panel; sin el parámetro, no', function (): void {
    $app = testApp();
    $web = new TestClient($app);
    $with = $web->get('/tema/muestra?filtros=1')->body;
    contains('<nav class="af-mobile-bar af-show-sm"', $with);
    contains('data-af-drawer-open="af-filters"', $with);
    contains('id="af-filters"', $with);
    contains('af-listing-filters', $with);
    contains('data-af-drawer-open="af-search"', $with);
    contains('data-af-drawer-open="af-cart"', $with);
    contains('data-af-cart-count', $with);
    contains('af-has-bar', $with);
    $without = $web->get('/tema/muestra')->body;
    contains('af-mobile-bar', $without);
    notContains('data-af-drawer-open="af-filters"', $without);
    notContains('id="af-filters"', $without);
});

test('con la depuración apagada la muestra no existe, y la marca de muestra no se queda pegada a la siguiente petición', function (): void {
    $web = new TestClient(testApp(['config' => ['debug' => false]]));
    same(404, $web->get('/tema/muestra')->status);

    $debug = new TestClient(testApp());
    $debug->get('/tema/muestra');
    $after = $debug->get('/esto-tampoco-existe')->body;
    notContains('af-mobile-bar', $after, 'sin módulos, la barra inferior no sale fuera de la muestra');
});

test('el formulario de la muestra responde como lo haría el carrito: JSON con contador y aviso', function (): void {
    $web = new TestClient(testApp());
    $r = $web->postJson('/tema/muestra', ['qty' => 3]);
    same(200, $r->status);
    $data = $web->json();
    same(true, $data['ok']);
    same(3, $data['count']);
    check(isset($data['message']) && $data['message'] !== '', 'trae el texto del aviso');
});

test('las piezas escapan los datos: una tarjeta con nombre «Mesa <b>&» sale escapada', function (): void {
    $app = baseThemeBooted();
    $html = $app->view()->render('partials/product-card', ['product' => [
        'id' => 1, 'name' => 'Mesa <b>&', 'url' => '/producto/mesa?a=1&b=2', 'image' => null, 'brand' => 'Casa <i>',
        'price' => ['amount' => 1999, 'with_tax' => true, 'compare' => null, 'currency' => 'EUR', 'from' => false],
        'rating' => ['average' => 4.5, 'count' => 3], 'badges' => ['sale', 'new', 'out'],
    ]]);
    contains('Mesa &lt;b&gt;&amp;', $html);
    notContains('Mesa <b>', $html);
    contains('Casa &lt;i&gt;', $html);
    contains('href="/producto/mesa?a=1&amp;b=2"', $html);
    contains('style="--rating:4.5"', $html);
    contains('af-badge-new', $html);
    contains('af-badge-out', $html);
    same(1, substr_count($html, 'class="af-product-card-link"'), 'todo el bloque enlaza una sola vez');
    notContains('href=""', $html);
});

test('la tarjeta sin dirección no pinta un enlace roto, y con la marca desactivada no enseña la marca', function (): void {
    $app = baseThemeBooted(['settings' => ['theme.options' => ['base' => ['show_brand_in_card' => 0, 'card_ratio' => 'portrait']]]]);
    $html = $app->view()->render('partials/product-card', ['product' => [
        'name' => 'Silla', 'url' => '', 'image' => null, 'brand' => 'Nordika',
        'price' => ['amount' => 500, 'currency' => 'EUR'], 'rating' => null, 'badges' => [],
    ]]);
    notContains('<a ', $html);
    notContains('Nordika', $html);
    contains('af-ratio-portrait', $html);
});

test('el precio pinta el anterior tachado y el porcentaje solo si hay oferta', function (): void {
    $app = baseThemeBooted();
    $sale = $app->view()->render('partials/price', ['size' => 'md', 'price' => [
        'amount' => 1999, 'with_tax' => true, 'compare' => 2499, 'discount_percent' => 20, 'currency' => 'EUR', 'from' => false,
    ]]);
    contains('af-price-now', $sale);
    contains('<s class="af-price-old"', $sale);
    contains('af-price-off', $sale);
    contains("\u{2212}20\u{a0}%", $sale);
    contains('is-sale', $sale);
    $plain = $app->view()->render('partials/price', ['size' => 'md', 'price' => [
        'amount' => 1999, 'with_tax' => true, 'compare' => null, 'discount_percent' => null, 'currency' => 'EUR', 'from' => false,
    ]]);
    notContains('af-price-old', $plain);
    notContains('af-price-off', $plain);
    contains('af-price-note', $plain);
    $from = $app->view()->render('partials/price', ['size' => 'sm', 'price' => ['amount' => 1999, 'currency' => 'EUR', 'from' => true]]);
    contains('af-price-from', $from);
    notContains('af-price-note"><', $from, 'en tamaño pequeño no hay nota de impuestos');
    $computed = $app->view()->render('partials/price', ['size' => 'sm', 'price' => ['amount' => 750, 'compare' => 1000, 'currency' => 'EUR']]);
    contains("\u{2212}25\u{a0}%", $computed, 'sin discount_percent se calcula con enteros');
    $live = $app->view()->render('partials/price', ['size' => 'lg', 'live' => true, 'price' => ['amount' => 1999, 'currency' => 'EUR']]);
    contains('data-af-price-old', $live);
    contains('data-af-price-off', $live);
});

test('paginación, avisos, cantidad y migas pintan lo que toca', function (): void {
    $app = baseThemeBooted();
    $pager = $app->view()->render('partials/pagination', ['page' => 5, 'pages' => 12, 'url' => static function (int $n): string {
        return '/lista?p=' . $n;
    }]);
    contains('rel="prev"', $pager);
    contains('rel="next"', $pager);
    contains('href="/lista?p=4"', $pager);
    contains('aria-current="page"', $pager);
    contains('af-pagination-gap', $pager);
    same('', trim($app->view()->render('partials/pagination', ['page' => 1, 'pages' => 1, 'url' => static function (int $n): string {
        return '/x' . $n;
    }])), 'con una sola página no se pinta nada');
    $first = $app->view()->render('partials/pagination', ['page' => 1, 'pages' => 3, 'url' => static function (int $n): string {
        return '/x' . $n;
    }]);
    notContains('rel="prev"', $first);

    $alerts = $app->view()->render('partials/messages', ['messages' => [['ok', 'Hecho & listo'], ['bad', '<b>mal</b>'], ['raro', 'Otro'], ['ok', '']]]);
    contains('af-alert af-alert-ok', $alerts);
    contains('Hecho &amp; listo', $alerts);
    contains('&lt;b&gt;mal&lt;/b&gt;', $alerts);
    contains('af-alert-info', $alerts);
    same(3, substr_count($alerts, 'class="af-alert '), 'un aviso vacío no se pinta');

    $qty = $app->view()->render('partials/qty', ['name' => 'cantidad', 'value' => 50, 'min' => 1, 'max' => 10]);
    contains('name="cantidad"', $qty);
    contains('value="10"', $qty);
    contains('max="10"', $qty);
    contains('data-af-qty-step="-1"', $qty);
    contains('data-af-qty-step="1"', $qty);

    $crumbs = $app->view()->render('partials/breadcrumbs', ['crumbs' => [['name' => 'Inicio', 'url' => '/'], ['name' => 'Mesas & sillas', 'url' => '']]]);
    contains('af-breadcrumbs', $crumbs);
    contains('Mesas &amp; sillas', $crumbs);
});

test('los menús de los módulos salen en cabecera y pie, y un elemento sin dirección no deja un enlace roto', function (): void {
    $app = testApp();
    $app->hooks()->filter('menu.items', static function (array $items, string $location): array {
        if ($location === 'main') {
            return [
                ['label' => 'Tienda', 'url' => '/tienda', 'current' => true, 'children' => [['label' => 'Sillas', 'url' => '/tienda/sillas']]],
                ['label' => 'Roto', 'url' => ''],
                ['label' => 'Grupo vacío', 'url' => '', 'children' => []],
                ['label' => 'Solo rótulo', 'url' => '', 'children' => [['label' => 'Hijo', 'url' => '/hijo']]],
            ];
        }
        if ($location === 'footer') {
            return [['label' => 'Sobre nosotros', 'url' => '/sobre-nosotros']];
        }
        if ($location === 'legal') {
            return [['label' => 'Privacidad', 'url' => '/privacidad']];
        }

        return $items;
    });
    $body = (new TestClient($app))->get('/')->body;
    $header = substr($body, (int) strpos($body, '<header'), (int) strpos($body, '</header>') - (int) strpos($body, '<header'));
    contains('<a class="af-nav-link" href="/tienda" aria-current="page">Tienda</a>', $header);
    contains('href="/tienda/sillas"', $header);
    contains('Solo rótulo', $header);
    contains('href="/hijo"', $header);
    notContains('Roto', $body);
    notContains('Grupo vacío', $body);
    $footer = substr($body, (int) strpos($body, '<footer'));
    contains('href="/sobre-nosotros"', $footer);
    contains('href="/privacidad"', $footer);
    contains('href="/tienda"', (string) substr($body, (int) strpos($body, 'id="af-menu"')), 'el panel del móvil lleva el mismo menú');
    notContains('href=""', $body);
});

test('sin ningún módulo la cabecera funciona y no enlaza a nada que no exista; con las rutas de comercio salen buscador, cuenta, carrito y barra', function (): void {
    $bare = (new TestClient(testApp()))->get('/')->body;
    contains('class="af-logo"', $bare);
    notContains('role="search"', $bare);
    notContains('af-mobile-bar', $bare);
    notContains('data-af-cart-count', $bare);
    notContains('id="af-cart"', $bare);
    notContains('href=""', $bare);
    notContains('af-has-bar', $bare);

    $app = testApp();
    baseThemeShopRoutes($app);
    $shop = (new TestClient($app))->get('/')->body;
    contains('role="search"', $shop);
    contains('action="/buscar"', $shop);
    contains('href="/mi-cuenta"', $shop);
    contains('href="/carrito"', $shop);
    contains('href="/finalizar-compra"', $shop);
    contains('<nav class="af-mobile-bar af-show-sm"', $shop);
    contains('id="af-cart"', $shop);
    contains('data-af-cart-lines', $shop);
    contains('af-has-bar', $shop);
    notContains('data-af-drawer-open="af-filters"', $shop, 'sin filtros funcionales no hay botón de filtros');
    notContains('href=""', $shop);
});

test('la barra del móvil no se queda con accesos que no existen', function (): void {
    $app = testApp();
    $app->router()->get('/carrito', static function (): string {
        return '';
    })->name('cart');
    $body = (new TestClient($app))->get('/')->body;
    contains('data-af-drawer-open="af-cart"', $body);
    notContains('data-af-drawer-open="af-search"', $body);
    notContains('af-mobile-bar-label">Cuenta', $body);
    $bar = substr($body, (int) strpos($body, '<nav class="af-mobile-bar'));
    $bar = substr($bar, 0, (int) strpos($bar, '</nav>'));
    same(1, substr_count($bar, 'af-mobile-bar-item'), 'solo el carrito');
});

test('las opciones del tema: cambiar el acento cambia la variable del <head> y un valor que no es un color se ignora', function (): void {
    $web = new TestClient(testApp(['settings' => ['theme.options' => ['base' => ['accent' => '#112233', 'radius' => 14]]]]));
    $body = $web->get('/')->body;
    contains('<style id="af-theme-vars">', $body);
    contains('--accent:#112233', $body);
    contains('--radius:14px', $body);

    $bad = (new TestClient(testApp(['settings' => ['theme.options' => ['base' => ['accent' => 'url(javascript:1)', 'radius' => 999]]]])))->get('/')->body;
    contains('--accent:#b94a17', $bad);
    notContains('javascript', $bad);
    contains('--radius:10px', $bad);
    contains('--font:system-ui', $bad);
});

test('la opción de cabecera centrada y la de tarjeta vertical llegan al HTML', function (): void {
    $centered = (new TestClient(testApp(['settings' => ['theme.options' => ['base' => ['header_style' => 'centered']]]])))->get('/')->body;
    contains('af-header af-header-centered', $centered);
    $classic = (new TestClient(testApp()))->get('/')->body;
    contains('af-header af-header-classic', $classic);
});

test('peso: theme.css pesa como mucho 45 000 bytes y theme.js como mucho 25 000', function (): void {
    $css = baseThemeDir() . '/assets/css/theme.css';
    $js = baseThemeDir() . '/assets/js/theme.js';
    check(is_file($css) && is_file($js), 'existen la hoja y el guion');
    check(filesize($css) <= 45000, 'theme.css pesa ' . filesize($css));
    check(filesize($js) <= 25000, 'theme.js pesa ' . filesize($js));
});

test('ninguna dirección externa en las plantillas ni en los recursos (salvo el espacio de nombres de los SVG) y ningún @import', function (): void {
    $files = array_merge(baseThemeFiles('templates'), baseThemeFiles('assets'));
    check(count($files) >= 15, 'se han encontrado las plantillas y los recursos');
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        same(0, preg_match('#https?://(?!www\.w3\.org)#i', $text), basename($file) . ' lleva una dirección externa');
        notContains('@import', $text, basename($file));
        notContains('//cdn', $text, basename($file));
    }
});

test('theme.css: ningún color #… fuera del bloque :root, y sin fuentes ni iconos de fuera', function (): void {
    $css = (string) file_get_contents(baseThemeDir() . '/assets/css/theme.css');
    $rest = baseThemeCssWithoutRoot($css);
    $found = [];
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $rest, $found);
    same([], $found[0], 'colores fijos fuera de :root');
    check(preg_match('/\b(?:rgb|rgba|hsl|hsla)\(/', $rest) === 0, 'colores funcionales fuera de :root');
    notContains('@font-face', $css);
    notContains('url(', $css);
});

test('theme.json es JSON válido, trae lo del contrato y cada opción con var existe en :root', function (): void {
    $json = json_decode((string) file_get_contents(baseThemeDir() . '/theme.json'), true);
    check(is_array($json), 'JSON válido');
    same('Base', $json['name']);
    same(null, $json['parent']);
    same(['main', 'footer', 'legal'], array_keys($json['menus']));
    same(160, $json['image_sizes']['thumb'][0]);
    same(480, $json['image_sizes']['card'][0]);
    same(1200, $json['image_sizes']['large'][0]);
    same(['system', 'serif', 'rounded', 'mono'], array_keys($json['font_stacks']));
    $css = (string) file_get_contents(baseThemeDir() . '/assets/css/theme.css');
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
    // P100: el título y el texto de la cabecera de portada («Temas → Portada»).
    same(['header_style', 'card_ratio', 'show_brand_in_card', 'home_title', 'home_text'], $plain);
});

test('todas las plantillas del contrato existen y empiezan por la cabecera del proyecto', function (): void {
    $names = ['layout', 'home', 'error', 'sample', 'partials/header', 'partials/footer', 'partials/mobile-bar', 'partials/product-card', 'partials/price',
        'partials/breadcrumbs', 'partials/pagination', 'partials/messages', 'partials/qty', 'partials/nav', 'partials/drawers'];
    foreach ($names as $name) {
        $file = baseThemeDir() . '/templates/' . $name . '.php';
        check(is_file($file), 'falta templates/' . $name . '.php');
        $head = (string) file_get_contents($file, false, null, 0, 900);
        check(strpos($head, "<?php\n/**\n * AIFEC") === 0, $name . ' no empieza por la cabecera del proyecto');
        contains('@author    Ecom Experts <ecomyseo@gmail.com>', $head);
    }
    foreach (['functions.php', 'lang/es.php', 'assets/css/theme.css', 'assets/js/theme.js'] as $extra) {
        check(is_file(baseThemeDir() . '/' . $extra), 'falta ' . $extra);
    }
});

test('theme.css cumple el contrato de estilos y el de móvil', function (): void {
    $css = (string) file_get_contents(baseThemeDir() . '/assets/css/theme.css');
    $classes = [
        'af-container', 'af-section', 'af-grid', 'af-cols-2', 'af-cols-3', 'af-cols-4', 'af-cols-5', 'af-cols-6', 'af-stack', 'af-row', 'af-split', 'af-sr',
        'af-hide-sm', 'af-show-sm', 'af-title', 'af-subtitle', 'af-muted', 'af-small', 'af-prose', 'af-btn', 'af-btn-primary', 'af-btn-ghost', 'af-btn-block',
        'af-btn-sm', 'af-btn-lg', 'af-input', 'af-select', 'af-textarea', 'af-field', 'af-hint', 'af-error', 'af-check', 'af-radio-card', 'af-qty', 'af-alert',
        'af-alert-ok', 'af-alert-bad', 'af-alert-info', 'af-alert-warn', 'af-badge', 'af-badge-sale', 'af-badge-new', 'af-badge-out', 'af-stars',
        'af-product-card', 'af-price', 'af-price-now', 'af-price-old', 'af-price-off', 'af-price-note', 'af-listing', 'af-listing-filters', 'af-listing-results',
        'af-filter', 'af-product', 'af-product-gallery', 'af-product-buy', 'af-gallery', 'af-gallery-main', 'af-gallery-thumbs', 'af-variants', 'af-variant',
        'af-availability', 'is-ok', 'is-low', 'is-out', 'af-facts', 'af-tabs', 'af-cart', 'af-cart-line', 'af-totals', 'af-totals-row', 'af-totals-total',
        'af-steps', 'af-summary', 'af-table', 'af-card', 'af-empty', 'af-mobile-bar', 'af-drawer', 'af-drawer-backdrop',
    ];
    foreach ($classes as $class) {
        check(preg_match('/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/', $css) === 1, 'theme.css no da estilo a .' . $class);
    }
    foreach (['position:sticky', 'env(safe-area-inset-bottom)', 'min-height:44px', 'prefers-reduced-motion', ':focus-visible', 'object-fit:contain', 'line-through',
        'min-width:44px', 'flex-wrap:wrap', 'overflow-x:auto'] as $needle) {
        contains($needle, $css, 'theme.css no contiene «' . $needle . '»');
    }
    // La cabecera es pegajosa y la barra inferior es fija.
    check(preg_match('/\.af-header\{[^}]*position:sticky/', $css) === 1, 'la cabecera es pegajosa');
    check(preg_match('/\.af-mobile-bar\{[^}]*position:fixed[^}]*bottom:0/', $css) === 1, 'la barra inferior es fija abajo');
    check(preg_match('/\.af-has-bar\{[^}]*padding-bottom:calc\(var\(--bar-h\) \+ env\(safe-area-inset-bottom\)\)/', $css) === 1, 'el contenido reserva el alto de la barra');
    check(preg_match('/\.af-product-card-media img[^{]*\{[^}]*object-fit:contain/', $css) === 1, 'la imagen de la tarjeta se ve completa');
});

test('theme.js trae los comportamientos del contrato, window.Aifec y ninguna ventana de aviso del navegador', function (): void {
    $js = (string) file_get_contents(baseThemeDir() . '/assets/js/theme.js');
    foreach (['data-af-drawer-open', 'data-af-drawer-close', 'data-af-cart-count', 'data-af-cart-add', 'data-af-qty', 'data-af-autosubmit', 'data-af-gallery',
        'data-af-variants', 'data-af-tabs', 'data-af-toggle', 'Aifec.on = ', 'Aifec.get = ', 'Aifec.post = ', 'Aifec.toast = ', 'Aifec.money = ', 'window.Aifec = Aifec',
        'data-currency', 'data-locale', 'data-cart-cookie', 'data-cart-url'] as $needle) {
        contains($needle, $js, 'theme.js no contiene «' . $needle . '»');
    }
    foreach (['alert(', 'confirm(', 'prompt(', 'eval(', 'document.write', 'innerHTML'] as $banned) {
        notContains($banned, $js, 'theme.js no puede usar ' . $banned);
    }
});
