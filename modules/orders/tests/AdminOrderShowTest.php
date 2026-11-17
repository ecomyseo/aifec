<?php
/**
 * AIFEC — pruebas de la ficha de un pedido del panel: lo que enseña, el cliente, las direcciones, el origen, el historial y
 * la hoja de impresión.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/AdminKit.php';

test('la ficha contiene líneas, desglose con las mismas cifras que el pedido, cobros, historial y direcciones', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app, ['sku' => 'CAM-1']);
    $id = adminOrdersPaid($app, 'gw', [[$p, 0, 2]]);
    $order = $app->service('orders')->find($id);
    $money = static function (int $cents) use ($app): string {
        return $app->i18n()->money($cents, 'EUR');
    };
    $web = adminClient($app);
    $r = $web->get('/admin/orders/' . $id);
    same(200, $r->status);
    $body = $r->body;
    contains('Pedido ' . $order['number'], $body, 'el título lleva el número');
    contains('Camiseta', $body);
    contains('CAM-1', $body, 'la referencia de la línea');
    contains($money(2000), $body, 'precio de la unidad');
    contains($money(4000), $body, 'total de la línea');
    contains($money((int) $order['items_total']), $body, 'subtotal');
    contains('Envío: Estándar', $body, 'la línea de envío con su transportista');
    contains($money((int) $order['shipping_total']), $body);
    foreach ($order['taxes'] as $tax) {
        if ((int) $tax['amount'] > 0) {
            contains('Incluye ' . $tax['name'], $body, 'el impuesto por tipo');
            contains($money((int) $tax['amount']), $body);
        }
    }
    contains($money((int) $order['total']), $body, 'el total');
    contains($money((int) $order['paid_total']), $body, 'lo cobrado');
    contains('PAY-', $body, 'el cobro con su referencia');
    contains('Pedido realizado', $body, 'el historial lleva lo que pasó');
    contains('Pago recibido', $body);
    contains('Calle Mayor 1', $body, 'la dirección de envío y la de facturación');
    contains('28001 Madrid', $body);
    contains('data-af-copy=', $body, 'botón de copiar la dirección');
    contains('Los precios incluyen impuestos.', $body);
    contains('data-af-print', $body, 'botón de imprimir');
    notContains('onclick=', $body, 'sin manejadores escritos en la página: la política de contenido no los ejecuta');
    contains('@media print', $body, 'hoja de estilos de impresión');
    // La ficha en claro se vuelve a calcular (Orders::viewToken()), así que el panel sí enlaza la página que ve el cliente.
    $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [$id]);
    contains('/pedido/' . $number . '/' . \Aifec\Modules\Orders\Orders::viewToken($app, $number), $body, 'enlace «ver como el cliente»');
    contains('/admin/orders/' . $id . '/status', $body, 'quien puede editar ve el formulario de estado');
});

test('«Otros pedidos» no cuenta los de prueba como pedidos: los dice aparte, como lo gastado', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app, ['sku' => 'CAM-9']);
    $first = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $second = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $app->db()->run('UPDATE {orders} SET test = 1 WHERE id IN (?, ?)', [$first, $second]);
    $body = adminClient($app)->get('/admin/orders/' . $second)->body;
    check(preg_match('#<dt>Otros pedidos</dt><dd>0 <span class="af-muted">· 1 pedido de prueba, que no cuenta</span></dd>#u', $body) === 1, 'cero de verdad y uno de prueba');
});

test('la lista de preparación suma por producto las unidades de los pedidos por preparar, con el número de cada pedido', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app, ['sku' => 'CAM-7']);
    $a = adminOrdersPaid($app, 'gw', [[$p, 0, 2]]);
    $b = adminOrdersPaid($app, 'gw', [[$p, 0, 3]]);
    $web = adminClient($app);
    $r = $web->get('/admin/orders/picking');
    same(200, $r->status);
    contains('2 pedidos por preparar', $r->body);
    contains('<td class="af-num"><strong>5</strong></td>', $r->body, '2 + 3 unidades');
    $numbers = $app->db()->column('SELECT number FROM {orders} WHERE id IN (?, ?) ORDER BY id', [$a, $b]);
    contains('>' . $numbers[0] . '</a>, <a', $r->body, 'con los dos pedidos');
    contains('Lista de preparación', $web->get('/admin/orders')->body, 'en el menú');
    $app->db()->run("UPDATE {orders} SET fulfillment_status = 'fulfilled'");
    contains('Nada que preparar', $web->get('/admin/orders/picking')->body);
});

test('la ficha del cliente en el panel lista sus últimos pedidos con su estado y enlace, y los de prueba marcados', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app, ['sku' => 'CAM-8']);
    $id = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $order = $app->service('orders')->find($id);
    check($order['customer_id'] !== null, 'el pedido tiene cliente');
    $body = adminClient($app)->get('/admin/r/customers/' . (int) $order['customer_id'])->body;
    contains('Últimos pedidos', $body);
    contains('href="/admin/orders/' . $id . '"><strong>' . $order['number'] . '</strong></a>', $body, 'el pedido con su enlace');
    contains('En preparación', $body, 'con su estado');
    contains('Ver todos sus pedidos', $body);
});

test('la forma de pago sale con su nombre en el idioma del panel, en la ficha y en el listado, y no como un código ni en inglés', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $id = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $web = adminClient($app);
    // Con la forma registrada, su nombre actual: el cobro dice «Pasarela de pruebas» y no el código «gw».
    $ficha = $web->get('/admin/orders/' . $id)->body;
    contains('<td>Pasarela de pruebas</td>', $ficha, 'el cobro lleva el nombre, no el código');
    notContains('<td>gw</td>', $ficha);
    // Una forma que ya no está registrada, con el texto guardado en inglés: se traduce; y sin texto, queda el código.
    $app->db()->update('orders', ['payment_method' => 'antigua', 'payment_title' => 'Cash on delivery'], ['id' => $id]);
    contains('<dd>Contra reembolso</dd>', $web->get('/admin/orders/' . $id)->body, 'el texto guardado en inglés se traduce');
    $list = $web->get('/admin/orders')->body;
    contains('Contra reembolso', $list, 'y en el listado también');
    notContains('Cash on delivery', $list);
});

test('la ficha escapa los datos del cliente: un nombre con «&», una nota con comillas y un código con etiquetas', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app, ['name' => 'Camiseta "Sol" & Luna']);
    $billing = aifecOrderCheckout()['billing'];
    $billing['company'] = 'Ruiz & Hijos <S.L.>';
    $id = adminOrdersPaid($app, 'gw', [[$p, 0, 1]], ['billing' => $billing, 'note' => 'Dejar en "portería" & llamar <ya>']);
    $app->db()->run("UPDATE {orders} SET tracking_code = '<b>ES1</b>' WHERE id = ?", [$id]);
    $web = adminClient($app);
    $body = $web->get('/admin/orders/' . $id)->body;
    contains('Ruiz &amp; Hijos &lt;S.L.&gt;', $body);
    contains('Dejar en &quot;portería&quot; &amp; llamar &lt;ya&gt;', $body, 'la nota del cliente');
    contains('Camiseta &quot;Sol&quot; &amp; Luna', $body);
    contains('&lt;b&gt;ES1&lt;/b&gt;', $body);
    notContains('Ruiz & Hijos <S.L.>', $body);
    notContains('<b>ES1</b>', $body);
});

test('la ficha enseña el cliente con sus pedidos anteriores y lo gastado, el origen y de dónde llegó', function (): void {
    $app = adminOrdersApp();
    $p = adminOrdersProduct($app);
    $first = adminOrdersPaid($app, 'gw', [[$p, 0, 1]]);
    $second = adminOrdersPaid($app, 'gw', [[$p, 0, 2]]);
    $app->db()->run("UPDATE {orders} SET source = 'assistant', attribution = ? WHERE id = ?", [
        json_encode(['source' => 'ChatGPT', 'landing' => '/producto/camiseta', 'at' => $app->clock()->sql()]), $second,
    ]);
    $web = adminClient($app);
    $body = $web->get('/admin/orders/' . $second)->body;
    $customerId = (int) $app->db()->value('SELECT customer_id FROM {orders} WHERE id = ?', [$second]);
    contains('/admin/r/customers/' . $customerId, $body, 'enlace a la ficha del cliente');
    contains('ana@cliente.test', $body);
    contains('q=ana%40cliente.test', $body, 'enlace a sus pedidos');
    contains($app->i18n()->money(2495 + 4495, 'EUR'), $body, 'total gastado');
    contains('Asistente de IA', $body, 'el canal');
    contains('Llegó desde ChatGPT', $body, 'la atribución');
    contains('/producto/camiseta', $body);

    // Sin permiso para ver clientes, el nombre no enlaza.
    adminOrdersRole($app, 'solopedidos', ['dashboard.view', 'orders.view']);
    $limited = adminClient($app, 'solopedidos');
    $page = $limited->get('/admin/orders/' . $first)->body;
    notContains('/admin/r/customers/', $page);
    notContains('/admin/orders/' . $first . '/status', $page, 'sin orders.edit no hay formularios');
    notContains('/admin/orders/' . $first . '/refund', $page);
    notContains('/admin/orders/' . $first . '/note', $page);
});

test('quien solo puede ver no encuentra acciones y quien tiene orders.notes solo la de notas', function (): void {
    $app = adminOrdersApp();
    $id = adminOrdersPaid($app, 'gw', [[adminOrdersProduct($app), 0, 1]]);
    $support = adminClient($app, 'support');
    $page = $support->get('/admin/orders/' . $id)->body;
    contains('/admin/orders/' . $id . '/note', $page, 'soporte puede dejar notas');
    notContains('/admin/orders/' . $id . '/status', $page);
    notContains('/admin/orders/' . $id . '/tracking', $page);
    notContains('/admin/orders/' . $id . '/refund', $page);
    notContains('/admin/orders/' . $id . '/cancel', $page);
});
