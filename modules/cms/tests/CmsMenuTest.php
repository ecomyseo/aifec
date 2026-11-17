<?php
/**
 * AIFEC — pruebas de los menús: guardar un árbol de dos niveles por el panel, leerlo con menu() en dos idiomas, lo que no se ve
 * no sale, el filtro «menu.items» que lo entrega al tema, el editor, el buscador y lo que se descarta por no ser seguro.
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

function cmsMenuService(App $app): Cms
{
    $cms = $app->service('cms');
    check($cms instanceof Cms, 'el servicio cms es el del módulo');

    return $cms;
}

/**
 * Guarda un menú por el panel, como lo manda el navegador (los campos en el orden de las filas).
 *
 * @param array<int,array<string,mixed>> $items
 */
function cmsSaveMenu(TestClient $web, string $code, array $items): Response
{
    $web->get('/admin/cms/menus/' . $code);

    return $web->post('/admin/cms/menus/' . $code, ['_token' => aifecToken($web), 'items' => $items]);
}

/**
 * @return array<int,array<string,mixed>>
 */
function cmsMainTree(App $app, int $home, int $team, int $about): array
{
    return [
        ['type' => 'home', 'ref' => '', 'level' => '0', 'blank' => '0', 'labels' => ['es' => 'Inicio', 'en' => 'Start']],
        ['type' => 'page', 'ref' => (string) $about, 'level' => '0', 'blank' => '0', 'labels' => ['es' => '', 'en' => '']],
        ['type' => 'page', 'ref' => (string) $team, 'level' => '1', 'blank' => '0', 'labels' => ['es' => 'Equipo', 'en' => 'Team']],
        ['type' => 'url', 'url' => 'https://ejemplo.test/externo', 'level' => '0', 'blank' => '1', 'labels' => ['es' => 'Externo', 'en' => 'External']],
    ];
}

test('guardar un árbol por el panel y leerlo con menu() en dos idiomas', function (): void {
    $app = cmsApp();
    $about = cmsInsertPage($app, ['title' => 'Quiénes somos', 'en' => 'About us']);
    $team = cmsInsertPage($app, ['title' => 'Nuestro equipo', 'en' => 'Our team']);
    $web = adminClient($app);
    $r = cmsSaveMenu($web, 'main', cmsMainTree($app, 0, $team, $about));
    same(302, $r->status);
    contains('Guardado', $web->follow()->body);

    $es = cmsMenuService($app)->menu('main', 'es');
    same(['Inicio', 'Quiénes somos', 'Externo'], array_map(static function (array $i): string {
        return $i['label'];
    }, $es), 'la etiqueta vacía toma el título de la página');
    same('/', $es[0]['url']);
    same('/quienes-somos', $es[1]['url']);
    same(['Equipo'], array_map(static function (array $i): string {
        return $i['label'];
    }, $es[1]['children']), 'un elemento de nivel 1 cuelga del anterior de nivel 0');
    same('Nuestro equipo' === 'Nuestro equipo' ? '/nuestro-equipo' : '', $es[1]['children'][0]['url']);
    same('https://ejemplo.test/externo', $es[2]['url']);
    same([], $es[0]['children']);
    foreach (['label', 'url', 'current', 'children'] as $key) {
        check(array_key_exists($key, $es[1]), 'falta la clave ' . $key);
    }

    $en = cmsMenuService($app)->menu('main', 'en');
    same(['Start', 'About us', 'External'], array_map(static function (array $i): string {
        return $i['label'];
    }, $en));
    same('/en/', $en[0]['url']);
    same('/en/about-us', $en[1]['url']);
    same('Team', $en[1]['children'][0]['label']);
    same('/en/our-team', $en[1]['children'][0]['url']);
});

test('el filtro menu.items entrega los menús del comerciante al tema y respeta los de otros módulos', function (): void {
    $app = cmsApp();
    $about = cmsInsertPage($app, ['title' => 'Quiénes somos']);
    $web = adminClient($app);
    cmsSaveMenu($web, 'footer', [['type' => 'page', 'ref' => (string) $about, 'level' => '0', 'blank' => '0', 'labels' => ['es' => 'Nosotros', 'en' => '']]]);

    $items = $app->hooks()->apply('menu.items', [], 'footer', $app);
    same(['Nosotros'], array_map(static function (array $i): string {
        return $i['label'];
    }, $items));
    same([], $app->hooks()->apply('menu.items', [], 'main', $app), 'un menú sin guardar sale vacío');

    $app->hooks()->filter('menu.items', static function ($items, $location): array {
        return array_merge([['label' => 'De otro módulo', 'url' => '/otro', 'current' => false, 'children' => []]], $items);
    }, 5);
    same(['De otro módulo', 'Nosotros'], array_map(static function (array $i): string {
        return $i['label'];
    }, $app->hooks()->apply('menu.items', [], 'footer', $app)));
    same(['De otro módulo', 'Nosotros'], array_map(static function (array $i): string {
        return $i['label'];
    }, menu('footer')), 'y la función menu() de las plantillas ve lo mismo');
});

test('un elemento de una página despublicada no sale, ni los que cuelgan de él, y vuelve cuando se publica', function (): void {
    $app = cmsApp();
    $about = cmsInsertPage($app, ['title' => 'Quiénes somos']);
    $team = cmsInsertPage($app, ['title' => 'Equipo']);
    $web = adminClient($app);
    cmsSaveMenu($web, 'main', cmsMainTree($app, 0, $team, $about));
    $cms = cmsMenuService($app);
    same(3, count($cms->menu('main')));

    $app->db()->update('pages', ['status' => 'draft'], ['id' => $about]);
    $app->invalidate(['cms', 'page:' . $about]);
    same(['Inicio', 'Externo'], array_map(static function (array $i): string {
        return $i['label'];
    }, $cms->menu('main')), 'la página en borrador y su hija desaparecen');

    $app->db()->update('pages', ['status' => 'published'], ['id' => $about]);
    $app->invalidate(['cms', 'page:' . $about]);
    same(3, count($cms->menu('main')));

    $app->db()->update('pages', ['status' => 'scheduled', 'published_at' => '2026-11-12 13:00:00'], ['id' => $about]);
    $app->invalidate(['cms', 'page:' . $about]);
    same(2, count($cms->menu('main')), 'programada para más tarde, todavía no sale');
    $app->clock()->advance(3700);
    $app->invalidate(['cms']);
    same(3, count($cms->menu('main')), 'a su hora sale');

    $app->db()->delete('pages', ['id' => $team]);
    $app->invalidate(['cms']);
    same(0, count($cms->menu('main')[1]['children']), 'una página borrada deja de salir');
});

test('el elemento de la dirección que se está viendo sale marcado, y su padre también', function (): void {
    $app = cmsApp();
    $about = cmsInsertPage($app, ['title' => 'Quiénes somos']);
    $team = cmsInsertPage($app, ['title' => 'Equipo']);
    $app->entity('page', static function (Request $request, App $app, array $params): Response {
        /** @var Cms $cms */
        $cms = $app->service('cms');

        return Response::json(['menu' => $cms->menu('main')]);
    });
    $web = adminClient($app);
    cmsSaveMenu($web, 'main', cmsMainTree($app, 0, $team, $about));

    $web->get('/equipo');
    $menu = $web->json()['menu'];
    same(false, $menu[0]['current']);
    same(true, $menu[1]['children'][0]['current'], 'el elemento actual');
    same(true, $menu[1]['current'], 'y su padre');
    same(false, $menu[2]['current']);
    $web->get('/quienes-somos');
    same(true, $web->json()['menu'][1]['current']);
    same(false, $web->json()['menu'][1]['children'][0]['current']);
});

test('lo que no es válido se descarta al guardar y nada peligroso llega a un enlace', function (): void {
    $app = cmsApp();
    $about = cmsInsertPage($app, ['title' => 'Quiénes somos']);
    $web = adminClient($app);
    cmsSaveMenu($web, 'main', [
        ['type' => 'url', 'url' => 'javascript:alert(1)', 'level' => '0', 'labels' => ['es' => 'Malo']],
        ['type' => 'url', 'url' => "java\tscript:alert(1)", 'level' => '0', 'labels' => ['es' => 'Malo 2']],
        ['type' => 'url', 'url' => '', 'level' => '0', 'labels' => ['es' => 'Vacío']],
        ['type' => 'otra-cosa', 'ref' => '1', 'level' => '0', 'labels' => ['es' => 'Tipo raro']],
        ['type' => 'page', 'ref' => '99999', 'level' => '0', 'labels' => ['es' => 'No existe']],
        ['type' => 'category', 'ref' => '1', 'level' => '0', 'labels' => ['es' => 'Sin catálogo']],
        ['type' => 'product', 'ref' => '1', 'level' => '0', 'labels' => ['es' => 'Sin catálogo']],
        ['type' => 'page', 'ref' => (string) $about, 'level' => '1', 'labels' => ['es' => '  <b>Bueno</b> & "feliz"  ', 'en' => '']],
        ['type' => 'url', 'url' => '/contacto', 'level' => '0', 'labels' => ['es' => 'Contacto']],
        'esto no es una fila',
    ]);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {menu_items}'));
    $menu = cmsMenuService($app)->menu('main', 'es');
    same(['Bueno & "feliz"', 'Contacto'], array_map(static function (array $i): string {
        return $i['label'];
    }, $menu), 'la etiqueta pierde las etiquetas HTML y los espacios, y un nivel 1 sin padre pasa a nivel 0');
    same('/contacto', $menu[1]['url'], 'una dirección del sitio lleva su subcarpeta e idioma');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {menu_items} WHERE parent_id IS NOT NULL'));
    same(['Bueno & "feliz"', 'Contacto'], array_map(static function (array $i): string {
        return $i['label'];
    }, cmsMenuService($app)->menu('main', 'en')), 'sin etiqueta en inglés, la del idioma principal');
});

test('volver a guardar un menú sustituye todos sus elementos', function (): void {
    $app = cmsApp();
    $about = cmsInsertPage($app, ['title' => 'Quiénes somos']);
    $web = adminClient($app);
    cmsSaveMenu($web, 'main', [['type' => 'home', 'level' => '0', 'labels' => ['es' => 'A']], ['type' => 'home', 'level' => '0', 'labels' => ['es' => 'B']]]);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {menu_items}'));
    cmsSaveMenu($web, 'main', [['type' => 'page', 'ref' => (string) $about, 'level' => '0', 'labels' => ['es' => 'Solo uno']]]);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {menu_items}'));
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {menus}'), 'el menú no se duplica');
    cmsSaveMenu($web, 'main', []);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {menu_items}'));
    same([], cmsMenuService($app)->menu('main'));
});

test('el editor de menús abre con los elementos guardados escapados, y un menú que el tema no tiene da 404', function (): void {
    $app = cmsApp();
    $about = cmsInsertPage($app, ['title' => 'Té & <b>café</b>']);
    $web = adminClient($app);
    cmsSaveMenu($web, 'main', [['type' => 'page', 'ref' => (string) $about, 'level' => '0', 'labels' => ['es' => 'Etiqueta & <i>propia</i>', 'en' => '']]]);

    $index = $web->get('/admin/cms/menus');
    same(200, $index->status);
    contains('/admin/cms/menus/main', $index->body);
    contains('/admin/cms/menus/footer', $index->body);
    $screen = $web->get('/admin/cms/menus/main');
    same(200, $screen->status);
    contains('Té &amp; &lt;b&gt;café&lt;/b&gt;', $screen->body);
    contains('Etiqueta &amp; propia', $screen->body);
    notContains('<b>café</b>', $screen->body);
    contains('data-cms-list', $screen->body);
    contains('data-cms-template', $screen->body);
    contains('menus.js', $screen->body, 'carga su JavaScript');
    same(404, $web->get('/admin/cms/menus/no-existe')->status);
    $web->get('/admin/cms/menus');
    same(404, $web->post('/admin/cms/menus/no-existe', ['_token' => aifecToken($web), 'items' => []])->status);
});

test('el buscador del editor encuentra páginas, entradas y categorías del blog por su título y no se deja engañar por % ni _', function (): void {
    $app = cmsApp();
    cmsInsertPage($app, ['title' => 'Quiénes somos']);
    cmsInsertPage($app, ['title' => 'Condiciones 100%']);
    cmsInsertPost($app, ['title' => 'Cómo cuidar la madera']);
    cmsTerm($app, 'Cuidados');
    $web = adminClient($app);

    $web->get('/admin/cms/menus/search?type=page&q=quién');
    same(['Quiénes somos'], array_map(static function (array $i): string {
        return $i['label'];
    }, $web->json()['items']));
    $web->get('/admin/cms/menus/search?type=page&q=%25');
    same(['Condiciones 100%'], array_map(static function (array $i): string {
        return $i['label'];
    }, $web->json()['items']), 'el % solo encuentra un %');
    $web->get('/admin/cms/menus/search?type=page&q=_');
    same([], $web->json()['items']);
    $web->get('/admin/cms/menus/search?type=post&q=madera');
    same(1, count($web->json()['items']));
    $web->get('/admin/cms/menus/search?type=term&q=cuid');
    same(['Cuidados'], array_map(static function (array $i): string {
        return $i['label'];
    }, $web->json()['items']));
    $web->get('/admin/cms/menus/search?type=product&q=a');
    same([], $web->json()['items'], 'sin catálogo no hay productos');
    same(400, $web->get('/admin/cms/menus/search?type=otra&q=a')->status);
});

test('con catálogo, un menú puede apuntar a una categoría y a un producto, y salen con su dirección', function (): void {
    $app = cmsApp(['cms', 'catalog']);
    $category = aifecCategory($app, 'Salón');
    $product = aifecProduct($app, ['name' => 'Mesa de roble']);
    $web = adminClient($app);
    cmsSaveMenu($web, 'main', [
        ['type' => 'category', 'ref' => (string) $category, 'level' => '0', 'labels' => ['es' => '']],
        ['type' => 'product', 'ref' => (string) $product, 'level' => '1', 'labels' => ['es' => '']],
    ]);
    $menu = cmsMenuService($app)->menu('main', 'es');
    same('Salón', $menu[0]['label']);
    contains('/categoria/salon', $menu[0]['url']);
    same('Mesa de roble', $menu[0]['children'][0]['label']);
    contains('/producto/', $menu[0]['children'][0]['url']);

    $web->get('/admin/cms/menus/search?type=product&q=mesa');
    same(['Mesa de roble'], array_map(static function (array $i): string {
        return $i['label'];
    }, $web->json()['items']));
    $web->get('/admin/cms/menus/search?type=category&q=sal');
    same(['Salón'], array_map(static function (array $i): string {
        return $i['label'];
    }, $web->json()['items']));
    $app->db()->update('categories', ['active' => 0], ['id' => $category]);
    $app->invalidate(['cms', 'catalog', 'category:' . $category]);
    same([], cmsMenuService($app)->menu('main', 'es'), 'una categoría desactivada y lo que cuelga de ella no salen');
});
