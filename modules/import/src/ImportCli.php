<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

use Aifec\Cli\Console;
use Aifec\Kernel\App;

/**
 * Las órdenes de consola de la migración: import:probe, import:run, import:status, import:pause, import:resume,
 * import:cancel, import:verify e import:report.
 *
 * Las claves nunca se piden en la línea de órdenes: se leen de variables de entorno (AIFEC_IMPORT_KEY,
 * AIFEC_IMPORT_SECRET, AIFEC_IMPORT_COOKIE_KEY y AIFEC_IMPORT_DB_PASSWORD) o, con --ask, de la entrada estándar. Si
 * alguien las escribe como opción (--key=…) se acepta con un aviso, porque queda en el historial de órdenes del shell.
 * Nada de lo que sale por pantalla lleva un dato de conexión.
 */
final class ImportCli
{
    /** Opciones que llevan una clave: avisan si se pasan por la línea de órdenes. */
    private const SECRET_OPTIONS = ['key', 'secret', 'cookie-key', 'cookie_key', 'db-password'];

    /**
     * import:probe --source=… --mode=… --url=… (y los campos del lector): comprueba la conexión y cuenta lo que hay.
     *
     * @param array<int|string,string|bool> $args
     */
    public static function probe(App $app, array $args, Console $io): int
    {
        $spec = self::connection($app, $args, $io);
        if ($spec === null) {
            return 2;
        }
        $service = self::service($app);
        $id = $service->createDraft($spec['source'], $spec['mode'], $spec['config'], $spec['db'], [], ['label' => 'Probe']);
        try {
            $run = $service->runs()->find($id);
            $secrets = $service->connections()->secrets($id);
            try {
                $reader = $service->connections()->build($run !== null ? $run : []);
                $probe = $reader->probe();
                $io->line(($probe['ok'] ? 'OK' : 'WARNING') . ': ' . Redactor::scrub((string) $probe['message'], $secrets, 300));
                $io->line('Version: ' . (string) $probe['version'] . ' · Currency: ' . (string) $probe['currency'] . ' · Languages: ' . implode(', ', array_map('strval', (array) $probe['languages'])));
                foreach ((array) $probe['shops'] as $shop) {
                    $io->line('Shop ' . (string) $shop['id'] . ': ' . (string) $shop['name']);
                }
                $rows = [];
                foreach ($reader->types() as $type) {
                    try {
                        $count = $reader->count($type);
                    } catch (SourceError $e) {
                        $count = null;
                    }
                    $rows[] = [$type, $count === null ? '?' : (string) $count];
                }
                $io->table(['TYPE', 'RECORDS'], $rows);
                foreach ((array) $probe['warnings'] as $warning) {
                    $io->line('Warning: ' . Redactor::scrub((string) $warning, $secrets, 300));
                }

                return !empty($probe['ok']) ? 0 : 1;
            } catch (SourceError $e) {
                $io->error(Redactor::scrub($e->getMessage(), $secrets, 191));

                return 1;
            }
        } finally {
            // Una prueba de conexión no es una migración: no deja ni fila ni datos guardados.
            $service->connections()->delete($id);
            $service->runs()->discard($id);
        }
    }

    /**
     * import:run [--dry-run] [--types=a,b] [--since=AAAA-MM-DD] --source=… --mode=… (y los campos del lector).
     *
     * @param array<int|string,string|bool> $args
     */
    public static function run(App $app, array $args, Console $io): int
    {
        $dry = isset($args['dry-run']);
        if (!$dry && !isset($args['yes'])) {
            $io->error('Add --yes to confirm that no email will be sent and that the payment methods stay switched off (or use --dry-run to try it first)');

            return 2;
        }
        $spec = self::connection($app, $args, $io);
        if ($spec === null) {
            return 2;
        }
        $service = self::service($app);
        $options = [];
        // --only es el nombre corto de --types: solo esos tipos.
        $only = isset($args['only']) ? $args['only'] : (isset($args['types']) ? $args['types'] : null);
        if (is_string($only) && $only !== '') {
            $options['types'] = Types::sorted(array_map('trim', explode(',', $only)));
            if ($options['types'] === []) {
                $io->error('Unknown types. Valid ones: ' . implode(', ', Types::ORDER));

                return 2;
            }
        }
        if (isset($args['source-host'])) {
            $host = is_string($args['source-host']) ? Runner::cleanHost($args['source-host']) : '';
            if ($host === '') {
                $io->error('--source-host has to be a domain, for example shop.example');

                return 2;
            }
            $options['source_host'] = $host;
        }
        if (isset($args['order-prefix']) && is_string($args['order-prefix']) && $args['order-prefix'] !== '') {
            $options['order_prefix'] = $args['order-prefix'];
        }
        if (isset($args['team-admins'])) {
            $options['team_admins'] = $args['team-admins'] !== '0';
        }
        if (isset($args['since'])) {
            if (!is_string($args['since']) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $args['since']) !== 1) {
                $io->error('--since has to be a date written YYYY-MM-DD');

                return 2;
            }
            $options['since'] = $args['since'];
        }
        if (isset($args['shop']) && is_string($args['shop'])) {
            $options['shop'] = $args['shop'];
        }
        if (isset($args['keep-urls'])) {
            $options['keep_urls'] = $args['keep-urls'] !== '0';
        }
        if (isset($args['no-images'])) {
            $options['import_images'] = false;
        }
        if (isset($args['overwrite-local'])) {
            $options['overwrite_local'] = true;
        }
        $id = $service->createDraft($spec['source'], $spec['mode'], $spec['config'], $spec['db'], $options, [
            'dry_run' => $dry, 'label' => isset($args['label']) && is_string($args['label']) ? $args['label'] : ($dry ? 'Simulation' : null),
        ]);
        $secrets = $service->connections()->secrets($id);
        // Las equivalencias se proponen por el significado; quien quiera otras, las pone en el panel.
        try {
            $run = $service->runs()->find($id);
            $source = $service->equivalences()->sourceValues($service->connections()->build($run !== null ? $run : []));
            $service->runs()->update($id, ['equivalences' => $service->equivalences()->propose($source, $service->equivalences()->localOptions())]);
        } catch (SourceError $e) {
            $io->line('Note: the equivalences could not be proposed (' . Redactor::scrub($e->getMessage(), $secrets, 150) . ')');
        }
        $started = $service->runner()->start($id, false);
        if (!$started['ok']) {
            $service->runner()->cancel($id);
            $io->error($started['reason']);

            return 1;
        }
        $io->line(($dry ? 'Simulation' : 'Migration') . ' #' . $id . ' started');
        if (isset($options['types'])) {
            foreach (self::missingReferences($options['types']) as $type => $missing) {
                $io->line('Note: «' . $type . '» refers to ' . implode(', ', $missing) . ', which are not part of this run: those links are left out (see the notes of the report)');
            }
        }

        return self::drive($app, $id, $io);
    }

    /**
     * De qué tipos que NO se traen dependen los que sí: cada tipo apunta a otros por identificador (un producto a su marca, sus categorías y su
     * clase de impuestos). Traer solo los productos los deja sin esos enlaces, y se dice antes de empezar.
     *
     * @param string[] $types
     *
     * @return array<string,string[]> tipo => tipos a los que apunta y no están
     */
    public static function missingReferences(array $types): array
    {
        $schema = NeutralSchema::types();
        $parts = NeutralSchema::parts();
        $out = [];
        foreach ($types as $type) {
            if (!isset($schema[$type])) {
                continue;
            }
            $found = [];
            $seen = [];
            $walk = static function (array $fields) use (&$walk, &$found, &$seen, $parts): void {
                foreach ($fields as $declaration) {
                    if (preg_match('/^refs?:([a-z_]+)/', (string) $declaration, $m) === 1) {
                        $found[$m[1]] = true;
                    } elseif (preg_match('/^(?:list|part):([a-z_]+)/', (string) $declaration, $m) === 1 && isset($parts[$m[1]]) && !isset($seen[$m[1]])) {
                        $seen[$m[1]] = true;
                        $walk($parts[$m[1]]);
                    }
                }
            };
            $walk($schema[$type]);
            $absent = [];
            foreach (array_keys($found) as $target) {
                if ($target !== $type && !in_array($target, $types, true)) {
                    $absent[] = $target;
                }
            }
            if ($absent !== []) {
                sort($absent);
                $out[$type] = $absent;
            }
        }

        return $out;
    }

    /**
     * import:status [id]
     *
     * @param array<int|string,string|bool> $args
     */
    public static function status(App $app, array $args, Console $io): int
    {
        $run = self::pick($app, $args, $io, false);
        if ($run === null) {
            return 1;
        }
        $service = self::service($app);
        $io->line('#' . $run['id'] . ' ' . $run['source'] . ' (' . $run['mode'] . ')' . ((int) $run['dry_run'] === 1 ? ' simulation' : '') . ': ' . $run['status']
            . ' · phase ' . $run['phase'] . ($run['current_type'] !== null ? ' · ' . $run['current_type'] : ''));
        if ($run['pause_reason'] !== null && $run['pause_reason'] !== '') {
            $io->line('Reason: ' . $run['pause_reason']);
        }
        $rows = [];
        foreach ($service->report()->progress($run)['types'] as $row) {
            $c = $row['counters'];
            $rows[] = [$row['type'], (string) $c['read'] . ($row['total'] !== null ? '/' . $row['total'] : ''), (string) $c['created'], (string) $c['updated'], (string) $c['unchanged'], (string) $c['conflict'], (string) $c['skipped']];
        }
        $io->table(['TYPE', 'READ', 'CREATED', 'UPDATED', 'UNCHANGED', 'CONFLICT', 'SKIPPED'], $rows);

        return 0;
    }

    /**
     * import:pause [id]
     *
     * @param array<int|string,string|bool> $args
     */
    public static function pause(App $app, array $args, Console $io): int
    {
        $run = self::pick($app, $args, $io, true);
        if ($run === null) {
            return 1;
        }
        if (!self::service($app)->runner()->pause((int) $run['id'])) {
            $io->error('Migration #' . $run['id'] . ' is not running');

            return 1;
        }
        $io->line('Migration #' . $run['id'] . ' is paused');

        return 0;
    }

    /**
     * import:resume [id]: la reanuda y sigue trabajando aquí mismo hasta que acaba o se vuelve a parar.
     *
     * @param array<int|string,string|bool> $args
     */
    public static function resume(App $app, array $args, Console $io): int
    {
        $run = self::pick($app, $args, $io, true);
        if ($run === null) {
            return 1;
        }
        if (!self::service($app)->runner()->resume((int) $run['id'], false)) {
            $io->error('Migration #' . $run['id'] . ' is not paused');

            return 1;
        }
        $io->line('Migration #' . $run['id'] . ' goes on');

        return self::drive($app, (int) $run['id'], $io);
    }

    /**
     * import:cancel [id]
     *
     * @param array<int|string,string|bool> $args
     */
    public static function cancel(App $app, array $args, Console $io): int
    {
        $run = self::pick($app, $args, $io, true);
        if ($run === null) {
            return 1;
        }
        if (!self::service($app)->runner()->cancel((int) $run['id'])) {
            $io->error('Migration #' . $run['id'] . ' is already closed');

            return 1;
        }
        $io->line('Migration #' . $run['id'] . ' is cancelled');

        return 0;
    }

    /**
     * import:verify [id]: enseña la comprobación final (las cifras del origen contra las de aquí). Sale con 1 si hay
     * diferencias o si la migración aún no la hizo.
     *
     * @param array<int|string,string|bool> $args
     */
    public static function verify(App $app, array $args, Console $io): int
    {
        $run = self::pick($app, $args, $io, false);
        if ($run === null) {
            return 1;
        }
        // Se vuelve a comprobar contra lo que hay ahora: lo que se tocó después de migrar sale en rojo.
        $fresh = self::service($app)->runner()->reverify((int) $run['id']);
        if ($fresh !== null) {
            $run = $fresh;
        }
        $checks = $run['checksums'];
        if (!isset($checks['source'])) {
            $io->error('Migration #' . $run['id'] . ' has no final check yet (a simulation never has one)');

            return 1;
        }
        $rows = [];
        foreach ($checks['source'] as $key => $value) {
            $dest = isset($checks['dest'][$key]) ? (string) $checks['dest'][$key] : '-';
            $rows[] = [(string) $key, (string) $value, $dest, isset($checks['dest'][$key]) && (int) $checks['dest'][$key] !== (int) $value ? 'DIFFERENT' : 'ok'];
        }
        $io->table(['FIGURE', 'SOURCE', 'HERE', ''], $rows);
        $diffs = isset($checks['diffs']) && is_array($checks['diffs']) ? count($checks['diffs']) : 0;
        $io->line($diffs === 0 ? 'No differences' : $diffs . ' differences');

        // La comprobación con muestras: productos al azar al céntimo, pedidos, direcciones antiguas, pedidos por estado y por año.
        $report = self::service($app)->report();
        $summary = $report->verifySummary($run);
        $bad = false;
        if ($summary !== null) {
            $table = [];
            $wrong = [];
            foreach ($report->verifyRows($run, 'text') as $row) {
                $table[] = [$row['section'], $row['label'], $row['source'], $row['here'], $row['ok'] ? 'ok' : 'WRONG'];
                if (!$row['ok']) {
                    $wrong[] = $row;
                }
            }
            $io->line();
            $io->table(['PART', 'CHECK', 'SOURCE', 'HERE', ''], $table);
            if ($summary['error'] !== null) {
                $io->error($summary['error']);
                $bad = true;
            }
            foreach ($wrong as $row) {
                $io->line('WRONG ' . ($row['record'] !== '' ? $row['record'] . ' ' : '') . $row['label'] . ': source ' . $row['source'] . ', here ' . $row['here']);
                foreach ($row['notes'] as $note) {
                    $io->line('      ' . $note);
                }
            }
            $io->line($summary['wrong'] === 0 && $summary['error'] === null
                ? 'Everything matches: ' . $summary['checked'] . ' things checked'
                : $summary['wrong'] . ' of ' . $summary['checked'] . ' things checked do not match');
            $bad = $bad || $summary['wrong'] > 0;
        }

        return $diffs === 0 && !$bad ? 0 : 1;
    }

    /**
     * import:report [id] [--out=fichero.csv]: el CSV de las notas de la migración.
     *
     * @param array<int|string,string|bool> $args
     */
    public static function report(App $app, array $args, Console $io): int
    {
        $run = self::pick($app, $args, $io, false);
        if ($run === null) {
            return 1;
        }
        $csv = self::service($app)->report()->csv((int) $run['id']);
        if (isset($args['out']) && is_string($args['out']) && $args['out'] !== '') {
            if (file_put_contents($args['out'], "\xEF\xBB\xBF" . $csv) === false) {
                $io->error('Could not write ' . $args['out']);

                return 1;
            }
            $io->line('Report written to ' . $args['out']);

            return 0;
        }
        foreach (explode("\n", rtrim($csv)) as $line) {
            $io->line($line);
        }

        return 0;
    }

    // ------------------------------------------------------------------ piezas

    private static function service(App $app): ImportService
    {
        /** @var ImportService $service */
        $service = $app->service('import');

        return $service;
    }

    /**
     * Trabaja tanda a tanda hasta que la migración se cierra o se pausa. Devuelve el código de salida.
     */
    private static function drive(App $app, int $id, Console $io): int
    {
        $runner = self::service($app)->runner();
        $last = '';
        $n = 0;
        $shown = [];
        do {
            $outcome = $runner->step($id);
            ++$n;
            $run = self::service($app)->runs()->find($id);
            $where = $run !== null ? $run['phase'] . ' ' . (string) $run['current_type'] : '';
            if ($where !== $last || $n % 20 === 0 || $outcome !== 'continue') {
                $io->line('  ' . $outcome . ': ' . $where);
                $last = $where;
            }
            if ($run !== null) {
                self::bars($app, $run, $io, $shown);
            }
        } while ($outcome === 'continue' || $outcome === 'retry');
        $run = self::service($app)->runs()->find($id);
        if ($run === null) {
            return 1;
        }
        $totals = self::service($app)->report()->progress($run)['totals'];
        $io->line('Finished as ' . $run['status'] . ': read ' . $totals['read'] . ', created ' . $totals['created'] . ', updated ' . $totals['updated']
            . ', unchanged ' . $totals['unchanged'] . ', conflicts ' . $totals['conflict'] . ', skipped ' . $totals['skipped']);
        if ($run['pause_reason'] !== null && $run['pause_reason'] !== '') {
            $io->line('Reason: ' . $run['pause_reason']);
        }
        if ($run['status'] === 'done') {
            $io->line('Details: php aifec import:report ' . $id);

            return 0;
        }

        return 1;
    }

    /**
     * La barra de avance de cada tipo: una línea cada vez que avanza una décima (y al acabar), con lo leído y el total del origen.
     *
     * @param array<string,mixed> $run
     * @param array<string,int>   $shown la décima ya enseñada de cada tipo (se rellena)
     */
    private static function bars(App $app, array $run, Console $io, array &$shown): void
    {
        $progress = self::service($app)->report()->progress($run);
        foreach ($progress['types'] as $row) {
            $type = (string) $row['type'];
            if ($row['state'] === 'waiting') {
                continue;
            }
            $percent = $row['percent'] === null ? 0 : (int) $row['percent'];
            $now = $row['state'] === 'done' ? 10 : (int) floor($percent / 10);
            if ($now <= (isset($shown[$type]) ? $shown[$type] : -1)) {
                continue;
            }
            $shown[$type] = $now;
            $filled = (int) round(20 * $percent / 100);
            $io->line(sprintf('    %-18s [%s%s] %3d%%  %d/%s', $type, str_repeat('#', $filled), str_repeat('-', 20 - $filled), $percent, (int) $row['counters']['read'], $row['total'] !== null ? (string) $row['total'] : '?'));
        }
    }

    /**
     * La migración de la que habla la orden: la que dice el primer argumento, la que ocupa el cerrojo o la última.
     *
     * @param array<int|string,string|bool> $args
     *
     * @return array<string,mixed>|null
     */
    private static function pick(App $app, array $args, Console $io, bool $preferOpen): ?array
    {
        $runs = self::service($app)->runs();
        if (isset($args[0])) {
            $id = (string) $args[0];
            $run = ctype_digit($id) ? $runs->find((int) $id) : null;
            if ($run === null) {
                $io->error('There is no migration ' . $id);
            }

            return $run;
        }
        $run = $preferOpen ? $runs->open() : null;
        if ($run === null) {
            $recent = $runs->recent(1);
            $run = isset($recent[0]) ? $recent[0] : null;
        }
        if ($run === null) {
            $io->error('There are no migrations yet');
        }

        return $run;
    }

    /**
     * Lee de las opciones y del entorno los datos de conexión. Devuelve null (con el error ya escrito) si faltan.
     *
     * @param array<int|string,string|bool> $args
     *
     * @return array{source:string,mode:string,config:array<string,mixed>,db:array<string,mixed>}|null
     */
    private static function connection(App $app, array $args, Console $io): ?array
    {
        $service = self::service($app);
        $source = isset($args['source']) && is_string($args['source']) ? $args['source'] : '';
        $mode = isset($args['mode']) && is_string($args['mode']) ? $args['mode'] : '';
        // La conexión también se puede dar como primer argumento: «prestashop:db», «woocommerce:api».
        if (($source === '' || $mode === '') && isset($args[0]) && is_string($args[0]) && strpos($args[0], ':') !== false) {
            list($fromSource, $fromMode) = explode(':', $args[0], 2);
            $source = $source !== '' ? $source : $fromSource;
            $mode = $mode !== '' ? $mode : $fromMode;
        }
        $card = $service->registry()->reader($source, $mode);
        if ($card === null) {
            $io->error('Choose the reader with --source=woocommerce|prestashop and --mode=api|public|db');
            $io->line('Available: ' . ($service->registry()->readers() === [] ? 'none' : implode(', ', array_keys($service->registry()->readers()))));

            return null;
        }
        foreach (self::SECRET_OPTIONS as $option) {
            if (isset($args[$option])) {
                $io->line('Warning: --' . $option . ' stays in your shell history. Better set AIFEC_IMPORT_' . strtoupper(str_replace('-', '_', $option)) . ' or use --ask.');
            }
        }
        $ask = isset($args['ask']);
        $input = [];
        foreach ($card['fields'] as $name => $field) {
            $name = (string) $name;
            $type = isset($field['type']) ? (string) $field['type'] : 'text';
            $value = self::option($args, $name);
            if ($value === '' && $type === 'secret') {
                $value = self::secretInput($name, $ask, $io);
            }
            $input[$name] = $value;
        }
        $normal = $service->connections()->normalize($card, $input);
        $errors = $normal['errors'];
        $db = [];
        if ($mode === 'db') {
            $password = self::option($args, 'db-password');
            if ($password === '') {
                $password = self::secretInput('db_password', $ask, $io);
            }
            $dbNormal = $service->connections()->normalizeDb([
                'db_host' => self::option($args, 'db-host'), 'db_port' => self::option($args, 'db-port'), 'db_name' => self::option($args, 'db-name'),
                'db_user' => self::option($args, 'db-user'), 'db_password' => $password,
            ]);
            $db = $dbNormal['db'];
            $errors += $dbNormal['errors'];
        }
        if ($errors !== []) {
            foreach ($errors as $name => $problem) {
                $io->error('--' . str_replace('_', '-', (string) $name) . ': ' . $problem);
            }

            return null;
        }

        return ['source' => $source, 'mode' => $mode, 'config' => $normal['config'], 'db' => $db];
    }

    /**
     * @param array<int|string,string|bool> $args
     */
    private static function option(array $args, string $name): string
    {
        foreach ([$name, str_replace('_', '-', $name)] as $key) {
            if (isset($args[$key]) && is_string($args[$key])) {
                return $args[$key];
            }
        }

        return '';
    }

    /**
     * Una clave: de la variable de entorno AIFEC_IMPORT_<NOMBRE> o, con --ask, de la entrada estándar.
     */
    private static function secretInput(string $name, bool $ask, Console $io): string
    {
        $env = getenv('AIFEC_IMPORT_' . strtoupper($name));
        if (is_string($env) && $env !== '') {
            return $env;
        }
        if ($ask && defined('STDIN')) {
            $io->line('Enter ' . $name . ' and press Enter:');
            $line = fgets(STDIN);

            return is_string($line) ? trim($line) : '';
        }

        return '';
    }
}
