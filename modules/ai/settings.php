<?php
/**
 * AIFEC — ajustes del módulo de IA: interruptor general, un proveedor y un modelo por nivel, y el tope de gasto.
 *
 * Las claves se guardan cifradas y nunca vuelven a la pantalla. El modelo es un campo de texto: vacío = gemini-flash-latest.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

$providers = ['fake' => 'Simulated (no cost, for tests)', 'gemini' => 'Google Gemini', 'openai' => 'OpenAI-compatible'];
$modelHint = 'Gemini models you can write: gemini-flash-latest, gemini-flash-lite-latest, gemini-3-flash-preview, gemini-3-pro-preview. Empty: gemini-flash-latest.';

return [
    'title' => 'Artificial intelligence',
    'description' => 'Providers, daily spending cap and what the AI may write',
    'icon' => 'sparkles',
    'cap' => 'ai.edit',
    'help' => "The AI proposes and a person approves: nothing it writes is published on its own.\nThe shop works the same with the AI off.\nEach tier (economy, standard, advanced) has its own provider, model and key. Short jobs such as meta tags and translations use the economy tier; long descriptions use the standard one.\nThe daily cap counts the estimated cost of the day (UTC). Set the price per million tokens of each model so the estimate is real: with a price of zero nothing is counted.",
    'tabs' => [
        'aigeneral' => ['label' => 'General', 'fields' => [
            'ai.enabled' => ['type' => 'toggle', 'label' => 'Turn the AI on', 'default' => false],
            'ai.show_notice' => ['type' => 'toggle', 'label' => 'Tell visitors that part of the text was written with AI help', 'default' => false],
        ]],
        'aimodels' => ['label' => 'Provider and API key', 'fields' => [
            'ai.h_economy' => ['type' => 'heading', 'label' => 'Economy tier'],
            'ai.economy.provider' => ['type' => 'select', 'label' => 'Provider', 'options' => $providers, 'default' => 'gemini'],
            'ai.economy.model' => ['type' => 'text', 'label' => 'Model', 'hint' => $modelHint, 'default' => ''],
            'ai.economy.base_url' => ['type' => 'url', 'label' => 'Base address (OpenAI-compatible only)', 'default' => ''],
            'ai.economy.key' => ['type' => 'secret', 'label' => 'API key'],
            'ai.h_standard' => ['type' => 'heading', 'label' => 'Standard tier'],
            'ai.standard.provider' => ['type' => 'select', 'label' => 'Provider', 'options' => $providers, 'default' => 'gemini'],
            'ai.standard.model' => ['type' => 'text', 'label' => 'Model', 'hint' => $modelHint, 'default' => ''],
            'ai.standard.base_url' => ['type' => 'url', 'label' => 'Base address (OpenAI-compatible only)', 'default' => ''],
            'ai.standard.key' => ['type' => 'secret', 'label' => 'API key'],
            'ai.h_advanced' => ['type' => 'heading', 'label' => 'Advanced tier'],
            'ai.advanced.provider' => ['type' => 'select', 'label' => 'Provider', 'options' => $providers, 'default' => 'gemini'],
            'ai.advanced.model' => ['type' => 'text', 'label' => 'Model', 'hint' => $modelHint, 'default' => ''],
            'ai.advanced.base_url' => ['type' => 'url', 'label' => 'Base address (OpenAI-compatible only)', 'default' => ''],
            'ai.advanced.key' => ['type' => 'secret', 'label' => 'API key'],
        ]],
        'aispend' => ['label' => 'Spending', 'fields' => [
            // Se guarda en céntimos (200), pero se escribe y se enseña en unidades de la moneda de la tienda («2,00»): el tipo «money» convierte al leer y al guardar.
            'ai.daily_limit_cents' => ['type' => 'money', 'label' => 'Daily cap', 'hint' => 'When the estimated spending of the day reaches it, the AI stops until tomorrow. Zero blocks it.', 'default' => 200, 'rules' => 'required|min:0', 'width' => 'sm'],
            'ai.daily_limit_calls' => ['type' => 'number', 'label' => 'Daily cap in calls', 'hint' => 'The AI also stops when it has made this many calls in a day, even if the prices below are not set. Zero blocks it.', 'default' => 500, 'rules' => 'int|min:0', 'width' => 'sm'],
            'ai.monthly_limit_cents' => ['type' => 'money', 'label' => 'Monthly spending cap', 'hint' => 'Optional cap for the calendar month in UTC. Zero means no monthly cap. Daily caps still apply.', 'default' => 0, 'rules' => 'min:0|max:1000000000', 'width' => 'sm'],
            'ai.daily_limit_tokens' => ['type' => 'number', 'label' => 'Daily token cap', 'hint' => 'Input and output tokens reported by the provider. Zero means no token cap. The call that reaches the cap is recorded; subsequent calls stop.', 'default' => 0, 'rules' => 'int|min:0', 'width' => 'sm'],
            'ai.timeout' => ['type' => 'number', 'label' => 'Seconds to wait for an answer', 'default' => 30, 'rules' => 'int|min:5|max:120', 'width' => 'sm'],
            'ai.h_prices' => ['type' => 'heading', 'label' => 'Price per million tokens, in cents'],
            'ai.economy.price_in' => ['type' => 'number', 'label' => 'Economy: input', 'default' => 0, 'rules' => 'int|min:0', 'width' => 'sm'],
            'ai.economy.price_out' => ['type' => 'number', 'label' => 'Economy: output', 'default' => 0, 'rules' => 'int|min:0', 'width' => 'sm'],
            'ai.standard.price_in' => ['type' => 'number', 'label' => 'Standard: input', 'default' => 0, 'rules' => 'int|min:0', 'width' => 'sm'],
            'ai.standard.price_out' => ['type' => 'number', 'label' => 'Standard: output', 'default' => 0, 'rules' => 'int|min:0', 'width' => 'sm'],
            'ai.advanced.price_in' => ['type' => 'number', 'label' => 'Advanced: input', 'default' => 0, 'rules' => 'int|min:0', 'width' => 'sm'],
            'ai.advanced.price_out' => ['type' => 'number', 'label' => 'Advanced: output', 'default' => 0, 'rules' => 'int|min:0', 'width' => 'sm'],
        ]],
    ],
];
