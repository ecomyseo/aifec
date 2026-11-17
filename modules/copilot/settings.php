<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Copilot',
    'description' => 'The panel assistant that turns plain-language orders into changes you can review and undo',
    'icon' => 'sparkles',
    'cap' => 'settings.edit',
    'help' => "You write what you want («mark order 12 as shipped with tracking 4455») and the copilot prepares it, shows exactly what will change and applies it only when you press the button.\nAfter applying you can undo the change while nothing else has touched it.\nWithout the AI the copilot understands the most common orders by rules and always finds settings, orders, products, customers and coupons.\nThe AI only chooses the action and its data: it never applies anything, and it never sees more than what you write and what it searches for.",
    'tabs' => [
        'cplgeneral' => ['label' => 'General', 'fields' => [
            'copilot.ai' => ['type' => 'toggle', 'label' => 'Use the AI to understand the orders', 'default' => true, 'hint' => 'Only when the AI is turned on in its own settings. Without it, or if it fails, the rules are used.'],
            'copilot.max_rows' => ['type' => 'number', 'label' => 'Most records one change can touch', 'default' => 500, 'rules' => 'int|min:1|max:5000', 'width' => 'sm', 'hint' => 'Above this number the copilot warns and does not apply.'],
            'copilot.history_days' => ['type' => 'number', 'label' => 'Days to keep the history', 'default' => 90, 'rules' => 'int|min:1|max:3650', 'width' => 'sm', 'suffix' => 'days', 'hint' => 'A daily task deletes the older entries of the copilot (never shop data).'],
        ]],
    ],
];
