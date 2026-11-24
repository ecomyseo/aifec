<?php
/**
 * AIFEC — pruebas de los listados de la tienda (categoría y marca): productos de la categoría y de sus hijas, cada filtro por
 * dirección, el orden, la paginación con su canónica, lo que se indexa y lo que no, las facetas con sus cuentas, el panel de
 * filtros del móvil, las direcciones antiguas y la caché de página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Modules\Catalog\Front\ListingQuery;
use Aifec\Modules\Catalog\Front\Support;

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
            // La única dirección de fuera es el aviso de origen que exige la licencia («Creado con AIFEC»).
            if ($host === 'aifec.gmartos.es') {
                continue;
            }
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

/**
 * La tienda de las pruebas de listados:
 *
 *   Muebles ─ Sillas
 *   p1 Silla Roja   50,00 €  Sillas   marca Nórdica  color rojo  10 uds  posición 1
 *   p2 Silla Azul   80,00 € → 60,00 € (oferta en marcha)  Sillas  marca Nórdica  color azul  0 uds  posición 2
 *   p3 Mesa Roble  120,00 €  Muebles  marca Zeta  sin color  5 uds  posición 3
 *
 * @return array<string,int>
 */
function frontListingShop(App $app): array
{
    $ids = [];
    $ids['muebles'] = aifecCategory($app, 'Muebles', null, ['description' => '<p>Todo para <b>casa</b></p>']);
    $ids['sillas'] = aifecCategory($app, 'Sillas', $ids['muebles']);
    $ids['nordica'] = aifecBrand($app, 'Nórdica', ['description' => 'Diseño del norte']);
    $ids['zeta'] = aifecBrand($app, 'Zeta');
    aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo', 'azul' => 'Azul']);
    $ids['p1'] = aifecProduct($app, [
        'name' => 'Silla Roja', 'slug' => 'silla-roja', 'price' => 5000, 'categories' => [$ids['sillas']], 'brand_id' => $ids['nordica'],
        'attributes' => ['color' => 'rojo'], 'stock' => 10, 'position' => 1,
    ]);
    $ids['p2'] = aifecProduct($app, [
        'name' => 'Silla Azul', 'slug' => 'silla-azul', 'price' => 8000, 'sale_price' => 6000, 'categories' => [$ids['sillas']],
        'brand_id' => $ids['nordica'], 'attributes' => ['color' => 'azul'], 'stock' => 0, 'position' => 2,
    ]);
    $ids['p3'] = aifecProduct($app, [
        'name' => 'Mesa Roble', 'slug' => 'mesa-roble', 'price' => 12000, 'categories' => [$ids['muebles']], 'brand_id' => $ids['zeta'],
        'stock' => 5, 'position' => 3,
    ]);

    return $ids;
}

if (!function_exists('frontCardNames')) {
    /**
     * Los nombres de las tarjetas que pinta una página, en el orden del HTML.
     *
     * @return string[]
     */
    function frontCardNames(string $html): array
    {
        preg_match_all('#<h3 class="af-product-card-name">([^<]*)</h3>#', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }
}

test('categoría: sus productos y los de sus hijas, un solo h1, la descripción y las subcategorías como accesos', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontListingShop($app);
    $web = new TestClient($app);
    $r = $web->get('/categoria/muebles');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'), 'un solo h1');
    contains('<h1 class="af-title">Muebles</h1>', $r->body);
    same(['Silla Roja', 'Silla Azul', 'Mesa Roble'], frontCardNames($r->body), 'los de la categoría y los de su hija, por posición');
    contains('<p>Todo para <b>casa</b></p>', $r->body, 'la descripción de la categoría');
    check(preg_match('#<nav class="af-row" aria-label="Subcategorías">.*?href="/categoria/muebles/sillas"[^>]*>Sillas#s', $r->body) === 1, 'la subcategoría es un acceso');
    contains('3 productos', $r->body, 'el número de productos');
    contains('<link rel="canonical" href="https://tienda.test/categoria/muebles">', $r->body);
    contains('index, follow', $r->body);
    contains('af-type-category', $r->body);
    same(['Silla Roja', 'Silla Azul'], frontCardNames($web->get('/categoria/muebles/sillas')->body), 'la hija solo lleva los suyos');
});

test('cada filtro por dirección: f[color][], min, max, stock y sale', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontListingShop($app);
    $web = new TestClient($app);
    same(['Silla Roja'], frontCardNames($web->get('/categoria/muebles?f[color][]=rojo')->body), 'por atributo');
    same(['Silla Roja', 'Silla Azul'], frontCardNames($web->get('/categoria/muebles?f[color][]=rojo&f[color][]=azul')->body), 'varios valores del mismo atributo: cualquiera');
    same(['Silla Azul'], frontCardNames($web->get('/categoria/muebles?min=55,00&max=70')->body), 'precio mínimo y máximo, con coma decimal, sobre el precio vigente');
    same(['Mesa Roble'], frontCardNames($web->get('/categoria/muebles?min=100')->body), 'solo mínimo');
    same(['Silla Roja', 'Silla Azul'], frontCardNames($web->get('/categoria/muebles?max=79.99')->body), 'solo máximo, con punto decimal');
    same(['Silla Roja', 'Mesa Roble'], frontCardNames($web->get('/categoria/muebles?stock=1')->body), 'solo con existencias');
    same(['Silla Azul'], frontCardNames($web->get('/categoria/muebles?sale=1')->body), 'solo en oferta');
    same(['Silla Roja', 'Silla Azul', 'Mesa Roble'], frontCardNames($web->get('/categoria/muebles?min=abc&max=&stock=0&f[color][]=')->body), 'lo que no es un filtro válido se ignora');
    $marca = frontCardNames($web->get('/categoria/muebles?f[brand][]=' . (int) $app->db()->value('SELECT id FROM {brands} WHERE name = ?', ['Zeta']))->body);
    same(['Mesa Roble'], $marca, 'la marca elegida en las facetas');
});

test('el orden: cada opción del desplegable y el valor desconocido', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontListingShop($app);
    $web = new TestClient($app);
    same(['Mesa Roble', 'Silla Azul', 'Silla Roja'], frontCardNames($web->get('/categoria/muebles?sort=price_desc')->body));
    same(['Silla Roja', 'Silla Azul', 'Mesa Roble'], frontCardNames($web->get('/categoria/muebles?sort=price_asc')->body));
    $body = $web->get('/categoria/muebles?sort=price_desc')->body;
    check(preg_match('#<select id="af-sort" class="af-select" name="sort" form="af-filters-form" data-af-autosubmit>(.*?)</select>#s', $body, $m) === 1, 'el desplegable de orden, asociado al formulario de filtros');
    contains('<option value="price_desc" selected>', $m[1], 'el orden actual va marcado');
    same(6, substr_count($m[1], '<option'), 'una opción por orden que da el catálogo');
    $unknown = $web->get('/categoria/muebles?sort=inventado');
    same(200, $unknown->status);
    contains('index, follow', $unknown->body, 'un orden desconocido se queda en el de serie y se indexa');
});

test('paginación: la página 2 lleva «— página 2», su propia canónica y enlaces anterior y siguiente', function (): void {
    $app = catalogApp(['catalog.per_page' => 2], ['catalog', 'media']);
    frontListingShop($app);
    $web = new TestClient($app);
    $one = $web->get('/categoria/muebles');
    same(['Silla Roja', 'Silla Azul'], frontCardNames($one->body));
    contains('<link rel="canonical" href="https://tienda.test/categoria/muebles">', $one->body);
    contains('rel="next"', $one->body);
    notContains('— página', $one->body);
    contains('<p>Todo para <b>casa</b></p>', $one->body);

    $two = $web->get('/categoria/muebles?page=2');
    same(200, $two->status);
    same(['Mesa Roble'], frontCardNames($two->body));
    contains('<link rel="canonical" href="https://tienda.test/categoria/muebles?page=2">', $two->body);
    contains('<title>Muebles — página 2 · ', $two->body);
    contains('rel="prev"', $two->body);
    contains('index, follow', $two->body, 'la página 2 sin filtros se indexa');
    notContains('<p>Todo para <b>casa</b></p>', $two->body, 'la descripción solo va en la primera página');

    // Una página de más ya no repite la última con un 200 (duplicado sin fin, visita de SEO del 13/11/2026): lleva a ella con un 301.
    $past = $web->get('/categoria/muebles?page=99');
    same(301, $past->status, 'una página de más redirige');
    contains('page=2', (string) ($past->headers['Location'] ?? ''), 'a la última de verdad');
    contains('<link rel="canonical" href="https://tienda.test/categoria/muebles?page=2">', $web->get('/categoria/muebles?page=2')->body);
    $sorted = $web->get('/categoria/muebles?sort=price_asc')->body;
    contains('href="/categoria/muebles?sort=price_asc&amp;page=2"', $sorted, 'la paginación conserva el orden');
});

test('con filtros o con otro orden: noindex, follow y la canónica apunta a la dirección sin filtros', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontListingShop($app);
    $web = new TestClient($app);
    foreach (['?f[color][]=rojo', '?min=10', '?max=100', '?stock=1', '?sale=1', '?sort=price_asc', '?sort=newest&page=1'] as $query) {
        $body = $web->get('/categoria/muebles' . $query)->body;
        contains('<meta name="robots" content="noindex, follow">', $body, $query);
        contains('<link rel="canonical" href="https://tienda.test/categoria/muebles">', $body, $query . ': canónica sin filtros');
    }
    $app2 = catalogApp(['catalog.per_page' => 1], ['catalog', 'media']);
    frontListingShop($app2);
    $body = (new TestClient($app2))->get('/categoria/muebles?page=2&stock=1')->body;
    contains('<link rel="canonical" href="https://tienda.test/categoria/muebles">', $body, 'filtrada y en la página 2: canónica limpia');
});

test('sin resultados: mensaje con sugerencia y el enlace «Quitar filtros»; sin filtros, otro mensaje', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $ids = frontListingShop($app);
    aifecCategory($app, 'Vacía');
    $web = new TestClient($app);
    $r = $web->get('/categoria/muebles?min=9999');
    same(200, $r->status);
    contains('af-empty', $r->body);
    contains('No hay productos', $r->body);
    contains('Prueba a quitar algún filtro.', $r->body);
    contains('href="/categoria/muebles">Quitar filtros</a>', $r->body);
    contains('0 productos', $r->body);
    $empty = $web->get('/categoria/vacia');
    same(200, $empty->status);
    contains('Todavía no hay productos aquí.', $empty->body);
    notContains('Quitar filtros', $empty->body);
    check($ids['muebles'] > 0, 'tienda montada');
});

test('filtros: formulario GET con las facetas y sus cuentas, precio, existencias y oferta; lo elegido va marcado', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontListingShop($app);
    $web = new TestClient($app);
    $r = $web->get('/categoria/muebles?f[color][]=rojo&min=19,99');
    $body = $r->body;
    check(preg_match('#<aside class="af-listing-filters af-drawer" id="af-filters"#', $body) === 1, 'el panel de filtros es el af-drawer «af-filters»');
    contains('<form class="af-drawer-body" id="af-filters-form" method="get" action="/categoria/muebles">', $body);
    contains('Color', $body);
    contains('<span>Rojo <span class="af-muted af-small">(1)</span></span>', $body, 'la cuenta de la faceta');
    contains('<span>Azul <span class="af-muted af-small">(1)</span></span>', $body);
    contains('name="f[color][]" value="rojo" checked', $body, 'lo elegido va marcado');
    notContains('name="f[color][]" value="azul" checked', $body);
    contains('name="min" value="19,99"', $body, 'el precio mínimo se vuelve a escribir');
    contains('name="stock" value="1"', $body);
    notContains('name="stock" value="1" checked', $body);
    contains('name="sale" value="1"', $body);
    contains('name="stock" value="1" checked', $web->get('/categoria/muebles?stock=1')->body, 'solo con existencias, marcado');
    contains('name="sale" value="1" checked', $web->get('/categoria/muebles?sale=1')->body, 'en oferta, marcado');
    contains('>Aplicar</button>', $body);
    contains('Quitar filtros</a>', $body);
    contains('data-af-drawer-open="af-filters"', $body, 'la barra inferior del móvil lleva el botón Filtros');
    // Una faceta de marca con sus cuentas, y el campo q solo en la búsqueda.
    contains('name="f[brand][]"', $body);
    notContains('<input type="hidden" name="q"', $body);
});

test('dirección antigua de una categoría: 301 a la nueva, conservando lo que traiga', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $ids = frontListingShop($app);
    $app->slugs()->set('category', $ids['sillas'], $app->defaultLang(), 'categoria/muebles/asientos');
    $web = new TestClient($app);
    $r = $web->get('/categoria/muebles/sillas?sort=price_asc');
    same(301, $r->status);
    same('/categoria/muebles/asientos?sort=price_asc', $r->headers['Location']);
    same(200, $web->get('/categoria/muebles/asientos')->status);
});

test('una categoría que no existe o está desactivada es un 404', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    aifecCategory($app, 'Oculta', null, ['active' => 0]);
    $web = new TestClient($app);
    same(404, $web->get('/categoria/oculta')->status);
    same(404, $web->get('/categoria/no-existe')->status);
});

test('marca: sus productos, sin faceta de marca y sin descripción vacía; una marca desactivada es un 404', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $ids = frontListingShop($app);
    aifecBrand($app, 'Oculta', ['active' => 0]);
    $web = new TestClient($app);
    $r = $web->get('/marca/nordica');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'));
    contains('<h1 class="af-title">Nórdica</h1>', $r->body);
    same(['Silla Roja', 'Silla Azul'], frontCardNames($r->body), 'solo los de la marca');
    contains('Diseño del norte', $r->body);
    contains('af-type-brand', $r->body);
    notContains('name="f[brand][]"', $r->body, 'en la página de una marca no se filtra por marca');
    contains('<li aria-current="page">Nórdica</li>', $r->body);
    same(['Silla Roja'], frontCardNames($web->get('/marca/nordica?f[color][]=rojo')->body), 'los filtros valen en la marca');
    same(404, $web->get('/marca/oculta')->status);
    same(['Silla Roja', 'Silla Azul'], frontCardNames($web->get('/marca/nordica?f[brand][]=' . $ids['zeta'])->body), 'la marca de la página manda sobre lo que pida la dirección');
});

test('caché de página: la categoría 300 s con su etiqueta, «catalog» y «tax»; la marca con la suya', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $ids = frontListingShop($app);
    $web = new TestClient($app);
    $category = $web->get('/categoria/muebles');
    same(300, $category->cacheTtl);
    foreach (['category:' . $ids['muebles'], 'catalog', 'tax'] as $tag) {
        check(in_array($tag, $category->cacheTags, true), 'etiqueta ' . $tag);
    }
    same('MISS', $category->headers['X-Cache']);
    $brand = $web->get('/marca/nordica');
    same(300, $brand->cacheTtl);
    check(in_array('catalog', $brand->cacheTags, true) && in_array('brand:' . $ids['nordica'], $brand->cacheTags, true), 'la marca se invalida con «catalog» y la suya');
});

test('los listados no llevan href vacío, direcciones de otro dominio ni formularios POST sin csrf', function (): void {
    $app = catalogApp(['catalog.per_page' => 2], ['orders', 'media']);
    frontListingShop($app);
    $web = new TestClient($app);
    foreach (['/categoria/muebles', '/categoria/muebles?page=2', '/categoria/muebles?f[color][]=rojo&min=10', '/marca/nordica', '/categoria/muebles?min=9999'] as $uri) {
        $r = $web->get($uri);
        same(200, $r->status, $uri);
        frontCheckPage($app, $r->body, $uri);
    }
});

test('ListingQuery: lee y limpia lo que trae la dirección y la vuelve a escribir', function (): void {
    $request = Request::create('GET', '/x?sort=price_asc&page=3&f[color][]=rojo&f[color][]=azul&f[color][]=rojo&f[b%20a][]=1&f[ok][]=2&min=19,99&max=1.234,50&stock=1&sale=1');
    $query = ListingQuery::fromRequest($request);
    same('price_asc', $query->sort);
    same(3, $query->page);
    same(['color' => ['rojo', 'azul'], 'ok' => ['2']], $query->filters, 'sin repetidos y sin códigos con espacios');
    same(1999, $query->min);
    same(123450, $query->max);
    same(true, $query->stock);
    same(true, $query->sale);
    check($query->hasFilters() && $query->isFiltered(), 'hay filtros');
    same('/c?sort=price_asc&f[color][]=rojo&f[color][]=azul&f[ok][]=2&min=19%2C99&max=1234%2C50&stock=1&sale=1&page=3', $query->url('/c'));
    same('/c?sort=price_asc&f[color][]=rojo&f[color][]=azul&f[ok][]=2&min=19%2C99&max=1234%2C50&stock=1&sale=1&page=5', $query->url('/c', 5));
    same('/c', $query->url('/c', null, true), 'la dirección limpia');
    $catalog = $query->toCatalog();
    same(['color' => ['rojo', 'azul'], 'ok' => ['2']], $catalog['filters']);
    same(1999, $catalog['price_min']);
    same(123450, $catalog['price_max']);
    same(true, $catalog['in_stock']);
    same(true, $catalog['on_sale']);
    same(3, $catalog['page']);

    $empty = ListingQuery::fromRequest(Request::create('GET', '/x?sort=%3Cb%3E&page=-4&f=hola&min=-5&max=abc&stock=yes'));
    same(ListingQuery::DEFAULT_SORT, $empty->sort, 'un orden con caracteres raros se descarta');
    same(1, $empty->page);
    same([], $empty->filters);
    same(null, $empty->min);
    same(null, $empty->max);
    same(false, $empty->stock);
    check(!$empty->hasFilters() && !$empty->isFiltered(), 'nada que filtrar');
    same('/c', $empty->url('/c'));

    $search = new ListingQuery();
    $search->q = 'silla & mesa';
    same('/buscar?q=silla%20%26%20mesa', $search->url('/buscar'));
    $brand = ListingQuery::fromRequest(Request::create('GET', '/x?f[brand][]=7&f[color][]=rojo'));
    $toCatalog = $brand->toCatalog();
    same(7, $toCatalog['brand_id'], 'la marca de las facetas pasa a brand_id');
    same(['color' => ['rojo']], $toCatalog['filters']);
});

test('importes escritos por una persona: coma o punto, millares, símbolo y lo que no vale', function (): void {
    $app = catalogApp([], ['catalog']);
    same(1999, Support::cents('19,99'));
    same(1999, Support::cents('19.99'));
    same(2000, Support::cents('20'));
    same(2000, Support::cents(' 20 € '));
    same(123450, Support::cents('1.234,50'));
    same(123450, Support::cents('1,234.50'));
    same(1990, Support::cents('19,9'));
    same(1999, Support::cents('19,999'), 'los céntimos de más se cortan');
    same(0, Support::cents('0'));
    same(null, Support::cents(''));
    same(null, Support::cents('abc'));
    same(null, Support::cents('-5'));
    same(null, Support::cents('1,2,3'));
    same(null, Support::cents('99999999999'));
    same('19,99', Support::formatCents(1999));
    same('20', Support::formatCents(2000));
    same('0,05', Support::formatCents(5));
    same('', Support::delivery(null));
    same('', Support::delivery([0, 0]));
    same('Entrega en 1 día', Support::delivery([1, 1]));
    same('Entrega en 3 días', Support::delivery([3, 3]));
    same('Entrega en 2 a 4 días', Support::delivery([2, 4]));
    check($app->has('catalog'), 'servicio publicado');
});
