<?php
/**
 * AIFEC — pruebas de los estados de pedido del panel (recurso «order_states»): alta, edición, borrado y el desplegable de la ficha.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/AdminKit.php';

/**
 * Da de alta un estado propio por el formulario del panel y devuelve su id.
 *
 * @param array<string,string> $cambios
 */
function adminOrdersNewState(App $app, TestClient $web, string $code, string $name, array $cambios = []): int
{
    $r = adminOrdersPost($web, '/admin/r/order_states/save', ['f' => $cambios + ['name' => $name, 'code' => $code, 'kind' => 'open', 'color' => 'warn', 'mail' => '', 'position' => '']]);
    same(302, $r->status, 'el alta del estado ' . $code . ' redirige');
    $id = (int) $app->db()->value('SELECT id FROM {order_states} WHERE code = ?', [$code]);
    check($id > 0, 'el estado ' . $code . ' se ha guardado');

    return $id;
}

test('los estados de serie salen traducidos en el listado, con su clase y su color, y se abre con permiso', function (): void {
    $app = adminOrdersApp();
    $web = adminClient($app);
    $r = $web->get('/admin/r/order_states');
    same(200, $r->status);
    foreach (['Pendiente de pago', 'En espera', 'En preparación', 'Enviado', 'Completado', 'Cancelado', 'Devuelto', 'Pago fallido'] as $name) {
        contains($name, $r->body, $name);
    }
    contains('En curso', $r->body, 'la clase');
    contains('processing', $r->body, 'el código');
    $support = adminClient($app, 'support');
    same(200, $support->get('/admin/r/order_states')->status, 'soporte puede verlos');
    same(403, adminClient($app, 'editor')->get('/admin/r/order_states')->status);
    same(403, adminOrdersPost($support, '/admin/r/order_states/save', ['f' => ['name' => 'X', 'code' => 'x', 'kind' => 'open', 'color' => 'info', 'mail' => '']])->status, 'soporte no los cambia');
});

test('un estado propio se da de alta, queda el último y sale en el desplegable de la ficha', function (): void {
    $app = adminOrdersApp();
    $web = adminClient($app);
    $id = adminOrdersNewState($app, $web, 'packing', 'Preparando & embalando', ['mail' => 'order_shipped', 'color' => 'ok']);
    $row = $app->db()->row('SELECT * FROM {order_states} WHERE id = ?', [$id]);
    same(['Preparando & embalando', 'packing', 'open', 'ok', 'order_shipped', 0, 8], [$row['name'], $row['code'], $row['kind'], $row['color'], $row['mail'], (int) $row['system'], (int) $row['position']]);

    $order = adminOrdersPaid($app, 'gw', [[adminOrdersProduct($app), 0, 1]]);
    $page = $web->get('/admin/orders/' . $order)->body;
    contains('<option value="packing">Preparando &amp; embalando</option>', $page, 'el estado propio sale en el desplegable, escapado');
    notContains('<option value="processing">', $page, 'el estado actual no se ofrece');
    notContains('<option value="cancelled">', $page, 'cancelar tiene su propio botón');
    notContains('<option value="refunded">', $page);

    $r = adminOrdersPost($web, '/admin/orders/' . $order . '/status', ['status' => 'packing', 'notify' => '1']);
    same(302, $r->status);
    same('packing', $app->service('orders')->find($order)['status']);
    same(1, count(aifecOrderMails($app, 'order_shipped')), 'el correo del estado propio se manda al avisar');
    contains('Preparando &amp; embalando', $web->get('/admin/orders')->body, 'el listado enseña el estado propio');
    same(1, aifecOrderCount($app, 'audit', 'action = ? AND entity = ?', ['create', 'order_states']));
});

test('no se borra un estado de serie ni uno en uso, y uno propio sin pedidos sí', function (): void {
    $app = adminOrdersApp();
    $web = adminClient($app);
    $used = adminOrdersNewState($app, $web, 'packing', 'Preparando');
    $spare = adminOrdersNewState($app, $web, 'spare', 'Sobrante');
    $order = adminOrdersPaid($app, 'gw', [[adminOrdersProduct($app), 0, 1]]);
    same(true, $app->service('orders')->setStatus($order, 'packing', '', null, false));
    $system = (int) $app->db()->value("SELECT id FROM {order_states} WHERE code = 'completed'");

    adminOrdersPost($web, '/admin/r/order_states/' . $system . '/delete');
    contains('Un estado de serie no se puede borrar', $web->follow()->body);
    same(1, aifecOrderCount($app, 'order_states', 'id = ?', [$system]));

    adminOrdersPost($web, '/admin/r/order_states/' . $used . '/delete');
    contains('Este estado lo usa 1 pedido, así que no se puede borrar.', $web->follow()->body);
    same(1, aifecOrderCount($app, 'order_states', 'id = ?', [$used]));

    adminOrdersPost($web, '/admin/r/order_states/' . $spare . '/delete');
    same(0, aifecOrderCount($app, 'order_states', 'id = ?', [$spare]), 'el propio y sin pedidos se borra');

    // En bloque pasa lo mismo.
    adminOrdersPost($web, '/admin/r/order_states/bulk', ['action' => 'delete', 'ids' => [(string) $system, (string) $used]]);
    same(1, aifecOrderCount($app, 'order_states', 'id = ?', [$system]));
    same(1, aifecOrderCount($app, 'order_states', 'id = ?', [$used]));
});

test('el código de un estado de serie no se cambia aunque se mande, y el de uno en uso tampoco', function (): void {
    $app = adminOrdersApp();
    $web = adminClient($app);
    $system = (int) $app->db()->value("SELECT id FROM {order_states} WHERE code = 'completed'");
    $r = adminOrdersPost($web, '/admin/r/order_states/' . $system . '/save', ['f' => ['name' => 'Entregado', 'code' => 'hackeado', 'kind' => 'done', 'color' => 'ok', 'mail' => 'order_completed', 'position' => '4']]);
    same(302, $r->status);
    $row = $app->db()->row('SELECT * FROM {order_states} WHERE id = ?', [$system]);
    same(['completed', 'Entregado', 1], [$row['code'], $row['name'], (int) $row['system']], 'el nombre cambia, el código no');
    contains('readonly', $web->get('/admin/r/order_states/' . $system)->body, 'el código se ve pero no se escribe');

    $used = adminOrdersNewState($app, $web, 'packing', 'Preparando');
    $order = adminOrdersPaid($app, 'gw', [[adminOrdersProduct($app), 0, 1]]);
    $app->service('orders')->setStatus($order, 'packing', '', null, false);
    $r = adminOrdersPost($web, '/admin/r/order_states/' . $used . '/save', ['f' => ['name' => 'Preparando', 'code' => 'otro', 'kind' => 'open', 'color' => 'warn', 'mail' => '', 'position' => '9']]);
    same(422, $r->status, 'el cambio se rechaza');
    contains('Este estado está en uso: su código no se puede cambiar', $r->body);
    same('packing', (string) $app->db()->value('SELECT code FROM {order_states} WHERE id = ?', [$used]));
    same('packing', $app->service('orders')->find($order)['status'], 'el pedido no se queda huérfano');

    // Un código mal escrito o repetido no se guarda.
    $r = adminOrdersPost($web, '/admin/r/order_states/save', ['f' => ['name' => 'Malo', 'code' => 'Con Espacios', 'kind' => 'open', 'color' => 'warn', 'mail' => '']]);
    same(422, $r->status);
    $r = adminOrdersPost($web, '/admin/r/order_states/save', ['f' => ['name' => 'Repetido', 'code' => 'on_hold', 'kind' => 'open', 'color' => 'warn', 'mail' => '']]);
    same(422, $r->status);
    same(0, aifecOrderCount($app, 'order_states', 'name IN (?, ?)', ['Malo', 'Repetido']));
});
