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
        'search_learning.view' => 'Read search learning rounds and reports',
        'search_learning.manage' => 'Authorise and manage search learning rounds',
    ],
    'menu' => [['group' => 'marketing', 'items' => [
        ['label' => 'Test and learn', 'url' => '/admin/search/learning', 'cap' => 'search_learning.view', 'order' => 33,
            'tabs' => 'search', 'tab' => 'Test and learn', 'menu_label' => 'Search engine'],
    ]]],
];
