<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Kernel\App;
use Aifec\Modules\Tools\Amounts;
use Aifec\Modules\Tools\CsvStream;

/**
 * Lo que el ERP deja para la tienda: un CSV o un JSON con existencias y/o precios por SKU.
 *
 * Primero se valida el fichero ENTERO sin escribir nada; si más de un 20 % de las líneas son malas (valor que no se entiende o SKU que no
 * existe) no se aplica nada y la pasada queda «rechazada». Si no, se aplican las buenas y el informe dice qué pasó con cada línea mala.
 * Nunca crea ni borra productos. Cada fichero leído queda en su pasada (erp_export_runs, direction = import) y se aparta en «processed».
 */
final class Importer
{
    /** Proporción máxima de líneas malas que admite un fichero. */
    public const MAX_BAD = 0.20;

    private const MAX_ROWS = 20000;

    private const MAX_REPORT = 200;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Lee los ficheros pendientes del perfil (hasta 5 por pasada).
     *
     * @param array<string,mixed> $options trigger (manual, schedule, cli) y contents/name (un fichero en mano: lo usa el panel y las pruebas)
     *
     * @return array<int,array<string,mixed>> una fila de erp_export_runs por fichero (o una sola si no había ficheros o falló la lectura)
     */
    public function run(int $profileId, array $options = []): array
    {
        $profile = $this->app->service('erp')->profiles()->find($profileId);
        if ($profile === null || $profile['direction'] !== 'import') {
            throw new \InvalidArgumentException('Unknown import profile');
        }
        $trigger = isset($options['trigger']) ? (string) $options['trigger'] : 'manual';
        $now = $this->app->clock()->now();
        $files = [];
        if (isset($options['contents'])) {
            $files[] = ['name' => isset($options['name']) ? (string) $options['name'] : 'upload', 'contents' => (string) $options['contents'], 'done' => static function (): void {
            }];
        } else {
            if (in_array((string) $profile['destination'], ['url', 'ftp', 'ftps'], true) && !$this->app->settings()->bool('erp.outbound', false)) {
                return [$this->record($profile, $trigger, ['status' => 'blocked', 'error' => 'Outgoing messages are switched off in the settings'])];
            }
            try {
                $files = $this->app->service('erp')->destinations()->sources($profile, 5);
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'erp.import ' . $profileId);

                return [$this->record($profile, $trigger, ['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500, 'UTF-8')])];
            }
            if ($files === []) {
                return [$this->record($profile, $trigger, ['status' => 'empty', 'error' => 'There are no files to read'])];
            }
        }
        $runs = [];
        foreach ($files as $file) {
            try {
                $result = $this->process($profile, (string) $file['name'], (string) $file['contents']);
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'erp.import ' . $profileId);
                $result = ['status' => 'failed', 'error' => mb_substr($e->getMessage() !== '' ? $e->getMessage() : get_class($e), 0, 500, 'UTF-8')];
            }
            $result['file_name'] = (string) $file['name'];
            $runs[] = $this->record($profile, $trigger, $result);
            try {
                $file['done']();
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'erp.import.done ' . $profileId);
            }
        }
        $set = ['last_run_at' => $this->app->clock()->sql()];
        if ($trigger === 'schedule' && $profile['enabled']) {
            $set['next_run_at'] = Profiles::nextRun($this->app, (string) $profile['schedule'], $profile['schedule_every'] !== null ? (int) $profile['schedule_every'] : null, $profile['schedule_at'] !== null ? (string) $profile['schedule_at'] : null, $now);
        }
        $this->app->db()->update('erp_exports', $set, ['id' => $profileId]);

        return $runs;
    }

    /**
     * Valida y aplica un fichero.
     *
     * @param array<string,mixed> $profile
     *
     * @return array<string,mixed> status, records, error, report
     */
    private function process(array $profile, string $name, string $contents): array
    {
        $o = $profile['options'];
        $lines = (string) $profile['format'] === 'json' ? $this->readJson($contents, $o) : $this->readCsv($contents, $o);
        if ($lines === []) {
            return ['status' => 'failed', 'error' => 'The file has no lines'];
        }
        $includes = !empty($o['prices_include_tax']);
        $writes = new CatalogWrites($this->app);
        $good = [];
        $report = [];
        $outcome = [];
        $bad = 0;
        foreach ($lines as $line) {
            $problem = $line['error'];
            if ($problem === null && $this->exists($line['sku']) === false) {
                $problem = 'There is no product or variant with that SKU';
            }
            if ($problem !== null) {
                ++$bad;
                $outcome[$line['line']] = ['line' => $line['line'], 'sku' => $line['sku'], 'status' => 'bad', 'message' => $problem];
                if (count($report) < self::MAX_REPORT) {
                    $report[] = $outcome[$line['line']];
                }
                continue;
            }
            $good[] = $line;
        }
        $total = count($lines);
        $summary = ['file' => $name, 'total' => $total, 'bad' => $bad, 'applied' => 0, 'errors' => $report, 'lines' => []];
        if ($bad / $total > self::MAX_BAD) {
            $summary['rejected'] = true;
            $summary['lines'] = array_slice(array_values($outcome), 0, self::MAX_REPORT);

            return ['status' => 'rejected', 'records' => 0, 'error' => $bad . ' of ' . $total . ' lines are not valid (more than ' . (int) (self::MAX_BAD * 100) . ' %): nothing was applied', 'report' => (string) json_encode($summary)];
        }
        $applied = 0;
        foreach ($good as $line) {
            $ok = true;
            if ($line['stock'] !== null) {
                $r = $writes->stockLine((string) $o['stock_mode'] === 'delta' ? ['sku' => $line['sku'], 'delta' => $line['stock']] : ['sku' => $line['sku'], 'stock' => $line['stock']], 'erp-import:' . (int) $profile['id']);
                if ($r['status'] !== 'done') {
                    $ok = false;
                    $this->note($summary, $line, $r['status'] . (isset($r['message']) ? ': ' . $r['message'] : ''));
                }
            }
            $set = [];
            if ($line['price'] !== null) {
                $set['price'] = $line['price'];
            }
            if ($line['sale_price'] !== null) {
                $set['sale_price'] = $line['sale_price'];
            }
            if ($set !== []) {
                $r = $writes->priceLine(['sku' => $line['sku']] + $set, $includes);
                if ($r['status'] !== 'done') {
                    $ok = false;
                    $this->note($summary, $line, $r['status'] . (isset($r['message']) ? ': ' . $r['message'] : ''));
                }
            }
            if ($ok) {
                ++$applied;
                $outcome[$line['line']] = ['line' => $line['line'], 'sku' => $line['sku'], 'status' => 'done', 'message' => ''];
            } else {
                ++$summary['bad'];
                $outcome[$line['line']] = ['line' => $line['line'], 'sku' => $line['sku'], 'status' => 'failed', 'message' => ''];
            }
        }
        ksort($outcome);
        $summary['applied'] = $applied;
        $summary['lines'] = array_slice(array_values($outcome), 0, self::MAX_REPORT);

        return ['status' => 'ok', 'records' => $applied, 'error' => null, 'report' => (string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)];
    }

    /**
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $line
     */
    private function note(array &$summary, array $line, string $message): void
    {
        if (count($summary['errors']) < self::MAX_REPORT) {
            $summary['errors'][] = ['line' => $line['line'], 'sku' => $line['sku'], 'status' => 'failed', 'message' => $message];
        }
    }

    private function exists(string $sku): bool
    {
        $db = $this->app->db();

        return $db->row('SELECT id FROM {products} WHERE sku = ?', [$sku]) !== null || $db->row('SELECT id FROM {variants} WHERE sku = ?', [$sku]) !== null;
    }

    // ------------------------------------------------------------------ lectura

    /**
     * @param array<string,mixed> $o
     *
     * @return array<int,array<string,mixed>> line, sku, stock, price, sale_price, error
     */
    private function readCsv(string $contents, array $o): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'erp');
        if ($path === '' || file_put_contents($path, $contents) === false) {
            throw new \RuntimeException('The file could not be read');
        }
        $csv = null;
        try {
            $csv = CsvStream::open($path);
            $index = [];
            foreach ($csv->header() as $i => $title) {
                $index[mb_strtolower(trim($title), 'UTF-8')] = $i;
            }
            $col = [];
            foreach (['sku' => 'sku_col', 'stock' => 'stock_col', 'price' => 'price_col', 'sale_price' => 'sale_price_col'] as $key => $option) {
                $wanted = mb_strtolower(trim((string) ($o[$option] ?? '')), 'UTF-8');
                if ($wanted === '') {
                    $col[$key] = null;
                } elseif (!isset($index[$wanted])) {
                    throw new \RuntimeException('The column «' . (string) $o[$option] . '» is not in the file');
                } else {
                    $col[$key] = $index[$wanted];
                }
            }
            $lines = [];
            $n = 1;
            while (($cells = $csv->next()) !== null) {
                ++$n;
                if (count($lines) >= self::MAX_ROWS) {
                    throw new \RuntimeException('The file has more than ' . self::MAX_ROWS . ' lines');
                }
                $lines[] = $this->line($n, [
                    'sku' => $col['sku'] !== null && isset($cells[$col['sku']]) ? $cells[$col['sku']] : '',
                    'stock' => $col['stock'] !== null && isset($cells[$col['stock']]) ? $cells[$col['stock']] : null,
                    'price' => $col['price'] !== null && isset($cells[$col['price']]) ? $cells[$col['price']] : null,
                    'sale_price' => $col['sale_price'] !== null && isset($cells[$col['sale_price']]) ? $cells[$col['sale_price']] : null,
                ], (string) ($o['stock_mode'] ?? 'absolute'));
            }
        } finally {
            if ($csv !== null) {
                $csv->close();
            }
            @unlink($path);
        }

        return $lines;
    }

    /**
     * @param array<string,mixed> $o
     *
     * @return array<int,array<string,mixed>>
     */
    private function readJson(string $contents, array $o): array
    {
        $data = json_decode($contents, true);
        if (is_array($data) && !array_is_list($data) && isset($data['items']) && is_array($data['items'])) {
            $data = $data['items'];
        }
        if (!is_array($data) || !array_is_list($data)) {
            throw new \RuntimeException('The JSON file must be a list of objects');
        }
        if (count($data) > self::MAX_ROWS) {
            throw new \RuntimeException('The file has more than ' . self::MAX_ROWS . ' lines');
        }
        $lines = [];
        foreach ($data as $i => $item) {
            $get = static function (string $option) use ($item, $o) {
                $name = trim((string) ($o[$option] ?? ''));

                return is_array($item) && $name !== '' && array_key_exists($name, $item) && $item[$name] !== null ? (is_scalar($item[$name]) ? (string) $item[$name] : '') : null;
            };
            $lines[] = $this->line($i + 1, ['sku' => (string) $get('sku_col'), 'stock' => $get('stock_col'), 'price' => $get('price_col'), 'sale_price' => $get('sale_price_col')], (string) ($o['stock_mode'] ?? 'absolute'));
        }

        return $lines;
    }

    /**
     * Una línea con sus valores ya entendidos; error = por qué no vale (null si vale).
     *
     * @param array<string,mixed> $raw
     *
     * @return array<string,mixed>
     */
    private function line(int $number, array $raw, string $stockMode): array
    {
        $line = ['line' => $number, 'sku' => trim((string) $raw['sku']), 'stock' => null, 'price' => null, 'sale_price' => null, 'error' => null];
        if ($line['sku'] === '') {
            $line['error'] = 'The SKU is empty';

            return $line;
        }
        try {
            if ($raw['stock'] !== null && trim((string) $raw['stock']) !== '') {
                $text = trim((string) $raw['stock']);
                if (preg_match('/^[+-]?\d{1,9}$/', $text) !== 1 || ($stockMode !== 'delta' && (int) $text < 0) || ($stockMode === 'delta' && (int) $text === 0)) {
                    throw new \InvalidArgumentException('The stock «' . $text . '» is not a valid whole number');
                }
                $line['stock'] = (int) $text;
            }
            foreach (['price', 'sale_price'] as $field) {
                if ($raw[$field] !== null && trim((string) $raw[$field]) !== '') {
                    try {
                        $line[$field] = Amounts::parse((string) $raw[$field]);
                    } catch (\InvalidArgumentException $e) {
                        throw new \InvalidArgumentException('The ' . $field . ' «' . trim((string) $raw[$field]) . '» is not an amount');
                    }
                }
            }
        } catch (\InvalidArgumentException $e) {
            $line['error'] = $e->getMessage();

            return $line;
        }
        if ($line['stock'] === null && $line['price'] === null && $line['sale_price'] === null) {
            $line['error'] = 'The line has nothing to apply';
        }

        return $line;
    }

    /**
     * Apunta la pasada en erp_export_runs y la devuelve.
     *
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $result
     *
     * @return array<string,mixed>
     */
    private function record(array $profile, string $trigger, array $result): array
    {
        $now = $this->app->clock()->sql();
        $row = [
            'profile_id' => (int) $profile['id'], 'direction' => 'import', 'trigger_kind' => $trigger, 'status' => (string) $result['status'],
            'records' => isset($result['records']) ? (int) $result['records'] : 0, 'bytes' => 0, 'destination' => (string) $profile['destination'],
            'file_name' => isset($result['file_name']) ? (string) $result['file_name'] : null, 'error' => isset($result['error']) ? $result['error'] : null,
            'report' => isset($result['report']) ? $result['report'] : null, 'started_at' => $now, 'finished_at' => $now,
        ];
        $row['id'] = $this->app->db()->insert('erp_export_runs', $row);

        return $row;
    }
}
