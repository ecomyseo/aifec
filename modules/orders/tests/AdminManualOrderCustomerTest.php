<?php
/**
 * AIFEC — pruebas del pedido a mano (P103): el cliente (buscar, crear sin duplicar) y la entrega (direcciones guardadas o nuevas).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminManualOrderKit.php';

if (!function_exists('moCustFind')) {
    /**
     * @return array<string,mixed>
     */
    function moCustFind(TestClient $web, string $what, string $q): array
    {
        $r = $web->get('/admin/orders/new/find/' . $what . '?q=' . rawurlencode($q), ['accept' => 'application/json']);
        same(200, $r->status, 'el buscador contesta');
        $data = json_decode($r->body, true);
        check(is_array($data), 'en JSON');

        return $data;
    }

    function moCustCount(\Aifec\Kernel\App $app): int
    {
        return (int) $app->db()->value('SELECT COUNT(*) FROM {customers}');
    }
}

test('el buscador de clientes encuentra por nombre, apellidos, correo y teléfono', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    foreach (['Ana', 'Ana Ruiz', 'ruiz', 'ana@cliente', '999000001', '999 000 001'] as $q) {
        $data = moCustFind($web, 'customers', $q);
        same(1, count($data['results']), 'encuentra a Ana con «' . $q . '»');
        same((int) $s['ana'], (int) $data['results'][0]['id']);
        contains('ana@cliente.test', (string) $data['results'][0]['label']);
    }
    same([], moCustFind($web, 'customers', 'zzz')['results']);
    same('No se ha encontrado nada.', moCustFind($web, 'customers', 'zzz')['status']);
    same([], moCustFind($web, 'customers', 'a')['results'], 'con una sola letra no se busca');
});

test('el buscador de clientes devuelve como mucho 10, escapa los comodines y deja fuera las cuentas apagadas', function (): void {
    $s = moShop();
    $app = $s['app'];
    for ($i = 1; $i <= 12; $i++) {
        aifecCartCustomer($app, 'cliente' . $i . '@ejemplo.test', ['first_name' => 'Cliente', 'last_name' => 'Número ' . $i]);
    }
    $web = moClient($app);
    $data = moCustFind($web, 'customers', 'Cliente');
    same(10, count($data['results']), 'tope de 10');
    same('10 resultados', $data['status']);
    same([], moCustFind($web, 'customers', '%%')['results'], 'un «%» se busca como texto');
    same([], moCustFind($web, 'customers', '__')['results'], 'un «_» también');
    $app->db()->update('customers', ['active' => 0], ['id' => (int) $s['ana']]);
    same([], moCustFind($web, 'customers', 'Ana Ruiz')['results'], 'una cuenta apagada no sale');
    $r = moStep($web, 'customer', ['customer_id' => (int) $s['ana']], true);
    same(false, json_decode($r->body, true)['ok'], 'ni se puede elegir');
});

test('sin JavaScript, la búsqueda de clientes pinta los resultados con un botón para elegir cada uno', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    $r = $web->get('/admin/orders/new?cq=Ana');
    same(200, $r->status);
    contains('Clientes encontrados', $r->body);
    $form = panelKitForm($r->body, '/admin/orders/new/customer');
    same((string) $s['ana'], (string) $form['customer_id']);
    $post = $web->post('/admin/orders/new/customer', $form);
    same(303, $post->status);
    contains('/admin/orders/new#mo-customer', (string) $post->headers['Location']);
    $page = $web->follow();
    contains('Cliente: Ana Ruiz', $page->body, 'el aviso');
    contains('Cambiar de cliente', $page->body);
});

test('cliente nuevo: se crea sin cuenta, se elige y queda listo para el pedido', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    $before = moCustCount($app);
    $r = moStep($web, 'customer-new', ['c' => ['first_name' => 'Luis', 'last_name' => 'Pérez & Hijos', 'email' => 'Luis@Cliente.test', 'phone' => '999000002']]);
    same(303, $r->status);
    same($before + 1, moCustCount($app));
    $row = $app->db()->row('SELECT * FROM {customers} WHERE email = ?', ['luis@cliente.test']);
    check($row !== null, 'el correo se guarda en minúsculas');
    same(1, (int) $row['guest'], 'sin cuenta');
    $page = $web->get('/admin/orders/new');
    contains('Luis Pérez &amp; Hijos', $page->body, 'escapado');
    contains('Sin cuenta', $page->body);
});

test('cliente nuevo con un correo que ya existe: ofrece el que hay y no crea otro', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    $before = moCustCount($app);
    $r = moStep($web, 'customer-new', ['c' => ['first_name' => 'Ana', 'email' => 'ANA@cliente.test']]);
    same(303, $r->status);
    same($before, moCustCount($app), 'no se duplica');
    $page = $web->follow();
    contains('Ya hay un cliente con este correo', $page->body);
    contains('Ana Ruiz (ana@cliente.test) ya es cliente.', $page->body);
    $form = panelKitForm($page->body, '/admin/orders/new/customer');
    same((string) $s['ana'], (string) $form['customer_id'], 'el botón «Usar este cliente» lleva a Ana');
    $web->post('/admin/orders/new/customer', $form);
    contains('Cambiar de cliente', $web->get('/admin/orders/new')->body, 'Ana queda elegida');
    check(!isset(moQuote($web)['missing']['customer']), 'y ya no falta el cliente');
    $json = moStep($web, 'customer-new', ['c' => ['first_name' => 'Ana', 'email' => 'ana@cliente.test']], true);
    $data = json_decode($json->body, true);
    same(false, $data['ok']);
    check(isset($data['errors']['email']), 'el error va en el correo');
});

test('cliente nuevo sin nombre o con un correo que no vale: no se crea nada', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    $before = moCustCount($app);
    foreach ([['first_name' => '', 'email' => 'x@ejemplo.test'], ['first_name' => 'Eva', 'email' => 'no-es-un-correo'], ['first_name' => 'Eva', 'email' => '']] as $c) {
        $data = json_decode(moStep($web, 'customer-new', ['c' => $c], true)->body, true);
        same(false, $data['ok']);
    }
    same($before, moCustCount($app));
    moStep($web, 'customer-new', ['c' => ['first_name' => 'Eva', 'email' => 'no-es-un-correo']]);
    $page = $web->follow();
    contains('Escribe un correo válido: ahí llega la confirmación del pedido.', $page->body);
    contains('value="no-es-un-correo"', $page->body, 'lo escrito vuelve al campo');
    contains('<details class="mo-new" open>', $page->body, 'el formulario sale abierto');
});

test('al elegir el cliente se elige su dirección de envío de serie y el carrito toma su destino', function (): void {
    $s = moShop();
    $app = $s['app'];
    $canary = (int) $app->service('customers')->saveAddress((int) $s['ana'], [
        'first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '35001', 'city' => 'Las Palmas', 'country' => 'ES', 'default_shipping' => 1,
    ]);
    // A Canarias el envío cuesta más: si el carrito no toma el destino de la dirección, el total no cambia.
    $app->db()->update('carrier_rates', ['price' => 1595], ['zone_id' => shippingZoneId($app, 'Canary Islands')]);
    $web = moClient($app);
    moStep($web, 'customer', ['customer_id' => (int) $s['ana']]);
    moStep($web, 'line', ['product_id' => $s['mug'], 'variant_id' => 0, 'qty' => 1]);
    $page = $web->get('/admin/orders/new');
    check(preg_match('/name="address_id" value="' . $canary . '" checked/', $page->body) === 1, 'la de envío de serie, marcada');
    $q = moQuote($web);
    $canaryTotal = $q['total'];
    moStep($web, 'address', ['address_id' => (int) $s['address']]);
    check(moQuote($web)['total'] !== $canaryTotal, 'cambiar de dirección cambia impuestos o envío');
});

test('dirección nueva: se guarda en la cuenta del cliente con la validación de siempre y se elige', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    moStep($web, 'customer', ['customer_id' => (int) $s['ana']]);
    $count = (int) $app->db()->value('SELECT COUNT(*) FROM {addresses} WHERE customer_id = ?', [(int) $s['ana']]);
    $bad = json_decode(moStep($web, 'address-new', ['a' => ['country' => 'ES', 'first_name' => 'Ana', 'line1' => 'Calle Luna 3', 'postcode' => '1234', 'city' => 'Sevilla']], true)->body, true);
    same(false, $bad['ok']);
    check(isset($bad['errors']['postcode']), 'código postal que no vale');
    $far = json_decode(moStep($web, 'address-new', ['a' => ['country' => 'JP', 'first_name' => 'Ana', 'line1' => 'Calle Luna 3', 'postcode' => '1000001', 'city' => 'Tokio']], true)->body, true);
    check(isset($far['errors']['country']), 'un país al que la tienda no envía');
    same($count, (int) $app->db()->value('SELECT COUNT(*) FROM {addresses} WHERE customer_id = ?', [(int) $s['ana']]), 'nada guardado');
    $ok = json_decode(moStep($web, 'address-new', ['a' => ['country' => 'ES', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Luna 3', 'postcode' => '41001', 'city' => 'Sevilla']], true)->body, true);
    same(true, $ok['ok']);
    same($count + 1, (int) $app->db()->value('SELECT COUNT(*) FROM {addresses} WHERE customer_id = ?', [(int) $s['ana']]));
    contains('Calle Luna 3', (string) $ok['html'], 'la columna nueva la trae elegida');
    $id = (int) $app->db()->value('SELECT MAX(id) FROM {addresses} WHERE customer_id = ?', [(int) $s['ana']]);
    check(preg_match('/name="address_id" value="' . $id . '" checked/', (string) $ok['html']) === 1, 'y marcada');
});

test('la dirección de otro cliente no se puede elegir, y sin cliente no hay entrega', function (): void {
    $s = moShop();
    $app = $s['app'];
    $other = aifecCartCustomer($app, 'otro@cliente.test');
    $foreign = (int) $app->service('customers')->saveAddress($other, ['first_name' => 'Otro', 'line1' => 'Calle Sol 2', 'postcode' => '28002', 'city' => 'Madrid', 'country' => 'ES']);
    $web = moClient($app);
    $none = json_decode(moStep($web, 'address', ['address_id' => (int) $s['address']], true)->body, true);
    same(false, $none['ok'], 'sin cliente');
    contains('Elige antes el cliente.', (string) $none['message']);
    moStep($web, 'customer', ['customer_id' => (int) $s['ana']]);
    $data = json_decode(moStep($web, 'address', ['address_id' => $foreign], true)->body, true);
    same(false, $data['ok']);
    same('Elige una de las direcciones del cliente.', (string) $data['message']);
    notContains('value="' . $foreign . '"', (string) $data['html'], 'la dirección ajena no sale en la pantalla');
    moStep($web, 'line', ['product_id' => $s['mug'], 'variant_id' => 0, 'qty' => 1]);
    same('Elige la dirección de entrega.', (string) moQuote($web)['missing']['delivery'], 'y no queda elegida');
});
