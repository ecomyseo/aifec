<?php
/**
 * AIFEC — ajustes de ventas: números de pedido, reglas de la caja, orden de las formas de pago y descargas. Todos son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

$general = [
    'orders.number_format' => ['type' => 'text', 'label' => 'Order number format', 'default' => '{year}-{number:6}', 'rules' => 'required|max:60',
        'hint' => 'For example {year}-{number:6} gives 2026-000123.'],
    'orders.guest_checkout' => ['type' => 'toggle', 'label' => 'Allow buying without an account', 'default' => true],
    'orders.require_vat_id' => ['type' => 'toggle', 'label' => 'Ask for the tax ID in the invoice details', 'default' => false],
    'orders.pending_minutes' => ['type' => 'number', 'label' => 'Minutes before an unpaid order is cancelled', 'default' => 120, 'rules' => 'required|min:10', 'width' => 'sm',
        'hint' => 'Only orders paid through a gateway. Their stock is released when they are cancelled.'],
];
// La página de condiciones solo se puede elegir si existe el módulo de contenidos.
if (App::hasCurrent() && App::current()->modules()->isLoaded('cms')) {
    $general['orders.terms_page_id'] = ['type' => 'relation', 'resource' => 'pages', 'label' => 'Terms and conditions page',
        'hint' => 'The customer accepts it in the checkout. Leave it empty if there is none.'];
}

return [
    'title' => 'Sales',
    'description' => 'Order numbers, checkout rules, payment methods and downloads',
    'icon' => 'cart',
    'cap' => 'orders.edit',
    'help' => "Order number: the year and the counter are filled in when the order is placed. Changing the format does not change the numbers that already exist.\nPayment methods: the order of the list is the order in which the customer sees them. The ones you do not name go last.\nDownloads: 0 means no limit.",
    'tabs' => [
        'orgeneral' => ['label' => 'General', 'fields' => $general],
        'orpayments' => ['label' => 'Payment methods', 'fields' => [
            'orders.payment_order' => ['type' => 'text', 'label' => 'Order of the payment methods', 'rules' => 'nullable|max:200',
                'hint' => 'Codes separated by commas, for example redsys, paypal, transfer.'],
        ]],
        'ordownloads' => ['label' => 'Downloads', 'fields' => [
            'orders.download_limit' => ['type' => 'number', 'label' => 'Downloads allowed per file', 'default' => 0, 'rules' => 'min:0', 'width' => 'sm', 'hint' => '0 = no limit.'],
            'orders.download_days' => ['type' => 'number', 'label' => 'Days a download stays valid', 'default' => 0, 'rules' => 'min:0', 'width' => 'sm', 'hint' => '0 = it never expires.'],
        ]],
    ],
];
