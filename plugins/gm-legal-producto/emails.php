<?php
/**
 * AIFEC — GM legal texts per product: la plantilla del correo con los textos legales del pedido.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'legal_notice' => [
        'subject' => 'Legal information about your order {number} - {shop}',
        'view' => 'gm-legal-producto::emails/legal_notice',
        'system' => false,
        'description' => 'Sent to the customer once, when the order is paid, with the legal texts of what they bought',
        'sample' => [
            'number' => '2026-000123', 'name' => 'Ana Ruiz', 'waiver' => 'yes',
            'blocks' => [['id' => 1, 'title' => 'Guarantee', 'kind' => 'guarantee', 'paragraphs' => ['Two years of legal guarantee.']]],
        ],
    ],
];
