<?php
/**
 * AIFEC — pruebas de package:apply (ContentApply): aplicar las páginas, los menús y el tema de un paquete a un sitio que ya existe,
 * sin borrar nada, sin repetir nada y con un ensayo que no escribe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Sites\ContentApply;
use Aifec\Modules\Sites\Package;

require_once __DIR__ . '/helpers.php';
require_once dirname(__DIR__, 2) . '/catalog/tests/helpers.php';

/**
 * Un sitio de prueba con el gestor de contenidos, los temas y la biblioteca de medios (y lo que se pida), ya arrancado.
 *
 * @param string[]            $modules
 * @param array<string,mixed> $config
 */
function aifecApplyApp(array $modules = ['cms', 'themes', 'media'], array $config = []): App
{
    $app = testApp(['modules' => $modules, 'config' => $config]);
    $app->boot();

    return $app;
}

/**
 * Una carpeta de paquetes con el paquete «mini»: tres páginas (la primera es la portada) y dos menús. Devuelve la carpeta.
 *
 * @param array<string,mixed> $extra claves de package.json que se añaden o cambian
 */
function aifecApplyPackages(array $extra = [], bool $withCategories = false): string
{
    $dir = tmpDir('aifec-apply');
    $pkg = $dir . '/mini';
    mkdir($pkg, 0777, true);
    $text = static function (string $html): array {
        return ['type' => 'paragraph', 'attrs' => ['html' => $html]];
    };
    $pages = [
        ['code' => 'inicio', 'title' => 'Inicio de prueba', 'home' => true, 'excerpt' => 'Un resumen.', 'meta_title' => 'Título SEO', 'blocks' => [
            ['type' => 'heading', 'attrs' => ['level' => 1, 'html' => 'Una tienda <em>de prueba</em> & más']], $text('Texto de la portada.'),
        ]],
        ['code' => 'sobre', 'title' => 'Sobre nosotros', 'blocks' => [$text('Quiénes somos.')]],
        ['code' => 'contacto', 'title' => 'Contacto', 'blocks' => [$text('Escríbenos.')]],
    ];
    $menus = [
        ['code' => 'main', 'name' => 'Menú principal', 'items' => [
            ['type' => 'home', 'label' => 'Inicio'],
            ['type' => 'page', 'ref' => 'sobre', 'label' => 'Sobre nosotros'],
            ['type' => 'url', 'url' => '/demos', 'label' => 'Demos'],
            ['type' => 'url', 'url' => '#tienda', 'label' => 'Tienda'],
        ]],
        ['code' => 'footer', 'name' => 'Pie', 'items' => [['type' => 'page', 'ref' => 'contacto', 'label' => 'Contacto']]],
    ];
    $files = ['pages.json', 'menus.json'];
    file_put_contents($pkg . '/pages.json', json_encode($pages, JSON_UNESCAPED_UNICODE));
    file_put_contents($pkg . '/menus.json', json_encode($menus, JSON_UNESCAPED_UNICODE));
    if ($withCategories) {
        file_put_contents($pkg . '/categories.json', json_encode([['code' => 'sillas', 'name' => 'Sillas']], JSON_UNESCAPED_UNICODE));
        $files[] = 'categories.json';
    }
    $meta = [
        'name' => 'Paquete mini', 'description' => 'Un paquete de prueba.', 'sector' => 'producto', 'locale' => 'es',
        'theme' => 'aifec', 'theme_options' => ['footer_principles' => 0],
        'store' => ['name' => 'Tienda de prueba', 'email' => 'tienda@ejemplo.test', 'currency' => 'EUR', 'currency_symbol' => '€', 'country' => 'ES', 'prices_include_tax' => true, 'display_with_tax' => true],
        'settings' => [], 'modules' => ['cms', 'media', 'themes'], 'files' => $files,
    ];
    file_put_contents($pkg . '/package.json', json_encode($extra + $meta, JSON_UNESCAPED_UNICODE));

    return $dir;
}

/**
 * Ejecuta «package:apply» como lo hace la consola.
 *
 * @param array<int|string,string|bool> $args
 *
 * @return array{0:int,1:string} código de salida y lo escrito
 */
function aifecApplyCli(App $app, string $packages, array $args): array
{
    $app->settings()->set('sites.packages_dir', $packages);
    $commands = require Autoload::root() . '/modules/sites/cli.php';
    $io = new Console(false);
    $code = $commands['package:apply']['run']($app, $args, $io);

    return [(int) $code, $io->output];
}

/**
 * Una huella de lo que hay en esas tablas (todas las filas, todas las columnas).
 *
 * @param string[] $tables
 */
function aifecApplyHash(App $app, array $tables): string
{
    $out = [];
    foreach ($tables as $table) {
        if ($app->db()->tableExists($table)) {
            $out[$table] = $app->db()->select('SELECT * FROM {' . $table . '} ORDER BY 1');
        }
    }

    return md5((string) json_encode($out));
}

function aifecApplyCount(App $app, string $table): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {' . $table . '}');
}

const AIFEC_APPLY_CONTENT = ['pages', 'page_i18n', 'menus', 'menu_items', 'revisions', 'slugs', 'media'];

test('aplicar a un sitio vacío crea las tres páginas, los dos menús y activa el tema con sus opciones', function (): void {
    $app = aifecApplyApp();
    $dir = aifecApplyPackages();
    [$code, $out] = aifecApplyCli($app, $dir, ['mini']);
    same(0, $code, $out);
    same(3, aifecApplyCount($app, 'pages'));
    same(3, aifecApplyCount($app, 'page_i18n'));
    same(2, aifecApplyCount($app, 'menus'));
    same(5, aifecApplyCount($app, 'menu_items'), 'cuatro elementos en el principal y uno en el pie');
    same('aifec', $app->settings()->str('theme.active'));
    same(0, (int) $app->service('themes')->options('aifec')['values']['footer_principles'], 'la opción del paquete llega al tema');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {pages} WHERE is_home = 1'), 'una sola portada');
    $home = $app->service('cms')->page((int) $app->db()->value('SELECT id FROM {pages} WHERE is_home = 1'));
    same('Inicio de prueba', $home['title']);
    contains('<em>de prueba</em>', $home['html']);
    $main = $app->service('cms')->menu('main');
    same(['Inicio', 'Sobre nosotros', 'Demos', 'Tienda'], array_map(static function (array $i): string {
        return $i['label'];
    }, $main));
});

test('la última línea es «APPLIED: p páginas (c nuevas, a actualizadas), m menús, tema <slug>»', function (): void {
    $app = aifecApplyApp();
    [$code, $out] = aifecApplyCli($app, aifecApplyPackages(), ['mini']);
    same(0, $code);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    same('APPLIED: 3 páginas (3 nuevas, 0 actualizadas), 2 menús, tema aifec', end($lines));
    contains('page inicio: create', $out);
    contains('menu main: create (4 items)', $out);
    contains('theme aifec: activate', $out);
});

test('una segunda pasada deja lo mismo: mismos identificadores, mismo número de filas y nada repetido', function (): void {
    $app = aifecApplyApp();
    $dir = aifecApplyPackages();
    aifecApplyCli($app, $dir, ['mini']);
    $ids = $app->db()->column('SELECT id FROM {pages} ORDER BY id');
    $menuIds = $app->db()->column('SELECT id FROM {menu_items} ORDER BY id');
    $before = aifecApplyHash($app, AIFEC_APPLY_CONTENT);
    [$code, $out] = aifecApplyCli($app, $dir, ['mini']);
    same(0, $code, $out);
    same($ids, $app->db()->column('SELECT id FROM {pages} ORDER BY id'), 'las mismas páginas, con los mismos identificadores');
    same($menuIds, $app->db()->column('SELECT id FROM {menu_items} ORDER BY id'), 'los mismos elementos de menú: ninguno borrado y vuelto a crear');
    same(3, aifecApplyCount($app, 'page_i18n'));
    same(2, aifecApplyCount($app, 'menus'));
    same(5, aifecApplyCount($app, 'menu_items'));
    same($before, aifecApplyHash($app, AIFEC_APPLY_CONTENT), 'ni una fila distinta');
    contains('page inicio: no changes', $out);
    contains('menu main: no changes', $out);
    contains('theme aifec: no changes', $out);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    same('APPLIED: 3 páginas (0 nuevas, 0 actualizadas), 2 menús, tema aifec', end($lines));
});

test('una página cambiada a mano vuelve al texto del paquete, deja su versión anterior y no se toca otra página que el paquete no trae', function (): void {
    $app = aifecApplyApp();
    $dir = aifecApplyPackages();
    aifecApplyCli($app, $dir, ['mini']);
    $db = $app->db();
    $idSobre = (int) $db->value('SELECT page_id FROM {page_i18n} WHERE title = ?', ['Sobre nosotros']);
    $db->update('page_i18n', ['title' => 'Cambiado a mano', 'blocks' => '[{"type":"paragraph","attrs":{"html":"Otro texto"}}]'], ['page_id' => $idSobre]);
    // Una página propia del comerciante, que el paquete no trae.
    $now = $app->clock()->sql();
    $mine = $db->insert('pages', ['status' => 'published', 'template' => 'default', 'position' => 9, 'noindex' => 0, 'is_home' => 0, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('page_i18n', ['page_id' => $mine, 'lang' => 'es', 'title' => 'Mi página', 'blocks' => '[]']);
    $app->slugs()->set('page', $mine, 'es', 'mi-pagina');
    $revisions = aifecApplyCount($app, 'revisions');

    [$code, $out] = aifecApplyCli($app, $dir, ['mini']);
    same(0, $code, $out);
    contains('page sobre: update', $out);
    same('Sobre nosotros', (string) $db->value('SELECT title FROM {page_i18n} WHERE page_id = ?', [$idSobre]), 'el título vuelve al del paquete');
    contains('Quiénes somos.', (string) $db->value('SELECT blocks FROM {page_i18n} WHERE page_id = ?', [$idSobre]));
    same($revisions + 1, aifecApplyCount($app, 'revisions'), 'lo que había se guarda en el historial antes de pisarlo');
    same('Mi página', (string) $db->value('SELECT title FROM {page_i18n} WHERE page_id = ?', [$mine]), 'la página que el paquete no trae sigue igual');
    same(4, aifecApplyCount($app, 'pages'), 'nada borrado: las tres del paquete y la propia');
    $lines = array_values(array_filter(explode("\n", trim($out))));
    same('APPLIED: 3 páginas (0 nuevas, 1 actualizadas), 2 menús, tema aifec', end($lines));
});

test('la portada del paquete pasa a ser la del sitio y la que lo era deja de serlo sin borrarse', function (): void {
    $app = aifecApplyApp();
    $db = $app->db();
    $now = $app->clock()->sql();
    $old = $db->insert('pages', ['status' => 'published', 'template' => 'default', 'position' => 0, 'noindex' => 0, 'is_home' => 1, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $db->insert('page_i18n', ['page_id' => $old, 'lang' => 'es', 'title' => 'Portada antigua', 'blocks' => '[]']);
    $app->slugs()->set('page', $old, 'es', 'portada-antigua');
    aifecApplyCli($app, aifecApplyPackages(), ['mini']);
    same(0, (int) $db->value('SELECT is_home FROM {pages} WHERE id = ?', [$old]));
    same('Inicio de prueba', (string) $db->value('SELECT x.title FROM {pages} p JOIN {page_i18n} x ON x.page_id = p.id WHERE p.is_home = 1'));
    same(1, (int) $db->value('SELECT COUNT(*) FROM {pages} WHERE is_home = 1'));
    same(4, aifecApplyCount($app, 'pages'));
});

test('el menú de otro código no se toca, y uno del paquete sustituye sus elementos por los del paquete', function (): void {
    $app = aifecApplyApp();
    $cms = $app->service('cms');
    $cms->menus()->save('lateral', 'Menú lateral', [['type' => 'url', 'url' => '/aviso', 'labels' => ['es' => 'Aviso']]]);
    $cms->menus()->save('footer', 'Pie', [['type' => 'url', 'url' => '/viejo', 'labels' => ['es' => 'Viejo']]]);
    $lateralBefore = $app->db()->select('SELECT * FROM {menu_items} WHERE menu_id = (SELECT id FROM {menus} WHERE code = ?)', ['lateral']);
    aifecApplyCli($app, aifecApplyPackages(), ['mini']);
    same($lateralBefore, $app->db()->select('SELECT * FROM {menu_items} WHERE menu_id = (SELECT id FROM {menus} WHERE code = ?)', ['lateral']), 'el menú lateral, intacto');
    same(['Contacto'], array_map(static function (array $i): string {
        return $i['label'];
    }, $cms->menu('footer')), 'el pie lleva solo lo del paquete');
    same(3, aifecApplyCount($app, 'menus'));
});

test('--only=menus no crea páginas y avisa de los elementos que enlazan una página que el sitio no tiene', function (): void {
    $app = aifecApplyApp();
    [$code, $out] = aifecApplyCli($app, aifecApplyPackages(), ['mini', 'only' => 'menus']);
    same(0, $code, $out);
    same(0, aifecApplyCount($app, 'pages'), 'ninguna página');
    same(2, aifecApplyCount($app, 'menus'));
    same(3, aifecApplyCount($app, 'menu_items'), 'inicio, demos y tienda; el elemento de la página «sobre» y el del pie no entran');
    contains('NOTE: Menu «main»: «Sobre nosotros» was skipped', $out);
    check(preg_match('/^page /m', $out) === 0, 'no habla de ninguna página');
    check(preg_match('/^theme /m', $out) === 0, 'ni del tema');
    same('', $app->settings()->str('theme.active', ''), 'ni el tema');
});

test('--only=pages no toca los menús ni el tema, y --only=theme solo activa el tema', function (): void {
    $app = aifecApplyApp();
    $dir = aifecApplyPackages();
    [$code] = aifecApplyCli($app, $dir, ['mini', 'only' => 'pages']);
    same(0, $code);
    same(3, aifecApplyCount($app, 'pages'));
    same(0, aifecApplyCount($app, 'menus'));
    same('', $app->settings()->str('theme.active', ''));
    [$code, $out] = aifecApplyCli($app, $dir, ['mini', 'only' => 'theme']);
    same(0, $code);
    same('aifec', $app->settings()->str('theme.active'));
    same(0, aifecApplyCount($app, 'menus'));
    contains('APPLIED: 0 páginas (0 nuevas, 0 actualizadas), 0 menús, tema aifec', $out);
});

test('--dry-run no escribe nada: la base queda idéntica y dice qué haría, también la segunda vez', function (): void {
    $app = aifecApplyApp();
    $dir = aifecApplyPackages();
    $app->settings()->set('sites.packages_dir', $dir);
    $empty = aifecApplyHash($app, array_merge(AIFEC_APPLY_CONTENT, ['settings']));
    [$code, $out] = aifecApplyCli($app, $dir, ['mini', 'dry-run' => true]);
    same(0, $code, $out);
    same($empty, aifecApplyHash($app, array_merge(AIFEC_APPLY_CONTENT, ['settings'])), 'la base idéntica después del ensayo');
    contains('page inicio: would create', $out);
    contains('menu main: would create (4 items)', $out);
    contains('theme aifec: would activate', $out);
    $lines = array_values(array_filter(explode("\n", trim($out))));
    same('DRY-RUN: 3 páginas (3 nuevas, 0 actualizadas), 2 menús, tema aifec', end($lines));

    aifecApplyCli($app, $dir, ['mini']);
    $db = $app->db();
    $db->update('page_i18n', ['title' => 'Cambiado'], ['page_id' => (int) $db->value('SELECT page_id FROM {page_i18n} WHERE title = ?', ['Contacto'])]);
    $full = aifecApplyHash($app, array_merge(AIFEC_APPLY_CONTENT, ['settings']));
    [$code, $out] = aifecApplyCli($app, $dir, ['mini', 'dry-run' => true]);
    same(0, $code, $out);
    same($full, aifecApplyHash($app, array_merge(AIFEC_APPLY_CONTENT, ['settings'])), 'tampoco escribe cuando ya hay contenido');
    contains('page contacto: would update', $out);
    contains('page inicio: no changes', $out);
    contains('menu footer: no changes', $out);
});

test('productos, categorías, pedidos y el nombre de la tienda quedan iguales antes y después', function (): void {
    $app = aifecApplyApp(['cms', 'themes', 'media', 'catalog', 'orders']);
    $category = aifecCategory($app, 'Sillas de prueba');
    aifecProduct($app, ['name' => 'Silla de prueba', 'categories' => [$category], 'price' => 4990]);
    aifecProduct($app, ['name' => 'Mesa de prueba', 'price' => 9900]);
    $app->settings()->set('store.name', 'Mi tienda real');
    $app->settings()->set('shop.currency', 'EUR');
    $tables = ['products', 'product_i18n', 'categories', 'category_i18n', 'variants', 'orders', 'order_items'];
    $before = aifecApplyHash($app, $tables);
    $name = $app->settings()->str('store.name');

    [$code, $out] = aifecApplyCli($app, aifecApplyPackages(), ['mini']);
    same(0, $code, $out);
    same($before, aifecApplyHash($app, $tables), 'catálogo y pedidos, intactos');
    same($name, $app->settings()->str('store.name'), 'el nombre de la tienda no se toca aunque el paquete traiga el suyo');
    same('EUR', $app->settings()->str('shop.currency'));
    same(2, aifecApplyCount($app, 'products'));
    same(1, aifecApplyCount($app, 'categories'));
});

test('un paquete con catálogo no lo carga: lo dice en una línea y sigue', function (): void {
    $app = aifecApplyApp(['cms', 'themes', 'media', 'catalog']);
    [$code, $out] = aifecApplyCli($app, aifecApplyPackages([], true), ['mini']);
    same(0, $code, $out);
    same(0, aifecApplyCount($app, 'categories'), 'la categoría del paquete no se crea');
    same(1, substr_count($out, 'NOTE: The package also brings categories.json'), 'una sola línea de aviso');
    same(3, aifecApplyCount($app, 'pages'), 'y las páginas sí');
});

test('un paquete que no existe da un error claro y el código 1, sin escribir nada', function (): void {
    $app = aifecApplyApp();
    $before = aifecApplyHash($app, AIFEC_APPLY_CONTENT);
    [$code, $out] = aifecApplyCli($app, aifecApplyPackages(), ['no-existe']);
    same(1, $code);
    contains('ERROR: the package does not exist', $out);
    contains('cannot be applied', $out);
    same($before, aifecApplyHash($app, AIFEC_APPLY_CONTENT));
    [$code, $out] = aifecApplyCli($app, aifecApplyPackages(), []);
    same(2, $code, 'sin nombre de paquete es un error de uso');
    contains('Usage: package:apply', $out);
});

test('una parte desconocida en --only da el código 2 y no toca nada', function (): void {
    $app = aifecApplyApp();
    $before = aifecApplyHash($app, AIFEC_APPLY_CONTENT);
    [$code, $out] = aifecApplyCli($app, aifecApplyPackages(), ['mini', 'only' => 'pages,productos']);
    same(2, $code);
    contains('Unknown part «productos»', $out);
    same($before, aifecApplyHash($app, AIFEC_APPLY_CONTENT));
});

test('en el sitio maestro funciona: no destruye nada, a diferencia de demo:create', function (): void {
    $app = aifecApplyApp(['cms', 'themes', 'media'], ['master' => true]);
    same(true, \Aifec\Modules\Sites\Demos::isMaster($app), 'el sitio de la prueba es el maestro');
    [$code, $out] = aifecApplyCli($app, aifecApplyPackages(), ['mini']);
    same(0, $code, $out);
    same(3, aifecApplyCount($app, 'pages'));
    same('aifec', $app->settings()->str('theme.active'));
});

test('una página del paquete cuya dirección ya es de otro contenido no se aplica y no se escribe nada', function (): void {
    $app = aifecApplyApp();
    $app->slugs()->set('post', 99, 'es', 'sobre');
    $before = aifecApplyHash($app, AIFEC_APPLY_CONTENT);
    [$code, $out] = aifecApplyCli($app, aifecApplyPackages(), ['mini']);
    same(1, $code);
    contains('already used by another kind of content (post)', $out);
    contains('(nothing was written)', $out);
    same($before, aifecApplyHash($app, AIFEC_APPLY_CONTENT), 'la primera página, que sí valía, tampoco se ha escrito');
});

test('el texto con «&» y tildes llega a la base tal cual y no se escapa dos veces', function (): void {
    $app = aifecApplyApp();
    aifecApplyCli($app, aifecApplyPackages(), ['mini']);
    $blocks = (string) $app->db()->value('SELECT blocks FROM {page_i18n} WHERE title = ?', ['Inicio de prueba']);
    contains('Una tienda <em>de prueba</em> &amp; más', $blocks);
    notContains('&amp;amp;', $blocks);
    same('Título SEO', (string) $app->db()->value('SELECT meta_title FROM {page_i18n} WHERE title = ?', ['Inicio de prueba']));
});

test('ContentApply por código: el resultado dice qué se hace con cada página, cada menú y el tema, y una parte desconocida es un error', function (): void {
    $app = aifecApplyApp();
    $dir = aifecApplyPackages();
    $pkg = Package::load($dir, 'mini', Autoload::root());
    $result = (new ContentApply($app, $pkg))->run(['pages'], true);
    same(['create', 'create', 'create'], array_column($result['pages'], 'action'));
    same([], $result['menus']);
    same(null, $result['theme']);
    same(3, $result['counts']['created']);
    $result = (new ContentApply($app, $pkg))->run(['theme'], true);
    same('aifec', $result['theme']['slug']);
    same('activate', $result['theme']['action'], 'el sitio nuevo trae el tema base');
    $result = (new ContentApply($app, $pkg))->run([], true);
    same(3, count($result['pages']));
    same(2, count($result['menus']));
    throws(function () use ($app, $pkg): void {
        (new ContentApply($app, $pkg))->run(['otra'], true);
    }, \InvalidArgumentException::class);
});

test('una página legal del paquete («legal»: código) la escribe el generador con los datos de Ajustes y queda apuntada', function (): void {
    $app = aifecApplyApp();
    $dir = aifecApplyPackages();
    $file = $dir . '/mini/pages.json';
    $pages = json_decode((string) file_get_contents($file), true);
    $pages[] = ['code' => 'aviso-legal', 'title' => 'Aviso legal', 'legal' => 'legal-notice', 'blocks' => []];
    file_put_contents($file, json_encode($pages, JSON_UNESCAPED_UNICODE));
    // Lo que había antes: el texto de ejemplo que el paquete marcaba con «keep» (14/11/2026).
    $app->settings()->set('store.vat_id', 'B11112222');
    [$code, $out] = aifecApplyCli($app, $dir, ['mini']);
    same(0, $code, $out);
    $id = (int) $app->slugs()->resolve('es', 'aviso-legal')['entity_id'];
    check($id > 0, 'la página existe');
    same($id, (int) ($app->settings()->arr('cms.legal')['legal-notice'] ?? 0), 'queda apuntada como el aviso legal');
    $html = (string) $app->service('cms')->page($id)['html'];
    contains('Titular de este sitio web', $html);
    contains('B11112222', $html, 'el NIF de Ajustes');
    notContains('…', $html, 'lo que no hay en Ajustes no sale');
    $app->settings()->set('store.vat_id', 'B33334444');
    $app->invalidate(['settings']);
    contains('B33334444', (string) $app->service('cms')->page($id)['html'], 'cambia con Ajustes, sin volver a aplicar el paquete');
    // Una segunda pasada no cambia nada.
    [$code2, $out2] = aifecApplyCli($app, $dir, ['mini']);
    same(0, $code2, $out2);
    contains('aviso-legal: no changes', $out2);
});
