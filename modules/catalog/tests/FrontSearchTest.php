<?php
/**
 * AIFEC — pruebas de la búsqueda de la tienda: la página sin nada buscado (el formulario), con una palabra (el listado, sin
 * indexar y sin caché), el recorte de lo buscado, el escape de lo que escribe quien busca y el respeto a una ruta «search» que ya
 * exista.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;

require_once __DIR__ . '/helpers.php';

if (!function_exists('frontCheckPage')) {
    /**
     * Lo que ninguna página de la tienda puede llevar: un href vacío, una dirección de otro dominio o un formulario POST cuya
     * ruta no pase por el intermediario «csrf».
     */
    function frontCheckPage(App $app, string $html, string $label): void
    {
        notContains('href=""', $html, $label . ': href vacío');
        preg_match_all('#(?:href|src|action)="(?:https?:)?//([^/"?\#]+)#i', $html, $hosts);
        foreach ($hosts[1] as $host) {
            same('tienda.test', $host, $label . ': dirección de otro dominio');
        }
        preg_match_all('/<form\b[^>]*>/i', $html, $forms);
        foreach ($forms[0] as $tag) {
            if (preg_match('/\bmethod="post"/i', $tag) !== 1) {
                continue;
            }
            $action = preg_match('/\baction="([^"]*)"/i', $tag, $a) === 1 ? html_entity_decode($a[1]) : '';
            $match = $app->router()->match('POST', (string) parse_url($action, PHP_URL_PATH));
            check($match !== null && in_array('csrf', $match['route']->middleware, true), $label . ': formulario POST sin csrf (' . $action . ')');
        }
    }
}

/** Una tienda con dos sillas y una mesa; la silla azul está agotada. */
function frontSearchShop(App $app): void
{
    aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo', 'azul' => 'Azul']);
    aifecProduct($app, ['name' => 'Silla Roja', 'slug' => 'silla-roja', 'price' => 5000, 'attributes' => ['color' => 'rojo'], 'position' => 1]);
    aifecProduct($app, ['name' => 'Silla Azul', 'slug' => 'silla-azul', 'price' => 8000, 'attributes' => ['color' => 'azul'], 'stock' => 0, 'position' => 2]);
    aifecProduct($app, ['name' => 'Mesa Roble', 'slug' => 'mesa-roble', 'price' => 12000, 'position' => 3]);
    aifecProduct($app, ['name' => 'Silla oculta', 'slug' => 'silla-oculta', 'visibility' => 'catalog']);
}

/**
 * @return string[]
 */
function frontSearchNames(string $html): array
{
    preg_match_all('#<h3 class="af-product-card-name">([^<]*)</h3>#', $html, $m);

    return array_map('html_entity_decode', $m[1]);
}

test('sin palabra: el formulario de búsqueda, un solo h1, sin indexar y sin caché de página', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontSearchShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'), 'un solo h1');
    contains('<h1 class="af-title">Buscar</h1>', $r->body);
    check(preg_match('#<form class="af-row" role="search" method="get" action="/buscar">.*?name="q" maxlength="80".*?</form>#s', $r->body) === 1, 'formulario GET hacia la ruta «search», con el largo máximo');
    contains('<meta name="robots" content="noindex, follow">', $r->body);
    notContains('af-product-card', $r->body, 'sin palabra no hay listado');
    same(0, $r->cacheTtl, 'no se guarda en la caché de página');
    check(!isset($r->headers['X-Cache']), 'ni lleva marca de caché');
    same('/buscar', route('search'), 'la ruta se llama «search» y usa la palabra del ajuste');
});

test('con palabra: listado de lo que casa, noindex, canónica con la palabra y sin caché', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontSearchShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar?q=silla');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'));
    contains('<h1 class="af-title">Resultados para silla</h1>', $r->body);
    same(['Silla Roja', 'Silla Azul'], frontSearchNames($r->body), 'lo que casa, y no lo que solo se ve en el catálogo');
    contains('<meta name="robots" content="noindex, follow">', $r->body);
    contains('<link rel="canonical" href="https://tienda.test/buscar?q=silla">', $r->body);
    contains('<title>Resultados para silla · ', $r->body);
    contains('2 productos', $r->body);
    contains('af-type-search', $r->body);
    same(0, $r->cacheTtl);
    check(!isset($r->headers['X-Cache']), 'sin marca de caché');
    // Los filtros y el orden valen en la búsqueda y la palabra viaja con ellos.
    $filtered = $web->get('/buscar?q=silla&f[color][]=rojo');
    same(['Silla Roja'], frontSearchNames($filtered->body));
    contains('<input type="hidden" name="q" value="silla">', $filtered->body, 'el formulario de filtros lleva la palabra');
    contains('action="/buscar"', $filtered->body);
    same(['Silla Azul', 'Silla Roja'], frontSearchNames($web->get('/buscar?q=silla&sort=price_desc')->body));
    contains('data-af-drawer-open="af-filters"', $r->body, 'la barra inferior del móvil lleva Filtros');
});

test('sin resultados: mensaje con sugerencia', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontSearchShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar?q=zzzz');
    same(200, $r->status);
    contains('af-empty', $r->body);
    contains('No hay productos', $r->body);
    contains('Revisa la ortografía', $r->body);
    contains('0 productos', $r->body);
});

test('una palabra larguísima se recorta a 80 caracteres', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontSearchShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar?q=' . str_repeat('a', 300));
    same(200, $r->status);
    contains('Resultados para ' . str_repeat('a', 80) . '</h1>', $r->body);
    notContains(str_repeat('a', 81), $r->body);
    $accents = $web->get('/buscar?q=' . rawurlencode(str_repeat('ñ', 120)));
    contains('Resultados para ' . str_repeat('ñ', 80) . '</h1>', $accents->body, 'se cuentan letras, no bytes');
    notContains(str_repeat('ñ', 81), $accents->body);
});

test('lo que escribe quien busca sale escapado: <script>, comillas y «&»', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontSearchShop($app);
    $web = new TestClient($app);
    $q = '<script>alert(1)</script> "a" & b';
    $r = $web->get('/buscar?q=' . rawurlencode($q));
    same(200, $r->status);
    notContains('<script>alert(1)', $r->body, 'el HTML de quien busca no entra sin escapar');
    contains('&lt;script&gt;alert(1)&lt;/script&gt; &quot;a&quot; &amp; b', $r->body);
    notContains('<title>Resultados para <script>', $r->body);
    check(preg_match('#<input type="hidden" name="q" value="&lt;script&gt;[^"]*">#', $r->body) === 1, 'también en el campo oculto de los filtros');
    frontCheckPage($app, $r->body, 'búsqueda con HTML');
});

test('la búsqueda respeta una ruta «search» que ya exista y no registra otra', function (): void {
    $app = catalogApp([], ['catalog']);
    $routes = require dirname(__DIR__) . '/routes.php';
    $router = new Router();
    $router->get('/otra-busqueda', static function (): string {
        return 'otra';
    })->name('search');
    $routes($router, $app);
    same('/otra-busqueda', $router->path('search'), 'la ruta que ya estaba sigue siendo «search»');
    check($router->match('GET', '/buscar') === null, 'el catálogo no pone la suya');
    check($router->has('catalog.choose'), 'el paso de las variantes sí se registra');

    $clean = new Router();
    $routes($clean, $app);
    same('/buscar', $clean->path('search'), 'sin otra, la palabra por defecto');
    $custom = catalogApp(['urls.search' => 'search-it'], ['catalog']);
    $other = new Router();
    $routes($other, $custom);
    same('/search-it', $other->path('search'), 'la palabra sale del ajuste urls.search');
});

test('las páginas de búsqueda no llevan href vacío, direcciones de otro dominio ni formularios POST sin csrf', function (): void {
    $app = catalogApp([], ['orders', 'media']);
    frontSearchShop($app);
    $web = new TestClient($app);
    foreach (['/buscar', '/buscar?q=silla', '/buscar?q=zzzz', '/buscar?q=silla&f[color][]=rojo'] as $uri) {
        $r = $web->get($uri);
        same(200, $r->status, $uri);
        frontCheckPage($app, $r->body, $uri);
    }
});
