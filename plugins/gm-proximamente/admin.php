<?php
/**
 * AIFEC — GM coming soon en el panel: permisos, menú y el listado de peticiones de aviso (solo lectura).
 *
 * El estado «próximamente» de cada producto se cambia en la pestaña «Coming soon» de su ficha (ver hooks.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Gm\Proximamente\RequestsAdmin;

return [
    'caps' => [
        'gmsoon.view' => 'See who asked to be told about coming soon products',
        'gmsoon.edit' => 'Change the coming soon settings',
    ],
    'menu' => [
        ['group' => 'marketing', 'items' => [
            ['label' => 'Coming soon requests', 'resource' => 'gm_soon_requests', 'cap' => 'gmsoon.view', 'order' => 60],
        ]],
        ['group' => 'settings', 'items' => [
            ['label' => 'Coming soon', 'settings' => 'gm-proximamente', 'cap' => 'gmsoon.edit', 'order' => 60],
        ]],
    ],
    'resources' => [
        'gm_soon_requests' => [
            'table' => 'gm_soon_requests', 'title' => 'Coming soon requests', 'singular' => 'Request', 'label_field' => 'email', 'cap' => 'gmsoon',
            'readonly' => true, 'can_create' => false, 'can_delete' => false, 'timestamps' => false,
            'list' => static function (App $app): array {
                return RequestsAdmin::listConfig($app);
            },
            'show' => [RequestsAdmin::class, 'show'],
            'help' => "People who asked to be told when a coming soon product is available.\nEach request sends one email, when the product becomes available, and is deleted after 90 days.",
        ],
    ],
];
