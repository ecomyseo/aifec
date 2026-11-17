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
 * La tarea periódica «semantic.visits» (cada hora, la registra jobs.php): suma en bot_visits las visitas nuevas de robots y de
 * asistentes que dejó el núcleo en los registros de ayer y de hoy.
 */
final class BotsJobs
{
    /** La tarea periódica. */
    public static function visitsTask(App $app): string
    {
        $done = Bots::ingest($app);

        return 'Visits read: ' . $done['lines'] . ' lines from ' . $done['files'] . ' files, ' . $done['added'] . ' visits added';
    }
}
