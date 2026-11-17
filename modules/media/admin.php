<?php
/**
 * AIFEC — panel del módulo de medios: permisos y entrada del menú (las pantallas son propias, ver routes.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'caps' => [
        'media.view' => 'See the media library',
        'media.edit' => 'Upload, change and delete media',
    ],
    'menu' => [
        ['group' => 'content', 'items' => [
            ['label' => 'Media', 'url' => '/admin/media', 'cap' => 'media.view', 'order' => 30],
        ]],
    ],
];
