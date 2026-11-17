<?php
/**
 * AIFEC — panel del módulo de tareas: permisos, menú y el recurso de solo lectura «jobs».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Jobs\JobsAdmin;

return [
    'caps' => [
        'jobs.view' => 'See the background tasks',
        'jobs.edit' => 'Retry and run background tasks',
    ],
    'menu' => [
        ['group' => 'system', 'items' => [
            ['label' => 'Background tasks', 'resource' => 'jobs', 'cap' => 'jobs.view', 'order' => 40, 'tabs' => 'tasks', 'tab' => 'In the background', 'menu_label' => 'Tasks'],
            ['label' => 'Scheduled tasks', 'url' => '/admin/jobs/schedule', 'cap' => 'jobs.view', 'order' => 41, 'tabs' => 'tasks', 'tab' => 'On a schedule', 'menu_label' => 'Tasks'],
        ]],
    ],
    'resources' => [
        'jobs' => [
            'table' => 'jobs', 'title' => 'Background tasks', 'singular' => 'Job', 'label_field' => 'type', 'cap' => 'jobs',
            'readonly' => true, 'can_create' => false, 'can_delete' => false, 'timestamps' => false,
            'list' => [
                'columns' => [
                    'type' => ['label' => 'Type', 'link' => true, 'sort' => true],
                    'status' => ['label' => 'Status', 'type' => 'badge', 'sort' => true, 'map' => [
                        'done' => ['Done', 'ok'],
                        'pending' => ['Pending', 'info'],
                        'running' => ['Running', 'info'],
                        'failed' => ['Failed', 'bad'],
                    ]],
                    'attempts' => ['label' => 'Attempts', 'type' => 'int', 'hide_sm' => true],
                    'run_at' => ['label' => 'Due', 'type' => 'datetime', 'sort' => true, 'hide_sm' => true],
                    'finished_at' => ['label' => 'Finished', 'type' => 'datetime', 'sort' => true, 'hide_sm' => true],
                    'error' => ['label' => 'Error', 'limit' => 80, 'hide_sm' => true],
                ],
                'search' => ['type'],
                'filters' => ['status' => ['label' => 'Status', 'options' => [
                    'pending' => 'Pending',
                    'running' => 'Running',
                    'done' => 'Done',
                    'failed' => 'Failed',
                ]]],
                'sort' => ['id', 'desc'],
                'export' => false,
            ],
            'show' => [JobsAdmin::class, 'show'],
            'help' => "Jobs wait here until a background process picks them up.\nA job that fails is tried again a few times before it is marked as failed.",
        ],
    ],
];
