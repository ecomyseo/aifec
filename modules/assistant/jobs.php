<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Assistant\AssistantTasks;

return ['cron' => [
    'assistant.purge' => [
        'every' => 'daily@04:40',
        'run' => [AssistantTasks::class, 'purge'],
        'description' => 'Removes the assistant conversations older than the days set in the settings',
    ],
]];
