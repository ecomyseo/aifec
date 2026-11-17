<?php
/**
 * AIFEC — consentimiento en el panel: permisos, menú, el inventario de cookies (un recurso) y la pieza del inicio. El informe y la
 * exportación son pantallas propias (src/ConsentAdmin.php, rutas en routes.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Consent\ConsentAdmin;

return [
    'caps' => [
        'consent.view' => 'See the cookie consent report and the list of cookies',
        'consent.edit' => 'Change the list of cookies and the consent settings',
    ],
    'menu' => [
        ['group' => 'marketing', 'items' => [
            ['label' => 'Cookie consent', 'url' => '/admin/consent', 'cap' => 'consent.view', 'order' => 50, 'tabs' => 'cookies', 'tab' => 'Consent', 'menu_label' => 'Cookies'],
            ['label' => 'Cookies', 'resource' => 'consent_cookies', 'cap' => 'consent.view', 'order' => 51, 'tabs' => 'cookies', 'menu_label' => 'Cookies'],
            ['label' => 'Consent settings', 'settings' => 'consent', 'cap' => 'consent.edit', 'order' => 52, 'tabs' => 'cookies', 'tab' => 'Settings', 'menu_label' => 'Cookies'],
        ]],
    ],
    'resources' => [
        'consent_cookies' => [
            'table' => 'consent_cookies', 'title' => 'Cookies', 'singular' => 'Cookie', 'label_field' => 'name', 'cap' => 'consent', 'timestamps' => false,
            'tags' => ['pages'],
            'list' => [
                'columns' => [
                    'name' => ['label' => 'Name', 'link' => true, 'sort' => true],
                    'provider' => ['label' => 'Set by', 'sort' => true, 'translate' => true],
                    'purpose' => ['label' => 'Purpose', 'hide_sm' => true, 'translate' => true],
                    'duration' => ['label' => 'Duration', 'hide_sm' => true, 'translate' => true],
                    'category' => ['label' => 'Category', 'type' => 'badge', 'map' => [
                        'necessary' => ['Necessary', 'ok'], 'preferences' => ['Preferences', 'info'], 'statistics' => ['Statistics', 'warn'], 'marketing' => ['Marketing', 'bad'],
                    ]],
                ],
                'search' => ['name', 'provider', 'purpose'],
                'filters' => ['category' => ['label' => 'Category', 'options' => ['necessary' => 'Necessary', 'preferences' => 'Preferences', 'statistics' => 'Statistics', 'marketing' => 'Marketing']]],
                'sort' => ['position', 'asc'], 'per_page' => 50,
            ],
            'form' => [
                'tabs' => ['cnmain' => 'Cookie'],
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => 'required|max:120', 'hint' => 'A name that ends with * is completed with the suffix of this site.'],
                    'provider' => ['label' => 'Set by', 'rules' => 'required|max:120'],
                    'purpose' => ['label' => 'Purpose', 'rules' => 'required|max:255'],
                    'duration' => ['label' => 'Duration', 'rules' => 'required|max:60', 'width' => 'sm'],
                    'category' => ['type' => 'select', 'label' => 'Category', 'options' => ['necessary' => 'Necessary', 'preferences' => 'Preferences', 'statistics' => 'Statistics', 'marketing' => 'Marketing'], 'default' => 'statistics'],
                    'position' => ['type' => 'number', 'label' => 'Order', 'default' => 100, 'rules' => 'int|min:0|max:9999', 'width' => 'sm'],
                ],
            ],
            'before_delete' => [ConsentAdmin::class, 'beforeDelete'],
            'help' => "The list of cookies is shown in the second layer of the notice and in the cookie table of your policy page.\nThe cookies AIFEC sets by itself are already declared. The services you turn on in other modules add theirs.",
        ],
    ],
    'dashboard' => [
        ['id' => 'consent.last', 'cap' => 'consent.view', 'size' => 4, 'order' => 60, 'render' => [ConsentAdmin::class, 'widget']],
    ],
];
