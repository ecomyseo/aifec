<?php
/**
 * AIFEC — pruebas de las direcciones de clientes: validación, provincia deducida, una sola por defecto y propiedad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/CustomersKit.php';

test('la misma dirección guardada dos veces no se duplica: se pone al día la que había', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $customer = customersMakeAccount($app);
    $first = $service->saveAddress($customer, customersAddressData(['city' => 'Palma', 'postcode' => '07001', 'phone' => '999000011']));
    $again = $service->saveAddress($customer, customersAddressData(['city' => 'palma', 'postcode' => '07001', 'phone' => '999000012']));
    same($first, $again, 'la misma');
    same(1, count($service->addresses($customer)));
    same('999000012', $service->address($first, $customer)['phone'], 'con lo último que se escribió');
    $other = $service->saveAddress($customer, customersAddressData(['city' => 'Palma', 'postcode' => '07002']));
    check($other !== $first, 'otra dirección sí es otra');
});

test('una dirección válida se guarda con la provincia deducida del código postal', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $customer = customersMakeAccount($app);

    $id = $service->saveAddress($customer, customersAddressData(['first_name' => 'ana', 'city' => 'las palmas']));
    $address = $service->address($id, $customer);
    check($address !== null, 'se puede leer');
    same('35', $address['region'], 'el código postal 35001 es de Las Palmas');
    same('ES', $address['country'], 'España por defecto');
    same('Ana', $address['first_name']);
    same('Las Palmas', $address['city']);
    same('999345678', $address['phone']);
    same(1, $address['default_shipping'], 'la primera es de envío por defecto');
    same(1, $address['default_billing'], 'y de facturación');
    same($customer, $address['customer_id']);

    // Una región escrita a mano se respeta; en otro país no se deduce nada.
    $other = $service->saveAddress($customer, customersAddressData(['postcode' => '75001', 'country' => 'fr', 'city' => 'Paris', 'region' => 'IDF']));
    same('IDF', (string) $service->address($other, $customer)['region']);
    same('FR', (string) $service->address($other, $customer)['country']);
    $noRegion = $service->saveAddress($customer, customersAddressData(['postcode' => '1000', 'country' => 'BE', 'city' => 'Bruxelles']));
    same(null, $service->address($noRegion, $customer)['region']);

    // Cambiar una dirección conserva lo que no se manda.
    $service->saveAddress($customer, ['line1' => 'Calle Nueva 7', 'postcode' => '38001'], $id);
    $address = $service->address($id, $customer);
    same('Calle Nueva 7', $address['line1']);
    same('Ana', $address['first_name']);
    same('35', $address['region'], 'la región sigue siendo la que se guardó');
    same(3, count($service->addresses($customer)));
    same(1, $address['default_shipping'], 'cambiar la dirección no le quita lo de por defecto');
});

test('la validación rechaza país, código postal, teléfono, NIF y campos que faltan', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $customer = customersMakeAccount($app);
    $bad = [
        'país de tres letras' => ['country' => 'ESP'],
        'país con cifras' => ['country' => '3S'],
        'código postal corto' => ['postcode' => '3500'],
        'código postal con letras' => ['postcode' => '35A01'],
        'provincia que no existe' => ['postcode' => '99999'],
        'código postal de Portugal mal escrito' => ['country' => 'PT', 'postcode' => '1000'],
        'teléfono con letras' => ['phone' => 'abc'],
        'NIF con la letra mal' => ['vat_id' => '12345678A'],
        'sin nombre' => ['first_name' => '  '],
        'sin calle' => ['line1' => ''],
        'sin ciudad' => ['city' => ''],
        'sin código postal' => ['postcode' => ''],
    ];
    foreach ($bad as $why => $change) {
        throws(function () use ($service, $customer, $change): void {
            $service->saveAddress($customer, customersAddressData($change));
        }, \InvalidArgumentException::class, $why);
    }
    same(0, customersCount($app, 'addresses'), 'ninguna dirección mala se guardó');
    throws(function () use ($service): void {
        $service->saveAddress(99999, customersAddressData());
    }, \InvalidArgumentException::class, 'un cliente que no existe');

    // Lo raro que sí vale: tildes, eñes, emojis, comillas y «&».
    $id = $service->saveAddress($customer, customersAddressData(['first_name' => 'Ñandú', 'line1' => "Calle \"Mayor\" & 'Sol' 5 😀", 'company' => 'Ruiz & Hijos S.L.', 'vat_id' => 'b-12345674']));
    $address = $service->address($id, $customer);
    same('Ñandú', $address['first_name']);
    same("Calle \"Mayor\" & 'Sol' 5 😀", $address['line1']);
    same('Ruiz & Hijos S.L.', $address['company']);
    same('B12345674', $address['vat_id']);
});

test('solo hay una dirección de envío y una de facturación por defecto, y al borrar la de por defecto otra la sustituye', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $customer = customersMakeAccount($app);
    $other = customersMakeAccount($app, 'otra@cliente.test');

    $first = $service->saveAddress($customer, customersAddressData(['line1' => 'Calle Uno 1']));
    $second = $service->saveAddress($customer, customersAddressData(['line1' => 'Calle Dos 2']));
    $third = $service->saveAddress($customer, customersAddressData(['line1' => 'Calle Tres 3', 'default_shipping' => 1]));
    $foreign = $service->saveAddress($other, customersAddressData(['line1' => 'Calle Ajena 9']));

    $flags = static function (int $id) use ($service, $customer): array {
        $address = $service->address($id, $customer);

        return [$address['default_shipping'], $address['default_billing']];
    };
    same([0, 1], $flags($first), 'envío pasa a la tercera; facturación sigue en la primera');
    same([0, 0], $flags($second));
    same([1, 0], $flags($third));
    same(1, (int) $service->address($foreign, $other)['default_shipping'], 'las de otro cliente no se tocan');

    $service->saveAddress($customer, ['default_billing' => 1], $second);
    same([0, 0], $flags($first));
    same([0, 1], $flags($second));
    same(1, customersCount($app, 'addresses', 'customer_id = ? AND default_billing = 1', [$customer]));
    same(1, customersCount($app, 'addresses', 'customer_id = ? AND default_shipping = 1', [$customer]));

    // Desmarcar la de por defecto no la quita: se cambia marcando otra.
    $service->saveAddress($customer, ['default_shipping' => 0], $third);
    same(1, $flags($third)[0]);

    $service->deleteAddress($third, $customer);
    same(null, $service->address($third, $customer));
    same(1, customersCount($app, 'addresses', 'customer_id = ? AND default_shipping = 1', [$customer]), 'otra ocupa su sitio');
    same(1, (int) $service->address($second, $customer)['default_shipping'], 'la más reciente de las que quedan');
    $list = $service->addresses($customer);
    same($second, $list[0]['id'], 'las de por defecto salen primero');
});

test('no se puede tocar la dirección de otro cliente', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $one = customersMakeAccount($app, 'uno@cliente.test');
    $two = customersMakeAccount($app, 'dos@cliente.test');
    $address = $service->saveAddress($one, customersAddressData());
    $before = $app->db()->row('SELECT * FROM {addresses} WHERE id = ?', [$address]);

    same(null, $service->address($address, $two));
    throws(function () use ($service, $two, $address): void {
        $service->saveAddress($two, customersAddressData(['line1' => 'Robada 1']), $address);
    }, \InvalidArgumentException::class);
    throws(function () use ($service, $two, $address): void {
        $service->deleteAddress($address, $two);
    }, \InvalidArgumentException::class);
    throws(function () use ($service, $one): void {
        $service->deleteAddress(99999, $one);
    }, \InvalidArgumentException::class);
    same($before, $app->db()->row('SELECT * FROM {addresses} WHERE id = ?', [$address]), 'la dirección sigue igual');
    same(0, customersCount($app, 'addresses', 'customer_id = ?', [$two]));
});

test('los grupos: el de por defecto existe y group() cae en él si no se pide uno que exista', function (): void {
    [$app] = customersTestApp();
    $service = customersService($app);
    $app->db()->insert('customer_groups', ['name' => 'Mayoristas', 'discount' => 1500, 'tax_exempt' => 1, 'is_default' => 0]);
    $wholesale = (int) $app->db()->value("SELECT id FROM {customer_groups} WHERE name = 'Mayoristas'");

    $default = $service->group(null);
    same(['id' => 1, 'name' => 'Customers', 'discount' => 0, 'tax_exempt' => false], $default);
    same($default, $service->group(99999), 'un grupo que no existe cae en el de por defecto');
    same(['id' => $wholesale, 'name' => 'Mayoristas', 'discount' => 1500, 'tax_exempt' => true], $service->group($wholesale));

    $app->db()->run('DELETE FROM {customer_groups}');
    same(0, $service->group(null)['id'], 'sin ningún grupo, uno de relleno');
});
