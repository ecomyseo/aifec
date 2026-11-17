<?php
/**
 * AIFEC — pruebas de las acciones de la ficha del pedido: estado, notas, seguimiento, cobro a mano, cancelar, devolver y
 * reenviar el correo. Cada una se comprueba releyendo el pedido de la base, no el HTML.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/AdminKit.php';

/**
 * Un pedido cobrado de dos camisetas (existencias: 10, y 8 tras el pedido). Devuelve [app, producto, id, navegador].
 *
 * @return array{0:App,1:int,2:int,3:TestClient}
 */
function adminOrdersPaidWeb(): array
{
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $id = adminOrdersPaid($app, 'gw', [[$p, 0, 2]]);

    return [$app, $p, $id, adminClient($app)];
}

/** La última apuntación del historial de un pedido. */
function adminOrdersLastHistory(App $app, int $id): array
{
    $row = $app->db()->row('SELECT * FROM {order_history} WHERE order_id = ? ORDER BY id DESC', [$id]);
    check($row !== null, 'el pedido tiene historial');

    return $row;
}

test('cambiar de estado guarda el estado y la nota, apunta quién lo hizo y encola el correo solo si se avisa', function (): void {
    list($app, $p, $id, $web) = adminOrdersPaidWeb();
    $r = adminOrdersPost($web, '/admin/orders/' . $id . '/status', ['status' => 'shipped', 'note' => 'Sale hoy & mañana', 'notify' => '1']);
    same(302, $r->status);
    contains('Estado cambiado a Enviado', $web->follow()->body);
    $order = $app->service('orders')->find($id);
    same('shipped', $order['status']);
    same('fulfilled', $order['fulfillment_status']);
    $entry = adminOrdersLastHistory($app, $id);
    $admin = (int) $app->db()->value("SELECT id FROM {users} WHERE email = 'admin@tienda.test'");
    same(['status', 'processing', 'shipped', 'Sale hoy & mañana', $admin], [$entry['kind'], $entry['from_value'], $entry['to_value'], $entry['note'], (int) $entry['user_id']]);
    same(1, count(aifecOrderMails($app, 'order_shipped')), 'con aviso, un correo');
    same(1, aifecOrderCount($app, 'audit', 'action = ? AND entity_id = ?', ['order.status', (string) $id]), 'queda en la auditoría');

    $second = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    adminOrdersPost($web, '/admin/orders/' . $second . '/status', ['status' => 'shipped']);
    same('shipped', $app->service('orders')->find($second)['status']);
    same(1, count(aifecOrderMails($app, 'order_shipped')), 'sin la casilla de avisar no sale correo');
});

test('un estado que el servicio rechaza sale como aviso y no cambia nada', function (): void {
    $app = adminOrdersApp();
    $id = adminOrdersPlace($app, 'gw', [[adminOrdersProduct($app), 0, 1]]);
    $web = adminClient($app);
    $history = aifecOrderCount($app, 'order_history');
    $r = adminOrdersPost($web, '/admin/orders/' . $id . '/status', ['status' => 'shipped', 'notify' => '1']);
    same(302, $r->status);
    contains('No se ha podido cambiar el estado', $web->follow()->body);
    same('pending_payment', $app->service('orders')->find($id)['status'], 'un pedido sin pagar no se envía');
    same($history, aifecOrderCount($app, 'order_history'), 'no queda ni una línea nueva en el historial');
    same(0, count(aifecOrderMails($app, 'order_shipped')));

    $r = adminOrdersPost($web, '/admin/orders/' . $id . '/status', ['status' => 'inventado']);
    contains('No se ha podido cambiar el estado', $web->follow()->body, 'un estado que no existe');
});

test('una nota privada no la ve el cliente y una pública sí; soporte puede dejar notas pero no cambiar estados', function (): void {
    list($app, $p, $id, $web) = adminOrdersPaidWeb();
    $r = adminOrdersPost($web, '/admin/orders/' . $id . '/note', ['note' => 'Cliente <difícil> & exigente', 'visibility' => 'private']);
    same(302, $r->status);
    contains('Nota privada guardada.', $web->follow()->body);
    $entry = adminOrdersLastHistory($app, $id);
    same(['note', 'Cliente <difícil> & exigente', 0], [$entry['kind'], $entry['note'], (int) $entry['is_public']]);
    $page = $web->get('/admin/orders/' . $id)->body;
    contains('Cliente &lt;difícil&gt; &amp; exigente', $page, 'la nota sale escapada');
    contains('Nota privada', $page);

    adminOrdersPost($web, '/admin/orders/' . $id . '/note', ['note' => 'Os lo mandamos mañana', 'visibility' => 'public']);
    $entry = adminOrdersLastHistory($app, $id);
    same(['note', 'Os lo mandamos mañana', 1], [$entry['kind'], $entry['note'], (int) $entry['is_public']]);

    $count = aifecOrderCount($app, 'order_history', 'order_id = ?', [$id]);
    adminOrdersPost($web, '/admin/orders/' . $id . '/note', ['note' => '   ', 'visibility' => 'public']);
    same($count, aifecOrderCount($app, 'order_history', 'order_id = ?', [$id]), 'una nota vacía no se guarda');

    $support = adminClient($app, 'support');
    same(302, adminOrdersPost($support, '/admin/orders/' . $id . '/note', ['note' => 'Llamó el cliente', 'visibility' => 'private'])->status);
    same('Llamó el cliente', adminOrdersLastHistory($app, $id)['note']);
    same(403, adminOrdersPost($support, '/admin/orders/' . $id . '/status', ['status' => 'shipped'])->status);
    same('processing', $app->service('orders')->find($id)['status']);
});

test('el seguimiento se guarda, queda en el historial y con «marcar como enviado» el pedido sale enviado con el código en el correo', function (): void {
    list($app, $p, $id, $web) = adminOrdersPaidWeb();
    $r = adminOrdersPost($web, '/admin/orders/' . $id . '/tracking', ['tracking_code' => 'ES123456789', 'ship' => '1', 'notify' => '1']);
    same(302, $r->status);
    $done = $web->follow()->body;
    contains('El pedido está marcado como enviado.', $done);
    notContains('No tiene código de seguimiento', $done, 'con código no se avisa');
    $order = $app->service('orders')->find($id);
    same('ES123456789', $order['tracking_code']);
    same('shipped', $order['status']);
    same('fulfilled', $order['fulfillment_status']);
    $kinds = $app->db()->column('SELECT kind FROM {order_history} WHERE order_id = ? ORDER BY id', [$id]);
    check(in_array('shipment', $kinds, true), 'el código queda en el historial');
    $mails = aifecOrderMails($app, 'order_shipped');
    same(1, count($mails));
    contains('ES123456789', (string) $mails[0]['html'], 'el correo lleva el código');

    // Enviado sin código: se puede, pero se avisa.
    $bare = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    adminOrdersPost($web, '/admin/orders/' . $bare . '/tracking', ['tracking_code' => '', 'ship' => '1']);
    contains('No tiene código de seguimiento', $web->follow()->body);
    same('shipped', $app->service('orders')->find($bare)['status']);

    // Solo el código, sin enviar.
    $other = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    adminOrdersPost($web, '/admin/orders/' . $other . '/tracking', ['tracking_code' => 'ES"1"']);
    $order = $app->service('orders')->find($other);
    same('ES"1"', $order['tracking_code']);
    same('processing', $order['status'], 'sin la casilla, el estado no cambia');

    // Ni código ni enviar: no hace nada.
    $before = aifecOrderCount($app, 'order_history');
    adminOrdersPost($web, '/admin/orders/' . $other . '/tracking', ['tracking_code' => '']);
    contains('Escribe el código de seguimiento', $web->follow()->body);
    same($before, aifecOrderCount($app, 'order_history'));
});

test('dar por cobrado a mano vale en un pedido de transferencia y se rechaza en uno de pasarela', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $transfer = adminOrdersPlace($app, 'transfer', [[$p, 0, 1]]);
    $gateway = adminOrdersPlace($app, 'gw', [[$p, 0, 1]]);
    $web = adminClient($app);
    same('on_hold', $app->service('orders')->find($transfer)['status']);

    $r = adminOrdersPost($web, '/admin/orders/' . $transfer . '/paid', ['reference' => '']);
    contains('Escribe la referencia del cobro.', $web->follow()->body);
    same('pending', $app->service('orders')->find($transfer)['payment_status'], 'sin referencia no se cobra');

    $r = adminOrdersPost($web, '/admin/orders/' . $transfer . '/paid', ['reference' => 'TRF-0001']);
    same(302, $r->status);
    contains('El cobro queda apuntado.', $web->follow()->body);
    $order = $app->service('orders')->find($transfer);
    same('paid', $order['payment_status']);
    same((int) $order['total'], (int) $order['paid_total']);
    same('processing', $order['status'], 'cobrado, pasa a en preparación');
    $payment = $app->db()->row('SELECT * FROM {order_payments} WHERE order_id = ?', [$transfer]);
    same(['transfer', 'TRF-0001', (int) $order['total'], 'ok'], [$payment['method'], $payment['reference'], (int) $payment['amount'], $payment['status']]);

    $r = adminOrdersPost($web, '/admin/orders/' . $gateway . '/paid', ['reference' => 'INVENTADA']);
    same(302, $r->status);
    contains('pasa por una pasarela', $web->follow()->body);
    same('pending', $app->service('orders')->find($gateway)['payment_status'], 'un pedido de pasarela no se da por cobrado a mano');
    same(0, aifecOrderCount($app, 'order_payments', 'order_id = ?', [$gateway]));
    notContains('/admin/orders/' . $gateway . '/paid', $web->get('/admin/orders/' . $gateway)->body, 'ni siquiera sale el formulario');
});

test('cancelar un pedido sin pagar lo deja cancelado y devuelve las existencias; uno cobrado no se cancela', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $unpaid = adminOrdersPlace($app, 'gw', [[$p, 0, 3]]);
    $paid = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $web = adminClient($app);
    same(6, aifecOrderStock($app, $p), 'tres y una unidades reservadas');

    $r = adminOrdersPost($web, '/admin/orders/' . $unpaid . '/cancel', ['reason' => 'No contesta', 'restock' => '1']);
    same(302, $r->status);
    contains('El pedido está cancelado.', $web->follow()->body);
    same('cancelled', $app->service('orders')->find($unpaid)['status']);
    same(9, aifecOrderStock($app, $p), 'las tres unidades vuelven');
    same('No contesta', (string) $app->db()->value("SELECT note FROM {order_history} WHERE order_id = ? AND to_value = 'cancelled'", [$unpaid]), 'el motivo queda en el historial');

    $r = adminOrdersPost($web, '/admin/orders/' . $paid . '/cancel', ['reason' => 'Por error', 'restock' => '1']);
    contains('No se ha podido cancelar el pedido', $web->follow()->body);
    same('processing', $app->service('orders')->find($paid)['status'], 'un pedido cobrado se devuelve, no se cancela');
    same(9, aifecOrderStock($app, $p));
});

test('una devolución parcial por líneas calcula el importe, repone las existencias y manda el correo', function (): void {
    list($app, $p, $id, $web) = adminOrdersPaidWeb();
    $order = $app->service('orders')->find($id);
    $line = 0;
    foreach ($order['items'] as $item) {
        if ($item['kind'] === 'product') {
            $line = (int) $item['id'];
        }
    }
    same(8, aifecOrderStock($app, $p));

    $r = adminOrdersPost($web, '/admin/orders/' . $id . '/refund', ['items' => [(string) $line => '1'], 'reason' => 'Talla pequeña', 'restock' => '1']);
    same(302, $r->status);
    contains('Devolución de ' . $app->i18n()->money(2000, 'EUR') . ' apuntada.', $web->follow()->body);
    $after = $app->service('orders')->find($id);
    same(2000, (int) $after['refunded_total'], 'una de las dos unidades: la mitad de la línea');
    same('partially_refunded', $after['payment_status']);
    same(1, (int) $after['items'][0]['refunded_qty']);
    same(9, aifecOrderStock($app, $p), 'la unidad devuelta vuelve a las existencias');
    $refund = $app->db()->row('SELECT * FROM {order_refunds} WHERE order_id = ?', [$id]);
    same([2000, 'Talla pequeña', 1], [(int) $refund['amount'], $refund['reason'], (int) $refund['restock']]);
    same(1, count(aifecOrderMails($app, 'order_refunded')));
    same(1, aifecOrderCount($app, 'audit', 'action = ? AND entity_id = ?', ['order.refund', (string) $id]));

    $page = $web->get('/admin/orders/' . $id)->body;
    contains('1 unidad devuelta', $page, 'la línea enseña lo devuelto');
    contains('Talla pequeña', $page, 'el motivo sale junto al movimiento');
});

test('una devolución por importe va por la pasarela si se pide y se apunta con su referencia', function (): void {
    list($app, $p, $id, $web) = adminOrdersPaidWeb();
    $r = adminOrdersPost($web, '/admin/orders/' . $id . '/refund', ['amount' => '10,00', 'reason' => 'Cortesía', 'gateway' => '1']);
    same(302, $r->status);
    contains('Devolución de ' . $app->i18n()->money(1000, 'EUR') . ' apuntada.', $web->follow()->body);
    same([[1000, 'Cortesía']], aifecOrderPayment($app)->refunds, 'la pasarela recibió el importe y el motivo');
    $after = $app->service('orders')->find($id);
    same(1000, (int) $after['refunded_total']);
    same('partially_refunded', $after['payment_status']);
    $payment = $app->db()->row("SELECT * FROM {order_payments} WHERE order_id = ? AND kind = 'refund'", [$id]);
    same('RF-1', $payment['reference']);
    same(8, aifecOrderStock($app, $p), 'sin la casilla de reponer, las existencias no se tocan');

    // La pasarela rechaza: aviso y nada cambia.
    aifecOrderPayment($app)->refundThrows = true;
    $before = aifecOrderCount($app, 'order_refunds');
    adminOrdersPost($web, '/admin/orders/' . $id . '/refund', ['amount' => '5,00', 'gateway' => '1']);
    contains('La devolución ha fallado: The gateway refused the refund', $web->follow()->body);
    same(1000, (int) $app->service('orders')->find($id)['refunded_total'], 'la devolución rechazada no se queda apuntada');
    same($before, aifecOrderCount($app, 'order_refunds'));
});

test('una devolución por encima de lo cobrado, sin importe o con un importe que no vale avisa y no cambia nada', function (): void {
    list($app, $p, $id, $web) = adminOrdersPaidWeb();
    $refunds = aifecOrderCount($app, 'order_refunds');
    $history = aifecOrderCount($app, 'order_history');

    adminOrdersPost($web, '/admin/orders/' . $id . '/refund', ['amount' => '999,00', 'gateway' => '1']);
    contains('La devolución es mayor que lo cobrado', $web->follow()->body);
    adminOrdersPost($web, '/admin/orders/' . $id . '/refund', ['amount' => 'mucho']);
    contains('Escribe un importe válido', $web->follow()->body);
    adminOrdersPost($web, '/admin/orders/' . $id . '/refund', ['amount' => '', 'reason' => 'nada']);
    contains('Escribe un importe o elige las líneas', $web->follow()->body);
    adminOrdersPost($web, '/admin/orders/' . $id . '/refund', ['items' => ['9999' => '1']]);
    $after = $app->service('orders')->find($id);
    same(0, (int) $after['refunded_total']);
    same('paid', $after['payment_status']);
    same($refunds, aifecOrderCount($app, 'order_refunds'));
    same($history, aifecOrderCount($app, 'order_history'));
    same([], aifecOrderPayment($app)->refunds, 'a la pasarela no llegó nada');
    same(8, aifecOrderStock($app, $p));
});

test('devolver pide orders.refund y cualquier acción sin la ficha del formulario no hace nada', function (): void {
    list($app, $p, $id, $web) = adminOrdersPaidWeb();
    adminOrdersRole($app, 'preparador', ['dashboard.view', 'orders.view', 'orders.edit']);
    $packer = adminClient($app, 'preparador');
    $r = adminOrdersPost($packer, '/admin/orders/' . $id . '/refund', ['amount' => '5,00']);
    same(403, $r->status, 'quien puede editar pero no devolver recibe un 403');
    same(0, (int) $app->service('orders')->find($id)['refunded_total']);
    notContains('/admin/orders/' . $id . '/refund', $packer->get('/admin/orders/' . $id)->body, 'ni ve el formulario');
    same('processing', $app->service('orders')->find($id)['status']);
    same(302, adminOrdersPost($packer, '/admin/orders/' . $id . '/status', ['status' => 'shipped'])->status, 'pero sí cambia el estado');

    $other = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $history = aifecOrderCount($app, 'order_history', 'order_id = ?', [$other]);
    $web->get('/admin/profile');
    foreach (['status' => ['status' => 'shipped'], 'note' => ['note' => 'x'], 'tracking' => ['tracking_code' => 'X1', 'ship' => '1'], 'paid' => ['reference' => 'R'],
        'cancel' => ['reason' => 'x'], 'refund' => ['amount' => '1,00'], 'resend' => []] as $action => $data) {
        $r = $web->post('/admin/orders/' . $other . '/' . $action, $data);
        same(302, $r->status, $action . ': se redirige sin hacer nada');
    }
    $order = $app->service('orders')->find($other);
    same(['processing', '', 0], [$order['status'], (string) $order['tracking_code'], (int) $order['refunded_total']], 'sin la ficha no cambia nada');
    same($history, aifecOrderCount($app, 'order_history', 'order_id = ?', [$other]));
    same(0, aifecOrderCount($app, 'order_refunds', 'order_id = ?', [$other]));

    // Un pedido que no existe da 404 y un método equivocado, 405.
    same(404, adminOrdersPost($web, '/admin/orders/99999/status', ['status' => 'shipped'])->status);
    same(405, $web->get('/admin/orders/' . $other . '/status')->status);
});

test('reenviar el correo del estado actual lo encola otra vez y lo apunta; un estado sin correo avisa', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $id = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $web = adminClient($app);
    same(1, count(aifecOrderMails($app, 'order_confirmed')), 'el correo del cobro');

    $r = adminOrdersPost($web, '/admin/orders/' . $id . '/resend');
    same(302, $r->status);
    contains('El correo va de camino al cliente.', $web->follow()->body);
    same(2, count(aifecOrderMails($app, 'order_confirmed')), 'el mismo correo, otra vez');
    $kinds = $app->db()->column('SELECT kind FROM {order_history} WHERE order_id = ? ORDER BY id', [$id]);
    same('mail', end($kinds), 'queda apuntado en el historial');

    $unpaid = adminOrdersPlace($app, 'gw', [[$p, 0, 1]]);
    adminOrdersPost($web, '/admin/orders/' . $unpaid . '/resend');
    contains('Este estado no manda ningún correo.', $web->follow()->body);
    same(2, count(aifecOrderMails($app, 'order_confirmed')));
});
