<?php
/**
 * AIFEC — ajustes del módulo de herramientas: modo mantenimiento, copias y registro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

// Un mensaje de mantenimiento por cada idioma de la tienda (el del idioma de la visita; si falta, el del principal).
$languages = App::hasCurrent() ? App::current()->languages() : ['es'];
$messages = [
    'tools.maintenance' => ['type' => 'toggle', 'label' => 'Maintenance mode', 'default' => false,
        'hint' => 'Visitors see a maintenance page. The panel and the payment notices keep working.'],
];
foreach ($languages as $lang) {
    $messages['tools.maintenance_text_' . $lang] = ['type' => 'textarea', 'label' => 'Message for visitors', 'rows' => 3, 'default' => '', 'rules' => 'max:600',
        'hint' => strtoupper((string) $lang)];
}

return [
    'title' => 'Tools',
    'description' => 'Maintenance mode, backups and log retention',
    'icon' => 'tool',
    'cap' => 'tools.edit',
    'help' => "Maintenance mode answers visitors with a 503 page and tells search engines to come back later.\nBackups are kept in the private folder of the site and are never reachable by their address.",    'tabs' => [
        'tlmaintenance' => ['label' => 'Maintenance', 'fields' => $messages],
        'tlbackups' => ['label' => 'Backups', 'fields' => [
            'tools.backup_auto' => ['type' => 'toggle', 'label' => 'Nightly backup', 'default' => true, 'hint' => 'A copy of the shop every night, checked when it is done.'],
            'tools.backup_content' => ['type' => 'select', 'label' => 'What the nightly backup contains', 'default' => 'full',
                'options' => ['full' => 'Complete: database, images and private files', 'db' => 'Only the database'],
                'hint' => 'Without the zip extension only the database is copied.'],
            'tools.keep_backups' => ['type' => 'number', 'label' => 'Backups to keep', 'default' => 7, 'rules' => 'required|int|min:1|max:60', 'width' => 'sm'],
        ]],
        'tllogs' => ['label' => 'Log', 'fields' => [
            'tools.keep_logs_days' => ['type' => 'number', 'label' => 'Days to keep the log files', 'default' => 30, 'rules' => 'required|int|min:1|max:365', 'width' => 'sm'],
        ]],
    ],
];
