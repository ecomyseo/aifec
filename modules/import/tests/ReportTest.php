<?php
/**
 * AIFEC — el informe: contadores, lo que se revisa a mano, el CSV y que nunca lleve un secreto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('el informe no lleva un secreto: ni el CSV, ni la página, ni el registro de actividad', function (): void {
    $app = importApp(['import', 'media', 'catalog', 'customers']);
    $customers = importRecords('customers', 3, ['password_hash' => 'hash-secreto-123']);
    $settings = neutralSample('settings');
    $settings['mail']['password'] = 'clave-de-mentira-correo';
    ImportFakeReader::$data = ['settings' => [$settings], 'customers' => $customers];
    ImportFakeWriter::$leak = true;
    $id = importDraft($app, ['types' => ['settings', 'customers']]);
    importService($app)->runner()->start($id);
    importWork($app);
    $run = importRun($app, $id);
    same('done', $run['status']);
    same(1, $run['counters']['settings']['skipped']);
    same(3, $run['counters']['customers']['skipped']);

    $web = adminClient($app);
    $csv = importService($app)->report()->csv($id);
    $page = $web->get('/admin/import/' . $id . '/report')->body;
    $print = $web->get('/admin/import/' . $id . '/print')->body;
    $audit = json_encode($app->db()->select('SELECT * FROM {audit}'));
    $runs = json_encode($app->db()->select('SELECT * FROM {import_runs}'));
    $log = json_encode($app->db()->select('SELECT * FROM {import_log}'));
    $logger = json_encode($app->log()->records);
    foreach (['hash-secreto-123', 'clave-de-mentira-correo'] as $secret) {
        notContains($secret, $csv, 'el CSV');
        notContains($secret, $page, 'la página');
        notContains($secret, $print, 'la página imprimible');
        notContains($secret, $audit, 'el registro de actividad');
        notContains($secret, $runs, 'import_runs');
        notContains($secret, $log, 'import_log');
        notContains($secret, $logger, 'el registro de la aplicación');
    }
    contains('***', $csv, 'el secreto está tapado, no borrado la línea');
    contains('bad record', $csv);
});

test('el CSV lleva sus columnas, escapa lo peligroso y se genera por páginas', function (): void {
    $app = importApp();
    $id = importDraft($app);
    $runs = importService($app)->runs();
    for ($i = 1; $i <= 1200; ++$i) {
        $runs->log($id, 'products', (string) $i, 'skipped', $i === 5 ? '=HYPERLINK("http://malo.test")' : 'Motivo, con coma y "comillas" ' . $i);
    }
    $csv = importService($app)->report()->csv($id);
    $lines = explode("\n", trim($csv));
    same('type;source_id;result;reason;created_at', $lines[0]);
    same(1201, count($lines), 'una línea por nota, más la cabecera: las 1.200 pasan las páginas de 500');
    contains("'=HYPERLINK", $csv, 'una celda que empieza por «=» no se ejecuta en una hoja de cálculo');
    contains('"Motivo, con coma y ""comillas"" 1"', $csv);
});

test('el avance por tipo: hechos, el actual con su porcentaje y los que esperan', function (): void {
    $app = importApp();
    $id = importDraft($app);
    $runs = importService($app)->runs();
    $runs->update($id, [
        'status' => 'running', 'phase' => 'write', 'current_type' => 'products',
        'cursor' => ['plan' => ['settings', 'products', 'redirects'], 'pos' => 1, 'totals' => ['settings' => 1, 'products' => 20, 'redirects' => 30], 'skipped_types' => []],
        'counters' => [
            'settings' => ['read' => 1, 'created' => 1, 'updated' => 0, 'unchanged' => 0, 'conflict' => 0, 'skipped' => 0],
            'products' => ['read' => 10, 'created' => 8, 'updated' => 0, 'unchanged' => 0, 'conflict' => 1, 'skipped' => 1],
        ],
    ]);
    $progress = importService($app)->report()->progress(importRun($app, $id));
    same(['done', 'current', 'waiting'], array_column($progress['types'], 'state'));
    same([100, 50, 0], array_column($progress['types'], 'percent'));
    same(45, $progress['percent'], '(100 + 50 + 0) / 3 con el 90 % para la fase de escritura');
    same(11, $progress['totals']['read']);
    same(1, $progress['totals']['conflict']);
    same('Products', $progress['types'][1]['label']);

    $runs->update($id, ['phase' => 'link']);
    $progress = importService($app)->report()->progress(importRun($app, $id));
    same(['done', 'done', 'done'], array_column($progress['types'], 'state'), 'pasada la escritura, todo lo escrito está hecho');
    check($progress['percent'] >= 90, 'enlazando ya va por el 90 %');
    $runs->update($id, ['status' => 'done', 'phase' => 'done']);
    same(100, importService($app)->report()->progress(importRun($app, $id))['percent']);
});

test('«revisa a mano»: formas de pago apagadas, correo retenido, equipo inactivo, módulos sin sustituto, idiomas, choques y diferencias', function (): void {
    $app = importApp();
    $id = importDraft($app, ['source_languages' => ['es', 'fr', 'de-DE', 'ES']]);
    $zero = ['read' => 0, 'created' => 0, 'updated' => 0, 'unchanged' => 0, 'conflict' => 0, 'skipped' => 0];
    importService($app)->runs()->update($id, [
        'status' => 'done', 'phase' => 'done',
        'cursor' => ['plan' => ['payment_methods', 'team', 'products'], 'pos' => 3, 'totals' => [], 'skipped_types' => ['orders' => ['text' => 'The «{module}» module is not active', 'vars' => ['module' => 'orders']]]],
        'counters' => [
            'payment_methods' => ['read' => 2, 'created' => 2] + $zero,
            'team' => ['read' => 3, 'created' => 2, 'updated' => 1] + $zero,
            'products' => ['read' => 9, 'created' => 6, 'conflict' => 2, 'skipped' => 1] + $zero,
        ],
        'unmapped' => ['reader' => [
            ['kind' => 'plugin', 'name' => 'Gift Cards Pro', 'count' => 12, 'note' => 'No equivalent'],
            ['kind' => 'feature', 'name' => 'Packs', 'count' => 4, 'note' => ''],
        ], 'writers' => ['x' => ['kind' => 'feature', 'name' => 'Gift wrap', 'count' => 2, 'note' => '']]],
        'checksums' => ['source' => ['orders_total' => 100], 'dest' => ['orders_total' => 90], 'diffs' => [['key' => 'orders_total', 'source' => 100, 'dest' => 90]]],
    ]);
    $report = importService($app)->report()->build(importRun($app, $id));
    $texts = array_column($report['review'], 'text');
    $joined = implode("\n", $texts);
    contains('{n} payment methods were brought switched off and in test mode', $joined);
    contains('No email was sent during the migration', $joined);
    contains('{n} team members were brought inactive and without a password', $joined);
    contains('The source uses «{name}», which has no replacement here', $joined);
    contains('These languages of the source are not set up here', $joined);
    contains('«{type}» was not brought', $joined);
    contains('{n} records were edited here after being brought', $joined);
    contains('{n} records could not be brought', $joined);
    contains('The final check found {n} differences', $joined);
    $byText = [];
    foreach ($report['review'] as $item) {
        $byText[$item['text']] = $item['vars'];
    }
    same(['n' => 2], $byText['{n} payment methods were brought switched off and in test mode: set up each one before you sell']);
    same(['n' => 3], $byText['{n} team members were brought inactive and without a password: switch on the ones that should come back and send them a password reset']);
    same(['name' => 'Gift Cards Pro'], $byText['The source uses «{name}», which has no replacement here'], 'solo los módulos y plugins, no las funciones');
    same(['list' => 'fr, de-DE'], $byText['These languages of the source are not set up here and their texts were not brought: {list}'], 'ES ya está en el sitio');
    same(['n' => 2], $byText['{n} records were edited here after being brought and were left as they are']);
    same(3, count($report['unmapped']));
    same(['source', 'source', 'here'], array_column($report['unmapped'], 'origin'));
    same([['key' => 'orders_total', 'source' => 100, 'dest' => 90]], $report['diffs']);
});

test('sin nada que revisar, la lista sale vacía', function (): void {
    $app = importApp();
    importFiveTypes();
    $id = importDraft($app, ['types' => ['categories']]);
    importService($app)->runner()->start($id);
    importWork($app);
    $report = importService($app)->report()->build(importRun($app, $id));
    same([], $report['review']);
    same([], $report['diffs']);
    same(['categories'], array_column($report['types'], 'type'));
});

test('la pantalla del informe enseña los contadores y las notas, escapadas, y filtra por resultado', function (): void {
    $app = importApp();
    $web = adminClient($app);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 3)];
    ImportFakeWriter::$results['redirects:102'] = ['skipped', 'Rota & <b>peligrosa</b>'];
    ImportFakeWriter::$results['redirects:103'] = ['conflict', 'Editada aquí'];
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id);
    importWork($app);

    $r = $web->get('/admin/import/' . $id . '/report');
    same(200, $r->status);
    contains('Informe de la migración', $r->body);
    contains('Rota &amp; &lt;b&gt;peligrosa&lt;/b&gt;', $r->body, 'un dato con «&» y etiquetas sale escapado');
    notContains('<b>peligrosa</b>', $r->body);
    contains('Editada aquí', $r->body);
    $only = $web->get('/admin/import/' . $id . '/report?result=conflict')->body;
    contains('Editada aquí', $only);
    notContains('Rota &amp;', $only, 'el filtro deja solo los choques');
    $csv = $web->get('/admin/import/' . $id . '/report.csv');
    same(200, $csv->status);
    contains('text/csv', $csv->headers['Content-Type']);
    contains('attachment', $csv->headers['Content-Disposition']);
    same(404, $web->get('/admin/import/999/report')->status);
});
