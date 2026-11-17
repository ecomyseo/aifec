<?php
/**
 * AIFEC — correo: tareas programadas (las ejecuta el módulo de tareas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Mail\MailTasks;

return ['cron' => [
    'mail.send' => ['every' => 60, 'run' => [MailTasks::class, 'send'], 'description' => 'Sends the queued email'],
    'mail.purge' => ['every' => 'daily@04:10', 'run' => [MailTasks::class, 'purge'], 'description' => 'Empties the body of old messages'],
]];
