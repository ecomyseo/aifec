<?php
/**
 * AIFEC — pruebas de lo que el catálogo pone en la portada (Destacados, Categorías, Novedades y Ofertas, cada una solo si tiene
 * algo y en su orden) y en el menú principal (las categorías de primer nivel que están en el menú, con sus hijas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

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

/**
 * Los identificadores de las secciones que pinta la portada, en su orden.
 *
 * @return string[]
 */
function frontHomeSections(string $html): array
{
    preg_match_all('#<div class="af-home-section af-container" data-section="([^"]+)">#', $html, $m);

    return $m[1];
}

test('portada: «Productos destacados» sale con productos destacados y no sale sin ellos', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $web = new TestClient($app);
    $empty = $web->get('/');
    same(200, $empty->status);
    notContains('Productos destacados', $empty->body);
    same([], frontHomeSections($empty->body), 'una tienda vacía no enseña ninguna sección del catálogo');

    aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo', 'featured' => 1, 'price' => 5990]);
    $app->invalidate(['catalog']);
    $r = $web->get('/');
    contains('<h2 id="af-catalog-featured-title">Productos destacados</h2>', $r->body);
    check(preg_match('#data-section="catalog\.featured">.*?<h3 class="af-product-card-name">Silla Ergo</h3>#s', $r->body) === 1, 'la tarjeta del destacado');
    contains('aria-labelledby="af-catalog-featured-title"', $r->body);
    same(1, substr_count($r->body, '<h1'), 'un solo h1 en la portada');
});

test('portada: Novedades y Ofertas, con hasta 8 productos cada una y solo lo que toca', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    for ($i = 1; $i <= 10; ++$i) {
        aifecProduct($app, ['name' => 'Producto ' . $i, 'slug' => 'producto-' . $i, 'price' => 1000 + $i, 'published_at' => '2026-11-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . ' 00:00:00']);
    }
    aifecProduct($app, ['name' => 'En rebajas', 'slug' => 'en-rebajas', 'price' => 8000, 'sale_price' => 6000, 'published_at' => '2026-10-01 00:00:00']);
    $web = new TestClient($app);
    $body = $web->get('/')->body;
    check(preg_match('#data-section="catalog\.new">(.*?)</section>#s', $body, $new) === 1, 'sección de novedades');
    same(8, count(frontCardNames($new[1])), 'hasta 8');
    contains('Producto 10', $new[1], 'las más nuevas primero');
    notContains('En rebajas', $new[1]);
    check(preg_match('#data-section="catalog\.sale">(.*?)</section>#s', $body, $sale) === 1, 'sección de ofertas');
    same(['En rebajas'], frontCardNames($sale[1]), 'solo lo que está en oferta');
    contains('<h2 id="af-catalog-sale-title">En oferta</h2>', $body);
    notContains('data-section="catalog.featured"', $body, 'sin destacados no hay sección');
});

test('portada: Categorías solo con las de primer nivel que tienen imagen', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    aifecCategory($app, 'Sin foto');
    $root = aifecCategory($app, 'Con foto', null, ['media_id' => aifecMedia($app, 'Muebles')]);
    aifecCategory($app, 'Hija con foto', $root, ['media_id' => aifecMedia($app, 'Hija')]);
    $web = new TestClient($app);
    $body = $web->get('/')->body;
    check(preg_match('#data-section="catalog\.categories">(.*?)</section>#s', $body, $m) === 1, 'sección de categorías');
    contains('<h2 id="af-catalog-categories-title">Compra por categoría</h2>', $m[1]);
    contains('href="/categoria/con-foto"', $m[1]);
    notContains('Sin foto', $m[1], 'una categoría sin imagen no sale');
    notContains('Hija con foto', $m[1], 'solo las de primer nivel');
    notContains('af-noimage', $m[1], 'con su imagen');

    $plain = catalogApp([], ['catalog', 'media']);
    aifecCategory($plain, 'Sin foto');
    notContains('data-section="catalog.categories"', (new TestClient($plain))->get('/')->body, 'sin ninguna con imagen no hay sección');
});

test('portada: las secciones salen en su orden (20, 30, 40, 50)', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    aifecCategory($app, 'Con foto', null, ['media_id' => aifecMedia($app, 'Muebles')]);
    aifecProduct($app, ['name' => 'Destacado', 'slug' => 'destacado', 'featured' => 1]);
    aifecProduct($app, ['name' => 'En rebajas', 'slug' => 'en-rebajas', 'price' => 8000, 'sale_price' => 6000]);
    $web = new TestClient($app);
    same(['catalog.featured', 'catalog.categories', 'catalog.new', 'catalog.sale'], frontHomeSections($web->get('/')->body));
});

test('la portada se guarda en la caché de página con la etiqueta «catalog»', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    aifecProduct($app, ['name' => 'Destacado', 'slug' => 'destacado', 'featured' => 1]);
    $web = new TestClient($app);
    $r = $web->get('/');
    check(in_array('catalog', $r->cacheTags, true), 'la portada lleva la etiqueta «catalog»');
});

test('menú principal: las categorías de primer nivel que están en el menú, con sus hijas', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $muebles = aifecCategory($app, 'Muebles');
    aifecCategory($app, 'Sillas', $muebles);
    $interna = aifecCategory($app, 'Interna');
    $hijaOculta = aifecCategory($app, 'Hija oculta', $muebles);
    // Con dos principales el menú las enseña a ellas (con una sola, sus hijas: ver StoreUxTest).
    aifecCategory($app, 'Lámparas');
    $app->db()->update('categories', ['in_menu' => 0], ['id' => $interna]);
    $app->db()->update('categories', ['in_menu' => 0], ['id' => $hijaOculta]);
    $app->invalidate(['catalog']);
    $items = $app->hooks()->apply('menu.items', [], 'main', $app);
    same(['Muebles', 'Lámparas'], array_map(static function (array $item): string {
        return $item['label'];
    }, $items), 'solo las de primer nivel que están en el menú');
    same('/categoria/muebles', $items[0]['url']);
    same(['Sillas'], array_map(static function (array $item): string {
        return $item['label'];
    }, $items[0]['children']), 'con sus hijas que también están en el menú');
    same(false, $items[0]['current']);

    $web = new TestClient($app);
    $body = $web->get('/')->body;
    contains('<a class="af-nav-link" href="/categoria/muebles">Muebles</a>', $body, 'el tema pinta el menú de serie');
    contains('href="/categoria/muebles/sillas"', $body);
    notContains('Interna', $body);
    contains('aria-current="page"', $web->get('/categoria/muebles')->body, 'la categoría en la que estás va marcada');
});

test('menú principal: si alguien ya ha aportado elementos, o es otra ubicación, no se toca', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    aifecCategory($app, 'Muebles');
    $own = [['label' => 'Mío', 'url' => '/mio', 'current' => false, 'children' => []]];
    same($own, $app->hooks()->apply('menu.items', $own, 'main', $app), 'el menú del comerciante manda');
    same([], $app->hooks()->apply('menu.items', [], 'footer', $app), 'el pie no lo toca');
    same([], $app->hooks()->apply('menu.items', [], 'legal', $app));
    $noMenu = catalogApp([], ['catalog', 'media']);
    same([], $noMenu->hooks()->apply('menu.items', [], 'main', $noMenu), 'sin categorías no hay nada que aportar');
});
