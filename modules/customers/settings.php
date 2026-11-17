<?php
/**
 * AIFEC — ajustes de clientes: registro, enlace de entrada y clave de las cuentas migradas. Todos son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Customers',
    'description' => 'Accounts, registration and sign-in of customers',
    'icon' => 'users',
    'cap' => 'customers.edit',
    'help' => "Registration: when it is off, nobody can create an account; guests can still buy.\nEmail link: the customer receives a link that signs them in once and expires after 15 minutes.\nMigrated accounts: customers that come from an old PrestaShop sign in with their old password if you copy here the cookie key of that shop.",
    'tabs' => [
        'cuaccounts' => ['label' => 'Accounts', 'fields' => [
            'customers.allow_register' => ['type' => 'toggle', 'label' => 'Allow customers to create an account', 'default' => true],
            'customers.magic_link' => ['type' => 'toggle', 'label' => 'Allow signing in with a link sent by email', 'default' => true],
        ]],
        'cumigrated' => ['label' => 'Migrated accounts', 'fields' => [
            'customers.legacy_cookie_key' => ['type' => 'secret', 'label' => 'Cookie key of the old PrestaShop',
                'hint' => 'Only needed for customers imported from an old PrestaShop that used the old password format.'],
        ]],
    ],
];
