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
use Aifec\Kernel\Clock;
use Aifec\Util\Str;

/**
 * Una pasada de exportación de un perfil.
 *
 * Reglas bloqueantes:
 *  - «Solo lo nuevo»: el cursor del perfil se siembra en «ahora» al crearlo; la primera pasada NO exporta lo que ya existía. Si no hay
 *    cursor (un perfil de ejemplo), la primera pasada lo siembra y no exporta nada. Solo un intervalo de fechas elegido a mano Y
 *    confirmado con el número de registros exporta lo antiguo, y no mueve el cursor.
 *  - El cursor avanza por lo ENTREGADO y solo cuando la entrega ha salido bien: un envío que falla no lo mueve.
 *  - Los destinos que sacan el fichero de la tienda (ftp, ftps, post, email) respetan el ajuste erp.outbound.
 *  - «Probar» enseña las 10 primeras filas y no mueve nada, no guarda nada y no envía nada.
 */
final class Exporter
{
    private const BATCH = 500;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Ejecuta el perfil. $options: trigger (manual, schedule, cli) y range (['from' => 'Y-m-d H:i:s'|null, 'to' => …, 'confirm' => N]).
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> la fila de erp_export_runs (status: ok, empty, failed, blocked, rejected, busy)
     */
    public function run(int $profileId, array $options = []): array
    {
        $profile = $this->profiles()->find($profileId);
        if ($profile === null || $profile['direction'] !== 'export') {
            throw new \InvalidArgumentException('Unknown export profile');
        }
        $db = $this->app->db();
        $now = $this->app->clock()->now();
        $trigger = isset($options['trigger']) ? (string) $options['trigger'] : 'manual';
        if ($db->row("SELECT id FROM {erp_export_runs} WHERE profile_id = ? AND status = 'running' AND started_at > ?", [$profileId, $this->app->clock()->sql($now - 600)]) !== null) {
            return ['id' => 0, 'status' => 'busy', 'records' => 0, 'bytes' => 0, 'error' => 'Another run of this profile is still going'];
        }
        $runId = $db->insert('erp_export_runs', [
            'profile_id' => $profileId, 'direction' => 'export', 'trigger_kind' => $trigger, 'status' => 'running', 'destination' => (string) $profile['destination'],
            'started_at' => $this->app->clock()->sql($now),
        ]);
        $result = ['status' => 'failed', 'records' => 0, 'bytes' => 0, 'error' => null, 'file' => null, 'file_name' => null, 'expires_at' => null, 'report' => null];
        try {
            $result = $this->execute($profile, $options, $result);
        } catch (\Throwable $e) {
            $this->app->log()->exception($e, 'erp.export ' . $profileId);
            $result['status'] = 'failed';
            $result['error'] = mb_substr($e->getMessage() !== '' ? $e->getMessage() : get_class($e), 0, 500, 'UTF-8');
        }
        $result['finished_at'] = $this->app->clock()->sql();
        $db->update('erp_export_runs', $result, ['id' => $runId]);
        $set = ['last_run_at' => $this->app->clock()->sql()];
        if ($trigger === 'schedule' && $profile['enabled']) {
            $set['next_run_at'] = Profiles::nextRun($this->app, (string) $profile['schedule'], $profile['schedule_every'] !== null ? (int) $profile['schedule_every'] : null, $profile['schedule_at'] !== null ? (string) $profile['schedule_at'] : null, $now);
        }
        $db->update('erp_exports', $set, ['id' => $profileId]);

        return ['id' => $runId] + $result;
    }

    /**
     * Las primeras filas de lo que se exportaría ahora, sin mover nada, sin guardar y sin enviar.
     *
     * @return array{header:string[],rows:array<int,string[]>,note:string}
     */
    public function preview(int $profileId, int $rows = 10): array
    {
        $profile = $this->profiles()->find($profileId);
        if ($profile === null || $profile['direction'] !== 'export') {
            throw new \InvalidArgumentException('Unknown export profile');
        }
        $formats = $this->formats($profile);
        $window = ['type' => 'all', 'after' => 0];
        $note = '';
        if (!empty($profile['filter']['only_new'])) {
            $cursor = (new Cursors($this->app))->get(Cursors::scope($profile['id']));
            if ($cursor === null) {
                return ['header' => $formats->headers(), 'rows' => [], 'note' => 'Nothing is new yet: the first run will only mark where to start'];
            }
            $window = ['type' => 'cursor', 'value' => $cursor];
            $note = 'Only what is new since the last run';
        }
        $out = [];
        $sources = $this->app->service('erp')->sources();
        $fetched = $sources->fetch((string) $profile['kind'], $profile['filter'], $window, $rows);
        foreach (array_slice($fetched['rows'], 0, $rows) as $row) {
            $out[] = $formats->cells($row);
        }

        return ['header' => $formats->headers(), 'rows' => $out, 'note' => $note];
    }

    /** Cuántos registros entrarían en un intervalo (el comerciante lo confirma viendo la cifra). */
    public function countRange(int $profileId, ?string $from, ?string $to): int
    {
        $profile = $this->profiles()->find($profileId);

        return $profile === null ? 0 : $this->app->service('erp')->sources()->countRange((string) $profile['kind'], $profile['filter'], $from, $to);
    }

    /**
     * El fichero de una pasada si sigue disponible (7 días).
     *
     * @return array{path:string,name:string,mime:string}|null
     */
    public function download(int $runId): ?array
    {
        $run = $this->app->db()->find('erp_export_runs', ['id' => $runId]);
        if ($run === null || $run['file'] === null || $run['expires_at'] === null || Clock::parse((string) $run['expires_at']) <= $this->app->clock()->now()) {
            return null;
        }
        $path = $this->app->site()->path('private/erp/runs/' . basename((string) $run['file']));
        if (!is_file($path)) {
            return null;
        }
        $ext = strtolower((string) pathinfo((string) $run['file_name'], PATHINFO_EXTENSION));
        $mime = ['csv' => 'text/csv', 'json' => 'application/json', 'jsonl' => 'application/x-ndjson', 'xml' => 'application/xml'];

        return ['path' => $path, 'name' => (string) $run['file_name'], 'mime' => isset($mime[$ext]) ? $mime[$ext] : 'application/octet-stream'];
    }

    /**
     * Limpieza diaria: los ficheros de pasadas caducados (7 días), los de las carpetas de salida con más de erp.folder_days días y
     * las pasadas con más de 180 días. Solo toca lo que el propio módulo creó.
     *
     * @return array{files:int,runs:int}
     */
    public function purge(): array
    {
        $db = $this->app->db();
        $now = $this->app->clock()->now();
        $files = 0;
        foreach ($db->select('SELECT id, file FROM {erp_export_runs} WHERE file IS NOT NULL AND expires_at < ?', [$this->app->clock()->sql($now)]) as $run) {
            $path = $this->app->site()->path('private/erp/runs/' . basename((string) $run['file']));
            if (is_file($path) && @unlink($path)) {
                ++$files;
            }
            $db->update('erp_export_runs', ['file' => null], ['id' => (int) $run['id']]);
        }
        $days = max(1, $this->app->settings()->int('erp.folder_days', 14));
        $out = $this->app->site()->path('private/erp/out');
        $found = is_dir($out) ? glob($out . '/*/*') : [];
        foreach (is_array($found) ? $found : [] as $file) {
            if (is_file($file) && filemtime($file) < $now - $days * 86400 && @unlink($file)) {
                ++$files;
            }
        }
        $runs = $db->run('DELETE FROM {erp_export_runs} WHERE started_at < ?', [$this->app->clock()->sql($now - 180 * 86400)]);

        return ['files' => $files, 'runs' => $runs];
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @param array<string,mixed> $profile
     * @param array<string,mixed> $options
     * @param array<string,mixed> $result
     *
     * @return array<string,mixed>
     */
    private function execute(array $profile, array $options, array $result): array
    {
        $kind = (string) $profile['kind'];
        $scope = Cursors::scope((int) $profile['id']);
        $cursors = new Cursors($this->app);
        $sources = $this->app->service('erp')->sources();
        $destination = (string) $profile['destination'];
        $range = isset($options['range']) && is_array($options['range']) ? $options['range'] : null;

        if (!isset(Destinations::available($this->app)[$destination])) {
            throw new \RuntimeException('This destination is not available here');
        }
        if (in_array($destination, Destinations::LEAVING, true) && !$this->app->settings()->bool('erp.outbound', false)) {
            $result['status'] = 'blocked';
            $result['error'] = 'Outgoing messages are switched off in the settings';

            return $result;
        }

        // Qué se lee: un intervalo confirmado, lo nuevo desde el cursor, o todo.
        if ($range !== null) {
            $from = isset($range['from']) && $range['from'] !== '' ? (string) $range['from'] : null;
            $to = isset($range['to']) && $range['to'] !== '' ? (string) $range['to'] : null;
            $count = $sources->countRange($kind, $profile['filter'], $from, $to);
            if (!isset($range['confirm']) || (int) $range['confirm'] !== $count) {
                $result['status'] = 'rejected';
                $result['error'] = 'The interval has ' . $count . ' records: confirm that number to export them';

                return $result;
            }
            $window = ['type' => 'range', 'from' => $from, 'to' => $to, 'after' => 0];
            $moveCursor = false;
        } elseif (!empty($profile['filter']['only_new'])) {
            $cursor = $cursors->get($scope);
            if ($cursor === null) {
                $cursors->set($scope, $sources->nowCursor($kind));
                $result['status'] = 'empty';
                $result['error'] = 'The starting point was set to now: only what happens from now on will be exported';

                return $result;
            }
            $window = ['type' => 'cursor', 'value' => $cursor];
            $moveCursor = true;
        } else {
            $window = ['type' => 'all', 'after' => 0];
            $moveCursor = false;
        }

        $formats = $this->formats($profile);
        $formats->begin();
        $max = max(1, $this->app->settings()->int('erp.export_max_rows', 50000));
        $last = null;
        while ($formats->count() < $max) {
            $batch = $sources->fetch($kind, $profile['filter'], $window, self::BATCH);
            foreach ($batch['rows'] as $row) {
                $formats->add($row);
            }
            if ($batch['next'] === null) {
                break;
            }
            $last = $batch['next'];
            if ($window['type'] === 'cursor') {
                $window['value'] = $batch['next'];
            } else {
                $window['after'] = (int) $batch['next'];
            }
            if ($batch['scanned'] < self::BATCH) {
                break;
            }
        }
        $records = $formats->count();
        $contents = $formats->finish();
        if ($records === 0) {
            // Lo leído no daba ninguna fila (pedidos sin líneas): se da por consumido para no volver a leerlo.
            if ($moveCursor && $last !== null) {
                $cursors->set($scope, $last);
            }
            $result['status'] = 'empty';

            return $result;
        }

        $name = Str::slug((string) $profile['name'], 40);
        $fileName = ($name !== '' ? $name : 'export') . '-' . gmdate('Ymd-His', $this->app->clock()->now()) . '.' . $formats->extension();
        $stored = $this->store($contents, $fileName);
        $result['records'] = $records;
        $result['bytes'] = strlen($contents);
        $result['file'] = $stored;
        $result['file_name'] = $fileName;
        $result['expires_at'] = $this->app->clock()->sql($this->app->clock()->now() + max(1, $this->app->settings()->int('erp.keep_days', 7)) * 86400);
        try {
            $result['report'] = (string) json_encode(['note' => $this->app->service('erp')->destinations()->deliver($profile, $fileName, $contents, $formats->mime())]);
        } catch (\Throwable $e) {
            // El fichero queda guardado (se puede descargar) pero el cursor NO se mueve: la próxima pasada lo vuelve a intentar.
            $result['status'] = 'failed';
            $result['error'] = mb_substr($e->getMessage() !== '' ? $e->getMessage() : get_class($e), 0, 500, 'UTF-8');

            return $result;
        }
        $result['status'] = 'ok';
        // Solo con la entrega hecha: un envío que falla lanza antes de llegar aquí y el cursor se queda donde estaba.
        if ($moveCursor && $last !== null) {
            $cursors->set($scope, $last);
        }

        return $result;
    }

    /**
     * @param array<string,mixed> $profile
     */
    private function formats(array $profile): Formats
    {
        $columns = $profile['columns'] !== [] ? $profile['columns'] : Sources::defaultColumns((string) $profile['kind']);

        return new Formats($this->app, (string) $profile['kind'], (string) $profile['format'], $columns, $profile['options']);
    }

    /** Guarda el fichero de la pasada en la carpeta privada y devuelve su nombre dentro de ella. */
    private function store(string $contents, string $fileName): string
    {
        $dir = $this->app->site()->path('private/erp/runs', true);
        foreach (['.htaccess' => "Require all denied\n", 'index.html' => ''] as $file => $content) {
            if (is_dir($dir) && !is_file($dir . '/' . $file)) {
                file_put_contents($dir . '/' . $file, $content);
            }
        }
        $stored = bin2hex(random_bytes(6)) . '-' . $fileName;
        if (!is_dir($dir) || file_put_contents($dir . '/' . $stored . '.part', $contents) === false || !rename($dir . '/' . $stored . '.part', $dir . '/' . $stored)) {
            throw new \RuntimeException('The file of the run could not be saved');
        }

        return $stored;
    }

    private function profiles(): Profiles
    {
        return $this->app->service('erp')->profiles();
    }
}
