<?php
/**
 * AIFEC — ajustes del módulo de medios: peso máximo de una subida y calidad de las variantes WebP.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Media',
    'description' => 'Upload limit and quality of the optimised images',
    'icon' => 'image',
    'cap' => 'media.edit',
    'tabs' => [
        'mdimages' => ['label' => 'Images', 'fields' => [
            'media.max_mb' => ['type' => 'number', 'label' => 'Largest file (MB)', 'default' => 8, 'rules' => 'required|int|min:1|max:64', 'width' => 'sm', 'hint' => 'Bigger files are refused when they are uploaded.'],
            'media.webp_quality' => ['type' => 'number', 'label' => 'WebP quality', 'default' => 82, 'rules' => 'required|int|min:50|max:95', 'width' => 'sm', 'hint' => 'Between 50 and 95. Higher is sharper and heavier.'],
        ]],
    ],
];
