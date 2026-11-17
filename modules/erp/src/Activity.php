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

/**
 * El registro de actividad (tabla audit) de lo que escribe un ERP: queda apuntada la conexión que lo hizo.
 */
final class Activity
{
    /**
     * Nunca lanza: un fallo aquí no puede tumbar una escritura que ya está hecha.
     *
     * @param array<string,mixed> $connection la fila de la conexión
     * @param array<string,mixed> $changes    campo => [antes, después]
     */
    public static function log(App $app, array $connection, string $action, string $entity, string $entityId, string $summary, array $changes = []): void
    {
        try {
            $app->db()->insert('audit', [
                'user_id' => null,
                'user_name' => mb_substr('ERP: ' . (string) $connection['name'], 0, 120, 'UTF-8'),
                'action' => substr($action, 0, 80),
                'entity' => $entity !== '' ? substr($entity, 0, 60) : null,
                'entity_id' => $entityId !== '' ? substr($entityId, 0, 60) : null,
                'summary' => $summary !== '' ? mb_substr($summary, 0, 190, 'UTF-8') : null,
                'changes' => $changes !== [] ? json_encode($changes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR) : null,
                'ip' => $app->request()->ip,
                'created_at' => $app->clock()->sql(),
            ]);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'erp.activity');
        }
    }
}
