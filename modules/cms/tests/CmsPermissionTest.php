<?php
/**
 * AIFEC — pruebas de permisos del panel de contenidos: sin sesión no se entra, quien no tiene permisos recibe 403 en todas las
 * pantallas y rutas, quien solo ve no cambia nada, quien solo escribe no publica, y los ajustes del módulo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Cms\Cms;

require_once __DIR__ . '/helpers.php';

/** Un sitio con las tres personas que importan: quien solo ve, quien escribe y quien no tiene nada que ver con los contenidos. */
function cmsPermissionApp(): App
{
    $app = cmsApp();
    cmsRole($app, 'viewer', ['dashboard.view', 'content.view']);
    cmsRole($app, 'writer', ['dashboard.view', 'content.view', 'content.edit']);

    return $app;
}

test('sin sesión no se entra: se manda a la pantalla de acceso y las llamadas de JavaScript reciben 401', function (): void {
    $app = cmsApp();
    $web = new TestClient($app);
    foreach (['/admin/r/pages', '/admin/cms/menus', '/admin/cms/legal', '/admin/cms/not-found', '/admin/cms/preview/page/1', '/admin/cms/entries/export'] as $url) {
        $r = $web->get($url);
        same(302, $r->status, $url);
        contains('/admin/login', $r->headers['Location'], $url);
    }
    same(401, $web->postJson('/admin/cms/blocks/import', ['content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'])->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'));
});

test('quien no tiene ningún permiso de contenidos recibe 403 en todas las pantallas y rutas', function (): void {
    $app = cmsPermissionApp();
    cmsInsertPage($app, ['title' => 'Existe']);
    $web = adminClient($app, 'support');
    $token = aifecToken($web);
    foreach ([
        '/admin/r/pages', '/admin/r/pages/new', '/admin/r/pages/1', '/admin/r/posts', '/admin/r/post_categories', '/admin/r/post_tags', '/admin/r/forms', '/admin/r/form_entries',
        '/admin/r/redirects', '/admin/cms/menus', '/admin/cms/menus/main', '/admin/cms/menus/search?type=page&q=a', '/admin/cms/not-found', '/admin/cms/legal',
        '/admin/cms/preview/page/1', '/admin/cms/entries/export', '/admin/settings/cms',
    ] as $url) {
        same(403, $web->get($url)->status, $url);
    }
    foreach ([
        ['/admin/r/pages/save', ['f' => cmsPageFields([])]], ['/admin/r/pages/1/delete', []], ['/admin/r/pages/bulk', ['action' => 'publish', 'ids' => ['1']]],
        ['/admin/cms/revisions/1/restore', []], ['/admin/cms/menus/main', ['items' => []]], ['/admin/cms/legal', []],
        ['/admin/cms/not-found/redirect', ['path' => 'a', 'target' => '/b']], ['/admin/cms/not-found/clear', []],
    ] as $call) {
        same(403, $web->post($call[0], ['_token' => $token] + $call[1])->status, $call[0]);
    }
    same(403, $web->postJson('/admin/cms/blocks/import', ['_token' => $token, 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'])->status);
    same(403, $web->postJson('/admin/cms/blocks/render', ['_token' => $token, 'blocks' => []])->status);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'), 'no se tocó nada');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'));
    $menu = $web->get('/admin')->body;
    notContains('/admin/r/pages', $menu, 'y el menú no le enseña lo que no puede abrir');
    notContains('/admin/cms/legal', $menu);
});

test('quien solo puede ver abre los listados y la vista previa pero no cambia nada', function (): void {
    $app = cmsPermissionApp();
    $id = cmsInsertPage($app, ['title' => 'Existe']);
    $web = adminClient($app, 'viewer');
    foreach (['/admin/r/pages', '/admin/r/posts', '/admin/r/forms', '/admin/r/form_entries', '/admin/r/redirects', '/admin/cms/menus', '/admin/cms/menus/main', '/admin/cms/legal',
        '/admin/cms/not-found', '/admin/cms/preview/page/' . $id, '/admin/cms/entries/export'] as $url) {
        same(200, $web->get($url)->status, $url);
    }
    same(403, $web->get('/admin/r/pages/new')->status, 'no hay alta');
    same(403, $web->get('/admin/r/pages/' . $id)->status, 'ni ficha para cambiar');
    same(403, $web->get('/admin/settings/cms')->status);
    $list = $web->get('/admin/r/pages')->body;
    notContains('/admin/r/pages/new', $list, 'el listado no ofrece el botón de nuevo');

    $token = aifecToken($web);
    same(403, $web->post('/admin/r/pages/save', ['_token' => $token, 'f' => cmsPageFields([])])->status);
    same(403, $web->post('/admin/r/pages/' . $id . '/delete', ['_token' => $token])->status);
    same(403, $web->post('/admin/cms/menus/main', ['_token' => $token, 'items' => [['type' => 'home', 'level' => '0']]])->status);
    same(403, $web->post('/admin/cms/legal', ['_token' => $token])->status);
    same(403, $web->post('/admin/cms/not-found/clear', ['_token' => $token])->status);
    same(403, $web->postJson('/admin/cms/blocks/import', ['_token' => $token, 'content' => '<!-- wp:paragraph --><p>x</p><!-- /wp:paragraph -->'])->status);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {menu_items}'));
});

test('quien escribe pero no publica puede guardar borradores y recibe 403 al publicar o programar, sin que se guarde nada', function (): void {
    $app = cmsPermissionApp();
    $web = adminClient($app, 'writer');
    same(302, cmsSavePage($web, ['status' => 'draft'])->status, 'un borrador sí');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'));
    same(403, cmsSavePage($web, ['status' => 'published', 'title' => ['es' => 'Publicada', 'en' => '']])->status, 'publicar no');
    same(403, cmsSavePage($web, ['status' => 'scheduled', 'published_at' => '2026-12-01 10:00:00', 'title' => ['es' => 'Programada', 'en' => '']])->status, 'programar tampoco');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'), 'no se creó ninguna');
    same(403, cmsSavePage($web, ['status' => 'published'], 1)->status, 'publicar una existente');
    same('draft', (string) $app->db()->value('SELECT status FROM {pages} WHERE id = 1'));

    same(302, cmsSavePost($web, ['status' => 'draft'])->status);
    same(403, cmsSavePost($web, ['status' => 'published', 'title' => ['es' => 'Otra', 'en' => '']])->status);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {posts}'));
    $web->get('/admin/r/pages');
    same(403, $web->post('/admin/r/pages/bulk', ['_token' => aifecToken($web), 'action' => 'publish', 'ids' => ['1']])->status, 'ni en bloque');
    same('draft', (string) $app->db()->value('SELECT status FROM {pages} WHERE id = 1'));
});

test('quien escribe puede seguir editando lo que ya está publicado sin cambiar su estado, y retirarlo', function (): void {
    $app = cmsPermissionApp();
    $admin = adminClient($app, 'admin');
    cmsSavePage($admin, ['status' => 'published', 'title' => ['es' => 'Publicada', 'en' => '']]);
    $id = cmsLastId($app, 'pages');
    $at = (string) $app->db()->value('SELECT published_at FROM {pages} WHERE id = ?', [$id]);

    $writer = adminClient($app, 'writer');
    same(302, cmsSavePage($writer, ['status' => 'published', 'title' => ['es' => 'Publicada y corregida', 'en' => '']], $id)->status, 'mismo estado y misma fecha');
    same('Publicada y corregida', (string) $app->db()->value('SELECT title FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, 'es']));
    same($at, (string) $app->db()->value('SELECT published_at FROM {pages} WHERE id = ?', [$id]), 'la fecha de publicación se conserva');

    same(403, cmsSavePage($writer, ['status' => 'published', 'published_at' => '2026-06-01 10:00:00'], $id)->status, 'cambiar la fecha de publicación es publicar');
    same(302, cmsSavePage($writer, ['status' => 'draft'], $id)->status, 'retirarla a borrador no exige el permiso');
    same(403, cmsSavePage($writer, ['status' => 'published'], $id)->status, 'volver a publicarla sí');

    // Restaurar una versión de algo que está publicado lo deja como borrador: lo decide quien publica.
    cmsSavePage($admin, ['status' => 'published'], $id);
    $first = (int) $app->db()->value("SELECT MIN(id) FROM {revisions} WHERE entity = 'page' AND entity_id = ?", [$id]);
    $writer->get('/admin/r/pages');
    same(403, $writer->post('/admin/cms/revisions/' . $first . '/restore', ['_token' => aifecToken($writer)])->status);
    same('published', (string) $app->db()->value('SELECT status FROM {pages} WHERE id = ?', [$id]));
});

test('el papel de editor, con content.*, publica y programa', function (): void {
    $app = cmsPermissionApp();
    $web = adminClient($app, 'editor');
    same(302, cmsSavePage($web, ['status' => 'published'])->status);
    same(302, cmsSavePost($web, ['status' => 'scheduled', 'published_at' => '2026-12-24 10:00:00'])->status);
    /** @var Cms $cms */
    $cms = $app->service('cms');
    check($cms->page(1) !== null, 'la página publicada se ve');
    same(null, $cms->post(1), 'la entrada programada para más adelante, todavía no');
    $web->get('/admin/cms/legal');
    same(302, $web->post('/admin/cms/legal', ['_token' => aifecToken($web)])->status, 'y crea las páginas legales');
});

test('quien escribe puede guardar un menú, crear redirecciones desde «No encontradas» y las páginas legales', function (): void {
    $app = cmsPermissionApp();
    $web = adminClient($app, 'writer');
    $public = new TestClient($app);
    $public->get('/sin-pagina');
    $web->get('/admin/cms/menus/main');
    same(302, $web->post('/admin/cms/menus/main', ['_token' => aifecToken($web), 'items' => [['type' => 'home', 'level' => '0', 'labels' => ['es' => 'Inicio']]]])->status);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {menu_items}'));
    $web->get('/admin/cms/not-found');
    same(302, $web->post('/admin/cms/not-found/redirect', ['_token' => aifecToken($web), 'path' => 'sin-pagina', 'target' => '/'])->status);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'));
    $web->get('/admin/cms/legal');
    same(302, $web->post('/admin/cms/legal', ['_token' => aifecToken($web)])->status);
    same(5, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'));
});

test('los ajustes del módulo los abre quien puede escribir contenidos', function (): void {
    $app = cmsPermissionApp();
    $writer = adminClient($app, 'writer');
    $screen = $writer->get('/admin/settings/cms');
    same(200, $screen->status);
    contains('cmblog', $screen->body, 'las pestañas llevan las iniciales del módulo');
    contains('cmurls', $screen->body);
    contains('name="f[urls.post]"', $screen->body);
    contains('value="blog"', $screen->body);
    same(403, adminClient($app, 'viewer')->get('/admin/settings/cms')->status);
});

test('cambiar la palabra del blog en los ajustes rehace las direcciones existentes y deja las antiguas redirigiendo', function (): void {
    $app = cmsApp();
    cmsFakeFront($app);
    $web = adminClient($app);
    cmsSavePost($web, ['status' => 'published', 'slug' => ['es' => 'madera', 'en' => '']]);
    $id = cmsLastId($app, 'posts');
    $tag = cmsTerm($app, 'Roble', 'tag');
    same('blog/madera', $app->slugs()->path('post', $id, 'es'));

    $web->get('/admin/settings/cms');
    $r = $web->post('/admin/settings/cms', ['_token' => aifecToken($web), 'f' => ['cms.posts_per_page' => '4', 'urls.post' => 'novedades', 'urls.term_category' => 'categoria', 'urls.term_tag' => 'tema']]);
    same(302, $r->status);
    same('novedades/madera', $app->slugs()->path('post', $id, 'es'));
    same('novedades/tema/roble', $app->slugs()->path('term', $tag, 'es'));
    same(301, $web->get('/blog/madera')->status);
    same(200, $web->get('/novedades/madera')->status);
    same(4, $app->settings()->int('cms.posts_per_page'));

    $web->get('/admin/settings/cms');
    same(422, $web->post('/admin/settings/cms', ['_token' => aifecToken($web), 'f' => ['cms.posts_per_page' => '0', 'urls.post' => 'novedades', 'urls.term_category' => 'categoria', 'urls.term_tag' => 'tema']])->status, 'cero entradas por página no vale');
});
