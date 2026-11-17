<?php
/**
 * AIFEC — pruebas de las pantallas del panel «Diseño»: la galería, el informe, el personalizador, la portada, el logotipo y el
 * asistente de creación. Cada pantalla se abre con permiso, da 403 sin él y guarda lo que debe (releyendo la base de datos).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Response;

require_once __DIR__ . '/ThemesKit.php';

/**
 * Manda un formulario del panel con la ficha de la última página abierta.
 *
 * @param array<string,mixed> $data
 */
function themesPanelPost(TestClient $web, string $path, array $data = []): Response
{
    if ($web->last === null || strpos($web->last->body, 'name="af-token"') === false) {
        $web->get('/admin');
    }

    return $web->post($path, ['_token' => aifecToken($web)] + $data);
}

/** Un papel que solo puede ver (no cambiar) el aspecto de la tienda. */
function themesViewer(App $app): TestClient
{
    $app->db()->insert('roles', ['code' => 'tviewer', 'name' => 'Viewer', 'caps' => json_encode(['dashboard.view', 'appearance.view']), 'system' => 0]);

    return adminClient($app, 'tviewer');
}

themesTest('la galería enseña una tarjeta por tema con sus botones, de quién hereda y la nota del validador, y escapa los nombres', function (): void {
    $root = themesRoot();
    themesChild($root, 'hijo', [], ['name' => 'Tienda & <b>nueva</b>']);
    $app = themesApp($root);
    $web = adminClient($app);
    $r = $web->get('/admin/themes');
    same(200, $r->status);
    contains('data-theme="base"', $r->body);
    contains('data-theme="hijo"', $r->body);
    contains('Tienda &amp; &lt;b&gt;nueva&lt;/b&gt;', $r->body);
    notContains('Tienda & <b>nueva</b>', $r->body);
    contains('Tema hijo de base', $r->body);
    contains('Tu tienda tiene ahora el aspecto del tema', $r->body, 'dice con palabras cuál es el aspecto actual');
    contains('Un tema hijo es una copia', $r->body, 'y qué es un tema hijo');
    contains('Personalizar', $r->body);
    contains('Validar', $r->body);
    contains('Activo', $r->body);
    contains('/admin/themes/hijo/activate', $r->body);
    notContains('/admin/themes/base/activate', $r->body, 'el tema activo no se vuelve a activar');
    contains('/admin/themes/preview/base.png', $r->body);
    contains('Calidad ', $r->body);
    notContains('Puntuación', $r->body);

    $bare = themesRoot();
    unlink($bare . '/themes/base/preview.png');
    $noPreview = adminClient(themesApp($bare))->get('/admin/themes');
    contains('Sin vista previa', $noPreview->body);
    notContains('/admin/themes/preview/', $noPreview->body);
});

themesTest('un tema con un error bloqueante no se puede activar desde la galería, y uno con theme.json roto no se puede ni personalizar', function (): void {
    $root = themesRoot();
    $layout = (string) file_get_contents($root . '/themes/base/templates/layout.php');
    themesChild($root, 'roto', ['templates/layout.php' => str_replace('page_head(', 'otra(', $layout)]);
    mkdir($root . '/themes/ilegible', 0777, true);
    file_put_contents($root . '/themes/ilegible/theme.json', '{no');
    $app = themesApp($root);
    $body = adminClient($app)->get('/admin/themes')->body;
    contains('errores bloqueantes', $body);
    contains('theme.json no válido', $body);
    contains('/admin/themes/roto/activate', $body);
    same(1, preg_match('#/admin/themes/roto/activate.*?<button[^>]*disabled#s', $body), 'el botón de activar va desactivado');
    notContains('/admin/themes/ilegible/activate', $body);
    notContains('/admin/themes/customise/ilegible', $body);
    contains('/admin/themes/customise/roto', $body);
});

themesTest('sin permiso todas las pantallas dan 403; con solo «ver» se ve pero no se cambia nada', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $support = adminClient($app, 'support');
    foreach (['/admin/themes', '/admin/themes/home', '/admin/themes/brand', '/admin/themes/new', '/admin/themes/customise/base', '/admin/themes/base/report', '/admin/themes/preview/base.png'] as $path) {
        same(403, $support->get($path)->status, $path);
    }
    foreach (['/admin/themes/base/activate', '/admin/themes/customise/base', '/admin/themes/home', '/admin/themes/brand', '/admin/themes/new'] as $path) {
        same(403, themesPanelPost($support, $path, ['f' => ['accent' => '#112233']])->status, 'POST ' . $path);
    }
    same(null, themesSetting($app, 'theme.options'));

    $viewer = themesViewer($app);
    foreach (['/admin/themes', '/admin/themes/home', '/admin/themes/brand', '/admin/themes/customise/base', '/admin/themes/base/report'] as $path) {
        same(200, $viewer->get($path)->status, $path);
    }
    same(403, $viewer->get('/admin/themes/new')->status);
    $list = $viewer->get('/admin/themes')->body;
    notContains('/admin/themes/new', $list, 'sin permiso de editar no hay botón de crear');
    $screen = $viewer->get('/admin/themes/customise/base')->body;
    notContains('value="publish"', $screen);
    foreach (['/admin/themes/base/activate', '/admin/themes/customise/base', '/admin/themes/home', '/admin/themes/brand'] as $path) {
        same(403, themesPanelPost($viewer, $path, ['f' => ['accent' => '#112233'], 'do' => 'publish'])->status, 'POST ' . $path);
    }
    same(null, themesSetting($app, 'theme.options'));
    same(null, themesSetting($app, 'theme.active'));
});

themesTest('crear un tema es un permiso aparte: quien solo puede personalizar no abre el asistente ni crea nada en disco', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $app->db()->insert('roles', ['code' => 'teditor', 'name' => 'Editor', 'caps' => json_encode(['dashboard.view', 'appearance.view', 'appearance.edit']), 'system' => 0]);
    $editor = adminClient($app, 'teditor');
    same(200, $editor->get('/admin/themes/customise/base')->status, 'personalizar sí');
    same(403, $editor->get('/admin/themes/new')->status, 'el asistente de tema nuevo, no');
    $before = count((array) glob($app->root() . '/themes/*', GLOB_ONLYDIR));
    same(403, themesPanelPost($editor, '/admin/themes/new', ['f' => ['name' => 'Tema colado', 'slug' => 'tema-colado', 'parent' => 'base']])->status, 'y el alta tampoco');
    same($before, count((array) glob($app->root() . '/themes/*', GLOB_ONLYDIR)), 'no se ha escrito ninguna carpeta de tema');
    notContains('/admin/themes/new', $editor->get('/admin/themes')->body, 'ni se le enseña el botón');

    $app->db()->insert('roles', ['code' => 'tmaker', 'name' => 'Maker', 'caps' => json_encode(['dashboard.view', 'appearance.*']), 'system' => 0]);
    same(200, adminClient($app, 'tmaker')->get('/admin/themes/new')->status, 'con el permiso de crear, el asistente se abre');
});

themesTest('activar desde el panel guarda theme.active y lo deja en el registro; uno bloqueado lleva al informe y no cambia nada', function (): void {
    $root = themesRoot();
    $layout = (string) file_get_contents($root . '/themes/base/templates/layout.php');
    themesChild($root, 'bueno');
    themesChild($root, 'roto', ['templates/layout.php' => str_replace('page_head(', 'otra(', $layout)]);
    $app = themesApp($root);
    $web = adminClient($app);
    $r = themesPanelPost($web, '/admin/themes/roto/activate');
    same(302, $r->status);
    contains('/admin/themes/roto/report', $r->headers['Location']);
    contains('errores bloqueantes', $web->follow()->body);
    same(null, themesSetting($app, 'theme.active'));

    $r = themesPanelPost($web, '/admin/themes/bueno/activate');
    same(302, $r->status);
    same('bueno', themesSetting($app, 'theme.active'));
    contains('Tema activado', $web->follow()->body);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'theme.activate' AND entity_id = ?", ['bueno']));

    $r = themesPanelPost($web, '/admin/themes/no-existe/activate');
    same(404, $r->status);
});

themesTest('sin ficha del formulario no se hace nada', function (): void {
    $root = themesRoot();
    themesChild($root, 'bueno');
    $app = themesApp($root);
    $web = adminClient($app);
    $r = $web->post('/admin/themes/bueno/activate', []);
    same(302, $r->status);
    same(null, themesSetting($app, 'theme.active'));
    $r = $web->post('/admin/themes/customise/base', ['do' => 'publish', 'f' => ['accent' => '#112233']]);
    same(302, $r->status);
    same(null, themesSetting($app, 'theme.options'));
});

themesTest('el informe enseña cada comprobación con su resultado; un tema que no existe da 404', function (): void {
    $root = themesRoot();
    $layout = (string) file_get_contents($root . '/themes/base/templates/layout.php');
    themesChild($root, 'roto', ['templates/layout.php' => str_replace('page_head(', 'otra(', $layout)]);
    $app = themesApp($root);
    $web = adminClient($app);
    $ok = $web->get('/admin/themes/base/report');
    same(200, $ok->status);
    contains('Informe del tema', $ok->body);
    contains('data-check="wrapper-head"', $ok->body);
    contains('data-check="live-home"', $ok->body, 'el informe incluye las comprobaciones en vivo');
    contains('Pasa el validador', $ok->body);
    $bad = $web->get('/admin/themes/roto/report');
    contains('Tiene errores bloqueantes', $bad->body);
    contains('El envoltorio tiene que llamar a page_head()', $bad->body);
    contains('templates/layout.php', $bad->body);
    notContains('/admin/themes/roto/activate', $bad->body, 'no se ofrece activar un tema con errores');
    same(404, $web->get('/admin/themes/no-existe/report')->status);
});

themesTest('la vista previa del tema se sirve como imagen; sin ella o con otro nombre, 404', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $web = adminClient($app);
    $r = $web->get('/admin/themes/preview/base.png');
    same(200, $r->status);
    same('image/png', $r->headers['Content-Type']);
    same(themesPng(), (string) file_get_contents((string) $r->file));
    same(404, $web->get('/admin/themes/preview/no-existe.png')->status);
    unlink($root . '/themes/base/preview.png');
    same(404, adminClient(themesApp($root))->get('/admin/themes/preview/base.png')->status);
});

themesTest('el personalizador tiene una pestaña por grupo con prefijo propio, el formulario de los campos y la vista previa', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $web = adminClient($app);
    $r = $web->get('/admin/themes/customise/base');
    same(200, $r->status);
    $body = $r->body;
    foreach (['thcolors', 'thtypography', 'thlayout'] as $tab) {
        contains('data-af-tab="' . $tab . '"', $body);
        contains('data-af-panel="' . $tab . '"', $body);
    }
    foreach (['general', 'content', 'main'] as $clash) {
        notContains('data-af-tab="' . $clash . '"', $body, 'una clave de pestaña suelta puede chocar con un id del panel');
    }
    contains('name="f[accent]"', $body);
    contains('name="f[radius]"', $body);
    contains('name="f[header_style]"', $body);
    contains('name="f[show_brand_in_card]"', $body);
    contains('#b94a17', $body);
    contains('name="_tab"', $body);
    contains('name="_token"', $body);
    contains('value="preview"', $body);
    contains('value="publish"', $body);
    contains('value="reset"', $body);
    contains('data-af-confirm', $body);
    contains('data-th-frame', $body);
    contains('af_preview=1', $body);
    contains('data-th-page', $body);
    contains('data-th-size', $body);
    contains('Portada', $body);
    contains('Escritorio', $body);
    contains('Móvil', $body);
    same(404, $web->get('/admin/themes/customise/no-existe')->status);
});

themesTest('publicar guarda en theme.options (releyendo la base), un valor malo da error en su campo y un interruptor se apaga con «0»', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $web = adminClient($app);
    $r = themesPanelPost($web, '/admin/themes/customise/base', ['do' => 'publish', '_tab' => 'thcolors', 'f' => [
        'accent' => '#112233', 'radius' => '16', 'header_style' => 'centered', 'show_brand_in_card' => '0',
    ]]);
    same(302, $r->status);
    contains('tab=thcolors', $r->headers['Location']);
    same(['base' => ['accent' => '#112233', 'radius' => 16, 'header_style' => 'centered', 'show_brand_in_card' => 0]], themesSetting($app, 'theme.options'));
    contains('Publicado', $web->follow()->body);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'theme.options'"));
    $home = (new TestClient(themesReboot($app, $root)))->get('/')->body;
    contains('--accent:#112233', $home);
    contains('af-header af-header-centered', $home);

    $r = themesPanelPost($web, '/admin/themes/customise/base', ['do' => 'publish', '_tab' => 'thcolors', 'f' => ['accent' => 'esto no es un color', 'ink' => '#000000', 'radius' => '99']]);
    same(200, $r->status, 'con errores se vuelve a pintar el formulario');
    contains('Ese valor no es válido', $r->body);
    contains('esto no es un color', $r->body, 'lo escrito se conserva');
    same(['base' => ['accent' => '#112233', 'radius' => 16, 'header_style' => 'centered', 'show_brand_in_card' => 0, 'ink' => '#000000']], themesSetting($app, 'theme.options'), 'lo válido se guarda y lo malo no toca lo que había');
});

themesTest('«Restablecer» borra lo publicado de ese tema y lleva a la misma pestaña', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $web = adminClient($app);
    themesPanelPost($web, '/admin/themes/customise/base', ['do' => 'publish', 'f' => ['accent' => '#112233']]);
    same(['base' => ['accent' => '#112233']], themesSetting($app, 'theme.options'));
    $r = themesPanelPost($web, '/admin/themes/customise/base', ['do' => 'reset', '_tab' => 'thlayout']);
    same(302, $r->status);
    contains('tab=thlayout', $r->headers['Location']);
    same([], themesSetting($app, 'theme.options'));
    contains('Han vuelto los valores de fábrica', $web->follow()->body);
});

themesTest('la portada: se abre, se reordena con los botones y las casillas, y se guarda en home.order y home.hidden', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $app->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'uno', 'order' => 10, 'html' => '<p>UNO</p>'];
        $sections[] = ['id' => 'dos', 'order' => 20, 'html' => '<p>DOS</p>'];
        $sections[] = ['id' => 'tres', 'order' => 30, 'html' => '<p>TRES</p>'];

        return $sections;
    });
    $web = adminClient($app);
    $r = $web->get('/admin/themes/home');
    same(200, $r->status);
    foreach (['uno', 'dos', 'tres'] as $id) {
        contains('data-section="' . $id . '"', $r->body);
    }
    contains('value="uno:down"', $r->body);

    $r = themesPanelPost($web, '/admin/themes/home', ['order' => ['uno', 'dos', 'tres'], 'show' => ['uno', 'tres'], 'move' => 'tres:up']);
    same(302, $r->status);
    same(['uno', 'tres', 'dos'], themesSetting($app, 'home.order'));
    same(['dos'], themesSetting($app, 'home.hidden'));
    contains('Guardado', $web->follow()->body);
    $after = $web->get('/admin/themes/home')->body;
    check(strpos($after, 'data-section="tres"') < strpos($after, 'data-section="dos"'), 'el orden se ve en la pantalla');

    themesPanelPost($web, '/admin/themes/home', ['order' => ['uno', 'tres', 'dos'], 'show' => ['uno', 'tres', 'dos'], 'move' => 'uno:up']);
    same(['uno', 'tres', 'dos'], themesSetting($app, 'home.order'), 'subir el primero no cambia nada');
    themesPanelPost($web, '/admin/themes/home', ['order' => ['uno', 'tres', 'dos'], 'show' => ['uno', 'tres', 'dos'], 'move' => 'dos:down']);
    same(['uno', 'tres', 'dos'], themesSetting($app, 'home.order'), 'bajar el último no cambia nada');
    same([], themesSetting($app, 'home.hidden'));
    $body = (new TestClient($app))->get('/')->body;
    check(strpos($body, 'TRES') !== false && strpos($body, 'TRES') < strpos($body, 'DOS'), 'el orden llega a la tienda');
});

themesTest('la portada sin secciones lo dice, y el logotipo y el icono se guardan como ajustes de la tienda', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $web = adminClient($app);
    contains('Todavía no hay secciones', $web->get('/admin/themes/home')->body);
    contains('Activa el módulo de medios', $web->get('/admin/themes/brand')->body);

    $r = themesPanelPost($web, '/admin/themes/brand', ['f' => ['logo' => '12', 'icon' => '34,35']]);
    same(302, $r->status);
    same(12, themesSetting($app, 'store.logo'));
    same(34, themesSetting($app, 'store.icon'));
    contains('Guardado', $web->follow()->body);
    themesPanelPost($web, '/admin/themes/brand', ['f' => ['logo' => '', 'icon' => 'x']]);
    same(0, themesSetting($app, 'store.logo'));
    same(0, themesSetting($app, 'store.icon'));
});

themesTest('el asistente crea un tema, lleva a personalizarlo y lo deja en la galería; un identificador repetido da error y no escribe', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $web = adminClient($app);
    $form = $web->get('/admin/themes/new');
    same(200, $form->status);
    foreach (['f[slug]', 'f[name]', 'f[parent]', 'f[header]', 'f[cards]', 'f[columns]', 'f[bg]', 'f[accent]', 'f[font]'] as $name) {
        contains('name="' . $name . '"', $form->body);
    }
    notContains('value="suggest"', $form->body, 'sin IA no hay botón de proponer');
    notContains('name="f[describe]"', $form->body);

    $r = themesPanelPost($web, '/admin/themes/new', ['do' => 'create', 'f' => [
        'slug' => 'mi-tienda', 'name' => 'Mi tienda & más', 'parent' => 'base', 'header' => 'centered', 'cards' => 'portrait', 'columns' => '4',
        'bg' => '', 'accent' => '#0b5394', 'font' => 'serif',
    ]]);
    same(302, $r->status);
    contains('/admin/themes/customise/mi-tienda', $r->headers['Location']);
    check(is_file($root . '/themes/mi-tienda/theme.json'), 'se ha creado la carpeta');
    $json = json_decode((string) file_get_contents($root . '/themes/mi-tienda/theme.json'), true);
    same('Mi tienda & más', $json['name']);
    same('#0b5394', $json['settings']['colors']['fields']['accent']['default']);
    contains('Tema creado', $web->follow()->body);
    contains('Mi tienda &amp; más', $web->get('/admin/themes')->body);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'theme.create'"));

    $before = Aifec\Modules\Themes\Files::files($root);
    $again = themesPanelPost($web, '/admin/themes/new', ['do' => 'create', 'f' => ['slug' => 'mi-tienda', 'parent' => 'base']]);
    same(200, $again->status);
    contains('Ya existe un tema con ese identificador', $again->body);
    contains('value="mi-tienda"', $again->body, 'lo escrito se conserva');
    $bad = themesPanelPost($web, '/admin/themes/new', ['do' => 'create', 'f' => ['slug' => '../fuera', 'parent' => 'base']]);
    same(200, $bad->status);
    contains('El identificador del tema no es válido', $bad->body);
    $color = themesPanelPost($web, '/admin/themes/new', ['do' => 'create', 'f' => ['slug' => 'otro', 'parent' => 'base', 'accent' => 'rojo']]);
    contains('El color de acento no es válido', $color->body);
    same($before, Aifec\Modules\Themes\Files::files($root), 'ningún rechazo escribe nada');
});

themesTest('con IA encendida el asistente propone una paleta que la persona revisa antes de crear; nada se crea al proponer', function (): void {
    $root = themesRoot();
    $app = themesApp($root);
    $ai = themesFakeAi($app, ['accent' => '#ffee00', 'bg' => '#ffffff', 'font' => 'serif']);
    $web = adminClient($app);
    $form = $web->get('/admin/themes/new');
    contains('value="suggest"', $form->body);
    contains('name="f[describe]"', $form->body);
    $before = Aifec\Modules\Themes\Files::files($root);
    $r = themesPanelPost($web, '/admin/themes/new', ['do' => 'suggest', 'f' => ['slug' => 'velas', 'name' => 'Velas', 'parent' => 'base', 'describe' => 'Velas artesanales de cera de soja']]);
    same(200, $r->status);
    contains('Revisa los colores y la tipografía propuestos', $r->body);
    contains('se ha oscurecido o aclarado', $r->body, 'avisa de que ajustó el acento');
    notContains('#ffee00', $r->body, 'el acento sin contraste no llega a la pantalla');
    contains('value="velas"', $r->body, 'lo ya escrito se conserva');
    contains('Velas artesanales de cera de soja', $r->body);
    same($before, Aifec\Modules\Themes\Files::files($root), 'proponer no crea nada');
    same(1, count($ai->calls));
    same('theme.palette', $ai->calls[0]['task']);

    $fail = themesFakeAi($app, null, true, true);
    $r2 = themesPanelPost($web, '/admin/themes/new', ['do' => 'suggest', 'f' => ['slug' => 'velas', 'describe' => 'Algo']]);
    same(200, $r2->status);
    contains('La IA no ha podido proponer una paleta', $r2->body);
    same(1, count($fail->calls));
});
