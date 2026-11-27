<?php
/** AIFEC — diferencia real hasta el envío gratuito y prioridad del destino explícito. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../customers/tests/CustomersKit.php';

test('la cotización usa la dirección propia solo por defecto y obtiene la diferencia del carrito real', function (): void {
    $app = assistantApp([], ['modules' => ['customers']]);
    $ids = assistantShop($app);
    $id = customersMakeAccount($app, 'compra@ejemplo.test', 'clave-larga-1');
    $app->service('customers')->saveAddress($id, customersAddressData(['country' => 'ES', 'postcode' => '28001', 'city' => 'Madrid']));
    $web = new TestClient($app);
    same(303, $web->post('/mi-cuenta/entrar', ['email' => 'compra@ejemplo.test', 'password' => 'clave-larga-1'])->status);
    $app->db()->run('UPDATE {carriers} SET free_from = 6090 WHERE active = 1');
    $web->postJson('/asistente/mensaje', ['add' => ['product_id' => $ids['silla_roja']]]);
    $r = assistantSay($web, '¿Cuánto cuesta el envío?');
    contains('tu dirección de entrega guardada', $r['text']);
    contains('1,00', $r['text']);
    check(count($r['cards']) > 0, 'un artículo disponible completa el envío gratis');
    check(!in_array($ids['silla_roja'], array_column($r['cards'], 'id'), true));
    same(1, count(assistantCartLines($app, $web)), 'la propuesta no modifica el carrito');
    notContains('28001', $r['text']);
    $island = assistantSay($web, '¿Enviáis a Canarias?');
    contains('Canarias', $island['text']);
    notContains('dirección de entrega guardada', $island['text']);
    $app->db()->run('UPDATE {carriers} SET free_from = 5900 WHERE active = 1');
    $r = assistantSay($web, '¿Cuánto cuesta el envío a Madrid 28001?');
    notContains('Faltan', $r['text']);
    contains('Gratis', $r['text']);
});
