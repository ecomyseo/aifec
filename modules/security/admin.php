<?php
/**
 * AIFEC — seguridad en el panel: permiso, menú y la pieza del inicio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Security\SecurityAdmin;

return [
    'caps' => [
        'security.view' => 'See the security audit and manage your passkeys',
    ],
    'menu' => [
        ['group' => 'system', 'items' => [
            ['label' => 'Security audit', 'url' => '/admin/security', 'cap' => 'security.view', 'order' => 40, 'tabs' => 'security', 'tab' => 'Audit', 'menu_label' => 'Security'],
            ['label' => 'Passkeys', 'url' => '/admin/security/passkeys', 'cap' => 'security.view', 'order' => 41, 'tabs' => 'security', 'tab' => 'Passkeys', 'menu_label' => 'Security'],
            ['label' => 'Security settings', 'settings' => 'security', 'cap' => 'security.view', 'order' => 42, 'tabs' => 'security', 'tab' => 'Settings', 'menu_label' => 'Security'],
        ]],
    ],
    'dashboard' => [
        ['id' => 'security.last', 'cap' => 'security.view', 'size' => 4, 'order' => 65, 'render' => [SecurityAdmin::class, 'widget']],
    ],
];
