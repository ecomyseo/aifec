<?php
/**
 * AIFEC — pruebas de la tienda: la página de resultados usa el buscador (y el listado del catálogo conserva filtros, orden y
 * paginación), enseña lo corregido, dice «no hemos encontrado nada» en vez de una rejilla de ruido, no se indexa ni se guarda
 * en caché, escapa lo que escribe quien busca, y el JSON de sugerencias.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/** Lo que ninguna página de la tienda puede llevar: un href vacío o una dirección de otro dominio. */
function searchCheckPage(string $html, string $label): void
{
    notContains('href=""', $html, $label . ': href vacío');
    preg_match_all('#(?:href|src|action)="(?:https?:)?//([^/"?\#]+)#i', $html, $hosts);
    foreach ($hosts[1] as $host) {
        // La única dirección de fuera es el aviso de origen que exige la licencia («Creado con AIFEC»).
        if ($host === 'aifec.dev') {
            continue;
        }
        same('tienda.test', $host, $label . ': dirección de otro dominio');
    }
}

/**
 * @return string[]
 */
function searchCardNames(string $html): array
{
    preg_match_all('#<h3 class="af-product-card-name">([^<]*)</h3>#', $html, $m);

    return array_map('html_entity_decode', $m[1]);
}

test('la ruta «search» es la del buscador y sustituye a la del catálogo; las sugerencias tienen la suya', function (): void {
    $app = searchApp();
    $match = $app->router()->match('GET', '/buscar');
    check($match !== null, 'la ruta existe');
    same('search', $match['route']->module, 'la atiende el módulo del buscador, no el catálogo');
    same('/buscar', route('search'));
    same('/buscar/sugerir.json', route('search.suggest'));
    same('/buscar/sugerencias.json', route('search.suggest.old'), 'la dirección de antes sigue');
    $custom = searchApp(['urls.search' => 'search-it']);
    same('/search-it', route('search'), 'la palabra sale del ajuste urls.search');
    same('/search-it/sugerir.json', route('search.suggest'));
    unset($custom);
});

test('sin palabra: el formulario, sin indexar y sin caché de página', function (): void {
    $app = searchApp();
    searchShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'));
    contains('<meta name="robots" content="noindex, follow">', $r->body);
    notContains('af-product-card', $r->body);
    same(0, $r->cacheTtl);
});

test('con palabra: lo que casa por relevancia, con la plantilla del catálogo, noindex, canónica y sin caché', function (): void {
    $app = searchApp();
    searchShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar?q=silla');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'));
    contains('<h1 class="af-title">Resultados para silla</h1>', $r->body);
    same(['Silla roja', 'Sillón orejero', 'Silla azul'], searchCardNames($r->body), 'por relevancia, con lo agotado («Silla azul») al final');
    contains('<meta name="robots" content="noindex, follow">', $r->body);
    contains('<link rel="canonical" href="https://tienda.test/buscar?q=silla">', $r->body);
    contains('3 productos', $r->body);
    same(0, $r->cacheTtl);
    check(!isset($r->headers['X-Cache']), 'sin marca de caché');
    notContains('data-af-search-nothing', $r->body);
    searchCheckPage($r->body, 'resultados');
});

test('el listado del catálogo conserva filtros, orden y paginación sobre lo que encontró el buscador', function (): void {
    $app = searchApp(['catalog.per_page' => 2]);
    searchShop($app);
    $web = new TestClient($app);
    same(['Silla roja'], searchCardNames($web->get('/buscar?q=silla&f[color][]=rojo')->body), 'filtro por atributo');
    $sorted = $web->get('/buscar?q=silla&sort=price_desc');
    same(['Silla azul', 'Silla roja'], searchCardNames($sorted->body), 'orden por precio, en la primera página');
    $page1 = $web->get('/buscar?q=silla');
    same(['Silla roja', 'Sillón orejero'], searchCardNames($page1->body));
    contains('rel="next"', $page1->body);
    contains('q=silla&amp;page=2', $page1->body, 'la palabra viaja con la página');
    $page2 = $web->get('/buscar?q=silla&page=2');
    same(['Silla azul'], searchCardNames($page2->body), 'la segunda página, con lo agotado');
    contains('q=silla&amp;page=2">', $page2->body, 'la canónica de la segunda página lleva la palabra y la página');
    contains('data-af-drawer-open="af-filters"', $page1->body, 'la barra inferior del móvil lleva Filtros');
});

test('una errata se corrige y la página lo dice', function (): void {
    $app = searchApp();
    searchShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar?q=slla');
    same(200, $r->status);
    contains('Mostrando resultados de silla', $r->body);
    contains('Has buscado slla', $r->body);
    contains('<h1 class="af-title">Resultados para silla</h1>', $r->body);
    same(['Silla roja', 'Sillón orejero', 'Silla azul'], searchCardNames($r->body));
    notContains('data-af-search-nothing', $r->body);
});

test('si lo encontrado no convence, la página dice «no hemos encontrado nada» y no enseña una rejilla', function (): void {
    $app = searchApp();
    searchShop($app);
    $web = new TestClient($app);
    foreach (['zapatillas', 'mesa zapatillas'] as $q) {
        $r = $web->get('/buscar?q=' . rawurlencode($q));
        same(200, $r->status, $q);
        contains('data-af-search-nothing', $r->body, $q);
        contains('No hemos encontrado nada que encaje con «' . $q . '»', $r->body, $q);
        notContains('af-product-card', $r->body, $q . ': sin rejilla');
        notContains('af-listing-results', $r->body, $q);
        contains('<meta name="robots" content="noindex, follow">', $r->body);
        contains('Mira la tienda', $r->body, 'con salidas: las categorías');
        contains('>Sillas</a>', $r->body, 'una categoría de la tienda');
        contains('name="q" value="' . $q . '"', $r->body, 'y el formulario para buscar otra vez, con lo escrito');
        same(1, substr_count($r->body, '<h1'));
        searchCheckPage($r->body, $q);
    }
});

test('lo más buscado con resultado sale como salida cuando no se encuentra nada', function (): void {
    $app = searchApp();
    searchShop($app);
    $web = new TestClient($app);
    $web->get('/buscar?q=silla');
    $web->get('/buscar?q=silla');
    $web->get('/buscar?q=mesa');
    $r = $web->get('/buscar?q=zapatillas');
    contains('Búsquedas populares', $r->body);
    contains('href="/buscar?q=silla"', $r->body);
    contains('href="/buscar?q=mesa"', $r->body);
    notContains('href="/buscar?q=zapatillas"', $r->body, 'lo que no se encontró no se ofrece como salida');
});

test('una palabra larguísima se recorta a 80 caracteres y lo que escribe quien busca sale escapado', function (): void {
    $app = searchApp();
    searchShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar?q=' . str_repeat('a', 300));
    same(200, $r->status);
    contains('Resultados para ' . str_repeat('a', 80) . '</h1>', $r->body);
    notContains(str_repeat('a', 81), $r->body);

    $q = '<script>alert(1)</script> "a" & b';
    $r = $web->get('/buscar?q=' . rawurlencode($q));
    same(200, $r->status);
    notContains('<script>alert(1)', $r->body, 'el HTML de quien busca no entra sin escapar');
    contains('&lt;script&gt;alert(1)&lt;/script&gt; &quot;a&quot; &amp; b', $r->body);
    searchCheckPage($r->body, 'búsqueda con HTML');
});

test('el JSON de sugerencias: por prefijo, con imagen y precio, hasta ocho, y cacheable en el servidor', function (): void {
    $app = searchApp();
    $ids = searchShop($app);
    $web = new TestClient($app);
    $r = $web->get('/buscar/sugerencias.json?q=sil');
    same(200, $r->status);
    contains('application/json', $r->headers['Content-Type']);
    contains('no-store', $r->headers['Cache-Control']);
    same(60, $r->cacheTtl, 'el servidor la guarda 60 segundos');
    $json = $web->json();
    same('sil', $json['q']);
    $labels = array_map(static function (array $item): string {
        return $item['label'];
    }, $json['items']);
    check(in_array('Silla roja', $labels, true) && in_array('Sillón orejero', $labels, true), 'sillas y sillón');
    foreach ($json['items'] as $item) {
        if ($item['label'] === 'Silla roja') {
            same('product', $item['type']);
            same('/producto/silla-roja', $item['url']);
            check(is_string($item['image']) && $item['image'] !== '', 'con imagen');
            check(preg_match('/^\d+,\d\d\x{a0}€$/u', (string) $item['price']) === 1, 'y precio con su formato: ' . json_encode($item['price']));
        }
    }
    same(['q' => 'x', 'items' => []], (function () use ($web): array {
        $web->get('/buscar/sugerencias.json?q=x');

        return $web->json();
    })(), 'con menos de dos letras no se sugiere nada');
    unset($ids);
});

test('las páginas del buscador no llevan href vacío ni direcciones de otro dominio', function (): void {
    $app = searchApp();
    searchShop($app);
    $web = new TestClient($app);
    foreach (['/buscar', '/buscar?q=silla', '/buscar?q=zzzz', '/buscar?q=silla&f[color][]=rojo', '/buscar?q=slla'] as $uri) {
        $r = $web->get($uri);
        same(200, $r->status, $uri);
        searchCheckPage($r->body, $uri);
    }
});
