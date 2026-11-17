<?php
/**
 * AIFEC — las órdenes de consola de la migración: import:probe, import:run (con --dry-run), import:status, import:pause,
 * import:resume, import:cancel, import:verify e import:report. Las claves van por entorno y nunca salen por pantalla.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/**
 * Ejecuta una orden de consola de la migración.
 *
 * @param array<int|string,string|bool> $args
 *
 * @return array{0:int,1:string} código de salida y lo escrito
 */
function importCli(App $app, string $command, array $args = []): array
{
    $io = new Console(false);
    $commands = $io->commands($app);
    check(isset($commands[$command]), 'la orden ' . $command . ' existe');
    $code = $commands[$command]['run']($app, $args, $io);

    return [(int) $code, $io->output];
}

/** @return array<string,string|bool> */
function importCliSource(): array
{
    return ['source' => 'woocommerce', 'mode' => 'public', 'url' => 'https://origen.test'];
}

test('todas las órdenes de la migración están declaradas con su ayuda', function (): void {
    $app = importApp();
    $commands = (new Console(false))->commands($app);
    foreach (['import:probe', 'import:run', 'import:status', 'import:pause', 'import:resume', 'import:cancel', 'import:verify', 'import:report'] as $name) {
        check(isset($commands[$name]), $name);
        check($commands[$name]['help'] !== '', 'ayuda de ' . $name);
    }
});

test('import:run --dry-run hace el simulacro entero sin escribir y import:status lo cuenta', function (): void {
    $app = importApp();
    importFiveTypes();
    [$code, $out] = importCli($app, 'import:run', ['dry-run' => true] + importCliSource());
    same(0, $code, $out);
    contains('Simulation #1 started', $out);
    contains('Finished as done: read 12, created 12', $out);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'));
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {import_map}'));
    same(1, importRun($app, 1)['dry_run']);
    same('done', importRun($app, 1)['status']);

    [$code, $out] = importCli($app, 'import:status');
    same(0, $code);
    contains('#1 woocommerce (public) simulation: done', $out);
    contains('products', $out);
    [$code, $out] = importCli($app, 'import:status', ['1']);
    same(0, $code);
    [$code, $out] = importCli($app, 'import:status', ['77']);
    same(1, $code);
    contains('There is no migration 77', $out);
});

test('import:run de verdad pide --yes, trae todo y deja la comprobación y el informe', function (): void {
    $app = importApp();
    importFiveTypes();
    ImportFakeReader::$checksums = ['products_count' => 4];
    ImportFakeWriter::$sums = ['products' => ['products_count' => 4]];
    [$code, $out] = importCli($app, 'import:run', importCliSource());
    same(2, $code);
    contains('Add --yes to confirm that no email will be sent', $out);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {import_runs}'), 'sin --yes no se crea nada');

    [$code, $out] = importCli($app, 'import:run', ['yes' => true, 'label' => 'Desde consola'] + importCliSource());
    same(0, $code, $out);
    contains('Migration #1 started', $out);
    contains('Finished as done: read 12, created 12', $out);
    same(12, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'));
    same('Desde consola', importRun($app, 1)['label']);
    same(false, importService($app)->connections()->has(1), 'al acabar se borran los datos de conexión');

    [$code, $out] = importCli($app, 'import:verify');
    same(0, $code, $out);
    contains('products_count', $out);
    contains('No differences', $out);

    ImportFakeReader::$checksums = ['products_count' => 9];
    [$code] = importCli($app, 'import:run', ['yes' => true] + importCliSource());
    same(0, $code);
    [$code, $out] = importCli($app, 'import:verify');
    same(1, $code, 'con diferencias, sale con 1');
    contains('DIFFERENT', $out);
});

test('import:run con --types y --since solo trae lo pedido; los datos mal escritos se rechazan con 2', function (): void {
    $app = importApp();
    importFiveTypes();
    [$code, $out] = importCli($app, 'import:run', ['yes' => true, 'types' => 'categories,products', 'since' => '2026-01-01'] + importCliSource());
    same(0, $code, $out);
    same(['categories', 'products'], array_keys(ImportFakeWriter::$written));
    same('2026-01-01', importRun($app, 1)['options']['since']);
    same(['categories', 'products'], importRun($app, 1)['options']['types']);

    foreach ([['types' => 'inventado'], ['since' => '1 de enero'], ['url' => 'http://tienda-vieja.example'], ['source' => 'nadie']] as $bad) {
        [$code, $out] = importCli($app, 'import:run', ['yes' => true] + $bad + importCliSource());
        same(2, $code, json_encode($bad) . ': ' . $out);
        contains('ERROR', $out);
    }
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {import_runs}'), 'lo rechazado no deja migraciones sueltas');
});

test('las claves se leen del entorno, nunca salen por pantalla y --key avisa del historial', function (): void {
    $app = importApp();
    importFiveTypes();
    putenv('AIFEC_IMPORT_KEY=clave-del-entorno-5555');
    try {
        [$code, $out] = importCli($app, 'import:probe', importCliSource());
        same(0, $code, $out);
        same('clave-del-entorno-5555', ImportFakeReader::$config['key'], 'el lector recibió la clave del entorno');
        notContains('clave-del-entorno-5555', $out);
        contains('OK: It works', $out);
        contains('Version: 9.1', $out);
        contains('Languages: es, fr', $out);
    } finally {
        putenv('AIFEC_IMPORT_KEY');
    }
    [$code, $out] = importCli($app, 'import:probe', ['key' => 'clave-por-opcion-6666'] + importCliSource());
    same(0, $code, $out);
    contains('--key stays in your shell history', $out);
    contains('AIFEC_IMPORT_KEY', $out);
    notContains('clave-por-opcion-6666', $out, 'se acepta pero no se repite');
    same('clave-por-opcion-6666', ImportFakeReader::$config['key']);
    $dump = json_encode($app->db()->select('SELECT * FROM {import_runs}')) . json_encode($app->db()->select('SELECT * FROM {import_log}'));
    notContains('clave-por-opcion-6666', $dump);
    notContains('clave-del-entorno-5555', $dump);
});

test('import:probe no deja nada colgado: ni fila de migración ni datos de conexión', function (): void {
    $app = importApp();
    importFiveTypes();
    [$code] = importCli($app, 'import:probe', importCliSource());
    same(0, $code);
    same(null, importService($app)->runs()->find(1), 'no queda una migración de mentira en el histórico');
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {settings} WHERE k LIKE 'import.conn.%'"));
    same(false, importService($app)->runs()->discard(99), 'no se descarta lo que no existe');
    $started = importDraft($app);
    importService($app)->runner()->start($started, false);
    same(false, importService($app)->runs()->discard($started), 'una migración que ya empezó no se descarta nunca');
    ImportFakeReader::$probe = ['ok' => false, 'message' => 'The list is empty', 'version' => '', 'shops' => [], 'languages' => [], 'currency' => '', 'warnings' => ['Nothing to read']];
    [$code, $out] = importCli($app, 'import:probe', importCliSource());
    same(1, $code, 'una lista vacía es un aviso, no un «conecta»');
    contains('WARNING: The list is empty', $out);
    contains('Warning: Nothing to read', $out);
    [$code, $out] = importCli($app, 'import:probe', ['source' => 'woocommerce', 'mode' => 'nada']);
    same(2, $code);
    contains('Choose the reader', $out);
    contains('woocommerce:public', $out, 'lista los lectores que hay');
});

test('import:pause, import:resume e import:cancel mandan sobre la migración en curso', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 30)];
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id, false);
    importService($app)->runner()->step($id);

    [$code, $out] = importCli($app, 'import:pause');
    same(0, $code, $out);
    contains('Migration #' . $id . ' is paused', $out);
    same('paused', importRun($app, $id)['status']);
    [$code, $out] = importCli($app, 'import:pause', [(string) $id]);
    same(1, $code, 'ya estaba en pausa');
    contains('is not running', $out);

    [$code, $out] = importCli($app, 'import:resume');
    same(0, $code, $out);
    contains('goes on', $out);
    contains('Finished as done', $out);
    same(30, importRun($app, $id)['counters']['redirects']['read'], 'reanudar sigue donde se quedó y acaba aquí mismo');
    [$code, $out] = importCli($app, 'import:resume', [(string) $id]);
    same(1, $code);
    contains('is not paused', $out);

    $second = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($second, false);
    [$code, $out] = importCli($app, 'import:cancel');
    same(0, $code, $out);
    same('cancelled', importRun($app, $second)['status']);
    [$code, $out] = importCli($app, 'import:cancel', [(string) $second]);
    same(1, $code);
    contains('already closed', $out);
});

test('import:run se niega si ya hay una migración en curso', function (): void {
    $app = importApp();
    importFiveTypes();
    $other = importDraft($app);
    importService($app)->runner()->start($other, false);
    [$code, $out] = importCli($app, 'import:run', ['dry-run' => true] + importCliSource());
    same(1, $code);
    contains('Another migration is in progress', $out);
    same('cancelled', importRun($app, 2)['status'], 'el borrador de la orden no se queda colgado');
});

test('import:report escribe el CSV de las notas en un fichero o por pantalla', function (): void {
    $app = importApp();
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 3)];
    ImportFakeWriter::$results['redirects:102'] = ['conflict', 'Editada aquí'];
    [$code] = importCli($app, 'import:run', ['yes' => true, 'types' => 'redirects'] + importCliSource());
    same(0, $code);
    $file = tmpDir('informe') . '/notas.csv';
    [$code, $out] = importCli($app, 'import:report', ['out' => $file]);
    same(0, $code, $out);
    $csv = (string) file_get_contents($file);
    check(strpos($csv, "\xEF\xBB\xBF") === 0, 'con marca BOM para que la abra una hoja de cálculo');
    contains('type;source_id;result;reason;created_at', $csv);
    contains('redirects;102;conflict;"Editada aquí"', $csv);
    [$code, $out] = importCli($app, 'import:report', ['1']);
    same(0, $code);
    contains('redirects;102;conflict', $out);
});

test('import:verify de un simulacro dice que no hay comprobación final', function (): void {
    $app = importApp();
    importFiveTypes();
    importCli($app, 'import:run', ['dry-run' => true] + importCliSource());
    [$code, $out] = importCli($app, 'import:verify');
    same(1, $code);
    contains('has no final check yet', $out);
});
