<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/*
 * Datos estructurados del resto de páginas: portada (organización y sitio), listados, migas, entradas y páginas.
 */

test('la portada lleva la organización con los datos de la tienda y el sitio con su buscador', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], [
        'store.name' => 'Muebles Norte',
        'store.legal_name' => 'Muebles Norte S.L.',
        'store.email' => 'tienda@ejemplo.test',
        'store.phone' => '999000001',
        'store.vat_id' => 'B12345678',
        'store.address' => "Calle Mayor 1\n35001 Las Palmas",
        'semantic.social' => "https://instagram.com/mueblesnorte\nno es una dirección\nhttps://www.linkedin.com/company/mueblesnorte",
    ]);
    // El logotipo es una fila de la biblioteca de medios: se crea en este mismo sitio.
    $app->settings()->set('store.logo', aifecMedia($app, 'Logo'));
    $web = new TestClient($app);

    $objects = semanticLd($web->get('/')->body);
    $org = semanticFind($objects, 'Organization');
    check($org !== null, 'hay una Organization');
    same('Muebles Norte', $org['name']);
    same('Muebles Norte S.L.', $org['legalName']);
    same('https://tienda.test/', $org['url']);
    same('tienda@ejemplo.test', $org['email']);
    same('999000001', $org['telephone']);
    same('B12345678', $org['vatID']);
    same('PostalAddress', $org['address']['@type']);
    same('Calle Mayor 1 35001 Las Palmas', $org['address']['streetAddress']);
    same('ES', $org['address']['addressCountry']);
    same(['https://instagram.com/mueblesnorte', 'https://www.linkedin.com/company/mueblesnorte'], $org['sameAs'], 'solo direcciones válidas');
    check(strpos($org['logo'], 'https://tienda.test/') === 0, 'el logotipo lleva la dirección completa: ' . $org['logo']);

    $site = semanticFind($objects, 'WebSite');
    check($site !== null, 'hay un WebSite');
    same('Muebles Norte', $site['name']);
    same('SearchAction', $site['potentialAction']['@type']);
    same('https://tienda.test/buscar?q={search_term_string}', $site['potentialAction']['target']['urlTemplate']);
    same($org['@id'], $site['publisher']['@id']);
});

test('la organización no lleva ninguna propiedad vacía: lo que no hay se omite', function (): void {
    $app = semanticApp();
    $web = new TestClient($app);

    $org = semanticFind(semanticLd($web->get('/')->body), 'Organization');
    same(['@context', '@type', '@id', 'name', 'url'], array_keys($org));
});

test('una categoría lleva un ItemList con las direcciones completas de sus productos, en orden', function (): void {
    $app = semanticApp();
    $sillas = aifecCategory($app, 'Sillas');
    foreach (['Silla uno', 'Silla dos', 'Silla tres'] as $i => $name) {
        aifecProduct($app, ['name' => $name, 'categories' => [$sillas], 'position' => $i]);
    }
    $web = new TestClient($app);

    $list = semanticFind(semanticLd($web->get('/categoria/sillas')->body), 'ItemList');
    check($list !== null, 'hay un ItemList');
    same(3, $list['numberOfItems']);
    same(3, count($list['itemListElement']));
    $expected = $app->service('catalog')->listing(['category_id' => $sillas]);
    foreach ($list['itemListElement'] as $i => $element) {
        same('ListItem', $element['@type']);
        same($i + 1, $element['position']);
        same('https://tienda.test' . $expected['items'][$i]['url'], $element['url']);
        same($expected['items'][$i]['name'], $element['name']);
    }
});

test('la segunda página de un listado sigue la numeración de la primera', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['catalog.per_page' => 2]);
    $sillas = aifecCategory($app, 'Sillas');
    for ($i = 1; $i <= 5; $i++) {
        aifecProduct($app, ['name' => 'Silla ' . $i, 'categories' => [$sillas]]);
    }
    $web = new TestClient($app);

    $list = semanticFind(semanticLd($web->get('/categoria/sillas?page=2')->body), 'ItemList');
    same(5, $list['numberOfItems']);
    same([3, 4], array_column($list['itemListElement'], 'position'));
});

test('las migas de la ficha son un BreadcrumbList con todos los niveles y direcciones completas', function (): void {
    $app = semanticApp();
    $muebles = aifecCategory($app, 'Muebles');
    $sillas = aifecCategory($app, 'Sillas', $muebles);
    semanticProduct($app, ['categories' => [$sillas]]);
    $web = new TestClient($app);

    $crumbs = semanticFind(semanticLd($web->get('/producto/silla')->body), 'BreadcrumbList');
    check($crumbs !== null, 'hay un BreadcrumbList');
    same(['Inicio', 'Muebles', 'Sillas', 'Silla de roble'], array_column($crumbs['itemListElement'], 'name'));
    same([1, 2, 3, 4], array_column($crumbs['itemListElement'], 'position'));
    foreach ($crumbs['itemListElement'] as $element) {
        check(strpos($element['item'], 'https://tienda.test/') === 0, 'la miga lleva la dirección completa: ' . $element['item']);
    }
    same('https://tienda.test/producto/silla', $crumbs['itemListElement'][3]['item']);
});

test('una entrada del blog lleva un BlogPosting con titular, fechas, autor, editor e imagen', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms']);
    $media = aifecMedia($app, 'Portada');
    $id = cmsInsertPost($app, ['title' => 'Cómo cuidar la madera', 'media_id' => $media, 'published_at' => '2026-11-10 09:30:00']);
    $web = new TestClient($app);

    $post = semanticFind(semanticLd($web->get('/blog/como-cuidar-la-madera')->body), 'BlogPosting');
    check($post !== null, 'hay un BlogPosting');
    same('Cómo cuidar la madera', $post['headline']);
    same('2026-11-10T09:30:00Z', $post['datePublished']);
    same('Organization', $post['author']['@type'], 'sin autor, la firma la tienda');
    same('AIFEC', $post['author']['name']);
    same('AIFEC', $post['publisher']['name']);
    same('https://tienda.test/blog/como-cuidar-la-madera', $post['mainEntityOfPage']['@id']);
    same(1, count($post['image']));
    check(strpos($post['image'][0], 'https://tienda.test/') === 0, 'la imagen lleva la dirección completa');
    same('es', $post['inLanguage']);
    check($id > 0, 'la entrada existe');
});

test('una página lleva un WebPage y el índice del blog un ItemList de entradas', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms']);
    cmsInsertPage($app, ['title' => 'Aviso legal']);
    cmsInsertPost($app, ['title' => 'Primera']);
    cmsInsertPost($app, ['title' => 'Segunda']);
    $web = new TestClient($app);

    $page = semanticFind(semanticLd($web->get('/aviso-legal')->body), 'WebPage');
    check($page !== null, 'hay un WebPage');
    same('Aviso legal', $page['name']);
    same('https://tienda.test/aviso-legal', $page['url']);
    same('https://tienda.test/#website', $page['isPartOf']['@id']);

    $list = semanticFind(semanticLd($web->get('/blog')->body), 'ItemList');
    check($list !== null, 'el índice del blog es un ItemList');
    same(2, $list['numberOfItems']);
    foreach ($list['itemListElement'] as $element) {
        check(strpos($element['url'], 'https://tienda.test/blog/') === 0, 'enlaza una entrada: ' . $element['url']);
    }
});

test('la búsqueda y el resto de páginas privadas no llevan datos de listado ni versión en texto', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    $web = new TestClient($app);

    $r = $web->get('/buscar?q=silla');
    same(200, $r->status);
    same(null, semanticFind(semanticLd($r->body), 'ItemList'));
    notContains('type="text/markdown"', $r->body);
});
