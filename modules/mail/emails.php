<?php
/**
 * AIFEC — plantillas de correo de este módulo (cada módulo declara las suyas en su emails.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

return [
    'admin_reset' => [
        'sensitive' => true,
        'subject' => 'Choose a new password for {shop}',
        'view' => 'mail::emails/admin_reset',
        'system' => true,
        'description' => 'Link to choose a new panel password',
        // La despedida de serie (el último párrafo que pinta la vista): la tienda puede cambiarla en «Plantillas de correo».
        'farewell' => 'If you did not ask for it, you can ignore this message: your password stays the same.',
        'sample' => ['name' => 'Ana', 'url' => 'https://example.com/admin/reset/abc', 'minutes' => 60],
    ],
    'mail_test' => [
        'subject' => 'Test email from {shop}',
        'view' => 'mail::emails/mail_test',
        'system' => true,
        'description' => 'Test message to check that email works',
        'sample' => [],
    ],
];
