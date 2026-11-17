<?php
/**
 * AIFEC — panel del módulo de herramientas: permisos y menú.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

$system = [
    ['label' => 'Diagnostics', 'url' => '/admin/tools/doctor', 'cap' => 'tools.view', 'order' => 50, 'tabs' => 'diagnostics', 'menu_label' => 'Diagnostics'],
    ['label' => 'System information', 'url' => '/admin/tools/info', 'cap' => 'tools.view', 'order' => 51, 'tabs' => 'diagnostics', 'menu_label' => 'Diagnostics'],
    ['label' => 'Logs', 'url' => '/admin/tools/logs', 'cap' => 'tools.view', 'order' => 52, 'tabs' => 'diagnostics', 'tab' => 'Log', 'menu_label' => 'Diagnostics'],
    ['label' => 'Cache and maintenance', 'url' => '/admin/tools/cache', 'cap' => 'tools.view', 'order' => 60],
    ['label' => 'Backups', 'url' => '/admin/tools/backups', 'cap' => 'tools.backup', 'order' => 70],
];

$menu = [['group' => 'system', 'items' => $system]];
// La importación y la exportación de productos solo tienen sentido si hay catálogo.
if (App::hasCurrent() && App::current()->has('catalog')) {
    $menu[] = ['group' => 'catalog', 'items' => [
        ['label' => 'Products CSV', 'url' => '/admin/tools/csv', 'cap' => 'catalog.edit', 'order' => 70],
    ]];
}

return [
    'caps' => [
        'tools.view' => 'See the diagnostics, the logs and the system information',
        'tools.edit' => 'Empty the caches and change the maintenance mode',
        'tools.backup' => 'Create, check, download and delete backups',
        'tools.restore' => 'Restore a backup over the shop',
    ],
    'menu' => $menu,
];
