<?php
/**
 * AIFEC — importaciones de productos por CSV: subida, vista previa, ejecución por tandas e informe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * Cada importación vive en la carpeta privada del sitio (`private/imports/`), sin tablas: el fichero subido
 * (`<ficha>.csv`), su estado (`<ficha>.json`: dónde va, cuántas altas, cambios y errores) y el informe fila a fila
 * (`<ficha>.report.csv`). La ficha es una cadena aleatoria de 24 caracteres.
 *
 * El trabajo se reparte en tandas de 50 filas. El estado guarda la posición en el fichero, así que una tanda retoma por donde
 * iba la anterior sin volver a leer lo ya hecho, y una importación cortada a mitad sigue donde se quedó. Cada fila se escribe
 * en su propia transacción: una fila mala no tumba a las demás.
 */
final class ImportRuns
{
    /** Filas por tanda. */
    public const BATCH = 50;

    /** Filas que mira como mucho la vista previa. */
    private const PREVIEW_ROWS = 20000;

    /** Filas de muestra que enseña la vista previa. */
    private const SAMPLE = 20;

    /** Errores que se enseñan en la vista previa y que se guardan en el estado. */
    private const ERRORS_SHOWN = 200;

    /** Tamaño máximo del fichero. */
    private const MAX_BYTES = 104857600;

    /** Días que se guardan las importaciones. */
    private const KEEP_DAYS = 30;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** Carpeta de las importaciones (con su protección para el servidor web, por si la carpeta de datos está a su alcance). */
    public function dir(): string
    {
        $dir = Fs::norm($this->app->site()->path('private/imports', true));
        $guard = $dir . '/.htaccess';
        if (is_dir($dir) && !is_file($guard)) {
            @file_put_contents($guard, "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n");
        }

        return $dir;
    }

    public static function validToken(string $token): bool
    {
        return preg_match('/^[a-f0-9]{24}$/', $token) === 1;
    }

    /**
     * Guarda el fichero subido, mira su cabecera y deja la importación lista para la vista previa. Devuelve la ficha.
     *
     * @throws \InvalidArgumentException con un mensaje fijo en inglés si el fichero no sirve
     */
    public function create(string $tmpPath, string $originalName): string
    {
        $size = is_file($tmpPath) ? (int) filesize($tmpPath) : 0;
        if ($size < 1) {
            throw new \InvalidArgumentException('The file is empty');
        }
        if ($size > self::MAX_BYTES) {
            throw new \InvalidArgumentException('The file is too large');
        }
        $token = bin2hex(random_bytes(12));
        $path = $this->csvPath($token);
        if (!@copy($tmpPath, $path)) {
            throw new \InvalidArgumentException('The file could not be saved');
        }
        try {
            $stream = CsvStream::open($path);
            $header = $stream->header();
            $delimiter = $stream->delimiter();
            $start = $stream->position();
            $stream->close();
        } catch (\RuntimeException $e) {
            @unlink($path);
            throw new \InvalidArgumentException($e->getMessage());
        }
        $analysis = CsvColumns::analyse($header, $this->app);
        if (!CsvColumns::hasSku($analysis['columns'])) {
            @unlink($path);
            throw new \InvalidArgumentException('The file has no sku column');
        }
        $name = preg_replace('/[^\p{L}\p{N}._ -]/u', '', basename(str_replace('\\', '/', $originalName)));
        $this->save([
            'token' => $token,
            'name' => is_string($name) && $name !== '' ? mb_substr($name, 0, 120, 'UTF-8') : 'products.csv',
            'size' => $size,
            'created_at' => $this->app->clock()->sql(),
            'started_at' => null,
            'finished_at' => null,
            'status' => 'uploaded',
            'delimiter' => $delimiter,
            'start' => $start,
            'offset' => $start,
            'row' => 0,
            'created' => 0,
            'updated' => 0,
            'errors' => 0,
            'warnings' => 0,
            'columns' => $analysis['columns'],
            'unknown' => $analysis['unknown'],
            'new_attributes' => $analysis['new_attributes'],
            'error_sample' => [],
            'user_id' => $this->app->auth()->adminId(),
        ]);

        return $token;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function load(string $token): ?array
    {
        if (!self::validToken($token)) {
            return null;
        }
        $file = $this->dir() . '/' . $token . '.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;

        return is_array($data) && isset($data['token']) ? $data : null;
    }

    /**
     * @param array<string,mixed> $state
     */
    public function save(array $state): void
    {
        $file = $this->dir() . '/' . $state['token'] . '.json';
        $tmp = $file . '.' . bin2hex(random_bytes(3)) . '.tmp';
        file_put_contents($tmp, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR), LOCK_EX);
        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('The state of the import could not be saved');
        }
    }

    /**
     * Las importaciones que hay, la más reciente primero.
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(): array
    {
        $out = [];
        $names = scandir($this->dir());
        foreach (is_array($names) ? $names : [] as $name) {
            if (preg_match('/^([a-f0-9]{24})\.json$/', $name, $m) === 1) {
                $state = $this->load($m[1]);
                if ($state !== null) {
                    $out[] = $state;
                }
            }
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string) $b['created_at'], (string) $a['created_at']);
        });

        return $out;
    }

    public function csvPath(string $token): string
    {
        return $this->dir() . '/' . $token . '.csv';
    }

    public function reportPath(string $token): string
    {
        return $this->dir() . '/' . $token . '.report.csv';
    }

    /** Borra una importación (el fichero, el estado y el informe). */
    public function delete(string $token): void
    {
        if (!self::validToken($token)) {
            return;
        }
        foreach ([$this->csvPath($token), $this->reportPath($token), $this->dir() . '/' . $token . '.json'] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
    }

    /** Borra las importaciones de más de 30 días. Devuelve cuántas. */
    public function purge(): int
    {
        $cut = $this->app->clock()->now() - self::KEEP_DAYS * 86400;
        $removed = 0;
        foreach ($this->all() as $state) {
            if (Clock::parse((string) $state['created_at']) < $cut) {
                $this->delete((string) $state['token']);
                ++$removed;
            }
        }

        return $removed;
    }

    // ------------------------------------------------------------------ vista previa

    /**
     * Lee el fichero entero sin escribir nada en el catálogo y cuenta lo que pasaría.
     *
     * @return array<string,mixed> header, sample (primeras filas), creates, updates, errors (lista), error_count, rows, complete,
     *                             known (cabeceras reconocidas), unknown, new_attributes
     */
    public function preview(string $token): array
    {
        $state = $this->load($token);
        if ($state === null) {
            throw new \InvalidArgumentException('The import does not exist');
        }
        $columns = $this->columns($state);
        $stream = CsvStream::open($this->csvPath($token), (string) $state['delimiter']);
        $writer = new ProductWriter($this->app);
        $out = [
            'header' => $stream->header(), 'sample' => [], 'creates' => 0, 'updates' => 0, 'errors' => [], 'error_count' => 0, 'rows' => 0,
            'complete' => true, 'unknown' => $state['unknown'], 'new_attributes' => $state['new_attributes'],
        ];
        $skuIndex = $this->skuIndex($columns);
        $planned = [];
        try {
            while (($cells = $stream->next()) !== null) {
                if ($out['rows'] >= self::PREVIEW_ROWS) {
                    $out['complete'] = false;
                    break;
                }
                ++$out['rows'];
                $status = 'error';
                $message = '';
                try {
                    $action = $writer->plan(RowParser::parse($cells, $columns), $planned);
                    $status = $action;
                    ++$out[$action === 'create' ? 'creates' : 'updates'];
                } catch (RowError $e) {
                    $message = $e->getMessage();
                    ++$out['error_count'];
                    if (count($out['errors']) < self::ERRORS_SHOWN) {
                        $out['errors'][] = ['row' => $out['rows'] + 1, 'sku' => $skuIndex !== null && isset($cells[$skuIndex]) ? $cells[$skuIndex] : '', 'message' => $message];
                    }
                }
                if (count($out['sample']) < self::SAMPLE) {
                    $out['sample'][] = ['row' => $out['rows'] + 1, 'cells' => $cells, 'status' => $status, 'message' => $message];
                }
            }
        } finally {
            $stream->close();
        }

        return $out;
    }

    // ------------------------------------------------------------------ ejecución

    /**
     * Deja la importación en cola: el informe nace con su cabecera y el estado pasa a «queued». false si no está para empezar.
     */
    public function start(string $token): bool
    {
        $state = $this->load($token);
        if ($state === null || $state['status'] !== 'uploaded') {
            return false;
        }
        $state['status'] = 'queued';
        $state['started_at'] = $this->app->clock()->sql();
        $h = fopen($this->reportPath($token), 'wb');
        if ($h === false) {
            throw new \RuntimeException('The report could not be written');
        }
        fputcsv($h, ['row', 'sku', 'result', 'message'], ';', '"', '');
        fclose($h);
        $this->save($state);

        return true;
    }

    /**
     * Trabaja por tandas de 50 filas hasta acabar el fichero o gastar $seconds segundos. Devuelve el estado:
     * `status` es «running» si queda trabajo y «done» si ha terminado.
     *
     * @return array<string,mixed>
     */
    public function work(string $token, int $seconds = 20): array
    {
        $state = $this->load($token);
        if ($state === null) {
            throw new \InvalidArgumentException('The import does not exist');
        }
        if ($state['status'] !== 'queued' && $state['status'] !== 'running') {
            return $state;
        }
        $state['status'] = 'running';
        $this->save($state);
        $columns = $this->columns($state);
        $skuIndex = $this->skuIndex($columns);
        $stream = CsvStream::open($this->csvPath($token), (string) $state['delimiter'], (int) $state['offset']);
        $writer = new ProductWriter($this->app);
        $report = fopen($this->reportPath($token), 'ab');
        $started = microtime(true);
        try {
            while (true) {
                $taken = 0;
                while ($taken < self::BATCH && ($cells = $stream->next()) !== null) {
                    ++$taken;
                    ++$state['row'];
                    $this->doRow($writer, $cells, $columns, $skuIndex, $state, $report);
                }
                $state['offset'] = $stream->position();
                if ($taken < self::BATCH) {
                    $state['status'] = 'done';
                    $state['finished_at'] = $this->app->clock()->sql();
                    break;
                }
                $this->save($state);
                if (microtime(true) - $started >= $seconds) {
                    break;
                }
            }
        } finally {
            $stream->close();
            if (is_resource($report)) {
                fclose($report);
            }
            $writer->finish();
        }
        $this->save($state);

        return $state;
    }

    /**
     * Una fila: se escribe, o se apunta por qué no.
     *
     * @param string[]                        $cells
     * @param array<int,array<string,string>> $columns
     * @param array<string,mixed>             $state
     * @param resource|false                  $report
     */
    private function doRow(ProductWriter $writer, array $cells, array $columns, ?int $skuIndex, array &$state, $report): void
    {
        $number = (int) $state['row'] + 1;
        $sku = $skuIndex !== null && isset($cells[$skuIndex]) ? trim($cells[$skuIndex]) : '';
        try {
            $done = $writer->apply(RowParser::parse($cells, $columns));
            ++$state[$done['result']];
            $this->line($report, $number, $sku, $done['result'], '');
            foreach ($done['warnings'] as $warning) {
                ++$state['warnings'];
                $this->line($report, $number, $sku, 'warning', $warning);
            }
        } catch (RowError $e) {
            $this->failed($state, $report, $number, $sku, $e->getMessage());
        } catch (\Throwable $e) {
            // Algo que no es de la fila (la base de datos, un módulo): se apunta en el registro y se sigue con la siguiente.
            $this->app->log()->exception($e, 'tools.csv_import');
            $this->failed($state, $report, $number, $sku, 'Unexpected error: ' . $e->getMessage());
        }
    }

    /**
     * @param array<string,mixed> $state
     * @param resource|false      $report
     */
    private function failed(array &$state, $report, int $number, string $sku, string $message): void
    {
        ++$state['errors'];
        if (count($state['error_sample']) < self::ERRORS_SHOWN) {
            $state['error_sample'][] = ['row' => $number, 'sku' => $sku, 'message' => $message];
        }
        $this->line($report, $number, $sku, 'error', $message);
    }

    /**
     * @param resource|false $report
     */
    private function line($report, int $row, string $sku, string $result, string $message): void
    {
        if (!is_resource($report)) {
            return;
        }
        $safe = static function (string $text): string {
            return $text !== '' && strpos('=+-@', $text[0]) !== false ? "'" . $text : $text;
        };
        fputcsv($report, [(string) $row, $safe($sku), $result, $safe($message)], ';', '"', '');
    }

    /**
     * @param array<string,mixed> $state
     *
     * @return array<int,array<string,string>>
     */
    private function columns(array $state): array
    {
        $columns = [];
        foreach (isset($state['columns']) && is_array($state['columns']) ? $state['columns'] : [] as $i => $column) {
            if (is_array($column) && isset($column['kind'], $column['key'])) {
                $columns[(int) $i] = ['kind' => (string) $column['kind'], 'key' => (string) $column['key']];
            }
        }

        return $columns;
    }

    /**
     * @param array<int,array<string,string>> $columns
     */
    private function skuIndex(array $columns): ?int
    {
        foreach ($columns as $i => $column) {
            if ($column['kind'] === 'fixed' && $column['key'] === 'sku') {
                return (int) $i;
            }
        }

        return null;
    }
}
