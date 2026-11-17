<?php
/**
 * AIFEC — ajustes del correo: modo de seguridad, remitente, transporte y conservación. Todos son por sitio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

return [
    'title' => 'Email',
    'description' => 'Sender, sending method and the on-hold switch for outgoing email',
    'icon' => 'mail',
    'cap' => 'mail.edit',
    'help' => "On hold: every email is saved in the log and nothing is sent. Release them one by one from the log.\nLive: emails are sent as soon as they are created.\nTest: everything is sent, but only to the test address, with the real recipient written in the subject.\nPassword reset and test emails always go out, even on hold, unless you turn that off.",
    'tabs' => [
        'mlmode' => ['label' => 'Mode', 'fields' => [
            'mail.mode' => ['type' => 'select', 'label' => 'Email mode', 'default' => 'hold',
                'options' => ['hold' => 'On hold: nothing is sent', 'live' => 'Live: send everything', 'redirect' => 'Test: send everything to one address'],
                'hint' => 'New shops start on hold so that nothing goes out by mistake.'],
            'mail.redirect_to' => ['type' => 'email', 'label' => 'Test address', 'default' => '', 'rules' => 'nullable|email',
                'hint' => 'Used only in test mode: every email goes here.'],
            'mail.system_always' => ['type' => 'toggle', 'label' => 'Always send password resets and test emails', 'default' => true,
                'hint' => 'Without this nobody could recover access while the email is on hold.'],
            'mail.test_link' => ['type' => 'html', 'label' => '', 'html' => static function ($values, App $app): string {
                return '<a class="af-btn" href="' . e($app->url()->plain('/admin/mail/templates')) . '">' . Admin::icon('mail') . e(t('Send a test email')) . '</a>';
            }],
        ]],
        'mlsender' => ['label' => 'Sender', 'fields' => [
            'mail.from_email' => ['type' => 'email', 'label' => 'Sender email', 'default' => '', 'rules' => 'nullable|email',
                'hint' => 'If empty, the shop contact email is used.'],
            'mail.from_name' => ['type' => 'text', 'label' => 'Sender name', 'default' => '', 'rules' => 'max:120',
                'hint' => 'If empty, the shop name is used.'],
            'mail.reply_to' => ['type' => 'email', 'label' => 'Reply to', 'default' => '', 'rules' => 'nullable|email'],
        ]],
        'mlsmtp' => ['label' => 'Sending method', 'fields' => [
            'mail.transport' => ['type' => 'select', 'label' => 'Method', 'default' => 'mail',
                'options' => ['smtp' => 'SMTP server', 'mail' => 'The server mail function', 'log' => 'Save to files (nothing is sent)'],
                'hint' => 'Saving to files is for demos and local work.'],
            'mail.smtp_host' => ['type' => 'text', 'label' => 'SMTP server', 'default' => '', 'rules' => 'max:190', 'placeholder' => 'smtp.example.com'],
            'mail.smtp_port' => ['type' => 'number', 'label' => 'SMTP port', 'default' => 587, 'rules' => 'int|min:1|max:65535', 'width' => 'sm'],
            'mail.smtp_encryption' => ['type' => 'select', 'label' => 'Encryption', 'default' => 'tls',
                'options' => ['tls' => 'STARTTLS (usually port 587)', 'ssl' => 'SSL/TLS (usually port 465)', 'none' => 'None']],
            'mail.smtp_user' => ['type' => 'text', 'label' => 'SMTP user', 'default' => '', 'rules' => 'max:190'],
            'mail.smtp_password' => ['type' => 'secret', 'label' => 'SMTP password'],
            'mail.smtp_timeout' => ['type' => 'number', 'label' => 'Timeout', 'default' => 15, 'rules' => 'int|min:3|max:120', 'suffix' => 'seconds', 'width' => 'sm'],
        ]],
        'mlkeep' => ['label' => 'Log', 'fields' => [
            'mail.keep_days' => ['type' => 'number', 'label' => 'Days to keep the content of each email', 'default' => 90, 'rules' => 'int|min:0|max:3650', 'width' => 'sm',
                'hint' => 'After that the content is emptied and the log line stays. Zero keeps everything.'],
        ]],
    ],
];
