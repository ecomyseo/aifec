<?php
/**
 * AIFEC — GM coming soon: tareas programadas.
 *
 * Solo trabajan con lo que está en las tablas del plugin y con ventanas de 90 días: nunca recorren pedidos ni clientes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Gm\Proximamente\Tasks;

return ['cron' => [
    'gm_proximamente.release' => ['every' => 900, 'run' => [Tasks::class, 'release'], 'description' => 'Makes the coming soon products available when their date arrives'],
    'gm_proximamente.purge' => ['every' => 'daily@04:10', 'run' => [Tasks::class, 'purge'], 'description' => 'Deletes the notice requests older than 90 days'],
]];
