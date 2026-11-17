<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Pruebas de la auditoría SEO del panel: qué detecta, con qué enlaces, quién la ve, la caché y la pieza del inicio.
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Seo\Audit;

require_once __DIR__ . '/helpers.php';

/** Una tienda con los problemas que busca la auditoría. @return array<string,int> */
function seoSickShop(App $app): array
{
    $ids = [];
    $ids['mesa1'] = aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa-1']);
    $ids['mesa2'] = aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa-2']);
    $ids['larga'] = aifecProduct($app, [
        'name' => 'Silla', 'slug' => 'silla', 'meta_title' => str_repeat('Silla de oficina ', 5), 'meta_description' => 'Una silla.',
        'description' => str_repeat('Texto de la silla. ', 30), 'images' => 1,
    ]);
    aifecMedia($app, '');
    aifecMedia($app, 'Con texto alternativo');

    return $ids;
}

test('detecta un producto sin descripción, un título repetido y una imagen sin texto alternativo, con sus enlaces', function (): void {
    $app = seoApp(['seo', 'catalog', 'cms', 'media']);
    $ids = seoSickShop($app);
    $web = adminClient($app);
    $r = $web->get('/admin/seo/audit');
    same(200, $r->status);

    $audit = Audit::run($app);
    $meta = seoCheck($audit, 'product.meta_description');
    same(2, $meta['count'], 'las dos mesas no tienen meta descripción; la silla sí');
    $repeated = seoCheck($audit, 'product.title_repeated');
    same(2, $repeated['count'], 'las dos mesas se llaman igual');
    same(2, count($repeated['items']));
    same(1, seoCheck($audit, 'product.title_long')['count'], 'la silla pasa de 65 caracteres');
    same(2, seoCheck($audit, 'product.no_image')['count'], 'las mesas no tienen imagen; la silla sí');
    same(2, seoCheck($audit, 'product.short_text')['count'], 'las mesas no tienen texto; el de la silla pasa de 300');
    same(1, seoCheck($audit, 'media.alt')['count'], 'una imagen sin texto alternativo: la de la silla y la tercera lo llevan escrito');

    contains('Productos sin meta descripción propia', $r->body, 'el texto sale en castellano');
    contains('href="/admin/r/products/' . $ids['mesa1'] . '"', $r->body, 'enlace a la ficha del producto');
    contains('href="/admin/r/products/' . $ids['mesa2'] . '"', $r->body);
    contains('Imágenes sin texto alternativo', $r->body);
    contains('href="/admin/media"', $r->body, 'enlace a la biblioteca');
    contains('href="/admin/r/products"', $r->body, 'y al listado');
});

test('lo que está bien no cuenta: sin problemas la auditoría marca cero', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    aifecProduct($app, [
        'name' => 'Silla', 'slug' => 'silla', 'meta_title' => 'Silla de oficina', 'meta_description' => 'Una silla cómoda para trabajar.',
        'description' => str_repeat('Texto de la silla. ', 30), 'images' => 1,
    ]);
    $audit = Audit::compute($app);
    $count = 0;
    foreach ($audit['checks'] as $check) {
        if ($check['id'] !== 'media.alt') {
            $count += $check['count'];
        }
    }
    same(0, $count, 'ningún problema en el producto');
    same(0, seoCheck($audit, 'product.title_repeated')['count']);
});

test('los borradores no se auditan', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    aifecProduct($app, ['name' => 'Borrador', 'slug' => 'borrador', 'status' => 'draft']);
    $audit = Audit::compute($app);
    same(0, seoCheck($audit, 'product.meta_description')['count']);
    same(0, seoCheck($audit, 'product.no_image')['count']);
});

test('páginas, entradas y categorías: sin meta descripción, título repetido y sin imagen', function (): void {
    $app = seoApp(['seo', 'catalog', 'cms', 'media']);
    cmsInsertPage($app, ['title' => 'Contacto']);
    cmsInsertPage($app, ['title' => 'Contacto', 'slug' => 'contacto-2']);
    cmsInsertPage($app, ['title' => 'Borrador', 'status' => 'draft']);
    cmsInsertPost($app, ['title' => 'Novedades']);
    aifecCategory($app, 'Muebles');
    $audit = Audit::compute($app);
    same(2, seoCheck($audit, 'page.meta_description')['count'], 'dos páginas publicadas');
    same(2, seoCheck($audit, 'page.title_repeated')['count']);
    same(1, seoCheck($audit, 'post.meta_description')['count']);
    same(1, seoCheck($audit, 'post.no_image')['count']);
    same(1, seoCheck($audit, 'category.meta_description')['count']);
    same(1, seoCheck($audit, 'category.no_image')['count']);
    $item = seoCheck($audit, 'page.meta_description')['items'][0];
    check(strpos($item['url'], '/admin/r/pages/') === 0, 'el enlace lleva a la página: ' . $item['url']);
});

test('una redirección encadenada se detecta y una sola no', function (): void {
    $app = seoApp(['seo', 'cms', 'media']);
    $now = $app->clock()->sql();
    $app->db()->insert('redirects', ['source' => 'viejo', 'target' => '/intermedio', 'code' => 301, 'hits' => 0, 'active' => 1, 'created_at' => $now]);
    $app->db()->insert('redirects', ['source' => 'intermedio', 'target' => '/nuevo', 'code' => 301, 'hits' => 0, 'active' => 1, 'created_at' => $now]);
    $app->db()->insert('redirects', ['source' => 'suelto', 'target' => '/otro', 'code' => 301, 'hits' => 0, 'active' => 1, 'created_at' => $now]);
    $app->db()->insert('redirects', ['source' => 'apagado', 'target' => '/suelto', 'code' => 301, 'hits' => 0, 'active' => 0, 'created_at' => $now]);
    $check = seoCheck(Audit::compute($app), 'redirects.chains');
    same(1, $check['count'], 'solo «viejo» → «intermedio» → «nuevo»; «apagado» no cuenta porque está apagada');
    contains('/viejo', $check['items'][0]['label']);
});

test('una página «no indexar» con un enlace en un menú se detecta', function (): void {
    $app = seoApp(['seo', 'cms', 'media']);
    $privada = cmsInsertPage($app, ['title' => 'Privada']);
    $visible = cmsInsertPage($app, ['title' => 'Visible']);
    $app->db()->update('pages', ['noindex' => 1], ['id' => $privada]);
    $menu = $app->db()->insert('menus', ['code' => 'seotest', 'name' => 'Prueba']);
    foreach ([$privada, $visible] as $i => $page) {
        $app->db()->insert('menu_items', ['menu_id' => $menu, 'position' => $i, 'type' => 'page', 'ref' => $page, 'labels' => null]);
    }
    $check = seoCheck(Audit::compute($app), 'menus.noindex');
    same(1, $check['count']);
    same('Privada', $check['items'][0]['label']);
    check(strpos($check['items'][0]['url'], '/admin/r/pages/' . $privada) === 0, 'enlaza a la página');
});

test('la auditoría la ve quien tiene «seo.view» y a los demás les da 403', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    same(200, adminClient($app, 'editor')->get('/admin/seo/audit')->status, 'el editor tiene seo.*');
    $other = seoApp(['seo', 'catalog', 'media']);
    same(403, adminClient($other, 'support')->get('/admin/seo/audit')->status);
    $anonymous = new TestClient(seoApp(['seo']));
    same(302, $anonymous->get('/admin/seo/audit')->status, 'sin sesión, al acceso');
});

test('el resultado se guarda en la caché diez minutos y se vacía al guardar algo del panel', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa']);
    same(1, seoCheck(Audit::run($app), 'product.meta_description')['count']);
    aifecProduct($app, ['name' => 'Otra', 'slug' => 'otra']);
    same(1, seoCheck(Audit::run($app), 'product.meta_description')['count'], 'dentro de los diez minutos sigue el resultado guardado');
    $app->hooks()->fire('admin.resource.saved', 'products', 2);
    same(2, seoCheck(Audit::run($app), 'product.meta_description')['count'], 'al guardar un producto se vuelve a calcular');
    $app->clock()->advance(Audit::TTL + 1);
    catalogNewRequest($app);
    aifecProduct($app, ['name' => 'Tercera', 'slug' => 'tercera']);
    same(3, seoCheck(Audit::run($app), 'product.meta_description')['count'], 'y pasados los diez minutos también');
});

test('el nombre de un producto con «&» y etiquetas sale escapado en la auditoría', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    aifecProduct($app, ['name' => 'Té & <b>café</b>', 'slug' => 'te']);
    $body = adminClient($app)->get('/admin/seo/audit')->body;
    contains('Té &amp; &lt;b&gt;café&lt;/b&gt;', $body);
    notContains('<b>café</b>', $body);
});

test('el inicio del panel enseña la pieza «SEO» con el número de problemas y el estado de indexación', function (): void {
    $app = seoApp(['seo', 'catalog', 'media']);
    aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa']);
    $home = adminClient($app)->get('/admin')->body;
    contains('Problemas encontrados', $home);
    contains('Abierto a los buscadores', $home);
    contains('href="/admin/seo/audit"', $home);
    notContains('oculto a los buscadores', $home, 'sin el interruptor no hay aviso');
    notContains('oculta a los buscadores', $home, 'sin el interruptor no hay aviso');

    $closed = seoApp(['seo', 'catalog', 'media'], ['seo.noindex_site' => true]);
    $page = adminClient($closed)->get('/admin')->body;
    contains('La tienda está oculta a los buscadores', $page, 'el aviso del inicio');
    notContains('Todo el sitio está oculto a los buscadores', $page, 'un solo aviso, no dos');
    contains('Oculto a los buscadores', $page, 'y el estado de la pieza');
    contains('href="/admin/settings/seo"', $page, 'con el enlace para quitarlo');
});

test('un papel sin permiso de ajustes ve el aviso de sitio cerrado, pero sin el enlace', function (): void {
    $app = seoApp(['seo', 'catalog', 'media'], ['seo.noindex_site' => true]);
    $page = adminClient($app, 'support')->get('/admin')->body;
    contains('La tienda está oculta a los buscadores', $page);
    notContains('href="/admin/settings/seo"', $page);
});
