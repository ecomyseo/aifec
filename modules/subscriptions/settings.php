<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Subscriptions', 'description' => 'Monthly service plans, payment provider and signed notifications',
    'icon' => 'repeat', 'cap' => 'subscriptions.edit',
    'help' => "A customer accepts the final monthly amount before subscribing. Existing subscriptions keep the amount they accepted when a plan is edited.\nThe simulated provider only works in demos and debugging mode. PayPal collects each monthly payment and manages payment retries. AIFEC records each confirmed payment as an order.\nCreate a webhook for PAYMENT.SALE.COMPLETED, PAYMENT.SALE.REFUNDED, PAYMENT.SALE.REVERSED and BILLING.SUBSCRIPTION.* events. Copy the notification address from the subscriptions screen.",
    'tabs' => [
        'general' => ['label' => 'General', 'fields' => [
            'subscriptions.enabled' => ['type' => 'toggle', 'label' => 'Offer monthly subscriptions', 'default' => false],
            'subscriptions.provider' => ['type' => 'select', 'label' => 'Provider', 'options' => ['simulated' => 'Simulated (no money)', 'paypal' => 'PayPal'], 'default' => 'simulated'],
            'subscriptions.paypal.environment' => ['type' => 'select', 'label' => 'PayPal environment', 'options' => ['sandbox' => 'Sandbox (no real money)', 'live' => 'Production'], 'default' => 'sandbox'],
        ]],
        'sandbox' => ['label' => 'Sandbox keys', 'fields' => [
            'subscriptions.paypal.client_id_sandbox' => ['type' => 'text', 'label' => 'Sandbox client ID'],
            'subscriptions.paypal.secret_sandbox' => ['type' => 'secret', 'label' => 'Sandbox secret'],
            'subscriptions.paypal.webhook_id_sandbox' => ['type' => 'text', 'label' => 'Sandbox webhook ID'],
        ]],
        'live' => ['label' => 'Production keys', 'fields' => [
            'subscriptions.paypal.client_id_live' => ['type' => 'text', 'label' => 'Production client ID'],
            'subscriptions.paypal.secret_live' => ['type' => 'secret', 'label' => 'Production secret'],
            'subscriptions.paypal.webhook_id_live' => ['type' => 'text', 'label' => 'Production webhook ID'],
        ]],
    ],
];
