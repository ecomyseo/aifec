<?php
/**
 * AIFEC — pruebas de restaurar: ida y vuelta, negativas sin tocar nada, fallos a mitad, partes, otro sitio, panel y consola.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Tools\BackupAreas;
use Aifec\Modules\Tools\BackupCli;
use Aifec\Modules\Tools\BackupController;
use Aifec\Modules\Tools\BackupException;
use Aifec\Modules\Tools\BackupRestorer;
use Aifec\Modules\Tools\Backups;

require_once __DIR__ . '/backup_helpers.php';

if (!function_exists('bkrWreck')) {
    /** Deja el sitio distinto de como estaba: filas fuera, una foto y una factura borradas, un fichero nuevo y un ajuste cambiado. */
    function bkrWreck(App $app): void
    {
        $dirs = BackupAreas::dirs($app);
        $app->db()->run('DELETE FROM {login_attempts}');
        $app->settings()->set('demo.clave', 'cambiada');
        unlink($dirs['media'] . '/product/silla.jpg');
        unlink($dirs['private'] . '/invoices/F-2026-0001.pdf');
        file_put_contents($dirs['private'] . '/nuevo.txt', 'un fichero que la copia no conoce');
        file_put_contents($dirs['media'] . '/product/nueva.jpg', 'otra foto nueva');
    }

    /** Como bkrWreck, para cuando ya se ha restaurado antes y algunos ficheros no están. */
    function bkrWreck2(App $app): void
    {
        $dirs = BackupAreas::dirs($app);
        $app->db()->run('DELETE FROM {login_attempts}');
        $app->settings()->set('demo.clave', 'cambiada otra vez');
        @unlink($dirs['media'] . '/product/silla.jpg');
        file_put_contents($dirs['private'] . '/nuevo2.txt', 'otro fichero nuevo');
    }

    /** Un sitio con datos, una copia completa hecha y lo que había al hacerla. @return array{0:App,1:Backups,2:string,3:array<string,mixed>} */
    function bkrShop(array $modules = ['tools']): array
    {
        $app = toolsApp($modules);
        bkfSeed($app, 5);
        $backups = new Backups($app);
        $name = $backups->create('full')['name'];

        return [$app, $backups, $name, bkfState($app)];
    }

    /** Restaurar tiene que negarse, y el sitio no se toca: ni datos, ni ficheros, ni copia de seguridad, ni carpetas sueltas, ni mantenimiento. */
    function bkrRefuses(App $app, Backups $backups, string $name, string $expect, array $options = []): void
    {
        $snap = bkfState($app);
        $copies = count($backups->all());
        $caught = null;
        try {
            (new BackupRestorer($backups))->restore($name, $options);
        } catch (BackupException $e) {
            $caught = $e;
        }
        check($caught !== null, 'tenía que negarse a restaurar ' . $name);
        if ($caught !== null) {
            contains($expect, $caught->getMessage());
        }
        same($snap, bkfState($app), 'no se ha tocado nada (datos ni ficheros)');
        same($copies, count($backups->all()), 'no se ha hecho ninguna copia de seguridad: nada cambió');
        same([], bkfLeftovers($app), 'no quedan carpetas sueltas');
        same(false, $app->settings()->bool('tools.maintenance'), 'el mantenimiento sigue como estaba');
    }
}

test('restaurar: ida y vuelta, y todo queda igual (filas por tabla, suma de cada fichero y ajustes)', function (): void {
    [$app, $backups, $name, $before] = bkrShop();
    bkrWreck($app);
    $wrecked = bkfState($app);
    check($wrecked !== $before, 'el destrozo se nota');
    same(0, $wrecked['tables']['af_login_attempts']);

    $result = (new BackupRestorer($backups))->restore($name);
    same($before, bkfState($app), 'las cuentas de filas por tabla y la suma de los ficheros vuelven a ser las de la copia');
    same('bkf-marca-1', $app->settings()->str('demo.clave'));
    same(count($before['files']['media']) + count($before['files']['private']), $result['files']);
    same(count($before['tables']) + 5, $result['tables'], 'se restauran todas las tablas (las de la foto y las cinco que la foto no compara)');
    same(false, $app->settings()->bool('tools.maintenance'), 'el mantenimiento vuelve a como estaba');

    // La copia de lo que había.
    check($backups->path($result['safety']) !== null, 'queda la copia de seguridad: ' . $result['safety']);
    same(true, $backups->isVerified($result['safety']), 'y está comprobada');
    $safety = (string) $backups->path($result['safety']);
    $names = bkfZipNames($safety);
    check(in_array('files/private/nuevo.txt', $names, true) && in_array('files/media/product/nueva.jpg', $names, true), 'lleva lo que había justo antes');
    check(!in_array('files/media/product/silla.jpg', $names, true), 'y no lo que ya se había borrado');
    same(0, bkfManifest($safety)['database']['rows_by_table']['af_login_attempts']);

    // Las carpetas viejas se apartan, no se borran.
    same(2, count($result['old']));
    foreach ($result['old'] as $old) {
        check(is_dir($old), 'sigue ahí: ' . $old);
        check(strpos($old, '.before-restore-') !== false);
    }
    $oldPrivate = '';
    foreach ($result['old'] as $old) {
        if (strpos($old, '/private.before-restore-') !== false) {
            $oldPrivate = $old;
        }
    }
    check(is_file($oldPrivate . '/nuevo.txt'), 'en la carpeta apartada está lo que había');
    check(!is_dir($oldPrivate . '/backups'), 'las copias no se quedan en la carpeta apartada: pasan a la nueva');
    check(is_dir(BackupAreas::dirs($app)['private'] . '/backups'), 'la carpeta de copias sigue en su sitio');
    check($backups->path($name) !== null, 'la copia restaurada sigue en la lista');
    same([], glob(BackupAreas::dirs($app)['private'] . '.restore-*') ?: [], 'no quedan carpetas nuevas sin colocar');
    same([], glob($app->site()->path('tmp') . '/restore-*') ?: [], 'el volcado extraído se borra');

    // Restaurar otra vez funciona y deja otras carpetas viejas.
    bkrWreck($app);
    $again = (new BackupRestorer($backups))->restore($name);
    same($before, bkfState($app));
    check($again['old'] !== $result['old'], 'las carpetas apartadas no se pisan');
    check($again['safety'] !== $result['safety']);
});

test('restaurar se niega, sin tocar nada, ante una copia con una suma cambiada, el manifiesto roto, el volcado cortado o de una versión posterior', function (): void {
    [$app, $backups, , ] = bkrShop();
    $make = static function (callable $break) use ($backups, $app): string {
        $app->clock()->advance(1);
        $name = $backups->create('full')['name'];
        $break((string) $backups->path($name), $name);

        return $name;
    };

    $name = $make(static function (string $path): void {
        bkfTamper($path, static function (ZipArchive $zip): void {
            $zip->addFromString('files/media/product/silla.jpg', 'cambiada a mano');
        });
    });
    bkrRefuses($app, $backups, $name, 'The backup is not valid');
    contains('La suma de un fichero no coincide', (function () use ($app, $backups, $name): string {
        try {
            (new BackupRestorer($backups))->restore($name);
        } catch (BackupException $e) {
            return $e->getMessage();
        }

        return '';
    })());

    $name = $make(static function (string $path): void {
        bkfTamper($path, static function (ZipArchive $zip): void {
            $db = (string) $zip->getFromName('db/database.sqlite');
            check(strpos($db, 'bkf-marca-1') !== false, 'el dato de ejemplo está en la base de datos');
            $zip->addFromString('db/database.sqlite', str_replace('bkf-marca-1', 'bkf-marca-2', $db));
        });
    });
    bkrRefuses($app, $backups, $name, 'La suma del volcado de la base de datos no coincide');

    $name = $make(static function (string $path): void {
        bkfTamper($path, static function (ZipArchive $zip): void {
            $zip->addFromString('manifest.json', '{"format": 1,');
        });
    });
    bkrRefuses($app, $backups, $name, 'El manifiesto está roto');

    $name = $make(static function (string $path): void {
        bkfTamper($path, static function (ZipArchive $zip): void {
            $db = (string) $zip->getFromName('db/database.sqlite');
            $zip->addFromString('db/database.sqlite', substr($db, 0, intdiv(strlen($db), 3)));
        });
    });
    bkrRefuses($app, $backups, $name, 'La suma del volcado de la base de datos no coincide');

    $name = $make(static function (string $path): void {
        bkfSetManifest($path, static function (array $m): array {
            $m['version'] = '9.9.9';

            return $m;
        });
    });
    bkrRefuses($app, $backups, $name, 'The backup comes from a newer version of AIFEC');

    $name = $make(static function (string $path): void {
        bkfSetManifest($path, static function (array $m): array {
            $m['database']['engine'] = 'mysql';

            return $m;
        });
    });
    bkrRefuses($app, $backups, $name, 'different database engine');

    $name = $make(static function (string $path): void {
        bkfSetManifest($path, static function (array $m): array {
            $m['database']['prefix'] = 'otro_';

            return $m;
        });
    });
    bkrRefuses($app, $backups, $name, 'different table prefix');

    $name = $make(static function (string $path): void {
        bkfTamper($path, static function (ZipArchive $zip): void {
            $zip->addFromString('config.php', '<?php return ["secret" => "robada"];');
        });
    });
    bkrRefuses($app, $backups, $name, 'The backup is not valid');

    // Una copia de las de antes (sin manifiesto), una que no existe y una parte que no existe.
    file_put_contents($backups->dir() . '/backup-20261001-100000-01.sqlite', 'no es una copia de verdad');
    bkrRefuses($app, $backups, 'backup-20261001-100000-01.sqlite', 'no manifest');
    bkrRefuses($app, $backups, 'backup-19990101-000000-01.zip', 'does not exist');
    bkrRefuses($app, $backups, $backups->all()[0]['name'], 'part to restore is not valid', ['only' => 'todo']);
    $dbOnly = $backups->create('db')['name'];
    bkrRefuses($app, $backups, $dbOnly, 'no files, only the database', ['only' => 'files']);
});

test('un fallo a mitad de la restauración deja la base de datos y los ficheros como estaban, y lo dice', function (): void {
    [$app, $backups, $name, ] = bkrShop();
    bkrWreck($app);
    $dirs = BackupAreas::dirs($app);

    foreach ([['table', 3], ['table', 1], ['files', 1], ['migrate', 1]] as $case) {
        [$stage, $nth] = $case;
        $snap = bkfState($app);
        $n = 0;
        $restorer = new BackupRestorer($backups);
        $restorer->setFault(static function (string $s, string $table) use (&$n, $stage, $nth): void {
            if ($s === $stage && ++$n === $nth) {
                throw new RuntimeException('fallo simulado en ' . $stage . ' ' . $table);
            }
        });
        $caught = null;
        try {
            $restorer->restore($name);
        } catch (RuntimeException $e) {
            $caught = $e;
        }
        check($caught !== null && strpos($caught->getMessage(), 'fallo simulado en ' . $stage) === 0, 'el fallo sale hacia fuera: ' . $stage);
        same($snap, bkfState($app), 'después de fallar en «' . $stage . '» (' . $nth . ') todo está como estaba');
        same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {login_attempts}'), 'las filas siguen sin volver: la base no se quedó a medias');
        check(is_file($dirs['private'] . '/nuevo.txt') && !is_file($dirs['media'] . '/product/silla.jpg'), 'los ficheros siguen como estaban');
        same([], bkfLeftovers($app), 'no quedan carpetas sueltas tras fallar en ' . $stage);
        same(false, $app->settings()->bool('tools.maintenance'), 'el mantenimiento se quita aunque falle');
        check(is_dir($dirs['private'] . '/backups') && $backups->path($name) !== null, 'las copias siguen en su sitio');
    }

    // Una tabla con otras filas que las del manifiesto (las sumas no lo ven: está en el propio manifiesto).
    $app->clock()->advance(1);
    $bad = $backups->create('full')['name'];
    bkfSetManifest((string) $backups->path($bad), static function (array $m): array {
        $m['database']['rows_by_table']['af_login_attempts'] += 1;

        return $m;
    });
    $snap = bkfState($app);
    $caught = null;
    try {
        (new BackupRestorer($backups))->restore($bad);
    } catch (BackupException $e) {
        $caught = $e;
    }
    check($caught !== null, 'una tabla que no queda con sus filas se niega');
    if ($caught !== null) {
        contains('A table does not have the rows of the backup', $caught->getMessage());
    }
    same($snap, bkfState($app), 'y la base de datos queda como estaba');
    same([], bkfLeftovers($app));

    // Y con todo en orden, después de tantos fallos, sí restaura.
    $result = (new BackupRestorer($backups))->restore($name);
    same(30, (int) $app->db()->value('SELECT COUNT(*) FROM {login_attempts}') - 0, 'ahora sí');
    check($result['tables'] > 0);
});

test('--only=db no toca los ficheros y --only=files no toca la base de datos', function (): void {
    [$app, $backups, $name, $before] = bkrShop();
    $dirs = BackupAreas::dirs($app);
    bkrWreck($app);

    $result = (new BackupRestorer($backups))->restore($name, ['only' => 'db']);
    same(30, (int) $app->db()->value('SELECT COUNT(*) FROM {login_attempts}'), 'las filas vuelven');
    same('bkf-marca-1', $app->settings()->str('demo.clave'));
    check(!is_file($dirs['media'] . '/product/silla.jpg'), 'la foto borrada sigue borrada');
    check(is_file($dirs['private'] . '/nuevo.txt') && is_file($dirs['media'] . '/product/nueva.jpg'), 'lo nuevo sigue ahí');
    same([], $result['old'], 'no se aparta ninguna carpeta');
    same(0, $result['files']);
    same([], bkfLeftovers($app));
    same('db', bkfManifest((string) $backups->path($result['safety']))['mode'], 'la copia de lo que había es solo de la base de datos');

    $app->db()->run('DELETE FROM {login_attempts}');
    $result = (new BackupRestorer($backups))->restore($name, ['only' => 'files']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {login_attempts}'), 'las filas no vuelven');
    check(is_file($dirs['media'] . '/product/silla.jpg') && is_file($dirs['private'] . '/invoices/F-2026-0001.pdf'), 'las fotos y facturas vuelven');
    check(!is_file($dirs['private'] . '/nuevo.txt') && !is_file($dirs['media'] . '/product/nueva.jpg'), 'y lo que no estaba en la copia se va');
    same(2, count($result['old']));
    same(0, $result['tables']);
    same('full', bkfManifest((string) $backups->path($result['safety']))['mode']);
    same($before['files'], bkfState($app)['files']);
});

test('una copia de otro sitio avisa, no restaura sin una confirmación aparte y no cambia la dirección del sitio', function (): void {
    [$app, $backups, $name, ] = bkrShop();
    bkfSetManifest((string) $backups->path($name), static function (array $m): array {
        $m['site']['url'] = 'https://otra-tienda.test';

        return $m;
    });
    $restorer = new BackupRestorer($backups);
    $info = $restorer->inspect($name);
    same(true, $info['other_site']);
    same(true, $info['has_files']);
    $other = $backups->create('full')['name'];
    same(false, $restorer->inspect($other)['other_site'], 'una copia de este mismo sitio no avisa');

    bkrWreck($app);
    bkrRefuses($app, $backups, $name, 'The backup is from another site');
    $url = $app->site()->url();
    $result = $restorer->restore($name, ['other_site' => true]);
    same(30, (int) $app->db()->value('SELECT COUNT(*) FROM {login_attempts}'));
    same($url, $app->site()->url(), 'la dirección del sitio no se cambia');
    check($result['safety'] !== '');
});

test('desde el panel: sin el permiso tools.restore no se ve el botón ni se entra, y con él hace falta contraseña, nombre de la tienda y casilla', function (): void {
    // Las personas del equipo existen ANTES de la copia: si no, al restaurar desaparecerían de la base de datos.
    $app = toolsApp(['tools']);
    bkfSeed($app, 5);
    $web = adminClient($app);
    toolsRole($app, 'copista', ['dashboard.view', 'tools.view', 'tools.backup']);
    aifecAdmin($app, 'copista', 'copista@tienda.test', 'Copista');
    $copyist = new TestClient($app);
    aifecLogin($copyist, 'copista@tienda.test');
    $copyist->get('/admin');
    $support = adminClient($app, 'support');
    $backups = new Backups($app);
    $name = $backups->create('full')['name'];
    $before = bkfState($app);
    bkrWreck($app);
    $wrecked = bkfState($app);

    $list = $web->get('/admin/tools/backups');
    same(200, $list->status);
    contains('/admin/tools/backups/restore/' . $name, $list->body, 'el administrador ve el botón Restaurar');

    $token = aifecToken($copyist);
    $page = $copyist->get('/admin/tools/backups');
    same(200, $page->status, 'quien tiene tools.backup ve la pantalla');
    notContains('/admin/tools/backups/restore/', $page->body, 'pero no el botón de restaurar');
    same(403, $copyist->get('/admin/tools/backups/restore/' . $name)->status);
    same(403, $copyist->post('/admin/tools/backups/restore/' . $name, ['_token' => $token, 'password' => 'clave-larga-1234', 'shop' => BackupController::shopName($app)])->status);
    same($wrecked, bkfState($app), 'sin permiso no se ha tocado nada');
    $copyist->get('/admin/tools/backups');
    $r = $copyist->post('/admin/tools/backups/verify/' . $name, ['_token' => aifecToken($copyist)]);
    same(302, $r->status, 'comprobar sí puede');
    contains('está bien', $copyist->follow()->body);

    same(403, $support->get('/admin/tools/backups/restore/' . $name)->status);

    // El formulario.
    $form = $web->get('/admin/tools/backups/restore/' . $name);
    same(200, $form->status);
    contains('Escribe el nombre de la tienda para confirmar', $form->body);
    contains('Tu contraseña', $form->body);
    contains('Todo: base de datos y ficheros', $form->body);
    notContains('name="other_site"', $form->body, 'una copia de este sitio no pide la casilla de «otro sitio»');
    $action = '/admin/tools/backups/restore/' . $name;
    $shop = BackupController::shopName($app);

    $web->post($action, ['_token' => aifecToken($web), 'password' => 'mala', 'shop' => $shop]);
    contains('La contraseña no es correcta', $web->follow()->body);
    same($wrecked, bkfState($app), 'con la contraseña mala no se toca nada');

    $web->get($action);
    $web->post($action, ['_token' => aifecToken($web), 'password' => 'clave-larga-1234', 'shop' => 'otra tienda']);
    contains('El nombre de la tienda no coincide', $web->follow()->body);
    same($wrecked, bkfState($app), 'con otro nombre no se toca nada');

    $web->get($action);
    $r = $web->post($action, ['_token' => aifecToken($web), 'password' => 'clave-larga-1234', 'shop' => strtoupper($shop)]);
    same(302, $r->status);
    $done = $web->follow();
    same(200, $done->status, 'la sesión del panel sigue valiendo tras restaurar');
    contains('Copia restaurada', $done->body);
    contains('La carpeta anterior se ha guardado en', $done->body);
    same($before, bkfState($app), 'restaurado desde el panel');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'tools.restore'"), 'queda auditado');
    same(false, $app->settings()->bool('tools.maintenance'));
});

test('desde el panel una copia de otro sitio pide una casilla aparte, y una copia rota se rechaza con su motivo y sin tocar nada', function (): void {
    $app = toolsApp(['tools']);
    bkfSeed($app, 5);
    $web = adminClient($app);
    $backups = new Backups($app);
    $base = (int) $app->db()->value('SELECT COUNT(*) FROM {login_attempts}');
    $app->clock()->advance(1);
    $foreign = $backups->create('full')['name'];
    bkfSetManifest((string) $backups->path($foreign), static function (array $m): array {
        $m['site']['url'] = 'https://otra-tienda.test';

        return $m;
    });
    $app->clock()->advance(1);
    $broken = $backups->create('full')['name'];
    bkfTamper((string) $backups->path($broken), static function (ZipArchive $zip): void {
        $zip->addFromString('files/private/returns/adjunto.txt', 'cambiado');
    });
    bkrWreck($app);
    $wrecked = bkfState($app);
    $shop = BackupController::shopName($app);

    $form = $web->get('/admin/tools/backups/restore/' . $foreign);
    contains('La copia es de otro sitio (https://otra-tienda.test)', $form->body);
    contains('name="other_site"', $form->body);
    $web->post('/admin/tools/backups/restore/' . $foreign, ['_token' => aifecToken($web), 'password' => 'clave-larga-1234', 'shop' => $shop]);
    contains('marca la casilla', $web->follow()->body);
    same($wrecked, bkfState($app), 'sin la casilla no se restaura');
    $web->get('/admin/tools/backups/restore/' . $foreign);
    $web->post('/admin/tools/backups/restore/' . $foreign, ['_token' => aifecToken($web), 'password' => 'clave-larga-1234', 'shop' => $shop, 'other_site' => '1']);
    contains('Copia restaurada', $web->follow()->body);
    same($base, (int) $app->db()->value('SELECT COUNT(*) FROM {login_attempts}'));

    bkrWreck2($app);
    $wrecked = bkfState($app);
    $r = $web->get('/admin/tools/backups/restore/' . $broken);
    same(302, $r->status, 'el formulario de una copia rota devuelve a la lista');
    contains('La copia no es válida y no se ha restaurado nada', $web->follow()->body);
    $web->post('/admin/tools/backups/verify/' . $broken, ['_token' => aifecToken($web)]);
    $flash = $web->follow()->body;
    contains('no está bien', $flash);
    contains('La suma de un fichero no coincide', $flash);
    same($wrecked, bkfState($app));
    same(404, $web->get('/admin/tools/backups/restore/backup-19990101-000000-01.zip')->status);
    same(404, $web->get('/admin/tools/backups/restore/..%2F..%2Fconfig.php')->status);
});

test('desde el panel una copia por tandas se sigue con «Continuar» y se puede cancelar; elegir «solo la base de datos» da un zip sin ficheros', function (): void {
    $app = toolsApp(['tools']);
    bkfSeed($app, 30);
    $web = adminClient($app);
    $backups = new Backups($app);

    $run = $backups->begin('full');
    $run->work(60.0, 10, 3);
    $token = $run->state()['token'];
    $page = $web->get('/admin/tools/backups/run/' . $token);
    same(200, $page->status);
    contains('<progress', $page->body);
    contains('Copia completa', $page->body);
    contains('Continuar', $page->body);
    $r = $web->post('/admin/tools/backups/run/' . $token, ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same('/admin/tools/backups', parse_url($r->headers['Location'], PHP_URL_PATH));
    contains('Copia creada: backup-', $web->follow()->body);
    same(1, count($backups->all()));
    same(302, $web->get('/admin/tools/backups/run/' . $token)->status, 'una copia terminada ya no tiene barra de avance');

    $other = $backups->begin('full');
    $other->work(60.0, 10, 3);
    $dir = $backups->runDir($other->state()['token']);
    $web->get('/admin/tools/backups');
    $web->post('/admin/tools/backups/run/' . $other->state()['token'] . '/cancel', ['_token' => aifecToken($web)]);
    contains('La copia se ha cancelado', $web->follow()->body);
    check(!is_dir($dir), 'cancelar borra la carpeta de trabajo');
    same(404, $web->post('/admin/tools/backups/run/' . $other->state()['token'], ['_token' => aifecToken($web)])->status);

    $web->get('/admin/tools/backups');
    $web->post('/admin/tools/backups/create', ['_token' => aifecToken($web), 'mode' => 'db']);
    $web->follow();
    $latest = $backups->all()[0];
    same('db', bkfManifest((string) $backups->path($latest['name']))['mode']);
    $web->get('/admin/tools/backups');
    $web->post('/admin/tools/backups/create', ['_token' => aifecToken($web)]);
    $latest = $backups->all()[0];
    same('full', bkfManifest((string) $backups->path($latest['name']))['mode'], 'de serie, completa');
    $same = (new Backups($app))->all();
    check(count($same) >= 3);
});

test('la orden backup:restore pregunta sin --yes, respeta --only y --other-site, y restaura un zip que viene de fuera', function (): void {
    [$app, $backups, $name, $before] = bkrShop();
    $commands = (new Console(false))->commands($app);
    $never = static function (string $question): string {
        throw new LogicException('no tenía que preguntar: ' . $question);
    };
    bkrWreck($app);
    $wrecked = bkfState($app);

    $io = new Console(false);
    same(2, $commands['backup:restore']['run']($app, [], $io));
    contains('Usage', $io->output);
    same(2, $commands['backup:restore']['run']($app, [$name, 'only' => 'otra'], new Console(false)));
    $io = new Console(false);
    same(1, $commands['backup:restore']['run']($app, ['no-existe.zip', 'yes' => true], $io));
    contains('does not exist', $io->output);

    $asked = [];
    $io = new Console(false);
    same(1, BackupCli::restore($app, [$name], $io, static function (string $q) use (&$asked): string {
        $asked[] = $q;

        return 'no';
    }), 'sin --yes pregunta y, si no le dicen YES, no hace nada');
    same(1, count($asked));
    contains('Type YES', $asked[0]);
    contains('Cancelled', $io->output);
    same($wrecked, bkfState($app));

    $io = new Console(false);
    same(0, BackupCli::restore($app, [$name], $io, static function (string $q): string {
        return 'YES';
    }));
    contains('Restored. A copy of what was there is', $io->output);
    contains('The previous folder was kept in', $io->output);
    same($before, bkfState($app));

    // --yes no pregunta; --only=db no aparta carpetas.
    bkrWreck($app);
    $io = new Console(false);
    same(0, BackupCli::restore($app, [$name, 'yes' => true, 'only' => 'db'], $io, $never));
    notContains('previous folder', $io->output);
    same(30, (int) $app->db()->value('SELECT COUNT(*) FROM {login_attempts}'));

    // Un zip de fuera de la carpeta de copias se trae y se restaura.
    $outside = tmpDir('tools-bkf') . '/traida.zip';
    copy((string) $backups->path($name), $outside);
    bkrWreck2($app);
    $io = new Console(false);
    same(0, BackupCli::restore($app, [$outside, 'yes' => true], $io, $never));
    same($before['files'], bkfState($app)['files']);

    // Otro sitio: con --yes hace falta además --other-site; a mano, otra pregunta.
    bkfSetManifest((string) $backups->path($name), static function (array $m): array {
        $m['site']['url'] = 'https://otra-tienda.test';

        return $m;
    });
    bkrWreck2($app);
    $wrecked = bkfState($app);
    $io = new Console(false);
    same(1, BackupCli::restore($app, [$name, 'yes' => true], $io, $never));
    contains('another site', $io->output);
    contains('Warning', $io->output);
    same($wrecked, bkfState($app));
    $questions = [];
    $io = new Console(false);
    same(1, BackupCli::restore($app, [$name], $io, static function (string $q) use (&$questions): string {
        $questions[] = $q;

        return count($questions) === 1 ? 'YES' : 'no';
    }), 'a la segunda pregunta (otro sitio) dice que no');
    same(2, count($questions));
    contains('MOVE', $questions[1]);
    same($wrecked, bkfState($app));
    same(0, BackupCli::restore($app, [$name, 'yes' => true, 'other-site' => true], new Console(false), $never));
    same(30, (int) $app->db()->value('SELECT COUNT(*) FROM {login_attempts}'));

    // Una copia rota: error y nada cambia.
    bkfTamper((string) $backups->path($name), static function (ZipArchive $zip): void {
        $zip->addFromString('files/private/returns/adjunto.txt', 'cambiado');
    });
    bkrWreck2($app);
    $wrecked = bkfState($app);
    $io = new Console(false);
    same(1, BackupCli::restore($app, [$name, 'yes' => true, 'other-site' => true], $io, $never));
    contains('not valid', $io->output);
    same($wrecked, bkfState($app));
});
