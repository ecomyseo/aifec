<?php
/**
 * AIFEC — ajustes generales del sitio. Todos son por sitio: cada tienda (también cada demo en su subcarpeta) tiene los suyos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'General',
    'description' => 'Shop identity, languages, currency, addresses and performance',
    'icon' => 'settings',
    'cap' => 'settings.edit',
    'order' => 0,
    'tabs' => [
        'coidentity' => ['label' => 'Identity', 'fields' => [
            'store.name' => ['type' => 'text', 'label' => 'Shop name', 'rules' => 'required|max:120', 'default' => 'AIFEC'],
            'store.logo' => ['type' => 'image', 'label' => 'Logo', 'default' => 0, 'hint' => 'Shown in the header instead of the shop name. Leave it empty to show the name.'],
            'store.icon' => ['type' => 'image', 'label' => 'Site icon', 'default' => 0, 'hint' => 'The small icon of the browser tab. Square, 180 px or more.'],
            'store.legal_name' => ['type' => 'text', 'label' => 'Legal name', 'rules' => 'max:160', 'default' => '', 'hint' => 'The one printed on invoices and legal pages.'],
            'store.vat_id' => ['type' => 'text', 'label' => 'Tax ID', 'rules' => 'max:40', 'default' => '', 'width' => 'md'],
            'store.email' => ['type' => 'email', 'label' => 'Contact email', 'default' => ''],
            'store.phone' => ['type' => 'text', 'label' => 'Phone', 'rules' => 'max:40', 'default' => '', 'width' => 'md'],
            'store.address' => ['type' => 'textarea', 'label' => 'Address', 'rows' => 3, 'rules' => 'max:400', 'default' => '',
                'hint' => 'Free text for invoices, emails and the footer. If you fill in the fields below, it is written from them.'],
            'store.address_street' => ['type' => 'text', 'label' => 'Street and number', 'rules' => 'max:160', 'default' => ''],
            'store.address_postcode' => ['type' => 'text', 'label' => 'Postcode', 'rules' => 'max:12', 'default' => '', 'width' => 'sm'],
            'store.address_city' => ['type' => 'text', 'label' => 'City', 'rules' => 'max:100', 'default' => '', 'width' => 'md'],
            'store.address_region' => ['type' => 'text', 'label' => 'Province or region', 'rules' => 'max:100', 'default' => '', 'width' => 'md'],
            'store.address_country' => ['type' => 'text', 'label' => 'Country (two letters)', 'rules' => 'max:2|regex:/^[A-Za-z]{2}$/', 'default' => '', 'width' => 'sm',
                'hint' => 'For example ES. Empty: the country of the shop for taxes. Search engines and assistants read the address from these fields.'],
        ]],
        'coregion' => ['label' => 'Languages and currency', 'fields' => [
            'i18n.languages' => ['type' => 'tags', 'label' => 'Languages', 'rules' => 'max:8', 'default' => [], 'placeholder' => 'es, en',
                'hint' => 'Two-letter codes separated by commas. The first one is the main language and has no prefix in the addresses.'],
            'shop.currency' => ['type' => 'select', 'label' => 'Currency', 'default' => 'EUR', 'translate' => false,
                'options' => ['EUR' => 'EUR — Euro', 'USD' => 'USD — US dollar', 'GBP' => 'GBP — Pound sterling', 'MXN' => 'MXN — Peso mexicano', 'COP' => 'COP — Peso colombiano', 'CLP' => 'CLP — Peso chileno', 'ARS' => 'ARS — Peso argentino']],
            'shop.currency_symbol' => ['type' => 'text', 'label' => 'Currency symbol', 'rules' => 'required|max:4', 'default' => '€', 'width' => 'sm'],
        ]],
        'courls' => ['label' => 'Addresses', 'fields' => [
            'urls.trailing_slash' => ['type' => 'toggle', 'label' => 'End the addresses with a slash', 'default' => false,
                'hint' => 'Keep the same choice the shop already had: the other form redirects to it.'],
        ]],
        'coperformance' => ['label' => 'Performance', 'fields' => [
            'cache.pages' => ['type' => 'toggle', 'label' => 'Page cache', 'default' => true, 'hint' => 'Visitors without a cart or a session get a stored copy of each page.'],
            'assets.minify_js' => ['type' => 'toggle', 'label' => 'Minify the JavaScript', 'default' => true, 'hint' => 'Publishes the scripts without comments or extra spaces. Off: the original files.'],
            'assets.minify_css' => ['type' => 'toggle', 'label' => 'Minify the CSS', 'default' => true, 'hint' => 'Writes the style sheets without comments or extra characters. Off: the original files.'],
        ]],
        'cosecurity' => ['label' => 'Security', 'fields' => [
            'security.admin_path' => ['type' => 'text', 'label' => 'Address of the admin panel', 'default' => '', 'rules' => 'max:40|regex:/^[a-z0-9][a-z0-9-]{4,38}[a-z0-9]$/', 'width' => 'md',
                'hint' => 'Six to forty letters, numbers or hyphens, for example «panel-4k7x». The panel opens there and «/admin» answers «not found». Write it down before saving.'],
            'api.rate_per_minute' => ['type' => 'number', 'label' => 'API requests per minute', 'default' => 120, 'rules' => 'min:0|max:6000', 'hint' => 'Per address. Zero means no limit.'],
            'security.csp' => ['type' => 'select', 'label' => 'Content security policy', 'default' => 'strict',
                'options' => ['strict' => 'Strict: only the scripts of the shop', 'relaxed' => 'Relaxed: scripts from any secure site', 'off' => 'Off'],
                'hint' => 'What the pages may load and run. Strict stops a script injected into a page. It relaxes by itself while a tag manager or a pixel is set up in Measurement.'],
            'security.csp_sources' => ['type' => 'textarea', 'label' => 'Other sites the pages may load from', 'rows' => 3, 'rules' => 'max:1000', 'default' => '',
                'hint' => 'One per line, starting with https://. Only what you have added yourself: the modules declare theirs.'],
            'security.trusted_proxies' => ['type' => 'textarea', 'label' => 'Trusted proxies', 'rows' => 3, 'rules' => 'max:1000', 'default' => '',
                'hint' => 'Addresses or ranges (10.0.0.0/8, 2001:db8::/32), one per line. Empty if the server is reached directly: then the address of the visitor is the one of the connection.'],
            'security.proxy_header' => ['type' => 'select', 'label' => 'Header with the visitor address', 'default' => 'x-forwarded-for',
                'options' => ['x-forwarded-for' => 'X-Forwarded-For', 'cf-connecting-ip' => 'CF-Connecting-IP (Cloudflare)'],
                'hint' => 'Only read when the connection comes from a trusted proxy.'],
        ]],
    ],
];
