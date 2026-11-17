<?php
/**
 * AIFEC — capa de datos en el panel: permisos y menú. La pantalla de los eventos del servidor está en src/DatalayerAdmin.php.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'caps' => [
        'datalayer.view' => 'See the events the server sent to the measurement services',
        'datalayer.edit' => 'Change the measurement settings',
    ],
    'menu' => [
        ['group' => 'marketing', 'items' => [
            ['label' => 'Measurement', 'settings' => 'datalayer', 'cap' => 'datalayer.edit', 'order' => 40, 'tabs' => 'measurement', 'tab' => 'Settings', 'menu_label' => 'Measurement'],
            ['label' => 'Server events', 'url' => '/admin/datalayer/events', 'cap' => 'datalayer.view', 'order' => 41, 'tabs' => 'measurement', 'menu_label' => 'Measurement'],
        ]],
    ],
];
