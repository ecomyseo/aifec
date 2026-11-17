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
    'title' => 'ERP',
    'description' => 'API limits, outgoing messages and kept files',
    'icon' => 'refresh',
    'cap' => 'erp.edit',
    'help' => "While «Allow outgoing messages» is off, nothing leaves the shop: messages to the ERP wait, and exports to FTP, to an address or by email do not run.\nThe API for the ERP answers always: it is the ERP that calls the shop.",
    'tabs' => [
        'erpgeneral' => ['label' => 'General', 'fields' => [
            'erp.outbound' => ['type' => 'toggle', 'label' => 'Allow outgoing messages', 'default' => false, 'hint' => 'Messages to the ERP, exports that leave the shop and imports from an address or FTP.'],
            'erp.rate_per_minute' => ['type' => 'number', 'label' => 'API requests per minute and key', 'default' => 300, 'rules' => 'required|int|min:10|max:6000', 'width' => 'sm'],
            'erp.idempotency_hours' => ['type' => 'number', 'label' => 'Hours an Idempotency-Key is remembered', 'default' => 24, 'rules' => 'required|int|min:1|max:168', 'width' => 'sm'],
        ]],
        'erpfiles' => ['label' => 'Files', 'fields' => [
            'erp.keep_days' => ['type' => 'number', 'label' => 'Days a run file can be downloaded', 'default' => 7, 'rules' => 'required|int|min:1|max:90', 'width' => 'sm'],
            'erp.folder_days' => ['type' => 'number', 'label' => 'Days a file stays in the private output folder', 'default' => 14, 'rules' => 'required|int|min:1|max:365', 'width' => 'sm'],
            'erp.export_max_rows' => ['type' => 'number', 'label' => 'Rows an export can have at most', 'default' => 50000, 'rules' => 'required|int|min:100|max:500000', 'width' => 'sm', 'hint' => 'If there are more, the next run continues where this one stopped.'],
        ]],
    ],
];
