<?php
/**
 * AIFEC — GM licences en el panel: permisos, menú y el recurso de licencias (listado, alta manual, ficha con dominios y acciones).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Gm\Licencias\LicenseAdmin;

return [
    'caps' => [
        'gmlic.view' => 'See the licences',
        'gmlic.edit' => 'Create licences and change them (revoke, reactivate, extend, reissue)',
    ],
    'menu' => [
        ['group' => 'sales', 'items' => [
            ['label' => 'Licences', 'resource' => 'gm_licenses', 'cap' => 'gmlic.view', 'order' => 70],
        ]],
        ['group' => 'settings', 'items' => [
            ['label' => 'Licences', 'settings' => 'gm-licencias', 'cap' => 'gmlic.edit', 'order' => 70],
        ]],
    ],
    'resources' => [
        'gm_licenses' => [
            'table' => 'gm_licenses', 'title' => 'Licences', 'singular' => 'Licence', 'label_field' => 'license_key', 'cap' => 'gmlic', 'timestamps' => true,
            'can_delete' => false,
            'list' => static function (App $app): array {
                return LicenseAdmin::listConfig($app);
            },
            'form' => static function (App $app, ?int $id): array {
                return LicenseAdmin::form($app, $id);
            },
            'before_save' => [LicenseAdmin::class, 'beforeSave'],
            'side' => [LicenseAdmin::class, 'side'],
            'help' => "One licence is created for each unit bought when the order is paid. A returned order revokes its licences.\nThe programs installed in the customers' shops check the key on this shop and activate their domain, up to the number allowed.\nA signed key works without a connection. It needs the tax ID in the billing data of the order and the signing key in the settings.",
        ],
    ],
];
