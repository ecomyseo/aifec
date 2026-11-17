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
    'title' => 'Shopping assistant',
    'description' => 'The chat that helps visitors find products and reach the checkout',
    'icon' => 'chat',
    'cap' => 'assistant.edit',
    'help' => "The assistant says it is an AI, only talks about the shop and never invents a price: prices, stock and delivery times always come from the catalogue, the cart and the shipping rules.\nIt can also take the whole order in the chat: the customer always confirms it with a button or a clear yes, and the total always comes from the cart.\nWithout the AI turned on it still works, in a simpler way: it understands searches, shipping to a postcode, order status, returns, payment and contact.\nWhat a customer writes can contain personal data, so the conversations are deleted after the days you set.",
    'tabs' => [
        'asgeneral' => ['label' => 'General', 'fields' => [
            'assistant.enabled' => ['type' => 'toggle', 'label' => 'Show the assistant in the shop', 'default' => true],
            'assistant.name' => ['type' => 'text', 'label' => 'Assistant name', 'default' => '', 'rules' => 'max:60', 'hint' => 'Optional name shown on the chat button and in its presentation. It always identifies itself as AI.'],
            'assistant.tone' => ['type' => 'select', 'label' => 'Tone', 'options' => [
                'friendly' => 'Warm and friendly', 'professional' => 'Professional and courteous', 'concise' => 'Brief and direct',
            ], 'default' => 'friendly', 'hint' => 'How the AI talks. The simple mode always answers in the same way.'],
            'assistant.quality' => ['type' => 'select', 'label' => 'Answer quality', 'options' => [
                'normal' => 'Fast (economy model)', 'high' => 'Higher quality (slower)',
            ], 'default' => 'normal', 'hint' => 'Fast uses the economy model in every step. With higher quality the economy model looks things up and the standard model writes the answer: it takes a little longer and costs more. The steps of a purchase always go with the fast one.'],
            'assistant.greeting' => ['type' => 'text', 'label' => 'Extra text for the greeting', 'default' => '', 'rules' => 'max:240', 'hint' => 'Added after the presentation message, for example your delivery promise.'],
        ]],
        'asinfo' => ['label' => 'What it tells customers', 'fields' => [
            'assistant.about' => ['type' => 'textarea', 'label' => 'What the assistant should know about your shop', 'rows' => 6, 'default' => '', 'rules' => 'max:1500', 'hint' => 'In your own words: what you sell and for whom, what makes you different, what you do not do. The assistant uses it when someone asks about the shop itself. Never prices or stock: those come from the catalogue.'],
            'assistant.instructions' => ['type' => 'textarea', 'label' => 'Specific instructions for the assistant', 'rows' => 6, 'default' => '', 'rules' => 'max:2000', 'hint' => 'Your preferences for advice and wording. They cannot change catalogue facts, prices, privacy or the requirement to confirm an order. They apply only when AI is available.'],
            'assistant.hours' => ['type' => 'text', 'label' => 'Opening hours', 'default' => '', 'rules' => 'max:240', 'hint' => 'Mon-Fri 9:00 to 18:00. Empty: the assistant says it does not have them.'],
            'assistant.returns_text' => ['type' => 'textarea', 'label' => 'Returns policy', 'rows' => 3, 'default' => '', 'rules' => 'max:600', 'hint' => 'Used when the semantic module has no returns policy defined.'],
        ]],
        'asorders' => ['label' => 'Orders in the chat', 'fields' => [
            'assistant.checkout' => ['type' => 'toggle', 'label' => 'Let the assistant take the order in the chat', 'default' => true, 'hint' => 'It collects the details, shows the shipping, the payment and the exact total, and places the order only when the customer confirms.'],
            'assistant.max_order_cents' => ['type' => 'money', 'label' => 'Highest order the assistant can take', 'default' => 0, 'width' => 'md', 'hint' => '0 means no limit. Above it, the assistant sends the customer to the shop checkout.'],
        ]],
        'aslimits' => ['label' => 'Limits', 'fields' => [
            'assistant.max_messages' => ['type' => 'number', 'label' => 'Messages per conversation', 'default' => 30, 'rules' => 'int|min:5|max:200', 'width' => 'sm', 'hint' => 'Messages from the customer. After them the assistant sends the customer to write to the shop.'],
            'assistant.retention_days' => ['type' => 'number', 'label' => 'Days to keep the conversations', 'default' => 30, 'rules' => 'int|min:1|max:365', 'width' => 'sm', 'suffix' => 'days', 'hint' => 'A daily task deletes the older ones.'],
        ]],
    ],
];
