<?php
/**
 * AIFEC — consentimiento: tareas programadas (las ejecuta el módulo de tareas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Consent\ConsentTasks;

return ['cron' => [
    'consent.purge' => ['every' => 'daily@04:10', 'run' => [ConsentTasks::class, 'purge'], 'description' => 'Deletes the proof of decisions older than 5 years'],
]];
