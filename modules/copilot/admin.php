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
        'copilot.use' => 'Use the copilot to prepare, apply and undo changes written in plain language',
    ],
    'menu' => [
        ['group' => 'copilot', 'label' => 'Copilot', 'icon' => 'sparkles', 'order' => 1, 'url' => '/admin/copilot', 'cap' => 'copilot.use'],
    ],
];
