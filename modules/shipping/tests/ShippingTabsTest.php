<?php
/**
 * AIFEC — pruebas de «Envío» como una sola entrada del menú con cuatro pestañas, de las direcciones de antes y del precio de una
 * tarifa cambiado en su fila (con y sin JavaScript) con el ejemplo «un pedido de 1 kg a Madrid».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/ShippingKit.php';

/**
 * Tienda con un transportista de tabla (tres tramos de peso en la Península) y otro de recogida.
 *
 * @return array{0:App,1:TestClient,2:int,3:array<string,int>} aplicación, navegador, transportista y los ids de sus tarifas
 */
function shippingTabsShop(): array
{
    $app = shippingTestApp();
    $web = adminClient($app);
    $carrier = shippingAddCarrier($app, 'mensajeros', ['name' => 'Mensajeros & Co', 'delay' => '24-48 h']);
    $zone = shippingZoneId($app, 'Peninsula');
    $rates = [
        'light' => shippingAddRate($app, $carrier, $zone, 'weight', 0, 1000, 495),
        'medium' => shippingAddRate($app, $carrier, $zone, 'weight', 1001, 5000, 795),
        'heavy' => shippingAddRate($app, $carrier, $zone, 'weight', 5001, 0, 1295),
    ];

    return [$app, $web, $carrier, $rates];
}

/** POST de la fila de una tarifa con la ficha de la página de la pestaña, como el navegador (con o sin JavaScript). */
function shippingTabsPost(TestClient $web, int $rate, string $price, string $op, bool $json = false): \Aifec\Kernel\Response
{
    $web->get('/admin/shipping/zones');

    return $web->post('/admin/shipping/rates/' . $rate, ['_token' => aifecToken($web), 'price' => $price, 'op' => $op], $json ? ['accept' => 'application/json'] : []);
}

function shippingTabsPrice(App $app, int $rate): int
{
    return (int) $app->db()->value('SELECT price FROM {carrier_rates} WHERE id = ?', [$rate]);
}

test('Envío es una sola entrada del menú y cada pestaña enseña solo lo suyo', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);

    $menu = $web->get('/admin')->body;
    same(1, preg_match_all('#href="/admin/shipping(/[a-z]*)?"#', $menu), 'una sola entrada de envío en el menú');

    $tabs = ['carriers' => 'Transportistas', 'zones' => 'Zonas y tarifas', 'settings' => 'Ajustes', 'test' => 'Probar un envío'];
    foreach ($tabs as $key => $label) {
        $r = $web->get('/admin/shipping/' . $key);
        same(200, $r->status, $key);
        foreach ($tabs as $other => $otherLabel) {
            contains('href="/admin/shipping/' . $other . '"', $r->body, 'la pestaña ' . $other . ' se ve desde ' . $key);
        }
        same(1, preg_match_all('/aria-selected="true"/', $r->body), 'solo una pestaña marcada en ' . $key);
        check(preg_match('#<a class="af-tab" role="tab" href="/admin/shipping/' . $key . '" aria-selected="true"#', $r->body) === 1, 'la marcada es la de ' . $key);
    }
    notContains('name="price"', $web->get('/admin/shipping/carriers')->body, 'los transportistas no traen campos de tarifa');
    notContains('shpreview', $web->get('/admin/shipping/test')->body);

    $r = $web->get('/admin/shipping');
    same(302, $r->status);
    contains('/admin/shipping/carriers', $r->headers['Location']);
    $other = $web->get('/admin/shipping/nada');
    same(404, $other->status, 'una pestaña que no existe no es una pantalla');
});

test('las direcciones de antes siguen funcionando y llevan a su pestaña', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    foreach (['/admin/r/carriers' => '/admin/shipping/carriers', '/admin/r/shipping_zones' => '/admin/shipping/zones', '/admin/settings/shipping' => '/admin/shipping/settings'] as $old => $new) {
        $r = $web->get($old);
        same(302, $r->status, $old);
        contains($new, $r->headers['Location'], $old . ' redirige a ' . $new);
        same(200, $web->follow()->status, 'y la pestaña se abre');
    }
    same(302, $web->get('/admin/r/carriers?f[active]=1&q=x')->status, 'con filtros también');

    // Lo que no se ha movido sigue donde estaba: el alta y la ficha de un transportista o de una zona.
    same(200, $web->get('/admin/r/carriers/new')->status);
    same(200, $web->get('/admin/r/shipping_zones/new')->status);
    $zone = shippingZoneId($app, 'Peninsula');
    same(200, $web->get('/admin/r/shipping_zones/' . $zone)->status);

    // Guardar un transportista vuelve al listado de siempre, que ahora es su pestaña.
    $web->get('/admin/r/carriers/new');
    $r = $web->post('/admin/r/carriers/save', ['_token' => aifecToken($web), '_then' => 'list', 'f' => ['name' => 'Uno', 'code' => 'uno', 'handler' => 'rates', 'delay' => '', 'max_weight' => '0', 'free_from' => '', 'tax_class_id' => '', 'tracking_url' => '', 'position' => '0', 'active' => '1', 'rates' => []]]);
    same(302, $r->status);
    $web->get($r->headers['Location']);
    same(302, $web->last->status, 'el listado de antes redirige');
    contains('Uno', $web->follow()->body, 'y el transportista nuevo sale en su pestaña');
});

test('en la pestaña de tarifas, cada una lleva su fila con el precio editable', function (): void {
    list($app, $web, , $rates) = shippingTabsShop();
    $body = $web->get('/admin/shipping/zones')->body;
    foreach ($rates as $id) {
        contains('action="/admin/shipping/rates/' . $id . '"', $body, 'el formulario de la fila ' . $id);
        contains('id="rate-' . $id . '"', $body);
    }
    same('4,95', inputValue($body, 'price'), 'el primer precio tal como se escribe');
    contains('data-af-ajax', $body, 'con JavaScript se envía sin recargar');
    contains('data-af-target="#shpreview"', $body, 'y repinta la tarjeta de muestra');
    contains('Mensajeros &amp; Co', $body, 'el «&» sale escapado');
    notContains('Mensajeros & Co', $body);
    contains('De 0 g a 1000 g', $body, 'el tramo en palabras');
    contains('Desde 5001 g', $body);
    contains('Península', $body, 'las zonas salen en la misma pestaña');
    contains('Un pedido de 1 kg a Madrid paga', $body, 'y al lado, el ejemplo');
    contains('4,95', $body);
});

test('cambiar el precio de una tarifa sin JavaScript lo guarda, vuelve a la pestaña y dice lo que pagaría 1 kg a Madrid', function (): void {
    list($app, $web, , $rates) = shippingTabsShop();
    $r = shippingTabsPost($web, $rates['light'], '5,50', 'save');
    same(302, $r->status);
    contains('/admin/shipping/zones#rate-' . $rates['light'], $r->headers['Location']);
    same(550, shippingTabsPrice($app, $rates['light']), 'el precio nuevo, en céntimos');
    same(795, shippingTabsPrice($app, $rates['medium']), 'las demás no se tocan');

    $page = $web->follow()->body;
    contains('Con este cambio, un pedido de 1 kg a Madrid pagaría', $page);
    contains('Mensajeros &amp; Co 5,50', $page, 'con el precio ya cambiado y el nombre escapado');
    same('5,50', inputValue($page, 'price'));
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'update' AND entity = 'carrier_rates' AND entity_id = ?", [(string) $rates['light']]), 'queda en el registro de actividad');
    same(550, (int) shippingService($app)->options(shippingPackage(['weight' => 1000]), shippingDest('ES', '28001', '28'))[0]['price'], 'y el servicio de envíos ya cobra eso');
});

test('«ver el efecto» enseña lo que pagaría el pedido de muestra con el precio escrito y no guarda nada', function (): void {
    list($app, $web, , $rates) = shippingTabsShop();
    $r = shippingTabsPost($web, $rates['light'], '9,90', 'check');
    same(200, $r->status, 'sin JavaScript, la misma pestaña con el resultado');
    contains('Con este cambio, un pedido de 1 kg a Madrid pagaría', $r->body);
    contains('9,90', $r->body);
    same('9,90', inputValue($r->body, 'price'), 'el campo conserva lo que se escribió');
    same(495, shippingTabsPrice($app, $rates['light']), 'la base de datos sigue igual');
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE entity = 'carrier_rates'"), 'y no hay nada en el registro');
    same(495, (int) shippingService($app)->options(shippingPackage(['weight' => 1000]), shippingDest('ES', '28001', '28'))[0]['price'], 'ni en el servicio');
    check(!$app->db()->inTransaction(), 'la prueba no deja una transacción abierta');
});

test('con JavaScript la respuesta es JSON con el aviso y la tarjeta repintada', function (): void {
    list($app, $web, , $rates) = shippingTabsShop();
    $r = shippingTabsPost($web, $rates['light'], '7,25', 'check', true);
    same(200, $r->status);
    $data = $web->json();
    same(true, $data['ok']);
    contains('Con este cambio, un pedido de 1 kg a Madrid pagaría: Mensajeros & Co 7,25', $data['message']);
    contains('7,25', $data['html']);
    contains('Con este cambio', $data['html']);
    same(495, shippingTabsPrice($app, $rates['light']));

    $r = shippingTabsPost($web, $rates['light'], '7,25', 'save', true);
    same(200, $r->status);
    $data = $web->json();
    same(true, $data['ok']);
    contains('7,25', $data['message']);
    contains('Un pedido de 1 kg a Madrid paga', $data['html']);
    notContains('&amp;amp;', $data['html'], 'el HTML de la tarjeta no se escapa dos veces');
    contains('Mensajeros &amp; Co', $data['html']);
    same(725, shippingTabsPrice($app, $rates['light']));
});

test('un precio que no es un importe, negativo o enorme no se guarda y se dice', function (): void {
    list($app, $web, , $rates) = shippingTabsShop();
    foreach (['', 'mucho', '-3', '1e3', '123456789,00'] as $bad) {
        $r = shippingTabsPost($web, $rates['light'], $bad, 'save');
        same(302, $r->status, '«' . $bad . '»');
        $page = $web->follow()->body;
        contains('Escribe un importe', $page, '«' . $bad . '» avisa');
        same(495, shippingTabsPrice($app, $rates['light']), '«' . $bad . '» no cambia nada');

        $r = shippingTabsPost($web, $rates['light'], $bad, 'check', true);
        same(422, $r->status);
        same(false, $web->json()['ok']);
    }
    same(0, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE entity = 'carrier_rates'"));
    same(404, shippingTabsPost($web, 99999, '1,00', 'save')->status, 'una tarifa que no existe');
});

test('el ejemplo usa la prueba de envío: sin transportista o sin zona lo dice, y un tramo vacío no cobra de más', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $body = $web->get('/admin/shipping/zones')->body;
    contains('Todavía no hay tarifas', $body);
    contains('Ningún transportista puede enviar un pedido de 1 kg a Madrid.', $body);

    $carrier = shippingAddCarrier($app, 'solo-islas');
    $canarias = shippingZoneId($app, 'Canary Islands');
    $rate = shippingAddRate($app, $carrier, $canarias, 'weight', 0, 0, 1500);
    $body = $web->get('/admin/shipping/zones')->body;
    contains('Ningún transportista puede enviar un pedido de 1 kg a Madrid.', $body, 'una tarifa de Canarias no sirve para Madrid');
    $r = shippingTabsPost($web, $rate, '1,00', 'check');
    contains('Ningún transportista puede enviar un pedido de 1 kg a Madrid.', $r->body);

    // Una tarifa gratis a partir de cierto importe: el pedido de muestra no llega, así que paga.
    list($app2, $web2, $carrier2, $rates) = shippingTabsShop();
    $app2->db()->update('carriers', ['free_from' => 0], ['id' => $carrier2]);
    $body = $web2->get('/admin/shipping/zones')->body;
    contains('Gratis', $body, 'con «gratis desde 0» el pedido de muestra sale gratis');
});

test('quien solo puede ver las tarifas las ve sin campos y no puede cambiarlas; sin permiso, nada', function (): void {
    list($app, $web, , $rates) = shippingTabsShop();
    $app->db()->insert('roles', ['code' => 'shipviewer', 'name' => 'Viewer', 'caps' => json_encode(['dashboard.view', 'shipping.view']), 'system' => 0]);
    $viewer = adminClient($app, 'shipviewer');

    $body = $viewer->get('/admin/shipping/zones')->body;
    contains('4,95', $body, 've el precio');
    notContains('name="price"', $body, 'pero no el campo para cambiarlo');
    notContains('/admin/shipping/rates/', $body);
    notContains('href="/admin/shipping/settings"', $body, 'ni la pestaña de ajustes');
    same(403, $viewer->get('/admin/shipping/settings')->status);
    same(403, $viewer->get('/admin/settings/shipping')->status);
    $viewer->get('/admin/shipping/zones');
    same(403, $viewer->post('/admin/shipping/rates/' . $rates['light'], ['_token' => aifecToken($viewer), 'price' => '1,00', 'op' => 'save'])->status);
    same(495, shippingTabsPrice($app, $rates['light']));
    notContains('/admin/r/carriers/new', $viewer->get('/admin/shipping/carriers')->body, 'ni el botón de añadir');

    $support = adminClient($app, 'support');
    $support->get('/admin');
    same(403, $support->post('/admin/shipping/rates/' . $rates['light'], ['_token' => aifecToken($support), 'price' => '1,00', 'op' => 'save'])->status);
    same(495, shippingTabsPrice($app, $rates['light']));

    // Sin la ficha de formulario no se acepta.
    $web->get('/admin/shipping/zones');
    $r = $web->post('/admin/shipping/rates/' . $rates['light'], ['price' => '1,00', 'op' => 'save']);
    same(302, $r->status, 'sin ficha, el panel vuelve a la página con un aviso');
    same(495, shippingTabsPrice($app, $rates['light']));
});

test('la pestaña de ajustes guarda en la dirección de siempre y vuelve a su pestaña', function (): void {
    $app = shippingTestApp();
    $web = adminClient($app);
    $body = $web->get('/admin/shipping/settings')->body;
    contains('action="/admin/settings/shipping"', $body);
    $r = $web->post('/admin/settings/shipping', ['_token' => aifecToken($web), 'f' => ['shipping.default_country' => 'FR', 'shipping.estimate_in_cart' => '1']]);
    same(302, $r->status);
    contains('/admin/settings/shipping', $r->headers['Location']);
    $web->follow();
    same(302, $web->last->status);
    contains('/admin/shipping/settings', $web->last->headers['Location'], 'el aviso de guardado se enseña en la pestaña');
    $app->settings()->reload();
    same('FR', shippingService($app)->defaultCountry());
    check(preg_match('/<option value="FR"[^>]*selected/', $web->follow()->body) === 1, 'Francia marcada al volver');
});
