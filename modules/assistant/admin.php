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
        'assistant.reply' => 'Reply to assistant requests',
        'assistant.edit' => 'Change the assistant settings',
    ],
    'resources' => [
        'assistant_guides' => [
            'table' => 'assistant_guides', 'title' => 'Shopping guides', 'singular' => 'Shopping guide', 'label_field' => 'title', 'cap' => 'assistant',
            'timestamps' => false,
            'list' => ['columns' => ['title' => ['label' => 'Title', 'link' => true], 'lang' => ['label' => 'Language'], 'active' => ['label' => 'Active', 'type' => 'bool']], 'search' => ['title', 'phrases']],
            'form' => static function (\Aifec\Kernel\App $app, ?int $id): array { return \Aifec\Modules\Assistant\MerchantGuide::form($app); },
            'before_save' => [\Aifec\Modules\Assistant\MerchantGuide::class, 'validate'],
        ],
    ],
    'menu' => [
        ['group' => 'ai', 'items' => [
            ['label' => 'Assistant', 'url' => '/admin/assistant', 'cap' => 'assistant.view', 'order' => 20, 'tabs' => 'assistant', 'tab' => 'Conversations', 'menu_label' => 'Assistant'],
            ['label' => 'Unanswered questions', 'url' => '/admin/assistant/unanswered', 'cap' => 'assistant.view', 'order' => 21, 'tabs' => 'assistant', 'menu_label' => 'Assistant'],
            ['label' => 'Shopping guides', 'resource' => 'assistant_guides', 'cap' => 'assistant.edit', 'order' => 23, 'tabs' => 'assistant', 'menu_label' => 'Assistant'],
            ['label' => 'Assistant settings', 'settings' => 'assistant', 'cap' => 'assistant.edit', 'order' => 22, 'tabs' => 'assistant', 'tab' => 'Settings', 'menu_label' => 'Assistant'],
        ]],
    ],
];
