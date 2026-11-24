<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Pruebas de los mapas del sitio: índice, partes por tipo, qué entra y qué no, tramos de 1.000 y alternativas de idioma.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Las direcciones (<loc>) de un mapa, en su orden.
 *
 * @return string[]
 */
function seoLocs(string $xml): array
{
    $doc = simplexml_load_string($xml);
    check($doc !== false, 'el XML es válido');
    $locs = [];
    foreach ($doc->url as $url) {
        $locs[] = (string) $url->loc;
    }

    return $locs;
}

/**
 * Cada <url> de un mapa con sus alternativas de idioma: [['loc' => …, 'links' => [hreflang => href]], …].
 *
 * @return array<int,array{loc:string,links:array<string,string>}>
 */
function seoAlternates(string $xml): array
{
    $dom = new DOMDocument();
    check($dom->loadXML($xml), 'el XML es válido');
    $out = [];
    foreach ($dom->getElementsByTagName('url') as $url) {
        $links = [];
        foreach ($url->getElementsByTagNameNS('http://www.w3.org/1999/xhtml', 'link') as $link) {
            $links[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }
        $loc = $url->getElementsByTagName('loc')->item(0);
        $out[] = ['loc' => $loc !== null ? $loc->textContent : '', 'links' => $links];
    }

    return $out;
}

test('/sitemap.xml es un XML válido: un índice con una parte por cada tipo que tiene contenido', function (): void {
    $app = seoApp(['seo', 'catalog', 'cms', 'media']);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    aifecCategory($app, 'Muebles');
    aifecBrand($app, 'Nórdica');
    cmsInsertPage($app, ['title' => 'Quiénes somos']);
    cmsInsertPost($app, ['title' => 'Primera entrada']);
    $r = (new TestClient($app))->get('/sitemap.xml');
    same(200, $r->status);
    contains('application/xml', $r->contentType());
    $doc = simplexml_load_string($r->body);
    check($doc !== false && $doc->getName() === 'sitemapindex', 'es un sitemapindex');
    $parts = [];
    foreach ($doc->sitemap as $s) {
        $parts[] = (string) $s->loc;
    }
    same([
        'https://tienda.test/sitemap-pages-1.xml', 'https://tienda.test/sitemap-posts-1.xml', 'https://tienda.test/sitemap-products-1.xml',
        'https://tienda.test/sitemap-categories-1.xml', 'https://tienda.test/sitemap-brands-1.xml',
    ], $parts);
});

test('el índice solo trae las partes que existen y siempre la de páginas (la portada)', function (): void {
    $app = seoApp(['seo']);
    $doc = simplexml_load_string((new TestClient($app))->get('/sitemap.xml')->body);
    check($doc !== false, 'XML válido sin catálogo ni contenidos');
    same(1, count($doc->sitemap), 'solo la de páginas');
    $web = new TestClient($app);
    same(['https://tienda.test/'], seoLocs($web->get('/sitemap-pages-1.xml')->body), 'la portada');
    same(404, $web->get('/sitemap-products-1.xml')->status, 'sin catálogo no hay mapa de productos');
});

test('un borrador, un producto oculto y un contenido «no indexar» no salen en el mapa', function (): void {
    $app = seoApp(['seo', 'catalog', 'cms', 'media']);
    aifecProduct($app, ['name' => 'Publicado', 'slug' => 'publicado']);
    aifecProduct($app, ['name' => 'Borrador', 'slug' => 'borrador', 'status' => 'draft']);
    aifecProduct($app, ['name' => 'Oculto', 'slug' => 'oculto', 'visibility' => 'hidden']);
    cmsInsertPage($app, ['title' => 'Visible']);
    cmsInsertPage($app, ['title' => 'Borrador de página', 'status' => 'draft']);
    $privada = cmsInsertPage($app, ['title' => 'Privada']);
    $app->db()->update('pages', ['noindex' => 1], ['id' => $privada]);
    cmsInsertPage($app, ['title' => 'Programada', 'status' => 'scheduled', 'published_at' => '2027-01-01 00:00:00']);
    $post = cmsInsertPost($app, ['title' => 'Entrada oculta']);
    $app->db()->update('posts', ['noindex' => 1], ['id' => $post]);
    $web = new TestClient($app);
    same(['https://tienda.test/producto/publicado'], seoLocs($web->get('/sitemap-products-1.xml')->body));
    same(['https://tienda.test/', 'https://tienda.test/visible'], seoLocs($web->get('/sitemap-pages-1.xml')->body), 'portada y la única página visible e indexable');
    same(404, $web->get('/sitemap-posts-1.xml')->status, 'la única entrada es «no indexar»: no hay mapa');
});

test('las páginas de contenido llamadas «Carrito», «Finalizar compra» o «Mi cuenta» no salen en el mapa: son privadas', function (): void {
    // Auditoría del 24/11/2026: WooCommerce deja esas tres como páginas y salían en sitemap-pages aunque se sirven con «noindex».
    $app = seoApp(['seo', 'cms', 'media']);
    cmsInsertPage($app, ['title' => 'Carrito', 'slug' => 'carrito']);
    cmsInsertPage($app, ['title' => 'Finalizar compra', 'slug' => 'finalizar-compra']);
    cmsInsertPage($app, ['title' => 'Mi cuenta', 'slug' => 'mi-cuenta']);
    cmsInsertPage($app, ['title' => 'Quiénes somos', 'slug' => 'quienes-somos']);
    $locs = seoLocs((new TestClient($app))->get('/sitemap-pages-1.xml')->body);
    same(['https://tienda.test/', 'https://tienda.test/quienes-somos'], $locs, 'solo la portada y la página pública');
});

test('cada dirección lleva su fecha de modificación', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    $body = (new TestClient($app))->get('/sitemap-products-1.xml')->body;
    contains('<lastmod>2026-11-12T12:00:00+00:00</lastmod>', $body);
});

test('la portada lleva su fecha y la página que hace de portada no sale otra vez con su dirección', function (): void {
    $app = seoApp(['seo', 'catalog', 'cms', 'media']);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    $home = cmsInsertPage($app, ['title' => 'Inicio']);
    $app->db()->update('pages', ['is_home' => 1, 'updated_at' => '2026-11-13 09:45:01'], ['id' => $home]);
    cmsInsertPage($app, ['title' => 'Contacto']);
    $body = (new TestClient($app))->get('/sitemap-pages-1.xml')->body;
    same(['https://tienda.test/', 'https://tienda.test/contacto'], seoLocs($body), 'sin /inicio');
    contains("<loc>https://tienda.test/</loc>\n    <lastmod>2026-11-13T09:45:01+00:00</lastmod>", $body, 'la fecha más reciente de su página y los productos');
});

test('1.001 productos se parten en dos ficheros de 1.000 y 1', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    seoBulkProducts($app, 1001);
    $web = new TestClient($app);
    $index = simplexml_load_string($web->get('/sitemap.xml')->body);
    $locs = [];
    foreach ($index->sitemap as $s) {
        $locs[] = (string) $s->loc;
    }
    check(in_array('https://tienda.test/sitemap-products-1.xml', $locs, true) && in_array('https://tienda.test/sitemap-products-2.xml', $locs, true), 'el índice lista las dos partes');
    check(!in_array('https://tienda.test/sitemap-products-3.xml', $locs, true), 'y no una tercera');
    $first = seoLocs($web->get('/sitemap-products-1.xml')->body);
    $second = seoLocs($web->get('/sitemap-products-2.xml')->body);
    same(1000, count($first));
    same(1, count($second));
    same(1001, count(array_unique(array_merge($first, $second))), 'ninguna repetida');
    same(404, $web->get('/sitemap-products-3.xml')->status, 'más allá del último tramo no hay nada');
    same(404, $web->get('/sitemap-products-0.xml')->status, 'el tramo 0 tampoco');
});

test('con dos idiomas cada contenido sale en cada idioma con sus alternativas y x-default', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], [], true);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'i18n' => ['en' => ['name' => 'Chair']]]);
    aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa']);
    $body = (new TestClient($app))->get('/sitemap-products-1.xml')->body;
    same([
        'https://tienda.test/producto/silla', 'https://tienda.test/en/producto/chair', 'https://tienda.test/producto/mesa',
    ], seoLocs($body), 'la silla en los dos idiomas y la mesa solo en uno');
    $entries = seoAlternates($body);
    same(['es' => 'https://tienda.test/producto/silla', 'en' => 'https://tienda.test/en/producto/chair', 'x-default' => 'https://tienda.test/producto/silla'], $entries[0]['links'], 'la silla en español');
    same($entries[0]['links'], $entries[1]['links'], 'y en inglés lleva las mismas alternativas');
    same([], $entries[2]['links'], 'sin traducción no hay alternativas');
    // La portada, en los dos idiomas.
    $pages = (new TestClient($app))->get('/sitemap-pages-1.xml')->body;
    same(['https://tienda.test/', 'https://tienda.test/en/'], seoLocs($pages));
});

test('los nombres con «&» y comillas salen escapados y el XML sigue siendo válido', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    aifecProduct($app, ['name' => 'Té & café', 'slug' => "a&b'c"]);
    $body = (new TestClient($app))->get('/sitemap-products-1.xml')->body;
    $locs = seoLocs($body);
    same(['https://tienda.test/producto/a&b\'c'], $locs);
    contains('a&amp;b&apos;c', $body);
});

test('los mapas se guardan en la caché de página y se vacían al guardar un contenido del panel', function (): void {
    $app = seoApp(['seo', 'catalog', 'cms', 'media']);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    $web = new TestClient($app);
    $r = $web->get('/sitemap-products-1.xml');
    same(3600, $r->cacheTtl);
    check(in_array('catalog', $r->cacheTags, true) && in_array('cms', $r->cacheTags, true), 'se invalida con «catalog» y «cms»');
    check(seoServe($app, '/sitemap-products-1.xml') !== null, 'la copia está guardada');
    $app->hooks()->fire('admin.resource.saved', 'products', 7);
    check(seoServe($app, '/sitemap-products-1.xml') === null, 'guardar un producto en el panel la vacía');
    $web->get('/sitemap.xml');
    check(seoServe($app, '/sitemap.xml') !== null, 'el índice también se guarda');
    $app->hooks()->fire('admin.resource.deleted', 'pages', 3);
    check(seoServe($app, '/sitemap.xml') === null, 'y se vacía al borrar una página');
});
