<?php
/**
 * AIFEC — lo que el núcleo aporta al panel: los grupos del menú, el equipo, los papeles y el registro de actividad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\CoreAdmin;
use Aifec\Admin\UsersController;

return [
    'caps' => [
        'dashboard.view' => 'See the dashboard',
        'settings.edit' => 'Change the settings',
        'team.view' => 'See the team and its roles',
        'team.edit' => 'Manage the team and its roles',
        'system.view' => 'See the activity log',
    ],
    'menu' => [
        ['group' => 'home', 'label' => 'Dashboard', 'icon' => 'home', 'order' => 0, 'url' => '/admin'],
        ['group' => 'sales', 'label' => 'Sales', 'icon' => 'cart', 'order' => 10],
        ['group' => 'catalog', 'label' => 'Catalog', 'icon' => 'box', 'order' => 20],
        ['group' => 'customers', 'label' => 'Customers', 'icon' => 'users', 'order' => 30],
        ['group' => 'content', 'label' => 'Content', 'icon' => 'file', 'order' => 40],
        ['group' => 'marketing', 'label' => 'Marketing', 'icon' => 'megaphone', 'order' => 50],
        ['group' => 'ai', 'label' => 'Artificial intelligence', 'icon' => 'sparkles', 'order' => 60],
        ['group' => 'design', 'label' => 'Design', 'icon' => 'palette', 'order' => 70],
        ['group' => 'plugins', 'label' => 'Modules and plugins', 'icon' => 'plug', 'order' => 80],
        ['group' => 'settings', 'label' => 'Settings', 'icon' => 'settings', 'order' => 90, 'items' => [
            ['label' => 'General', 'settings' => 'core', 'cap' => 'settings.edit', 'order' => 10],
            ['label' => 'Team', 'resource' => 'users', 'cap' => 'team.view', 'order' => 100, 'tabs' => 'team', 'tab' => 'People', 'menu_label' => 'Team'],
            ['label' => 'Roles', 'resource' => 'roles', 'cap' => 'team.view', 'order' => 101, 'tabs' => 'team', 'menu_label' => 'Team'],
            ['label' => 'All settings', 'url' => '/admin/settings', 'cap' => 'settings.edit', 'order' => 200],
        ]],
        ['group' => 'system', 'label' => 'System', 'icon' => 'tool', 'order' => 100, 'items' => [
            ['label' => 'Activity log', 'resource' => 'audit', 'cap' => 'system.view', 'order' => 53, 'tabs' => 'diagnostics', 'menu_label' => 'Diagnostics'],
        ]],
    ],
    'resources' => [
        'users' => [
            'table' => 'users', 'title' => 'Team', 'singular' => 'Person', 'label_field' => 'name', 'cap' => 'team',
            'list' => [
                // Con el papel al lado para enseñar su nombre («Administrador») y no su código («admin»).
                'from' => '{users} t LEFT JOIN {roles} r ON r.code = t.role',
                'select' => 't.*, r.name AS role_name',
                'key_sql' => 't.id',
                'columns' => [
                    'name' => ['label' => 'Name', 'link' => true, 'sort' => 't.name'],
                    'email' => ['label' => 'Email', 'sort' => 't.email'],
                    'role_name' => ['label' => 'Role', 'sort' => 'r.name', 'translate' => true],
                    'active' => ['label' => 'Can sign in', 'type' => 'bool'],
                    'last_login_at' => ['label' => 'Last sign-in', 'type' => 'datetime', 'sort' => 't.last_login_at', 'hide_sm' => true],
                ],
                'search' => ['t.name', 't.email'],
                'sort' => ['name', 'asc'],
            ],
            'form' => ['fields' => [
                'name' => ['label' => 'Name', 'rules' => 'required|max:120'],
                'email' => ['type' => 'email', 'label' => 'Email', 'rules' => 'required|unique:users,email'],
                'role' => ['type' => 'select', 'label' => 'Role', 'rules' => 'required', 'options_from' => ['table' => 'roles', 'value' => 'code', 'label' => 'name'], 'translate' => true],
                'locale' => ['type' => 'select', 'label' => 'Panel language', 'options' => ['es' => 'Español', 'en' => 'English'], 'translate' => false, 'default' => 'es'],
                'password' => ['type' => 'password', 'label' => 'Password', 'rules' => 'min:10', 'hint' => 'At least 10 characters. When editing, leave it empty to keep the current one.'],
                'active' => ['type' => 'toggle', 'label' => 'Can sign in', 'default' => 1],
            ]],
            'before_save' => [CoreAdmin::class, 'userBeforeSave'],
            'after_save' => [CoreAdmin::class, 'userAfterSave'],
            'before_delete' => [CoreAdmin::class, 'userBeforeDelete'],
            'side' => [UsersController::class, 'side'],
            'help' => 'Each person signs in with their own email and password. What they can see and do depends on their role.',
        ],
        'roles' => [
            'table' => 'roles', 'title' => 'Roles', 'singular' => 'Role', 'label_field' => 'name', 'cap' => 'team', 'timestamps' => false,
            'list' => [
                'columns' => [
                    'name' => ['label' => 'Name', 'link' => true, 'sort' => true, 'translate' => true],
                    'code' => ['label' => 'Code'],
                    'system' => ['label' => 'Built-in', 'type' => 'bool'],
                ],
                'search' => ['name', 'code'],
                'sort' => ['name', 'asc'],
            ],
            'form' => ['fields' => [
                'name' => ['label' => 'Name', 'rules' => 'required|max:80', 'seeded' => true],
                'code' => ['type' => 'slug', 'label' => 'Code', 'rules' => 'required|max:40|unique:roles,code', 'from' => 'name', 'width' => 'md', 'hint' => 'Short name without spaces. It cannot be changed in the built-in roles.'],
                'caps' => ['type' => 'checks', 'label' => 'Permissions', 'options' => [CoreAdmin::class, 'capabilityOptions'], 'translate' => false, 'free' => true, 'wide' => true],
            ]],
            'before_save' => [CoreAdmin::class, 'roleBeforeSave'],
            'before_delete' => [CoreAdmin::class, 'roleBeforeDelete'],
        ],
        'audit' => [
            'table' => 'audit', 'title' => 'Activity log', 'singular' => 'Entry', 'label_field' => 'action', 'cap' => 'system', 'readonly' => true, 'timestamps' => false,
            'list' => [
                'columns' => [
                    'created_at' => ['label' => 'When', 'type' => 'datetime', 'sort' => true, 'link' => true],
                    'user_name' => ['label' => 'Who'],
                    'action' => ['label' => 'Action'],
                    'entity' => ['label' => 'What', 'hide_sm' => true],
                    'entity_id' => ['label' => 'Id', 'hide_sm' => true],
                    'summary' => ['label' => 'Summary', 'limit' => 70, 'hide_sm' => true],
                ],
                'search' => ['user_name', 'action', 'entity', 'summary'],
                'sort' => ['id', 'desc'],
                'per_page' => 50,
            ],
            'show' => [CoreAdmin::class, 'auditShow'],
        ],
    ],
];
