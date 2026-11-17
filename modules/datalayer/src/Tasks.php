<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Datalayer;

use Aifec\Kernel\App;

/**
 * Tareas en segundo plano de la capa de datos (las ejecuta el módulo de tareas, ver jobs.php).
 */
final class Tasks
{
    /** Días que se guarda el registro de eventos del servidor. */
    public const KEEP_DAYS = 90;

    /**
     * Manejador del trabajo «datalayer.server_event»: manda un evento encolado.
     *
     * @param array<string,mixed> $payload id del evento (fila de datalayer_events)
     *
     * @return string el estado final
     */
    public static function serverEvent(array $payload, App $app): string
    {
        /** @var Datalayer $datalayer */
        $datalayer = $app->service('datalayer');

        return $datalayer->server()->send(isset($payload['id']) ? (int) $payload['id'] : 0);
    }

    /**
     * Tarea diaria «datalayer.purge»: lo que lleva más de 24 horas «en cola» ya no se manda (pasa a «failed») y el registro se guarda
     * 90 días. Solo toca eventos del propio módulo; nunca recorre pedidos.
     */
    public static function purge(App $app): ?string
    {
        $db = $app->db();
        $now = $app->clock()->now();
        $stale = $db->run("UPDATE {datalayer_events} SET status = 'failed', response = 'No answer in 24 hours' WHERE status = 'queued' AND created_at < ?", [$app->clock()->sql($now - ServerEvents::MAX_AGE)]);
        $old = $db->run('DELETE FROM {datalayer_events} WHERE created_at < ?', [$app->clock()->sql($now - self::KEEP_DAYS * 86400)]);

        return $stale + $old > 0 ? $stale . ' closed, ' . $old . ' deleted' : null;
    }
}
