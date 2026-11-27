<?php
/** AIFEC — privacidad de los pedidos y complementos elegidos por la tienda. */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../customers/tests/CustomersKit.php';

test('la reposición muestra solo compras propias, pagadas, vigentes y con plazo cumplido', function (): void {
    $app = assistantApp(['assistant.replenishment' => true], ['modules' => ['customers', 'mail']]);
    $app->boot();
    $customer = customersMakeAccount($app, 'cliente@ejemplo.test', 'clave-larga-1');
    $other = customersMakeAccount($app, 'otro@ejemplo.test', 'clave-larga-1');
    $buy = static function (int $owner, int $id, int $days, array $order = [], int $refunded = 0) use ($app): void {
        static $number = 0;
        $date = $app->clock()->sql($app->clock()->now() - $days * 86400);
        $orderId = $app->db()->insert('orders', $order + [
            'number' => 'REP-' . ++$number, 'customer_id' => $owner, 'email' => 'tienda@ejemplo.test',
            'status' => 'completed', 'payment_status' => 'paid', 'placed_at' => $date, 'created_at' => $date, 'updated_at' => $date,
        ]);
        $app->db()->insert('order_items', ['order_id' => $orderId, 'name' => 'Compra', 'product_id' => $id, 'refunded_qty' => $refunded]);
    };
    $ids = [];
    foreach (['debido', 'reciente', 'ajeno', 'impagado', 'prueba', 'devuelto', 'cancelado', 'agotado'] as $key) {
        $ids[$key] = aifecProduct($app, ['name' => 'Artículo ' . $key, 'price' => 1550, 'manage_stock' => 1, 'stock' => $key === 'agotado' ? 0 : 9]);
        $app->db()->update('products', ['replenishment_days' => 30], ['id' => $ids[$key]]);
    }
    $buy($customer, $ids['debido'], 30);
    $buy($customer, $ids['reciente'], 80);
    $buy($customer, $ids['reciente'], 4);
    $buy($other, $ids['ajeno'], 90);
    $buy($customer, $ids['impagado'], 90, ['payment_status' => 'pending']);
    $buy($customer, $ids['prueba'], 90, ['test' => 1]);
    $buy($customer, $ids['devuelto'], 90, [], 1);
    $buy($customer, $ids['cancelado'], 90, ['status' => 'cancelled']);
    $buy($customer, $ids['agotado'], 90);
    $web = new TestClient($app);
    same([], assistantSay($web, '¿Qué puedo reponer?')['cards']);
    same(303, $web->post('/mi-cuenta/entrar', ['email' => 'cliente@ejemplo.test', 'password' => 'clave-larga-1'])->status);
    $reply = assistantSay($web, '¿Qué puedo reponer?');
    same([$ids['debido']], array_column($reply['cards'], 'id'));
    check(in_array(['type' => 'private_turn'], $reply['actions'], true));
    same(1550, $reply['cards'][0]['price_cents']);
    $saved = assistantRows($app, 'conversations')[0];
    notContains('debido', (string) $saved['state']);
    notContains('debido', json_encode(assistantRows($app, 'conversation_messages')));
});

test('la venta cruzada respeta el interruptor, los vínculos, el stock y las sugerencias ya vistas', function (): void {
    foreach ([false, true] as $enabled) {
        $app = assistantApp(['assistant.cross_sell' => $enabled]);
        $ids = assistantShop($app);
        foreach (['silla_azul', 'trampa'] as $position => $key) {
            $app->db()->insert('product_relations', ['product_id' => $ids['silla_roja'], 'related_id' => $ids[$key], 'kind' => 'cross', 'position' => $position]);
        }
        $web = new TestClient($app);
        $add = ['add' => ['product_id' => $ids['silla_roja']]];
        $web->postJson('/asistente/mensaje', $add);
        same($enabled ? [$ids['trampa']] : [], array_column($web->json()['cards'], 'id'));
        assistantSay($web, '¿Cuál es vuestro horario?');
        $web->postJson('/asistente/mensaje', $add);
        same([], array_column($web->json()['cards'], 'id'));
        same(1, count(assistantCartLines($app, $web)));
    }
});
