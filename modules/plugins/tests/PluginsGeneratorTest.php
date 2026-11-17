<?php
/**
 * AIFEC — pruebas del generador de plugins y del plugin de ejemplo: lo generado es PHP válido, trae todo lo prometido y pasa
 * el validador, con una sola pieza y con todas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Plugins\Fs;
use Aifec\Modules\Plugins\Generator;

require_once __DIR__ . '/PluginsKit.php';

/** Módulos que hacen falta para que se pueda activar un plugin con forma de pago y transportista de ejemplo. */
const PLUGINS_COMMERCE = ['orders', 'catalog', 'customers', 'tax', 'shipping', 'coupons'];

/**
 * Comprueba que todos los PHP de un plugin generado son sintácticamente válidos (token_get_all con TOKEN_PARSE lanza
 * ParseError si no lo son).
 */
function pluginsAssertValidPhp(string $dir): int
{
    $count = 0;
    foreach (Fs::files($dir) as $rel) {
        if (substr($rel, -4) === '.php') {
            token_get_all((string) file_get_contents($dir . '/' . $rel), TOKEN_PARSE);
            ++$count;
        }
    }

    return $count;
}

pluginsTest('el generador crea un plugin con manifiesto completo, catálogo, prueba y LEEME, y es PHP válido', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    $r = (new Generator($svc))->create('mi-primero', 'Mi primero', ['setting', 'listener'], ['description' => 'Mi primer plugin', 'author' => 'Ana Ruiz']);
    same(true, $r['ok'], (string) json_encode($r));
    $dir = $root . '/plugins/mi-primero';
    foreach (['plugin.json', 'hooks.php', 'settings.php', 'lang/es.php', 'tests/MiPrimeroTest.php', 'LEEME.md'] as $file) {
        check(is_file($dir . '/' . $file), 'existe ' . $file);
    }
    check(!is_file($dir . '/admin.php'), 'no crea lo que no se pidió');
    $manifest = json_decode((string) file_get_contents($dir . '/plugin.json'), true);
    same('Mi primero', $manifest['name']);
    same('0.1.0', $manifest['version']);
    same('Plugins\\MiPrimero', $manifest['namespace']);
    same(false, $manifest['default']);
    same('>=7.4', $manifest['requires']['php']);
    same([], $manifest['requires']['modules']);
    same(['hosts' => [], 'personal_data' => [], 'tables' => [], 'capabilities' => []], $manifest['contract']);
    contains('Ana Ruiz', (string) file_get_contents($dir . '/hooks.php'));
    check(pluginsAssertValidPhp($dir) >= 4, 'todos sus PHP son válidos');
    $lang = require $dir . '/lang/es.php';
    same('Mi primero', $lang['Mi primero']);
    same('Activar este plugin', $lang['Enable this plugin']);
    same(false, $svc->find('mi-primero')['active']);
});

pluginsTest('lo generado con una sola pieza pasa las comprobaciones estáticas, pieza a pieza', function (): void {
    $root = pluginsRoot(PLUGINS_COMMERCE);
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    foreach (Generator::FEATURES as $feature) {
        $id = 'solo-' . $feature;
        $r = (new Generator($svc))->create($id, 'Solo ' . $feature, [$feature]);
        same(true, $r['ok'], (string) json_encode($r));
        pluginsAssertValidPhp($root . '/plugins/' . $id);
        $report = pluginsStatic($app, $root, $id);
        same([], pluginsFailed($report), $feature . ":\n" . pluginsMessages($report));
        same([], pluginsFailed($report, 'warning'), $feature . ":\n" . pluginsMessages($report));
    }
});

pluginsTest('un plugin recién generado con todas las opciones pasa el validador con ok, también el dinámico', function (): void {
    $root = pluginsRoot(PLUGINS_COMMERCE);
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    $r = (new Generator($svc))->create('todo-junto', 'Todo junto', Generator::FEATURES);
    same(true, $r['ok'], (string) json_encode($r));
    same(['plugin.json', 'hooks.php', 'settings.php', 'admin.php', 'migrations/0001_items.php', 'views/front/note.php', 'blocks.php', 'jobs.php', 'src/Tasks.php', 'cli.php', 'src/ExamplePayment.php', 'src/ExampleCarrier.php', 'lang/es.php', 'tests/TodoJuntoTest.php', 'LEEME.md'], $r['files']);
    pluginsAssertValidPhp($root . '/plugins/todo-junto');
    $manifest = json_decode((string) file_get_contents($root . '/plugins/todo-junto/plugin.json'), true);
    same(['todo_junto_items'], $manifest['contract']['tables']);
    same(['orders', 'shipping'], $manifest['requires']['modules']);
    $report = $svc->validate('todo-junto');
    same([], pluginsFailed($report), pluginsMessages($report));
    same([], pluginsFailed($report, 'warning'), pluginsMessages($report));
    same(true, $report['ok']);
    same(true, $report['dynamic']);
    same(100, $report['score']);
    same(true, $svc->enable('todo-junto')['ok']);
    check($app->db()->tableExists('todo_junto_items'), 'al activar se crea su tabla');
});

pluginsTest('el generador rechaza un identificador no válido, uno reservado y uno que ya existe', function (): void {
    $root = pluginsRoot(['catalog', 'tax']);
    $svc = pluginsSvc(pluginsApp($root));
    $gen = new Generator($svc);
    foreach (['A', 'Mayusculas', 'con espacio', 'con_guion_bajo', '-empieza', '../fuera', ''] as $bad) {
        same(false, $gen->create($bad, 'X', [])['ok'], 'rechaza «' . $bad . '»');
    }
    $reserved = $gen->create('shop', 'Tienda', []);
    same(false, $reserved['ok']);
    contains('reservado', (string) $reserved['message']);
    same(false, $gen->create('catalog', 'Catálogo', [])['ok']);
    same(true, $gen->create('ya-esta', 'Ya está', [])['ok']);
    $again = $gen->create('ya-esta', 'Ya está', []);
    same(false, $again['ok']);
    contains('ya existe', (string) $again['message']);
    same(['ya-esta'], array_map('basename', array_filter(glob($root . '/plugins/*') ?: [], 'is_dir')));
});

pluginsTest('el nombre con comillas, «&» o cierres de comentario no rompe el PHP generado', function (): void {
    $root = pluginsRoot();
    $svc = pluginsSvc(pluginsApp($root));
    $r = (new Generator($svc))->create('nombre-raro', "O'Hara & Hijos */ <b>", ['setting', 'screen']);
    same(true, $r['ok']);
    pluginsAssertValidPhp($root . '/plugins/nombre-raro');
    $manifest = json_decode((string) file_get_contents($root . '/plugins/nombre-raro/plugin.json'), true);
    notContains('*/', $manifest['name']);
    notContains('<', $manifest['name']);
    contains('Hara', $manifest['name']);
    same([], pluginsFailed(pluginsStatic(pluginsApp($root), $root, 'nombre-raro')));
});

pluginsTest('el plugin de ejemplo hello-aifec pasa el validador con ok y trae lo que enseña la guía', function (): void {
    $app = testApp(['modules' => ['plugins', 'catalog', 'hello-aifec'], 'settings' => ['modules.active' => ['plugins', 'catalog']]]);
    $app->boot();
    $svc = pluginsSvc($app);
    $row = $svc->find('hello-aifec');
    same('plugin', $row['kind']);
    same(false, $row['active']);
    same(false, $row['default']);
    same(['hello_aifec_made_in'], $row['contract']['tables']);
    $report = $svc->validate('hello-aifec');
    same([], pluginsFailed($report), pluginsMessages($report));
    same(true, $report['ok']);
    same(true, $report['dynamic']);
    same(100, $report['score']);
    foreach (['plugin.json', 'hooks.php', 'settings.php', 'lang/es.php', 'migrations/0001_made_in.php', 'views/front/made-in.php', 'src/MadeIn.php', 'tests/HelloAifecTest.php', 'LEEME.md'] as $file) {
        check(is_file(dirname(__DIR__, 3) . '/plugins/hello-aifec/' . $file), 'hello-aifec trae ' . $file);
    }
});
