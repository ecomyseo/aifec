<?php
/** AIFEC — consultas privadas de pedidos e incidencias de entrega. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../customers/tests/CustomersKit.php';

test('los pedidos por fechas y su seguimiento solo salen para su titular y se excluyen del historial de la IA', function (): void {
    $app = assistantApp([], ['modules' => ['customers']]);
    $app->boot();
    $account = customersMakeAccount($app, 'cuenta@ejemplo.test', 'clave-larga-1');
    $other = customersMakeAccount($app, 'otro@ejemplo.test', 'clave-larga-1');
    foreach ([[$account, '2026-000301', '2026-10-19'], [$other, '2026-000302', '2026-10-19'], [$account, '2026-000303', '2026-11-15']] as [$customer, $number, $day]) {
        $date = $day . ' 12:00:00';
        $app->db()->insert('orders', ['customer_id' => $customer, 'email' => 'compra@ejemplo.test', 'number' => $number, 'placed_at' => $date,
            'created_at' => $date, 'updated_at' => $date, 'tracking_code' => 'SEG-' . $number, 'carrier_title' => 'Transporte de prueba']);
    }
    $web = new TestClient($app);
    $q = 'Mis pedidos entre 2026-10-01 y 2026-10-31';
    notContains('000301', assistantSay($web, $q)['text']);
    $web->post('/mi-cuenta/entrar', ['email' => 'cuenta@ejemplo.test', 'password' => 'clave-larga-1']);
    $r = assistantSay($web, $q);
    contains('SEG-2026-000301', $r['text']);
    contains('Transporte de prueba', $r['text']);
    notContains('000302', $r['text']);
    notContains('000303', $r['text']);
    check(in_array(['type' => 'private_turn'], $r['actions'], true));
    notContains('SEG-2026', json_encode(assistantRows($app, 'conversation_messages')));
    contains('No veo pedidos', assistantSay($web, 'Mis pedidos del 2026-10-01')['text']);
    contains('cronológico', assistantSay($web, 'Mis pedidos del 2026-02-30')['text']);
    $late = assistantSay($web, 'Mi pedido 2026-000301 no me ha llegado');
    contains('Referencia facilitada por el cliente: 2026-000301', $late['text']);
    contains('resumen', $late['text']);
});
