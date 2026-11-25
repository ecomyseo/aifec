<?php
/**
 * AIFEC — plantillas de correo de clientes: bienvenida, verificación, nueva contraseña, enlace de entrada y aviso de cuenta existente.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

// Las que permiten recuperar el acceso (verificar, nueva contraseña, enlace de entrada) son del sistema: salen aunque el correo esté retenido.
return [
    'customer_welcome' => [
        'sensitive' => true,
        'subject' => 'Welcome to {shop}',
        'view' => 'customers::emails/customer_welcome',
        'system' => false,
        'description' => 'Welcome message sent when a customer creates an account',
        'sample' => ['name' => 'Ana', 'verify_url' => 'https://example.com/mi-cuenta/verificar/abc', 'account_url' => 'https://example.com/mi-cuenta', 'days' => 7],
    ],
    'customer_verify' => [
        'sensitive' => true,
        'subject' => 'Confirm your email address for {shop}',
        'view' => 'customers::emails/customer_verify',
        'system' => true,
        'description' => 'Link to confirm the email address of an account',
        'sample' => ['name' => 'Ana', 'url' => 'https://example.com/mi-cuenta/verificar/abc', 'days' => 7],
    ],
    'customer_reset' => [
        'sensitive' => true,
        'subject' => 'Choose a new password for {shop}',
        'view' => 'customers::emails/customer_reset',
        'system' => true,
        'description' => 'Link to choose a new password for a customer account',
        'sample' => ['name' => 'Ana', 'url' => 'https://example.com/mi-cuenta/restablecer/abc', 'minutes' => 60],
    ],
    'customer_magic_link' => [
        'sensitive' => true,
        'subject' => 'Your sign-in link for {shop}',
        'view' => 'customers::emails/customer_magic_link',
        'system' => true,
        'description' => 'One-use link to sign in without a password',
        'sample' => ['name' => 'Ana', 'url' => 'https://example.com/mi-cuenta/entrar/abc', 'minutes' => 15],
    ],
    'customer_exists' => [
        'subject' => 'Someone tried to register with your email at {shop}',
        'view' => 'customers::emails/customer_exists',
        'system' => false,
        'description' => 'Notice to the owner of an account when someone tries to register with the same email',
        'sample' => ['name' => 'Ana', 'login_url' => 'https://example.com/mi-cuenta', 'reset_url' => 'https://example.com/mi-cuenta/recuperar'],
    ],
];
