<?php
/**
 * AIFEC — panel del módulo de migración: el permiso y la entrada del menú.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'caps' => [
        'import.run' => 'Migrate a shop from WooCommerce or PrestaShop',
    ],
    'menu' => [
        ['group' => 'system', 'items' => [
            ['label' => 'Migration', 'url' => '/admin/import', 'cap' => 'import.run', 'order' => 30],
        ]],
    ],
];
