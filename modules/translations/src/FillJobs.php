<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Translations;

use Aifec\Kernel\App;

/**
 * Los trabajos de la cola: «translations.fill» corre una tanda y se vuelve a encolar mientras el trabajo siga corriendo;
 * «translations.fill» del cron (cada cinco minutos) vuelve a encolar los que corren sin ningún trabajo detrás. Solo mira
 * translation_jobs en estado «running»: nunca recorre ningún histórico ni crea trabajos nuevos.
 */
final class FillJobs
{
    /**
     * Manejador de «translations.fill».
     *
     * @param array<string,mixed> $payload id del trabajo
     */
    public static function run(array $payload, App $app): string
    {
        $id = isset($payload['id']) ? (int) $payload['id'] : 0;
        $fill = new Fill($app);
        $job = $fill->runChunk($id);
        if ($job === null) {
            return 'Unknown translation job ' . $id;
        }
        if ((string) $job['status'] === Fill::RUNNING) {
            $fill->queue($id);
        }

        return 'Translation job ' . $id . ': ' . (string) $job['status'] . ', ' . (int) $job['done'] . ' done, ' . (int) $job['failed'] . ' failed of ' . (int) $job['total'];
    }

    /**
     * Tarea periódica: vuelve a encolar los trabajos que corren sin nada en la cola (un trabajo perdido).
     */
    public static function nudge(App $app): ?string
    {
        $db = $app->db();
        $fill = new Fill($app);
        $restarted = 0;
        foreach ($db->column("SELECT id FROM {translation_jobs} WHERE status = 'running' ORDER BY id") as $id) {
            $id = (int) $id;
            $waiting = (int) $db->value(
                "SELECT COUNT(*) FROM {jobs} WHERE type = ? AND status IN ('pending', 'running') AND unique_key LIKE ?",
                [Fill::JOB, Fill::JOB . ':' . $id . ':%']
            );
            if ($waiting === 0) {
                $fill->queue($id);
                ++$restarted;
            }
        }

        return $restarted > 0 ? 'Queued ' . $restarted . ' translation jobs again' : null;
    }
}
