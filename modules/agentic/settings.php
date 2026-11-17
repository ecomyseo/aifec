<?php
/**
 * AIFEC — ajustes del comercio con agentes de IA: UCP (la compra desde el Modo IA de Google y Gemini) y la verificación de
 * los agentes que firman (ChatGPT). Son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Agentic\Ucp;

return [
    'title' => 'UCP and agents',
    'description' => 'Purchases from AI platforms through UCP and verification of signed AI agents',
    'icon' => 'chat',
    'cap' => 'orders.edit',
    'help' => "UCP is the protocol Google uses to buy in a shop from AI Mode and Gemini: the platform builds the cart, the delivery and the payment with the checkout of the shop, and the person pays with Google Pay.\nThe signed agents (the agent and the wallet of ChatGPT) buy on the web like a person: here the shop recognises them, so their sales are attributed to them.\nToday both only buy in the United States; in Europe they are announced but have no date.",
    'tabs' => [
        'agucp' => ['label' => 'UCP', 'fields' => [
            'agentic.ucp_state' => ['type' => 'html', 'label' => 'Profile address', 'html' => static function ($values, App $app): string {
                $url = $app->url()->origin() . $app->url()->to('/.well-known/ucp', [], '');
                $state = !Ucp::enabled($app) ? t('Off: the profile and the purchase API answer 404.')
                    : (Ucp::canPay($app) ? t('On: the platforms can complete the purchase with the payment methods announced.')
                        : t('On, without a payment method for platforms: purchases are finished on the checkout page of the shop.'));

                return '<div class="af-input-group"><input type="text" class="af-input" readonly value="' . e($url) . '" aria-label="' . e(t('Profile address')) . '"></div>'
                    . '<p class="af-hint" data-af-ucp-state>' . e($state) . '</p>'
                    . (Ucp::links($app) === [] ? '<p class="af-hint">' . e(t('Choose the terms and the privacy pages: UCP asks for both.')) . '</p>' : '');
            }],
            'agentic.ucp_enabled' => ['type' => 'toggle', 'label' => 'Turn UCP on', 'default' => false,
                'hint' => 'Off, the profile and the purchase API answer 404.'],
            'agentic.ucp_require_key' => ['type' => 'toggle', 'label' => 'Ask for an API key to complete a purchase', 'default' => true,
                'hint' => 'Give the platform a key of the API with the scope «orders:create». Off, anyone with a valid payment token could complete one.'],
            'agentic.privacy_page_id' => ['type' => 'relation', 'resource' => 'pages', 'label' => 'Privacy policy page', 'default' => 0,
                'hint' => 'It goes with the terms page of the orders in every purchase through UCP.'],
        ]],
        'agagents' => ['label' => 'Signed agents', 'fields' => [
            'agentic.agents_state' => ['type' => 'html', 'label' => 'Last verified agent', 'html' => static function ($values, App $app): string {
                $last = $app->cache()->get('agentic.last_agent');
                $text = is_array($last) && isset($last['name'], $last['at'])
                    ? t('{name}, {date}', ['name' => (string) $last['name'], 'date' => date('Y-m-d H:i', (int) $last['at'])])
                    : t('None yet.');

                return '<p class="af-hint" data-af-agents-state>' . e($text) . '</p>';
            }],
            'agentic.verify_agents' => ['type' => 'toggle', 'label' => 'Verify the signature of AI agents', 'default' => true,
                'hint' => 'Web Bot Auth: the shop checks the signature with the public key of the agent and attributes the visit to it. It never blocks anybody.'],
            'agentic.trusted_agents' => ['type' => 'textarea', 'label' => 'Trusted agents', 'default' => 'https://chatgpt.com', 'rows' => 3,
                'rules' => ['nullable', 'max:1000'], 'hint' => 'One address per line. Only the keys of these agents are downloaded.'],
        ]],
    ],
];
