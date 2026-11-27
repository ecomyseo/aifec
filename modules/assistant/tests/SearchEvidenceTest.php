<?php
/** AIFEC — un pedido pagado permite sugerir una corrección para que una persona la revise. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../consent/tests/helpers.php';

test('una búsqueda consentida seguida de compra propone un sinónimo sin activarlo y descarta las compras devueltas', function (): void {
    $app = assistantApp([], ['modules' => ['consent']]);
    $app->boot();
    $id = aifecProduct($app, ['name' => 'Notebook de muestra']);
    searchService($app)->reindexNow();
    $web = new TestClient($app);
    assistantSay($web, 'Busco notebok');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {conversation_messages} WHERE learning_term IS NOT NULL'));
    $web->cookies[$app->cookieName('consent')] = consentCookie($app, 's');
    assistantSay($web, 'Busco notebok');
    $conv = $app->service('assistant')->conversations()->findByToken($web->cookies[$app->cookieName('as')]);
    $now = $app->clock()->sql();
    $order = $app->db()->insert('orders', ['number' => '2026-000599', 'email' => 'prueba@ejemplo.test', 'payment_status' => 'paid', 'placed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $app->db()->insert('order_items', ['order_id' => $order, 'product_id' => $id, 'name' => 'Notebook de muestra', 'qty' => 1]);
    $app->service('assistant')->conversations()->recordOrder((int) $conv['id'], $order);
    $rows = \Aifec\Modules\Assistant\SearchEvidence::suggestions($app);
    same('notebok', $rows[0]['source'] ?? '');
    same('notebook', $rows[0]['terms']);
    same(1, $rows[0]['count']);
    $app->db()->update('orders', ['payment_status' => 'refunded'], ['id' => $order]);
    same([], \Aifec\Modules\Assistant\SearchEvidence::suggestions($app));
});
