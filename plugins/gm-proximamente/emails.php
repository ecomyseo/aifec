<?php
/**
 * AIFEC — GM coming soon: la plantilla de correo «ya está disponible» (una por petición de aviso).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'soon_available' => [
        'subject' => '{product_name} is now available - {shop}',
        'view' => 'gm-proximamente::emails/soon_available',
        'system' => false,
        'description' => 'Sent once to the people who asked to be told when a coming soon product is available',
        'sample' => ['name' => 'ana@cliente.test', 'product_name' => 'Silla de roble', 'product_url' => '/producto/silla-de-roble'],
    ],
];
