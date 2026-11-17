<?php
/**
 * AIFEC — facturas en el panel: permisos y menú. El listado y sus acciones son pantallas propias
 * (src/Admin/InvoicesController.php, rutas en routes.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'caps' => [
        'invoices.view' => 'See and download the invoices, and export them',
        'invoices.edit' => 'Issue invoices by hand and change the invoice settings',
    ],
    'menu' => [
        ['group' => 'sales', 'items' => [
            ['label' => 'Invoices', 'url' => '/admin/invoices', 'cap' => 'invoices.view', 'order' => 30],
        ]],
        ['group' => 'settings', 'items' => [
            ['label' => 'Invoice settings', 'settings' => 'invoices', 'cap' => 'invoices.edit', 'order' => 52, 'tabs' => 'sales', 'tab' => 'Invoices', 'menu_label' => 'Sales'],
        ]],
    ],
];
