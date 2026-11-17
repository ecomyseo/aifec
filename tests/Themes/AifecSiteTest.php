<?php
/**
 * AIFEC — pruebas de la portada del sitio raíz: el paquete aifec-sitio aplicado (con ContentApply) a un sitio con catálogo y a otro sin él,
 * con el tema aifec. La frase y los dos botones, los tres pasos de «Pruébalo ahora», la rejilla de categorías, el botón del asistente, el menú
 * con «Tienda», los enlaces que no dan 404, el contrato móvil, nada en línea y ningún producto nombrado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Http\FakeHttpClient;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Modules\Sites\ContentApply;
use Aifec\Modules\Sites\Package;

require_once dirname(__DIR__, 2) . '/modules/sites/tests/helpers.php';
require_once dirname(__DIR__, 2) . '/modules/catalog/tests/helpers.php';

/** Los productos de la tienda de prueba (inventados): la portada no puede nombrar otro. */
const AIFEC_ROOT_PRODUCTS = ['Producto de ejemplo uno', 'Producto de ejemplo dos', 'Producto de ejemplo tres', 'Producto de ejemplo cuatro', 'Producto de ejemplo cinco', 'Producto de ejemplo seis'];

/**
 * Un sitio con el paquete aifec-sitio ya aplicado.
 *
 * @param bool $catalog   con tres categorías de primer nivel (una con imagen) y seis productos
 * @param bool $assistant con el módulo del asistente
 * @param bool $agents    con el módulo de la API, que trae la compra con enlaces para los agentes
 */
function aifecRootSite(bool $catalog, bool $assistant = true, bool $agents = false): App
{
    $modules = ['cms', 'media', 'themes', 'docs', 'sites', 'seo', 'semantic'];
    if ($catalog) {
        $modules = array_merge($modules, ['catalog', 'orders', 'search', 'customers']);
        if ($assistant) {
            $modules[] = 'assistant';
        }
        if ($agents) {
            $modules[] = 'api';
        }
    }
    $app = testApp(['modules' => $modules, 'config' => ['master' => true], 'settings' => ['modules.active' => $modules]]);
    $app->boot();
    if ($catalog) {
        $one = aifecCategory($app, 'Categoría de ejemplo A');
        $two = aifecCategory($app, 'Categoría de ejemplo B');
        $three = aifecCategory($app, 'Categoría de ejemplo C', null, ['media_id' => aifecMedia($app, 'Imagen de la categoría C')]);
        aifecCategory($app, 'Subcategoría de ejemplo', $one);
        $cats = [$one, $one, $two, $two, $three, $three];
        foreach (AIFEC_ROOT_PRODUCTS as $i => $name) {
            $data = ['name' => $name, 'categories' => [$cats[$i]], 'price' => 1000 + 500 * $i, 'images' => 1, 'featured' => $i < 2 ? 1 : 0];
            if ($i === 3) {
                $data['price'] = 2000;
                $data['sale_price'] = 900;
            }
            aifecProduct($app, $data);
        }
        $app->invalidate(['catalog']);
    }
    $package = Package::load(Autoload::root() . '/demos', 'aifec-sitio', Autoload::root());
    (new ContentApply($app, $package))->run([], false);
    $app->cache()->flush();

    return aifecRootReboot($app, $modules);
}

/**
 * El mismo sitio arrancado de nuevo: el tema, sus ganchos y sus plantillas se eligen al arrancar, y package:apply cambia el tema de un
 * sitio que ya está en marcha (en producción, la siguiente petición lo arranca ya con el tema nuevo).
 */
/**
 * @param string[] $modules
 */
function aifecRootReboot(App $before, array $modules): App
{
    $app = new App($before->site(), Autoload::root(), [
        'clock' => new Clock(testNow()), 'http' => new FakeHttpClient(), 'log_files' => false, 'public_dir' => $before->site()->dataDir . '/public', 'modules' => $modules,
    ]);
    $app->hooks()->onError(static function (string $module, string $hook, \Throwable $e): void {
        AifecTestEnv::$hookErrors[] = 'gancho «' . $hook . '» (' . $module . '): ' . get_class($e) . ': ' . $e->getMessage() . ' en ' . basename($e->getFile()) . ':' . $e->getLine();
    });
    $app->makeCurrent();
    $app->boot();

    return $app;
}

/** La portada de un sitio, ya con el paquete aplicado (una sola petición por sitio y variante). */
function aifecRootHome(bool $catalog, bool $assistant = true): string
{
    static $cache = [];
    $key = ($catalog ? 'c' : 'n') . ($assistant ? 'a' : 'x');
    if (!isset($cache[$key])) {
        $app = aifecRootSite($catalog, $assistant);
        $response = (new TestClient($app))->get('/');
        check($response->status === 200, 'la portada responde 200 (' . $response->status . ')');
        $cache[$key] = $response->body;
    }

    return $cache[$key];
}

/** El contenido de <main>. */
function aifecRootMain(string $html): string
{
    $start = (int) strpos($html, '<main');
    $end = (int) strpos($html, '</main>');

    return substr($html, $start, max(0, $end - $start));
}

/**
 * Las direcciones internas que enlaza una página con <a href="/…">, sin repetir, sin ancla y sin lo que sirve el servidor de recursos.
 *
 * @return string[]
 */
function aifecRootLinks(string $html): array
{
    preg_match_all('/<a\b[^>]*\bhref="(\/[^"]*)"/i', $html, $m);
    $out = [];
    foreach ($m[1] as $href) {
        $href = html_entity_decode($href, ENT_QUOTES, 'UTF-8');
        $href = (string) preg_replace('/#.*$/', '', $href);
        if ($href === '' || strpos($href, '//') === 0 || preg_match('#^/(t|media|assets)/#', $href) === 1) {
            continue;
        }
        $out[$href] = true;
    }

    return array_keys($out);
}

/** Ruta de un fichero del tema. */
function aifecRootTheme(string $relative): string
{
    return rtrim(str_replace('\\', '/', Autoload::root()), '/') . '/themes/aifec/' . $relative;
}

test('la portada tiene un solo h1 y es la frase: «Los agentes ya están comprando. Tú solo decides si dejas que lo hagan.»', function (): void {
    foreach ([true, false] as $catalog) {
        $body = aifecRootHome($catalog);
        same(1, substr_count($body, '<h1'), 'un solo h1 (' . ($catalog ? 'con' : 'sin') . ' catálogo)');
        check(preg_match('#<h1[^>]*>Los agentes ya están comprando\. <em>Tú solo decides</em> si dejas que lo hagan\.</h1>#', $body) === 1, 'el h1 es la frase');
        contains('AI First E-commerce — la primera tienda que entienden las personas y las máquinas.', $body, 'y debajo, la línea llana con el nombre');
        notContains('AI First Shop', $body, 'el nombre es AI First E-commerce');
    }
});

test('el titular lleva sus dos botones, «Probar con un agente» y «Ver las demos», y al lado el registro de una compra hecha por un agente', function (): void {
    foreach ([true, false] as $catalog) {
        $main = aifecRootMain(aifecRootHome($catalog));
        contains('<a class="af-btn af-btn-fill" href="#pruebalo">Probar con un agente</a>', $main);
        contains('<a class="af-btn af-btn-outline" href="/demos">Ver las demos</a>', $main);
        contains('id="pruebalo"', $main, 'el primer botón lleva a un apartado que existe');
        $hero = substr($main, 0, (int) strpos($main, '</section>'));
        contains('<table', $hero, 'el registro es una tabla');
        foreach (['Hora', 'Lo que hizo el agente', 'La tienda', 'La persona le da su código', 'pedido 2026-000043'] as $piece) {
            contains($piece, $hero);
        }
        same(7, substr_count($hero, '<tr') - 1, 'siete pasos y la fila de cabecera');
    }
});

test('«Pruébalo»: con tienda y asistente, tres pasos con su enlace; el del agente solo sale con la compra con enlaces', function (): void {
    $main = aifecRootMain(aifecRootHome(true));
    contains('id="pruebalo"', $main);
    foreach (['Pregunta al asistente de la tienda', 'Haz una compra de prueba', 'Recorre una demo de tu sector'] as $step) {
        contains($step, $main);
    }
    same(3, substr_count($main, 'af-b-column') - substr_count($main, 'af-b-columns') - 2, 'tres columnas de pasos (las dos del titular de portada no cuentan)');
    contains('Pago de prueba', $main, 'dice con qué forma de pago no se cobra nada');
    contains('<a class="af-btn af-btn-outline" href="#tienda">Ir a la tienda</a>', $main);
    contains('href="#asistente"', $main);
    check(preg_match('#Recorre una demo de tu sector.*?href="/demos">Ver las demos</a>#s', $main) === 1, 'el último paso enlaza la página de demos');
    notContains('contraseña', $main, 'las credenciales las enseña la página de demos, no se copian');
    notContains('Dale la tienda a un agente', $main, 'sin la API no se promete la compra con enlaces');
    notContains('/buy-with-links', $main);
});

test('con la API encendida el primer paso es darle la tienda a un agente, y su dirección responde', function (): void {
    $app = aifecRootSite(true, true, true);
    $client = new TestClient($app);
    $main = aifecRootMain($client->get('/')->body);
    contains('Dale la tienda a un agente', $main);
    contains('<a class="af-btn af-btn-fill" href="/buy-with-links">Abrir la compra para agentes</a>', $main);
    same(4, substr_count($main, 'af-b-column') - substr_count($main, 'af-b-columns') - 2, 'cuatro columnas de pasos');
    check(strpos($main, 'Dale la tienda a un agente') < strpos($main, 'Pregunta al asistente de la tienda'), 'el del agente va el primero');
    same(200, $client->get('/buy-with-links')->status, 'la dirección que enlaza existe');
    $app->settings()->set('api.links', false);
    $app->cache()->flush();
    notContains('/buy-with-links', aifecRootMain((new TestClient($app))->get('/')->body), 'apagada la compra con enlaces, el paso se va');
});

test('las tiendas de demostración salen en la portada con su nombre, su sector, su enlace y su captura o su inicial', function (): void {
    $app = aifecRootSite(false);
    $html = $app->view()->render('partials/home-demos', ['demos' => [
        ['name' => 'Deporte & Aire libre', 'sector' => 'deporte', 'url' => 'https://tienda.test/demos/deporte/', 'preview' => 'https://tienda.test/demo-preview/deporte'],
        ['name' => 'óptica', 'sector' => '', 'url' => 'https://tienda.test/demos/optica/', 'preview' => ''],
    ]]);
    contains('<section class="af-demos-home" id="demos"', $html);
    contains('Tiendas de demostración que puedes abrir ahora', $html, 'el título, traducido');
    same(2, substr_count($html, 'class="af-demo"'));
    contains('href="https://tienda.test/demos/deporte/"', $html);
    contains('Deporte &amp; Aire libre', $html, 'el nombre, escapado');
    contains('src="https://tienda.test/demo-preview/deporte"', $html);
    contains('<span class="af-demo-blank" aria-hidden="true">Ó</span>', $html, 'sin captura, su inicial');
    same('', trim($app->view()->render('partials/home-demos', ['demos' => []])), 'sin demos no se pinta nada');
    notContains('af-demos-home', aifecRootHome(false), 'y un sitio sin demos apuntadas no lleva el apartado');
});

test('sin tienda ni asistente solo queda el tercer paso, y sin asistente cae solo el segundo', function (): void {
    $bare = aifecRootMain(aifecRootHome(false));
    contains('Recorre una demo de tu sector', $bare);
    notContains('Haz una compra de prueba', $bare);
    notContains('Pregunta al asistente', $bare);
    notContains('paso-comprar', $bare);
    $main = aifecRootMain(aifecRootHome(true, false));
    contains('Haz una compra de prueba', $main);
    contains('Recorre una demo de tu sector', $main);
    notContains('Pregunta al asistente', $main);
    notContains('#asistente', $main);
});

test('el botón del asistente lleva data-af-assistant-open solo con el módulo activo', function (): void {
    $with = aifecRootHome(true, true);
    contains('href="#asistente" role="button" aria-haspopup="dialog" data-af-assistant-open>Abrir el asistente</a>', aifecRootMain($with));
    contains('af-assistant-fab', $with, 'y el asistente está en la página');
    $without = aifecRootHome(true, false);
    notContains('data-af-assistant-open', $without, 'sin el módulo no hay ni botón ni ventana');
    notContains('Abrir el asistente', $without);
});

test('«Qué lo distingue»: siete puntos en una lista numerada, cada uno con su negrita y su frase', function (): void {
    $main = aifecRootMain(aifecRootHome(false));
    contains('id="distingue"', $main);
    $list = substr($main, (int) strpos($main, 'id="distingue"'));
    $list = substr($list, (int) strpos($list, '<ol'), (int) strpos($list, '</ol>') - (int) strpos($list, '<ol'));
    $items = substr_count($list, '<li>');
    check($items >= 5 && $items <= 7, 'entre cinco y siete puntos: ' . $items);
    same(7, $items);
    same(7, substr_count($list, '<strong>'), 'cada uno empieza por su negrita');
    foreach (['Una sola verdad por producto', 'Un buscador que entiende la frase', 'Un asistente que prepara el pedido', 'Una caja de una sola página', 'Puertas para los agentes', 'Preparada para el ERP', 'Cookies y devoluciones resueltas'] as $point) {
        contains($point, $list);
    }
    contains('solo hace el pedido cuando tú lo confirmas', $list, 'el asistente solo cierra el pedido con la confirmación de la persona');
});

test('cada punto de «Qué lo distingue» está respaldado por un módulo y una ruta que existen en el proyecto', function (): void {
    $root = rtrim(str_replace('\\', '/', Autoload::root()), '/');
    $proof = [
        'una sola verdad' => ['modules/semantic/routes.php', "'/llms.txt'"],
        'buscador' => ['modules/search/routes.php', 'sugerir.json'],
        'asistente' => ['modules/assistant/src/Assistant.php', 'public function confirm('],
        'caja de una página' => ['modules/orders/routes.php', "name('checkout')"],
        'API' => ['modules/api/routes.php', '/api/openapi.json'],
        'MCP' => ['modules/mcp/routes.php', "'/mcp'"],
        'WebMCP' => ['modules/webmcp/routes.php', '/webmcp/tools.json'],
        'ERP' => ['modules/erp/src/Routes.php', "'/api/v1/erp'"],
        'ERP, avisos firmados' => ['modules/erp/src/Webhooks.php', 'X-Aifec-Signature'],
        'cookies' => ['modules/consent/routes.php', '/consentimiento'],
        'devoluciones y desistimiento' => ['modules/returns/routes.php', '/desistimiento'],
        'importación' => ['modules/import/src/ImportController.php', 'no email is composed'],
        'copiloto' => ['modules/copilot/module.json', 'Plain-language orders'],
        'pago de prueba' => ['modules/payments-simulated/src/SimulatedPayment.php', "t('Test payment')"],
    ];
    foreach ($proof as $what => $where) {
        $file = $root . '/' . $where[0];
        check(is_file($file), $what . ': falta ' . $where[0]);
        contains($where[1], (string) file_get_contents($file), $what . ' (' . $where[0] . ')');
    }
    $json = (string) file_get_contents(Autoload::root() . '/demos/aifec-sitio/pages.json');
    foreach (['/llms.txt', '/api/v1', '/api/openapi.json', '/mcp', '/webmcp/tools.json', '/api/v1/erp'] as $path) {
        contains($path, $json, 'la página nombra ' . $path);
    }
});

test('la rejilla de categorías sale con catálogo, con su nombre, su enlace y su imagen si la tiene, y no sale sin él', function (): void {
    $shop = aifecRootHome(true);
    contains('<section class="af-cats" id="tienda"', $shop);
    same(3, substr_count($shop, 'class="af-cat"'), 'las tres categorías de primer nivel (la subcategoría no sale)');
    foreach (['Categoría de ejemplo A', 'Categoría de ejemplo B', 'Categoría de ejemplo C'] as $name) {
        check(preg_match('#class="af-cat" href="/[^"]+"[^>]*>.*?<span class="af-cat-name">' . preg_quote($name, '#') . '</span>#s', $shop) === 1, 'el enlace de ' . $name);
    }
    notContains('Subcategoría de ejemplo</span>', $shop);
    same(1, substr_count($shop, 'af-cat-img'), 'solo la categoría C tiene imagen');
    same(2, substr_count($shop, 'af-cat-letter'), 'las otras dos, su inicial');
    notContains('af-cats', aifecRootHome(false));
    notContains('id="tienda"', aifecRootHome(false));
});

test('el orden de la portada: titular, categorías, destacados, novedades y, debajo, el resto de la página; sin duplicar categorías', function (): void {
    $shop = aifecRootHome(true);
    preg_match_all('/data-section="([^"]+)"/', $shop, $m);
    same(['cms.home', 'aifec.categories', 'catalog.featured', 'catalog.new', 'catalog.sale', 'cms.home.more'], $m[1]);
    $first = substr($shop, (int) strpos($shop, 'data-section="cms.home"'), (int) strpos($shop, 'data-section="aifec.categories"') - (int) strpos($shop, 'data-section="cms.home"'));
    contains('<h1', $first, 'arriba solo va el titular: la tienda sale justo debajo');
    foreach (['pruebalo', 'distingue', 'importar', 'desarrolladores'] as $anchor) {
        notContains('id="' . $anchor . '"', $first);
    }
    $last = substr($shop, (int) strpos($shop, 'data-section="cms.home.more"'));
    foreach (['pruebalo', 'distingue', 'importar', 'desarrolladores'] as $anchor) {
        contains('id="' . $anchor . '"', $last);
    }
    contains('Trae tu tienda <em>entera</em>', $last);
    contains('Para desarrolladores', $last);
    contains('href="/docs"', $last);
    contains('href="/para-desarrolladores"', $last);
    notContains('catalog.categories', $shop, 'la sección de categorías del catálogo cede su sitio a la del tema');
    $bare = aifecRootHome(false);
    preg_match_all('/data-section="([^"]+)"/', $bare, $m);
    same(['cms.home', 'cms.home.more'], $m[1], 'sin catálogo, solo las dos partes de la página');
});

test('el HTML de la portada solo nombra productos del sitio de prueba y el paquete no nombra ninguno', function (): void {
    $shop = aifecRootHome(true);
    preg_match_all('#<h3 class="af-product-card-name">([^<]*)</h3>#', $shop, $m);
    check(count($m[1]) >= 2, 'se pintan tarjetas de producto');
    foreach ($m[1] as $name) {
        check(in_array(html_entity_decode($name), AIFEC_ROOT_PRODUCTS, true), 'la tarjeta «' . $name . '» es de un producto del sitio de prueba');
    }
    $package = (string) file_get_contents(Autoload::root() . '/demos/aifec-sitio/pages.json') . (string) file_get_contents(Autoload::root() . '/demos/aifec-sitio/menus.json');
    notContains('product_skus', $package);
    notContains('"type": "product"', $package);
    notContains('"type":"product"', $package);
    notContains('"mode":"manual"', $package);
    foreach (array_merge(aifecThemeFilesOf('templates'), [aifecRootTheme('functions.php')]) as $file) {
        notContains('product_skus', (string) file_get_contents($file), basename($file));
    }
});

/**
 * @return string[]
 */
function aifecThemeFilesOf(string $sub): array
{
    $files = [];
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(aifecRootTheme($sub), \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }
    sort($files);

    return $files;
}

test('el menú: Inicio, Qué es AIFEC, Tienda, Demos, Documentación, Desarrolladores, Contacto; «Tienda» despliega las categorías del sitio', function (): void {
    $body = aifecRootHome(true);
    $nav = substr($body, (int) strpos($body, '<nav class="af-nav af-hide-sm"'));
    $nav = substr($nav, 0, (int) strpos($nav, '</nav>'));
    $last = -1;
    foreach (['>Inicio<', '>Qué es AIFEC<', '>Tienda<', '>Demos<', '>Documentación<', '>Desarrolladores<', '>Contacto<'] as $label) {
        $at = strpos($nav, $label);
        check($at !== false && $at > $last, 'el menú trae ' . $label . ' y en su sitio');
        $last = (int) $at;
    }
    contains('class="af-nav-item has-children af-nav-shop"', $nav);
    $shop = substr($nav, (int) strpos($nav, 'id="af-shop-h-0"'));
    $shop = substr($shop, 0, (int) strpos($shop, '</ul>'));
    foreach (['Categoría de ejemplo A', 'Categoría de ejemplo B', 'Categoría de ejemplo C'] as $name) {
        contains('>' . $name . '</a>', $shop);
    }
    notContains('Subcategoría de ejemplo', $shop, 'solo las de primer nivel');
    notContains('href="#tienda"', $nav, 'el marcador del paquete no llega al HTML');
});

test('«Tienda» es accesible: botón con aria-expanded y aria-controls, oculto hasta que lo enseña el guion, y la lista funciona sin JavaScript', function (): void {
    $body = aifecRootHome(true);
    same(2, substr_count($body, 'class="af-nav-toggle"'), 'uno en la cabecera y otro en el cajón del móvil');
    preg_match_all('#<button type="button" class="af-nav-toggle" aria-expanded="false" aria-controls="([^"]+)" aria-label="[^"]+" hidden>#', $body, $m);
    same(2, count($m[1]), 'cada botón nace plegado y oculto');
    check($m[1][0] !== $m[1][1], 'los identificadores no se repiten: ' . implode(' / ', $m[1]));
    foreach ($m[1] as $id) {
        same(1, substr_count($body, 'id="' . $id . '"'), 'el desplegable ' . $id . ' existe una sola vez');
    }
    // Sin JavaScript: «Tienda» es un enlace al buscador y las categorías están en la lista (se abre con :hover y :focus-within).
    contains('<a class="af-nav-link" href="/buscar">Tienda</a>', $body);
    $css = (string) file_get_contents(aifecRootTheme('assets/css/theme.css'));
    contains('.af-nav-item:hover>.af-nav-sub,.af-nav-item:focus-within>.af-nav-sub{display:flex}', $css);
    contains('.af-nav-toggle[hidden]{display:none}', $css, 'el atributo hidden gana a display:inline-flex: sin JavaScript no hay botón muerto');
    $js =(string) file_get_contents(aifecRootTheme('assets/js/theme.js'));
    foreach (['Escape', "setAttribute('aria-expanded'", "removeAttribute('hidden')", 'af-nav-shop'] as $needle) {
        contains($needle, $js, 'theme.js lleva ' . $needle);
    }
});

test('en el cajón del móvil lleva las mismas entradas y las categorías plegadas dentro de «Tienda»', function (): void {
    $body = aifecRootHome(true);
    $drawer = substr($body, (int) strpos($body, 'id="af-menu"'));
    $drawer = substr($drawer, 0, (int) strpos($drawer, '</aside>'));
    $last = -1;
    foreach (['>Inicio<', '>Qué es AIFEC<', '>Tienda<', '>Demos<', '>Documentación<', '>Desarrolladores<', '>Contacto<'] as $label) {
        $at = strpos($drawer, $label);
        check($at !== false && $at > $last, 'el cajón trae ' . $label);
        $last = (int) $at;
    }
    contains('af-nav-shop', $drawer);
    contains('Categoría de ejemplo B', $drawer);
    $css = (string) file_get_contents(aifecRootTheme('assets/css/theme.css'));
    contains('.af-drawer .af-nav-shop>.af-nav-sub{display:none;grid-column:1/-1}', $css);
    contains('.af-drawer .af-nav-shop.is-open>.af-nav-sub{display:flex}', $css);
});

test('sin catálogo el menú no lleva «Tienda» ni su botón, y el resto de entradas siguen', function (): void {
    $bare = aifecRootHome(false);
    notContains('af-nav-shop', $bare);
    notContains('af-nav-toggle', $bare);
    notContains('>Tienda<', $bare);
    notContains('href="#tienda"', $bare);
    contains('>Qué es AIFEC</a>', $bare);
    contains('>Desarrolladores</a>', $bare);
    contains('>Documentación</a>', $bare);
});

test('la cabecera no se parte en dos líneas: ninguna regla de la navegación del escritorio activa flex-wrap, y el botón sobrante se esconde entre 900 y 1279', function (): void {
    $css = (string) file_get_contents(aifecRootTheme('assets/css/theme.css'));
    preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
    $seen = 0;
    foreach ($rules as $rule) {
        foreach (explode(',', $rule[1]) as $selector) {
            $selector = trim($selector);
            $inNav = strpos($selector, '.af-header') !== false || strpos($selector, '.af-nav') !== false;
            if ($inNav && strpos($selector, '.af-footer') === false && strpos($selector, '.af-drawer') === false) {
                ++$seen;
                check(preg_match('/flex-wrap:\s*wrap/', $rule[2]) !== 1, 'la regla «' . $selector . '» activa flex-wrap');
            }
        }
    }
    check($seen >= 20, 'se han mirado las reglas de la cabecera y del menú (' . $seen . ')');
    contains('.af-nav-list{display:flex;flex-wrap:nowrap', $css);
    contains('.af-header .af-nav-link{padding:.5rem .45rem;font-size:.68rem;letter-spacing:.05em;white-space:nowrap}', $css);
    contains('@media (min-width:900px) and (max-width:1279px){' . "\n" . '  .af-header-cta{display:none}', $css);
});

test('contrato móvil con tienda: cabecera pegajosa, barra inferior fija con Buscar y Carrito reales y sus paneles', function (): void {
    $body = aifecRootHome(true);
    contains('<header class="af-header af-header-bar"', $body);
    $bar = substr($body, (int) strpos($body, '<nav class="af-mobile-bar'));
    $bar = substr($bar, 0, (int) strpos($bar, '</nav>'));
    contains('data-af-drawer-open="af-search"', $bar);
    contains('data-af-drawer-open="af-cart"', $bar);
    contains('href="/buscar"', $bar);
    contains('href="/carrito"', $bar);
    contains('data-af-cart-count', $bar, 'el carrito con su contador');
    notContains('Filtros', $bar, 'sin filtros que funcionen no hay botón de filtros');
    contains('id="af-search"', $body);
    contains('id="af-cart"', $body);
    contains('role="search"', $body);
    $css = (string) file_get_contents(aifecRootTheme('assets/css/theme.css'));
    check(preg_match('/\.af-header\{[^}]*position:sticky/', $css) === 1, 'la cabecera es pegajosa');
    check(preg_match('/\.af-mobile-bar\{[^}]*position:fixed[^}]*bottom:0/', $css) === 1, 'la barra inferior es fija abajo');
    check(preg_match('/\.af-has-bar\{[^}]*padding-bottom:calc\(var\(--bar-h\) \+ env\(safe-area-inset-bottom\)\)/', $css) === 1, 'el contenido reserva su alto y respeta el área segura');
    contains('af-has-bar', $body);
});

test('en la cabecera de escritorio van el buscador, la cuenta y el carrito (y solo con tienda)', function (): void {
    $header = aifecRootHome(true);
    $header = substr($header, (int) strpos($header, '<header class="af-header'), (int) strpos($header, '</header>') - (int) strpos($header, '<header class="af-header'));
    contains('data-af-drawer-open="af-search"', $header);
    contains('data-af-account', $header);
    contains('data-af-drawer-open="af-cart"', $header);
    $bare = aifecRootHome(false);
    $bare = substr($bare, (int) strpos($bare, '<header class="af-header'), (int) strpos($bare, '</header>') - (int) strpos($bare, '<header class="af-header'));
    notContains('af-cart', $bare);
    notContains('af-search', $bare);
});

test('ni style= ni <script> en línea en el contenido de la portada, con tienda y sin ella', function (): void {
    foreach ([true, false] as $catalog) {
        $main = aifecRootMain(aifecRootHome($catalog));
        check($main !== '', 'hay contenido');
        notContains(' style=', $main, 'estilo en línea (' . ($catalog ? 'con' : 'sin') . ' catálogo)');
        notContains('<script', strtolower($main), 'guion en línea');
        notContains(' onclick=', $main);
    }
});

test('ningún enlace interno de la portada, del menú ni del pie da 404, con tienda y sin ella', function (): void {
    foreach ([true, false] as $catalog) {
        $app = aifecRootSite($catalog);
        $web = new TestClient($app);
        $links = aifecRootLinks($web->get('/')->body);
        check(count($links) >= 10, 'hay enlaces que mirar: ' . count($links));
        foreach (['/demos', '/docs', '/que-es-aifec', '/para-desarrolladores', '/sobre-las-demos', '/contacto', '/aviso-legal', '/privacidad'] as $must) {
            check(in_array($must, $links, true), 'la portada enlaza ' . $must . ($catalog ? ' (con tienda)' : ' (sin tienda)'));
        }
        $bad = [];
        foreach ($links as $href) {
            $status = $web->get($href)->status;
            if ($status >= 400) {
                $bad[] = $href . ' → ' . $status;
            }
        }
        same([], $bad, 'enlaces rotos ' . ($catalog ? 'con' : 'sin') . ' catálogo');
        aifecSitesRelease($web);
        aifecSitesRelease($app);
    }
});

test('el pie lleva sus enlaces y los legales, todos con página', function (): void {
    $body = aifecRootHome(false);
    $footer = substr($body, (int) strpos($body, '<footer class="af-footer'));
    foreach (['/que-es-aifec', '/sobre-las-demos', '/para-desarrolladores', '/docs', '/predicciones', '/contacto', '/aviso-legal', '/privacidad'] as $href) {
        contains('href="' . $href . '"', $footer, 'el pie enlaza ' . $href);
    }
    contains('Una sola verdad', substr($footer, (int) strpos($footer, 'af-principles')));
});

test('el paquete se aplica dos veces sin repetir nada, y el aviso legal que el sitio ya tenía pasa a leer los datos de Ajustes (con su versión anterior guardada)', function (): void {
    $app = testApp(['modules' => ['cms', 'media', 'themes', 'docs'], 'config' => ['master' => true], 'settings' => ['modules.active' => ['cms', 'media', 'themes', 'docs']]]);
    $app->boot();
    $db = $app->db();
    $now = $app->clock()->sql();
    $legal = $db->insert('pages', ['status' => 'published', 'template' => 'default', 'position' => 0, 'noindex' => 0, 'is_home' => 0, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('page_i18n', ['page_id' => $legal, 'lang' => 'es', 'title' => 'Aviso legal real', 'blocks' => '[{"type":"paragraph","attrs":{"html":"Texto legal real"}}]']);
    $app->slugs()->set('page', $legal, 'es', 'aviso-legal');
    $package = Package::load(Autoload::root() . '/demos', 'aifec-sitio', Autoload::root());
    $first = (new ContentApply($app, $package))->run([], false);
    same(11, $first['counts']['pages']);
    same(10, $first['counts']['created'], 'diez páginas nuevas: la del aviso legal ya existía');
    // 14/11/2026, decisión del usuario: las páginas legales de todas las tiendas usan siempre los datos de empresa de Ajustes.
    same('update', $first['pages'][6]['action'], 'el aviso legal que ya tenía el sitio se rehace con el generador');
    same('Aviso legal', (string) $db->value('SELECT title FROM {page_i18n} WHERE page_id = ?', [$legal]));
    contains('"type":"company"', (string) $db->value('SELECT blocks FROM {page_i18n} WHERE page_id = ?', [$legal]));
    contains('Texto legal real', (string) $db->value("SELECT data FROM {revisions} WHERE entity = 'page' AND entity_id = ? ORDER BY id", [$legal]), 'el texto de antes queda en sus versiones');
    same($legal, (int) ($app->settings()->arr('cms.legal')['legal-notice'] ?? 0), 'y queda apuntado como el aviso legal');
    $counts = [(int) $db->value('SELECT COUNT(*) FROM {pages}'), (int) $db->value('SELECT COUNT(*) FROM {page_i18n}'), (int) $db->value('SELECT COUNT(*) FROM {menus}'), (int) $db->value('SELECT COUNT(*) FROM {menu_items}')];
    same([11, 11, 3, 17], $counts);
    $second = (new ContentApply($app, $package))->run([], false);
    same(0, $second['counts']['created']);
    same(0, $second['counts']['updated']);
    same($counts, [(int) $db->value('SELECT COUNT(*) FROM {pages}'), (int) $db->value('SELECT COUNT(*) FROM {page_i18n}'), (int) $db->value('SELECT COUNT(*) FROM {menus}'), (int) $db->value('SELECT COUNT(*) FROM {menu_items}')]);
    same(1, (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE is_home = 1'));
    same('aifec', $app->settings()->str('theme.active'));
});

test('las páginas del paquete dicen solo lo que existe hoy: nada de VERI*FACTU, de adaptadores UCP/ACP ni de «estamos preparando»', function (): void {
    $text = (string) file_get_contents(Autoload::root() . '/demos/aifec-sitio/pages.json');
    foreach (['VERI*FACTU', 'Adaptadores para UCP', 'estamos preparando', 'prueba el prototipo', 'Google Pay y Apple Pay, y PayPal', 'cuánto tarda cada página', 'detecta contradicciones'] as $gone) {
        notContains($gone, $text, 'sigue diciendo «' . $gone . '»');
    }
    foreach (['WebMCP', 'copiloto', 'importación completa desde PrestaShop y WooCommerce', 'facturas rectificativas', 'package:apply', 'API del ERP'] as $new) {
        contains($new, $text, 'falta lo nuevo: ' . $new);
    }
});

test('sin palabras de relleno ni signos de exclamación en las páginas del paquete, y ni un dato personal ni un cliente real', function (): void {
    $pages = json_decode((string) file_get_contents(Autoload::root() . '/demos/aifec-sitio/pages.json'), true);
    $plain = '';
    $walk = static function (array $blocks) use (&$walk, &$plain): void {
        foreach ($blocks as $block) {
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
            foreach (['html', 'summary'] as $key) {
                $plain .= ' ' . (isset($attrs[$key]) && is_string($attrs[$key]) ? strip_tags($attrs[$key]) : '');
            }
            foreach (isset($attrs['items']) && is_array($attrs['items']) ? $attrs['items'] : [] as $item) {
                $plain .= ' ' . (is_array($item) ? strip_tags((string) (isset($item['html']) ? $item['html'] : (isset($item['text']) ? $item['text'] : ''))) : '');
            }
            if (isset($block['children']) && is_array($block['children'])) {
                $walk($block['children']);
            }
        }
    };
    foreach ($pages as $page) {
        $walk($page['blocks']);
    }
    check(strlen($plain) > 20000, 'se ha leído el texto de las páginas');
    foreach (['potente', 'robusto', 'revolucionari', 'Potente', 'Robusto'] as $word) {
        notContains($word, $plain);
    }
    same(0, substr_count($plain, '!'), 'ni un signo de exclamación');
    preg_match_all('/[A-Za-z0-9._-]+@[A-Za-z0-9.-]+/', (string) file_get_contents(Autoload::root() . '/demos/aifec-sitio/pages.json') . (string) file_get_contents(Autoload::root() . '/demos/aifec-sitio/package.json'), $mails);
    foreach ($mails[0] as $mail) {
        check(preg_match('/\.test$|@example\.com$/', $mail) === 1, 'un correo que no es de ejemplo: ' . $mail);
    }
});

test('presupuesto: theme.css pesa como mucho 45 000 bytes, theme.js 25 000, y el guion no tiene direcciones externas ni eval', function (): void {
    $css = aifecRootTheme('assets/css/theme.css');
    $js = aifecRootTheme('assets/js/theme.js');
    check(filesize($css) <= 45000, 'theme.css pesa ' . filesize($css));
    check(is_file($js) && filesize($js) <= 25000, 'theme.js pesa ' . filesize($js));
    $code = (string) file_get_contents($js);
    same(0, preg_match('#https?://#i', $code));
    notContains('eval(', $code);
    notContains('innerHTML', $code, 'el guion no escribe HTML');
});

test('la página de predicciones trae las cuatro IA con sus tablas por plazo, y la página larga la resume y la enlaza', function (): void {
    $app = aifecRootSite(false);
    $web = new TestClient($app);
    $r = $web->get('/predicciones');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'), 'un solo h1');
    foreach (['grok', 'chatgpt', 'gemini', 'claude', 'coinciden', 'discrepan', 'medir'] as $anchor) {
        contains('id="' . $anchor . '"', $r->body);
    }
    same(7, substr_count($r->body, '<table'), 'el resumen, las cuatro tablas por tipo de tienda, las prioridades de ChatGPT y las cuotas de Claude');
    foreach (['Fin 2026 – ppio. 2027', '2027–2028', '2029–2030', 'Recambios de automoción', 'Joyería y relojes', 'Tiendas solo para agentes', '10–15 %', '5–10 %', 'Dominante'] as $piece) {
        contains($piece, $r->body);
    }
    contains('Son predicciones, no datos medidos', $r->body, 'lo dice arriba');
    notContains(' style=', aifecRootMain($r->body));
    $pages = json_decode((string) file_get_contents(Autoload::root() . '/demos/aifec-sitio/pages.json'), true);
    $page = array_values(array_filter($pages, static function (array $p): bool {
        return $p['code'] === 'predicciones';
    }))[0];
    $rows = [];
    foreach ($page['blocks'] as $block) {
        if ($block['type'] === 'table' && $block['attrs']['head'][0][0] === 'Tipo de tienda') {
            $rows[] = count($block['attrs']['rows']);
            foreach ($block['attrs']['rows'] as $row) {
                same(4, count($row), 'cada tipo de tienda, con sus tres plazos: ' . $row[0]);
            }
        }
    }
    same([14, 28, 10, 22], $rows, 'Grok, ChatGPT, Gemini y Claude, con todos sus tipos de tienda');

    $long = $web->get('/que-es-aifec')->body;
    contains('id="predicciones"', $long);
    contains('href="#predicciones"', $long, 'está en el índice');
    contains('href="/predicciones"', $long, 'y enlaza la página completa');
});
