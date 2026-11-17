<?php
/**
 * AIFEC — trabajos del módulo de IA: la tanda de un lote (se encola a sí misma) y un vigía que recupera lotes sin trabajo detrás.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Ai\BatchJobs;

return [
    'handlers' => [
        'ai.batch' => [BatchJobs::class, 'run'],
    ],
    'cron' => [
        'ai.batches' => ['every' => 300, 'run' => [BatchJobs::class, 'nudge'], 'description' => 'Restarts AI batches that are running but have no job queued'],
    ],
];
