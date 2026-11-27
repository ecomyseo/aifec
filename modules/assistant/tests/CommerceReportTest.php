<?php
/** AIFEC — consentimiento, una valoración por sesión y atribución de los pedidos pagados. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../datalayer/tests/helpers.php';

test('el panel cuenta compras pagadas, reembolsos y valoraciones sin duplicar clientes ni mezclar monedas', function (): void {
    $app = dlApp(['modules' => ['assistant', 'search']]);
    $product = aifecProduct($app, ['name' => 'Silla de exposición', 'price' => 2500]);
    $web = dlWeb($app, 's');
    $guest = dlWeb($app);
    same(404, $guest->postJson('/asistente/valorar', ['vote' => 'up'])->status);
    assistantSay($web, 'Hola');
    same(400, $web->postJson('/asistente/valorar', ['vote' => 'down', 'reason' => 'dato arbitrario'])->status);
    foreach (['down', 'up'] as $vote) { same(200, $web->postJson('/asistente/valorar', ['vote' => $vote, 'reason' => 'clarity'])->status); }
    $add = ['add' => ['product_id' => $product]];
    $guest->postJson('/asistente/mensaje', $add);
    check(!in_array('analytics', array_column($guest->json()['actions'], 'type'), true));
    $web->postJson('/asistente/mensaje', $add);
    $analytics = array_values(array_filter($web->json()['actions'], static function ($a) { return $a['type'] === 'analytics'; }));
    same('add_to_cart', $analytics[0]['event']['event']);
    same((string) $product, $analytics[0]['event']['ecommerce']['items'][0]['item_id']);
    same(1, $analytics[0]['event']['ecommerce']['items'][0]['quantity']);
    $first = dlOrder($app, $web, true, [], [[$product, 0, 1]]);
    $second = dlOrder($app, $web, true, [], [[$product, 0, 1]]);
    dlOrder($app, $web, false, [], [[$product, 0, 1]]);
    $db = $app->db();
    $db->update('orders', ['currency' => 'USD', 'payment_status' => 'partially_refunded', 'refunded_total' => 150], ['id' => $second['id']]);
    $report = \Aifec\Modules\Assistant\CommerceReport::week($app);
    same(1, $report['converted']);
    same(1, (int) $report['votes']);
    same(1, (int) $report['positive']);
    same(2, count($report['revenue']));
    $amount = (int) $db->value('SELECT total FROM {orders} WHERE id = ?', [$second['id']]);
    check(in_array($app->i18n()->money($amount - 150, 'USD'), $report['revenue'], true));
    contains('Resultados comerciales', adminClient($app)->get('/admin/assistant')->body);
    $event = dlEvent(dlEvents($web->get($first['url'])->body), 'purchase');
    same('assistant', $event['source']);
});

test('la estimación de coste se atribuye solo al turno que invocó el proveedor simulado', function (): void {
    $app = assistantApp(['ai.enabled' => true, 'ai.economy.provider' => 'fake', 'ai.economy.price_in' => 100, 'ai.economy.price_out' => 400], ['modules' => ['ai']]);
    $app->boot();
    $fake = new \Aifec\Ai\FakeProvider();
    $app->bind('ai.provider.economy', static function () use ($fake) { return $fake; });
    $fake->script(new \Aifec\Ai\AiResult('Puedo ayudarte a comparar las sillas.', [], null, 1000, 500, 'stop', 'fake'));
    assistantSay(new TestClient($app), 'Necesito ayuda para elegir');
    $total = (int) $app->db()->value('SELECT SUM(cost_micro) FROM {ai_usage}');
    check($total > 0, 'el proveedor de prueba tiene precios de referencia');
    same($total, (int) assistantRows($app, 'conversations')[0]['ai_cost_micro']);
    assistantSay(new TestClient($app), '¿Puedo usar la crema durante el embarazo?');
    same(null, assistantRows($app, 'conversations')[1]['ai_cost_micro']);
});
