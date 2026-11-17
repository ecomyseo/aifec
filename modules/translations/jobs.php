<?php
/**
 * AIFEC — trabajos de las traducciones: la tanda de un trabajo de la IA (se encola a sí misma) y un vigía que recupera los trabajos
 * que corren sin nada en la cola. Solo mira trabajos «running» que lanzó una persona: nunca recorre ningún histórico.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Translations\FillJobs;

return [
    'handlers' => [
        'translations.fill' => [FillJobs::class, 'run'],
    ],
    'cron' => [
        'translations.jobs' => ['every' => 300, 'run' => [FillJobs::class, 'nudge'], 'description' => 'Restarts AI translations that are running but have no job queued'],
    ],
];
