<?php
/**
 * AIFEC — tareas de sitios: construir una demostración en segundo plano y restablecerlas todas cada noche.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Sites\SitesTasks;

return [
    'handlers' => [
        'sites.build' => [SitesTasks::class, 'build'],
    ],
    'cron' => [
        'sites.reset_demos' => [
            'every' => 'daily@04:30',
            'run' => [SitesTasks::class, 'resetDemos'],
            'description' => 'Deletes every demo shop and builds it again from its package (it can be turned off in Sites and demos)',
        ],
    ],
];
