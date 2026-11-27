<?php
/** AIFEC — el inicio público y las decisiones privadas de la caja desde el navegador. */
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../customers/tests/CustomersKit.php';

test('la bienvenida consulta el catálogo al abrir, reconoce las rebajas reales y permite abrir el chat independiente', function (): void {
    $app = assistantApp();
    $app->boot();
    $web = new TestClient($app);
    same(200, $web->get('/asistente/inicio')->status);
    same(['Envíos y devoluciones', '¿Dónde está mi pedido?'], $web->json()['suggestions']);
    aifecProduct($app, ['name' => 'Bolsa rebajada', 'price' => 3200, 'sale_price' => 2500]);
    $web->get('/asistente/inicio');
    check(in_array('Ofertas', $web->json()['suggestions'], true));
    $reply = assistantSay($web, 'Ofertas');
    same('Bolsa rebajada', $reply['cards'][0]['name']);
    $window = $web->get('/asistente/ventana');
    same(200, $window->status);
    contains('data-af-chat-window', $window->body);
    contains('data-af-assistant', $window->body);
    $app->settings()->set('assistant.enabled', false);
    same(404, $web->get('/asistente/inicio')->status);
    same(404, $web->get('/asistente/ventana')->status);
});

test('el cliente elige su dirección incluso tras otra pregunta y puede solicitar una nueva o cancelar', function (): void {
    foreach ([false, true] as $model) {
        $app = assistantApp([], ['modules' => ['customers']]);
        $ids = assistantShop($app);
        if ($model) { assistantAi($app); }
        $id = customersMakeAccount($app, 'cuenta@ejemplo.test', 'clave-larga-1');
        $app->service('customers')->saveAddress($id, customersAddressData(['country' => 'ES', 'postcode' => '28001', 'city' => 'Madrid']));
        $web = new TestClient($app);
        $web->post('/mi-cuenta/entrar', ['email' => 'cuenta@ejemplo.test', 'password' => 'clave-larga-1']);
        $web->postJson('/asistente/mensaje', ['add' => ['product_id' => $ids['silla_roja']]]);
        $offer = assistantSay($web, 'Hacer mi pedido aquí');
        contains('dirección de entrega guardada', $offer['text']);
        check(in_array(t('Use another address'), $offer['suggestions'], true), json_encode($offer, JSON_UNESCAPED_UNICODE));
        assistantSay($web, '¿Envíos y devoluciones?');
        $selected = assistantSay($web, 'Sí, a esa dirección');
        contains('Usaré tu dirección', $selected['text']);
        check(!empty($selected['private_history']), 'privacidad de la dirección elegida');
        assistantSay($web, 'Cancelar');
        assistantSay($web, 'Hacer mi pedido aquí');
        $other = assistantSay($web, 'Otra dirección');
        contains('nueva dirección', $other['text']);
        $cancel = assistantSay($web, 'Cancelar');
        contains('no hago el pedido', $cancel['text']);
        same(1, count(assistantCartLines($app, $web)));
        $private = assistantSay($web, 'Mi correo es privado@ejemplo.test');
        check(!empty($private['private_history']), 'privacidad del correo');
    }
});
