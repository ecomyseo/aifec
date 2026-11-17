<?php
/**
 * AIFEC — pruebas de la instalación de un plugin desde un ZIP: uno bueno se instala sin activar; los malos se rechazan
 * y no dejan nada escrito en plugins/.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Plugins\ZipInstaller;

require_once __DIR__ . '/PluginsKit.php';

/** Instala un ZIP y comprueba que se rechaza y que en plugins/ no hay nada nuevo. */
function pluginsRejected(string $zip, string $mustSay): void
{
    $root = pluginsRoot();
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    $before = pluginsListed($root);
    $r = $svc->install($zip);
    same(false, $r['ok'], (string) json_encode($r));
    contains($mustSay, (string) $r['message']);
    same($before, pluginsListed($root));
    same([], array_filter(glob($root . '/plugins/*') ?: [], 'is_dir'));
}

pluginsTest('un ZIP bueno se instala en plugins/ sin activarse y se puede activar después', function (): void {
    $root = pluginsRoot();
    $app = pluginsApp($root);
    $svc = pluginsSvc($app);
    $r = $svc->install(pluginsZipOf('zip-bueno'));
    same(true, $r['ok'], (string) json_encode($r));
    same('zip-bueno', $r['id']);
    check(is_file($root . '/plugins/zip-bueno/plugin.json'), 'su plugin.json está en plugins/zip-bueno');
    check(is_file($root . '/plugins/zip-bueno/lang/es.php'), 'y su catálogo');
    $row = $svc->find('zip-bueno');
    same('plugin', $row['kind']);
    same(false, $row['active']);
    same(null, $app->settings()->get('modules.active'));
    same(false, $r['report']['dynamic']);
    same(true, $r['report']['ok']);
    $again = $svc->install(pluginsZipOf('zip-bueno'));
    same(false, $again['ok']);
    contains('ya está instalado', (string) $again['message']);
    same(true, $svc->enable('zip-bueno')['ok']);
});

pluginsTest('un ZIP con «../» en una ruta se rechaza y no escribe nada', function (): void {
    $json = pluginsFiles('evil')['plugin.json'];
    pluginsRejected(pluginsRawZip([
        ['name' => 'evil/plugin.json', 'data' => $json],
        ['name' => 'evil/../../fuera.php', 'data' => '<?php echo 1;'],
    ]), 'ruta que no se permite');
});

pluginsTest('un ZIP con una ruta absoluta o de otra unidad se rechaza', function (): void {
    $json = pluginsFiles('evil')['plugin.json'];
    pluginsRejected(pluginsRawZip([['name' => 'evil/plugin.json', 'data' => $json], ['name' => '/etc/cron.d/x', 'data' => 'x']]), 'ruta que no se permite');
    pluginsRejected(pluginsRawZip([['name' => 'evil/plugin.json', 'data' => $json], ['name' => 'C:/Windows/x.php', 'data' => 'x']]), 'ruta que no se permite');
});

pluginsTest('un ZIP con dos carpetas raíz se rechaza', function (): void {
    $files = pluginsFiles('uno');
    pluginsRejected(pluginsZip(['uno/plugin.json' => $files['plugin.json'], 'dos/plugin.json' => $files['plugin.json']]), 'una sola carpeta');
});

pluginsTest('un ZIP con ficheros sueltos fuera de la carpeta del plugin se rechaza', function (): void {
    $files = pluginsFiles('uno');
    pluginsRejected(pluginsZip(['uno/plugin.json' => $files['plugin.json'], 'LEEME.txt' => 'hola']), 'fuera de la carpeta');
});

pluginsTest('un ZIP sin plugin.json se rechaza', function (): void {
    pluginsRejected(pluginsZip(['sin-manifiesto/hooks.php' => "<?php\n"]), 'no tiene plugin.json');
});

pluginsTest('un ZIP con un plugin.json que no es válido se rechaza', function (): void {
    pluginsRejected(pluginsZip(['roto-json/plugin.json' => '{no es json']), 'plugin.json');
});

pluginsTest('un ZIP cuya carpeta no es un identificador válido se rechaza', function (): void {
    $files = pluginsFiles('x');
    pluginsRejected(pluginsZip(['Mala_Carpeta/plugin.json' => $files['plugin.json']]), 'identificador válido');
});

pluginsTest('un ZIP con un enlace simbólico se rechaza', function (): void {
    $json = pluginsFiles('enlace')['plugin.json'];
    pluginsRejected(pluginsRawZip([['name' => 'enlace/plugin.json', 'data' => $json], ['name' => 'enlace/passwd', 'data' => '/etc/passwd', 'link' => true]]), 'enlace simbólico');
});

pluginsTest('un ZIP demasiado grande una vez descomprimido se rechaza', function (): void {
    $files = pluginsFiles('enorme');
    pluginsRejected(pluginsZip(['enorme/plugin.json' => $files['plugin.json'], 'enorme/assets/grande.bin' => str_repeat('a', ZipInstaller::MAX_BYTES + 1024)]), '20 MB');
});

pluginsTest('un ZIP con demasiados ficheros se rechaza', function (): void {
    $files = pluginsFiles('muchos');
    $entries = ['muchos/plugin.json' => $files['plugin.json']];
    for ($i = 0; $i <= ZipInstaller::MAX_FILES; $i++) {
        $entries['muchos/assets/f' . $i . '.txt'] = 'x';
    }
    pluginsRejected(pluginsZip($entries), 'más de');
});

pluginsTest('un ZIP con eval( en el código se rechaza por el validador y no se escribe nada', function (): void {
    pluginsRejected(pluginsZipOf('con-eval', ['files' => ['hooks.php' => pluginsHooks("    eval('return 1;');")]]), 'no pasa las comprobaciones');
});

pluginsTest('un ZIP con código que falla el contrato dice qué regla y en qué fichero', function (): void {
    $root = pluginsRoot();
    $svc = pluginsSvc(pluginsApp($root));
    $r = $svc->install(pluginsZipOf('con-mail', ['files' => ['hooks.php' => pluginsHooks("    mail('a@cliente.test', 'x', 'y');")]]));
    same(false, $r['ok']);
    contains('C6', implode("\n", $r['errors']));
    contains('hooks.php', implode("\n", $r['errors']));
    same([], pluginsListed($root));
});

pluginsTest('un fichero que no es un ZIP, o que no llegó, se rechaza con su mensaje', function (): void {
    $app = pluginsApp(pluginsRoot());
    $svc = pluginsSvc($app);
    $bad = tmpDir() . '/no-es.zip';
    file_put_contents($bad, 'esto no es un zip');
    $r = $svc->install($bad);
    same(false, $r['ok']);
    contains('no es un ZIP', (string) $r['message']);
    $r = $svc->install(tmpDir() . '/no-existe.zip');
    same(false, $r['ok']);
    contains('No se ha recibido', (string) $r['message']);
});
