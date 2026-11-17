<?php
/**
 * AIFEC — ajustes de las facturas: emisión automática, series y formato de los números, texto legal y adjunto del correo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Invoices',
    'description' => 'Automatic invoices, numbering, legal text and the email attachment',
    'icon' => 'receipt',
    'cap' => 'invoices.edit',
    'help' => "The data of the issuer (legal name, tax ID, address) are the ones in Settings, Identity. They are copied into each invoice when it is issued: changing them later does not change the invoices that already exist.\nNumbers: the counter of each series is consecutive and never skips a number. In the format, {series} is the series, {year} the year and {number:5} the counter with zeros up to 5 digits. Changing the format does not change the numbers that already exist.\nOrders paid in test mode always use the TEST series.",
    'tabs' => [
        'ivgeneral' => ['label' => 'General', 'fields' => [
            'invoices.auto' => ['type' => 'toggle', 'label' => 'Issue the invoice when an order is paid, and the credit note when money is refunded', 'default' => true],
            'invoices.attach' => ['type' => 'toggle', 'label' => 'Attach the invoice to the order confirmation email', 'default' => true],
        ]],
        'ivnumbers' => ['label' => 'Numbering', 'fields' => [
            'invoices.series' => ['type' => 'text', 'label' => 'Invoice series', 'default' => 'F', 'rules' => 'required|max:10', 'width' => 'sm',
                'hint' => 'Letters and digits only. Each series has its own counter.'],
            'invoices.format' => ['type' => 'text', 'label' => 'Invoice number format', 'default' => '{series}{year}-{number:5}', 'rules' => 'required|max:30',
                'hint' => 'For example {series}{year}-{number:5} gives F2026-00001.'],
            'invoices.credit_series' => ['type' => 'text', 'label' => 'Credit note series', 'default' => 'R', 'rules' => 'required|max:10', 'width' => 'sm'],
            'invoices.credit_format' => ['type' => 'text', 'label' => 'Credit note number format', 'default' => '{series}{year}-{number:5}', 'rules' => 'required|max:30'],
        ]],
        'ivlegal' => ['label' => 'Legal text', 'fields' => [
            'invoices.legal_text' => ['type' => 'textarea', 'label' => 'Text at the bottom of the invoice', 'rows' => 4, 'rules' => 'nullable|max:1000',
                'hint' => 'For example the commercial register data or the tax regime.'],
        ]],
    ],
];
