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
        'webmcp.view' => 'See the tools and forms offered to AI agents in the browser',
        'webmcp.edit' => 'Change the WebMCP settings',
    ],
    'menu' => [
        ['group' => 'ai', 'items' => [
            ['label' => 'WebMCP', 'url' => '/admin/webmcp', 'cap' => 'webmcp.view', 'order' => 50, 'tabs' => 'webmcp', 'menu_label' => 'WebMCP'],
            ['label' => 'WebMCP settings', 'settings' => 'webmcp', 'cap' => 'webmcp.edit', 'order' => 51, 'tabs' => 'webmcp', 'tab' => 'Settings', 'menu_label' => 'WebMCP'],
        ]],
    ],
];
