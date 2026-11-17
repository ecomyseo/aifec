<?php
/**
 * AIFEC — valoraciones en el panel: permisos y menú. La bandeja y la ficha de una opinión son pantallas propias
 * (src/Admin/ReviewsController.php, rutas en routes.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'caps' => [
        'reviews.view' => 'See the product reviews',
        'reviews.edit' => 'Publish, reject and answer product reviews, and change their settings',
    ],
    'menu' => [
        ['group' => 'catalog', 'items' => [
            ['label' => 'Reviews', 'url' => '/admin/reviews', 'cap' => 'reviews.view', 'order' => 60, 'tabs' => 'reviews', 'menu_label' => 'Reviews'],
            ['label' => 'Review settings', 'settings' => 'reviews', 'cap' => 'reviews.edit', 'order' => 61, 'tabs' => 'reviews', 'tab' => 'Settings', 'menu_label' => 'Reviews'],
        ]],
    ],
];
