<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Jobs;

use Aifec\Kernel\App;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Session;

/**
 * Tareas periódicas del propio módulo.
 */
final class JobTasks
{
    /** Días que se guardan los intentos de acceso. */
    private const LOGIN_ATTEMPT_DAYS = 30;

    /**
     * «jobs.purge»: borra los trabajos terminados y fallidos antiguos, las sesiones caducadas, los intentos de acceso
     * de hace más de 30 días y los contadores de límite caducados. Solo toca lo caducado: nunca lo reciente.
     */
    public static function purge(App $app): ?string
    {
        $db = $app->db();
        $clock = $app->clock();
        $now = $clock->now();
        $doneCut = $clock->sql($now - max(1, $app->settings()->int('jobs.keep_done_days', 7)) * 86400);
        $failedCut = $clock->sql($now - max(1, $app->settings()->int('jobs.keep_failed_days', 30)) * 86400);

        $done = $db->run(
            "DELETE FROM {jobs} WHERE status = 'done' AND (finished_at < ? OR (finished_at IS NULL AND created_at < ?))",
            [$doneCut, $doneCut]
        );
        $failed = $db->run(
            "DELETE FROM {jobs} WHERE status = 'failed' AND (finished_at < ? OR (finished_at IS NULL AND created_at < ?))",
            [$failedCut, $failedCut]
        );
        $sessions = Session::purge($db, $clock);
        $attempts = $db->run('DELETE FROM {login_attempts} WHERE created_at < ?', [$clock->sql($now - self::LOGIN_ATTEMPT_DAYS * 86400)]);
        $limits = (new RateLimiter($db, $clock))->purge();

        return 'Removed ' . $done . ' done and ' . $failed . ' failed jobs, ' . $sessions . ' sessions, '
            . $attempts . ' sign-in attempts and ' . $limits . ' rate limits';
    }
}
