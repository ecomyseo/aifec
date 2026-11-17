<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;
use Aifec\Modules\Semantic\Audit;
use Aifec\Modules\Semantic\AuditJobs;
use Aifec\Modules\Semantic\AuditStore;

require_once __DIR__ . '/helpers.php';

/*
 * El cálculo de las notas de los productos en segundo plano: por tandas de 100, un producto solo al guardarlo, una vez al día,
 * y la orden de consola.
 */

/** @return int[] identificadores de los productos creados */
function auditJobsProducts(App $app, int $count): array
{
    $ids = [];
    for ($i = 1; $i <= $count; ++$i) {
        $ids[] = aifecProduct($app, ['name' => 'Producto ' . $i, 'slug' => 'producto-' . $i]);
    }

    return $ids;
}

/** @return array<int,array<string,mixed>> los trabajos de puntuar que hay en la cola */
function auditJobsRows(App $app, string $status = ''): array
{
    return $app->db()->select(
        "SELECT * FROM {jobs} WHERE type = 'semantic.audit'" . ($status !== '' ? " AND status = '" . $status . "'" : '') . ' ORDER BY id'
    );
}

test('el trabajo puntúa 250 productos en tres tandas de 100, 100 y 50 y deja encolada la siguiente cada vez', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);
    auditJobsProducts($app, 250);
    same(0, AuditStore::count($app));

    // Se ejecuta el manejador a mano, tal como lo haría el trabajador de la cola.
    $first = AuditJobs::run([], $app);
    same(100, AuditStore::count($app));
    contains('100', $first);
    $pending = auditJobsRows($app, 'pending');
    same(1, count($pending), 'la primera tanda encola la segunda');
    same(['after' => 100], json_decode((string) $pending[0]['payload'], true));

    AuditJobs::run(['after' => 100], $app);
    same(200, AuditStore::count($app));
    same(2, count(auditJobsRows($app, 'pending')), 'y la segunda, la tercera');

    $third = AuditJobs::run(['after' => 200], $app);
    same(250, AuditStore::count($app));
    contains('50', $third);
    same(2, count(auditJobsRows($app, 'pending')), 'la última tanda no encola nada más');
});

test('el trabajador de la cola mide todos los productos de golpe, tanda a tanda, y no repite el cálculo mientras haya uno en marcha', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);
    auditJobsProducts($app, 130);

    check(AuditJobs::queueAll($app), 'se encola');
    AuditJobs::queueAll($app);
    same(1, count(auditJobsRows($app, 'pending')), 'dos peticiones seguidas son un solo trabajo');

    $app->service('jobs')->work(30, 20);
    same(130, AuditStore::count($app));
    same(2, count(auditJobsRows($app, 'done')), 'dos tandas: 100 y 30');
    same(0, count(auditJobsRows($app, 'pending')));
});

test('solo se puntúan los productos publicados y el que se despublica o se borra pierde su nota', function (): void {
    $app = semanticApp();
    $publicado = aifecProduct($app, ['name' => 'Publicado', 'slug' => 'publicado']);
    $borrador = aifecProduct($app, ['name' => 'Borrador', 'slug' => 'borrador', 'status' => 'draft']);
    $despues = aifecProduct($app, ['name' => 'Despublicado luego', 'slug' => 'despublicado']);

    Audit::batch($app, 0);
    same(2, AuditStore::count($app), 'el borrador no se mide');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {audit_scores} WHERE entity_id = ?', [$borrador]));

    $app->db()->update('products', ['status' => 'draft'], ['id' => $despues]);
    $app->db()->delete('products', ['id' => $publicado]);
    Audit::batch($app, 0);
    same(0, AuditStore::count($app), 'ni el despublicado ni el borrado conservan nota');
});

test('guardar un producto relanza solo el suyo y los guardados seguidos son un solo trabajo', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);
    [$a, $b, $c] = auditJobsProducts($app, 3);
    Audit::batch($app, 0);
    $before = $app->clock()->sql();
    $app->clock()->advance(3600);

    $app->db()->update('product_i18n', ['summary' => 'Un resumen nuevo.'], ['product_id' => $b]);
    $app->invalidate(['catalog']);
    $app->hooks()->fire('admin.resource.saved', 'products', $b, [], null);
    $app->hooks()->fire('admin.resource.saved', 'products', $b, [], null);
    $app->hooks()->fire('admin.resource.saved', 'pages', 7, [], null);

    $pending = auditJobsRows($app, 'pending');
    same(1, count($pending), 'un solo trabajo, y solo por el producto');
    same(['id' => $b], json_decode((string) $pending[0]['payload'], true));

    $app->service('jobs')->work(10, 10);
    same(1, count(auditJobsRows($app, 'pending')), 'todavía no toca: espera un minuto para juntar guardados');
    $app->clock()->advance(61);
    $app->service('jobs')->work(10, 10);
    same(0, count(auditJobsRows($app, 'pending')), 'ya se ejecutó');

    $rows = [];
    foreach ($app->db()->select('SELECT entity_id, checked_at FROM {audit_scores} ORDER BY entity_id') as $row) {
        $rows[(int) $row['entity_id']] = (string) $row['checked_at'];
    }
    same($before, $rows[$a], 'el primero no se tocó');
    same($before, $rows[$c], 'el tercero tampoco');
    check($rows[$b] !== $before, 'el guardado sí se volvió a medir');
});

test('guardar un producto sin el módulo de tareas no rompe nada', function (): void {
    $app = semanticApp();
    $id = aifecProduct($app);

    $app->hooks()->fire('admin.resource.saved', 'products', $id, [], null);
    same(false, AuditJobs::queueProduct($app, $id));
    same(false, AuditJobs::queueAll($app));
});

test('la tarea diaria encola el cálculo de todos los productos', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);

    same('Audit of all products queued', AuditJobs::dailyTask($app));
    same([[]], array_map(static function (array $row): array {
        return json_decode((string) $row['payload'], true);
    }, auditJobsRows($app, 'pending')));

    $schedule = require $app->root() . '/modules/semantic/jobs.php';
    same('daily@03:30', $schedule['cron']['semantic.audit']['every']);
    same('hourly', $schedule['cron']['semantic.visits']['every']);
    check(isset($schedule['handlers']['semantic.audit']), 'el manejador está registrado');
});

test('aifec:audit enseña la nota del sitio y mide los productos, o los deja en la cola con --queue', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'jobs']);
    auditJobsProducts($app, 3);
    $commands = require $app->root() . '/modules/semantic/cli.php';

    $io = new Console(false);
    same(0, $commands['aifec:audit']['run']($app, [], $io));
    contains('AIFEC score of the shop: ', $io->output);
    contains('MISSING', $io->output);
    contains('Products measured: 3', $io->output);
    same(3, AuditStore::count($app));

    $queued = new Console(false);
    same(0, $commands['aifec:audit']['run']($app, ['queue' => true], $queued));
    contains('queued', $queued->output);
    same(1, count(auditJobsRows($app, 'pending')));
});
