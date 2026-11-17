<?php
/**
 * AIFEC — ajustes del contra reembolso: dónde se ofrece, hasta qué importe y cuánto cuesta. Son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Cash on delivery',
    'description' => 'Where it is offered and what it costs',
    'icon' => 'box',
    'cap' => 'orders.edit',
    'help' => "Cash on delivery is only offered for orders that need shipping.\nThe fee is a fixed amount plus a percentage of the order total, never less than the minimum.\nAmounts are written like your prices: with taxes included or not, as set in the tax settings.",
    'tabs' => [
        'cdwhere' => ['label' => 'Where', 'fields' => [
            'payments.cod.countries' => ['type' => 'tags', 'label' => 'Countries', 'default' => ['ES'],
                'hint' => 'Two-letter country codes, for example ES, PT.'],
            'payments.cod.max_total' => ['type' => 'money', 'label' => 'Highest order total', 'default' => 0, 'width' => 'md',
                'hint' => '0 means no limit.'],
        ]],
        'cdfee' => ['label' => 'Fee', 'fields' => [
            'payments.cod.fee' => ['type' => 'money', 'label' => 'Fixed fee', 'default' => 0, 'width' => 'md'],
            'payments.cod.percent' => ['type' => 'percent', 'scale' => 100, 'label' => 'Percentage of the order total', 'default' => 0, 'width' => 'md'],
            'payments.cod.min_fee' => ['type' => 'money', 'label' => 'Minimum fee', 'default' => 0, 'width' => 'md'],
        ]],
        'cdtexts' => ['label' => 'Texts', 'fields' => [
            'payments.cod.title' => ['type' => 'text', 'label' => 'Title', 'rules' => 'max:120', 'hint' => 'Empty: the standard title.', 'width' => 'md'],
            'payments.cod.description' => ['type' => 'text', 'label' => 'Description', 'rules' => 'max:255', 'hint' => 'Empty: the standard description.'],
        ]],
    ],
];
