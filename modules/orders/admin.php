<?php
/**
 * AIFEC — pedidos en el panel: permisos, menú, el recurso «order_states» y las piezas del inicio.
 *
 * El listado, la ficha y los carritos son pantallas propias (src/Admin/OrdersController.php, rutas en routes.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\Admin\OrdersAdmin;
use Aifec\Modules\Orders\Admin\OrdersDashboard;

return [
    'caps' => [
        'orders.view' => 'See the orders and the carts',
        'orders.edit' => 'Change the status of orders, add notes and tracking codes, and mark them as paid',
        'orders.notes' => 'Add notes to orders',
        'orders.refund' => 'Refund money from orders',
        'orders.export' => 'Export the list of orders',
        'orders.create' => 'Create orders from the panel (by phone, by WhatsApp or at the counter) and repeat an order',
        'orders.price_override' => 'Give a product a different price in an order made from the panel (only by its name: «orders.*» does not include it)',
    ],
    'menu' => [
        ['group' => 'sales', 'items' => [
            ['label' => 'Orders', 'url' => '/admin/orders', 'cap' => 'orders.view', 'order' => 10],
            ['label' => 'Carts', 'url' => '/admin/orders/carts', 'cap' => 'orders.view', 'order' => 20],            ['label' => 'Reports', 'url' => '/admin/reports', 'cap' => 'orders.view', 'order' => 50],
        ]],
        ['group' => 'settings', 'items' => [
            ['label' => 'Payment methods', 'url' => '/admin/payments', 'cap' => 'orders.edit', 'order' => 20],
            ['label' => 'Sales', 'settings' => 'orders', 'cap' => 'orders.edit', 'order' => 50, 'tabs' => 'sales', 'tab' => 'Settings', 'menu_label' => 'Sales'],
            ['label' => 'Order statuses', 'resource' => 'order_states', 'cap' => 'orders.view', 'order' => 51, 'tabs' => 'sales', 'menu_label' => 'Sales'],
        ]],
    ],
    'resources' => [
        'order_states' => [
            'table' => 'order_states', 'title' => 'Order statuses', 'singular' => 'Order status', 'label_field' => 'name', 'cap' => 'orders',
            'timestamps' => false, 'tags' => ['orders'],
            'list' => [
                'columns' => [
                    'name' => ['label' => 'Name', 'link' => true, 'sort' => true, 'translate' => true],
                    'code' => ['label' => 'Code', 'hide_sm' => true],
                    'kind' => ['label' => 'Class', 'type' => 'badge', 'map' => [
                        'open' => ['In progress', 'info'], 'done' => ['Finished', 'ok'], 'cancelled' => ['Cancelled', 'bad'],
                    ]],
                    'color' => ['label' => 'Colour', 'render' => static function (array $row): string {
                        return Admin::badge(t((string) $row['name']), (string) $row['color']);
                    }],
                    'mail' => ['label' => 'Email sent', 'hide_sm' => true],
                    'system' => ['label' => 'Built-in', 'type' => 'bool', 'hide_sm' => true],
                ],
                'search' => ['name', 'code'],
                'sort' => ['position', 'asc'],
                'per_page' => 50,
            ],
            'form' => static function (App $app, ?int $id): array {
                return OrdersAdmin::stateForm($app, $id);
            },
            'before_save' => [OrdersAdmin::class, 'stateBeforeSave'],
            'before_delete' => [OrdersAdmin::class, 'stateBeforeDelete'],
            'help' => "Each order is always in one status. The colour and the class decide how it looks and how it is counted.\nThe built-in statuses cannot be deleted and keep their code. A status that has orders cannot be deleted either.\nThe email is sent to the customer when the order enters the status, if the person who changes it chooses to notify.",
        ],
    ],
    'dashboard' => [
        ['id' => 'orders.today', 'cap' => 'orders.view', 'size' => 4, 'order' => 10, 'render' => [OrdersDashboard::class, 'today']],
        ['id' => 'orders.week', 'cap' => 'orders.view', 'size' => 4, 'order' => 11, 'render' => [OrdersDashboard::class, 'week']],
        ['id' => 'orders.month', 'cap' => 'orders.view', 'size' => 4, 'order' => 12, 'render' => [OrdersDashboard::class, 'month']],
        ['id' => 'orders.prepare', 'cap' => 'orders.view', 'size' => 6, 'order' => 13, 'render' => [OrdersDashboard::class, 'prepare']],
        ['id' => 'orders.chart', 'cap' => 'orders.view', 'size' => 6, 'order' => 14, 'render' => [OrdersDashboard::class, 'chart']],
    ],
];
