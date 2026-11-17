<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Api\Keys;
use Aifec\Modules\Api\KeysAdmin;

return [
    'caps' => [
        'api.view' => 'See the API keys',
        'api.edit' => 'Create, change and delete API keys, and change the API settings',
    ],
    'menu' => [
        ['group' => 'settings', 'items' => [
            ['label' => 'API keys', 'resource' => 'api_keys', 'cap' => 'api.view', 'order' => 110, 'tabs' => 'api-erp', 'menu_label' => 'API and ERP'],
        ]],
    ],
    'resources' => [
        'api_keys' => [
            'table' => 'api_keys', 'title' => 'API keys', 'singular' => 'API key', 'label_field' => 'name', 'cap' => 'api',
            'timestamps' => false,
            'help' => "A key lets a program use the API with a higher usage limit. With the scope «orders:create» it can also place orders for a customer who confirmed them.\nThe key is shown once, when you create it. Only its summary is kept: if it is lost, create another one.\nReading the shop and preparing a cart need no key.",
            'list' => [
                'columns' => [
                    'name' => ['label' => 'Name', 'link' => true, 'sort' => true],
                    'scopes' => ['label' => 'Scopes', 'render' => [KeysAdmin::class, 'scopesCell']],
                    'active' => ['label' => 'Status', 'type' => 'badge', 'map' => ['1' => ['Active', 'ok'], '0' => ['Off', 'warn']]],
                    'last_used_at' => ['label' => 'Last used', 'type' => 'datetime', 'sort' => true, 'hide_sm' => true],
                    'created_at' => ['label' => 'Created', 'type' => 'datetime', 'sort' => true, 'hide_sm' => true],
                ],
                'search' => ['name'],
                'where' => ["(scopes IS NULL OR scopes NOT LIKE '%erp:%')"],
                'sort' => ['id', 'desc'],
                'per_page' => 25,
            ],
            'form' => [
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => 'required|max:120', 'width' => 'md', 'hint' => 'What the key is for.'],
                    'scopes' => ['type' => 'checks', 'label' => 'What it can do', 'options' => Keys::SCOPES, 'hint' => 'Without a scope the key only raises the usage limit.'],
                    'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1],
                    'key_hash' => ['type' => 'hidden'],
                    'created_at' => ['type' => 'hidden'],
                ],
            ],
            'before_save' => [KeysAdmin::class, 'beforeSave'],
            'after_save' => [KeysAdmin::class, 'afterSave'],
            'before_delete' => [KeysAdmin::class, 'beforeDelete'],
        ],
    ],
];
