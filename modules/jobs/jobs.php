<?php
/**
 * AIFEC — tareas del módulo de tareas: la limpieza diaria.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Jobs\JobTasks;

return [
    'handlers' => [],
    'cron' => [
        'jobs.purge' => [
            'every' => 'daily@03:40',
            'run' => [JobTasks::class, 'purge'],
            'description' => 'Removes old finished jobs, expired sessions and old sign-in attempts',
        ],
    ],
];
