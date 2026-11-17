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
        'erp.view' => 'See the ERP connections, exports and imports',
        'erp.edit' => 'Create ERP connections and keys, and run exports and imports',
    ],
    'menu' => [
        ['group' => 'settings', 'items' => [
            ['label' => 'ERP connections', 'url' => '/admin/erp', 'cap' => 'erp.view', 'order' => 111, 'tabs' => 'api-erp', 'tab' => 'Connections', 'menu_label' => 'API and ERP'],
            ['label' => 'ERP exports', 'url' => '/admin/erp/exports', 'cap' => 'erp.view', 'order' => 112, 'tabs' => 'api-erp', 'tab' => 'Exports', 'menu_label' => 'API and ERP'],
            ['label' => 'ERP imports', 'url' => '/admin/erp/imports', 'cap' => 'erp.view', 'order' => 113, 'tabs' => 'api-erp', 'tab' => 'Imports', 'menu_label' => 'API and ERP'],
            ['label' => 'ERP runs', 'url' => '/admin/erp/runs', 'cap' => 'erp.view', 'order' => 114, 'tabs' => 'api-erp', 'tab' => 'Runs', 'menu_label' => 'API and ERP'],
            ['label' => 'ERP settings', 'settings' => 'erp', 'cap' => 'erp.edit', 'order' => 115, 'tabs' => 'api-erp', 'tab' => 'Settings', 'menu_label' => 'API and ERP'],
        ]],
    ],
];
