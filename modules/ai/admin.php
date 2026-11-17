<?php
/**
 * AIFEC — panel del módulo de IA: permisos, menú del grupo «ai» y la pieza del inicio (gasto de hoy y propuestas pendientes).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Ai\AiAdmin;

return [
    'caps' => [
        'ai.view' => 'See the AI spending and usage',
        'ai.use' => 'Ask the AI for proposals, approve or reject them and run batches',
        'ai.edit' => 'Change the AI settings',
    ],
    'menu' => [
        ['group' => 'ai', 'items' => [
            ['label' => 'AI proposals', 'url' => '/admin/ai/proposals', 'cap' => 'ai.use', 'order' => 10, 'tabs' => 'ai', 'tab' => 'Proposals', 'menu_label' => 'AI'],
            ['label' => 'AI batches', 'url' => '/admin/ai/batches', 'cap' => 'ai.use', 'order' => 11, 'tabs' => 'ai', 'tab' => 'Work batches', 'menu_label' => 'AI'],
            ['label' => 'AI usage', 'url' => '/admin/ai/usage', 'cap' => 'ai.view', 'order' => 12, 'tabs' => 'ai', 'tab' => 'Usage', 'menu_label' => 'AI'],
            ['label' => 'AI settings', 'settings' => 'ai', 'cap' => 'ai.edit', 'order' => 13, 'tabs' => 'ai', 'tab' => 'Settings', 'menu_label' => 'AI'],
            ['label' => 'Test the AI connection', 'url' => '/admin/ai/connection', 'cap' => 'ai.edit', 'order' => 14, 'tabs' => 'ai', 'tab' => 'Connection test', 'menu_label' => 'AI'],
        ]],
    ],
    'dashboard' => [
        ['id' => 'ai.spend', 'cap' => 'ai.view', 'size' => 4, 'order' => 60, 'render' => [AiAdmin::class, 'widget']],
    ],
];
