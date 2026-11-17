<?php
/**
 * AIFEC — GM licences: la plantilla de correo con las claves de licencia.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'license_issued' => [
        'subject' => 'Your licences for order {number} - {shop}',
        'view' => 'gm-licencias::emails/license_issued',
        'system' => false,
        'description' => 'Sent to the customer once, when the order is paid, with the licence keys',
        'sample' => [
            'number' => '2026-000123', 'name' => 'Ana Ruiz', 'account_url' => '/mi-cuenta/licencias',
            'licenses' => [['product' => 'Módulo de ejemplo', 'key' => 'AAAAA-BBBBB-CCCCC-DDDDD-EEEEE', 'kind' => 'domain', 'pending' => false, 'domains' => 1, 'units' => 1, 'updates_until' => '2027-11-12']],
        ],
    ],
];
