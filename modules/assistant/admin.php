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
    'caps' => [
        'assistant.view' => 'See the assistant conversations and the questions it could not answer',
        'assistant.edit' => 'Change the assistant settings',
    ],
    'menu' => [
        ['group' => 'ai', 'items' => [
            ['label' => 'Assistant', 'url' => '/admin/assistant', 'cap' => 'assistant.view', 'order' => 20, 'tabs' => 'assistant', 'tab' => 'Conversations', 'menu_label' => 'Assistant'],
            ['label' => 'Unanswered questions', 'url' => '/admin/assistant/unanswered', 'cap' => 'assistant.view', 'order' => 21, 'tabs' => 'assistant', 'menu_label' => 'Assistant'],
            ['label' => 'Assistant settings', 'settings' => 'assistant', 'cap' => 'assistant.edit', 'order' => 22, 'tabs' => 'assistant', 'tab' => 'Settings', 'menu_label' => 'Assistant'],
        ]],
    ],
];
