<?php
/**
 * AIFEC — valoraciones: tareas programadas (las ejecuta el módulo de tareas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Reviews\ReviewTasks;

return ['cron' => [
    'reviews.ask' => ['every' => 3600, 'run' => [ReviewTasks::class, 'ask'], 'description' => 'Asks buyers for a review a few days after their order is completed'],
]];
