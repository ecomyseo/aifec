<?php
/**
 * AIFEC — ajustes de impuestos: precios con el impuesto dentro, país de la tienda, impuesto del envío y exención de empresas de la UE.
 * Son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Geo\Countries;
use Aifec\Kernel\App;

return [
    'title' => 'Taxes',
    'description' => 'Prices with tax, shop country and tax of the shipping',
    'icon' => 'percent',
    'cap' => 'tax.edit',
    'help' => "Prices with tax included: the price you type is the final price and the tax is taken out of it; when it is off, the tax is added on top of the price you type.\nCanary Islands (IGIC), Ceuta and Melilla (IPSI) have their own rates, chosen by postcode in Tax rates: the sales there carry no VAT.\nThe shipping takes the tax class you choose here, or the highest rate of the products in the cart.",
    'tabs' => [
        'txgeneral' => ['label' => 'General', 'fields' => [
            'tax.prices_include_tax' => ['type' => 'toggle', 'label' => 'Prices already include tax', 'default' => true,
                'hint' => 'Applies to the prices of products, shipping, fees and coupons.'],
            'tax.display_with_tax' => ['type' => 'toggle', 'label' => 'Show prices with tax in the shop', 'default' => true],
            'tax.base_country' => ['type' => 'select', 'label' => 'Shop country', 'default' => 'ES', 'rules' => 'required', 'translate' => false, 'width' => 'md',
                'options' => static function (App $app): array {
                    return Countries::all($app->i18n()->locale());
                }],
            'tax.base_postcode' => ['label' => 'Shop postcode', 'rules' => 'max:12', 'width' => 'sm'],
            'tax.shipping_class' => ['type' => 'select', 'label' => 'Tax of the shipping', 'default' => 'highest', 'rules' => 'required', 'width' => 'md',
                'options' => static function (App $app): array {
                    $options = ['highest' => 'Highest rate in the cart'];
                    foreach ($app->service('tax')->classes() as $class) {
                        $options[$class['code']] = $class['name'];
                    }

                    return $options;
                }],
            'tax.eu_b2b_exempt' => ['type' => 'toggle', 'label' => 'No tax for businesses of other EU countries', 'default' => false,
                'hint' => 'Customers with a tax ID that receive in another EU country pay no tax.'],
        ]],
    ],
];
