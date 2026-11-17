<?php
/**
 * AIFEC — ajustes de la capa de datos: una pestaña por destino, cada uno con su categoría de consentimiento, y el interruptor de los
 * eventos del servidor (nace apagado).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

$categories = ['statistics' => 'Statistics', 'marketing' => 'Marketing', 'preferences' => 'Preferences', 'necessary' => 'Necessary (no consent needed)'];

return [
    'title' => 'Measurement',
    'description' => 'Analytics and advertising tags, loaded only after the visitor agrees',
    'icon' => 'chart',
    'cap' => 'datalayer.edit',
    'help' => "Every tag is loaded only after the visitor accepts its category in the cookie notice. Until then, nothing from a third party is downloaded.\nUse Tag Manager or the direct tags, not both for the same service, or visits are counted twice.\nPrices in the events go without tax, as Google Analytics asks, unless you turn on the option. The tax goes in its own field.\nServer events (purchases and refunds) are off. When you turn them on, only what happens from then on is sent, and only for visitors who accepted the cookies of that service. Old orders are never sent.\nThe identifiers are checked: one that does not look like a real one is ignored.\nIn demo shops you can review these settings, but external tags and custom scripts are never loaded and server events are never sent.",
    'tabs' => [
        'dlgeneral' => ['label' => 'General', 'fields' => [
            'datalayer.enabled' => ['type' => 'toggle', 'label' => 'Measure the shop', 'default' => true],
            'datalayer.value_with_tax' => ['type' => 'toggle', 'label' => 'Prices in the events include tax', 'default' => false],
            'datalayer.exclude_staff' => ['type' => 'toggle', 'label' => 'Do not measure people who are signed in to the panel', 'default' => true],
            'datalayer.server_events' => ['type' => 'toggle', 'label' => 'Send purchases and refunds from the server', 'hint' => 'Off by default. Only what happens after you turn it on is sent.', 'default' => false],
        ]],
        'dlgtm' => ['label' => 'Tag Manager', 'fields' => [
            'datalayer.gtm_id' => ['type' => 'text', 'label' => 'Container ID', 'placeholder' => 'GTM-XXXXXXX', 'rules' => 'nullable|regex:/^GTM-[A-Z0-9]{4,12}$/', 'width' => 'md'],
            'datalayer.gtm_category' => ['type' => 'select', 'label' => 'Needs consent for', 'options' => $categories, 'default' => 'statistics'],
        ]],
        'dlga4' => ['label' => 'Google Analytics', 'fields' => [
            'datalayer.ga4_id' => ['type' => 'text', 'label' => 'Measurement ID', 'placeholder' => 'G-XXXXXXXXXX', 'rules' => 'nullable|regex:/^G-[A-Z0-9]{4,14}$/', 'width' => 'md'],
            'datalayer.ga4_category' => ['type' => 'select', 'label' => 'Needs consent for', 'options' => $categories, 'default' => 'statistics'],
            'datalayer.ga4_api_secret' => ['type' => 'secret', 'label' => 'API secret (server events)'],
        ]],
        'dlads' => ['label' => 'Google Ads', 'fields' => [
            'datalayer.ads_id' => ['type' => 'text', 'label' => 'Conversion ID', 'placeholder' => 'AW-123456789', 'rules' => 'nullable|regex:/^AW-[0-9]{6,14}$/', 'width' => 'md'],
            'datalayer.ads_label' => ['type' => 'text', 'label' => 'Purchase conversion label', 'rules' => 'nullable|regex:/^[A-Za-z0-9_-]{4,40}$/', 'width' => 'md'],
            'datalayer.ads_enhanced' => ['type' => 'toggle', 'label' => 'Enhanced conversions (email and phone, hashed)', 'hint' => 'Only sent if the visitor accepted marketing cookies.', 'default' => false],
            'datalayer.ads_category' => ['type' => 'select', 'label' => 'Needs consent for', 'options' => $categories, 'default' => 'marketing'],
        ]],
        'dlmeta' => ['label' => 'Meta Pixel', 'fields' => [
            'datalayer.meta_pixel' => ['type' => 'text', 'label' => 'Pixel ID', 'rules' => 'nullable|regex:/^[0-9]{6,20}$/', 'width' => 'md'],
            'datalayer.meta_category' => ['type' => 'select', 'label' => 'Needs consent for', 'options' => $categories, 'default' => 'marketing'],
            'datalayer.meta_token' => ['type' => 'secret', 'label' => 'Access token (server events)'],
            'datalayer.meta_version' => ['type' => 'text', 'label' => 'API version', 'placeholder' => 'v21.0', 'rules' => 'nullable|regex:/^v[0-9]{1,2}\.[0-9]$/', 'width' => 'sm', 'hint' => 'Meta changes it from time to time.'],
            'datalayer.meta_test_code' => ['type' => 'text', 'label' => 'Test event code', 'rules' => 'nullable|regex:/^[A-Za-z0-9_-]{1,40}$/', 'width' => 'md'],
        ]],
        'dltiktok' => ['label' => 'TikTok', 'fields' => [
            'datalayer.tiktok_pixel' => ['type' => 'text', 'label' => 'Pixel ID', 'rules' => 'nullable|regex:/^[A-Za-z0-9]{6,30}$/', 'width' => 'md'],
            'datalayer.tiktok_category' => ['type' => 'select', 'label' => 'Needs consent for', 'options' => $categories, 'default' => 'marketing'],
        ]],
        'dluet' => ['label' => 'Microsoft Ads', 'fields' => [
            'datalayer.uet_id' => ['type' => 'text', 'label' => 'UET tag ID', 'rules' => 'nullable|regex:/^[0-9]{5,12}$/', 'width' => 'md'],
            'datalayer.uet_category' => ['type' => 'select', 'label' => 'Needs consent for', 'options' => $categories, 'default' => 'marketing'],
        ]],
        'dlcustom' => ['label' => 'Your own code', 'fields' => [
            'datalayer.custom_preferences' => ['type' => 'code', 'label' => 'Code for visitors who accepted preferences', 'rules' => 'nullable|max:20000'],
            'datalayer.custom_statistics' => ['type' => 'code', 'label' => 'Code for visitors who accepted statistics', 'rules' => 'nullable|max:20000'],
            'datalayer.custom_marketing' => ['type' => 'code', 'label' => 'Code for visitors who accepted marketing', 'rules' => 'nullable|max:20000'],
        ]],
    ],
];
