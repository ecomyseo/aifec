<?php
/** AIFEC — editar la búsqueda y guardar un presupuesto únicamente con una decisión de la cuenta. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../customers/tests/CustomersKit.php';

test('los criterios se pueden quitar y el presupuesto de cuenta exige sesión, consentimiento y propietario', function (): void {
    $app = assistantApp([], ['modules' => ['customers']]);
    $ids = assistantShop($app);
    $one = new TestClient($app);
    $two = new TestClient($app);
    $one->postJson('/asistente/mensaje', ['message' => 'Busco sillas por menos de 120 euros', 'criteria' => 1]);
    $initial = $one->json()['preferences'];
    check(!empty($initial['chips']), 'hay criterios de la búsqueda');
    $key = key($initial['chips']);
    $one->postJson('/asistente/preferencias', ['remove' => $key]);
    check(!isset($one->json()['preferences']['chips'][$key]));
    same(404, $two->postJson('/asistente/preferencias', ['save_budget' => 1, 'max_price' => 1000])->status);
    $account = customersMakeAccount($app, 'filtro@ejemplo.test', 'clave-larga-1');
    $one->post('/mi-cuenta/entrar', ['email' => 'filtro@ejemplo.test', 'password' => 'clave-larga-1']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {assistant_preferences}'));
    $one->postJson('/asistente/preferencias', ['save_budget' => 1, 'max_price' => 4000]);
    same(4000, $one->json()['preferences']['budget']);
    $two->get('/asistente/inicio');
    same(0, $two->json()['preferences']['budget']);
    $one->postJson('/asistente/mensaje', ['message' => 'Busco sillas', 'criteria' => 1]);
    $reply = $one->json();
    check(!empty($reply['preferences']['chips']['account']));
    foreach ($reply['cards'] as $card) { check($card['price_cents'] <= 4000); }
    $two->postJson('/asistente/preferencias', ['remove' => 'account', 'customer_id' => $account]);
    same(404, $two->last->status);
    $one->postJson('/asistente/preferencias', ['remove' => 'account']);
    same(0, $one->json()['preferences']['budget']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {assistant_preferences}'));
});
