<?php
/**
 * AIFEC — pruebas de los derechos sobre los datos: consentimientos, copia de datos de una persona y anonimización.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/CustomersKit.php';

test('consent apunta la prueba y, si es de publicidad, actualiza la casilla del cliente', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app);

    $service->consent($id, 'ANA@cliente.test', 'marketing', true, 'v3', 'checkout');
    $row = $app->db()->row('SELECT * FROM {consents} WHERE customer_id = ?', [$id]);
    check($row !== null, 'queda apuntado');
    same('ana@cliente.test', $row['email']);
    same('marketing', $row['kind']);
    same(1, (int) $row['granted']);
    same('v3', $row['version']);
    same('checkout', $row['context']);
    same('127.0.0.1', $row['ip']);
    same('2026-11-12 12:00:00', $row['created_at']);
    same(1, (int) customersRow($app, $id)['marketing']);
    same('2026-11-12 12:00:00', customersRow($app, $id)['marketing_at']);

    $service->consent($id, 'ana@cliente.test', 'marketing', false);
    same(0, (int) customersRow($app, $id)['marketing']);
    same(2, customersCount($app, 'consents', 'customer_id = ?', [$id]), 'retirar también deja constancia');

    // Sin cliente (alguien que acepta antes de tener cuenta) basta el correo; los demás tipos no tocan la casilla.
    $service->consent(null, 'visita@cliente.test', 'terms', true, '2026-01');
    same(1, customersCount($app, 'consents', 'email = ? AND customer_id IS NULL', ['visita@cliente.test']));
    same(0, (int) customersRow($app, $id)['marketing']);
    throws(function () use ($service, $id): void {
        $service->consent($id, 'ana@cliente.test', 'cookies', true);
    }, \InvalidArgumentException::class);
    throws(function () use ($service): void {
        $service->consent(null, '', 'terms', true);
    }, \InvalidArgumentException::class);
});

test('exportData contiene lo suyo y nada de otros, sin la contraseña', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $one = customersMakeAccount($app, 'uno@cliente.test', 'clave-larga-1', ['first_name' => 'Uno', 'phone' => '999111111']);
    $two = customersMakeAccount($app, 'dos@cliente.test', 'clave-larga-1', ['first_name' => 'Dos', 'phone' => '999222222']);
    $service->saveAddress($one, customersAddressData(['line1' => 'Calle Uno 1']));
    $service->saveAddress($two, customersAddressData(['line1' => 'Calle Dos 2']));
    $service->consent($one, 'uno@cliente.test', 'privacy', true, 'v1');
    $service->consent($two, 'dos@cliente.test', 'privacy', true, 'v1');
    $service->consent(null, 'uno@cliente.test', 'terms', true, 'v1', 'antes de tener cuenta');
    $app->db()->insert('withdrawals', ['order_id' => 7, 'customer_id' => $one, 'status' => 'requested', 'created_at' => $app->clock()->sql()]);
    $app->db()->insert('withdrawals', ['order_id' => 8, 'customer_id' => $two, 'status' => 'requested', 'created_at' => $app->clock()->sql()]);
    $app->hooks()->filter('customer.export', static function (array $data, int $id): array {
        $data['orders'] = [['number' => 'PEDIDO-' . $id]];

        return $data;
    });

    $data = $service->exportData($one);
    same('uno@cliente.test', $data['customer']['email']);
    same('999111111', $data['customer']['phone']);
    check(!array_key_exists('password', $data['customer']), 'sin contraseña');
    same(1, count($data['addresses']));
    same('Calle Uno 1', $data['addresses'][0]['line1']);
    same(2, count($data['consents']), 'los suyos, también el que se apuntó solo con su correo');
    same(1, count($data['withdrawals']));
    same([['number' => 'PEDIDO-' . $one]], $data['orders'], 'otros módulos añaden lo suyo con el filtro');
    check(isset($data['exported_at']) && $data['exported_at'] === '2026-11-12T12:00:00Z', 'lleva la fecha de la copia');

    $json = (string) json_encode($data);
    notContains('dos@cliente.test', $json);
    notContains('999222222', $json);
    notContains('Calle Dos', $json);
    notContains('Argon', $json);
    throws(function () use ($service): void {
        $service->exportData(99999);
    }, \InvalidArgumentException::class);
});

test('anonymise deja la fila sin datos personales, sin direcciones, sin sesiones y sin fichas, y conserva el id', function (): void {
    [$app, $transport] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1', [
        'phone' => '999345678', 'vat_id' => '12345678Z', 'company' => 'Ruiz & Co', 'notes' => 'llama por las tardes', 'marketing' => 1,
        'orders_count' => 4, 'total_spent' => 9900, 'legacy_id' => 'ps-77', 'last_login_at' => $app->clock()->sql(),
    ]);
    $other = customersMakeAccount($app, 'otra@cliente.test');
    $service->saveAddress($id, customersAddressData());
    $service->saveAddress($other, customersAddressData(['line1' => 'Calle Ajena 9']));
    $service->consent($id, 'ana@cliente.test', 'privacy', true, 'v1');
    customersMakeSession($app, $id);
    customersMakeSession($app, $other);
    $service->requestReset('ana@cliente.test');
    $app->db()->insert('login_attempts', ['guard' => 'customer', 'identifier' => 'ana@cliente.test', 'ip' => '10.0.0.1', 'ok' => 0, 'created_at' => $app->clock()->sql()]);
    $seen = [];
    $app->hooks()->on('customer.anonymised', static function (int $customerId) use (&$seen): void {
        $seen[] = $customerId;
    });

    $service->anonymise($id);

    $row = customersRow($app, $id);
    same($id, (int) $row['id'], 'conserva el id');
    same('anonymised-' . $id . '@anonymised.invalid', $row['email']);
    same('Anonymised', $row['first_name']);
    foreach (['password', 'last_name', 'company', 'vat_id', 'phone', 'notes', 'legacy_id', 'email_verified_at', 'last_login_at', 'marketing_at'] as $column) {
        same(null, $row[$column], $column . ' vacío');
    }
    same(0, (int) $row['active']);
    same(0, (int) $row['marketing']);
    same(4, (int) $row['orders_count'], 'las cifras de pedidos se conservan: los pedidos siguen apuntando al cliente');
    same(9900, (int) $row['total_spent']);
    $json = json_encode($row) ?: '';
    foreach (['ana@cliente.test', 'Ruiz', 'Ana', '999345678', '12345678Z', 'llama'] as $personal) {
        notContains($personal, $json);
    }
    same(0, customersCount($app, 'addresses', 'customer_id = ?', [$id]));
    same(0, customersCount($app, 'sessions', "guard = 'customer' AND user_id = ?", [$id]));
    same(1, customersCount($app, 'sessions', "guard = 'customer' AND user_id = ?", [$other]), 'las sesiones de otros siguen');
    same(1, customersCount($app, 'addresses', 'customer_id = ?', [$other]));
    same(0, customersCount($app, 'tokens', "subject = 'ana@cliente.test'"));
    same(0, customersCount($app, 'login_attempts', "identifier = 'ana@cliente.test'"));
    $consent = $app->db()->row('SELECT * FROM {consents} WHERE customer_id = ?', [$id]);
    check($consent !== null, 'la prueba del consentimiento se conserva');
    same('anonymised-' . $id . '@anonymised.invalid', $consent['email']);
    same(null, $consent['ip']);
    same([$id], $seen, 'se dispara customer.anonymised para que cada módulo limpie lo suyo');

    // Ya no se puede entrar, ni pedir un enlace, y el correo de antes queda libre para registrarse de nuevo.
    same(null, $service->login('ana@cliente.test', 'clave-larga-1'));
    $mails = count($transport->sent);
    $service->requestReset('anonymised-' . $id . '@anonymised.invalid');
    same($mails, count($transport->sent));
    $again = $service->register(['email' => 'ana@cliente.test', 'password' => 'clave-larga-1']);
    check($again !== $id, 'una cuenta nueva, no la anonimizada');

    // Hacerlo dos veces no rompe nada.
    $service->anonymise($id);
    same('Anonymised', customersRow($app, $id)['first_name']);
    throws(function () use ($service): void {
        $service->anonymise(99999);
    }, \InvalidArgumentException::class);
});

test('anonymise del cliente que tiene la sesión abierta la cierra', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $id = customersMakeAccount($app, 'ana@cliente.test', 'clave-larga-1');
    same($id, $service->login('ana@cliente.test', 'clave-larga-1'));
    same($id, $app->auth()->customerId());
    $service->anonymise($id);
    same(null, $app->auth()->customerId(), 'la sesión de esta petición se cierra');
    same(null, $service->current());
});
