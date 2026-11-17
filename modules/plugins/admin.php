<?php
/**
 * AIFEC — panel del módulo de plugins: permisos y menú del grupo «Módulos y plugins».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'caps' => [
        'plugins.view' => 'See the modules and plugins',
        'plugins.edit' => 'Turn modules and plugins on and off, install, validate and uninstall them',
    ],
    'menu' => [
        ['group' => 'system', 'items' => [
            ['label' => 'Modules and plugins', 'url' => '/admin/plugins', 'cap' => 'plugins.view', 'order' => 10],
            // Se llega con el botón «Nuevo plugin» de la lista; el buscador del panel también la encuentra.
            ['label' => 'New plugin', 'url' => '/admin/plugins/new', 'cap' => 'plugins.edit', 'order' => 11, 'hidden' => true],
        ]],
    ],
];
