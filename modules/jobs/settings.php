<?php
/**
 * AIFEC — ajustes del módulo de tareas: cuánto tiempo se guardan los trabajos terminados.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'title' => 'Background tasks',
    'description' => 'How long finished jobs are kept',
    'icon' => 'clock',
    'cap' => 'jobs.edit',
    'tabs' => [
        'jbretention' => ['label' => 'Retention', 'fields' => [
            'jobs.keep_done_days' => ['type' => 'number', 'label' => 'Days to keep finished jobs', 'default' => 7, 'rules' => 'required|int|min:1|max:365', 'width' => 'sm'],
            'jobs.keep_failed_days' => ['type' => 'number', 'label' => 'Days to keep failed jobs', 'default' => 30, 'rules' => 'required|int|min:1|max:365', 'width' => 'sm', 'hint' => 'Failed jobs stay longer so there is time to look at them.'],
        ]],
    ],
];
