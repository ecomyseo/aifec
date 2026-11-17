<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;
use Aifec\Security\Random;

/**
 * Las claves de la API (tabla api_keys del núcleo). Solo se guarda el resumen SHA-256 de la clave: la clave en claro se enseña
 * una vez, al crearla. Una clave sirve para subir el límite de uso y, con el ámbito «orders:create», para hacer pedidos.
 *
 * La tabla la comparten otros módulos (el ERP guarda ahí las suyas, con ámbitos «erp:…»). Aquí solo valen las que empiezan por
 * «aifec_api_»: una clave del ERP no entra por esta puerta.
 */
final class Keys
{
    public const PREFIX = 'aifec_api_';

    /** Los ámbitos que se pueden dar a una clave: ámbito => texto. */
    public const SCOPES = [
        'orders:create' => 'Place orders in the name of a customer who confirmed them',
    ];

    /** Una clave nueva, en claro. */
    public static function generate(): string
    {
        return self::PREFIX . Random::token(24);
    }

    /** El resumen que se guarda de una clave. */
    public static function hash(string $key): string
    {
        return hash('sha256', $key);
    }

    /**
     * La clave a la que pertenece un texto, si está activa.
     *
     * @return array<string,mixed>|null la fila de api_keys más 'scopes' como lista
     */
    public static function authenticate(App $app, string $key): ?array
    {
        if ($key === '' || strlen($key) > 200 || strpos($key, self::PREFIX) !== 0) {
            return null;
        }
        $row = $app->db()->row('SELECT * FROM {api_keys} WHERE key_hash = ?', [self::hash($key)]);
        if ($row === null || (int) $row['active'] !== 1) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        $row['scopes'] = self::scopes($row['scopes']);

        return $row;
    }

    /**
     * @param mixed $stored lo que hay en la columna scopes (JSON)
     *
     * @return string[]
     */
    public static function scopes($stored): array
    {
        $list = is_string($stored) && $stored !== '' ? json_decode($stored, true) : null;

        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * Apunta que la clave se ha usado (como mucho una vez por minuto: no se escribe en la base en cada petición).
     *
     * @param array<string,mixed> $key
     */
    public static function touch(App $app, array $key): void
    {
        $now = $app->clock()->now();
        $app->db()->run(
            'UPDATE {api_keys} SET last_used_at = ? WHERE id = ? AND (last_used_at IS NULL OR last_used_at < ?)',
            [$app->clock()->sql($now), (int) $key['id'], $app->clock()->sql($now - 60)]
        );
    }

    /**
     * Apunta en el registro de actividad (tabla audit) lo que hizo una clave. Nunca lanza: un fallo aquí no puede tumbar
     * un pedido que ya está hecho.
     *
     * @param array<string,mixed> $key
     */
    public static function audit(App $app, array $key, string $channel, string $action, string $entity, string $entityId, string $summary): void
    {
        try {
            $app->db()->insert('audit', [
                'user_id' => null,
                'user_name' => mb_substr(strtoupper($channel) . ': ' . (string) $key['name'], 0, 120, 'UTF-8'),
                'action' => substr($action, 0, 80),
                'entity' => $entity !== '' ? substr($entity, 0, 60) : null,
                'entity_id' => $entityId !== '' ? substr($entityId, 0, 60) : null,
                'summary' => $summary !== '' ? mb_substr($summary, 0, 190, 'UTF-8') : null,
                'changes' => json_encode(['key_id' => (int) $key['id']]),
                'ip' => $app->request()->ip,
                'created_at' => $app->clock()->sql(),
            ]);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'api.audit');
        }
    }
}
