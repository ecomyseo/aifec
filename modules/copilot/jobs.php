<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Copilot\CopilotTasks;

return ['cron' => [
    'copilot.purge' => [
        'every' => 'daily@04:50',
        'run' => [CopilotTasks::class, 'purge'],
        'description' => 'Removes the copilot history older than the days set in the settings',
    ],
]];
