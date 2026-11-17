<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * Las propuestas guardadas (tabla copilot_proposals): una por cada orden escrita. Aquí solo se leen y se escriben filas; lo que
 * significan (entender, aplicar, deshacer) es cosa de Copilot.
 *
 * Estados: proposed, incomplete, applied, undone, discarded y failed.
 */
final class Proposals
{
    public const STATES = ['proposed', 'incomplete', 'applied', 'undone', 'discarded', 'failed'];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Guarda una propuesta nueva y devuelve su identificador.
     *
     * @param array<string,mixed> $data text, action, args, preview, status, fingerprint, source, result
     */
    public function create(array $data): int
    {
        $now = $this->app->clock()->sql();
        $admin = $this->app->auth()->admin();

        return $this->app->db()->insert('copilot_proposals', [
            'user_id' => $admin !== null ? (int) $admin['id'] : null,
            'user_name' => $admin !== null ? mb_substr((string) $admin['name'], 0, 120, 'UTF-8') : null,
            'text' => (string) $data['text'],
            'action' => isset($data['action']) ? (string) $data['action'] : '',
            'args' => self::json(isset($data['args']) ? $data['args'] : []),
            'preview' => self::json(isset($data['preview']) ? $data['preview'] : []),
            'status' => isset($data['status']) ? (string) $data['status'] : 'incomplete',
            'fingerprint' => isset($data['fingerprint']) ? (string) $data['fingerprint'] : '',
            'result' => isset($data['result']) ? (string) $data['result'] : null,
            'source' => isset($data['source']) ? (string) $data['source'] : 'rules',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $row = $this->app->db()->find('copilot_proposals', ['id' => $id]);

        return $row === null ? null : self::hydrate($row);
    }

    /**
     * Cambia campos de una propuesta (los JSON se pasan como arrays).
     *
     * @param array<string,mixed> $fields
     */
    public function update(int $id, array $fields): void
    {
        foreach (['args', 'preview', 'undo'] as $json) {
            if (array_key_exists($json, $fields)) {
                $fields[$json] = $fields[$json] === null ? null : self::json($fields[$json]);
            }
        }
        $fields['updated_at'] = $this->app->clock()->sql();
        $this->app->db()->update('copilot_proposals', $fields, ['id' => $id]);
    }

    /**
     * Pasa de un estado a otro con UNA sentencia condicionada al estado de partida: de dos peticiones a la vez, solo una gana.
     *
     * @param array<string,mixed> $extra otros campos que se escriben en el mismo cambio
     */
    public function claim(int $id, string $from, string $to, array $extra = []): bool
    {
        $now = $this->app->clock()->sql();
        $set = ['status' => $to, 'updated_at' => $now] + $extra;
        $params = [];
        $sql = [];
        foreach ($set as $column => $value) {
            $sql[] = $this->app->db()->ident((string) $column) . ' = ?';
            $params[] = $value;
        }
        $params[] = $id;
        $params[] = $from;

        return $this->app->db()->run('UPDATE {copilot_proposals} SET ' . implode(', ', $sql) . ' WHERE id = ? AND status = ?', $params) === 1;
    }

    /**
     * Las últimas propuestas de una persona.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(?int $userId, int $limit = 25): array
    {
        $rows = $userId === null
            ? $this->app->db()->select('SELECT * FROM {copilot_proposals} WHERE user_id IS NULL ORDER BY id DESC LIMIT ?', [$limit])
            : $this->app->db()->select('SELECT * FROM {copilot_proposals} WHERE user_id = ? ORDER BY id DESC LIMIT ?', [$userId, $limit]);
        $out = [];
        foreach ($rows as $row) {
            $out[] = self::hydrate($row);
        }

        return $out;
    }

    /** Borra lo que lleva más de $days días sin tocarse. Solo filas del copiloto. */
    public function purge(int $days): int
    {
        $limit = $this->app->clock()->sql($this->app->clock()->now() - max(1, $days) * 86400);

        return $this->app->db()->run('DELETE FROM {copilot_proposals} WHERE updated_at < ?', [$limit]);
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    public static function hydrate(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['user_id'] = $row['user_id'] !== null ? (int) $row['user_id'] : null;
        foreach (['args', 'preview'] as $json) {
            $data = isset($row[$json]) && $row[$json] !== null ? json_decode((string) $row[$json], true) : null;
            $row[$json] = is_array($data) ? $data : [];
        }
        $undo = isset($row['undo']) && $row['undo'] !== null ? json_decode((string) $row['undo'], true) : null;
        $row['undo'] = is_array($undo) ? $undo : null;
        $row['result'] = isset($row['result']) && $row['result'] !== null ? (string) $row['result'] : '';
        $row['created_ts'] = Clock::parse((string) $row['created_at']);

        return $row;
    }

    /**
     * @param mixed $data
     */
    private static function json($data): string
    {
        return (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
