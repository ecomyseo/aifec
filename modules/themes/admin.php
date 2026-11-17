<?php
/**
 * AIFEC — panel del módulo de temas: permisos y entradas del menú del grupo «Diseño».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'caps' => [
        'appearance.view' => 'See the themes and the look of the shop',
        'appearance.edit' => 'Choose, customise and validate themes',
        'appearance.create' => 'Create new themes (writes files on the server)',
    ],
    'menu' => [
        ['group' => 'design', 'items' => [
            ['label' => 'Themes', 'url' => '/admin/themes', 'cap' => 'appearance.view', 'order' => 10],
            ['label' => 'Home page', 'url' => '/admin/themes/home', 'cap' => 'appearance.view', 'order' => 20],
            ['label' => 'Logo and icon', 'url' => '/admin/themes/brand', 'cap' => 'appearance.view', 'order' => 30],
            // Se llega con el botón «Tema nuevo» de la lista de temas; el buscador del panel también la encuentra.
            ['label' => 'New theme', 'url' => '/admin/themes/new', 'cap' => 'appearance.create', 'order' => 40, 'hidden' => true],
        ]],
    ],
];
