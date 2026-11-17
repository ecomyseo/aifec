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
    'erp_webhook_paused' => [
        'subject' => 'Messages to the ERP are paused - {shop}',
        'view' => 'erp::emails/webhook_paused',
        'system' => true,
        'description' => 'Sent to the shop when the messages to an ERP fail five times in a row and are paused',
        'sample' => ['connection' => 'ERP', 'url' => 'https://erp.example.com/hooks/aifec', 'reason' => 'It failed 5 times in a row. Last error: The server answered 500', 'panel_url' => 'https://tienda.test/admin/erp/connections/1'],
    ],
    'erp_export_file' => [
        'subject' => 'Export {profile} - {shop}',
        'view' => 'erp::emails/export_file',
        'system' => false,
        'description' => 'The file of a scheduled export, attached',
        'sample' => ['profile' => 'Stock levels', 'file' => 'stock-levels-20261112-120000.csv'],
    ],
];
