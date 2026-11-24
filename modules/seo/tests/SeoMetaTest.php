<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Pruebas del filtro «page.meta» sobre las páginas reales de la tienda: plantillas de título y de descripción, idiomas
 * alternativos, «noindex» y códigos de verificación.
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('el título de un producto sale de la plantilla «{name} | {shop}»', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], ['store.name' => 'Muebles Ana', 'seo.title.product' => '{name} | {shop}']);
    aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo']);
    $r = (new TestClient($app))->get('/producto/silla-ergo');
    same(200, $r->status);
    same('Silla Ergo | Muebles Ana', seoTitle($r->body), 'el nombre de la tienda no se añade dos veces');
    contains('<meta property="og:title" content="Silla Ergo | Muebles Ana">', $r->body);
});

test('toda página lleva la tarjeta de Twitter y, sin imagen propia, no se inventa og:image si la tienda no tiene logo ni icono', function (): void {
    // Auditoría del 24/11/2026: 70 páginas sin twitter:card ni og:image. La tarjeta va siempre; la imagen de la tienda, si existe.
    $app = seoApp(['seo', 'catalog', 'media'], ['store.name' => 'Muebles Ana']);
    aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo']);
    $body = (new TestClient($app))->get('/producto/silla-ergo')->body;
    contains('<meta name="twitter:card" content="summary">', $body, 'sin imagen: tarjeta pequeña');
    notContains('og:image', $body, 'y sin logo ni icono no hay imagen que poner');
});

test('una plantilla no pisa un meta título escrito a mano', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], ['store.name' => 'Muebles Ana', 'seo.title.product' => '{name} | {shop}']);
    aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo', 'meta_title' => 'La mejor silla de oficina']);
    $r = (new TestClient($app))->get('/producto/silla-ergo');
    same('La mejor silla de oficina · Muebles Ana', seoTitle($r->body), 'se queda el del comerciante, con el comportamiento de siempre');
});

test('la plantilla de descripción usa las variables del producto y no pisa la descripción propia', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], ['seo.description.product' => '{name} en {category} por {price}.']);
    $sillas = aifecCategory($app, 'Sillas');
    aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo', 'price' => 5990, 'categories' => [$sillas], 'summary' => 'Resumen corto']);
    aifecProduct($app, ['name' => 'Silla Propia', 'slug' => 'silla-propia', 'meta_description' => 'Descripción escrita a mano']);
    $web = new TestClient($app);
    $body = $web->get('/producto/silla-ergo')->body;
    // Los espacios (también el que separa el importe del símbolo) salen normales: una descripción no necesita espacios duros.
    contains('<meta name="description" content="Silla Ergo en Sillas por ' . e((string) preg_replace('/\s+/u', ' ', money(5990))) . '.">', $body);
    $own = $web->get('/producto/silla-propia')->body;
    contains('<meta name="description" content="Descripción escrita a mano">', $own, 'la descripción propia se respeta');
});

test('una variable que el producto no tiene no deja separadores colgando en el título', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], ['store.name' => 'Muebles Ana', 'seo.title.product' => '{name} | {brand} | {shop}']);
    aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo']);
    $marca = aifecBrand($app, 'Nórdica');
    aifecProduct($app, ['name' => 'Silla Nórdica', 'slug' => 'silla-nordica', 'brand_id' => $marca]);
    $web = new TestClient($app);
    same('Silla Ergo | Muebles Ana', seoTitle($web->get('/producto/silla-ergo')->body), 'sin marca');
    same('Silla Nórdica | Nórdica | Muebles Ana', seoTitle($web->get('/producto/silla-nordica')->body), 'con marca');
});

test('un título de plantilla se recorta a 65 caracteres sin partir palabras', function (): void {
    $name = 'Silla ergonómica de oficina con reposabrazos regulables y respaldo de malla transpirable';
    $app = seoApp(['seo', 'catalog', 'media'], ['store.name' => 'Muebles Ana', 'seo.title.product' => '{name} | {shop}']);
    aifecProduct($app, ['name' => $name, 'slug' => 'silla-larga']);
    $title = seoTitle((new TestClient($app))->get('/producto/silla-larga')->body);
    check(mb_strlen($title) <= 65, 'como mucho 65: ' . mb_strlen($title));
    check($title !== '' && strpos($name . ' | Muebles Ana', $title) === 0, 'es el principio del título completo');
    $next = mb_substr($name . ' | Muebles Ana', mb_strlen($title), 1);
    check($next === ' ', 'el corte cae entre palabras');
});

test('el producto con dos idiomas lleva sus «hreflang» y «x-default»', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], [], true);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'i18n' => ['en' => ['name' => 'Chair']]]);
    $body = (new TestClient($app))->get('/producto/silla')->body;
    contains('<link rel="alternate" hreflang="es" href="https://tienda.test/producto/silla">', $body);
    contains('<link rel="alternate" hreflang="en" href="https://tienda.test/en/producto/chair">', $body);
    contains('<link rel="alternate" hreflang="x-default" href="https://tienda.test/producto/silla">', $body, 'x-default es el idioma principal');
});

test('un producto sin traducir no lleva «hreflang» y la portada los lleva todos', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], [], true);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    $web = new TestClient($app);
    notContains('<link rel="alternate" hreflang=', $web->get('/producto/silla')->body, 'una sola versión: nada que alternar');
    $home = $web->get('/')->body;
    contains('<link rel="alternate" hreflang="es" href="https://tienda.test/">', $home);
    contains('<link rel="alternate" hreflang="en" href="https://tienda.test/en/">', $home);
    contains('<link rel="alternate" hreflang="x-default" href="https://tienda.test/">', $home);
});

test('con un solo idioma no hay «hreflang»', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    notContains('<link rel="alternate" hreflang=', (new TestClient($app))->get('/producto/silla')->body);
});

test('la portada respeta el título que ya fija el comerciante y si no, usa la plantilla', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], ['store.name' => 'Muebles Ana', 'seo.title.home' => '{shop} · Tienda de muebles']);
    same('Muebles Ana · Tienda de muebles', seoTitle((new TestClient($app))->get('/')->body));
    $own = seoApp(['seo', 'catalog', 'media'], ['store.name' => 'Muebles Ana', 'seo.title.home' => '{shop} · Tienda de muebles', 'seo.home_title' => 'Mi portada']);
    same('Mi portada', seoTitle((new TestClient($own))->get('/')->body), 'seo.home_title manda sobre la plantilla');
});

test('con el sitio sin indexar todas las páginas llevan noindex', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], ['seo.noindex_site' => true]);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    $web = new TestClient($app);
    contains('<meta name="robots" content="noindex, follow">', $web->get('/producto/silla')->body);
    contains('<meta name="robots" content="noindex, follow">', $web->get('/')->body);
    $open = seoApp(['seo', 'catalog', 'media']);
    aifecProduct($open, ['name' => 'Silla', 'slug' => 'silla']);
    notContains('noindex', (new TestClient($open))->get('/producto/silla')->body, 'sin el interruptor, indexable');
});

test('las páginas de un listado más allá de la última indexable llevan noindex', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], ['catalog.per_page' => 1, 'seo.max_indexed_page' => 2]);
    $cat = aifecCategory($app, 'Muebles');
    foreach (['Uno', 'Dos', 'Tres'] as $name) {
        aifecProduct($app, ['name' => $name, 'categories' => [$cat]]);
    }
    $web = new TestClient($app);
    notContains('noindex', $web->get('/categoria/muebles?page=2')->body, 'la página 2 se indexa');
    contains('<meta name="robots" content="noindex, follow">', $web->get('/categoria/muebles?page=3')->body, 'la 3 no');
    $free = seoApp(['seo', 'catalog', 'media'], ['catalog.per_page' => 1]);
    $cat2 = aifecCategory($free, 'Muebles');
    foreach (['Uno', 'Dos', 'Tres'] as $name) {
        aifecProduct($free, ['name' => $name, 'categories' => [$cat2]]);
    }
    notContains('noindex', (new TestClient($free))->get('/categoria/muebles?page=3')->body, 'con 0 no hay límite');
});

test('los códigos de verificación salen en el head y un código raro guardado a mano no sale', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], ['seo.verify_google' => 'abc123XYZ_-789', 'seo.verify_bing' => 'F00BA4F00BA4F00BA4F00BA4F00BA4AA']);
    $body = (new TestClient($app))->get('/')->body;
    contains('<meta name="google-site-verification" content="abc123XYZ_-789">', $body);
    contains('<meta name="msvalidate.01" content="F00BA4F00BA4F00BA4F00BA4F00BA4AA">', $body);
    $bad = seoApp(['seo', 'catalog', 'media'], ['seo.verify_google' => '"><script>alert(1)</script>', 'seo.verify_bing' => "abc\"def'123456"]);
    $html = (new TestClient($bad))->get('/')->body;
    notContains('google-site-verification', $html);
    notContains('msvalidate', $html);
    notContains('<script>alert(1)', $html);
});
