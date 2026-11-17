<?php
/**
 * AIFEC — devoluciones en el panel: permisos y menú. El listado y la ficha son pantallas propias
 * (src/Admin/ReturnsController.php, rutas en routes.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'caps' => [
        'returns.view' => 'See the returns and their photos',
        'returns.manage' => 'Accept, reject, receive and refund returns, and change the return settings',
    ],
    'menu' => [
        ['group' => 'sales', 'items' => [
            ['label' => 'Returns', 'url' => '/admin/returns', 'cap' => 'returns.view', 'order' => 40],
        ]],
        ['group' => 'settings', 'items' => [
            ['label' => 'Return settings', 'settings' => 'returns', 'cap' => 'returns.manage', 'order' => 53, 'tabs' => 'sales', 'tab' => 'Returns', 'menu_label' => 'Sales'],
        ]],
    ],
];
