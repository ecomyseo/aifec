<?php
/**
 * AIFEC — ajustes de envíos: país de partida y cálculo del envío en el carrito. Son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Geo\Countries;
use Aifec\Kernel\App;

return [
    'title' => 'Shipping',
    'description' => 'Default country and shipping estimate in the cart',
    'icon' => 'truck',
    'cap' => 'shipping.edit',
    'help' => "Default country: the one used when a destination does not say its country.\nEstimate in the cart: the customer can see the shipping cost in the cart by choosing a country and a postcode, before reaching the checkout.\nZones, carriers and rates are set in their own screens; use Test a shipment to check them.",
    'tabs' => [
        'shgeneral' => ['label' => 'General', 'fields' => [
            'shipping.default_country' => ['type' => 'select', 'label' => 'Default country', 'default' => 'ES', 'rules' => 'required', 'translate' => false, 'width' => 'md',
                'options' => static function (App $app): array {
                    return Countries::all($app->i18n()->locale());
                }],
            'shipping.estimate_in_cart' => ['type' => 'toggle', 'label' => 'Let customers estimate the shipping in the cart', 'default' => true,
                'hint' => 'They choose a country and a postcode and see the carriers and their prices.'],
        ]],
    ],
];
