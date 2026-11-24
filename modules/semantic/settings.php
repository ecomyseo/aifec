<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

/*
 * Ajustes de «Datos para máquinas»: todos son por sitio. Un solo formulario con cuatro pestañas (las claves llevan las
 * iniciales del módulo). El permiso es el de SEO si el módulo existe y, si no, el de ajustes generales.
 */
$cap = 'settings.edit';
if (App::hasCurrent() && App::current()->modules()->manifest('seo') !== null) {
    $cap = 'seo.edit';
}

return [
    'title' => 'Data for machines',
    'description' => 'Structured data, text versions, llms.txt and product feeds',
    'icon' => 'search',
    'cap' => $cap,
    'help' => "Organization: what search engines and assistants read about your company.\nReturns: the return policy that goes into every offer, the text versions and the feeds.\nText versions: the summary of llms.txt and how much text llms-full.txt can hold.\nFeeds: the product files for Google and for shopping assistants.",
    'tabs' => [
        'smorg' => ['label' => 'Organization', 'fields' => [
            'semantic.logo' => ['type' => 'image', 'label' => 'Logo for search engines', 'hint' => 'Leave it empty to use the logo of the shop.', 'default' => 0],
            'semantic.social' => ['type' => 'textarea', 'label' => 'Social profiles', 'hint' => 'One address per line: Instagram, LinkedIn, YouTube…', 'rows' => 4, 'default' => '', 'rules' => 'max:2000'],
        ]],
        'smreturns' => ['label' => 'Returns', 'fields' => [
            'semantic.returns.days' => ['type' => 'number', 'label' => 'Days to return a product', 'hint' => '0 = no return policy is published.', 'default' => 0, 'rules' => 'between:0,365', 'width' => 'sm'],
            'semantic.returns.payer' => ['type' => 'select', 'label' => 'Who pays the return', 'options' => ['customer' => 'The customer', 'merchant' => 'The shop'], 'default' => 'customer', 'width' => 'md'],
            'semantic.returns.method' => ['type' => 'select', 'label' => 'How the product comes back', 'options' => ['mail' => 'By mail', 'store' => 'In a physical shop', 'kiosk' => 'At a drop-off point', 'none' => 'Download or service: nothing is sent back'], 'default' => 'mail', 'width' => 'md'],
        ]],
        'smtext' => ['label' => 'Text versions', 'fields' => [
            'semantic.summary' => ['type' => 'textarea', 'label' => 'Summary of the shop', 'hint' => 'The first paragraph of llms.txt. Empty = it is made from the shop data.', 'rows' => 4, 'default' => '', 'rules' => 'max:1000'],
            'semantic.llms_full_kb' => ['type' => 'number', 'label' => 'Size limit of llms-full.txt (KB)', 'default' => 500, 'rules' => 'between:1,5000', 'width' => 'sm'],
        ]],
        'smrobots' => ['label' => 'Robots and agents', 'fields' => [
            'semantic.text_for.training' => ['type' => 'toggle', 'label' => 'Serve the text version (.md) to: AI training', 'default' => true,
                'hint' => 'Same address, plain text. GPTBot, ClaudeBot, CCBot, Bytespider…'],
            'semantic.text_for.assistant' => ['type' => 'toggle', 'label' => 'Serve the text version (.md) to: AI assistants', 'default' => true,
                'hint' => 'ChatGPT-User, Claude-User, PerplexityBot, Amazonbot, Gemini…'],
            'semantic.text_for.seo' => ['type' => 'toggle', 'label' => 'Serve the text version (.md) to: SEO tools', 'default' => true,
                'hint' => 'SemrushBot, AhrefsBot, MJ12bot, DotBot.'],
            'semantic.text_for.other' => ['type' => 'toggle', 'label' => 'Serve the text version (.md) to: other robots', 'default' => true,
                'hint' => 'Robots that are not in another family.'],
            'semantic.text_for.search' => ['type' => 'toggle', 'label' => 'Serve the text version (.md) to: search engines', 'default' => false,
                'hint' => 'The search engine would index text without structured data: it can lose rich results. Turn it on only if you decide so.',
                'attrs' => ['data-af-confirm-on' => 'The search engine would index text without structured data: it can lose rich results. Turn it on only if you decide so. Turn it on?']],
        ]],
        'smfeeds' => ['label' => 'Feeds', 'fields' => [
            'semantic.feeds.google' => ['type' => 'toggle', 'label' => 'Google Merchant feed (google.xml)', 'default' => true],
            'semantic.feeds.products' => ['type' => 'toggle', 'label' => 'General product feed (products.json)', 'default' => true],
            'semantic.feeds.openai' => ['type' => 'toggle', 'label' => 'Shopping assistants feed (openai.json)', 'default' => true],
            'semantic.openai.enable_search' => ['type' => 'toggle', 'label' => 'Let assistants show the products in their answers', 'default' => true],
            'semantic.openai.enable_checkout' => ['type' => 'toggle', 'label' => 'Let assistants start the purchase', 'default' => false,
                'hint' => 'Activate after OpenAI enables checkout for your integration. ACP checkout and the terms and privacy pages must be active.'],
            'semantic.feeds.include_free' => ['type' => 'toggle', 'label' => 'Include free products in the product feeds', 'default' => false,
                'hint' => 'Google Merchant and ACP ask for a price above zero, so free products are left out. Turn it on only if the channel accepts them.'],
            'semantic.feeds.google_category' => ['type' => 'text', 'label' => 'Google product category', 'hint' => 'Optional. The same one for the whole shop, for example 436.', 'default' => '', 'rules' => 'max:200', 'width' => 'md'],
        ]],
    ],
];
