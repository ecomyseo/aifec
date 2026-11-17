<?php
/**
 * AIFEC — pedidos y datos personales: al anonimizar un cliente se borra lo que la ley permite (carritos, correo, notas,
 * dirección de red y, en los pedidos que nunca se cobraron, todo) y se conserva lo de los pedidos cobrados (hay factura).
 * Y sus pedidos salen en la copia de sus datos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/**
 * Un pedido mínimo metido a mano. $over pisa columnas.
 *
 * @param array<string,mixed> $over
 */
function orderPrivacyOrder(App $app, array $over = []): int
{
    static $n = 0;
    ++$n;
    $address = json_encode(['name' => 'Marta Ruiz', 'address1' => 'Calle de la Muestra 5', 'postcode' => '46000', 'city' => 'Valencia', 'country' => 'ES', 'vat' => '12345678Z']);

    return $app->db()->insert('orders', $over + [
        'number' => 'P-' . str_pad((string) $n, 6, '0', STR_PAD_LEFT), 'customer_id' => 7, 'email' => 'marta@ejemplo.test', 'status' => 'processing',
        'payment_status' => 'paid', 'total' => 12100, 'paid_total' => 12100, 'billing' => $address, 'shipping' => $address,
        'customer_note' => 'Llamar al 999000001 antes de entregar', 'ip' => '203.0.113.9', 'attribution' => '{"utm_source":"boletin"}',
        'placed_at' => '2026-11-01 10:00:00', 'paid_at' => '2026-11-01 10:01:00',
        'created_at' => '2026-11-01 10:00:00', 'updated_at' => '2026-11-01 10:01:00',
    ]);
}

/**
 * @param array<string,mixed> $over
 */
function orderPrivacyCart(App $app, array $over = []): int
{
    static $n = 0;
    ++$n;
    $now = $app->clock()->sql();

    return $app->db()->insert('carts', $over + [
        'token' => hash('sha256', 'carrito-' . $n), 'customer_id' => 7, 'items' => '[]', 'email' => 'marta@ejemplo.test',
        'checkout' => '{"billing":{"name":"Marta Ruiz"}}', 'destination' => '{"country":"ES","postcode":"46000"}', 'created_at' => $now, 'updated_at' => $now,
    ]);
}

test('al anonimizar: los carritos sin pedido se borran y los pedidos pierden correo, nota, dirección de red y origen', function (): void {
    $app = aifecCartApp();
    $db = $app->db();
    $paid = orderPrivacyOrder($app);
    $unpaid = orderPrivacyOrder($app, ['status' => 'cancelled', 'payment_status' => 'pending', 'paid_total' => 0, 'paid_at' => null]);
    $refunded = orderPrivacyOrder($app, ['payment_status' => 'refunded', 'paid_total' => 0, 'refunded_total' => 12100]);
    $guest = orderPrivacyOrder($app, ['customer_id' => null]);
    $other = orderPrivacyOrder($app, ['customer_id' => 8, 'email' => 'otra@ejemplo.test']);
    $open = orderPrivacyCart($app);
    $converted = orderPrivacyCart($app, ['order_id' => $paid]);
    $guestCart = orderPrivacyCart($app, ['customer_id' => null]);
    $otherCart = orderPrivacyCart($app, ['customer_id' => 8, 'email' => 'otra@ejemplo.test']);

    $app->hooks()->fire('customer.anonymised', 7, 'marta@ejemplo.test');

    // Carritos.
    same(null, $db->row('SELECT id FROM {carts} WHERE id = ?', [$open]), 'el carrito que no llegó a pedido se borra');
    same(null, $db->row('SELECT id FROM {carts} WHERE id = ?', [$guestCart]), 'también el de invitado con ese correo');
    $row = $db->row('SELECT * FROM {carts} WHERE id = ?', [$converted]);
    check($row !== null, 'el carrito que acabó en pedido se queda (el pedido apunta a él)');
    same([null, null, null], [$row['email'], $row['checkout'], $row['destination']], 'pero sin nada de la persona');
    $row = $db->row('SELECT * FROM {carts} WHERE id = ?', [$otherCart]);
    same('otra@ejemplo.test', $row['email'], 'el carrito de otro cliente no se toca');

    // Pedido cobrado: hay factura, se conservan sus direcciones.
    $row = $db->row('SELECT * FROM {orders} WHERE id = ?', [$paid]);
    same('deleted-' . $paid . '@anonymous.invalid', $row['email']);
    same([null, null, null], [$row['customer_note'], $row['ip'], $row['attribution']]);
    contains('Marta Ruiz', (string) $row['billing'], 'la facturación de un pedido cobrado se conserva');
    contains('Calle de la Muestra', (string) $row['shipping']);
    same(12100, (int) $row['total'], 'los importes no se tocan');

    // Devuelto entero: se cobró en su día (paid_at), así que también hubo factura.
    $row = $db->row('SELECT * FROM {orders} WHERE id = ?', [$refunded]);
    contains('Marta Ruiz', (string) $row['billing']);
    same(null, $row['ip']);

    // Nunca cobrado: no queda nada.
    $row = $db->row('SELECT * FROM {orders} WHERE id = ?', [$unpaid]);
    same([null, null, null, null, null], [$row['billing'], $row['shipping'], $row['customer_note'], $row['ip'], $row['attribution']]);
    same('deleted-' . $unpaid . '@anonymous.invalid', $row['email']);

    // El pedido de invitado con su correo también es suyo.
    $row = $db->row('SELECT * FROM {orders} WHERE id = ?', [$guest]);
    same('deleted-' . $guest . '@anonymous.invalid', $row['email']);

    // Lo de otra persona, intacto.
    $row = $db->row('SELECT * FROM {orders} WHERE id = ?', [$other]);
    same('otra@ejemplo.test', $row['email']);
    same('203.0.113.9', $row['ip']);
    contains('999000001', (string) $row['customer_note']);
});

test('anonimizar sin identificador ni correo no toca nada', function (): void {
    $app = aifecCartApp();
    $id = orderPrivacyOrder($app, ['customer_id' => null, 'email' => '']);
    $cart = orderPrivacyCart($app, ['customer_id' => null, 'email' => null]);
    $result = \Aifec\Modules\Orders\OrderPrivacy::forget($app, 0, '  ');
    same(['carts_deleted' => 0, 'carts_cleared' => 0, 'orders_cleared' => 0, 'orders_emptied' => 0], $result);
    same('203.0.113.9', $app->db()->value('SELECT ip FROM {orders} WHERE id = ?', [$id]));
    check($app->db()->row('SELECT id FROM {carts} WHERE id = ?', [$cart]) !== null, 'un carrito anónimo sin correo no es de nadie: no se borra');
});

test('la copia de datos de un cliente lleva sus pedidos con sus líneas, y solo los suyos', function (): void {
    $app = aifecCartApp();
    $db = $app->db();
    $mine = orderPrivacyOrder($app);
    $db->insert('order_items', ['order_id' => $mine, 'kind' => 'product', 'name' => 'Silla Ergo Pro', 'variant_label' => 'Negro', 'sku' => 'SIL-ERGO-NEG', 'qty' => 2, 'unit_price' => 5000, 'line_total' => 10000, 'position' => 1]);
    $db->insert('order_items', ['order_id' => $mine, 'kind' => 'product', 'name' => 'Cojín & funda', 'sku' => 'TEX-LUM', 'qty' => 1, 'unit_price' => 2100, 'line_total' => 2100, 'position' => 2]);
    orderPrivacyOrder($app, ['customer_id' => 8, 'email' => 'otra@ejemplo.test']);

    $data = $app->hooks()->apply('customer.export', ['customer' => ['id' => 7]], 7);
    same(['id' => 7], $data['customer'], 'lo que ya traía la copia sigue ahí');
    same(1, count($data['orders']));
    $order = $data['orders'][0];
    same(12100, $order['total']);
    same('marta@ejemplo.test', $order['email']);
    same('Marta Ruiz', $order['billing']['name']);
    same(['Silla Ergo Pro', 'Cojín & funda'], [$order['items'][0]['name'], $order['items'][1]['name']]);
    same([2, 5000, 10000, 'Negro'], [$order['items'][0]['qty'], $order['items'][0]['unit_price'], $order['items'][0]['total'], $order['items'][0]['variant']]);

    same([], $app->hooks()->apply('customer.export', [], 99)['orders'], 'un cliente sin pedidos lleva la lista vacía');
});
