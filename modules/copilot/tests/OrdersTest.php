<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Las órdenes sobre pedidos: marcar enviado, cambiar el estado, añadir una nota y pedir un reembolso (que solo da el enlace).
 * Cada una se entiende en castellano y en inglés, enseña una vista previa exacta, se aplica, se comprueba en la base de datos y se
 * deshace.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('marcar enviado en castellano: entiende pedido y seguimiento, enseña estado y código, y no cambia nada hasta aplicar', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Marca el pedido ' . $order['n'] . ' como enviado con el seguimiento 4455');

    same('orders.ship', $row['action']);
    same('proposed', $row['status']);
    same(['order' => $order['n'], 'tracking' => '4455'], $row['args']);
    same(1, $row['preview']['count']);
    same([['label' => 'Estado', 'from' => 'En preparación', 'to' => 'Enviado'], ['label' => 'Código de seguimiento', 'from' => '—', 'to' => '4455']], $row['preview']['changes']);
    same('processing', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$order['id']]), 'entender no cambia nada');
    same(null, $app->db()->value('SELECT tracking_code FROM {orders} WHERE id = ?', [$order['id']]));
    contains('Marcar el pedido ' . $order['number'] . ' como enviado', $web->get('/admin/copilot/' . $row['id'])->body);
});

test('marcar enviado en inglés: mismo resultado con otra redacción', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Ship order #' . $order['number'] . ' with tracking ABC123');

    same('orders.ship', $row['action']);
    same($order['number'], $row['args']['order']);
    same('ABC123', $row['args']['tracking'], 'el código se conserva con sus mayúsculas');
    same('proposed', $row['status']);
});

test('aplicar «enviado» escribe estado, fecha y seguimiento en la base de datos, lo apunta en la auditoría y se puede deshacer', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $web = copilotAdmin($app);
    $row = copilotAsk($web, 'marca el pedido ' . $order['n'] . ' como enviado con el seguimiento 4455');
    $mails = copilotMails($app);

    $r = copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same(303, $r->status);

    $db = $app->db()->row('SELECT status, tracking_code, shipped_at, fulfillment_status FROM {orders} WHERE id = ?', [$order['id']]);
    same('shipped', $db['status']);
    same('4455', $db['tracking_code']);
    check($db['shipped_at'] !== null, 'se apunta cuándo se envió');
    same('fulfilled', $db['fulfillment_status']);
    same('applied', copilotRow($app, (int) $row['id'])['status']);
    same($mails, copilotMails($app), 'por defecto no se avisa al cliente');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'copilot.apply' AND entity_id = ?", [(string) $row['id']]), 'la auditoría lo apunta');
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {order_history} WHERE order_id = ? AND kind = 'shipment'", [$order['id']]), 'el cliente ve el seguimiento en el historial');

    $r = copilotPost($web, '/admin/copilot/' . $row['id'] . '/undo');
    same(303, $r->status);
    $db = $app->db()->row('SELECT status, tracking_code FROM {orders} WHERE id = ?', [$order['id']]);
    same('processing', $db['status'], 'deshacer devuelve el estado');
    same(null, $db['tracking_code'], 'y quita el seguimiento');
    same('undone', copilotRow($app, (int) $row['id'])['status']);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {audit} WHERE action = 'copilot.undo'"), 'deshacer también va a la auditoría');
});

test('avisar al cliente solo si la orden lo dice: «avisando al cliente» encola el correo y «sin avisar» no', function (): void {
    $app = copilotApp();
    $a = copilotOrder($app);
    $b = copilotOrder($app);
    $web = copilotAdmin($app);

    $notify = copilotAsk($web, 'Marca el pedido ' . $a['n'] . ' como enviado y avisa al cliente');
    same(true, $notify['args']['notify']);
    $quiet = copilotAsk($web, 'Mark order ' . $b['n'] . ' as shipped without notifying the customer');
    same(false, $quiet['args']['notify']);

    $before = copilotMails($app);
    copilotPost($web, '/admin/copilot/' . $quiet['id'] . '/apply');
    same($before, copilotMails($app), 'sin avisar no sale ningún correo');
    copilotPost($web, '/admin/copilot/' . $notify['id'] . '/apply');
    check(copilotMails($app) > $before, 'avisando, el correo del estado se encola');
});

test('cambiar el estado: «en espera» y «completado», en castellano y en inglés, con vista previa y deshacer', function (): void {
    $app = copilotApp();
    $es = copilotOrder($app);
    $en = copilotOrder($app);
    $web = copilotAdmin($app);

    $hold = copilotAsk($web, 'Pon el pedido ' . $es['n'] . ' en espera');
    same('orders.status', $hold['action']);
    same('on_hold', $hold['args']['status']);
    same([['label' => 'Estado', 'from' => 'En preparación', 'to' => 'En espera']], $hold['preview']['changes']);
    $done = copilotAsk($web, 'Change order ' . $en['n'] . ' to completed');
    same('completed', $done['args']['status']);

    copilotPost($web, '/admin/copilot/' . $hold['id'] . '/apply');
    copilotPost($web, '/admin/copilot/' . $done['id'] . '/apply');
    same('on_hold', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$es['id']]));
    same('completed', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$en['id']]));

    copilotPost($web, '/admin/copilot/' . $hold['id'] . '/undo');
    same('processing', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$es['id']]));
});

test('una nota se añade al historial: privada por defecto, visible para el cliente si la orden lo dice, y no se puede deshacer', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $web = copilotAdmin($app);

    $private = copilotAsk($web, 'Añade una nota privada al pedido ' . $order['n'] . ': el cliente ha llamado');
    same('orders.note', $private['action']);
    same('el cliente ha llamado', $private['args']['note']);
    same(false, $private['args']['public']);
    $public = copilotAsk($web, 'Add a note to order ' . $order['n'] . ' that the customer can see: Your parcel leaves tomorrow');
    same('orders.note', $public['action']);
    same(true, $public['args']['public']);
    contains('Your parcel leaves tomorrow', (string) $public['args']['note']);

    copilotPost($web, '/admin/copilot/' . $private['id'] . '/apply');
    copilotPost($web, '/admin/copilot/' . $public['id'] . '/apply');
    $notes = $app->db()->select("SELECT note, is_public FROM {order_history} WHERE order_id = ? AND kind = 'note' ORDER BY id", [$order['id']]);
    same(2, count($notes));
    same(['el cliente ha llamado', 0], [$notes[0]['note'], (int) $notes[0]['is_public']]);
    same(1, (int) $notes[1]['is_public']);
    same(null, copilotRow($app, (int) $private['id'])['undo'], 'una nota no se borra: no hay nada que deshacer');
    same(false, strpos(copilotPage($web, (int) $private['id']), '/undo') !== false, 'y la pantalla no ofrece deshacer');
});

test('un reembolso NUNCA se hace: solo propone el enlace a la ficha del pedido y aplicar se rechaza', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $web = copilotAdmin($app);
    $paid = $app->db()->value('SELECT paid_total FROM {orders} WHERE id = ?', [$order['id']]);

    foreach (['Reembolsa el pedido ' . $order['n'], 'Refund order ' . $order['n'], 'Cancela el pedido ' . $order['n']] as $text) {
        $row = copilotAsk($web, $text);
        same('orders.refund', $row['action'], $text);
        same(true, $row['preview']['readonly']);
        same('/admin/orders/' . $order['id'], $row['preview']['links'][0]['url']);
        $page = copilotPage($web, (int) $row['id']);
        contains('/admin/orders/' . $order['id'], $page);
        notContains('/apply', $page, 'no hay botón de aplicar');

        copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
        same('proposed', copilotRow($app, (int) $row['id'])['status'], 'aplicar no hace nada');
    }
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {order_refunds}'), 'no se ha devuelto dinero');
    same($paid, $app->db()->value('SELECT paid_total FROM {orders} WHERE id = ?', [$order['id']]));
    same('processing', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$order['id']]), 'y no se ha cancelado');
});

test('un pedido que no existe o que no puede pasar a ese estado da un error y no se puede aplicar', function (): void {
    $app = copilotApp();
    $order = copilotOrder($app);
    $web = copilotAdmin($app);

    $missing = copilotAsk($web, 'Marca el pedido 9999 como enviado');
    same('proposed', $missing['status']);
    contains('No existe el pedido 9999', $missing['preview']['errors'][0]);
    same(false, copilotService($app)->present($missing)['can_apply']);

    $app->db()->update('orders', ['status' => 'pending_payment', 'payment_status' => 'pending'], ['id' => $order['id']]);
    $unpaid = copilotAsk($web, 'Marca el pedido ' . $order['n'] . ' como enviado');
    contains('todavía no está pagado', $unpaid['preview']['errors'][0]);
    copilotPost($web, '/admin/copilot/' . $unpaid['id'] . '/apply');
    same('pending_payment', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$order['id']]), 'no se aplica');

    $other = copilotOrder($app);
    $same = copilotAsk($web, 'Pon el pedido ' . $other['n'] . ' en proceso');
    contains('ya está en ese estado', $same['preview']['errors'][0]);
});

test('dos pedidos que acaban en el mismo número: la propuesta queda incompleta, ofrece los dos y elegir uno la deja lista', function (): void {
    $app = copilotApp();
    $a = copilotOrder($app);
    $b = copilotOrder($app);
    $app->db()->update('orders', ['number' => '2025-000007'], ['id' => $a['id']]);
    $app->db()->update('orders', ['number' => '2026-000007'], ['id' => $b['id']]);
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Marca el pedido 7 como enviado');
    same('incomplete', $row['status']);
    same(2, count($row['preview']['choices']));
    same('order', $row['preview']['choices'][0]['param']);
    contains('2026-000007', $web->get('/admin/copilot/' . $row['id'])->body);

    $r = copilotPost($web, '/admin/copilot/' . $row['id'] . '/complete', ['p' => ['order' => '2025-000007']]);
    same(303, $r->status);
    $row = copilotRow($app, (int) $row['id']);
    same('proposed', $row['status']);
    same('2025-000007', $row['args']['order']);
    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    same('shipped', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$a['id']]));
    same('processing', $app->db()->value('SELECT status FROM {orders} WHERE id = ?', [$b['id']]), 'el otro no se toca');
});

test('marcar enviado con transportista: lo reconoce por su nombre, lo enseña en la vista previa y deshacer lo restaura', function (): void {
    $app = copilotApp();
    $gls = shippingAddCarrier($app, 'gls', ['name' => 'GLS Express']);
    $order = copilotOrder($app);
    $before = $app->db()->row('SELECT carrier_id, carrier_title FROM {orders} WHERE id = ?', [$order['id']]);
    $web = copilotAdmin($app);

    $row = copilotAsk($web, 'Marca el pedido ' . $order['n'] . ' como enviado con GLS Express y el seguimiento Z9988');
    same('GLS Express', $row['args']['carrier']);
    same('Z9988', $row['args']['tracking']);
    same('Transportista', $row['preview']['changes'][2]['label']);
    same('GLS Express', $row['preview']['changes'][2]['to']);

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/apply');
    $now = $app->db()->row('SELECT carrier_id, carrier_title, tracking_code FROM {orders} WHERE id = ?', [$order['id']]);
    same($gls, (int) $now['carrier_id']);
    same('GLS Express', $now['carrier_title']);

    copilotPost($web, '/admin/copilot/' . $row['id'] . '/undo');
    $back = $app->db()->row('SELECT carrier_id, carrier_title, tracking_code FROM {orders} WHERE id = ?', [$order['id']]);
    same($before['carrier_title'], $back['carrier_title']);
    same(null, $back['tracking_code']);
});
