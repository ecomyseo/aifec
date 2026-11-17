<?php
/**
 * AIFEC — pruebas de las cuentas de clientes: alta, invitados, cambios de datos y reglas de correo, teléfono y NIF.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Customers\Rules;
use Aifec\Security\Passwords;

require_once __DIR__ . '/CustomersKit.php';

test('un alta normal crea la cuenta, normaliza el correo y los nombres y manda la bienvenida', function (): void {
    [$app, $transport] = customersTestApp();
    $seen = [];
    $app->hooks()->on('customer.registered', static function (array $customer) use (&$seen): void {
        $seen[] = $customer;
    });
    $service = customersService($app);
    $id = $service->register(['email' => '  Ana.Ruiz@Cliente.TEST ', 'password' => 'clave-larga-1', 'first_name' => 'ana', 'last_name' => 'ruiz garcía', 'marketing' => '1']);

    $row = customersRow($app, $id);
    same('ana.ruiz@cliente.test', $row['email']);
    same('Ana', $row['first_name']);
    same('Ruiz García', $row['last_name']);
    same(0, (int) $row['guest']);
    same(1, (int) $row['group_id'], 'entra en el grupo por defecto');
    same(1, (int) $row['marketing']);
    same('register', $row['source']);
    notContains('clave-larga-1', (string) $row['password']);
    check(Passwords::verify('clave-larga-1', (string) $row['password']), 'la contraseña se guarda con su resumen');
    same(1, customersCount($app, 'consents', 'customer_id = ? AND kind = ? AND granted = 1', [$id, 'marketing']), 'queda la prueba del consentimiento');

    same(1, count($transport->sent));
    same(['ana.ruiz@cliente.test'], $transport->sent[0]->recipients());
    same('Te damos la bienvenida a AIFEC', $transport->sent[0]->getSubject());
    contains('/mi-cuenta/verificar/', customersMailText($transport));
    same(false, $service->isVerified($id), 'recién registrada no está verificada');
    same(1, count($seen), 'se dispara customer.registered');
    same($id, $seen[0]['id']);
    check(!array_key_exists('password', $seen[0]), 'el aviso no lleva la contraseña');
});

test('una contraseña corta, un correo malo o el registro cerrado no crean nada', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    throws(function () use ($service): void {
        $service->register(['email' => 'ana@cliente.test', 'password' => '1234567']);
    }, \InvalidArgumentException::class, 'siete caracteres no bastan');
    throws(function () use ($service): void {
        $service->register(['email' => 'ana@cliente.test', 'password' => str_repeat('x', 4097)]);
    }, \InvalidArgumentException::class, 'más de 4096 caracteres tampoco');
    throws(function () use ($service): void {
        $service->register(['email' => 'esto-no-es-un-correo', 'password' => 'clave-larga-1']);
    }, \InvalidArgumentException::class);
    throws(function () use ($service): void {
        $service->register(['email' => 'ana@cliente.test', 'password' => 'clave-larga-1', 'vat_id' => '12345678A']);
    }, \InvalidArgumentException::class, 'un NIF con la letra mal no vale');
    same(0, customersCount($app, 'customers'));
    same(0, customersCount($app, 'mail_messages'));

    $app->settings()->set('customers.allow_register', false);
    throws(function () use ($service): void {
        $service->register(['email' => 'ana@cliente.test', 'password' => 'clave-larga-1']);
    }, \InvalidArgumentException::class, 'con el registro cerrado nadie crea cuenta');
    same(0, customersCount($app, 'customers'));
});

test('un correo que ya es de una cuenta da un mensaje neutro, no crea filas y avisa al dueño una sola vez', function (): void {
    [$app, $transport] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', ['first_name' => 'Ana']);
    $before = customersRow($app, $id);

    $messages = [];
    foreach (['ana@cliente.test', 'ANA@cliente.test'] as $email) {
        try {
            $service->register(['email' => $email, 'password' => 'otra-clave-99', 'first_name' => 'Intruso']);
            check(false, 'tenía que lanzar');
        } catch (\InvalidArgumentException $e) {
            $messages[] = $e->getMessage();
        }
    }
    same($messages[0], $messages[1], 'siempre el mismo mensaje');
    notContains('already', $messages[0]);
    notContains('exist', $messages[0]);
    notContains('in use', $messages[0]);
    same(1, customersCount($app, 'customers'), 'ninguna fila nueva');
    same($before, customersRow($app, $id), 'la cuenta no se toca: ni nombre ni contraseña');
    same(1, count($transport->sent), 'un solo aviso en poco tiempo');
    same(['ana@cliente.test'], $transport->sent[0]->recipients());
    same(1, customersMailCount($app, 'customer_exists'));
    contains('Alguien ha intentado', $transport->sent[0]->getSubject());
    contains('/mi-cuenta', customersMailText($transport));
});

test('un correo de invitado pasa a cuenta en la misma fila, conserva sus pedidos y queda sin verificar', function (): void {
    [$app, $transport] = customersTestApp();
    $service = customersService($app);
    $guest = customersMakeGuest($app, 'bea@cliente.test', ['orders_count' => 3, 'total_spent' => 12345, 'email_verified_at' => $app->clock()->sql()]);

    $id = $service->register(['email' => 'bea@cliente.test', 'password' => 'clave-larga-1', 'first_name' => 'bea', 'last_name' => 'lopez']);
    same($guest, $id, 'misma fila');
    $row = customersRow($app, $id);
    same(0, (int) $row['guest']);
    same(3, (int) $row['orders_count']);
    same(12345, (int) $row['total_spent']);
    same('Bea', $row['first_name']);
    same(null, $row['email_verified_at'], 'sin verificar aunque el invitado lo estuviera');
    same(false, $service->isVerified($id));
    same(1, customersCount($app, 'customers'));

    // Hasta que pulse el enlace del correo.
    $token = customersMailToken($transport);
    same(true, $service->verify($token));
    same(true, $service->isVerified($id));
    check(Passwords::verify('clave-larga-1', (string) customersRow($app, $id)['password']));
});

test('ensureGuest reutiliza filas, devuelve la cuenta sin iniciar sesión y completa sin borrar', function (): void {
    [$app, $transport] = customersTestApp();
    $service = customersService($app);

    $one = $service->ensureGuest('Bea@Cliente.test', ['first_name' => 'bea']);
    $two = $service->ensureGuest('bea@cliente.test', ['phone' => '999 345 678', 'first_name' => '']);
    same($one, $two, 'el mismo correo reutiliza la fila');
    $row = customersRow($app, $one);
    same(1, (int) $row['guest']);
    same(null, $row['password']);
    same('999345678', $row['phone']);
    same('Bea', $row['first_name'], 'un dato vacío no borra el que había');
    same('checkout', $row['source']);
    same(1, customersCount($app, 'customers'));

    $account = customersMakeAccount($app, 'ana@cliente.test');
    same($account, $service->ensureGuest('ANA@cliente.test', ['first_name' => 'Otra']));
    same('Ana', customersRow($app, $account)['first_name'], 'a una cuenta no se le cambian los datos');
    same(null, $service->current(), 'no inicia sesión');
    same(null, $app->auth()->customerId());
    same(2, customersCount($app, 'customers'));
    same(0, count($transport->sent), 'un invitado no recibe correos de cuenta');

    throws(function () use ($service): void {
        $service->ensureGuest('no-es-correo');
    }, \InvalidArgumentException::class);

    // Un teléfono o un NIF malos de un pedido no tumban el pedido: se descartan.
    $three = $service->ensureGuest('carla@cliente.test', ['phone' => 'abc', 'vat_id' => '12345678A', 'company' => '  Carla   SL ']);
    $row = customersRow($app, $three);
    same(null, $row['phone']);
    same(null, $row['vat_id']);
    same('Carla SL', $row['company']);
});

test('update cambia datos, un correo nuevo deja la cuenta sin verificar y no admite el de otro', function (): void {
    [$app, $transport] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app, 'ana@cliente.test');
    customersMakeAccount($app, 'otra@cliente.test');

    $service->update($id, ['first_name' => 'ana maría', 'phone' => '+34 999 345 678', 'vat_id' => '12.345.678-z', 'company' => 'Ruiz & Co', 'marketing' => 1, 'notes' => ' cliente vip ']);
    $row = customersRow($app, $id);
    same('Ana María', $row['first_name']);
    same('999345678', $row['phone']);
    same('12345678Z', $row['vat_id']);
    same('Ruiz & Co', $row['company']);
    same('cliente vip', $row['notes']);
    same(1, (int) $row['marketing']);
    same(1, customersCount($app, 'consents', 'customer_id = ? AND kind = ? AND granted = 1 AND context = ?', [$id, 'marketing', 'account']));

    throws(function () use ($service, $id): void {
        $service->update($id, ['vat_id' => '12345678A']);
    }, \InvalidArgumentException::class);
    throws(function () use ($service, $id): void {
        $service->update($id, ['email' => 'otra@cliente.test']);
    }, \InvalidArgumentException::class, 'el correo de otra cuenta no se puede coger');
    throws(function () use ($service, $id): void {
        $service->update($id, ['password' => 'corta']);
    }, \InvalidArgumentException::class);
    throws(function () use ($service): void {
        $service->update(99999, ['first_name' => 'Nadie']);
    }, \InvalidArgumentException::class);
    same('ana@cliente.test', customersRow($app, $id)['email'], 'un cambio rechazado no deja nada a medias');

    same(true, $service->isVerified($id));
    $service->update($id, ['email' => 'Nueva@Cliente.test']);
    $row = customersRow($app, $id);
    same('nueva@cliente.test', $row['email']);
    same(null, $row['email_verified_at']);
    same(1, count($transport->sent), 'el enlace de verificación va al correo nuevo');
    same(['nueva@cliente.test'], $transport->sent[0]->recipients());

    $service->update($id, ['password' => 'una-clave-nueva']);
    check(Passwords::verify('una-clave-nueva', (string) customersRow($app, $id)['password']));
    $service->update($id, ['marketing' => 0]);
    same(2, customersCount($app, 'consents', 'customer_id = ? AND kind = ?', [$id, 'marketing']), 'retirar la publicidad también queda apuntado');
    same(null, customersRow($app, $id)['marketing_at']);
});

test('las reglas normalizan correo, teléfono y NIF, y descartan lo que no vale', function (): void {
    same('ana@cliente.test', Rules::email('  ANA@Cliente.test '));
    same('', Rules::email('ana@'));
    same('', Rules::email(''));

    same('999345678', Rules::phone('+34 999 34 56 78'));
    same('999345678', Rules::phone('0034-999-345-678'));
    same('+447700900123', Rules::phone('+44 77 00 90 01 23'), 'un teléfono extranjero conserva su prefijo');
    same('', Rules::phone('   '));
    same(null, Rules::phone('abc'));
    same(null, Rules::phone('123'));

    same('12345678Z', Rules::vat('12.345.678-z'));
    same('X1234567L', Rules::vat('x-1234567-l'));
    same('FR12345678901', Rules::vat('FR 123 456 789 01'), 'un número de IVA de otro país vale');
    same('', Rules::vat(''));
    same(null, Rules::vat('12345678A'));
    same(null, Rules::vat('???'));

    same('Ana María', Rules::names('ana maría', '')['first_name']);
    same('De la Cruz', Rules::names('Juan', 'de la cruz')['last_name'], 'las partículas interiores van en minúscula');
    same('es', Rules::locale('es_ES', 'en'));
    same('en', Rules::locale('x', 'en'));
    same('Ñandú & Co', Rules::text("  Ñandú \n  & Co ", 50));
});
