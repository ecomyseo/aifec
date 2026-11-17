<?php
/**
 * AIFEC — el motor de la migración: fases, cursor, fallos del origen, pausa, cancelación, barrido y cerrojo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;
use Aifec\Modules\Import\Types;

require_once __DIR__ . '/helpers.php';

test('una migración completa de cinco tipos: en el orden de Types::ORDER, el enlace solo para los enlazados y read = suma de resultados', function (): void {
    $app = importApp();
    importFiveTypes();
    $id = importDraft($app);
    $started = importService($app)->runner()->start($id);
    same(['ok' => true, 'reason' => ''], $started);
    same('running', importRun($app, $id)['status']);

    importWork($app);

    $run = importRun($app, $id);
    same('done', $run['status']);
    same('done', $run['phase']);
    check($run['finished_at'] !== null, 'queda cerrada con su fecha');
    same(['settings', 'tax_classes', 'categories', 'products', 'redirects'], array_keys(ImportFakeWriter::$written), 'el orden de Types::ORDER');
    same(Types::sorted(array_keys(ImportFakeWriter::$written)), array_keys(ImportFakeWriter::$written));
    same(['categories', 'products'], array_keys(ImportFakeWriter::$linked), 'la segunda pasada solo toca los tipos enlazados');
    same(['101', '102', '103'], ImportFakeWriter::$linked['categories']);
    same(['101', '102', '103', '104'], ImportFakeWriter::$linked['products']);
    $total = 0;
    foreach (['settings' => 1, 'tax_classes' => 2, 'categories' => 3, 'products' => 4, 'redirects' => 2] as $type => $n) {
        same($n, $run['counters'][$type]['read'], 'leídos de ' . $type);
        same($n, importSum($run, $type), 'read = created + updated + unchanged + conflict + skipped en ' . $type);
        same($n, $run['counters'][$type]['created']);
        $total += $n;
    }
    same(12, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'), 'los escritores escribieron de verdad');
    same(12, (int) $app->db()->value('SELECT COUNT(*) FROM {import_map}'));
    same(false, importService($app)->connections()->has($id), 'al cerrarla se borran los datos de conexión');
});

test('cada tanda renueva el latido', function (): void {
    $app = importApp();
    importFiveTypes();
    $id = importDraft($app);
    importService($app)->runner()->start($id, false);
    $first = importRun($app, $id)['heartbeat_at'];
    $app->clock()->advance(300);
    importService($app)->runner()->step($id);
    $now = $app->clock()->sql();
    same($now, importRun($app, $id)['heartbeat_at']);
    check($first !== $now, 'el latido cambió');
});

test('una tanda con registros inválidos en medio no se atasca: el cursor avanza por lo leído y el final lo dice el origen', function (): void {
    $app = importApp();
    $records = importRecords('products', 25);
    $records[6] = importBroken('products', '107');
    $records[7] = importBroken('products', '108');
    ImportFakeReader::$data = ['products' => $records];
    $id = importDraft($app, ['types' => ['products']]);
    importService($app)->runner()->start($id);
    importWork($app);

    $run = importRun($app, $id);
    same('done', $run['status']);
    $c = $run['counters']['products'];
    same(25, $c['read']);
    same(2, $c['skipped']);
    same(23, $c['created']);
    same(['', '10', '20', '', '10', '20'], array_map(static function (array $call): string {
        return $call[1];
    }, ImportFakeReader::$calls), 'una lectura de escritura y otra de enlace, cada una de tres trozos: ni se relee ni se atasca');
    same(23, count(ImportFakeWriter::$written['products']), 'los inválidos no llegan al escritor');
    $log = importService($app)->runs()->logs($id, 'products', 'skipped');
    same(2, count($log));
    contains('products.name: missing', (string) $log[0]['reason'], 'el motivo es el primer problema del formato');
});

test('el final llega con next a null aunque la última tanda venga llena', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 20)];
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id);
    importWork($app);
    same('done', importRun($app, $id)['status']);
    same(20, importRun($app, $id)['counters']['redirects']['read']);
    same(2, count(ImportFakeReader::$calls), 'dos lecturas llenas y ninguna de más');
    same(['', '10'], array_column(ImportFakeReader::$calls, 1));
});

test('un origen que entrega pocos registros por tanda no se da por terminado', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 10)];
    ImportFakeReader::$pageCap = 3;
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id);
    importWork($app);
    $run = importRun($app, $id);
    same('done', $run['status']);
    same(10, $run['counters']['redirects']['read'], 'siguió leyendo aunque cada tanda viniera corta');
    same(10, count(ImportFakeWriter::$written['redirects']));
});

test('los registros que el lector descarta cuentan como leídos y se apuntan', function (): void {
    $app = importApp();
    $records = importRecords('redirects', 5);
    $records[2] = ['id' => '999', '__skip' => true];
    ImportFakeReader::$data = ['redirects' => $records];
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id);
    importWork($app);
    $run = importRun($app, $id);
    same(5, $run['counters']['redirects']['read']);
    same(1, $run['counters']['redirects']['skipped']);
    same(4, $run['counters']['redirects']['created']);
    $log = importService($app)->runs()->logs($id, 'redirects', 'skipped');
    same('999', $log[0]['source_id']);
    same('Could not be converted', $log[0]['reason']);
});

test('un corte de tiempo a mitad deja el cursor en lo leído y la siguiente tanda no relee', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['products' => importRecords('products', 25)];
    $id = importDraft($app, ['types' => ['products']]);
    $runner = importService($app)->runner();
    $time = 0.0;
    $runner->setMonotonic(static function () use (&$time): float {
        $time += 25.0;

        return $time;
    });
    $runner->start($id, false);
    same('continue', $runner->step($id));
    $run = importRun($app, $id);
    same('10', $run['cursor']['read'], 'el cursor es el next del trozo ya escrito');
    same(10, $run['counters']['products']['read']);
    same([['products', '', 10]], ImportFakeReader::$calls);

    importDrain($runner, $id);
    $run = importRun($app, $id);
    same('done', $run['status']);
    same(25, $run['counters']['products']['read'], 'ningún registro se leyó dos veces');
    same(25, $run['counters']['products']['created']);
    $cursors = [];
    foreach (ImportFakeReader::$calls as $call) {
        if ($call[0] === 'products') {
            $cursors[] = $call[1];
        }
    }
    same(['', '10', '20', '', '10', '20'], $cursors);
});

test('el tamaño de la tanda sale de los ajustes: 10 registros por paso', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 25)];
    $id = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($id, false);
    same('continue', $runner->step($id));
    same(10, importRun($app, $id)['counters']['redirects']['read'], 'un paso, un trozo');
    same('continue', $runner->step($id));
    same(20, importRun($app, $id)['counters']['redirects']['read']);
});

test('tres fallos seguidos del origen pausan con motivo, sin perder el cursor y sin enseñar la clave', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 40)];
    $id = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($id, false);
    same('continue', $runner->step($id));
    same('10', importRun($app, $id)['cursor']['read']);

    $leak = new SourceError('GET https://origen.test/wp-json?key=clave-de-mentira-7777 gave 502');
    ImportFakeReader::$failures = [$leak, $leak, $leak];
    same('retry', $runner->step($id));
    same(1, importRun($app, $id)['source_errors']);
    same('retry', $runner->step($id));
    same('paused', $runner->step($id));
    $run = importRun($app, $id);
    same('paused', $run['status']);
    same(3, $run['source_errors']);
    same('10', $run['cursor']['read'], 'el cursor sigue donde estaba');
    contains('failed 3 times in a row', (string) $run['pause_reason']);
    notContains('clave-de-mentira-7777', (string) $run['pause_reason'], 'la clave no sale en el motivo');
    contains('***', (string) $run['pause_reason']);
    same('idle', $runner->step($id), 'una migración en pausa no trabaja');
});

test('un éxito de por medio pone a cero el contador de fallos', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 60)];
    $id = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($id, false);
    $boom = new SourceError('down');
    ImportFakeReader::$failures = [$boom, null, $boom, $boom, null];
    same('retry', $runner->step($id));
    same(1, importRun($app, $id)['source_errors']);
    same('continue', $runner->step($id));
    same(0, importRun($app, $id)['source_errors'], 'el éxito lo pone a cero');
    same('retry', $runner->step($id));
    same('retry', $runner->step($id));
    same(2, importRun($app, $id)['source_errors']);
    same('continue', $runner->step($id));
    same(0, importRun($app, $id)['source_errors']);
    same('running', importRun($app, $id)['status'], 'nunca llegó a tres seguidos');
});

test('un acceso denegado pausa en el acto y reanudar sigue', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 30)];
    $id = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($id, false);
    ImportFakeReader::$failures = [new SourceDenied('401 bad key clave-de-mentira-7777')];
    same('paused', $runner->step($id));
    $run = importRun($app, $id);
    same('paused', $run['status']);
    same(0, $run['source_errors'], 'no es un fallo que se reintente');
    notContains('clave-de-mentira-7777', (string) $run['pause_reason']);

    same(true, $runner->resume($id, false));
    $run = importRun($app, $id);
    same('running', $run['status']);
    same(null, $run['pause_reason']);
    importDrain($runner, $id);
    $run = importRun($app, $id);
    same('done', $run['status']);
    same(30, $run['counters']['redirects']['read'], 'reanudar sigue donde se quedó');
});

test('pausar y reanudar a mano; cancelar cierra y borra la conexión', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 30)];
    $id = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($id, false);
    $runner->step($id);
    same(true, $runner->pause($id));
    same('paused', importRun($app, $id)['status']);
    same('Paused by a person', importRun($app, $id)['pause_reason']);
    same(false, $runner->pause($id), 'no se pausa dos veces');
    same(true, importService($app)->connections()->has($id), 'en pausa conserva la conexión');
    same(true, $runner->resume($id, false));
    same(false, $runner->resume($id, false), 'no se reanuda lo que no está en pausa');
    $runner->step($id);
    same(true, $runner->cancel($id));
    $run = importRun($app, $id);
    same('cancelled', $run['status']);
    check($run['finished_at'] !== null);
    same(false, importService($app)->connections()->has($id), 'cancelar borra los datos de conexión');
    same('idle', $runner->step($id));
    same(false, $runner->cancel($id), 'ya está cerrada');
});

test('el barrido cierra como fallida la que lleva una hora en curso sin latido, y solo esa', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 30)];
    $id = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($id, false);
    $runner->step($id);
    $app->clock()->advance(59 * 60);
    same(0, $runner->sweep(), 'a los 59 minutos todavía no');
    same('running', importRun($app, $id)['status']);
    $app->clock()->advance(2 * 60);
    same(1, $runner->sweep());
    $run = importRun($app, $id);
    same('failed', $run['status']);
    contains('No activity for 60 minutes', (string) $run['pause_reason']);
    check($run['finished_at'] !== null);
    same(false, importService($app)->connections()->has($id));
    same(0, $runner->sweep(), 'una cerrada no se vuelve a cerrar');
});

test('el barrido no toca una migración en pausa y cancela los borradores abandonados', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 30)];
    $paused = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($paused, false);
    $runner->pause($paused);
    $draft = importDraft($app);
    $app->clock()->advance(3 * 3600);
    same(0, $runner->sweep());
    same('paused', importRun($app, $paused)['status']);
    same('draft', importRun($app, $draft)['status']);
    $app->clock()->advance(22 * 3600);
    same(1, $runner->sweep());
    same('cancelled', importRun($app, $draft)['status'], 'un borrador de más de 24 horas se cancela');
    same(false, importService($app)->connections()->has($draft));
    same('paused', importRun($app, $paused)['status']);
});

test('una sola migración en curso por sitio: la segunda se niega', function (): void {
    $app = importApp();
    importFiveTypes();
    $runner = importService($app)->runner();
    $first = importDraft($app);
    $second = importDraft($app);
    same(['ok' => true, 'reason' => ''], $runner->start($first, false));
    $refused = $runner->start($second, false);
    same(false, $refused['ok']);
    same('Another migration is in progress', $refused['reason']);
    same('draft', importRun($app, $second)['status'], 'la segunda se queda en borrador');
    $runner->pause($first);
    same(false, $runner->start($second, false)['ok'], 'una en pausa también ocupa el cerrojo');
    $runner->cancel($first);
    same(true, $runner->start($second, false)['ok'], 'cerrada la primera, la segunda puede empezar');
    same(false, $runner->start($second, false)['ok'], 'y no se empieza dos veces');
});

test('el cerrojo no depende del orden: una migración de menor identificador tampoco se cuela', function (): void {
    $app = importApp();
    importFiveTypes();
    $runner = importService($app)->runner();
    $older = importDraft($app);
    $newer = importDraft($app);
    same(true, $runner->start($newer, false)['ok']);
    $refused = $runner->start($older, false);
    same(false, $refused['ok']);
    same('Another migration is in progress', $refused['reason']);
    same('draft', importRun($app, $older)['status']);
    same('running', importRun($app, $newer)['status']);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {import_runs} WHERE status IN ('running', 'paused')"), 'solo una ocupa el cerrojo');
});

test('un simulacro también ocupa el cerrojo mientras dura', function (): void {
    $app = importApp();
    importFiveTypes();
    $runner = importService($app)->runner();
    $sim = importDraft($app, [], ['dry_run' => true]);
    $real = importDraft($app);
    same(true, $runner->start($sim, false)['ok']);
    same(false, $runner->start($real, false)['ok']);
});

test('los tipos que no se pueden traer se saltan enteros y lo dice el informe; sin nada que traer no empieza', function (): void {
    $app = importApp(['import', 'media']);
    ImportFakeReader::$data = ['settings' => importRecords('settings', 1, ['id' => 'main']), 'orders' => importRecords('orders', 2), 'products' => importRecords('products', 2)];
    ImportFakeReader::$types = ['settings', 'orders', 'products', 'redirects'];
    $id = importDraft($app, ['types' => ['settings', 'orders', 'products', 'redirects', 'team']]);
    same(true, importService($app)->runner()->start($id)['ok']);
    importWork($app);
    $run = importRun($app, $id);
    same('done', $run['status']);
    same(['settings'], array_keys(ImportFakeWriter::$written), 'solo lo que se puede escribir');
    $skipped = $run['cursor']['skipped_types'];
    same('The «{module}» module is not active', $skipped['orders']['text']);
    same(['module' => 'orders'], $skipped['orders']['vars']);
    same('The «{module}» module is not active', $skipped['products']['text'], 'catalog no está activo');
    same('This source does not provide this type', $skipped['team']['text'], 'el lector no da el equipo');
    check(!isset($skipped['redirects']), 'las redirecciones sí se pueden: no se saltan');
});

test('un borrador sin nada que se pueda escribir se niega a empezar', function (): void {
    $app = importApp(['import', 'media']);
    ImportFakeReader::$data = ['orders' => importRecords('orders', 2)];
    $id = importDraft($app, ['types' => ['orders']]);
    $started = importService($app)->runner()->start($id);
    same(false, $started['ok']);
    contains('nothing to bring', $started['reason']);
    same('draft', importRun($app, $id)['status']);
});

test('un escritor que lanza se cuenta como descartado y la migración sigue; un resultado raro también', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 4)];
    ImportFakeWriter::$throwOn['redirects:102'] = true;
    ImportFakeWriter::$results['redirects:103'] = ['bogus', 'x'];
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id);
    importWork($app);
    $run = importRun($app, $id);
    same('done', $run['status']);
    $c = $run['counters']['redirects'];
    same(4, $c['read']);
    same(2, $c['created']);
    same(2, $c['skipped']);
    same(4, importSum($run, 'redirects'));
    $reasons = array_column(importService($app)->runs()->logs($id, 'redirects', 'skipped'), 'reason');
    contains('boom redirects:102', implode('|', $reasons));
    contains('unknown result', implode('|', $reasons));
});

test('un conflicto que dice el escritor se cuenta y queda en el informe', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 3)];
    ImportFakeWriter::$results['redirects:102'] = ['conflict', 'Edited here after being brought'];
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id);
    importWork($app);
    $run = importRun($app, $id);
    same(1, $run['counters']['redirects']['conflict']);
    same(2, $run['counters']['redirects']['created']);
    same(3, importSum($run, 'redirects'));
    $log = importService($app)->runs()->logs($id, 'redirects', 'conflict');
    same('Edited here after being brought', $log[0]['reason']);
    same('102', $log[0]['source_id']);
});

test('repetir una migración no duplica: lo ya traído sale sin cambios y lo cambiado, actualizado', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['products' => importRecords('products', 3)];
    $runner = importService($app)->runner();
    $first = importDraft($app, ['types' => ['products']]);
    $runner->start($first);
    importWork($app);
    same(3, importRun($app, $first)['counters']['products']['created']);

    $changed = ImportFakeReader::$data['products'];
    $changed[1]['name'] = ['es' => 'Cambiado en el origen'];
    ImportFakeReader::$data['products'] = $changed;
    $second = importDraft($app, ['types' => ['products']]);
    $runner->start($second);
    importWork($app);
    $c = importRun($app, $second)['counters']['products'];
    same(0, $c['created']);
    same(2, $c['unchanged']);
    same(1, $c['updated']);
    same(3, (int) $app->db()->value("SELECT COUNT(*) FROM {import_map} WHERE type = 'products'"), 'sigue habiendo una pareja por registro');
});

test('«solo lo cambiado desde» deja sin cambios lo anterior a esa fecha', function (): void {
    $app = importApp();
    $records = importRecords('products', 3);
    $records[0]['updated_at'] = '2026-01-01T00:00:00Z';
    $records[1]['updated_at'] = '2026-11-01T00:00:00Z';
    $records[2]['updated_at'] = null;
    ImportFakeReader::$data = ['products' => $records];
    $id = importDraft($app, ['types' => ['products'], 'since' => '2026-10-01']);
    importService($app)->runner()->start($id);
    importWork($app);
    $c = importRun($app, $id)['counters']['products'];
    same(3, $c['read']);
    same(1, $c['unchanged'], 'el de enero queda fuera');
    same(2, $c['created'], 'el de noviembre y el que no dice fecha entran');
    same(['102', '103'], ImportFakeWriter::$written['products']);
});

test('la comprobación final compara el origen con la suma de los escritores y guarda las diferencias', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['products' => importRecords('products', 2), 'categories' => importRecords('categories', 2)];
    ImportFakeReader::$checksums = ['products_count' => 2, 'categories_count' => 2, 'orders_total' => 9900];
    ImportFakeWriter::$sums = ['products' => ['products_count' => 1], 'categories' => ['categories_count' => 2]];
    $id = importDraft($app, ['types' => ['products', 'categories']]);
    importService($app)->runner()->start($id);
    importWork($app);
    $run = importRun($app, $id);
    same('done', $run['status']);
    same(['products_count' => 2, 'categories_count' => 2, 'orders_total' => 9900], $run['checksums']['source']);
    same(['categories_count' => 2, 'products_count' => 1], $run['checksums']['dest']);
    same([['key' => 'products_count', 'source' => 2, 'dest' => 1]], $run['checksums']['diffs'], 'solo se comparan las cifras que están en los dos lados');
});

test('lo que no se puede traer, del lector y de los escritores, queda en la migración', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 2)];
    ImportFakeReader::$unmapped = [['kind' => 'plugin', 'name' => 'Gift Cards Pro', 'count' => 12, 'note' => 'No equivalent']];
    ImportFakeWriter::$notes = true;
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id);
    importWork($app);
    $run = importRun($app, $id);
    same('Gift Cards Pro', $run['unmapped']['reader'][0]['name']);
    same(12, $run['unmapped']['reader'][0]['count']);
    $writers = array_values($run['unmapped']['writers']);
    same('Gift wrap', $writers[0]['name']);
    same(2, $writers[0]['count'], 'se suman los de cada registro');
    same(2, count(importService($app)->runs()->logs($id, 'redirects', 'note')), 'y las notas van al informe');
});

test('un lector que no avanza se detecta en vez de dar vueltas para siempre', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 5)];
    ImportFakeReader::$stuck = true;
    $id = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($id, false);
    same('retry', $runner->step($id));
    same(1, importRun($app, $id)['source_errors'], 'cuenta como fallo del origen');
    same('retry', $runner->step($id));
    same('paused', $runner->step($id));
    contains('The source did not move forward', (string) importRun($app, $id)['pause_reason']);
    same(0, importRun($app, $id)['counters']['redirects']['read'] ?? 0, 'no se apuntó nada como leído');
});

test('un fallo inesperado cierra la migración como fallida con su motivo y sin dejarla colgada', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 3)];
    $id = importDraft($app, ['types' => ['redirects']]);
    $runner = importService($app)->runner();
    $runner->start($id, false);
    ImportFakeReader::$failures = [new \LogicException('algo que no es del origen')];
    same('failed', $runner->step($id));
    $run = importRun($app, $id);
    same('failed', $run['status']);
    contains('algo que no es del origen', (string) $run['pause_reason']);
    check($run['finished_at'] !== null);
    same(false, importService($app)->connections()->has($id));
});

test('el trabajo import.step encola el siguiente paso y la cola lleva la migración hasta el final', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 25)];
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'import.step' AND status = 'pending'"));
    $work = importWork($app);
    check($work['done'] >= 3, 'varios pasos: ' . $work['done']);
    same('done', importRun($app, $id)['status']);
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE status IN ('pending', 'running')"), 'no queda nada en la cola');
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {jobs} WHERE status = 'failed'"));
});

test('la tarea periódica import.sweep está declarada y cierra las colgadas', function (): void {
    $app = importApp();
    importFiveTypes();
    $id = importDraft($app);
    importService($app)->runner()->start($id, false);
    $app->clock()->advance(2 * 3600);
    $names = array_column($app->service('jobs')->tasks(), 'task');
    check(in_array('import.sweep', $names, true), 'la tarea existe');
    $note = $app->service('jobs')->runTask('import.sweep');
    same('Closed 1 stale migrations', $note);
    same('failed', importRun($app, $id)['status']);
});
