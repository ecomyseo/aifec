<?php
/**
 * AIFEC — lo que sitios aporta al panel: el permiso y la entrada «Sites and demos» del grupo Sistema.
 * En un sitio que no es el maestro, hooks.php quita esta definición (ver el filtro admin.definitions).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'caps' => [
        'sites.manage' => 'Manage the sites and the demo shops',
    ],
    'menu' => [
        ['group' => 'system', 'items' => [
            ['label' => 'Sites and demos', 'url' => '/admin/sites', 'cap' => 'sites.manage', 'order' => 20],
        ]],
    ],
];
