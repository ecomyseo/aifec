<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Subscriptions\PlansAdmin;

return [
    'caps' => ['subscriptions.view' => 'See monthly subscriptions and payments', 'subscriptions.edit' => 'Manage monthly plans and subscriptions'],
    'menu' => [['group' => 'sales', 'items' => [
        ['label' => 'Subscriptions', 'url' => '/admin/subscriptions', 'cap' => 'subscriptions.view', 'order' => 55, 'tabs' => 'subscriptions', 'tab' => 'Subscriptions', 'menu_label' => 'Subscriptions'],
        ['label' => 'Monthly plans', 'resource' => 'subscription_plans', 'cap' => 'subscriptions.view', 'order' => 56, 'tabs' => 'subscriptions', 'tab' => 'Monthly plans', 'menu_label' => 'Subscriptions'],
        ['label' => 'Subscription settings', 'settings' => 'subscriptions', 'cap' => 'subscriptions.edit', 'order' => 57, 'tabs' => 'subscriptions', 'tab' => 'Settings', 'menu_label' => 'Subscriptions'],
    ]]],
    'resources' => ['subscription_plans' => [
        'table' => 'subscription_plans', 'title' => 'Monthly plans', 'singular' => 'Monthly plan', 'label_field' => 'name', 'cap' => 'subscriptions',
        'tags' => ['subscriptions'],
        'list' => ['search' => ['name'], 'sort' => ['id', 'desc'], 'export' => false, 'columns' => [
            'name' => ['label' => 'Name', 'link' => true], 'amount' => ['label' => 'Monthly price', 'type' => 'money'], 'active' => ['label' => 'Active', 'type' => 'bool'],
        ]],
        'form' => static function (App $app, ?int $id): array { return PlansAdmin::form($app); },
        'before_save' => [PlansAdmin::class, 'save'], 'before_delete' => [PlansAdmin::class, 'delete'],
        'help' => 'Editing a plan changes new subscriptions only. The monthly amount accepted by existing subscribers stays unchanged. Turn off a plan to stop new subscriptions.',
    ]],
];
