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
    'title' => 'MCP',
    'description' => 'The Model Context Protocol server: lets an AI assistant use the shop with the same tools as the shop assistant',
    'icon' => 'chat',
    'cap' => 'mcp.edit',
    'help' => "An AI assistant (Claude, ChatGPT and others) connects to the address of this server and can search the catalogue, build a cart and take the purchase to the end with the same tools as the assistant of the shop.\nEach connection has its own cart. To place an order the person confirms the total with the code the shop emails to them, or the assistant uses a key of the API with the scope «orders:create»: it is the setting «Orders placed by AI agents» of the API.\nThe limits (highest order, orders per hour) are the ones of the API settings.",
    'tabs' => [
        'mcgeneral' => ['label' => 'General', 'fields' => [
            'mcp.enabled' => ['type' => 'toggle', 'label' => 'Turn the MCP server on', 'default' => true, 'hint' => 'Off, its addresses answer 404.'],
            'mcp.registry_auth' => ['type' => 'text', 'label' => 'Proof for the official MCP Registry', 'default' => '', 'rules' => ['nullable', 'max:300', 'regex:/^(v=MCPv1; k=(ed25519|ecdsap384); p=[A-Za-z0-9+\\/=]+)?$/'],
                'hint' => 'The line «v=MCPv1; k=ed25519; p=…» with the public key. It is published at /.well-known/mcp-registry-auth so the registry can check that the domain is yours when you publish the server.'],
        ]],
        'mctools' => ['label' => 'Tools', 'fields' => [
            'mcp.tools' => ['type' => 'checks', 'label' => 'Tools the assistants can use', 'default' => [
                'search_products', 'get_product', 'list_categories', 'check_availability', 'shipping_quote', 'store_info', 'cart_view', 'cart_add', 'cart_update',
                'checkout_link', 'checkout_status', 'checkout_set_customer', 'checkout_shipping_options', 'checkout_set_shipping', 'checkout_payment_options',
                'checkout_set_payment', 'checkout_review', 'checkout_place',
            ], 'options' => [
                'search_products' => 'Search products',
                'get_product' => 'Product details',
                'list_categories' => 'List the categories',
                'check_availability' => 'Check availability',
                'shipping_quote' => 'Shipping quote',
                'store_info' => 'Shop information and policies',
                'cart_view' => 'See the cart',
                'cart_add' => 'Add to the cart',
                'cart_update' => 'Change the cart',
                'checkout_link' => 'Address to pay on the web',
                'order_status' => 'Order status (needs the order number and its email)',
                'checkout_status' => 'Checkout: where the purchase is',
                'checkout_set_customer' => 'Checkout: save contact and address',
                'checkout_shipping_options' => 'Checkout: delivery options',
                'checkout_set_shipping' => 'Checkout: choose the delivery',
                'checkout_payment_options' => 'Checkout: payment methods',
                'checkout_set_payment' => 'Checkout: choose the payment',
                'checkout_review' => 'Checkout: review the order',
                'checkout_place' => 'Checkout: place the order (needs the code emailed to the buyer, or a key with the scope orders:create)',
            ], 'hint' => 'Only the ticked tools are offered. By default all but the order status.'],
        ]],
    ],
];
