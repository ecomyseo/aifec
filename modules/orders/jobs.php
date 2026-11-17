<?php
/**
 * AIFEC — pedidos: tareas programadas (las ejecuta el módulo de tareas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Orders\OrderTasks;

return ['cron' => [
    'orders.expire' => ['every' => 300, 'run' => [OrderTasks::class, 'expire'], 'description' => 'Cancels the orders that were never paid'],
    'orders.carts' => ['every' => 86400, 'run' => [OrderTasks::class, 'cleanCarts'], 'description' => 'Deletes the carts that nobody can open any more'],
]];
