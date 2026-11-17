<?php
/**
 * AIFEC — cupones en el panel: permisos, menú y el recurso «coupons».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Coupons\CouponsAdmin;

return [
    'caps' => [
        'coupons.view' => 'See the coupons',
        'coupons.edit' => 'Create and change coupons',
    ],
    'menu' => [
        ['group' => 'marketing', 'items' => [
            ['label' => 'Coupons', 'resource' => 'coupons', 'cap' => 'coupons.view', 'order' => 10],
        ]],
    ],
    'resources' => [
        'coupons' => [
            'table' => 'coupons', 'title' => 'Coupons', 'singular' => 'Coupon', 'label_field' => 'code', 'cap' => 'coupons',
            'tags' => ['coupons'],
            'list' => static function (App $app): array {
                return CouponsAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return CouponsAdmin::formDefinition($app, $id);
            },
            'before_save' => [CouponsAdmin::class, 'beforeSave'],
            'before_delete' => [CouponsAdmin::class, 'beforeDelete'],
            'side' => [CouponsAdmin::class, 'side'],
            'help' => "A coupon is typed by the customer in the cart. The code is saved in capital letters.\nA used coupon cannot be deleted: turn it off instead.\nIf a coupon is limited to products and to categories, an item is included when it matches either of them.",
        ],
    ],
];
