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
    'description' => 'UCP and ACP checkout and verification of signed AI agents',
    'icon' => 'chat',
    'cap' => 'orders.edit',
    'help' => "UCP and ACP prepare purchases using the shop's own checkout. Enable each protocol separately and give the platform an API key with the orders:create scope. Buyers confirm and pay on the shop checkout with its enabled payment methods.\nProtocol compatibility does not grant access to a third-party commerce programme. Payment-provider and platform onboarding are separate. Signed browser agents are verified with Web Bot Auth.",
    'tabs' => [
        'agdiscovery' => ['label' => 'Discovery and installation', 'fields' => [
            'agentic.discovery_help' => ['type' => 'html', 'label' => 'Public protocol endpoints', 'html' => static function ($values, App $app): string {
                $ucp = Ucp::absolute($app, $app->url()->to('/.well-known/ucp'));
                $acp = Ucp::absolute($app, $app->url()->to('/.well-known/acp.json'));
                return '<p>' . e(t('UCP and ACP profiles are dynamic endpoints of this shop. They require the protocol switches and server routing, not a product feed.')) . '</p>'
                    . '<p><a href="' . e($ucp) . '" target="_blank" rel="noopener">' . e($ucp) . '</a><br><a href="' . e($acp) . '" target="_blank" rel="noopener">' . e($acp) . '</a></p>'
                    . '<p>' . e(t('On Nginx, allow .well-known before rules that deny hidden paths. Use docs/04-ai-first/ucp-acp-nginx.conf and preserve the current PHP configuration.')) . '</p>'
                    . '<p>' . e(t('Public HTTP access has not been verified. Installing files does not confirm server routing.')) . '</p>';
            }],
        ]],
        'agucp' => ['label' => 'UCP', 'fields' => [
            'agentic.acp_enabled' => ['type' => 'toggle', 'label' => 'Enable ACP', 'default' => false,
                'hint' => 'Agentic Commerce Protocol 2026-04-17. Give the platform an API key with the orders:create scope.'],
            'agentic.ucp_state' => ['type' => 'html', 'label' => 'Profile address', 'html' => static function ($values, App $app): string {
                $url = $app->url()->origin() . $app->url()->to('/.well-known/ucp', [], '');
                $state = !Ucp::enabled($app) ? t('Off: the profile and the purchase API answer 404.')
                    : t('Buyers confirm and pay in the shop checkout using the active payment methods.');

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
