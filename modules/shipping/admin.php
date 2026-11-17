<?php
/**
 * AIFEC — envíos en el panel: permisos, menú y los recursos «shipping_zones» y «carriers».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Shipping\ShippingAdmin;

return [
    'caps' => [
        'shipping.view' => 'See the shipping zones, carriers and rates',
        'shipping.edit' => 'Change the shipping settings, zones, carriers and rates',
    ],
    'menu' => [
        ['group' => 'settings', 'items' => [
            // Una sola entrada: Transportistas, Zonas y tarifas, Ajustes y Probar un envío son sus pestañas.
            ['label' => 'Shipping', 'url' => '/admin/shipping', 'cap' => 'shipping.view', 'order' => 30],
        ]],
    ],
    'resources' => [
        'shipping_zones' => [
            'table' => 'shipping_zones', 'title' => 'Shipping zones', 'singular' => 'Shipping zone', 'label_field' => 'name', 'cap' => 'shipping',
            'timestamps' => false, 'tags' => ['shipping'],
            'list' => ShippingAdmin::zoneListConfig(),
            'form' => static function (App $app, ?int $id = null): array {
                $form = ShippingAdmin::zoneForm($app, $id);
                // Las zonas de serie se guardan en inglés («Peninsula»): el campo enseña el nombre en el idioma del panel, como el listado.
                if (isset($form['fields']['name']) && is_array($form['fields']['name'])) {
                    $form['fields']['name']['seeded'] = true;
                }

                return $form;
            },
            'before_save' => [ShippingAdmin::class, 'zoneBeforeSave'],
            'after_save' => [ShippingAdmin::class, 'zoneAfterSave'],
            'before_delete' => [ShippingAdmin::class, 'zoneBeforeDelete'],
            'after_delete' => [ShippingAdmin::class, 'zoneAfterDelete'],
            'help' => "A zone is a set of places that share the same shipping prices, for example the peninsula or the islands.\nThe most specific place wins: a postcode before a region, a region before a country, a country before the rest of the world.\nA zone with carrier rates cannot be deleted.",
        ],
        'carriers' => [
            'table' => 'carriers', 'title' => 'Carriers', 'singular' => 'Carrier', 'label_field' => 'name', 'cap' => 'shipping',
            'timestamps' => true, 'tags' => ['shipping'],
            'list' => ShippingAdmin::carrierListConfig(),
            'form' => static function (App $app, ?int $id = null): array {
                return ShippingAdmin::carrierForm($app, $id);
            },
            'before_save' => [ShippingAdmin::class, 'carrierBeforeSave'],
            'after_save' => [ShippingAdmin::class, 'carrierAfterSave'],
            'before_delete' => [ShippingAdmin::class, 'carrierBeforeDelete'],
            'after_delete' => [ShippingAdmin::class, 'carrierAfterDelete'],
            'help' => "A carrier is a way to send the order. With a rate table, you set a price for each zone and each weight or order amount range.\nWith pickup in store there are no rates: you set the price and the address where the customer collects the order.\nUse Test a shipment to check that a destination gets the prices you expect.",
        ],
    ],
];
