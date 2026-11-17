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
    'title' => 'API',
    'description' => 'The door for programs and assistants: read the shop, prepare a cart and place orders',
    'icon' => 'refresh',
    'cap' => 'api.edit',
    'help' => "Anyone can read the catalogue and prepare a cart without a key. A key raises the usage limit and, with the scope «orders:create», lets a program place orders.\nAn order placed by a program always needs the confirmation of the person. With «Orders placed by AI agents» set to the verification code, the shop emails a code with the total to the buyer and the order is only placed when the program sends that code back.\nThe person pays: bank transfer and cash on delivery give instructions, and online payment gives the address where the customer pays. Nothing is charged without them.\nOrders by API and MCP follow the assistant's switch «Let the assistant take the order in the chat».",
    'tabs' => [
        'apgeneral' => ['label' => 'General', 'fields' => [
            'api.enabled' => ['type' => 'toggle', 'label' => 'Turn the API on', 'default' => true, 'hint' => 'Off, every address of the API answers 404.'],
            'api.links' => ['type' => 'toggle', 'label' => 'Let assistants that can only read pages buy with links', 'default' => true,
                'hint' => 'Many chat assistants cannot send a form, only open addresses. With this on, each step of the purchase is also an address they can open. The order still needs the code sent to the buyer.'],
            'api.cors_origins' => ['type' => 'textarea', 'label' => 'Origins allowed to use the cart from a browser', 'rows' => 3, 'default' => '', 'rules' => 'max:600',
                'hint' => 'One address per line, for example https://app.ejemplo.test. Reading is open to every origin.'],
        ]],
        'aplimits' => ['label' => 'Limits', 'fields' => [
            'api.rate_per_minute' => ['type' => 'number', 'label' => 'Requests per minute without a key', 'default' => 120, 'rules' => 'int|min:0|max:6000', 'width' => 'sm', 'hint' => 'Per address. Zero means no limit.'],
            'api.rate_per_minute_key' => ['type' => 'number', 'label' => 'Requests per minute with a key', 'default' => 600, 'rules' => 'int|min:0|max:60000', 'width' => 'sm', 'hint' => 'Per key. Zero means no limit.'],
        ]],
        'aporders' => ['label' => 'Orders', 'fields' => [
            'api.agent_orders' => ['type' => 'select', 'label' => 'Orders placed by AI agents', 'default' => 'code', 'options' => [
                'code' => 'Yes, any agent: the buyer confirms with a code sent to their email',
                'key' => 'Only agents with an API key that has the scope «orders:create»',
                'off' => 'No: agents send the person to the checkout page',
            ], 'hint' => 'With the code, the shop tells the agent that it has emailed a verification code to the buyer, and the agent has to wait for it. A key with the scope «orders:create» never needs the code.'],
            'api.code_whatsapp' => ['type' => 'toggle', 'label' => 'Send the code by WhatsApp too', 'default' => false, 'hint' => 'To the phone the buyer gave, through your WhatsApp system. The code always goes by email as well.'],
            'api.whatsapp_only' => ['type' => 'textarea', 'label' => 'Only send WhatsApp to these phones', 'rows' => 2, 'default' => '', 'rules' => 'max:600',
                'hint' => 'One per line or separated by commas, with the country code. Empty: to any buyer. In a test shop put only your own phone: the message leaves from your WhatsApp to the number the buyer writes.'],
            'api.whatsapp_provider' => ['type' => 'select', 'label' => 'WhatsApp system', 'default' => 'mensajero', 'options' => [
                'mensajero' => 'Text gateway (mensajero)', '360dialog' => 'Meta WhatsApp Business through 360dialog',
            ], 'hint' => 'The text gateway sends the message as it is. Meta only lets a shop start a conversation with an approved template.'],
            'api.whatsapp_url' => ['type' => 'text', 'label' => 'WhatsApp gateway address', 'default' => '', 'rules' => 'max:300',
                'hint' => 'The text gateway receives a POST with {"to": phone, "text": message} and the key as Authorization: Bearer. With 360dialog leave it empty.'],
            'api.whatsapp_token' => ['type' => 'secret', 'label' => 'WhatsApp gateway key'],
            'api.whatsapp_template' => ['type' => 'text', 'label' => '360dialog: approved template', 'default' => '', 'rules' => 'max:120', 'hint' => 'The name of the template, as it is in Meta. Without it nothing is sent through 360dialog.'],
            'api.whatsapp_template_lang' => ['type' => 'text', 'label' => '360dialog: template language', 'default' => 'es', 'rules' => 'max:12', 'width' => 'sm', 'hint' => 'The code the template was registered with: es, es_ES, en…'],
            'api.whatsapp_template_vars' => ['type' => 'text', 'label' => '360dialog: values for {{1}}, {{2}}…', 'default' => 'code', 'rules' => 'max:60', 'hint' => 'In the order of the template, separated by commas: code, total, minutes, shop.'],
            'api.whatsapp_template_button' => ['type' => 'toggle', 'label' => '360dialog: the template has a copy-code button', 'default' => false, 'hint' => 'Meta authentication templates have it.'],
            'api.whatsapp_prefix' => ['type' => 'text', 'label' => 'Country code for phones written without one', 'default' => '34', 'rules' => 'max:4', 'width' => 'sm'],
            'api.max_order_cents' => ['type' => 'money', 'label' => 'Highest order a program can place', 'default' => 0, 'width' => 'md', 'hint' => '0 means no limit. Above it, the program sends the person to the checkout page.'],
            'api.max_orders_per_hour' => ['type' => 'number', 'label' => 'Orders per key (or per address without a key) and hour', 'default' => 10, 'rules' => 'int|min:0|max:1000', 'width' => 'sm', 'hint' => '0 means no limit.'],
            'api.codes_per_email_hour' => ['type' => 'number', 'label' => 'Verification codes per email address and hour', 'default' => 5, 'rules' => 'int|min:1|max:60', 'width' => 'sm', 'hint' => 'How many times an hour the shop sends the buyer code to the same address. Raise it only on a test shop where every purchase uses the same mailbox.'],
        ]],
    ],
];
