<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Semantic\AuditAdmin;

/*
 * Datos para máquinas en el panel: el permiso para ver la auditoría y los robots, las dos entradas del grupo «Inteligencia
 * artificial» del menú y la pieza «AIFEC» del inicio.
 */
return [
    'caps' => [
        'semantic.view' => 'See the AIFEC audit and the bots and assistants',
    ],
    'menu' => [
        ['group' => 'ai', 'items' => [
            ['label' => 'AIFEC audit', 'url' => '/admin/semantic/audit', 'cap' => 'semantic.view', 'order' => 30],
            ['label' => 'Bots and assistants', 'url' => '/admin/semantic/bots', 'cap' => 'semantic.view', 'order' => 40],
        ]],
    ],
    'dashboard' => [
        ['id' => 'semantic.aifec', 'cap' => 'semantic.view', 'size' => 4, 'order' => 55, 'render' => [AuditAdmin::class, 'widget']],
    ],
];
