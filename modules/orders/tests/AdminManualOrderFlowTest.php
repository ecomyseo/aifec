<?php
/**
 * AIFEC — pruebas del pedido a mano (P103): la pantalla y el pedido completo por HTTP, con cada camino de pago, comparado al
 * céntimo con el mismo carrito comprado en la tienda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminManualOrderKit.php';

if (!function_exists('moFlowAdminId')) {
    function moFlowAdminId(\Aifec\Kernel\App $app): int
    {
        return (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");
    }

    function moFlowVariantStock(\Aifec\Kernel\App $app, int $variantId): int
    {
        return (int) $app->db()->value('SELECT stock FROM {variants} WHERE id = ?', [$variantId]);
    }
}

test('la pantalla «Pedido nuevo» se abre con sus siete pasos y el resumen', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    $r = $web->get('/admin/orders/new');
    same(200, $r->status);
    foreach (['mo-customer', 'mo-products', 'mo-delivery', 'mo-shipping', 'mo-coupon', 'mo-payment', 'mo-confirm'] as $id) {
        contains('id="' . $id . '"', $r->body, 'el paso ' . $id);
    }
    contains('data-mo-rows', $r->body, 'el resumen');
    contains('<title>Pedido nuevo', $r->body);
    contains('1. Cliente', $r->body);
    contains('7. Confirmar', $r->body);
    contains('admin-manual-order.js', $r->body);
    contains('admin-manual-order.css', $r->body);
    check(preg_match('/<button type="submit" class="af-btn af-btn-primary" disabled>/', $r->body) === 1, 'sin cliente ni productos, «Crear el pedido» está apagado');
});

test('«Ya está pagado»: nace pagado, con su apunte de cobro, origen «admin», la persona del equipo y su nota', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    $q = moReady($web, $s, [[$s['shirt'], $s['v']['CAM-M'], 2], [$s['book'], 0, 1]], 'paid', ['paid_with' => 'Bizum']);
    same(true, $q['ready'], 'listo: ' . json_encode($q['missing']) . json_encode($q['problems']));
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {orders}');
    $r = moPlace($web);
    same(303, $r->status, 'lleva a la ficha');
    same($before + 1, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'));
    $order = moLastOrder($app);
    contains('/admin/orders/' . (int) $order['id'], (string) $r->headers['Location']);
    same('paid', (string) $order['payment_status'], 'pagado');
    same('processing', (string) $order['status'], 'en preparación');
    same($q['total'], (int) $order['paid_total']);
    same('admin', (string) $order['source']);
    same('admin', (string) $order['attribution']['channel']);
    same(moFlowAdminId($app), (int) $order['attribution']['user_id'], 'quién lo hizo, en la atribución');
    same($q['total'], (int) $order['total']);
    same((int) $s['ana'], (int) $order['customer_id']);
    same('in_person', (string) $order['payment_method']);
    contains('Bizum', (string) $order['payment_title']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {order_payments} WHERE order_id = ? AND kind = ?', [(int) $order['id'], 'charge']), 'un apunte de cobro');
    same('panel-' . (string) $order['number'], (string) $app->db()->value('SELECT reference FROM {order_payments} WHERE order_id = ?', [(int) $order['id']]));
    $notes = [];
    foreach ($order['history'] as $entry) {
        $notes[] = (string) $entry['note'] . '|' . ($entry['user_id'] !== null ? (int) $entry['user_id'] : 0);
    }
    contains('Creado en el panel por Admin.|' . moFlowAdminId($app), implode("\n", $notes), 'el historial dice quién lo creó');
    contains('Pagado en persona: Bizum', implode("\n", $notes));
});

test('una forma de pago con instrucciones (transferencia): el pedido nace en espera, como en la tienda', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    $q = moReady($web, $s, [[$s['mug'], 0, 1]], 'method:transfer');
    same(true, $q['ready'], json_encode($q['missing']));
    same(303, moPlace($web)->status);
    $order = moLastOrder($app);
    same('on_hold', (string) $order['status']);
    same('pending', (string) $order['payment_status']);
    same('transfer', (string) $order['payment_method']);
    same(1, moCustomerMails($app, 'ana@cliente.test'), 'el cliente recibe las instrucciones una vez');
    contains('IBAN de mentira', (string) $app->db()->value("SELECT html FROM {mail_messages} WHERE template = 'order_on_hold'"));
});

test('el total coincide al céntimo con el del mismo carrito comprado en la tienda (variantes, cupón, dos IVA y envío)', function (): void {
    $s = moShop();
    $app = $s['app'];
    $lines = [[$s['shirt'], $s['v']['CAM-M'], 2], [$s['shirt'], $s['v']['CAM-S'], 1], [$s['book'], 0, 1]];
    $app->db()->run('UPDATE {variants} SET stock = 10 WHERE product_id = ? AND sku <> ?', [(int) $s['shirt'], 'CAM-L']);
    $shop = moShopOrder($app, $lines, 'transfer', ['OTONO']);
    $web = moClient($app);
    $q = moReady($web, $s, $lines, 'method:transfer', ['coupon' => 'OTONO']);
    same(true, $q['ready'], json_encode($q['missing']) . json_encode($q['problems']));
    same((int) $shop['total'], $q['total'], 'el resumen da lo mismo que la tienda');
    same(2, count($q['taxes']), 'dos tipos de IVA');
    check($q['discount'] > 0 && $q['shipping'] !== null && (int) $q['shipping']['amount'] > 0, 'con descuento y con envío');
    same(303, moPlace($web)->status);
    $order = moLastOrder($app);
    foreach (['total', 'total_net', 'total_tax', 'items_total', 'discount_total', 'shipping_total', 'fee_total'] as $key) {
        same((int) $shop[$key], (int) $order[$key], 'mismo ' . $key . ' que en la tienda');
    }
    same($shop['taxes'], $order['taxes'], 'el mismo desglose de impuestos');
    same(['OTONO'], array_map(static function (array $c): string {
        return (string) $c['code'];
    }, $order['coupons']));
});

test('«Ya está pagado» con cupón y dos IVA: el mismo total que la tienda y nada de recargos', function (): void {
    $s = moShop();
    $app = $s['app'];
    $lines = [[$s['mug'], 0, 2], [$s['book'], 0, 1]];
    $shop = moShopOrder($app, $lines, 'transfer', ['OTONO']);
    $web = moClient($app);
    $q = moReady($web, $s, $lines, 'paid', ['coupon' => 'OTONO']);
    same((int) $shop['total'], $q['total']);
    same([], $q['fees']);
    moPlace($web);
    same((int) $shop['total'], (int) moLastOrder($app)['total']);
});

test('las existencias se descuentan una sola vez, con su movimiento', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    moReady($web, $s, [[$s['shirt'], $s['v']['CAM-M'], 2], [$s['book'], 0, 3]]);
    same(3, moFlowVariantStock($app, $s['v']['CAM-M']));
    same(10, aifecOrderStock($app, $s['book']));
    same(303, moPlace($web)->status);
    same(1, moFlowVariantStock($app, $s['v']['CAM-M']), '3 - 2');
    same(7, aifecOrderStock($app, $s['book']), '10 - 3');
    $order = moLastOrder($app);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {stock_moves} WHERE reference = ?', [(string) $order['number']]), 'un movimiento por línea');
});

test('el correo de confirmación sale una vez con la casilla marcada y ninguna sin ella; el aviso a la tienda, siempre', function (): void {
    foreach ([true, false] as $notify) {
        $s = moShop();
        $app = $s['app'];
        $web = moClient($app);
        moReady($web, $s, [[$s['mug'], 0, 1]], 'paid');
        $r = moPlace($web, $notify ? [] : ['notify' => null]);
        same(303, $r->status);
        same($notify ? 1 : 0, moCustomerMails($app, 'ana@cliente.test'), $notify ? 'marcada: un correo' : 'desmarcada: ninguno');
        same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {mail_messages} WHERE template = 'admin_new_order'"), 'la tienda recibe su aviso');
        same('paid', (string) moLastOrder($app)['payment_status'], 'el pedido no depende del correo');
    }
});

test('sin la casilla, tampoco sale el correo de instrucciones de una transferencia', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    moReady($web, $s, [[$s['mug'], 0, 1]], 'method:transfer');
    $form = panelKitForm($web->get('/admin/orders/new')->body, '/admin/orders/new/place');
    same('1', isset($form['notify']) ? (string) $form['notify'] : '', 'la casilla viene marcada de serie');
    unset($form['notify']);
    same(303, $web->post('/admin/orders/new/place', $form)->status);
    same(0, moCustomerMails($app, 'ana@cliente.test'));
    same('on_hold', (string) moLastOrder($app)['status']);
});

test('la nota interna queda en el historial, privada, y el cliente no la ve', function (): void {
    $s = moShop();
    $app = $s['app'];
    $web = moClient($app);
    moReady($web, $s, [[$s['mug'], 0, 1]]);
    moPlace($web, ['note' => 'Llamó a las 10 & pidió envolver']);
    $order = moLastOrder($app);
    same(null, $order['customer_note'], 'no es la nota del cliente');
    $found = null;
    foreach ($order['history'] as $entry) {
        if ((string) $entry['note'] === 'Llamó a las 10 & pidió envolver') {
            $found = $entry;
        }
    }
    check($found !== null, 'está en el historial');
    same(0, (int) $app->db()->value('SELECT is_public FROM {order_history} WHERE id = ?', [(int) $found['id']]), 'privada');
    $page = $web->get('/admin/orders/' . (int) $order['id']);
    contains('Llamó a las 10 &amp; pidió envolver', $page->body, 'la ficha la enseña escapada');
    contains('Creado en el panel por Admin.', $page->body, 'la ficha dice quién lo creó');
    contains('Panel de administración', $page->body, 'y el canal');
});

test('«Mandar enlace de pago» está apagado y dice por qué; mandarlo a mano no cambia nada', function (): void {
    $s = moShop();
    $web = moClient($s['app']);
    $page = $web->get('/admin/orders/new');
    check(preg_match('/<input type="radio" name="mode" value="link" disabled/', $page->body) === 1, 'la opción sale apagada');
    contains('Todavía no está disponible: la página del pedido no puede cobrar un pedido que sigue esperando el pago.', $page->body);
    moReady($web, $s, [[$s['mug'], 0, 1]], 'paid');
    $r = moStep($web, 'payment', ['mode' => 'link']);
    same(303, $r->status);
    $q = moQuote($web);
    same('in_person', $q['payment_method'], 'sigue el pago elegido');
    $json = moStep($web, 'payment', ['mode' => 'link'], true);
    same(false, json_decode($json->body, true)['ok']);
});

test('recoge en tienda: con una forma de envío de recogida, se elige desde la entrega y el pedido la lleva', function (): void {
    $s = moShop();
    $app = $s['app'];
    // Más cara que el envío de siempre (si fuera la más barata, el carrito ya la elegiría solo, como en la tienda).
    $pickup = shippingAddCarrier($app, 'tienda', ['handler' => 'pickup', 'name' => 'Recogida en tienda', 'position' => 5, 'settings' => json_encode(['price' => 1000, 'address' => 'Calle Mayor 1, Madrid'])]);
    $web = moClient($app);
    moReady($web, $s, [[$s['mug'], 0, 1]]);
    $page = $web->get('/admin/orders/new');
    contains('El cliente lo recoge en la tienda: Recogida en tienda', $page->body);
    same(303, moStep($web, 'shipping', ['carrier_id' => $pickup])->status);
    $q = moQuote($web);
    same($pickup, (int) $q['shipping']['carrier_id']);
    contains('Recoge en tienda', $web->get('/admin/orders/new')->body);
    moPlace($web);
    $order = moLastOrder($app);
    same('Recogida en tienda', (string) $order['carrier_title']);
    same(1000, (int) $order['shipping_total']);
});

test('lo que no se envía (un producto digital): sin envío y sin dirección, y el pedido se crea igual', function (): void {
    $s = moShop();
    $app = $s['app'];
    $ebook = aifecProduct($app, ['name' => 'Guía en PDF', 'price' => 900, 'kind' => 'digital', 'tax_class_id' => 'standard', 'stock' => 50]);
    $app->db()->delete('addresses', ['customer_id' => (int) $s['ana']]);
    $web = moClient($app);
    moStep($web, 'customer', ['customer_id' => (int) $s['ana']]);
    moStep($web, 'line', ['product_id' => $ebook, 'variant_id' => 0, 'qty' => 1]);
    moStep($web, 'payment', ['mode' => 'paid', 'paid_with' => 'Tarjeta']);
    $q = moQuote($web);
    same(false, $q['needs_shipping']);
    same(true, $q['ready'], json_encode($q['missing']));
    contains('No hay nada que enviar', $web->get('/admin/orders/new')->body);
    same(303, moPlace($web)->status);
    $order = moLastOrder($app);
    same('not_needed', (string) $order['fulfillment_status']);
    same('completed', (string) $order['status']);
    same('Ana', (string) $order['billing']['first_name'], 'la factura lleva el nombre del cliente');
});

test('el carrito de la tienda de quien está en el panel no se toca, y aunque haya entrado como cliente el pedido es del elegido', function (): void {
    $s = moShop();
    $app = $s['app'];
    $other = aifecCartCustomer($app, 'otro@cliente.test', ['first_name' => 'Otro']);
    $web = moClient($app);
    $book = (int) $s['book'];
    aifecCartRun($web, static function ($cart) use ($book): void {
        check(!empty($cart->add($book, 0, 2)['ok']), 'el carrito propio');
    });
    $logged = aifecCartRun($web, static function ($cart, \Aifec\Kernel\App $app): ?int {
        return $app->service('customers')->login('otro@cliente.test', 'clave-larga-1234');
    });
    same($other, $logged, 'el navegador del panel también ha entrado en la tienda');
    moReady($web, $s, [[$s['mug'], 0, 1]]);
    same(303, moPlace($web)->status);
    $order = moLastOrder($app);
    same((int) $s['ana'], (int) $order['customer_id'], 'el pedido es de Ana, no de quien ha entrado en la tienda');
    same('ana@cliente.test', (string) $order['email']);
    $mine = aifecCartRun($web, static function ($cart): array {
        return $cart->summary();
    });
    same(2, (int) $mine['count'], 'el carrito propio sigue con lo suyo');
    same([(int) $s['book']], array_values(array_map(static function (array $l): int {
        return (int) $l['product_id'];
    }, $mine['lines'])));
});

test('el descuento del grupo del cliente elegido se aplica, como en la tienda', function (): void {
    $s = moShop();
    $app = $s['app'];
    $group = aifecCartGroup($app, 1000);
    $app->db()->update('customers', ['group_id' => $group], ['id' => (int) $s['ana']]);
    $web = moClient($app);
    $q = moReady($web, $s, [[$s['mug'], 0, 1]]);
    same(120, (int) $q['discount'], 'el 10 % de 12,00 €');
    contains('Descuento de grupo', json_encode($q['rows'], JSON_UNESCAPED_UNICODE));
});
