<?php
/**
 * AIFEC — ajustes del pago simulado: cuándo se ofrece, qué hace y los textos que ve el cliente. Son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Simulated payment',
    'description' => 'A payment that charges nothing, for tests and demos',
    'icon' => 'card',
    'cap' => 'orders.edit',
    'help' => "It is only offered in demo sites and in sites in debug mode, unless you allow it on a live shop.\nIn Ask mode the customer lands on a test page and chooses whether the payment goes well or badly.\nA simulated payment never moves money: its orders are marked as test orders.",
    'tabs' => [
        'spmain' => ['label' => 'General', 'fields' => [
            'payments.simulated.enabled' => ['type' => 'toggle', 'label' => 'Offer the simulated payment', 'default' => true],
            'payments.simulated.mode' => ['type' => 'select', 'label' => 'What happens when the customer chooses it', 'default' => 'ask', 'rules' => 'required', 'width' => 'md',
                'options' => [
                    'ask' => 'Ask on a test page',
                    'approve' => 'Approve every payment',
                    'decline' => 'Decline every payment',
                ]],
            'payments.simulated.allow_live' => ['type' => 'toggle', 'label' => 'Allow it on a live shop', 'default' => false,
                'hint' => 'Warning: real customers would place orders without paying. Leave it off unless you know why you need it.'],
        ]],
        'sptexts' => ['label' => 'Texts', 'fields' => [
            'payments.simulated.title' => ['type' => 'text', 'label' => 'Title', 'rules' => 'max:120', 'hint' => 'Empty: the standard title.', 'width' => 'md'],
            'payments.simulated.description' => ['type' => 'text', 'label' => 'Description', 'rules' => 'max:255', 'hint' => 'Empty: the standard description.'],
        ]],
    ],
];
