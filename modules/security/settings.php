<?php
/**
 * AIFEC — ajustes del módulo de seguridad. La ruta propia del panel está en los ajustes generales, pestaña «Security».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Security',
    'description' => 'Passkeys, the security audit and the notice of new versions',
    'icon' => 'shield',
    'cap' => 'security.view',
    'help' => "The address of the admin panel is set in Settings, General, Security.\nThe audit and the version check run by themselves, every day.",
    'tabs' => [
        'sepasskeys' => ['label' => 'Passkeys and updates', 'fields' => [
            'security.passkeys' => ['type' => 'toggle', 'label' => 'Allow signing in with a passkey', 'default' => true],
            'security.update_check' => ['type' => 'toggle', 'label' => 'Tell me when a new version of AIFEC is out', 'default' => true,
                'hint' => 'Once a day the shop asks GitHub for the latest release. Nothing is installed.'],
        ]],
    ],
];
