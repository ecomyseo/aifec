<?php
/**
 * AIFEC — pruebas del diagnóstico: un sitio de prueba sano, huérfanos, tareas, carpetas, la orden de consola y las comprobaciones de otros módulos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;
use Aifec\Modules\Tools\Checks\FileChecks;
use Aifec\Modules\Tools\Checks\ShopChecks;
use Aifec\Modules\Tools\Doctor;

require_once __DIR__ . '/helpers.php';

/** Un sitio con todo lo que el diagnóstico mira, con el cron ya pasado una vez. */
function toolsDoctorApp(): App
{
    require_once __DIR__ . '/../../catalog/tests/helpers.php';
    $app = toolsApp(['tools', 'jobs', 'mail', 'catalog', 'media']);
    $app->service('jobs')->cron();

    return $app;
}

/**
 * @param array<int,array<string,mixed>> $results
 *
 * @return array<string,array<string,mixed>>
 */
function toolsById(array $results): array
{
    $out = [];
    foreach ($results as $row) {
        $out[$row['id']] = $row;
    }

    return $out;
}

test('el diagnóstico de un sitio de prueba no tiene nada en rojo y cada resultado trae todos sus campos', function (): void {
    $app = toolsDoctorApp();
    $results = (new Doctor($app))->run();
    check(count($results) >= 20, 'hay muchas comprobaciones: ' . count($results));
    foreach ($results as $row) {
        same(['id', 'group', 'label', 'status', 'detail', 'fix_url'], array_keys($row), 'campos de ' . $row['id']);
        check(in_array($row['status'], ['ok', 'warn', 'bad'], true), 'estado válido en ' . $row['id']);
        check($row['label'] !== '', 'etiqueta en ' . $row['id']);
        check($row['status'] !== 'bad', 'en rojo: ' . $row['id'] . ' (' . $row['detail'] . ')');
    }
    $groups = array_unique(array_column($results, 'group'));
    foreach (['server', 'files', 'database', 'tasks', 'mail', 'shop', 'security'] as $group) {
        check(in_array($group, $groups, true), 'hay comprobaciones del grupo ' . $group);
    }
    $ids = array_column($results, 'id');
    same(count($ids), count(array_unique($ids)), 'los identificadores no se repiten');
    $sum = Doctor::summary($results);
    same(count($results), $sum['ok'] + $sum['warn'] + $sum['bad']);
});

test('las comprobaciones de un módulo solo salen si el módulo está', function (): void {
    $app = toolsApp(['tools']);
    $ids = array_column((new Doctor($app))->run(), 'id');
    foreach (['tasks.cron', 'mail.setup', 'mail.failed'] as $id) {
        check(!in_array($id, $ids, true), $id . ' no sale sin su módulo');
    }
    check(in_array('server.requirements', $ids, true));
    check(in_array('db.connection', $ids, true));

    $app = toolsDoctorApp();
    $ids = array_column((new Doctor($app))->run(), 'id');
    foreach (['tasks.cron', 'tasks.failed_jobs', 'tasks.scheduled', 'mail.setup', 'mail.failed'] as $id) {
        check(in_array($id, $ids, true), $id . ' sale con su módulo');
    }
});

test('una variante huérfana insertada a mano sale en su comprobación', function (): void {
    $app = toolsDoctorApp();
    $before = toolsById((new Doctor($app))->run());
    same('ok', $before['db.orphan_variants']['status']);

    $now = $app->clock()->sql();
    $app->db()->insert('variants', ['product_id' => 999999, 'sku' => 'HUERFANA', 'stock' => 0, 'position' => 0, 'active' => 1, 'created_at' => $now, 'updated_at' => $now]);
    $app->db()->insert('product_i18n', ['product_id' => 888888, 'lang' => 'es', 'name' => 'Sin producto']);
    $app->db()->insert('product_categories', ['product_id' => 1, 'category_id' => 777777, 'is_main' => 1, 'position' => 0]);
    $app->db()->insert('slugs', ['lang' => 'es', 'path' => 'producto/fantasma', 'type' => 'product', 'entity_id' => 666666, 'canonical' => 1]);

    $after = toolsById((new Doctor($app))->run());
    same('warn', $after['db.orphan_variants']['status']);
    contains('1', $after['db.orphan_variants']['detail']);
    same('warn', $after['db.orphan_texts']['status']);
    same('warn', $after['db.orphan_categories']['status']);
    same('warn', $after['db.orphan_slugs']['status']);
    same('ok', $after['db.orphan_order_items']['status'], 'lo que no tiene huérfanos sigue bien');
});

test('los medios cuyo fichero no existe se detectan mirando una muestra', function (): void {
    require_once __DIR__ . '/../../catalog/tests/helpers.php';
    $app = toolsApp(['tools', 'media']);
    $root = rtrim($app->site()->mediaDir($app->publicDir()), '/');
    mkdir($root . '/2026/11', 0775, true);
    file_put_contents($root . '/2026/11/esta.jpg', 'x');
    $now = $app->clock()->sql();
    $app->db()->insert('media', ['path' => '2026/11/esta.jpg', 'filename' => 'esta.jpg', 'mime' => 'image/jpeg', 'kind' => 'image', 'bytes' => 1, 'created_at' => $now]);
    same('ok', toolsById((new Doctor($app))->run())['db.orphan_media']['status']);

    $app->db()->insert('media', ['path' => '2026/11/falta.jpg', 'filename' => 'falta.jpg', 'mime' => 'image/jpeg', 'kind' => 'image', 'bytes' => 1, 'created_at' => $now]);
    $row = toolsById((new Doctor($app))->run())['db.orphan_media'];
    same('warn', $row['status']);
    contains('1', $row['detail']);
});

test('una comprobación que lanza (añadida por el filtro) sale en rojo con su error y no tumba las demás', function (): void {
    $app = toolsDoctorApp();
    $base = count((new Doctor($app))->run());
    $app->hooks()->filter('doctor.checks', static function ($checks): array {
        $checks[] = ['id' => 'test.boom', 'group' => 'other', 'label' => 'Explota', 'run' => static function (): array {
            throw new \RuntimeException('Algo reventó');
        }];
        $checks[] = ['id' => 'test.fine', 'group' => 'other', 'label' => 'Va bien', 'run' => static function (): array {
            return ['status' => 'ok', 'detail' => 'todo en orden', 'fix_url' => ''];
        }];
        $checks[] = ['id' => 'test.solo_estado', 'group' => 'other', 'label' => 'Solo el estado', 'run' => static function (): string {
            return 'warn';
        }];
        $checks[] = ['id' => 'test.basura', 'group' => 'other', 'label' => 'Devuelve basura', 'run' => static function (): array {
            return ['nada' => 'de esto'];
        }];
        $checks[] = static function (App $app): array {
            return ['status' => 'ok', 'detail' => 'función suelta'];
        };

        return $checks;
    });
    $results = (new Doctor($app))->run();
    same($base + 5, count($results), 'las demás siguen corriendo');
    $by = toolsById($results);
    same('bad', $by['test.boom']['status']);
    contains('Algo reventó', $by['test.boom']['detail']);
    contains('RuntimeException', $by['test.boom']['detail']);
    same('ok', $by['test.fine']['status']);
    same('todo en orden', $by['test.fine']['detail']);
    same('warn', $by['test.solo_estado']['status']);
    same('bad', $by['test.basura']['status']);
    same('ok', $by['custom.' . ($base + 4)]['status'], 'una función suelta cuenta como una comprobación sin nombre');
    same('ok', $by['db.connection']['status']);
});

test('la orden doctor sale con 0 si no hay nada en rojo y con 1 si lo hay', function (): void {
    $app = toolsDoctorApp();
    $commands = (new Console(false))->commands($app);
    check(isset($commands['doctor']['run']), 'la orden doctor existe');

    $io = new Console(false);
    same(0, $commands['doctor']['run']($app, [], $io));
    contains('problems', $io->output);

    $app->hooks()->filter('doctor.checks', static function ($checks): array {
        $checks[] = ['id' => 'test.rojo', 'group' => 'other', 'label' => 'Roja', 'run' => static function (): array {
            return ['status' => 'bad', 'detail' => 'mal'];
        }];

        return $checks;
    });
    $io = new Console(false);
    same(1, $commands['doctor']['run']($app, [], $io));
    contains('BAD', $io->output);
    contains('Roja', $io->output);
});

test('las tareas: sin cron es rojo, a los 15 minutos aviso y a las 2 horas rojo; con el cron al día, bien', function (): void {
    $app = toolsApp(['tools', 'jobs']);
    $doctor = new Doctor($app);
    $row = toolsById($doctor->run())['tasks.cron'];
    same('bad', $row['status'], 'nunca ha corrido');
    contains('* * * * * php', $row['detail']);
    contains('/admin/jobs/schedule', $row['fix_url']);

    $app->service('jobs')->cron();
    same('ok', toolsById($doctor->run())['tasks.cron']['status']);
    $app->clock()->advance(899);
    same('ok', toolsById($doctor->run())['tasks.cron']['status']);
    $app->clock()->advance(1);
    same('warn', toolsById($doctor->run())['tasks.cron']['status']);
    $app->clock()->advance(7200 - 900 - 1);
    same('warn', toolsById($doctor->run())['tasks.cron']['status']);
    $app->clock()->advance(1);
    same('bad', toolsById($doctor->run())['tasks.cron']['status']);
});

test('los trabajos fallidos de las últimas 24 horas y las tareas con error salen en el diagnóstico', function (): void {
    $app = toolsApp(['tools', 'jobs']);
    $q = $app->service('jobs');
    $q->cron();
    $doctor = new Doctor($app);
    $by = toolsById($doctor->run());
    same('ok', $by['tasks.failed_jobs']['status']);
    same('ok', $by['tasks.scheduled']['status']);

    $now = $app->clock()->sql();
    $app->db()->insert('jobs', ['queue' => 'default', 'type' => 'demo', 'payload' => '[]', 'status' => 'failed', 'attempts' => 3, 'max_attempts' => 3,
        'run_at' => $now, 'finished_at' => $now, 'created_at' => $now, 'error' => 'falló']);
    $app->db()->insert('jobs', ['queue' => 'default', 'type' => 'viejo', 'payload' => '[]', 'status' => 'failed', 'attempts' => 3, 'max_attempts' => 3,
        'run_at' => $now, 'finished_at' => $app->clock()->sql($app->clock()->now() - 3 * 86400), 'created_at' => $now, 'error' => 'falló hace días']);
    $q->schedule('demo.rota', 60, static function (): void {
        throw new \RuntimeException('tarea rota');
    });
    $q->cron();

    $by = toolsById($doctor->run());
    same('warn', $by['tasks.failed_jobs']['status']);
    contains('1', $by['tasks.failed_jobs']['detail']);
    same('bad', $by['tasks.scheduled']['status']);
    contains('demo.rota', $by['tasks.scheduled']['detail']);
});

test('el correo: retenido es aviso, SMTP sin servidor es rojo y los fallidos de 24 horas se cuentan', function (): void {
    $app = toolsApp(['tools', 'mail']);
    $doctor = new Doctor($app);
    $by = toolsById($doctor->run());
    same('warn', $by['mail.setup']['status'], 'por defecto el correo está retenido');
    same('ok', $by['mail.failed']['status']);

    $app->settings()->setMany(['mail.mode' => 'live', 'mail.transport' => 'mail']);
    same('ok', toolsById($doctor->run())['mail.setup']['status']);
    $app->settings()->set('mail.transport', 'smtp');
    same('bad', toolsById($doctor->run())['mail.setup']['status']);
    $app->settings()->setMany(['mail.smtp_host' => 'smtp.example.com']);
    same('ok', toolsById($doctor->run())['mail.setup']['status']);
    $app->settings()->set('mail.transport', 'log');
    same('warn', toolsById($doctor->run())['mail.setup']['status'], 'guardar en ficheros no envía nada');

    $now = $app->clock()->sql();
    $app->db()->insert('mail_messages', ['to_email' => 'ana@cliente.test', 'subject' => 'x', 'template' => 't', 'locale' => 'es', 'status' => 'failed', 'attempts' => 3, 'created_at' => $now]);
    same('warn', toolsById($doctor->run())['mail.failed']['status']);
});

test('las carpetas: una que no se puede escribir es roja, y los datos dentro de la carpeta pública también', function (): void {
    $app = toolsApp(['tools']);
    $by = toolsById((new Doctor($app))->run());
    foreach (['files.data', 'files.cache', 'files.logs', 'files.media'] as $id) {
        same('ok', $by[$id]['status'], $id);
    }
    same('ok', $by['files.web']['status'], 'la carpeta de datos no cuelga de la pública');

    // Un fichero donde debería haber una carpeta: no se puede escribir ahí.
    $dir = $app->site()->dataDir;
    @rmdir($dir . '/logs');
    file_put_contents($dir . '/logs', 'soy un fichero');
    same('bad', toolsById((new Doctor($app))->run())['files.logs']['status']);
    unlink($dir . '/logs');

    // La carpeta pública que contiene a la de datos.
    $inside = new App($app->site(), $app->root(), ['public_dir' => $dir, 'clock' => new Clock(testNow()), 'modules' => ['tools'], 'log_files' => false]);
    same('bad', FileChecks::outOfWeb($inside)['status']);
    file_put_contents($dir . '/.htaccess', "Require all denied\n");
    same('warn', FileChecks::outOfWeb($inside)['status'], 'con .htaccess que lo niega queda en aviso');
    unlink($dir . '/.htaccess');
    $app->makeCurrent();
});

test('el espacio en disco, los límites de PHP y la zona horaria dan un resultado', function (): void {
    $app = toolsApp(['tools']);
    $by = toolsById((new Doctor($app))->run());
    foreach (['files.disk', 'server.limits', 'server.timezone', 'server.opcache', 'files.logs_size', 'files.cache_size'] as $id) {
        check(in_array($by[$id]['status'], ['ok', 'warn'], true), $id . ' → ' . $by[$id]['status'] . ' ' . $by[$id]['detail']);
        check($by[$id]['detail'] !== '', $id . ' trae detalle');
    }
    contains('Europe/Madrid', $by['server.timezone']['detail']);

    $bad = testApp(['modules' => ['tools'], 'config' => ['timezone' => 'Marte/Olympus']]);
    $bad->boot();
    same('bad', toolsById((new Doctor($bad))->run())['server.timezone']['status']);
    $app->makeCurrent();
});

test('la tienda: sin nombre o sin correo avisa; la depuración y el HTTPS solo son rojos fuera de un sitio local', function (): void {
    $app = toolsApp(['tools']);
    $by = toolsById((new Doctor($app))->run());
    same('warn', $by['shop.identity']['status'], 'sin correo de contacto');
    same('ok', $by['shop.debug']['status'], 'la depuración está encendida pero es un sitio .test');
    same('ok', $by['shop.https']['status']);
    check(ShopChecks::isLocal($app));

    $real = testApp(['modules' => ['tools'], 'config' => ['url' => 'http://www.mitienda.com', 'debug' => true]]);
    $real->boot();
    $by = toolsById((new Doctor($real))->run());
    same('bad', $by['shop.debug']['status']);
    same('bad', $by['shop.https']['status']);
    check(!ShopChecks::isLocal($real));
    $real->settings()->setMany(['store.name' => 'Mi tienda', 'store.email' => 'tienda@ejemplo.test']);
    same('ok', toolsById((new Doctor($real))->run())['shop.identity']['status']);

    $demo = testApp(['modules' => ['tools'], 'config' => ['url' => 'http://www.mitienda.com', 'debug' => true, 'demo' => true]]);
    $demo->boot();
    same('ok', toolsById((new Doctor($demo))->run())['shop.debug']['status'], 'un sitio de demostración puede llevar la depuración');
    $app->makeCurrent();
});

test('la portada se pide dentro del proceso: cabeceras de seguridad y caché de página', function (): void {
    $app = toolsApp(['tools']);
    $by = toolsById((new Doctor($app))->run());
    same('ok', $by['shop.headers']['status'], $by['shop.headers']['detail']);
    check(in_array($by['shop.cacheable']['status'], ['ok', 'warn'], true), 'la portada se pide y se juzga: ' . $by['shop.cacheable']['detail']);
    check($by['shop.cacheable']['detail'] !== '');
    check(App::current() === $app, 'la aplicación en curso vuelve a ser la de la pantalla');

    // Con el mantenimiento puesto la portada daría 503: no se comprueba y se dice.
    $app->settings()->set('tools.maintenance', true);
    $by = toolsById((new Doctor($app))->run());
    same('warn', $by['shop.headers']['status']);
    same('warn', $by['shop.cacheable']['status']);
});

test('la seguridad: un solo administrador sin segundo paso avisa; con dos, o con segundo paso, está bien', function (): void {
    $app = toolsApp(['tools']);
    $doctor = new Doctor($app);
    same('warn', toolsById($doctor->run())['security.admins']['status'], 'sin administradores');

    aifecAdmin($app, 'admin', 'uno@cliente.test', 'Uno');
    same('warn', toolsById($doctor->run())['security.admins']['status'], 'uno solo y sin segundo paso');

    $app->db()->update('users', ['totp_secret' => 'JBSWY3DPEHPK3PXP'], ['email' => 'uno@cliente.test']);
    same('ok', toolsById($doctor->run())['security.admins']['status'], 'uno solo pero con segundo paso');
    $app->db()->update('users', ['totp_secret' => null], ['email' => 'uno@cliente.test']);

    aifecAdmin($app, 'admin', 'dos@cliente.test', 'Dos');
    same('ok', toolsById($doctor->run())['security.admins']['status'], 'dos administradores');
});

test('las cuentas de demostración solo avisan en un sitio real', function (): void {
    $real = testApp(['modules' => ['tools'], 'config' => ['url' => 'https://www.mitienda.com']]);
    $real->boot();
    same('ok', toolsById((new Doctor($real))->run())['security.demo_users']['status']);
    aifecAdmin($real, 'admin', 'admin@aifec.test', 'De fábrica');
    $row = toolsById((new Doctor($real))->run())['security.demo_users'];
    same('warn', $row['status']);
    $real->db()->delete('users', ['email' => 'admin@aifec.test']);
    aifecAdmin($real, 'demo', 'cliente@mitienda.com', 'Papel demo');
    same('warn', toolsById((new Doctor($real))->run())['security.demo_users']['status'], 'el papel demo también cuenta');

    $local = toolsApp(['tools']);
    aifecAdmin($local, 'admin', 'admin@aifec.test', 'En local');
    same('ok', toolsById((new Doctor($local))->run())['security.demo_users']['status']);
    $local->makeCurrent();
});

test('la pantalla de diagnóstico pide permiso, agrupa y enseña el enlace de arreglo', function (): void {
    $app = toolsDoctorApp();
    $web = adminClient($app);
    $r = $web->get('/admin/tools/doctor');
    same(200, $r->status);
    contains('Diagnóstico', $r->body);
    contains('Requisitos del servidor', $r->body);
    contains('Conexión con la base de datos', $r->body);
    contains('Seguridad', $r->body);
    contains('/admin/settings/mail', $r->body, 'el correo retenido enlaza con su ajuste');
    contains('Arreglar', $r->body);

    same(403, adminClient($app, 'support')->get('/admin/tools/doctor')->status);
    toolsRole($app, 'mirador', ['dashboard.view', 'tools.view']);
    aifecAdmin($app, 'mirador', 'mirador@tienda.test', 'Mirador');
    $viewer = new TestClient($app);
    aifecLogin($viewer, 'mirador@tienda.test');
    same(200, $viewer->get('/admin/tools/doctor')->status, 'con tools.view se ve');
    $app->makeCurrent();
});
