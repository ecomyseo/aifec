<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Kernel\App;

/**
 * El cálculo de las notas en segundo plano (trabajo «semantic.audit», lo registra jobs.php).
 *
 *   Sin datos         recalcula todos los productos publicados, por tandas de 100: cada tanda encola la siguiente.
 *   {"id": 12}        recalcula solo el producto 12 (lo encola el gancho de guardado, con un minuto de espera para
 *                     juntar los guardados seguidos).
 *
 * La tarea periódica «semantic.audit» lo lanza una vez al día, de madrugada.
 */
final class AuditJobs
{
    /** Segundos que se espera antes de recalcular un producto recién guardado. */
    private const PRODUCT_DELAY = 60;

    /** Encola el cálculo de todos los productos (uno solo a la vez). Sin el módulo de tareas no hace nada. */
    public static function queueAll(App $app): bool
    {
        if (!$app->has('jobs') || !$app->has('semantic')) {
            return false;
        }
        $app->service('jobs')->push('semantic.audit', [], ['unique' => 'semantic.audit.all']);

        return true;
    }

    /** Encola el cálculo de un solo producto (los guardados seguidos del mismo producto son un solo trabajo). */
    public static function queueProduct(App $app, int $id): bool
    {
        if ($id <= 0 || !$app->has('jobs') || !$app->has('semantic')) {
            return false;
        }
        $app->service('jobs')->push('semantic.audit', ['id' => $id], ['unique' => 'semantic.audit.p' . $id, 'delay' => self::PRODUCT_DELAY]);

        return true;
    }

    /**
     * El manejador del trabajo.
     *
     * @param array<string,mixed> $payload
     */
    public static function run(array $payload, App $app): string
    {
        /** @var Semantic $semantic */
        $semantic = $app->service('semantic');
        if (isset($payload['id']) && (int) $payload['id'] > 0) {
            $id = (int) $payload['id'];
            $result = Audit::scoreProduct($semantic, $id);

            return $result === null ? 'Product ' . $id . ' is gone: score removed' : 'Product ' . $id . ' scored ' . $result['score'];
        }
        $after = isset($payload['after']) ? max(0, (int) $payload['after']) : 0;
        $batch = Audit::batch($app, $after);
        if ($batch['more'] && $app->has('jobs')) {
            // La clave única incluye el punto de partida: la tanda en curso sigue «running» y no puede tapar a la siguiente.
            $app->service('jobs')->push('semantic.audit', ['after' => $batch['last']], ['unique' => 'semantic.audit.next.' . $batch['last']]);
        }

        return 'Scored ' . $batch['done'] . ' products' . ($batch['more'] ? ', more to come' : ', finished');
    }

    /** La tarea periódica (una vez al día): lanza el cálculo de todos. */
    public static function dailyTask(App $app): string
    {
        return self::queueAll($app) ? 'Audit of all products queued' : 'No job queue: nothing queued';
    }
}
