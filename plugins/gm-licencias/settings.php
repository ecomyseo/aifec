<?php
/**
 * AIFEC — GM licences: ajustes. Las claves de pestaña llevan las iniciales del plugin y las de ajuste, su zona.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Licences',
    'description' => 'Keys for the software the shop sells and the API that checks them',
    'icon' => 'key',
    'cap' => 'gmlic.edit',
    'help' => "Mark a product as a licence in the Licences tab of its page: type, domains allowed and months of updates.\nThe programs check a key at POST /api/gm/licencia/verificar with the key and the domain, and free a domain at /api/gm/licencia/desactivar.\nThe signing key is only for the signed keys that work offline. It is stored encrypted and never shown again.",
    'tabs' => [
        'glsapi' => ['label' => 'Checking API', 'fields' => [
            'gm_licencias.rate_per_address' => ['type' => 'number', 'label' => 'Checks per minute and address', 'default' => 30, 'rules' => 'int|min:1|max:1000', 'width' => 'sm'],
            'gm_licencias.rate_per_key' => ['type' => 'number', 'label' => 'Checks per minute and key', 'default' => 10, 'rules' => 'int|min:1|max:1000', 'width' => 'sm'],
        ]],
        'glssigning' => ['label' => 'Signed keys', 'fields' => [
            'gm_licencias.signing_key' => ['type' => 'secret', 'label' => 'Signing key', 'hint' => 'The private key in PEM format (elliptic curve prime256v1). Empty: signed licences stay pending.'],
        ]],
    ],
];
