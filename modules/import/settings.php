<?php
/**
 * AIFEC — ajustes del módulo de migración: valores de partida de una migración nueva y tamaño de cada tanda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Migration',
    'description' => 'Starting values for a new migration and the size of each batch',
    'icon' => 'download',
    'cap' => 'import.run',
    'tabs' => [
        'imdefaults' => ['label' => 'Defaults', 'fields' => [
            'import.keep_urls' => ['type' => 'toggle', 'label' => 'Keep the old addresses', 'default' => true, 'hint' => 'The old addresses of products and pages keep working.'],
            'import.import_images' => ['type' => 'toggle', 'label' => 'Bring the images', 'default' => true],
            'import.overwrite_local' => ['type' => 'toggle', 'label' => 'Overwrite what was edited here', 'default' => false, 'hint' => 'Off: a record changed here after being brought is left alone.'],
        ]],
        'imbatches' => ['label' => 'Batches', 'fields' => [
            'import.step_records' => ['type' => 'number', 'label' => 'Records per batch', 'default' => 50, 'rules' => 'required|int|min:1|max:500', 'width' => 'sm'],
            'import.step_seconds' => ['type' => 'number', 'label' => 'Seconds per batch', 'default' => 20, 'rules' => 'required|int|min:1|max:300', 'width' => 'sm', 'hint' => 'Lower it on a slow server.'],
        ]],
    ],
];
