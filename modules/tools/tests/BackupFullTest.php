<?php
/**
 * AIFEC — pruebas de la copia completa: qué entra y qué no, el manifiesto y sus sumas, las tandas, la memoria y la comprobación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Modules\Tools\BackupAreas;
use Aifec\Modules\Tools\BackupVerifier;
use Aifec\Modules\Tools\Backups;
use Aifec\Modules\Tools\SqlDump;

require_once __DIR__ . '/backup_helpers.php';

test('una copia completa es un solo zip con la base de datos, los medios, los privados y un manifiesto con sumas correctas', function (): void {
    $app = toolsApp(['tools']);
    $seed = bkfSeed($app);
    $backups = new Backups($app);
    $info = $backups->create('full');
    $path = $backups->path($info['name']);
    check($path !== null && substr($info['name'], -4) === '.zip', 'una copia es un solo fichero zip: ' . $info['name']);

    $names = bkfZipNames((string) $path);
    $expected = ['db/database.sqlite', 'files.lst', 'manifest.json'];
    foreach ($seed['media'] as $rel) {
        $expected[] = 'files/media/' . $rel;
    }
    foreach ($seed['private'] as $rel) {
        $expected[] = 'files/private/' . $rel;
    }
    sort($expected);
    same($expected, $names, 'entran el volcado, los medios y los privados, y nada más');

    $manifest = bkfManifest((string) $path);
    same(\Aifec\Kernel\App::VERSION, $manifest['version']);
    same('full', $manifest['mode']);
    same('sqlite', $manifest['database']['engine']);
    same('af_', $manifest['database']['prefix']);
    same('2026-11-12 12:00:00', $manifest['created_at']);
    same($app->site()->url(), $manifest['site']['url']);
    same(count($seed['media']) + count($seed['private']), $manifest['files']);
    $live = bkfState($app);
    foreach ($manifest['database']['rows_by_table'] as $table => $rows) {
        if (isset($live['tables'][$table])) {
            same($live['tables'][$table], $rows, 'filas de ' . $table);
        }
    }
    same(30, $manifest['database']['rows_by_table']['af_login_attempts']);
    same(count($manifest['database']['rows_by_table']), $manifest['database']['tables']);

    // Las sumas, recalculadas aparte.
    $zip = new ZipArchive();
    $zip->open((string) $path);
    same(hash('sha256', (string) $zip->getFromName('db/database.sqlite')), $manifest['parts']['db']['sha256']);
    same(strlen((string) $zip->getFromName('db/database.sqlite')), $manifest['parts']['db']['bytes']);
    same(hash('sha256', (string) $zip->getFromName('files.lst')), $manifest['parts']['files_list']['sha256']);
    $lines = array_filter(explode("\n", (string) $zip->getFromName('files.lst')));
    same(count($seed['media']) + count($seed['private']), count($lines));
    $mediaSum = hash_init('sha256');
    foreach ($lines as $line) {
        [$part, $sha, $size, $rel] = explode("\t", $line, 4);
        same(hash('sha256', (string) $zip->getFromName('files/' . $part . '/' . $rel)), $sha, 'suma de ' . $rel);
        same((int) $size, strlen((string) $zip->getFromName('files/' . $part . '/' . $rel)));
        if ($part === 'media') {
            hash_update($mediaSum, $line . "\n");
        }
    }
    same(hash_final($mediaSum), $manifest['parts']['media']['sha256'], 'la suma de la parte «media»');
    same(count($seed['media']), $manifest['parts']['media']['files']);
    $zip->close();

    // Qué NO entra: ni la configuración, ni la clave secreta, ni cachés, registros, temporales ni copias anteriores.
    $text = bkfZipText((string) $path);
    notContains($app->site()->secret(), $text, 'la clave secreta del sitio no está en el zip');
    notContains('clave-de-mentira', $text);
    foreach (['config.php', 'cache/', 'logs/', 'tmp/', 'backups/', '.tmp-trabajo', 'miniatura', 'copia-ajena'] as $banned) {
        notContains($banned, implode("\n", $names), 'no entra ' . $banned);
    }
    same([], glob($backups->dir() . '/.run-*') ?: [], 'la carpeta de trabajo se borra al acabar');
    $check = BackupVerifier::verify((string) $path, 'af_');
    same([], $check['errors']);
    same(true, $check['ok']);
});

test('«solo la base de datos» también es un zip con manifiesto, pero sin ficheros', function (): void {
    $app = toolsApp(['tools']);
    bkfSeed($app);
    $backups = new Backups($app);
    $info = $backups->create('db');
    $path = (string) $backups->path($info['name']);
    same(['db/database.sqlite', 'manifest.json'], bkfZipNames($path));
    $manifest = bkfManifest($path);
    same('db', $manifest['mode']);
    check(!isset($manifest['files']) && !isset($manifest['parts']['files_list']), 'sin lista de ficheros');
    same(true, BackupVerifier::verify($path, 'af_')['ok']);

    $app->settings()->set('tools.backup_content', 'db');
    same('db', $backups->mode('auto'), 'lo que diga el ajuste');
    $app->settings()->set('tools.backup_content', 'full');
    same('full', $backups->mode('auto'));
    same('full', $backups->mode('cualquier cosa'));
});

test('con 300 ficheros la copia se hace por tandas, se puede retomar desde disco y el resultado es el mismo', function (): void {
    $app = toolsApp(['tools']);
    $seed = bkfSeed($app, 300);
    $backups = new Backups($app);
    $run = $backups->begin('full');
    $token = $run->state()['token'];
    same('db', $run->state()['stage']);

    $slices = 0;
    $percent = [];
    $state = $run->work(60.0, 100, 1); // la base de datos
    same('list', $state['stage']);
    $state = $run->work(60.0, 100, 1); // la lista de ficheros
    same('files', $state['stage']);
    same(count($seed['media']) + count($seed['private']), $state['total'], 'se conocen todos los ficheros');
    check(count($seed['media']) >= 300, 'hay 300 fotos de ejemplo');
    while ($state['stage'] === 'files') {
        $state = $run->work(60.0, 100, 1);
        $percent[] = $state['percent'];
        ++$slices;
        // Cada tanda se retoma desde lo que quedó en disco, como haría otra petición.
        $run = $backups->resume($token);
        check($run !== null, 'la tanda se puede retomar');
        if ($slices === 1) {
            check($state['done'] > 0 && $state['done'] < $state['total'], 'a medias: ' . $state['done'] . ' de ' . $state['total']);
            check(is_dir($backups->runDir($token)), 'la carpeta de trabajo sigue ahí');
            same([], $backups->all(), 'todavía no hay copia con nombre definitivo');
        }
    }
    check($slices >= 3, 'hicieron falta varias tandas: ' . $slices);
    same($percent, array_values(array_unique($percent)), 'la barra solo avanza');
    $state = $run->work(60.0);
    same('done', $state['stage']);
    same(100, $state['percent']);
    $all = $backups->all();
    same(1, count($all));
    $path = (string) $backups->path($all[0]['name']);
    same(count($seed['media']) + count($seed['private']), bkfManifest($path)['files']);
    same(true, BackupVerifier::verify($path, 'af_')['ok'], 'una copia por tandas se comprueba igual que una de un tirón');
    same([], glob($backups->dir() . '/.run-*') ?: []);
    same(null, $backups->resume($token), 'una copia terminada ya no se retoma');
    same(null, $backups->resume('no-es-una-ficha'));
});

test('una copia que se corta entre tandas se puede retomar y no deja nada a medias con nombre de copia', function (): void {
    $app = toolsApp(['tools']);
    bkfSeed($app, 40);
    $backups = new Backups($app);
    $run = $backups->begin('full');
    $run->work(60.0, 10, 3);
    $token = $run->state()['token'];
    // La carpeta de trabajo se queda con su estado; otra «petición» sigue donde estaba.
    $again = $backups->resume($token);
    check($again !== null);
    $state = $again->work(60.0, 10);
    same('done', $state['stage']);
    same(true, BackupVerifier::verify((string) $backups->path($state['name']), 'af_')['ok']);

    $other = $backups->begin('full');
    $other->work(60.0, 10, 3);
    $dir = $backups->runDir($other->state()['token']);
    check(is_dir($dir));
    $other->abort();
    check(!is_dir($dir), 'cancelar borra la carpeta de trabajo');
    same(1, count($backups->all()), 'cancelar no deja ninguna copia');
});

test('con muy poca memoria, un fichero grande se copia, se comprueba y se restaura sin cargarlo entero', function (): void {
    $app = toolsApp(['tools']);
    bkfSeed($app);
    $big = BackupAreas::dirs($app)['media'] . '/video/grande.jpg';
    @mkdir(dirname($big), 0775, true);
    $h = fopen($big, 'wb');
    for ($i = 0; $i < 40; ++$i) {
        fwrite($h, random_bytes(1024 * 1024));
    }
    fclose($h);
    $sha = (string) hash_file('sha256', $big);
    $backups = new Backups($app);
    gc_collect_cycles();
    $old = (string) ini_get('memory_limit');
    ini_set('memory_limit', (string) (memory_get_usage(true) + 16 * 1024 * 1024));
    try {
        $info = $backups->create('full');
        $check = $backups->verify($info['name']);
        unlink($big);
        $result = (new Aifec\Modules\Tools\BackupRestorer($backups))->restore($info['name']);
    } finally {
        ini_set('memory_limit', $old);
    }
    same(true, $check['ok'], 'la comprobación de 40 MB cabe en 16 MB de margen');
    check($info['bytes'] > 40 * 1024 * 1024, 'el zip lleva el fichero grande');
    same($sha, (string) hash_file('sha256', $big), 'el fichero grande vuelve igual');
    check($result['files'] > 0);
});

test('comprobar una copia buena, y cuatro malas: suma cambiada, manifiesto roto, volcado cortado y entrada ajena', function (): void {
    $app = toolsApp(['tools']);
    bkfSeed($app);
    $backups = new Backups($app);
    $name = $backups->create('full')['name'];
    $good = (string) $backups->path($name);
    same(false, $backups->isVerified($name), 'recién hecha, sin comprobar');
    $r = $backups->verify($name);
    same(true, $r['ok']);
    same([], $r['errors']);
    same(true, $backups->isVerified($name), 'comprobada queda anotada');
    same(1, count((new Backups($app, true))->all()), 'el servicio del inicio la ve');

    $make = static function (callable $break) use ($backups, $app): array {
        $app->clock()->advance(1);
        $copy = $backups->create('full')['name'];
        bkfTamper((string) $backups->path($copy), $break);

        return $backups->verify($copy);
    };

    $r = $make(static function (ZipArchive $zip): void {
        $zip->addFromString('files/media/product/silla.jpg', 'otro contenido');
    });
    same(false, $r['ok']);
    contains('The sum of a file does not match', implode('|', $r['errors']), 'suma de un fichero cambiada');

    $r = $make(static function (ZipArchive $zip): void {
        $zip->addFromString('manifest.json', '{"format": 1, "roto"');
    });
    same(false, $r['ok']);
    contains('The manifest is broken', implode('|', $r['errors']), 'manifiesto roto');

    $r = $make(static function (ZipArchive $zip): void {
        $db = (string) $zip->getFromName('db/database.sqlite');
        $zip->addFromString('db/database.sqlite', substr($db, 0, intdiv(strlen($db), 2)));
    });
    same(false, $r['ok']);
    contains('The sum of the database dump does not match', implode('|', $r['errors']), 'volcado cortado');

    $r = $make(static function (ZipArchive $zip): void {
        $zip->addFromString('config.php', '<?php return [];');
    });
    same(false, $r['ok']);
    contains('not part of the backup', implode('|', $r['errors']), 'entrada ajena');

    $r = $make(static function (ZipArchive $zip): void {
        $zip->addFromString('files/media/../../../fuera.txt', 'salida');
    });
    same(false, $r['ok']);
    contains('unsafe name', implode('|', $r['errors']), 'ruta que se sale');

    $r = $make(static function (ZipArchive $zip): void {
        $zip->deleteName('files/private/returns/adjunto.txt');
    });
    same(false, $r['ok']);
    contains('A file is missing from the backup', implode('|', $r['errors']), 'falta un fichero');

    $r = $backups->verify($name);
    same(true, $r['ok'], 'la buena sigue siendo buena');
    $broken = $backups->all()[1];
    same(false, $broken['verified'], 'una copia mala no queda como comprobada');
    same(false, $backups->verify('backup-19990101-000000-01.zip')['ok'], 'una copia que no existe');
    file_put_contents($good, substr((string) file_get_contents($good), 0, 200));
    same(false, $backups->isVerified($name), 'si el fichero cambia, la anotación ya no vale');
    same(false, $backups->verify($name)['ok'], 'un zip cortado');
});

test('el volcado de MySQL se lee sentencia a sentencia, con «;», comillas y saltos dentro de los textos', function (): void {
    $dir = tmpDir('tools-bkf');
    $path = bkfFakeMysqlZip($dir);
    $zip = new ZipArchive();
    $zip->open($path);
    $h = $zip->getStream('db/dump.sql');
    $statements = iterator_to_array(SqlDump::statements($h), false);
    fclose($h);
    $zip->close();
    same(6, count($statements));
    same('SET NAMES utf8mb4', $statements[0]);
    same('SET FOREIGN_KEY_CHECKS = 0', $statements[1]);
    contains("`id` int NOT NULL,\n  `txt` text", $statements[3]);
    contains("(1, 'a;b')", $statements[4], 'el «;» dentro de un texto no corta');
    contains("it\\'s; ñ ; \"q\" `x`", $statements[4]);
    same(SqlDump::FOOTER, $statements[5]);

    same(['type' => 'set', 'table' => ''], SqlDump::classify('SET NAMES utf8mb4', 'af_'));
    same(['type' => 'insert', 'table' => 'af_demo'], SqlDump::classify($statements[4], 'af_'));
    same(['type' => 'create', 'table' => 'af_demo'], SqlDump::classify($statements[3], 'af_'));
    same(['type' => 'drop', 'table' => 'af_demo'], SqlDump::classify($statements[2], 'af_'));
    throws(function (): void {
        SqlDump::classify('DROP DATABASE aifec', 'af_');
    }, RuntimeException::class);
    throws(function (): void {
        SqlDump::classify('INSERT INTO `otra_tabla` (`a`) VALUES (1)', 'af_');
    }, RuntimeException::class);
    throws(function (): void {
        SqlDump::classify('CREATE TABLE `af_x` (`a` int, FOREIGN KEY (`a`) REFERENCES `af_y`(`id`))', 'af_');
    }, RuntimeException::class);
    throws(function (): void {
        SqlDump::classify('SET GLOBAL max_allowed_packet = 1', 'af_');
    }, RuntimeException::class);
    same('INSERT INTO `zr1_x` (`id`) VALUES (1)', SqlDump::retarget('INSERT INTO `af_demo` (`id`) VALUES (1)', 'af_demo', 'zr1_x'));
    same('CREATE TABLE `zr1_x` (`id` int)', SqlDump::retarget('CREATE TABLE `af_demo` (`id` int)', 'af_demo', 'zr1_x'));

    // Un volcado cortado a mitad de una sentencia se detecta.
    $cut = fopen('php://memory', 'w+b');
    fwrite($cut, "SET NAMES utf8mb4;\nINSERT INTO `af_demo` (`id`) VALUES\n(1),\n(2");
    rewind($cut);
    throws(function () use ($cut): void {
        iterator_to_array(SqlDump::statements($cut), false);
    }, RuntimeException::class);
    fclose($cut);
});

test('de un sitio MySQL, el volcado SQL se comprueba: bueno, sin su final, con otra tabla y con una entrada de más', function (): void {
    $dir = tmpDir('tools-bkf');
    $r = BackupVerifier::verify(bkfFakeMysqlZip($dir), 'af_');
    same([], $r['errors']);
    same(true, $r['ok']);
    same('mysql', $r['manifest']['database']['engine']);

    $r = BackupVerifier::verify(bkfFakeMysqlZip($dir, ['cut' => true]), 'af_');
    same(false, $r['ok']);
    contains('The database dump is cut short', implode('|', $r['errors']), 'un volcado sin su final está cortado');

    $r = BackupVerifier::verify(bkfFakeMysqlZip($dir, ['prefix' => 'otro_']), 'af_');
    same(false, $r['ok']);
    contains('does not belong to this site', implode('|', $r['errors']) . $r['errors'][0], 'tablas de otro prefijo');

    $r = BackupVerifier::verify(bkfFakeMysqlZip($dir, ['extra' => 'sorpresa.php']), 'af_');
    same(false, $r['ok']);
    contains('not part of the backup', implode('|', $r['errors']));
});

test('las copias de antes (un solo fichero, solo la base de datos) también se comprueban, y se ve si están cortadas', function (): void {
    $app = toolsApp(['tools']);
    $backups = new Backups($app);
    $dir = $backups->dir();
    // Una .sqlite.gz de las de antes.
    $tmp = $dir . '/.tmp-prueba';
    $app->db()->pdo()->exec("VACUUM INTO '" . str_replace("'", "''", $tmp) . "'");
    $plain = (string) file_get_contents($tmp);
    unlink($tmp);
    file_put_contents($dir . '/backup-20261110-100000-01.sqlite.gz', gzencode($plain));
    file_put_contents($dir . '/backup-20261110-110000-01.sqlite', $plain);
    file_put_contents($dir . '/backup-20261110-120000-01.sqlite', substr($plain, 0, 5000));
    $sql = "SET NAMES utf8mb4;\nDROP TABLE IF EXISTS `af_demo`;\nCREATE TABLE `af_demo` (`id` int);\nINSERT INTO `af_demo` (`id`) VALUES\n(1);\nSET FOREIGN_KEY_CHECKS = 1;\n";
    file_put_contents($dir . '/backup-20261110-130000-01.sql.gz', gzencode($sql));
    file_put_contents($dir . '/backup-20261110-140000-01.sql', substr($sql, 0, -30));

    same(true, $backups->verify('backup-20261110-100000-01.sqlite.gz')['ok'], '.sqlite.gz');
    same(true, $backups->verify('backup-20261110-110000-01.sqlite')['ok'], '.sqlite');
    $cut = $backups->verify('backup-20261110-120000-01.sqlite');
    same(false, $cut['ok'], '.sqlite cortado');
    same(true, $cut['errors'] !== []);
    same(true, $backups->verify('backup-20261110-130000-01.sql.gz')['ok'], '.sql.gz');
    $cutSql = $backups->verify('backup-20261110-140000-01.sql');
    same(false, $cutSql['ok'], '.sql sin su final');
    same(true, $backups->isVerified('backup-20261110-100000-01.sqlite.gz'));
    $names = array_column($backups->all(), 'kind', 'name');
    same('sqlite', $names['backup-20261110-100000-01.sqlite.gz']);
    same('sql', $names['backup-20261110-130000-01.sql.gz']);
});

test('el servicio «backups» del inicio solo cuenta las copias comprobadas y la pantalla de copias las enseña todas', function (): void {
    $app = toolsApp(['tools']);
    $web = adminClient($app);
    $backups = new Backups($app);
    $name = $backups->create('full')['name'];
    same([], $app->service('backups')->all(), 'el servicio no ve una copia sin comprobar');
    same(1, count($backups->all()));
    contains('Hay 1 copia de seguridad de la tienda sin comprobar: compruébala', $web->get('/admin')->body, 'sin comprobar, el inicio dice que hay una y que se compruebe');
    notContains('Todavía no hay ninguna copia de seguridad de la tienda', $web->last->body, 'el aviso no contradice a la pantalla de copias');

    $page = $web->get('/admin/tools/backups');
    contains($name, $page->body);
    contains('Sin comprobar', $page->body);
    $backups->verify($name);
    same(1, count($app->service('backups')->all()));
    notContains('sin comprobar: compruébala', $web->get('/admin')->body, 'comprobada, el aviso se va');
    contains('Comprobada', $web->get('/admin/tools/backups')->body);
});

test('la tarea nocturna hace la copia, la comprueba y la deja anotada; si falló la comprobación, el inicio lo dice', function (): void {
    $app = toolsApp(['tools', 'jobs']);
    $web = adminClient($app);
    bkfSeed($app);
    $note = $app->service('jobs')->runTask('tools.backup');
    contains('Backup backup-20261112-120000-01.zip', (string) $note);
    contains('checked', (string) $note);
    $backups = new Backups($app);
    same(true, $backups->isVerified('backup-20261112-120000-01.zip'), 'la tarea la comprueba');
    same('', $app->settings()->str('tools.backup_failed', ''));
    $path = (string) $backups->path('backup-20261112-120000-01.zip');
    same(31, bkfManifest($path)['database']['rows_by_table']['af_login_attempts'], 'es completa de serie (30 de ejemplo y el acceso de la prueba)');
    check(isset(bkfManifest($path)['files']), 'lleva ficheros');

    $app->settings()->set('tools.backup_content', 'db');
    $app->clock()->advance(60);
    $app->service('jobs')->runTask('tools.backup');
    $second = $backups->all()[0];
    same('db', bkfManifest((string) $backups->path($second['name']))['mode'], 'el ajuste manda en la nocturna');

    $app->settings()->set('tools.backup_failed', 'The backup failed its check :: backup-X: The sum of a file does not match');
    $body = $web->get('/admin')->body;
    contains('La última copia no ha pasado la comprobación', $body);
    contains('La copia no ha pasado la comprobación (backup-X: The sum of a file does not match)', $body);
    $app->settings()->set('tools.backup_failed', '');
    notContains('La última copia no ha pasado la comprobación', $web->get('/admin')->body);
});

test('la orden backup crea una copia completa y la comprueba, y backup:verify dice si una copia está bien', function (): void {
    $app = toolsApp(['tools']);
    bkfSeed($app);
    $commands = (new Console(false))->commands($app);
    $io = new Console(false);
    same(0, $commands['backup']['run']($app, [], $io));
    contains('Backup backup-20261112-120000-01.zip', $io->output);
    contains('Checked', $io->output);
    same(0, $commands['backup']['run']($app, ['mode' => 'db'], new Console(false)));
    same(2, $commands['backup']['run']($app, ['mode' => 'rara'], new Console(false)));

    $io = new Console(false);
    same(0, $commands['backup:verify']['run']($app, ['backup-20261112-120000-01.zip'], $io));
    contains('Checked', $io->output);
    contains('files', $io->output);

    $backups = new Backups($app);
    bkfTamper((string) $backups->path('backup-20261112-120000-01.zip'), static function (ZipArchive $zip): void {
        $zip->addFromString('files/private/returns/adjunto.txt', 'cambiado');
    });
    $io = new Console(false);
    same(1, $commands['backup:verify']['run']($app, ['backup-20261112-120000-01.zip'], $io));
    contains('The sum of a file does not match', $io->output);
    same(1, $commands['backup:verify']['run']($app, ['no-existe.zip'], new Console(false)));
    same(2, $commands['backup:verify']['run']($app, [], new Console(false)));
});

test('el volcado de SQLite sin la extensión zip sigue funcionando: la copia de solo la base de datos de siempre se comprueba', function (): void {
    // Sin ZipArchive no se puede probar aquí; lo que sí se comprueba es que el panel avisa solo cuando falta y que la clase lo pregunta.
    check(Backups::zipAvailable() === class_exists('ZipArchive'));
    $app = toolsApp(['tools']);
    $web = adminClient($app);
    notContains('La extensión zip no está instalada', $web->get('/admin/tools/backups')->body, 'con zip, sin aviso');
    contains('Completa: base de datos, imágenes y ficheros privados', $web->last->body);
});
