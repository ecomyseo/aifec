<?php
/**
 * AIFEC — pruebas de las páginas: alta por el panel con bloques saneados, estados, programación, traducción y respaldo, dirección
 * amigable y redirección al cambiarla, versiones, portada única, permiso de publicar y escapado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Cms\Cms;

require_once __DIR__ . '/helpers.php';

function cmsService(App $app): Cms
{
    $cms = $app->service('cms');
    check($cms instanceof Cms, 'el servicio cms es el del módulo');

    return $cms;
}

test('sin el editor de bloques, el texto escrito en «Contenido» se guarda como párrafos (no pide JSON)', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $r = cmsSavePage($web, ['title' => ['es' => 'R2 Novedades', 'en' => ''], 'blocks' => ['es' => "Esta semana llegan cosas nuevas.\nY rebajas <de> verano.\n\nSegundo párrafo & más.", 'en' => '']]);
    same(302, $r->status, 'se guarda');
    $stored = json_decode((string) $app->db()->value('SELECT blocks FROM {page_i18n} WHERE page_id = ? AND lang = ?', [cmsLastId($app, 'pages'), 'es']), true);
    same(2, count($stored), 'dos párrafos');
    same('paragraph', $stored[0]['type']);
    contains('Esta semana llegan cosas nuevas.<br>', $stored[0]['attrs']['html']);
    contains('&lt;de&gt;', $stored[0]['attrs']['html'], 'escapado');
    contains('Segundo párrafo &amp; más.', $stored[1]['attrs']['html']);
});

test('una página con un script y un onclick se guarda sin ellos y se ve con su texto', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $html = 'Hola <script>alert(1)</script><b onclick="robar()">mundo</b> <a href="javascript:alert(2)">enlace</a>';
    $r = cmsSavePage($web, ['title' => ['es' => 'Quiénes somos', 'en' => ''], 'blocks' => ['es' => cmsBlocks($html), 'en' => ''], 'status' => 'published']);
    same(302, $r->status);
    $id = cmsLastId($app, 'pages');

    $stored = (string) $app->db()->value('SELECT blocks FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, 'es']);
    notContains('script', $stored, 'el script no se guarda');
    notContains('onclick', $stored, 'el onclick no se guarda');
    notContains('javascript:', $stored, 'el enlace peligroso no se guarda');
    contains('mundo', $stored);

    $page = cmsService($app)->page($id);
    check($page !== null, 'la página publicada se ve');
    contains('mundo', $page['html']);
    notContains('<script', $page['html']);
    notContains('onclick', $page['html']);
    same('Quiénes somos', $page['title']);
    contains('Hola', $page['text']);
});

test('una página publicada trae todas las claves del contrato', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'published', 'meta_title' => ['es' => 'Título SEO', 'en' => ''], 'meta_description' => ['es' => 'Descripción SEO', 'en' => ''], 'noindex' => '1']);
    $id = cmsLastId($app, 'pages');
    $page = cmsService($app)->page($id);
    foreach (['id', 'title', 'html', 'blocks', 'excerpt', 'template', 'url', 'url_abs', 'seo', 'published_at', 'updated_at', 'text'] as $key) {
        check(array_key_exists($key, $page), 'falta la clave ' . $key);
    }
    same('Título SEO', $page['seo']['title']);
    same('Descripción SEO', $page['seo']['description']);
    same(true, $page['seo']['noindex']);
    same('default', $page['template']);
    contains('/quienes-somos', $page['url']);
    contains('https://tienda.test/quienes-somos', $page['url_abs']);
    same('Somos una tienda pequeña.', $page['excerpt'], 'sin extracto, sale del contenido');
    check(is_array($page['blocks']) && count($page['blocks']) === 1, 'los bloques son una lista');
});

test('un borrador no se ve salvo que se pida con $any', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'draft']);
    $id = cmsLastId($app, 'pages');
    $cms = cmsService($app);
    same(null, $cms->page($id));
    check($cms->page($id, null, true) !== null, 'con $any se ve');
    same(null, $cms->page(99999, null, true), 'una página que no existe es null');
});

test('una página programada no se ve antes de su hora y sí después, sin tarea que la publique', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    // El reloj de las pruebas está en las 12:00 UTC; el panel guarda la hora de Madrid (13:00 UTC = 14:00 locales).
    $r = cmsSavePage($web, ['status' => 'scheduled', 'published_at' => '2026-11-12 14:00:00']);
    same(302, $r->status);
    $id = cmsLastId($app, 'pages');
    same('2026-11-12 13:00:00', (string) $app->db()->value('SELECT published_at FROM {pages} WHERE id = ?', [$id]), 'se guarda en UTC');
    $cms = cmsService($app);
    same(null, $cms->page($id), 'todavía no');
    $app->clock()->advance(3000);
    same(null, $cms->page($id), 'faltan 10 minutos');
    $app->clock()->advance(1000);
    check($cms->page($id) !== null, 'ya se ve');
    $app->clock()->advance(-3000);
    same(null, $cms->page($id), 'y si el reloj vuelve atrás, deja de verse (la caché no congela la visibilidad)');
});

test('programar sin fecha da un error y no guarda nada', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $r = cmsSavePage($web, ['status' => 'scheduled', 'published_at' => '']);
    same(422, $r->status);
    contains('Elige la fecha y la hora', $r->body);
    same(0, cmsLastId($app, 'pages'));
});

test('la traducción se ve en su idioma y lo que falta cae en el principal', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, [
        'status' => 'published',
        'title' => ['es' => 'Quiénes somos', 'en' => 'About us'],
        'blocks' => ['es' => cmsBlocks('Texto en español.'), 'en' => ''],
        'excerpt' => ['es' => 'Resumen en español', 'en' => ''],
    ]);
    $id = cmsLastId($app, 'pages');
    $cms = cmsService($app);
    $en = $cms->page($id, 'en');
    same('About us', $en['title']);
    contains('Texto en español.', $en['html'], 'sin bloques en inglés, salen los del idioma principal');
    same('Resumen en español', $en['excerpt']);
    contains('/en/about-us', $en['url']);
    $es = $cms->page($id, 'es');
    same('Quiénes somos', $es['title']);
    contains('/quienes-somos', $es['url']);
    same('Quiénes somos', $cms->page($id, 'fr')['title'], 'un idioma que el sitio no tiene cae en el actual');
});

test('una página con título solo en español se ve igual en inglés', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'published']);
    $id = cmsLastId($app, 'pages');
    $en = cmsService($app)->page($id, 'en');
    same('Quiénes somos', $en['title']);
    contains('/quienes-somos', $en['url'], 'sin dirección en inglés, la del idioma principal');
});

test('cambiar la dirección de una página deja la antigua redirigiendo a la nueva', function (): void {
    $app = cmsApp();
    cmsFakeFront($app);
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'published', 'slug' => ['es' => 'quienes-somos', 'en' => '']]);
    $id = cmsLastId($app, 'pages');
    same(200, $web->get('/quienes-somos')->status);

    cmsSavePage($web, ['status' => 'published', 'slug' => ['es' => 'nosotros', 'en' => '']], $id);
    same('nosotros', $app->slugs()->path('page', $id, 'es'));
    $old = $web->get('/quienes-somos');
    same(301, $old->status);
    contains('/nosotros', $old->headers['Location']);
    same(200, $web->get('/nosotros')->status);
});

test('una dirección ocupada por otra página recibe un número', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'published']);
    cmsSavePage($web, ['status' => 'published']);
    same('quienes-somos', $app->slugs()->path('page', 1, 'es'));
    same('quienes-somos-2', $app->slugs()->path('page', 2, 'es'));
});

test('cada guardado deja una versión y solo se conservan las 20 últimas', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['title' => ['es' => 'Versión 1', 'en' => '']]);
    $id = cmsLastId($app, 'pages');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {revisions} WHERE entity = 'page' AND entity_id = ?", [$id]));
    for ($i = 2; $i <= 25; ++$i) {
        cmsSavePage($web, ['title' => ['es' => 'Versión ' . $i, 'en' => '']], $id);
    }
    same(20, (int) $app->db()->value("SELECT COUNT(*) FROM {revisions} WHERE entity = 'page' AND entity_id = ?", [$id]));
    $oldest = json_decode((string) $app->db()->value("SELECT data FROM {revisions} WHERE entity = 'page' AND entity_id = ? ORDER BY id ASC LIMIT 1", [$id]), true);
    same('Versión 6', $oldest['i18n']['es']['title'], 'se borraron las cinco primeras');
    $who = $app->db()->value("SELECT user_id FROM {revisions} WHERE entity = 'page' AND entity_id = ? ORDER BY id DESC LIMIT 1", [$id]);
    check($who !== null, 'la versión dice quién la hizo');
});

test('restaurar una versión la carga como borrador y deja otra versión', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['title' => ['es' => 'Texto antiguo', 'en' => ''], 'blocks' => ['es' => cmsBlocks('Contenido antiguo'), 'en' => ''], 'status' => 'published']);
    $id = cmsLastId($app, 'pages');
    cmsSavePage($web, ['title' => ['es' => 'Texto nuevo', 'en' => ''], 'blocks' => ['es' => cmsBlocks('Contenido nuevo'), 'en' => ''], 'status' => 'published'], $id);
    same('Texto nuevo', cmsService($app)->page($id)['title']);
    $first = (int) $app->db()->value("SELECT MIN(id) FROM {revisions} WHERE entity = 'page' AND entity_id = ?", [$id]);

    $screen = $web->get('/admin/r/pages/' . $id);
    contains('/admin/cms/revisions/' . $first . '/restore', $screen->body, 'la ficha ofrece restaurar');
    $r = $web->post('/admin/cms/revisions/' . $first . '/restore', ['_token' => aifecToken($web)]);
    same(302, $r->status);

    same('Texto antiguo', (string) $app->db()->value('SELECT title FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, 'es']));
    same('draft', (string) $app->db()->value('SELECT status FROM {pages} WHERE id = ?', [$id]), 'queda como borrador');
    same(null, cmsService($app)->page($id), 'y deja de verse');
    same(3, (int) $app->db()->value("SELECT COUNT(*) FROM {revisions} WHERE entity = 'page' AND entity_id = ?", [$id]), 'la restauración también deja su versión');
    $web->get('/admin/r/pages/' . $id);
    same(404, $web->post('/admin/cms/revisions/99999/restore', ['_token' => aifecToken($web)])->status);
});

test('solo una página puede ser la portada', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'published', 'is_home' => '1']);
    $a = cmsLastId($app, 'pages');
    cmsSavePage($web, ['title' => ['es' => 'Otra', 'en' => ''], 'status' => 'published', 'is_home' => '1']);
    $b = cmsLastId($app, 'pages');
    same('0', (string) $app->db()->value('SELECT is_home FROM {pages} WHERE id = ?', [$a]));
    same('1', (string) $app->db()->value('SELECT is_home FROM {pages} WHERE id = ?', [$b]));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {pages} WHERE is_home = 1'));
    same($b, cmsService($app)->homePageId());
    cmsSavePage($web, ['status' => 'draft', 'is_home' => '1'], $a);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {pages} WHERE is_home = 1'), 'sigue habiendo una sola');
    same(null, cmsService($app)->homePageId(), 'una portada en borrador no cuenta como portada visible');
});

test('una página no puede ser madre de sí misma ni de su madre', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'published']);
    $a = cmsLastId($app, 'pages');
    cmsSavePage($web, ['title' => ['es' => 'Hija', 'en' => ''], 'status' => 'published', 'parent_id' => (string) $a]);
    $b = cmsLastId($app, 'pages');
    same((string) $a, (string) $app->db()->value('SELECT parent_id FROM {pages} WHERE id = ?', [$b]));
    same(422, cmsSavePage($web, ['status' => 'published', 'parent_id' => (string) $a], $a)->status, 'ella misma');
    same(422, cmsSavePage($web, ['status' => 'published', 'parent_id' => (string) $b], $a)->status, 'su propia hija');
    $screen = $web->post('/admin/r/pages/' . $a . '/delete', ['_token' => aifecToken($web)]);
    same(302, $screen->status);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {pages} WHERE id = ?', [$a]), 'con subpáginas no se borra');
});

test('borrar una página borra sus textos, versiones, dirección y su sitio en los menús', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'published']);
    $id = cmsLastId($app, 'pages');
    $app->db()->insert('menus', ['code' => 'main', 'name' => 'Main menu']);
    $app->db()->insert('menu_items', ['menu_id' => 1, 'position' => 0, 'type' => 'page', 'ref' => $id, 'blank' => 0]);
    $web->get('/admin/r/pages/' . $id);
    $r = $web->post('/admin/r/pages/' . $id . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {pages} WHERE id = ?', [$id]));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {page_i18n} WHERE page_id = ?', [$id]));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {revisions} WHERE entity = 'page'"));
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {menu_items} WHERE type = 'page'"));
    same(null, $app->slugs()->path('page', $id, 'es'));
    same(null, cmsService($app)->page($id, null, true));
});

test('un título con & y <b> sale escapado en el listado y en la ficha', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'published', 'title' => ['es' => 'Té & <b>café</b>', 'en' => '']]);
    $id = cmsLastId($app, 'pages');
    $list = $web->get('/admin/r/pages')->body;
    contains('Té &amp; &lt;b&gt;café&lt;/b&gt;', $list);
    notContains('<b>café</b>', $list);
    $screen = $web->get('/admin/r/pages/' . $id)->body;
    notContains('<b>café</b>', $screen);
    $preview = $web->get('/admin/cms/preview/page/' . $id)->body;
    contains('Té &amp; &lt;b&gt;café&lt;/b&gt;', $preview);
    notContains('<b>café</b>', $preview);
    same('Té & <b>café</b>', cmsService($app)->page($id)['title'], 'el servicio da el dato tal cual y quien lo pinta lo escapa');
});

test('la lista de páginas se puede filtrar por estado y las acciones en bloque publican y pasan a borrador', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'draft']);
    cmsSavePage($web, ['title' => ['es' => 'Segunda', 'en' => ''], 'status' => 'published']);
    $list = $web->get('/admin/r/pages', [])->body;
    contains('Quiénes somos', $list);
    contains('Segunda', $list);
    $filtered = $web->get('/admin/r/pages?f[status]=published')->body;
    contains('Segunda', $filtered);
    notContains('Quiénes somos', $filtered);

    $web->get('/admin/r/pages');
    $r = $web->post('/admin/r/pages/bulk', ['_token' => aifecToken($web), 'action' => 'publish', 'ids' => ['1', '2']]);
    same(302, $r->status);
    same('published', (string) $app->db()->value('SELECT status FROM {pages} WHERE id = 1'));
    check($app->db()->value('SELECT published_at FROM {pages} WHERE id = 1') !== null, 'al publicar se pone la fecha');
    $web->get('/admin/r/pages');
    $web->post('/admin/r/pages/bulk', ['_token' => aifecToken($web), 'action' => 'draft', 'ids' => ['1', '2']]);
    same('draft', (string) $app->db()->value('SELECT status FROM {pages} WHERE id = 2'));
    same(null, cmsService($app)->page(2));
});
