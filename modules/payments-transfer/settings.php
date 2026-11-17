<?php
/**
 * AIFEC — ajustes de la transferencia bancaria: la cuenta a la que paga el cliente y los textos que ve. Son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Bank transfer',
    'description' => 'The bank account your customers pay into',
    'icon' => 'card',
    'cap' => 'orders.edit',
    'help' => "Without a valid IBAN the bank transfer is not offered.\nThe order stays on hold until you mark it as paid.\nThe customer sees these details on the order page and in the on-hold email.",
    'tabs' => [
        'trmain' => ['label' => 'Bank account', 'fields' => [
            'payments.transfer.holder' => ['type' => 'text', 'label' => 'Account holder', 'rules' => 'max:120', 'width' => 'md'],
            'payments.transfer.iban' => ['type' => 'text', 'label' => 'IBAN', 'width' => 'md',
                'rules' => ['nullable', 'regex:/^[A-Za-z]{2}[0-9]{2}[A-Za-z0-9 .\-]{11,40}$/'],
                'hint' => 'Two letters, two check digits and the account number. Spaces are fine.'],
            'payments.transfer.bank' => ['type' => 'text', 'label' => 'Bank', 'rules' => 'max:120', 'width' => 'md'],
            'payments.transfer.days' => ['type' => 'number', 'label' => 'Days to pay', 'default' => 5, 'rules' => 'min:0|max:365', 'width' => 'sm',
                'hint' => '0 means no deadline is mentioned.'],
            'payments.transfer.note' => ['type' => 'textarea', 'label' => 'Extra note', 'rules' => 'max:1000',
                'hint' => 'Shown below the bank details. Plain text.'],
        ]],
        'trtexts' => ['label' => 'Texts', 'fields' => [
            'payments.transfer.title' => ['type' => 'text', 'label' => 'Title', 'rules' => 'max:120', 'hint' => 'Empty: the standard title.', 'width' => 'md'],
            'payments.transfer.description' => ['type' => 'text', 'label' => 'Description', 'rules' => 'max:255', 'hint' => 'Empty: the standard description.'],
        ]],
    ],
];
