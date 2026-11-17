<?php
/**
 * AIFEC — correo en el panel: permisos, menú y el registro de mensajes (solo lectura).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Mail\MailAdmin;

return [
    'caps' => [
        'mail.view' => 'See the email log and templates',
        'mail.edit' => 'Change the email settings, release, resend and test email',
    ],
    'menu' => [
        ['group' => 'settings', 'items' => [
            ['label' => 'Email', 'settings' => 'mail', 'cap' => 'mail.edit', 'order' => 80, 'tabs' => 'mail', 'tab' => 'Settings', 'menu_label' => 'Mail'],
            ['label' => 'Email templates', 'url' => '/admin/mail/templates', 'cap' => 'mail.view', 'order' => 81, 'tabs' => 'mail', 'tab' => 'Templates', 'menu_label' => 'Mail'],
            ['label' => 'Email log', 'resource' => 'mail', 'cap' => 'mail.view', 'order' => 82, 'tabs' => 'mail', 'tab' => 'Sending log', 'menu_label' => 'Mail'],
        ]],
    ],
    'resources' => [
        'mail' => [
            'table' => 'mail_messages', 'title' => 'Email log', 'singular' => 'Email', 'label_field' => 'subject', 'cap' => 'mail',
            'readonly' => true, 'can_create' => false, 'can_delete' => false, 'timestamps' => false,
            'list' => [
                'columns' => [
                    'created_at' => ['label' => 'Date', 'type' => 'datetime', 'sort' => true, 'link' => true],
                    'to_email' => ['label' => 'To', 'sort' => true],
                    'subject' => ['label' => 'Subject', 'limit' => 60, 'hide_sm' => true],
                    'template' => ['label' => 'Template', 'hide_sm' => true],
                    'status' => ['label' => 'Status', 'type' => 'badge', 'sort' => true, 'map' => [
                        'sent' => ['Sent', 'ok'], 'pending' => ['Pending', 'info'], 'held' => ['Held', 'warn'], 'failed' => ['Failed', 'bad'],
                    ]],
                ],
                'search' => ['to_email', 'subject'],
                'filters' => ['status' => ['label' => 'Status', 'options' => ['sent' => 'Sent', 'pending' => 'Pending', 'held' => 'Held', 'failed' => 'Failed']]],
                'sort' => ['id', 'desc'],
                'per_page' => 50,
            ],
            'show' => [MailAdmin::class, 'show'],
            'help' => 'Every email the shop creates is listed here, also the ones that were held and never sent.',
        ],
    ],
];
