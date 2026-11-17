<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Seo\SeoAdmin;

/*
 * SEO en el panel: permisos, el menú (la auditoría en «Marketing», los ajustes en «Ajustes») y la pieza del inicio.
 */
return [
    'caps' => [
        'seo.view' => 'See the SEO audit',
        'seo.edit' => 'Change the SEO settings',
    ],
    'menu' => [
        ['group' => 'marketing', 'items' => [
            ['label' => 'SEO', 'url' => '/admin/seo/audit', 'cap' => 'seo.view', 'order' => 20, 'tabs' => 'seo', 'tab' => 'Audit', 'menu_label' => 'SEO'],
            ['label' => 'SEO', 'settings' => 'seo', 'cap' => 'seo.edit', 'order' => 21, 'tabs' => 'seo', 'tab' => 'Settings', 'menu_label' => 'SEO'],
        ]],
    ],
    'dashboard' => [
        ['id' => 'seo.summary', 'cap' => 'seo.view', 'size' => 4, 'order' => 60, 'render' => [SeoAdmin::class, 'widget']],
    ],
];
