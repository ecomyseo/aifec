<?php
/**
 * AIFEC — Hello AIFEC: ajustes. Las claves de pestaña llevan las iniciales del plugin y las de ajuste, su zona.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Hello AIFEC',
    'cap' => 'settings.edit',
    'tabs' => [
        'hageneral' => ['label' => 'General', 'fields' => [
            'hello_aifec.default_country' => [
                'type' => 'text', 'label' => 'Default country', 'hint' => 'Shown on the products that do not have their own value', 'default' => '', 'rules' => 'max:80',
            ],
        ]],
    ],
];
