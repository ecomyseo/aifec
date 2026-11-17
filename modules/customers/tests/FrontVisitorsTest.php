<?php
/**
 * AIFEC — pruebas de lo que encontraron los visitantes en la zona de cliente (P106): «Mis datos» con el teléfono de la
 * dirección guardada cuando la cuenta no lo tiene.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/CustomersKit.php';

/** Un navegador de mentira que ya ha entrado con esa cuenta, por la pantalla de entrada. */
function visitorsLogin(\Aifec\Kernel\App $app, string $email = 'ana@cliente.test', string $password = 'clave-larga-1'): TestClient
{
    $web = new TestClient($app);
    same(303, $web->post('/mi-cuenta/entrar', ['email' => $email, 'password' => $password])->status, 'entrar con ' . $email);

    return $web;
}

test('mis datos: sin teléfono en la cuenta se propone el de la dirección guardada (la de entrega primero), y el de la cuenta manda si lo hay', function (): void {
    [$app] = customersTestApp();
    $id = customersMakeAccount($app, 'ana@cliente.test');
    $customers = customersService($app);
    $customers->saveAddress($id, customersAddressData(['line1' => 'Calle Vieja 1', 'phone' => '999611229', 'default_shipping' => 0, 'default_billing' => 0]));
    $customers->saveAddress($id, customersAddressData(['line1' => 'Calle Mayor 5', 'phone' => '999345678', 'default_shipping' => 1]));
    $web = visitorsLogin($app);

    same(null, customersRow($app, $id)['phone'], 'la cuenta no tiene teléfono');
    $page = $web->get('/mi-cuenta/datos');
    same(200, $page->status);
    contains('name="phone" value="999345678"', $page->body, '«Mis datos» enseña el de la dirección de entrega por defecto');

    // Con teléfono en la cuenta, ese es el que sale.
    $app->db()->update('customers', ['phone' => '999000000'], ['id' => $id]);
    contains('name="phone" value="999000000"', $web->get('/mi-cuenta/datos')->body, 'el de la cuenta manda');

    // Sin ninguna dirección con teléfono, el campo queda vacío.
    [$app2] = customersTestApp();
    $other = customersMakeAccount($app2, 'luis@cliente.test');
    customersService($app2)->saveAddress($other, customersAddressData(['phone' => '']));
    $web2 = visitorsLogin($app2, 'luis@cliente.test');
    contains('name="phone" value=""', $web2->get('/mi-cuenta/datos')->body);
});
