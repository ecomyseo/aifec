<?php
/**
 * AIFEC — tareas del módulo de migración: un paso de la migración y el barrido de las que se quedaron colgadas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\ImportTasks;

return [
    'handlers' => [
        'import.step' => [ImportTasks::class, 'step'],
    ],
    'cron' => [
        'import.sweep' => [
            'every' => 600,
            'run' => [ImportTasks::class, 'sweep'],
            'description' => 'Closes migrations that stopped giving signs of life and abandoned drafts',
        ],
    ],
];
