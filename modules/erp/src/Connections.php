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
use Aifec\Security\Random;

/**
 * Las conexiones: un ERP externo con su clave. La clave sale de api_keys (solo se guarda su resumen SHA-256) y se enseña UNA vez,
 * al crearla. Los ámbitos (scopes) viven en api_keys; erp_connections guarda el resto (nombre, estado, uso).
 */
final class Connections
{
    public const KEY_PREFIX = 'aifec_erp_';

    /** Ámbitos que se pueden dar a una clave. Escribir no implica leer: se dan por separado. */
    public const SCOPES = [
        'erp:orders' => 'Read orders',
        'erp:orders:write' => 'Confirm orders, change their status and add tracking',
        'erp:products' => 'Read products',
        'erp:products:write' => 'Create and update products and prices',
        'erp:stock' => 'Read stock',
        'erp:stock:write' => 'Update stock',
        'erp:customers' => 'Read customers',
        'erp:customers:write' => 'Create and update customers',
        'erp:invoices' => 'Read invoices and download their PDF',
    ];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Da de alta una conexión con su clave nueva. La clave en claro solo está en el valor devuelto.
     *
     * @param string[] $scopes
     *
     * @return array{id:int,key:string}
     *
     * @throws \InvalidArgumentException si el nombre está vacío o algún ámbito no existe
     */
    public function create(string $name, array $scopes): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name, 'UTF-8') > 120) {
            throw new \InvalidArgumentException('The name must have between 1 and 120 characters');
        }
        $clean = [];
        foreach ($scopes as $scope) {
            if (!is_string($scope) || !isset(self::SCOPES[$scope])) {
                throw new \InvalidArgumentException('Unknown scope');
            }
            $clean[$scope] = true;
        }
        $clean = array_keys($clean);
        if ($clean === []) {
            throw new \InvalidArgumentException('Choose at least one scope');
        }
        $key = self::KEY_PREFIX . Random::token(24);
        $now = $this->app->clock()->sql();
        $json = (string) json_encode($clean);
        $id = (int) $this->app->db()->transaction(function ($db) use ($name, $key, $now, $json, $clean): int {
            $keyId = $db->insert('api_keys', [
                'name' => mb_substr('ERP: ' . $name, 0, 120, 'UTF-8'), 'key_hash' => hash('sha256', $key), 'scopes' => $json,
                'active' => 1, 'created_at' => $now,
            ]);

            return $db->insert('erp_connections', [
                'name' => $name, 'api_key_id' => $keyId, 'key_hint' => substr($key, -4), 'scopes' => $json, 'active' => 1,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        });

        return ['id' => $id, 'key' => $key];
    }

    /**
     * La conexión a la que pertenece una clave en claro, si la clave y la conexión siguen activas.
     *
     * @return array<string,mixed>|null la fila de la conexión más 'scopes' (lista) y 'key_id'
     */
    public function authenticate(string $key): ?array
    {
        if ($key === '' || strlen($key) > 200) {
            return null;
        }
        $row = $this->app->db()->row(
            'SELECT c.*, k.active AS key_active, k.scopes AS key_scopes, k.id AS key_id FROM {erp_connections} c '
            . 'JOIN {api_keys} k ON k.id = c.api_key_id WHERE k.key_hash = ?',
            [hash('sha256', $key)]
        );
        if ($row === null || (int) $row['key_active'] !== 1 || (int) $row['active'] !== 1 || $row['revoked_at'] !== null) {
            return null;
        }
        $scopes = json_decode((string) $row['key_scopes'], true);
        $row['scopes'] = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];

        return $row;
    }

    /**
     * Apunta que la clave se ha usado (como mucho una vez por minuto: no se escribe en la base en cada petición).
     *
     * @param array<string,mixed> $connection
     */
    public function touch(array $connection): void
    {
        $now = $this->app->clock()->now();
        $db = $this->app->db();
        $db->run('UPDATE {api_keys} SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)', [
            $this->app->clock()->sql($now), (int) $connection['key_id'], $this->app->clock()->sql($now - 60),
        ]);
        $db->run('UPDATE {erp_connections} SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)', [
            $this->app->clock()->sql($now), (int) $connection['id'], $this->app->clock()->sql($now - 60),
        ]);
    }

    /**
     * @return array<int,array<string,mixed>> todas, la más nueva primero, con 'scopes' como lista
     */
    public function all(): array
    {
        $rows = $this->app->db()->select('SELECT * FROM {erp_connections} ORDER BY id DESC');
        foreach ($rows as $i => $row) {
            $rows[$i] = $this->decorate($row);
        }

        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $row = $this->app->db()->find('erp_connections', ['id' => $id]);

        return $row === null ? null : $this->decorate($row);
    }

    /** Activa o desactiva (la clave deja de valer mientras esté apagada). Una conexión revocada no vuelve. */
    public function setActive(int $id, bool $active): bool
    {
        $row = $this->find($id);
        if ($row === null || $row['revoked_at'] !== null) {
            return false;
        }
        $now = $this->app->clock()->sql();
        $this->app->db()->update('erp_connections', ['active' => $active ? 1 : 0, 'updated_at' => $now], ['id' => $id]);
        $this->app->db()->update('api_keys', ['active' => $active ? 1 : 0], ['id' => (int) $row['api_key_id']]);

        return true;
    }

    /** Revoca la clave para siempre y apaga sus avisos. */
    public function revoke(int $id): bool
    {
        $row = $this->find($id);
        if ($row === null || $row['revoked_at'] !== null) {
            return false;
        }
        $now = $this->app->clock()->sql();
        $db = $this->app->db();
        $db->update('erp_connections', ['active' => 0, 'revoked_at' => $now, 'updated_at' => $now], ['id' => $id]);
        $db->update('api_keys', ['active' => 0], ['id' => (int) $row['api_key_id']]);
        $db->update('erp_webhooks', ['active' => 0, 'updated_at' => $now], ['connection_id' => $id]);

        return true;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function decorate(array $row): array
    {
        $scopes = json_decode((string) $row['scopes'], true);
        $row['scopes'] = is_array($scopes) ? array_values(array_filter($scopes, 'is_string')) : [];
        $row['id'] = (int) $row['id'];
        $row['active'] = (int) $row['active'] === 1;

        return $row;
    }
}
