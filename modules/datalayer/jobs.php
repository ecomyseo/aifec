<?php
/**
 * AIFEC — capa de datos: el trabajo que manda un evento al servidor de medición y la limpieza diaria.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Datalayer\Tasks;

return [
    'handlers' => [
        'datalayer.server_event' => [Tasks::class, 'serverEvent'],
    ],
    'cron' => [
        'datalayer.purge' => ['every' => 'daily@04:30', 'run' => [Tasks::class, 'purge'], 'description' => 'Closes server events that waited more than 24 hours and deletes the log after 90 days'],
    ],
];
