<?php
/**
 * AIFEC — impuestos en el panel: permisos, menú y los recursos «tax_classes» y «tax_rates».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Tax\TaxAdmin;

return [
    'caps' => [
        'tax.view' => 'See the tax classes and rates',
        'tax.edit' => 'Change the tax settings, classes and rates',
    ],
    'menu' => [
        ['group' => 'settings', 'items' => [
            ['label' => 'Taxes', 'settings' => 'tax', 'cap' => 'tax.edit', 'order' => 40, 'tabs' => 'taxes', 'tab' => 'Settings', 'menu_label' => 'Taxes'],
            ['label' => 'Tax classes', 'resource' => 'tax_classes', 'cap' => 'tax.view', 'order' => 41, 'tabs' => 'taxes', 'tab' => 'Classes', 'menu_label' => 'Taxes'],
            ['label' => 'Tax rates', 'resource' => 'tax_rates', 'cap' => 'tax.view', 'order' => 42, 'tabs' => 'taxes', 'tab' => 'Percentages', 'menu_label' => 'Taxes'],
        ]],
    ],
    'resources' => [
        'tax_classes' => [
            'table' => 'tax_classes', 'title' => 'Tax classes', 'singular' => 'Tax class', 'label_field' => 'name', 'cap' => 'tax',
            'timestamps' => false, 'tags' => ['tax', 'catalog'],
            'list' => TaxAdmin::classListConfig(),
            'form' => static function (App $app, ?int $id = null): array {
                return TaxAdmin::classForm($app, $id);
            },
            'before_save' => [TaxAdmin::class, 'classBeforeSave'],
            'after_save' => [TaxAdmin::class, 'classAfterSave'],
            'before_delete' => [TaxAdmin::class, 'classBeforeDelete'],
            'help' => "A class groups the products that carry the same tax, for example the standard and the reduced rate.\nThe code cannot change once the class has rates or is used by a product.\nA class with rates or products cannot be deleted.",
        ],
        'tax_rates' => [
            'table' => 'tax_rates', 'title' => 'Tax rates', 'singular' => 'Tax rate', 'label_field' => 'name', 'cap' => 'tax',
            'timestamps' => false, 'tags' => ['tax', 'catalog'],
            'list' => TaxAdmin::rateListConfig(),
            'form' => [
                'fields' => [
                    'tax_class_id' => ['type' => 'select', 'label' => 'Tax class', 'rules' => 'required', 'translate' => true, 'width' => 'md',
                        'options_from' => ['table' => 'tax_classes', 'value' => 'id', 'label' => 'name', 'order' => 'position']],
                    'country' => ['type' => 'select', 'label' => 'Country', 'default' => 'ES', 'rules' => 'required', 'translate' => false, 'width' => 'md',
                        'options' => static function (App $app): array {
                            return TaxAdmin::countryOptions($app);
                        }],
                    'region' => ['label' => 'Region', 'rules' => 'max:16', 'width' => 'sm', 'hint' => 'Optional. Empty applies to the whole country.'],
                    'postcodes' => ['label' => 'Postcodes', 'rules' => 'max:255',
                        'hint' => 'Prefixes or ranges separated by commas, for example 35,38 or 51000-51999. When filled, the region is not used.'],
                    'rate' => ['type' => 'percent', 'label' => 'Rate', 'scale' => 100, 'rules' => 'required|between:0,100', 'width' => 'sm'],
                    'name' => ['label' => 'Name', 'rules' => 'required|max:60', 'hint' => 'The name that appears on the invoice, for example VAT 21 %.'],
                    'priority' => ['type' => 'number', 'label' => 'Priority', 'default' => 0, 'rules' => 'required', 'width' => 'sm',
                        'hint' => 'When two rates apply equally, the higher priority wins.'],
                    'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1],
                ],
            ],
            'before_save' => [TaxAdmin::class, 'rateBeforeSave'],
            'help' => "The most specific rate wins: postcodes first, then region, then the country alone.\nIf two rates are equally specific, the one with the higher priority is used.\nChanging a rate does not change the orders already placed: they keep the tax they were charged.",
        ],
    ],
];
