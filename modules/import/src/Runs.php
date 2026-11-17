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

use Aifec\Kernel\App;

/**
 * Acceso a las tablas de la migración: import_runs (una fila por migración o simulacro) e import_log (lo que hay que
 * poder enseñar registro a registro). Las columnas de texto con JSON se leen ya descodificadas.
 */
final class Runs
{
    /** Columnas de import_runs que guardan JSON. */
    private const JSON = ['options', 'cursor', 'counters', 'equivalences', 'unmapped', 'checksums'];

    /** Estados que ocupan el cerrojo: solo puede haber una migración así por sitio. */
    public const OPEN = ['running', 'paused'];

    /** Estados finales: una migración que llega a uno no vuelve a moverse. */
    public const CLOSED = ['done', 'failed', 'cancelled'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Da de alta una migración en borrador.
     *
     * @param array<string,mixed> $values source, mode, source_key, label, options, equivalences, dry_run, user_id
     */
    public function create(array $values): int
    {
        $now = $this->app->clock()->sql();
        $row = [
            'source' => (string) $values['source'],
            'mode' => (string) $values['mode'],
            'source_key' => isset($values['source_key']) ? substr((string) $values['source_key'], 0, 60) : '',
            'label' => isset($values['label']) && $values['label'] !== null ? substr((string) $values['label'], 0, 120) : null,
            'dry_run' => !empty($values['dry_run']) ? 1 : 0,
            'status' => 'draft',
            'phase' => 'write',
            'source_errors' => 0,
            'user_id' => isset($values['user_id']) ? (int) $values['user_id'] : null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        foreach (['options', 'equivalences', 'counters', 'cursor', 'unmapped', 'checksums'] as $json) {
            if (isset($values[$json]) && is_array($values[$json])) {
                $row[$json] = self::encode($values[$json]);
            }
        }

        return $this->app->db()->insert('import_runs', $row);
    }

    /**
     * Una migración con sus columnas JSON ya descodificadas, o null si no existe.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->app->db()->row('SELECT * FROM {import_runs} WHERE id = ?', [$id]);

        return $row === null ? null : self::decode($row);
    }

    /**
     * La migración que ocupa el cerrojo (en curso o en pausa), o null.
     *
     * @return array<string,mixed>|null
     */
    public function open(): ?array
    {
        $row = $this->app->db()->row("SELECT * FROM {import_runs} WHERE status IN ('running', 'paused') ORDER BY id LIMIT 1");

        return $row === null ? null : self::decode($row);
    }

    /**
     * Las últimas migraciones (las reales y los simulacros), la más reciente primero.
     *
     * @return array<int,array<string,mixed>>
     */
    public function recent(int $limit = 50): array
    {
        $rows = $this->app->db()->select('SELECT * FROM {import_runs} ORDER BY id DESC LIMIT ' . max(1, (int) $limit));
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::decode($row);
        }

        return $out;
    }

    /**
     * Cambia columnas. Si se da $onlyStatus, solo si la fila sigue en ese estado (o en uno de esos): devuelve las filas
     * cambiadas, así quien llama sabe si ganó.
     *
     * @param array<string,mixed> $values
     * @param string|string[]|null $onlyStatus
     */
    public function update(int $id, array $values, $onlyStatus = null): int
    {
        foreach ($values as $column => $value) {
            if (in_array($column, self::JSON, true) && is_array($value)) {
                $values[$column] = self::encode($value);
            }
        }
        $values['updated_at'] = $this->app->clock()->sql();
        if ($onlyStatus === null) {
            return $this->app->db()->update('import_runs', $values, ['id' => $id]);
        }
        $sets = [];
        $params = [];
        foreach ($values as $column => $value) {
            $sets[] = $this->app->db()->ident((string) $column) . ' = ?';
            $params[] = $value;
        }
        $statuses = is_array($onlyStatus) ? array_values($onlyStatus) : [$onlyStatus];
        $in = $this->app->db()->in($statuses, $params);
        $params[] = $id;

        return $this->app->db()->run('UPDATE {import_runs} SET ' . implode(', ', $sets) . ' WHERE status IN (' . $in . ') AND id = ?', $params);
    }

    /**
     * Borra del todo una migración que no ha llegado a empezar (una prueba de conexión desde la consola) y sus líneas.
     * Una migración que ya trabajó no se borra nunca: queda en el histórico.
     */
    public function discard(int $id): bool
    {
        $row = $this->app->db()->row('SELECT status FROM {import_runs} WHERE id = ?', [$id]);
        if ($row === null || (string) $row['status'] !== 'draft') {
            return false;
        }
        $this->app->db()->delete('import_log', ['run_id' => $id]);

        return $this->app->db()->delete('import_runs', ['id' => $id]) === 1;
    }

    /**
     * Una línea del informe registro a registro. $result: skipped, conflict, error o note.
     */
    public function log(int $runId, string $type, string $sourceId, string $result, string $reason): void
    {
        $this->app->db()->insert('import_log', [
            'run_id' => $runId,
            'type' => substr($type, 0, 30),
            'source_id' => $sourceId === '' ? null : substr($sourceId, 0, 64),
            'result' => substr($result, 0, 12),
            'reason' => $reason === '' ? null : mb_substr($reason, 0, 255),
            'created_at' => $this->app->clock()->sql(),
        ]);
    }

    /**
     * Líneas del informe, las primeras por orden de aparición.
     *
     * @return array<int,array<string,mixed>>
     */
    public function logs(int $runId, string $type = '', string $result = '', int $limit = 200, int $afterId = 0): array
    {
        $sql = 'SELECT * FROM {import_log} WHERE run_id = ? AND id > ?';
        $params = [$runId, $afterId];
        if ($type !== '') {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }
        if ($result !== '') {
            $sql .= ' AND result = ?';
            $params[] = $result;
        }

        return $this->app->db()->select($sql . ' ORDER BY id LIMIT ' . max(1, $limit), $params);
    }

    /** Las últimas líneas, la más reciente primero (para «últimos avisos»). */
    public function lastLogs(int $runId, int $limit = 5): array
    {
        return $this->app->db()->select('SELECT * FROM {import_log} WHERE run_id = ? ORDER BY id DESC LIMIT ' . max(1, $limit), [$runId]);
    }

    /**
     * Cuántas líneas hay por resultado.
     *
     * @return array<string,int>
     */
    public function logCounts(int $runId): array
    {
        $out = [];
        foreach ($this->app->db()->select('SELECT result, COUNT(*) AS n FROM {import_log} WHERE run_id = ? GROUP BY result', [$runId]) as $row) {
            $out[(string) $row['result']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private static function decode(array $row): array
    {
        foreach (self::JSON as $column) {
            $value = isset($row[$column]) && is_string($row[$column]) && $row[$column] !== '' ? json_decode($row[$column], true) : null;
            $row[$column] = is_array($value) ? $value : [];
        }
        foreach (['id', 'dry_run', 'source_errors'] as $column) {
            $row[$column] = isset($row[$column]) ? (int) $row[$column] : 0;
        }
        $row['user_id'] = isset($row['user_id']) && $row['user_id'] !== null ? (int) $row['user_id'] : null;

        return $row;
    }

    /**
     * @param array<mixed,mixed> $value
     */
    private static function encode(array $value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? $json : '{}';
    }
}
