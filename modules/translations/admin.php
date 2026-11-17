<?php
/**
 * AIFEC — traducciones en el panel: permisos. No añade ninguna entrada al menú (el menú tiene un tope): la pantalla se abre desde
 * «Ajustes» → «Traducciones», que lleva el enlace arriba del todo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'caps' => [
        'translations.view' => 'See what is translated in each language',
        'translations.edit' => 'Translate the texts of the shop and approve the AI translations',
    ],
    'menu' => [
        ['group' => 'settings', 'items' => [
            ['label' => 'Translations', 'settings' => 'translations', 'cap' => 'translations.edit', 'order' => 90],
        ]],
    ],
];
