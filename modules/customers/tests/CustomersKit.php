<?php
/**
 * AIFEC — apoyo de las pruebas de clientes: aplicación con transporte de correo en memoria y ayudas para preparar datos.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Mail\ArrayTransport;
use Aifec\Mail\Transport;
use Aifec\Modules\Customers\Customers;
use Aifec\Security\Passwords;

/**
 * Aplicación con el módulo de clientes (y el de correo, que necesita) y un ArrayTransport en lugar de la red.
 *
 * @param array<string,mixed> $settings
 *
 * @return array{0:App,1:ArrayTransport}
 */
function customersTestApp(array $settings = []): array
{
    // El correo es un módulo esencial (siempre cargado en un sitio real); aquí se pide a mano porque «customers» no lo declara en requires.
    $app = testApp(['modules' => ['customers', 'mail'], 'settings' => $settings + ['mail.mode' => 'live', 'mail.from_email' => 'tienda@tienda.test']]);
    $transport = new ArrayTransport();
    $app->bind('mail.transport', static function () use ($transport): Transport {
        return $transport;
    });
    $app->boot();

    return [$app, $transport];
}

function customersService(App $app): Customers
{
    $service = $app->service('customers');
    check($service instanceof Customers, 'el servicio customers es el del módulo');

    return $service;
}

/** @return array<string,mixed> */
function customersRow(App $app, int $id): array
{
    $row = $app->db()->row('SELECT * FROM {customers} WHERE id = ?', [$id]);
    check($row !== null, 'el cliente ' . $id . ' existe');

    return (array) $row;
}

function customersCount(App $app, string $table, string $where = '1 = 1', array $params = []): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {' . $table . '} WHERE ' . $where, $params);
}

/** Resumen de contraseña calculado una sola vez por texto: Argon2id es lento a propósito. */
function customersHash(string $password): string
{
    static $cache = [];
    if (!isset($cache[$password])) {
        $cache[$password] = Passwords::hash($password);
    }

    return $cache[$password];
}

/**
 * Cuenta ya creada y con el correo verificado. Devuelve su id.
 *
 * @param array<string,mixed> $extra columnas que pisan las de serie
 */
function customersMakeAccount(App $app, string $email = 'ana@cliente.test', string $password = 'clave-larga-1', array $extra = []): int
{
    $now = $app->clock()->sql();

    return $app->db()->insert('customers', $extra + [
        'email' => $email, 'password' => customersHash($password), 'guest' => 0, 'first_name' => 'Ana', 'last_name' => 'Ruiz',
        'group_id' => 1, 'locale' => 'es', 'active' => 1, 'marketing' => 0, 'orders_count' => 0, 'total_spent' => 0,
        'email_verified_at' => $now, 'created_at' => $now, 'updated_at' => $now,
    ]);
}

/**
 * Invitado (compró sin cuenta). Devuelve su id.
 *
 * @param array<string,mixed> $extra
 */
function customersMakeGuest(App $app, string $email = 'invitado@cliente.test', array $extra = []): int
{
    $now = $app->clock()->sql();

    return $app->db()->insert('customers', $extra + [
        'email' => $email, 'password' => null, 'guest' => 1, 'first_name' => 'Invitado', 'last_name' => null,
        'group_id' => 1, 'locale' => 'es', 'active' => 1, 'marketing' => 0, 'orders_count' => 0, 'total_spent' => 0,
        'created_at' => $now, 'updated_at' => $now,
    ]);
}

/** El mensaje completo de un correo enviado, con el cuerpo descodificado. */
function customersMailText(ArrayTransport $transport, int $index = -1): string
{
    $sent = $transport->sent;
    check($sent !== [], 'se ha enviado algún correo');
    $message = $index < 0 ? $sent[count($sent) + $index] : $sent[$index];

    return quoted_printable_decode($message->toString());
}

/** La ficha del enlace de un correo enviado (restablecer, entrar o verificar). */
function customersMailToken(ArrayTransport $transport, int $index = -1): string
{
    check(preg_match('#/(?:restablecer|entrar|verificar)/([A-Za-z0-9_-]{20,})#', customersMailText($transport, $index), $m) === 1, 'el correo lleva un enlace con ficha');

    return $m[1];
}

/** Cuántos correos de esa plantilla hay en el registro de correo (campo template). */
function customersMailCount(App $app, string $template): int
{
    return (int) $app->db()->value('SELECT COUNT(*) FROM {mail_messages} WHERE template = ?', [$template]);
}

/** Una sesión de cliente metida a mano en la tabla sessions (no hay páginas de la tienda todavía). */
function customersMakeSession(App $app, int $customerId): void
{
    $now = $app->clock()->sql();
    $app->db()->insert('sessions', [
        'id' => hash('sha256', 'sesion-' . $customerId . '-' . $now), 'guard' => 'customer', 'user_id' => $customerId, 'data' => '{}',
        'created_at' => $now, 'last_at' => $now, 'expires_at' => $app->clock()->sql($app->clock()->now() + 3600),
    ]);
}

/** Dirección válida de España; $extra pisa lo que se quiera. */
function customersAddressData(array $extra = []): array
{
    return $extra + [
        'first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 5', 'postcode' => '35001', 'city' => 'Las Palmas de Gran Canaria',
        'phone' => '999345678',
    ];
}
