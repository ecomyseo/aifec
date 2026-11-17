<?php
/**
 * AIFEC — pruebas de las copias de seguridad: fichero consistente, conservación, descarga con permiso, tarea nocturna y copia en segundo plano.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Tools\Backups;
use Aifec\Modules\Tools\ToolsTasks;

require_once __DIR__ . '/helpers.php';

/** Abre una copia como base de datos SQLite (descomprimiéndola si hace falta) y la deja lista para consultar. */
function toolsOpenBackup(string $path): PDO
{
    $file = $path;
    if (substr($path, -4) === '.zip') {
        $file = tmpDir('tools-backup') . '/abierta.sqlite';
        $zip = new ZipArchive();
        check($zip->open($path) === true, 'la copia es un zip');
        $plain = $zip->getFromName('db/database.sqlite');
        $zip->close();
        check(is_string($plain) && $plain !== '', 'el zip trae la base de datos');
        file_put_contents($file, $plain);
    } elseif (substr($path, -3) === '.gz') {
        $file = tmpDir('tools-backup') . '/abierta.sqlite';
        $plain = gzdecode((string) file_get_contents($path));
        check(is_string($plain) && $plain !== '', 'la copia se descomprime');
        file_put_contents($file, $plain);
    }
    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    return $pdo;
}

test('una copia se crea en la carpeta privada, se abre como SQLite y trae las tablas y los datos', function (): void {
    $app = toolsApp(['tools']);
    $app->settings()->set('demo.clave', 'valor con ñ & <b>');
    $backups = new Backups($app);
    $info = $backups->create();

    check(preg_match('/^backup-20261112-120000-01\.zip$/', $info['name']) === 1, 'el nombre sale de la fecha: ' . $info['name']);
    check($info['bytes'] > 1000, 'la copia no está vacía');
    $path = $app->site()->path('private/backups') . '/' . $info['name'];
    check(is_file($path), 'está en la carpeta privada del sitio');
    same($info['bytes'], (int) filesize($path));

    $pdo = toolsOpenBackup($path);
    $tables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('af_settings', $tables, true) && in_array('af_users', $tables, true), 'tiene las tablas del sitio');
    $value = $pdo->query("SELECT v FROM af_settings WHERE k = 'demo.clave'")->fetchColumn();
    same('valor con ñ & <b>', json_decode((string) $value, true));
    same('ok', (string) $pdo->query('PRAGMA integrity_check')->fetchColumn(), 'la copia es consistente');

    same('valor con ñ & <b>', $app->settings()->str('demo.clave'), 'el sitio sigue funcionando después de la copia');
    same(0, count(glob($app->site()->path('private/backups') . '/.tmp-*') ?: []), 'no se deja ningún temporal');
});

test('la carpeta de copias se protege y no cuelga de la carpeta pública', function (): void {
    $app = toolsApp(['tools']);
    $backups = new Backups($app);
    $dir = $backups->dir();
    check(is_file($dir . '/.htaccess'), 'lleva su .htaccess');
    contains('Require all denied', (string) file_get_contents($dir . '/.htaccess'));
    contains('Deny from all', (string) file_get_contents($dir . '/.htaccess'));
    check(strpos($dir, rtrim(str_replace('\\', '/', $app->publicDir()), '/') . '/') !== 0, 'no está dentro de la carpeta pública');
    check(strpos($dir, '/private/backups') !== false);
});

test('se conservan solo las últimas copias y las del mismo segundo no se pisan', function (): void {
    $app = toolsApp(['tools']);
    $backups = new Backups($app);
    $names = [];
    for ($i = 1; $i <= 10; ++$i) {
        $names[] = $backups->create()['name'];
    }
    same(10, count(array_unique($names)), 'diez copias en el mismo segundo, diez nombres');
    $left = array_column($backups->all(), 'name');
    same(7, count($left), 'por defecto se guardan 7');
    same(array_reverse(array_slice($names, 3)), $left, 'se quedan las 7 últimas, la más reciente primero');
    contains('-10.', $left[0]);
    contains('-04.', $left[6]);

    $app->settings()->set('tools.keep_backups', 3);
    $backups->create();
    same(3, count($backups->all()), 'el ajuste manda');
    contains('-11.', $backups->all()[0]['name']);

    $app->clock()->advance(3600);
    $later = $backups->create();
    contains('backup-20261112-130000-01.', $later['name']);
    same($later['name'], $backups->all()[0]['name'], 'una copia de otra hora va la primera');
});

test('la lista de copias trae nombre, fecha y tamaño, y solo reconoce sus propios nombres', function (): void {
    $app = toolsApp(['tools']);
    $backups = new Backups($app);
    $info = $backups->create();
    $dir = $backups->dir();
    file_put_contents($dir . '/notas.txt', 'ajeno');
    file_put_contents($dir . '/backup-malo.sqlite', 'nombre que no es de una copia');

    $all = $backups->all();
    same(1, count($all));
    same($info['name'], $all[0]['name']);
    same('2026-11-12 12:00:00', $all[0]['created_at']);
    same('zip', $all[0]['kind']);
    same(false, $all[0]['verified'], 'recién hecha, todavía no se ha comprobado');
    same($info['bytes'], $all[0]['bytes']);
    same($dir . '/' . $info['name'], $backups->path($info['name']));
    same(null, $backups->path('notas.txt'));
    same(null, $backups->path('../db.sqlite'));
    same(false, $backups->delete('backup-malo.sqlite'), 'no borra lo que no es una copia');
    check(is_file($dir . '/backup-malo.sqlite'));
    same(true, $backups->delete($info['name']));
    same([], $backups->all());
    check($backups->databaseSize() > 0, 'se sabe cuánto ocupa la base de datos');
});

test('desde el panel se crea, se descarga y se borra una copia, con permiso y auditado', function (): void {
    $app = toolsApp(['tools']);
    $web = adminClient($app);
    $r = $web->get('/admin/tools/backups');
    same(200, $r->status);
    contains('Todavía no hay copias', $r->body);

    $r = $web->post('/admin/tools/backups/create', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    $page = $web->follow();
    same(200, $page->status);
    contains('Copia creada: backup-20261112-120000-01', $page->body);
    $name = (new Backups($app))->all()[0]['name'];
    contains($name, $page->body);
    contains('/admin/tools/backups/download/' . $name, $page->body);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'tools.backup'"));

    $d = $web->get('/admin/tools/backups/download/' . $name);
    same(200, $d->status);
    same($app->site()->path('private/backups') . '/' . $name, $d->file);
    contains('attachment', $d->headers['Content-Disposition']);
    contains($name, $d->headers['Content-Disposition']);
    contains('application/zip', $d->headers['Content-Type']);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'tools.backup_download'"));

    same(404, $web->get('/admin/tools/backups/download/backup-19990101-000000-01.sqlite')->status);
    same(404, $web->get('/admin/tools/backups/download/..')->status);
    $web->get('/admin/tools/backups');
    same(404, $web->post('/admin/tools/backups/delete/notas.txt', ['_token' => aifecToken($web)])->status);

    $web->get('/admin/tools/backups');
    $r = $web->post('/admin/tools/backups/delete/' . $name, ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same([], (new Backups($app))->all());
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'tools.backup_delete'"));
});

test('sin el permiso tools.backup no se ve, no se crea, no se descarga y no se borra', function (): void {
    $app = toolsApp(['tools']);
    $name = (new Backups($app))->create()['name'];
    toolsRole($app, 'mirador', ['dashboard.view', 'tools.view', 'tools.edit']);
    aifecAdmin($app, 'mirador', 'mirador@tienda.test', 'Mirador');
    $viewer = new TestClient($app);
    aifecLogin($viewer, 'mirador@tienda.test');
    $viewer->get('/admin');
    $token = aifecToken($viewer);

    same(403, $viewer->get('/admin/tools/backups')->status);
    same(403, $viewer->get('/admin/tools/backups/download/' . $name)->status);
    same(403, $viewer->post('/admin/tools/backups/create', ['_token' => $token])->status);
    same(403, $viewer->post('/admin/tools/backups/delete/' . $name, ['_token' => $token])->status);
    same(1, count((new Backups($app))->all()), 'no se ha tocado nada');

    $support = adminClient($app, 'support');
    same(403, $support->get('/admin/tools/backups')->status);
    same(403, $support->get('/admin/tools/backups/download/' . $name)->status);
});

test('la tarea nocturna crea la copia y se puede apagar con el ajuste', function (): void {
    $app = toolsApp(['tools', 'jobs']);
    $note = $app->service('jobs')->runTask('tools.backup');
    contains('Backup backup-20261112-120000-01', (string) $note);
    same(1, count((new Backups($app))->all()));

    $app->settings()->set('tools.backup_auto', false);
    $off = $app->service('jobs')->runTask('tools.backup');
    contains('Skipped', (string) $off);
    same(1, count((new Backups($app))->all()), 'apagada, no crea nada');

    $tasks = array_column($app->service('jobs')->tasks(), 'every', 'task');
    same('daily@03:10', $tasks['tools.backup']);
    same('daily@03:25', $tasks['tools.logs']);
});

test('la orden backup de la consola crea una copia', function (): void {
    $app = toolsApp(['tools']);
    $commands = (new Console(false))->commands($app);
    $io = new Console(false);
    same(0, $commands['backup']['run']($app, [], $io));
    contains('Backup backup-20261112-120000-01', $io->output);
    same(1, count((new Backups($app))->all()));
});

test('si la base de datos pasa de 20 MB el panel encola la copia y la hace una tarea en segundo plano', function (): void {
    $app = toolsApp(['tools', 'jobs']);
    $web = adminClient($app);
    $app->db()->insert('settings', ['k' => 'relleno.grande', 'v' => json_encode(str_repeat('abcdefghij', 2200000))]);
    check((new Backups($app))->databaseSize() > Backups::BACKGROUND_FROM, 'la base de prueba pesa más de 20 MB');

    $r = $web->post('/admin/tools/backups/create', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same([], (new Backups($app))->all(), 'en la petición no se hace');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'tools.backup_job' AND status = 'pending'"));
    contains('en segundo plano', $web->follow()->body);

    $web->post('/admin/tools/backups/create', ['_token' => aifecToken($web)]);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'tools.backup_job'"), 'pedirla dos veces no la encola dos veces');

    $work = $app->service('jobs')->work(60);
    same(1, $work['done']);
    $all = (new Backups($app))->all();
    same(1, count($all));
    check($all[0]['bytes'] > 1000);
    $pdo = toolsOpenBackup((new Backups($app))->path($all[0]['name']));
    check(strlen((string) $pdo->query("SELECT v FROM af_settings WHERE k = 'relleno.grande'")->fetchColumn()) > 20000000, 'la copia lleva todos los datos');
});

test('el volcado de MySQL escribe NULL, números y textos entre comillas con varias filas por sentencia', function (): void {
    $quote = static function (string $value): string {
        return "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    };
    $sql = Backups::insertStatement('`af_demo`', [
        ['id' => 1, 'name' => "Ana d'Arc", 'note' => null, 'price' => 1999],
        ['id' => 2, 'name' => 'Mía & Ñu <b> 😀', 'note' => 'a\\b', 'price' => '19.99'],
    ], $quote);
    same("INSERT INTO `af_demo` (`id`, `name`, `note`, `price`) VALUES\n(1, 'Ana d\\'Arc', NULL, 1999),\n(2, 'Mía & Ñu <b> 😀', 'a\\\\b', '19.99');\n", $sql);
    same('', Backups::insertStatement('`af_demo`', [], $quote));
});
