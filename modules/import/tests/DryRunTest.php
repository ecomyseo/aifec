<?php
/**
 * AIFEC — el simulacro: el mismo motor con dry_run, que no escribe en ninguna tabla que no sea import_runs e import_log.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('un simulacro no cambia el recuento de ninguna tabla salvo import_runs e import_log', function (): void {
    $app = importApp();
    importFiveTypes();
    $before = importCounts($app);
    check(count($before) > 30, 'se recorren todas las tablas del sitio: ' . count($before));

    $id = importDraft($app, [], ['dry_run' => true]);
    $runner = importService($app)->runner();
    same(true, $runner->start($id, false)['ok']);
    importDrain($runner, $id);
    $after = importCounts($app);

    same('done', importRun($app, $id)['status']);
    same(1, $after['af_import_runs'] - $before['af_import_runs'], 'solo ha nacido su fila de import_runs');
    unset($before['af_import_runs'], $after['af_import_runs'], $before['af_import_log'], $after['af_import_log']);
    same($before, $after, 'todas las demás tablas, idénticas');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'), 'el escritor no escribió');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {import_map}'), 'y no se apuntó ninguna pareja');
    same(false, importService($app)->connections()->has($id), 'se cierra como cualquier migración');
});

test('el simulacro enseña lo mismo que haría la migración de verdad', function (): void {
    $app = importApp();
    importFiveTypes();
    $runner = importService($app)->runner();
    $sim = importDraft($app, [], ['dry_run' => true]);
    $runner->start($sim);
    importWork($app);
    $simulated = importRun($app, $sim)['counters'];

    $real = importDraft($app);
    $runner->start($real);
    importWork($app);
    same($simulated, importRun($app, $real)['counters'], 'los contadores del simulacro y de la migración coinciden');
    same(12, array_sum(array_column($simulated, 'created')));
});

test('un simulacro de una migración ya hecha enseña lo que quedaría sin cambios', function (): void {
    $app = importApp();
    importFiveTypes();
    $runner = importService($app)->runner();
    $real = importDraft($app);
    $runner->start($real);
    importWork($app);

    $sim = importDraft($app, [], ['dry_run' => true]);
    $runner->start($sim);
    importWork($app);
    $c = importRun($app, $sim)['counters'];
    same(12, array_sum(array_column($c, 'unchanged')));
    same(0, array_sum(array_column($c, 'created')));
    same(12, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'), 'lo de la migración real sigue donde estaba');
});

test('el simulacro no hace la segunda pasada ni la comprobación final', function (): void {
    $app = importApp();
    importFiveTypes();
    ImportFakeReader::$checksums = ['products_count' => 4];
    ImportFakeWriter::$sums = ['products' => ['products_count' => 1]];
    $id = importDraft($app, [], ['dry_run' => true]);
    importService($app)->runner()->start($id);
    importWork($app);
    $run = importRun($app, $id);
    same('done', $run['status']);
    same([], ImportFakeWriter::$linked, 'sin enlace: no hay nada escrito a lo que enlazar');
    same([], $run['checksums'], 'sin comparación: el destino no ha cambiado');
});
