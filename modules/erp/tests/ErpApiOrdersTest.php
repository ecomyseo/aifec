<?php
/**
 * AIFEC — ERP: pedidos por la API (lista por cursor, ficha, confirmación, estado y seguimiento).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('el cursor recorre 250 pedidos sin saltarse ni repetir ninguno aunque entren pedidos nuevos a mitad', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    erpOrderRows($app, 250);
    $seen = [];
    $cursor = '';
    $pages = 0;
    do {
        $r = erpCall($app, 'GET', '/orders?limit=100' . ($cursor !== '' ? '&cursor=' . $cursor : ''), null, $c['key']);
        same(200, $r->status);
        $body = erpJson($r);
        foreach ($body['data'] as $order) {
            $seen[] = $order['number'];
        }
        ++$pages;
        if ($pages === 2) {
            // Entran pedidos nuevos mientras se recorre: van al final.
            erpOrderRows($app, 7);
        }
        $cursor = (string) $body['next_cursor'];
    } while ($body['has_more'] && $pages < 10);
    same(257, count($seen));
    same(257, count(array_unique($seen)));
    same('T-' . str_pad('1', 6, '0', STR_PAD_LEFT), $seen[0]);
    same(3, $pages);
    // Con el cursor de la última página no sale nada nuevo, pero el cursor se conserva para la próxima consulta.
    $again = erpJson(erpCall($app, 'GET', '/orders?cursor=' . $cursor, null, $c['key']));
    same([], $again['data']);
    same($cursor, $again['next_cursor']);
    erpOrderRows($app, 2);
    same(2, count(erpJson(erpCall($app, 'GET', '/orders?cursor=' . $cursor, null, $c['key']))['data']));
});

test('un cursor roto y un límite fuera de rango dan 400', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $r = erpCall($app, 'GET', '/orders?cursor=esto-no-vale', null, $c['key']);
    same(400, $r->status);
    same('cursor', erpJson($r)['error']['field']);
    same(400, erpCall($app, 'GET', '/orders?limit=0', null, $c['key'])->status);
    same(400, erpCall($app, 'GET', '/orders?limit=201', null, $c['key'])->status);
    same(400, erpCall($app, 'GET', '/orders?updated_since=ayer', null, $c['key'])->status);
});

test('la ficha de un pedido trae líneas con SKU, impuestos, envío, direcciones, pago y cliente con los importes al céntimo', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $id = erpOrder($app, 2, 2000);
    $order = $app->service('orders')->find($id);
    $r = erpCall($app, 'GET', '/orders/' . rawurlencode((string) $order['number']), null, $c['key']);
    same(200, $r->status);
    $data = erpJson($r)['data'];
    same($order['number'], $data['number']);
    same((int) $order['total'], $data['totals']['total']);
    same(number_format($order['total'] / 100, 2, '.', ''), $data['totals']['total_decimal']);
    same((int) $order['total_tax'], $data['totals']['total_tax']);
    same((int) $order['paid_total'], $data['totals']['paid_total']);
    same((int) $order['shipping_total'], $data['totals']['shipping_total']);
    $line = $data['lines'][0];
    check(str_starts_with((string) $line['sku'], 'CAM-'), 'la línea lleva su SKU');
    same(2, $line['qty']);
    same((int) $order['items'][0]['unit_price'], $line['unit_price']);
    same((int) $order['items'][0]['line_total'], $line['line_total']);
    same((int) $order['items'][0]['tax_rate'], $line['tax_rate']);
    check($data['taxes'] !== [], 'trae los impuestos por tipo');
    same('paid', $data['payment_status']);
    same('gw', $data['payment']['method']);
    check($data['billing_address'] !== null && $data['billing_address']['country'] !== '', 'trae la dirección de facturación');
    check($data['shipping']['address'] !== null, 'trae la dirección de envío');
    same('ana@cliente.test', $data['email']);
    check(preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', (string) $data['placed_at']) === 1, 'fechas ISO 8601 en UTC');
    check(array_key_exists('attribution', $data) && array_key_exists('discounts', $data), 'trae atribución y descuentos');
    same(null, $data['erp_ack']);
    $sum = 0;
    foreach ($data['lines'] as $l) {
        $sum += $l['line_total'];
    }
    check($sum > 0, 'las líneas suman');
});

test('un pedido que no existe da 404', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $r = erpCall($app, 'GET', '/orders/2026-999999', null, $c['key']);
    same(404, $r->status);
    same('order_not_found', erpJson($r)['error']['code']);
});

test('la lista se puede filtrar por estado y por fecha de cambio', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    erpOrderRows($app, 3);
    $app->db()->run("UPDATE {orders} SET status = 'shipped' WHERE number = 'T-000002'");
    $app->clock()->advance(3600);
    erpOrderRows($app, 2, $app->clock()->sql());
    same(1, count(erpJson(erpCall($app, 'GET', '/orders?status=shipped', null, $c['key']))['data']));
    same(5, count(erpJson(erpCall($app, 'GET', '/orders?status=shipped,processing', null, $c['key']))['data']));
    same(2, count(erpJson(erpCall($app, 'GET', '/orders?updated_since=2026-11-12T12:30:00Z', null, $c['key']))['data']));
});

test('confirmar un pedido apunta el identificador del ERP, se ve en la ficha del pedido y repetirlo no duplica', function (): void {
    $app = erpApp();
    $c = erpConnection($app, null, 'Odoo');
    $id = erpOrder($app);
    $number = adminOrdersNumber($app, $id);
    $r = erpCall($app, 'POST', '/orders/' . $number . '/ack', ['external_id' => 'SO-1001'], $c['key']);
    same(200, $r->status);
    same('SO-1001', erpJson($r)['data']['external_id']);
    same(true, erpJson($r)['data']['changed']);
    $again = erpJson(erpCall($app, 'POST', '/orders/' . $number . '/ack', ['external_id' => 'SO-1001'], $c['key']));
    same(false, $again['data']['changed']);
    same(1, aifecOrderCount($app, 'erp_acks'));
    same(1, aifecOrderCount($app, 'order_history', "kind = 'note' AND order_id = ?", [$id]));
    $shown = erpJson(erpCall($app, 'GET', '/orders/' . $number, null, $c['key']))['data'];
    same('SO-1001', $shown['erp_ack']['external_id']);
    // En la ficha del pedido del panel.
    $web = adminClient($app);
    $page = $web->get('/admin/orders/' . $id);
    same(200, $page->status);
    contains('SO-1001', $page->body);
    contains('Odoo', $page->body);
    // Un identificador nuevo lo sustituye.
    erpCall($app, 'POST', '/orders/' . $number . '/ack', ['external_id' => 'SO-2002'], $c['key']);
    same(1, aifecOrderCount($app, 'erp_acks'));
    same('SO-2002', (string) $app->db()->value('SELECT external_id FROM {erp_acks}'));
});

test('confirmar sin identificador es un error de validación con el campo', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $number = adminOrdersNumber($app, erpOrder($app));
    $r = erpCall($app, 'POST', '/orders/' . $number . '/ack', ['external_id' => ''], $c['key']);
    same(422, $r->status);
    same('external_id', erpJson($r)['error']['field']);
});

test('cambiar el estado con una transición permitida lo cambia y no avisa al cliente si no se pide', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $id = erpOrder($app);
    $number = adminOrdersNumber($app, $id);
    $before = count(aifecOrderMails($app, 'order_shipped'));
    $r = erpCall($app, 'POST', '/orders/' . $number . '/status', ['status' => 'shipped', 'note' => 'Sale hoy'], $c['key']);
    same(200, $r->status);
    same(true, erpJson($r)['data']['changed']);
    same('shipped', erpJson($r)['data']['order']['status']);
    same('shipped', (string) $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$id]));
    same($before, count(aifecOrderMails($app, 'order_shipped')));
    // Con notify el cliente recibe su correo.
    $r2 = erpCall($app, 'POST', '/orders/' . $number . '/status', ['status' => 'completed', 'notify' => true], $c['key']);
    same(200, $r2->status);
    same(1, count(aifecOrderMails($app, 'order_completed')));
    // El mismo estado otra vez no cambia nada.
    same(false, erpJson(erpCall($app, 'POST', '/orders/' . $number . '/status', ['status' => 'completed'], $c['key']))['data']['changed']);
});

test('una transición que la tienda no permite da 422 y un estado desconocido también', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $number = adminOrdersNumber($app, erpOrder($app));
    $r = erpCall($app, 'POST', '/orders/' . $number . '/status', ['status' => 'pending_payment'], $c['key']);
    same(422, $r->status);
    same('transition_not_allowed', erpJson($r)['error']['code']);
    $u = erpCall($app, 'POST', '/orders/' . $number . '/status', ['status' => 'inventado'], $c['key']);
    same(422, $u->status);
    same('unknown_status', erpJson($u)['error']['code']);
    same('status', erpJson($u)['error']['field']);
    same('processing', erpJson(erpCall($app, 'GET', '/orders/' . $number, null, $c['key']))['data']['status']);
});

test('el seguimiento guarda el número, el transportista y el enlace y lo ve el cliente', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $id = erpOrder($app);
    $number = adminOrdersNumber($app, $id);
    $r = erpCall($app, 'POST', '/orders/' . $number . '/tracking', ['tracking_number' => 'ES0012345', 'carrier' => 'Correos Express', 'url' => 'https://seguimiento.test/ES0012345'], $c['key']);
    same(200, $r->status);
    $data = erpJson($r)['data'];
    same('ES0012345', $data['shipping']['tracking_number']);
    same('Correos Express', $data['shipping']['carrier']);
    same('https://seguimiento.test/ES0012345', $data['shipping']['shipments'][0]['url']);
    same('ES0012345', (string) $app->db()->value('SELECT tracking_code FROM {orders} WHERE id = ?', [$id]));
    same(1, aifecOrderCount($app, 'order_history', "kind = 'shipment' AND is_public = 1 AND order_id = ?", [$id]));
    $bad = erpCall($app, 'POST', '/orders/' . $number . '/tracking', ['tracking_number' => 'X', 'url' => 'javascript:alert(1)'], $c['key']);
    same(422, $bad->status);
    same('url', erpJson($bad)['error']['field']);
    same(422, erpCall($app, 'POST', '/orders/' . $number . '/tracking', ['carrier' => 'Solo transportista'], $c['key'])->status);
});

test('una escritura de pedido queda en el registro de actividad', function (): void {
    $app = erpApp();
    $c = erpConnection($app, null, 'Holded');
    $number = adminOrdersNumber($app, erpOrder($app));
    erpCall($app, 'POST', '/orders/' . $number . '/ack', ['external_id' => 'H-1'], $c['key']);
    erpCall($app, 'POST', '/orders/' . $number . '/status', ['status' => 'shipped'], $c['key']);
    erpCall($app, 'POST', '/orders/' . $number . '/tracking', ['tracking_number' => 'T1'], $c['key']);
    same(3, aifecOrderCount($app, 'audit', "user_name = 'ERP: Holded' AND action LIKE 'erp.order.%'"));
});
