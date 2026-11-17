<?php
/**
 * AIFEC — pruebas de la creación del pedido (Orders::place): filas, número, totales idénticos a los del carrito,
 * existencias, cupones, cliente, consentimientos y todas las validaciones (que no escriben nada).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Orders\Sequence;

require_once __DIR__ . '/helpers.php';

/** ¿Queda la cookie del carrito en el navegador de mentira? */
function orderPlaceHasCartCookie(TestClient $web): bool
{
    foreach (array_keys($web->cookies) as $name) {
        if (strpos((string) $name, 'af_k_') === 0) {
            return true;
        }
    }

    return false;
}

test('un pedido completo guarda filas, número, totales del carrito, existencias, cupón, invitado y consentimientos', function (): void {
    $app = aifecOrderApp();
    couponsMake($app, ['code' => 'OTONO', 'percent' => 1000]);
    $cafe = aifecProduct($app, ['name' => 'Café & Té <b>', 'price' => 1210, 'stock' => 10, 'weight' => 300, 'sku' => 'CAFE']);
    $taza = aifecProduct($app, ['name' => 'Taza', 'price' => 2000, 'stock' => 5, 'tax_class_id' => 'reduced', 'weight' => 200]);
    $web = new TestClient($app);

    $data = aifecCartRun($web, static function ($cart, App $app) use ($cafe, $taza): array {
        $cart->add($cafe, 0, 2);
        $cart->add($taza, 0, 1);
        $cart->applyCoupon('otono');
        $cart->setPaymentMethod('gw');
        $cart->setDestination(['country' => 'ES', 'region' => '28', 'postcode' => '28001', 'city' => 'Madrid']);
        $before = $cart->summary();
        $result = $app->service('orders')->place(aifecOrderCheckout(['marketing' => true, 'note' => 'Llamar al timbre']), ['attribution' => ['utm_source' => 'prueba'], 'ip' => '203.0.113.9']);

        return ['before' => $before, 'result' => $result];
    });
    $result = $data['result'];
    $totals = $data['before']['totals'];

    same(true, $result['ok'], (string) $result['error']);
    same('2026-000001', $result['number']);
    same('redirect', $result['action']->type);
    $order = $app->service('orders')->find((int) $result['id']);
    check($order !== null, 'el pedido se puede leer');

    // Los totales son los del carrito, al céntimo.
    same((int) $totals['total'], $order['total']);
    same((int) $totals['items_total'], $order['items_total']);
    same((int) $totals['discount_total'], $order['discount_total']);
    same((int) $totals['total_net'], $order['total_net']);
    same((int) $totals['total_tax'], $order['total_tax']);
    same((int) $totals['shipping']['price'], $order['shipping_total']);
    same($totals['taxes'], $order['taxes']);
    check($order['discount_total'] > 0, 'el cupón descuenta algo');
    same('pending_payment', $order['status']);
    same('pending', $order['payment_status']);
    same('unfulfilled', $order['fulfillment_status']);
    same('gw', $order['payment_method']);
    same(true, $order['test']);
    same(true, $order['prices_include_tax']);
    same('web', $order['source']);
    same(['utm_source' => 'prueba'], $order['attribution']);
    same('203.0.113.9', $order['ip']);
    same('Llamar al timbre', $order['customer_note']);
    same('Madrid', $order['billing']['city']);
    same('Madrid', $order['shipping']['city']);
    same('ana@cliente.test', $order['email']);
    same('es', $order['lang']);
    same('EUR', $order['currency']);
    same(['pending_payment'], array_column($order['history'], 'to_value'));
    same(false, array_key_exists('secret', $order));

    // La ficha: se devuelve en claro y en la base solo está su resumen.
    same(48, strlen($result['secret']));
    same(hash('sha256', $result['secret']), $app->db()->value('SELECT secret FROM {orders} WHERE id = ?', [$result['id']]));

    // La suma de las líneas da el total (base + impuesto de cada una).
    $sum = 0;
    foreach ($order['items'] as $item) {
        $sum += $item['line_net'] + $item['line_tax'];
    }
    same($order['total'], $sum);
    same(['product', 'product', 'shipping', 'discount'], array_column($order['items'], 'kind'));
    same('Café & Té <b>', $order['items'][0]['name']);
    same(2, $order['items'][0]['qty']);
    same('CAFE', $order['items'][0]['sku']);
    same(300, $order['items'][0]['weight']);

    // Existencias, cupón, cliente, consentimientos y carrito.
    same(8, aifecOrderStock($app, $cafe));
    same(4, aifecOrderStock($app, $taza));
    same(1, aifecOrderCount($app, 'coupon_uses', 'order_id = ?', [$result['id']]));
    same(1, (int) $app->db()->value('SELECT uses FROM {coupons} WHERE code = ?', ['OTONO']));
    same('OTONO', $order['coupons'][0]['code']);
    $customer = $app->db()->row('SELECT * FROM {customers} WHERE email = ?', ['ana@cliente.test']);
    check($customer !== null && (int) $customer['guest'] === 1, 'se crea un cliente invitado');
    same((int) $customer['id'], $order['customer_id']);
    same(['terms', 'marketing'], array_column($app->db()->select('SELECT kind FROM {consents} WHERE customer_id = ? ORDER BY id', [(int) $customer['id']]), 'kind'));
    same($result['id'], (int) $app->db()->value('SELECT order_id FROM {carts}'));
    same(false, orderPlaceHasCartCookie($web));
});

test('el número sigue el formato del ajuste y el contador avanza de uno en uno', function (): void {
    $app = aifecOrderApp(['settings' => ['orders.number_format' => 'PED-{year}/{number:4}']]);
    $p = aifecProduct($app, ['stock' => 10]);
    $first = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);
    $second = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);

    same('PED-2026/0001', $first['number']);
    same('PED-2026/0002', $second['number']);
    same(2, (int) $app->db()->value("SELECT value FROM {sequences} WHERE name = 'order'"));
    same($second['id'], $app->service('orders')->findByNumber('PED-2026/0002')['id']);
});

test('el formato del número se completa si no lleva contador y no pasa de lo que cabe en la columna', function (): void {
    same('2026-000007', Sequence::format('{year}-{number:6}', 7, 2026));
    same('A7', Sequence::format('A{number}', 7, 2026));
    same('2026-000007', Sequence::format('{year}', 7, 2026));
    same('000012', Sequence::format('', 12, 2026));
    same('2026-000009', Sequence::format('un-prefijo-larguisimo-que-no-cabe-en-la-columna-{number:6}', 9, 2026));
    same('N-12345678', Sequence::format('N-{number:8}', 12345678, 2026));
});

test('un pedido con recargo de pago, con precios sin impuestos y con cliente exento suma el total en sus líneas', function (): void {
    foreach ([[true, false], [false, false], [true, true], [false, true]] as $case) {
        list($incluye, $exento) = $case;
        $app = aifecOrderApp(['prices_include_tax' => $incluye]);
        aifecOrderPayment($app)->fee = 300;
        couponsMake($app, ['code' => 'FIJO', 'kind' => 'fixed', 'amount' => 333]);
        $p1 = aifecProduct($app, ['name' => 'Uno', 'price' => 1999, 'stock' => 10, 'weight' => 400]);
        $p2 = aifecProduct($app, ['name' => 'Dos', 'price' => 799, 'stock' => 10, 'tax_class_id' => 'reduced', 'weight' => 100]);
        $web = new TestClient($app);
        if ($exento) {
            aifecCartCustomer($app, 'ana@cliente.test', ['group_id' => aifecCartGroup($app, 0, true)]);
            aifecCartRun($web, static function ($cart, App $app): void {
                check($app->service('customers')->login('ana@cliente.test', 'clave-larga-1234') !== null, 'el cliente entra');
            });
        }
        $result = aifecCartRun($web, static function ($cart, App $app) use ($p1, $p2): array {
            $cart->add($p1, 0, 3);
            $cart->add($p2, 0, 2);
            $cart->applyCoupon('FIJO');
            $cart->setPaymentMethod('gw');

            return $app->service('orders')->place(aifecOrderCheckout());
        });
        same(true, $result['ok'], (string) $result['error']);
        $order = $app->service('orders')->find((int) $result['id']);
        $sum = 0;
        foreach ($order['items'] as $item) {
            $sum += $item['line_net'] + $item['line_tax'];
        }
        same($order['total'], $sum, 'las líneas suman el total (incluye ' . ($incluye ? 'sí' : 'no') . ', exento ' . ($exento ? 'sí' : 'no') . ')');
        same(300, $order['fee_total']);
        check(in_array('fee', array_column($order['items'], 'kind'), true), 'el recargo es una línea');
        check(in_array('discount', array_column($order['items'], 'kind'), true), 'el descuento sale como línea');
        same($order['total_net'] + $order['total_tax'], $order['total']);
        if ($exento) {
            same(0, $order['total_tax']);
        }
    }
});

test('la dirección de envío manda el destino del cálculo y la de facturación puede ser otra', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 300]);
    $canarias = ['first_name' => 'Luis', 'last_name' => 'Pérez', 'line1' => 'Calle del Mar 2', 'postcode' => '35001', 'city' => 'Las Palmas', 'region' => '35', 'country' => 'ES', 'phone' => '999000035'];
    $web = new TestClient($app);
    $result = aifecOrderPlace($app, $web, [[$p, 0, 1]], ['shipping' => $canarias]);

    same(true, $result['ok'], (string) $result['error']);
    $order = $app->service('orders')->find((int) $result['id']);
    same('Las Palmas', $order['shipping']['city']);
    same('Madrid', $order['billing']['city']);
    $destination = json_decode((string) $app->db()->value('SELECT destination FROM {carts}'), true);
    same('35001', $destination['postcode']);
    same('35', $destination['region']);
    check($order['carrier_id'] !== null && $order['carrier_title'] === 'Estándar', 'queda el transportista elegido');
});

test('un pedido solo de digitales no necesita envío ni dirección completa', function (): void {
    $app = aifecOrderApp();
    $guia = aifecProduct($app, ['name' => 'Guía', 'kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    $web = new TestClient($app);
    $result = aifecOrderPlace($app, $web, [[$guia, 0, 1]], ['billing' => ['first_name' => 'Ana', 'country' => 'ES']]);

    same(true, $result['ok'], (string) $result['error']);
    $order = $app->service('orders')->find((int) $result['id']);
    same('not_needed', $order['fulfillment_status']);
    same(null, $order['carrier_id']);
    same([], $order['shipping']);
    same(0, $order['shipping_total']);
    same(['product'], array_column($order['items'], 'kind'));
    same(true, $order['items'][0]['is_digital']);
    same(1500, $order['total']);
});

test('place() devuelve cada fallo de validación sin escribir nada', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 300]);
    $valid = aifecOrderCheckout()['billing'];
    $cases = [
        'email' => [['email' => 'esto-no-es-un-correo'], 'email'],
        'nombre' => [['billing' => ['first_name' => '', 'last_name' => ''] + $valid], 'billing.first_name'],
        'dirección de facturación' => [['billing' => ['line1' => ''] + $valid], 'billing.line1'],
        'código postal de envío' => [['shipping' => ['postcode' => ''] + $valid], 'shipping.postcode'],
        'país de envío' => [['shipping' => ['country' => '1'] + $valid], 'shipping.country'],
        'términos' => [['accept_terms' => false], 'accept_terms'],
        'contraseña corta' => [['create_account' => true, 'password' => 'corta'], 'password'],
    ];
    foreach ($cases as $name => $case) {
        $before = aifecOrderSnapshot($app);
        $result = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], $case[0]);
        same(false, $result['ok'], 'falla: ' . $name);
        check(isset($result['errors'][$case[1]]), 'el error de «' . $name . '» llega con su campo ' . $case[1] . ': ' . json_encode(array_keys($result['errors'])));
        check($result['error'] !== '', 'hay mensaje para ' . $name);
        same(null, $result['action']);
        same(0, $result['id']);
        same($before, aifecOrderSnapshot($app), 'no se escribe nada: ' . $name);
        same(10, aifecOrderStock($app, $p));
    }
});

test('el NIF solo es obligatorio con el ajuste y los invitados pueden prohibirse', function (): void {
    $app = aifecOrderApp(['settings' => ['orders.require_vat_id' => true, 'orders.guest_checkout' => false]]);
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 300]);
    $before = aifecOrderSnapshot($app);

    $noVat = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['create_account' => true, 'password' => 'clave-larga-1234']);
    same(false, $noVat['ok']);
    check(isset($noVat['errors']['billing.vat_id']), 'falta el NIF');

    $guest = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['billing' => ['vat_id' => '12345678Z'] + aifecOrderCheckout()['billing']]);
    same(false, $guest['ok']);
    check(isset($guest['errors']['email']), 'sin cuenta no se puede comprar');
    same($before, aifecOrderSnapshot($app));

    $ok = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['create_account' => true, 'password' => 'clave-larga-1234', 'billing' => ['vat_id' => '12345678Z'] + aifecOrderCheckout()['billing']]);
    same(true, $ok['ok'], (string) $ok['error']);
});

test('un carrito vacío, sin forma de pago o con avisos no crea pedido', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 300]);

    $before = aifecOrderSnapshot($app);
    $empty = aifecCartRun(new TestClient($app), static function ($cart, App $app): array {
        return $app->service('orders')->place(aifecOrderCheckout());
    });
    same(false, $empty['ok']);
    same('Tu carrito está vacío', $empty['error']);

    $noPayment = aifecCartRun(new TestClient($app), static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 1);

        return $app->service('orders')->place(aifecOrderCheckout());
    });
    same(false, $noPayment['ok']);
    check(isset($noPayment['errors']['payment']), 'falta la forma de pago');

    $problems = aifecCartRun(new TestClient($app), static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 3);
        $cart->setPaymentMethod('gw');
        $app->db()->run('UPDATE {products} SET stock = 1 WHERE id = ?', [$p]);
        $app->invalidate(['product:' . $p]);

        return $app->service('orders')->place(aifecOrderCheckout());
    });
    same(false, $problems['ok']);
    check(isset($problems['errors']['cart']), 'el aviso del carrito impide terminar');
    $after = aifecOrderSnapshot($app);
    same($before['orders'], $after['orders']);
    same($before['order_items'], $after['order_items']);
    same($before['sequence'], $after['sequence']);
});

test('sin transportistas que lleguen al destino no se crea el pedido', function (): void {
    $app = aifecOrderApp(['carrier' => false]);
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 300]);
    $before = aifecOrderSnapshot($app);
    $result = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);

    same(false, $result['ok']);
    same('No podemos enviar a este destino', $result['error']);
    same($before['orders'], aifecOrderSnapshot($app)['orders']);
    same(10, aifecOrderStock($app, $p));
});

test('si las existencias se acaban entre el carrito y la caja el pedido falla y lo ya descontado vuelve', function (): void {
    $app = aifecOrderApp();
    $cafe = aifecProduct($app, ['name' => 'Café', 'stock' => 5, 'weight' => 100]);
    $taza = aifecProduct($app, ['name' => 'Taza', 'stock' => 5, 'weight' => 100]);
    // Otro cliente se lleva las tazas justo después de que se descuente el café.
    $app->hooks()->on('stock.changed', static function (int $productId) use ($app, $taza, $cafe): void {
        if ($productId === $cafe) {
            $app->db()->run('UPDATE {products} SET stock = 0 WHERE id = ?', [$taza]);
        }
    });
    $before = aifecOrderSnapshot($app);
    $result = aifecOrderPlace($app, new TestClient($app), [[$cafe, 0, 2], [$taza, 0, 2]]);

    same(false, $result['ok']);
    contains('Ya no quedan suficientes unidades de Taza', $result['error']);
    same(5, aifecOrderStock($app, $cafe));
    same(5, aifecOrderStock($app, $taza));
    same($before, aifecOrderSnapshot($app));
});

test('un cupón que se agota en ese instante deshace el pedido entero', function (): void {
    $app = aifecOrderApp();
    couponsMake($app, ['code' => 'UNICO', 'max_uses' => 1]);
    $p = aifecProduct($app, ['stock' => 5, 'weight' => 100]);
    // Otro pedido canjea el último uso justo después de descontar las existencias.
    $app->hooks()->on('stock.changed', static function () use ($app): void {
        $app->db()->run("UPDATE {coupons} SET uses = 1 WHERE code = 'UNICO'");
    });
    $before = aifecOrderSnapshot($app);
    $web = new TestClient($app);
    $result = aifecCartRun($web, static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 1);
        $cart->applyCoupon('UNICO');
        $cart->setPaymentMethod('gw');

        return $app->service('orders')->place(aifecOrderCheckout());
    });

    same(false, $result['ok']);
    same('Un cupón de tu carrito se acaba de agotar', $result['error']);
    same(5, aifecOrderStock($app, $p));
    $after = aifecOrderSnapshot($app);
    same($before['orders'], $after['orders']);
    same($before['sequence'], $after['sequence']);
    same(0, $after['coupon_uses']);
});

test('un cliente que ha entrado conserva su cuenta y su carrito, y pide el pedido a su nombre', function (): void {
    $app = aifecOrderApp();
    $id = aifecCartCustomer($app);
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 300]);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart, App $app): void {
        check($app->service('customers')->login('ana@cliente.test', 'clave-larga-1234') !== null, 'el cliente entra');
    });
    $result = aifecOrderPlace($app, $web, [[$p, 0, 1]], ['marketing' => false]);

    same(true, $result['ok'], (string) $result['error']);
    $order = $app->service('orders')->find((int) $result['id']);
    same($id, $order['customer_id']);
    same('ana@cliente.test', $order['customer']['email']);
    same(1, aifecOrderCount($app, 'customers'));
    same(['terms'], array_column($app->db()->select('SELECT kind FROM {consents} WHERE customer_id = ?', [$id]), 'kind'));
});

test('pedir cuenta al comprar crea la cuenta; con un correo que ya tiene cuenta el pedido sigue y la contraseña no cambia', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 300]);

    $new = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['email' => 'luis@cliente.test', 'create_account' => true, 'password' => 'clave-larga-1234']);
    same(true, $new['ok'], (string) $new['error']);
    $account = $app->db()->row('SELECT * FROM {customers} WHERE email = ?', ['luis@cliente.test']);
    same(0, (int) $account['guest']);
    check((string) $account['password'] !== '' && strpos((string) $account['password'], 'clave-larga-1234') === false, 'la contraseña se guarda con resumen');
    same((int) $account['id'], $app->service('orders')->find((int) $new['id'])['customer_id']);

    $existing = aifecCartCustomer($app, 'marta@cliente.test');
    $passwordBefore = (string) $app->db()->value('SELECT password FROM {customers} WHERE id = ?', [$existing]);
    $again = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], ['email' => 'marta@cliente.test', 'create_account' => true, 'password' => 'otra-clave-larga-99']);
    same(true, $again['ok'], (string) $again['error']);
    same($existing, $app->service('orders')->find((int) $again['id'])['customer_id']);
    same($passwordBefore, (string) $app->db()->value('SELECT password FROM {customers} WHERE id = ?', [$existing]));
});

test('el origen del pedido solo admite los conocidos y la IP tiene que ser válida', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 300]);

    $mcp = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], [], ['source' => 'mcp', 'ip' => 'no-es-una-ip']);
    $odd = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]], [], ['source' => 'otra-cosa']);

    $orders = $app->service('orders');
    same('mcp', $orders->find((int) $mcp['id'])['source']);
    same(null, $orders->find((int) $mcp['id'])['ip']);
    same('web', $orders->find((int) $odd['id'])['source']);
});
