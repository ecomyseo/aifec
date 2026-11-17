<?php
/**
 * AIFEC — GM coming soon: ajustes. Las claves de pestaña llevan las iniciales del plugin y las de ajuste, su zona.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Coming soon',
    'description' => 'Products that are visible but cannot be bought yet',
    'icon' => 'clock',
    'cap' => 'gmsoon.edit',
    'help' => "A product is marked «coming soon» in the Coming soon tab of its page in the panel.\nVisitors can leave their email once per product and are told once, when it is available.\nThe requests are deleted after 90 days.\nThe empty texts use the standard wording, which is already translated.",
    'tabs' => [
        'gpsgeneral' => ['label' => 'Texts', 'fields' => [
            'gm_proximamente.badge' => ['type' => 'text', 'label' => 'Badge text', 'hint' => 'Shown next to the price. Empty: «Coming soon».', 'default' => '', 'rules' => 'max:60'],
            'gm_proximamente.intro' => ['type' => 'text', 'label' => 'Text of the form', 'default' => '', 'rules' => 'max:200'],
        ]],
        'gpslimits' => ['label' => 'Limits', 'fields' => [
            'gm_proximamente.rate_per_hour' => ['type' => 'number', 'label' => 'Requests per hour and address', 'default' => 5, 'rules' => 'int|min:1|max:100', 'width' => 'sm'],
            'gm_proximamente.url' => ['type' => 'text', 'label' => 'Base address of the form', 'hint' => 'Without the domain. Changing it takes effect after clearing the cache.', 'default' => 'proximamente', 'rules' => 'max:60'],
        ]],
    ],
];
