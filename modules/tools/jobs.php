<?php
/**
 * AIFEC — tareas del módulo de herramientas: copia nocturna, limpieza del registro y la importación por tandas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Tools\ToolsTasks;

return [
    'handlers' => [
        'tools.csv_import' => [ToolsTasks::class, 'csvImport'],
        'tools.backup_job' => [ToolsTasks::class, 'backupJob'],
    ],
    'cron' => [
        'tools.backup' => [
            'every' => 'daily@03:10',
            'run' => [ToolsTasks::class, 'backup'],
            'description' => 'Creates the nightly database backup',
        ],
        'tools.logs' => [
            'every' => 'daily@03:25',
            'run' => [ToolsTasks::class, 'purgeLogs'],
            'description' => 'Removes the log files that are too old',
        ],
    ],
];
