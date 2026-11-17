<?php
/**
 * AIFEC — plantillas de correo de las valoraciones (cada módulo declara las suyas en su emails.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'review_request' => [
        'subject' => 'How was your order? Tell us what you think - {shop}',
        'view' => 'reviews::emails/review_request',
        'system' => false,
        'description' => 'Sent to the customer a few days after the order is completed, with a link to review each product',
        'sample' => [
            'name' => 'Ana', 'number' => '2026-000123',
            'products' => [['name' => 'Producto de ejemplo', 'url' => 'https://tienda.test/valorar/abc']],
        ],
    ],
];
