<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai;

use Aifec\Kernel\App;

/**
 * Los trabajos de los lotes: «ai.batch» corre una tanda y se encola a sí mismo mientras el lote siga corriendo; «ai.batches» (cada
 * cinco minutos) vuelve a encolar los lotes que corren sin ningún trabajo detrás (un trabajo perdido), para que ninguno se quede
 * a medias. Solo mira ai_batches en estado «running»: nunca recorre ningún histórico.
 */
final class BatchJobs
{
    /**
     * Manejador de «ai.batch».
     *
     * @param array<string,mixed> $payload id del lote
     */
    public static function run(array $payload, App $app): string
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $batches = new Batches($app);
        $batch = $batches->runChunk($id);
        if ($batch === null) {
            return 'Unknown batch ' . $id;
        }
        if ((string) $batch['status'] === Batches::RUNNING) {
            $batches->queue($id);
        }

        return 'Batch ' . $id . ': ' . (string) $batch['status'] . ', ' . (int) $batch['done'] . ' done, ' . (int) $batch['failed'] . ' failed of ' . (int) $batch['total'];
    }

    /**
     * Tarea periódica «ai.batches».
     */
    public static function nudge(App $app): ?string
    {
        $db = $app->db();
        $batches = new Batches($app);
        $restarted = 0;
        foreach ($db->column("SELECT id FROM {ai_batches} WHERE status = 'running' ORDER BY id") as $id) {
            $id = (int) $id;
            $waiting = (int) $db->value("SELECT COUNT(*) FROM {jobs} WHERE type = 'ai.batch' AND status IN ('pending', 'running') AND unique_key LIKE ?", ['ai.batch:' . $id . ':%']);
            if ($waiting === 0) {
                $batches->queue($id);
                ++$restarted;
            }
        }

        return $restarted > 0 ? 'Queued ' . $restarted . ' batches again' : null;
    }
}
