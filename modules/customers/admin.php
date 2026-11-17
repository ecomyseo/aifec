<?php
/**
 * AIFEC — clientes en el panel: permisos, menú y los recursos «customers» y «customer_groups».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Customers\CustomersAdmin;

return [
    'caps' => [
        'customers.view' => 'See the customers',
        'customers.edit' => 'Create and change customers and groups',
        'customers.export' => 'Export the list of customers',
        'customers.privacy' => 'Download the data of a customer and anonymise it',
    ],
    'menu' => [
        ['group' => 'customers', 'items' => [
            ['label' => 'Customers', 'resource' => 'customers', 'cap' => 'customers.view', 'order' => 10],
            ['label' => 'Groups', 'resource' => 'customer_groups', 'cap' => 'customers.view', 'order' => 20],
        ]],
    ],
    'resources' => [
        'customers' => [
            'table' => 'customers', 'title' => 'Customers', 'singular' => 'Customer', 'label_field' => 'email', 'cap' => 'customers',
            'tags' => ['customers'],
            'list' => CustomersAdmin::listConfig(false),
            'form' => [
                'tabs' => ['cudata' => 'Customer', 'cuaccess' => 'Access'],
                'fields' => [
                    'email' => ['type' => 'email', 'label' => 'Email', 'rules' => 'required|max:191|unique:customers,email', 'tab' => 'cudata'],
                    'first_name' => ['label' => 'First name', 'rules' => 'nullable|max:80', 'tab' => 'cudata', 'width' => 'md'],
                    'last_name' => ['label' => 'Last name', 'rules' => 'nullable|max:120', 'tab' => 'cudata', 'width' => 'md'],
                    'company' => ['label' => 'Company', 'rules' => 'nullable|max:160', 'tab' => 'cudata'],
                    'vat_id' => ['label' => 'Tax ID', 'rules' => 'nullable|max:40', 'tab' => 'cudata', 'width' => 'md'],
                    'phone' => ['type' => 'tel', 'label' => 'Phone', 'rules' => 'nullable|max:40', 'tab' => 'cudata', 'width' => 'md'],
                    'group_id' => ['type' => 'select', 'label' => 'Group', 'options_from' => ['table' => 'customer_groups', 'value' => 'id', 'label' => 'name'], 'translate' => true,
                        'empty' => 'Default group', 'null_empty' => true, 'tab' => 'cudata'],
                    'locale' => ['label' => 'Language', 'rules' => 'nullable|max:8', 'hint' => 'Language code, for example es.', 'tab' => 'cudata', 'width' => 'sm'],
                    'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1, 'tab' => 'cudata'],
                    'marketing' => ['type' => 'toggle', 'label' => 'Accepts marketing emails', 'default' => 0, 'tab' => 'cudata'],
                    'notes' => ['type' => 'textarea', 'label' => 'Internal notes', 'rules' => 'nullable', 'tab' => 'cudata'],
                    'password' => ['type' => 'password', 'label' => 'New password', 'rules' => 'nullable|max:4096', 'hint' => 'Leave it empty to keep the current one.', 'tab' => 'cuaccess'],
                    'guest' => ['type' => 'select', 'label' => 'Guest (bought without an account)', 'options' => ['0' => 'No', '1' => 'Yes'], 'store' => false,
                        'attrs' => ['disabled' => true], 'tab' => 'cuaccess', 'width' => 'sm'],
                    'verified' => ['type' => 'toggle', 'label' => 'Email verified', 'column' => 'email_verified_at', 'store' => false, 'default' => 0, 'tab' => 'cuaccess'],
                ],
            ],
            'before_save' => [CustomersAdmin::class, 'beforeSave'],
            'after_save' => [CustomersAdmin::class, 'afterSave'],
            'before_delete' => [CustomersAdmin::class, 'beforeDelete'],
            'after_delete' => [CustomersAdmin::class, 'afterDelete'],
            'show' => [CustomersAdmin::class, 'show'],
            'side' => [CustomersAdmin::class, 'side'],
            'actions' => [CustomersAdmin::class, 'actions'],
            'help' => "Customers who bought without creating an account appear as guests.\nA customer with orders cannot be deleted: use Anonymise to erase the personal data and keep the orders.\nExporting the list and downloading the data of one customer have their own permissions.",
        ],
        'customer_groups' => [
            'table' => 'customer_groups', 'title' => 'Customer groups', 'singular' => 'Group', 'label_field' => 'name', 'cap' => 'customers',
            'timestamps' => false, 'tags' => ['customers'],
            'list' => CustomersAdmin::groupListConfig(),
            'form' => [
                'fields' => [
                    'name' => ['label' => 'Name', 'rules' => 'required|max:80'],
                    'discount' => ['type' => 'percent', 'label' => 'Discount', 'scale' => 100, 'default' => 0, 'rules' => 'required|between:0,100'],
                    'tax_exempt' => ['type' => 'toggle', 'label' => 'Exempt from taxes', 'default' => 0, 'hint' => 'For business customers in another EU country.'],
                    'is_default' => ['type' => 'toggle', 'label' => 'Default group', 'default' => 0, 'hint' => 'New customers join this group. Only one group can be the default.'],
                ],
            ],
            'before_save' => [CustomersAdmin::class, 'groupBeforeSave'],
            'after_save' => [CustomersAdmin::class, 'groupAfterSave'],
            'before_delete' => [CustomersAdmin::class, 'groupBeforeDelete'],
            'help' => 'A group gathers customers that share a discount or a tax exemption.',
        ],
    ],
];
