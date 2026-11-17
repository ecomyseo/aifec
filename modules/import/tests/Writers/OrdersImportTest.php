<?php
/**
 * AIFEC — escritor de pedidos: lo que devuelve el servicio de pedidos coincide con el origen campo a campo y al céntimo; es historia
 * (no toca existencias, no genera descargas, no cuenta como venta de hoy, no lo tocan las tareas automáticas, no se le pide opinión);
 * sale en la cuenta del cliente y en sus cifras; las devoluciones dejan los importes y los estados correctos; repetir no duplica nada;
 * lo que no cuadra entra igual con una línea de ajuste; un número repetido choca o toma el prefijo; y no avisa a nadie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\CouponsWriter;
use Aifec\Modules\Import\Writers\OrdersWriter;
use Aifec\Modules\Orders\Admin\OrdersDashboard;

require_once __DIR__ . '/WritersKit2.php';

/** Las apuntaciones de historial que no son del sistema, como [tipo, de, a, público, autor, fecha]. */
function pwHistory(array $order): array
{
    $out = [];
    foreach ($order['history'] as $h) {
        if ($h['kind'] !== 'system') {
            $out[] = [$h['kind'], $h['from_value'], $h['to_value'], $h['is_public'], $h['actor'], $h['created_at']];
        }
    }

    return $out;
}

pwTest('el pedido de ejemplo se escribe y orders->find() lo devuelve campo a campo, al céntimo', function (): void {
    [$app, $transport, $ctx, $product, $customer] = pwOrderSite();
    $out = (new OrdersWriter($app))->write(pwOrder(), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $order = $app->service('orders')->find((int) $out['id']);

    same(['P001042', 'ana@cliente.test', 'es', 'EUR', $customer], [$order['number'], $order['email'], $order['lang'], $order['currency'], $order['customer_id']]);
    same(['shipped', 'paid', 'fulfilled', 'shipped'], [$order['status'], $order['payment_status'], $order['fulfillment_status'], $order['state']['code']], 'el estado de aquí por su significado');
    same(['redsys', 'Tarjeta', null, 'Mensajería 24 h', 'TRK123'], [$order['payment_method'], $order['payment_title'], $order['carrier_id'], $order['carrier_title'], $order['tracking_code']], 'el transportista del origen que no se trajo no se inventa: queda su título');
    same([false, 10661, 0, 590, 0, 11251, 2363, 13614, 13614, 0], [
        $order['prices_include_tax'], $order['items_total'], $order['discount_total'], $order['shipping_total'], $order['fee_total'],
        $order['total_net'], $order['total_tax'], $order['total'], $order['paid_total'], $order['refunded_total'],
    ], 'los importes, tal cual');
    same([['name' => 'IVA 21 %', 'rate' => 2100, 'base' => 11251, 'amount' => 2363]], $order['taxes']);
    same([], $order['adjustments']);
    same(['first_name' => 'Ana', 'last_name' => 'Ruiz Peña', 'company' => '', 'vat_id' => '', 'phone' => '999000001', 'line1' => 'Calle Mayor 1', 'line2' => '2.º B',
        'postcode' => '28013', 'city' => 'Madrid', 'region' => '28', 'country' => 'ES'], $order['billing'], 'la dirección, copiada');
    same($order['billing'], $order['shipping']);
    same(['Llamar antes de entregar', 'import', '9001', false], [$order['customer_note'], $order['source'], $order['legacy_id'], $order['test']]);
    same(['2026-10-01 10:15:00', '2026-10-01 10:16:00', '2026-10-02 08:00:00', null, null], [$order['placed_at'], $order['paid_at'], $order['shipped_at'], $order['completed_at'], $order['cancelled_at']], 'con las fechas del origen');
    same('2026-10-01 10:15:00', $order['created_at']);

    same(2, count($order['items']));
    $line = $order['items'][0];
    same(['product', $product, $ctx->localId('variants', '501'), 'MESA-ROBLE-P', 'Mesa auxiliar de roble', 'Tamaño: Pequeña', 1], [$line['kind'], $line['product_id'], $line['variant_id'], $line['sku'], $line['name'], $line['variant_label'], $line['qty']], 'la línea con su producto y su variante de aquí');
    same([10661, 0, 10661, 10661, 2239, 2100, 'IVA 21 %', 6500, false, 0, 0], [$line['unit_price'], $line['discount'], $line['line_total'], $line['line_net'], $line['line_tax'], $line['tax_rate'], $line['tax_name'], $line['weight'], $line['is_digital'], $line['refunded_qty'], $line['refunded_total']]);
    $ship = $order['items'][1];
    same(['shipping', 'Mensajería 24 h', 590, 590, 124, 2100, null], [$ship['kind'], $ship['name'], $ship['unit_price'], $ship['line_net'], $ship['line_tax'], $ship['tax_rate'], $ship['product_id']]);

    same(1, count($order['payments']));
    $payment = $order['payments'][0];
    same(['redsys', 'charge', '000001042', 13614, 'ok', '2026-10-01 10:16:00', false], [$payment['method'], $payment['kind'], $payment['reference'], $payment['amount'], $payment['status'], $payment['created_at'], $payment['test']]);
    same([['status', '1', '2', true, 'sistema', '2026-10-01 10:16:00'], ['status', '2', 'shipped', false, 'system', '2026-10-02 08:00:00']], pwHistory($order), 'el historial con sus fechas y autores');
    same([], $order['refunds']);
    same(['orders_count' => 1, 'orders_total' => 13614, 'order_items_count' => 2], (new OrdersWriter($app))->checksums($ctx));
    pwSilent($app, $transport, 'tras escribir un pedido pagado y enviado');
});

pwTest('es historia: no toca las existencias, no genera descargas ni licencias, no suma ventas del producto y no pide ninguna opinión', function (): void {
    [$app, $transport, $ctx, $product] = pwOrderSite();
    $stock = pwStock($app, $product);
    $sales = (int) wrValue($app, 'SELECT sales_count FROM {products} WHERE id = ?', [$product]);
    $db = $app->db();
    $tables = ['order_downloads', 'review_requests', 'jobs'];
    $before = [];
    foreach ($tables as $table) {
        $before[$table] = wrCount($app, $table);
    }
    $writer = new OrdersWriter($app);
    foreach ([pwOrder(), pwOrderN(3, ['state_meaning' => 'delivered', 'state' => '6']), pwOrderN(4, ['state_meaning' => 'pending', 'state' => '1', 'paid_total' => 0])] as $record) {
        $writer->write($record, $ctx);
    }
    same(3, wrCount($app, 'orders'));
    same($stock, pwStock($app, $product), 'las existencias de cada variante y del producto, intactas');
    same($sales, (int) wrValue($app, 'SELECT sales_count FROM {products} WHERE id = ?', [$product]), 'y sus ventas');
    foreach ($tables as $table) {
        same($before[$table], wrCount($app, $table), 'sin filas nuevas en ' . $table);
    }
    same(0, (int) $db->value("SELECT COUNT(*) FROM {order_history} WHERE kind = 'system' AND to_value = 'stock_held'"), 'nadie apunta que las existencias estén descontadas');
    pwSilent($app, $transport, 'tras escribir tres pedidos');
});

pwTest('el informe de ventas de hoy no lo cuenta, y un pedido cobrado hoy de verdad sí', function (): void {
    [$app, , $ctx] = pwOrderSite();
    same(0, OrdersDashboard::summary($app)['today']['n'], 'antes: nada');
    (new OrdersWriter($app))->write(pwOrder(), $ctx);
    $summary = OrdersDashboard::summary($app);
    same([0, 0, 0, 0], [$summary['today']['n'], $summary['today']['amount'], $summary['week']['n'], $summary['month']['n']], 'ni hoy, ni 7 días, ni 30');

    $now = $app->clock()->sql();
    $app->db()->insert('orders', ['number' => 'HOY-1', 'email' => 'otra@cliente.test', 'status' => 'processing', 'payment_status' => 'paid', 'total' => 5000, 'paid_total' => 5000, 'test' => 0,
        'placed_at' => $now, 'paid_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $app->invalidate(['orders']);
    $summary = OrdersDashboard::summary($app);
    same([1, 5000], [$summary['today']['n'], $summary['today']['amount']], 'el de hoy cuenta (así la medida es de verdad) y el importado sigue sin contar');
});

pwTest('el pedido sale en la cuenta del cliente, que entra con su contraseña de siempre', function (): void {
    [$app, , $ctx] = pwOrderSite();
    (new OrdersWriter($app))->write(pwOrder(), $ctx);
    $web = new TestClient($app);
    same(303, $web->post('/mi-cuenta/entrar', ['email' => 'ana@cliente.test', 'password' => 'clave-larga-1'])->status, 'entra');
    $page = $web->get('/mi-cuenta/pedidos');
    same(200, $page->status);
    contains('/mi-cuenta/pedidos/P001042', $page->body, 'el pedido antiguo está en su lista');
    $detail = $web->get('/mi-cuenta/pedidos/P001042');
    same(200, $detail->status);
    contains('Mesa auxiliar de roble', $detail->body, 'y se abre con sus líneas');
});

pwTest('las cifras del cliente se recalculan desde sus pedidos cobrados: ni los cancelados ni los pendientes cuentan', function (): void {
    [$app, , $ctx, , $customer] = pwOrderSite();
    $writer = new OrdersWriter($app);
    $row = static function () use ($app, $customer): array {
        $r = $app->db()->row('SELECT orders_count, total_spent FROM {customers} WHERE id = ?', [$customer]);

        return [(int) $r['orders_count'], (int) $r['total_spent']];
    };
    $writer->write(pwOrder(), $ctx);
    same([1, 13614], $row());
    $writer->write(pwOrderN(3, ['state_meaning' => 'cancelled', 'state' => '5', 'paid_total' => 0]), $ctx);
    $writer->write(pwOrderN(4, ['state_meaning' => 'pending', 'state' => '1', 'paid_total' => 0]), $ctx);
    same([1, 13614], $row(), 'un cancelado y uno pendiente no suman');
    $writer->write(pwOrderN(5, ['state_meaning' => 'delivered', 'state' => '6']), $ctx);
    same([2, 27228], $row());
    $writer->write(pwOrderN(5, ['state_meaning' => 'delivered', 'state' => '6', 'customer_note' => 'otra nota']), $ctx);
    same([2, 27228], $row(), 'repetirlo con cambios no suma otra vez');
});

pwTest('una devolución parcial deja lo devuelto, el estado del cobro y las líneas correctos; una total, el pedido reembolsado', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new OrdersWriter($app);
    $refund = ['amount' => 5000, 'tax' => 868, 'reason' => 'Rotura', 'at' => '2026-10-05T09:00:00Z', 'restock' => false, 'lines' => [['index' => 0, 'qty' => 1, 'amount' => 5000]]];
    $payments = neutralSample('orders')['payments'];
    $payments[] = ['method' => 'redsys', 'kind' => 'refund', 'reference' => 'RF-1', 'amount' => 5000, 'status' => 'ok', 'at' => '2026-10-05T09:00:00Z'];
    $id = (int) $writer->write(pwOrder(['refunds' => [$refund], 'payments' => $payments, 'refunded_total' => 5000]), $ctx)['id'];
    $order = $app->service('orders')->find($id);

    same([5000, 'partially_refunded', 'shipped'], [$order['refunded_total'], $order['payment_status'], $order['status']]);
    same(1, count($order['refunds']));
    $r = $order['refunds'][0];
    $refundPayment = $app->db()->row("SELECT id FROM {order_payments} WHERE order_id = ? AND kind = 'refund'", [$id]);
    same([5000, 868, 'Rotura', '2026-10-05 09:00:00', false, (int) $refundPayment['id']], [$r['amount'], $r['tax'], $r['reason'], $r['created_at'], $r['restock'], $r['payment_id']], 'unida a su movimiento de dinero');
    same([(int) $order['items'][0]['id'] => 1], $r['items'], 'con las líneas que devolvió');
    same([1, 5000], [$order['items'][0]['refunded_qty'], $order['items'][0]['refunded_total']], 'y la línea sabe lo que se le devolvió');
    same(2, count($order['payments']));

    $full = (int) $writer->write(pwOrderN(3, ['state_meaning' => 'refunded', 'state' => '8', 'refunded_total' => 13614]), $ctx)['id'];
    $done = $app->service('orders')->find($full);
    same(['refunded', 'refunded', 'returned', 13614], [$done['status'], $done['payment_status'], $done['fulfillment_status'], $done['refunded_total']]);
});

pwTest('repetir el pedido no duplica pagos ni historial; cambiarlo lo reescribe en su sitio y respeta las notas que se pusieron aquí', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new OrdersWriter($app);
    $record = pwOrder();
    $id = (int) $writer->write($record, $ctx)['id'];
    $counts = wrCounts($app);

    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    same($counts, wrCounts($app), 'ni una fila nueva');
    $app->service('orders')->addNote($id, 'Nota que escribí yo', false);
    $mine = wrCounts($app);

    $changed = $writer->write(pwOrder(['tracking_code' => 'TRK999']), $ctx);
    same(TypeWriter::UPDATED, $changed['result']);
    same($id, $changed['id']);
    same($mine, wrCounts($app), 'se reescribe sin duplicar líneas, pagos ni historial');
    $order = $app->service('orders')->find($id);
    same('TRK999', $order['tracking_code']);
    same(1, count($order['payments']));
    same(2, count($order['items']));
    check(in_array('Nota que escribí yo', array_column($order['history'], 'note'), true), 'la nota de aquí sigue en el historial');
});

pwTest('un pedido editado aquí después de traerlo choca; overwrite_local lo pisa; el simulacro decide lo mismo sin escribir', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new OrdersWriter($app);
    $id = (int) $writer->write(pwOrder(), $ctx)['id'];
    $app->clock()->advance(300);
    $app->db()->update('orders', ['customer_note' => 'editada aquí', 'updated_at' => $app->clock()->sql()], ['id' => $id]);
    $app->clock()->advance(300);

    $out = $writer->write(pwOrder(['tracking_code' => 'NUEVO']), $ctx);
    same(TypeWriter::CONFLICT, $out['result']);
    same($id, $out['id']);
    same(['editada aquí', 'TRK123'], [wrValue($app, 'SELECT customer_note FROM {orders} WHERE id = ?', [$id]), wrValue($app, 'SELECT tracking_code FROM {orders} WHERE id = ?', [$id])], 'lo de aquí queda intacto');

    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    same(TypeWriter::CONFLICT, $writer->write(pwOrder(['tracking_code' => 'NUEVO']), $dry)['result']);
    same(TypeWriter::CREATED, $writer->write(pwOrderN(3), $dry)['result']);
    same(TypeWriter::CREATED, $writer->write(pwOrderN(4, ['customer_id' => null, 'email' => 'nadie@cliente.test']), $dry)['result']);
    same($counts, wrCounts($app), 'ni pedidos, ni líneas, ni pagos, ni historial, ni un cliente invitado');

    same(TypeWriter::UPDATED, $writer->write(pwOrder(['tracking_code' => 'NUEVO']), writersContext($app, ['overwrite_local' => true]))['result']);
    same('NUEVO', wrValue($app, 'SELECT tracking_code FROM {orders} WHERE id = ?', [$id]));
});

pwTest('si las líneas no suman el total del origen, el pedido entra igual con una línea de ajuste con nombre, una nota y en «revisa a mano»', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $out = (new OrdersWriter($app))->write(pwOrder(['total_net' => 11337, 'total' => 13700, 'paid_total' => 13700]), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $order = $app->service('orders')->find((int) $out['id']);
    same([13700, 13700, 11337, 2363], [$order['total'], $order['paid_total'], $order['total_net'], $order['total_tax']], 'los importes del origen, sin tocar');
    same(3, count($order['items']), 'las dos líneas del origen y la de ajuste');
    $adjust = $order['items'][2];
    same(['fee', 86, 86, 0, true], [$adjust['kind'], $adjust['line_total'], $adjust['line_net'], $adjust['line_tax'], $adjust['data']['adjustment']]);
    check($adjust['name'] !== '', 'con nombre');
    $sum = 0;
    foreach ($order['items'] as $line) {
        $sum += $line['line_net'] + $line['line_tax'];
    }
    same(13700, $sum, 'ahora las líneas suman el total');
    same(86, $order['adjustments'][0]['amount'], 'y el ajuste está en la lista de ajustes del pedido');
    contains('did not add up', implode(' ', $ctx->noteTexts('orders')));
    same(1, $ctx->unmappedCount('review', 'Order whose lines did not add up'));
    same(['orders_count' => 1, 'orders_total' => 13700, 'order_items_count' => 2], (new OrdersWriter($app))->checksums($ctx), 'y la línea de ajuste no cuenta como línea del origen');

    $negative = (int) (new OrdersWriter($app))->write(pwOrderN(3, ['total_net' => 11200, 'total_tax' => 2363, 'total' => 13563, 'paid_total' => 13563]), $ctx)['id'];
    $line = $app->service('orders')->find($negative)['items'][2];
    same(['discount', -51], [$line['kind'], $line['line_total']], 'una diferencia a la baja es un descuento');
});

pwTest('un descuento que el origen da aparte, ya repartido en las líneas, se guarda como informativo y no suma', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $items = neutralSample('orders')['items'];
    $items[] = ['kind' => 'discount', 'name' => 'Cupón OTOÑO10', 'qty' => 1, 'unit_price' => -1000, 'line_total' => -1000, 'line_net' => -826, 'line_tax' => -174, 'tax_rate' => 2100];
    $id = (int) (new OrdersWriter($app))->write(pwOrder(['items' => $items, 'discount_total' => 1000]), $ctx)['id'];
    $order = $app->service('orders')->find($id);
    same(3, count($order['items']));
    $discount = $order['items'][2];
    same(['discount', -1000, 0, 0, true], [$discount['kind'], $discount['line_total'], $discount['line_net'], $discount['line_tax'], $discount['data']['informational']]);
    same([], $order['adjustments'], 'y no hay ajuste');
});

pwTest('un producto que no vino entra como línea con su nombre y su referencia, y se dice', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $items = neutralSample('orders')['items'];
    $items[0]['product_id'] = '555';
    $items[0]['variant_id'] = '777';
    $id = (int) (new OrdersWriter($app))->write(pwOrder(['items' => $items]), $ctx)['id'];
    $line = $app->service('orders')->find($id)['items'][0];
    same([null, null, 'MESA-ROBLE-P', 'Mesa auxiliar de roble', 10661], [$line['product_id'], $line['variant_id'], $line['sku'], $line['name'], $line['line_total']]);
    contains('was not brought', implode(' ', $ctx->noteTexts('orders')));
});

pwTest('un número que ya existe aquí choca; con el prefijo de la opción o del ajuste entra con otro número', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $now = $app->clock()->sql();
    $mine = $app->db()->insert('orders', ['number' => 'P001042', 'email' => 'otra@cliente.test', 'status' => 'completed', 'payment_status' => 'paid', 'total' => 100, 'placed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $writer = new OrdersWriter($app);

    $out = $writer->write(pwOrder(), $ctx);
    same(TypeWriter::CONFLICT, $out['result']);
    contains('already exists', $out['reason']);
    contains('order_prefix', $out['reason'], 'y dice cómo traerlo');
    same(1, wrCount($app, 'orders'));
    same(0, wrCount($app, 'order_items'), 'no queda nada a medias');
    same(100, (int) wrValue($app, 'SELECT total FROM {orders} WHERE id = ?', [$mine]), 'el de aquí, intacto');

    $with = writersContext($app, ['order_prefix' => 'WC-']);
    $done = $writer->write(pwOrder(), $with);
    same(TypeWriter::CREATED, $done['result']);
    same('WC-P001042', wrValue($app, 'SELECT number FROM {orders} WHERE id = ?', [$done['id']]));
    contains('WC-P001042', implode(' ', $with->noteTexts('orders')), 'con su nota');
    same(TypeWriter::UNCHANGED, $writer->write(pwOrder(), $with)['result'], 'repetirlo sigue siendo lo mismo');

    $app->settings()->set('import.order_prefix', 'TP-');
    $viaSetting = $writer->write(pwOrderN(3, ['number' => 'P001042']), writersContext($app));
    same('TP-P001042', wrValue($app, 'SELECT number FROM {orders} WHERE id = ?', [$viaSetting['id']]), 'el ajuste import.order_prefix vale igual');
});

pwTest('cada significado de estado cae en el estado de aquí que le toca, con el cobro y el envío coherentes', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new OrdersWriter($app);
    $cases = [
        ['pending', 0, 0, 'on_hold', 'pending', 'unfulfilled'],
        ['on_hold', 0, 0, 'on_hold', 'pending', 'unfulfilled'],
        ['paid', 13614, 0, 'processing', 'paid', 'unfulfilled'],
        ['processing', 13614, 0, 'processing', 'paid', 'unfulfilled'],
        ['shipped', 13614, 0, 'shipped', 'paid', 'fulfilled'],
        ['delivered', 13614, 0, 'completed', 'paid', 'fulfilled'],
        ['cancelled', 0, 0, 'cancelled', 'cancelled', 'unfulfilled'],
        ['refunded', 13614, 13614, 'refunded', 'refunded', 'returned'],
        ['failed', 0, 0, 'failed', 'failed', 'unfulfilled'],
    ];
    foreach ($cases as $i => $case) {
        $id = (int) $writer->write(pwOrderN($i + 1, ['state_meaning' => $case[0], 'state' => 's' . $i, 'paid_total' => $case[1], 'refunded_total' => $case[2]]), $ctx)['id'];
        $row = $app->db()->row('SELECT status, payment_status, fulfillment_status FROM {orders} WHERE id = ?', [$id]);
        same([$case[3], $case[4], $case[5]], [$row['status'], $row['payment_status'], $row['fulfillment_status']], $case[0]);
    }
    same(0, wrCount($app, 'orders', "status = 'pending_payment'"), 'ninguno entra «pendiente de pago»');
    contains('on hold', implode(' ', $ctx->noteTexts('orders')));
});

pwTest('la equivalencia elegida manda sobre el significado, y una que no existe aquí cae en el significado con una nota', function (): void {
    [$app] = pwOrderSite();
    $writer = new OrdersWriter($app);
    $first = writersContext($app, [], ['order_state' => ['4' => 'completed']]);
    $a = (int) $writer->write(pwOrder(), $first)['id'];
    same('completed', wrValue($app, 'SELECT status FROM {orders} WHERE id = ?', [$a]));

    $second = writersContext($app, [], ['order_state' => ['4' => 'no-existe']]);
    $b = (int) $writer->write(pwOrderN(3), $second)['id'];
    same('shipped', wrValue($app, 'SELECT status FROM {orders} WHERE id = ?', [$b]));
    contains('does not exist here', implode(' ', $second->noteTexts('orders')));

    $third = writersContext($app, [], ['order_state' => ['1' => 'pending_payment']]);
    $c = (int) $writer->write(pwOrderN(4, ['state' => '1', 'state_meaning' => 'pending', 'paid_total' => 0]), $third)['id'];
    same('on_hold', wrValue($app, 'SELECT status FROM {orders} WHERE id = ?', [$c]), 'ni por equivalencia entra pendiente de pago');
});

pwTest('las tareas automáticas no tocan un pedido importado, ni el pendiente de hoy: la de caducidad sí cancela uno normal sin pagar', function (): void {
    [$app, $transport, $ctx] = pwOrderSite();
    $writer = new OrdersWriter($app);
    $paid = (int) $writer->write(pwOrder(), $ctx)['id'];
    $pending = (int) $writer->write(pwOrderN(3, ['state_meaning' => 'pending', 'state' => '1', 'paid_total' => 0, 'paid_at' => null, 'shipped_at' => null,
        'placed_at' => '2026-11-12T10:30:00Z', 'payments' => [], 'history' => []]), $ctx)['id'];
    $now = $app->clock()->sql();
    $control = $app->db()->insert('orders', ['number' => 'CTRL-PEND', 'email' => 'otra@cliente.test', 'status' => 'pending_payment', 'payment_status' => 'pending', 'total' => 1000,
        'placed_at' => '2026-11-12 08:00:00', 'created_at' => $now, 'updated_at' => $now]);
    $state = static function (int $id) use ($app): array {
        $row = $app->db()->row('SELECT status, payment_status, updated_at FROM {orders} WHERE id = ?', [$id]);

        return [$row['status'], $row['payment_status'], $row['updated_at']];
    };
    $before = [$state($paid), $state($pending)];

    $ran = pwTasks($app);
    check(in_array('orders.expire', $ran, true) && in_array('reviews.ask', $ran, true) && in_array('mail.send', $ran, true), 'corrieron la caducidad, las opiniones y el correo');
    same($before, [$state($paid), $state($pending)], 'ni el pagado ni el pendiente cambian');
    same('on_hold', $state($pending)[0]);
    same('cancelled', $state($control)[0], 'el control sin importar: la tarea sí funciona');
    pwSilent($app, $transport, 'tras las tareas programadas');
});

pwTest('cancelar desde el panel un pedido importado no devuelve existencias que nunca se descontaron', function (): void {
    [$app, $transport, $ctx, $product] = pwOrderSite();
    $id = (int) (new OrdersWriter($app))->write(pwOrderN(3, ['state_meaning' => 'pending', 'state' => '1', 'paid_total' => 0, 'payments' => []]), $ctx)['id'];
    $stock = pwStock($app, $product);
    pwSilent($app, $transport, 'antes de cancelar');
    pwAllowMail($transport); // cancelar desde el panel avisa al cliente: eso ya no es la migración
    same(true, $app->service('orders')->cancel($id, 'Probando'), 'se cancela');
    same('cancelled', wrValue($app, 'SELECT status FROM {orders} WHERE id = ?', [$id]));
    same($stock, pwStock($app, $product), 'y el almacén sigue como estaba');
});

pwTest('un pedido sin cliente en el origen cuelga de un cliente invitado con su correo, que se reutiliza', function (): void {
    [$app, $transport, $ctx] = pwOrderSite();
    $writer = new OrdersWriter($app);
    $a = (int) $writer->write(pwOrderN(3, ['customer_id' => null, 'email' => 'Sin-Cuenta@Cliente.test']), $ctx)['id'];
    $guest = $app->db()->row("SELECT * FROM {customers} WHERE email = 'sin-cuenta@cliente.test'");
    same([1, null, 'Ana'], [(int) $guest['guest'], $guest['password'], $guest['first_name']], 'invitado, sin contraseña');
    same((int) $guest['id'], (int) wrValue($app, 'SELECT customer_id FROM {orders} WHERE id = ?', [$a]));
    $b = (int) $writer->write(pwOrderN(4, ['customer_id' => null, 'email' => 'sin-cuenta@cliente.test']), $ctx)['id'];
    same((int) $guest['id'], (int) wrValue($app, 'SELECT customer_id FROM {orders} WHERE id = ?', [$b]), 'el segundo no crea otro');
    same(1, wrCount($app, 'customers', "email = 'sin-cuenta@cliente.test'"));
    same([2, 27228], [(int) $app->db()->value('SELECT orders_count FROM {customers} WHERE id = ?', [(int) $guest['id']]), (int) $app->db()->value('SELECT total_spent FROM {customers} WHERE id = ?', [(int) $guest['id']])]);

    // Un pedido cuyo cliente no se trajo, pero el correo ya es de un cliente: cuelga de ese.
    $c = (int) $writer->write(pwOrderN(5, ['customer_id' => '404']), $ctx)['id'];
    same((int) wrValue($app, 'SELECT id FROM {customers} WHERE email = ?', ['ana@cliente.test']), (int) wrValue($app, 'SELECT customer_id FROM {orders} WHERE id = ?', [$c]));
    pwSilent($app, $transport, 'tras traer pedidos de invitados');
});

pwTest('la clave única de los pagos no choca: la referencia repetida lleva el origen delante y la vacía se inventa', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new OrdersWriter($app);
    $a = (int) $writer->write(pwOrder(), $ctx)['id'];
    $b = (int) $writer->write(pwOrderN(3), $ctx)['id'];
    $c = (int) $writer->write(pwOrderN(4), $ctx)['id'];
    same('000001042', wrValue($app, 'SELECT reference FROM {order_payments} WHERE order_id = ?', [$a]));
    same('woocommerce:000001042', wrValue($app, 'SELECT reference FROM {order_payments} WHERE order_id = ?', [$b]), 'otro pedido, la misma referencia');
    check(strpos((string) wrValue($app, 'SELECT reference FROM {order_payments} WHERE order_id = ?', [$c]), 'P001044') !== false, 'y a la tercera se le añade el número del pedido');
    same(3, wrCount($app, 'order_payments'));

    $payments = [['method' => 'transferencia', 'kind' => 'charge', 'reference' => '', 'amount' => 13614, 'status' => 'pending']];
    $d = (int) $writer->write(pwOrderN(5, ['payments' => $payments]), $ctx)['id'];
    $row = $app->db()->row('SELECT reference, status, created_at FROM {order_payments} WHERE order_id = ?', [$d]);
    same(['import-P001045-1', 'pending', '2026-10-01 10:15:00'], [$row['reference'], $row['status'], $row['created_at']], 'sin fecha, la del pedido');
});

pwTest('las facturas del origen quedan dentro del pedido y, con módulo de facturas, con su número y su fecha', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $id = (int) (new OrdersWriter($app))->write(pwOrder(), $ctx)['id'];
    $attribution = json_decode((string) wrValue($app, 'SELECT attribution FROM {orders} WHERE id = ?', [$id]), true);
    same([['number' => 'F2026-000317', 'date' => '2026-10-01T10:16:00Z', 'kind' => 'invoice']], $attribution['import']['invoices']);
    same('9001', $attribution['import']['legacy_id']);

    [$withInvoices, , $ictx] = pwOrderSite([], [], ['invoices']);
    $oid = (int) (new OrdersWriter($withInvoices))->write(pwOrder(['invoices' => [
        ['number' => 'F2026-000317', 'date' => '2026-10-01T10:16:00Z', 'kind' => 'invoice'], ['number' => 'R2026-000004', 'date' => '2026-10-06T10:00:00Z', 'kind' => 'credit'],
    ]]), $ictx)['id'];
    $rows = $withInvoices->db()->select('SELECT * FROM {invoices} WHERE order_id = ?', [$oid]);
    same(1, count($rows), 'la factura entra; la rectificativa solo queda dentro del pedido');
    same(['F2026-000317', 'F2026', 317, 'invoice', '2026-10-01 10:16:00', 13614, 11251, 2363, 0], [
        $rows[0]['full_number'], $rows[0]['series'], (int) $rows[0]['number'], $rows[0]['type'], $rows[0]['issued_at'], (int) $rows[0]['total'], (int) $rows[0]['net'], (int) $rows[0]['tax'], (int) $rows[0]['test'],
    ]);
    $invoice = $withInvoices->service('invoices')->findByNumber('F2026-000317');
    check($invoice !== null && (int) $invoice['order_id'] === $oid, 'el módulo de facturas la encuentra por su número');
    $ctxNotes = implode(' ', $ictx->noteTexts('orders'));
    contains('credit note', $ctxNotes);
    $attr = json_decode((string) wrValue($withInvoices, 'SELECT attribution FROM {orders} WHERE id = ?', [$oid]), true);
    same(2, count($attr['import']['invoices']));
});

pwTest('los cupones del pedido cuentan como usos del cupón traído, sin sumarlos otra vez a «uses»', function (): void {
    [$app, , $ctx, , $customer] = pwOrderSite();
    $coupon = (int) (new CouponsWriter($app))->write(wr('coupons'), $ctx)['id'];
    $usesBefore = (int) wrValue($app, 'SELECT uses FROM {coupons} WHERE id = ?', [$coupon]);
    $writer = new OrdersWriter($app);
    $id = (int) $writer->write(pwOrder(['discount_total' => 1000, 'coupons' => [['code' => 'otoño10', 'amount' => 1000], ['code' => 'DESCONOCIDO', 'amount' => 200]]]), $ctx)['id'];

    $uses = $app->db()->select('SELECT * FROM {coupon_uses} WHERE order_id = ?', [$id]);
    same(1, count($uses), 'solo el del cupón que se trajo');
    same([$coupon, $customer, 'ana@cliente.test', 1000, '2026-10-01 10:15:00'], [(int) $uses[0]['coupon_id'], (int) $uses[0]['customer_id'], $uses[0]['email'], (int) $uses[0]['amount'], $uses[0]['created_at']]);
    same($usesBefore, (int) wrValue($app, 'SELECT uses FROM {coupons} WHERE id = ?', [$coupon]), 'el origen ya lo traía contado');
    $order = $app->service('orders')->find($id);
    same([['coupon_id' => $coupon, 'code' => 'OTOÑO10', 'label' => '', 'amount' => 1000], ['coupon_id' => null, 'code' => 'DESCONOCIDO', 'label' => '', 'amount' => 200]], $order['coupons']);
    contains('DESCONOCIDO', implode(' ', $ctx->noteTexts('orders')));

    $writer->write(pwOrder(['discount_total' => 1000, 'tracking_code' => 'X', 'coupons' => [['code' => 'otoño10', 'amount' => 1000]]]), $ctx);
    same(1, wrCount($app, 'coupon_uses', 'order_id = ?', [$id]), 'volver a escribir no duplica el uso');
});

pwTest('los estados del historial pasan por la equivalencia elegida y el actual por el de aquí', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $with = writersContext($app, [], ['order_state' => ['1' => 'pending_payment', '2' => 'processing']]);
    $id = (int) (new OrdersWriter($app))->write(pwOrder(), $with)['id'];
    same([['status', 'pending_payment', 'processing', true, 'sistema', '2026-10-01 10:16:00'], ['status', 'processing', 'shipped', false, 'system', '2026-10-02 08:00:00']], pwHistory($app->service('orders')->find($id)), 'de «2» a «4»: processing → shipped');
    $kinds = neutralSample('orders')['history'];
    $kinds[] = ['at' => '2026-10-03T08:00:00Z', 'kind' => 'note', 'note' => 'Cliente avisado', 'public' => true, 'actor' => 'Marta'];
    $kinds[] = ['at' => '2026-10-03T09:00:00Z', 'kind' => 'mail', 'note' => 'Enviado el correo'];
    $kinds[] = ['at' => '2026-10-03T10:00:00Z', 'kind' => 'other', 'note' => 'Algo'];
    $two = (int) (new OrdersWriter($app))->write(pwOrderN(3, ['history' => $kinds]), $ctx)['id'];
    $history = pwHistory($app->service('orders')->find($two));
    same(['note', null, null, true, 'Marta', '2026-10-03 08:00:00'], $history[2]);
    same(['mail', 'system'], [$history[3][0], $history[3][4]]);
    same(4, count($history), 'la apuntación «other» pasa a ser del sistema y esta lista no la enseña');
    same('system', wrValue($app, "SELECT kind FROM {order_history} WHERE order_id = ? AND note = 'Algo'", [$two]), 'lo desconocido es del sistema');
});

pwTest('las cifras del destino cuentan lo traído, y las líneas de ajuste no cuentan como líneas del origen', function (): void {
    [$app, , $ctx] = pwOrderSite();
    $writer = new OrdersWriter($app);
    $writer->write(pwOrder(), $ctx);
    $writer->write(pwOrderN(3, ['total_net' => 11337, 'total' => 13700, 'paid_total' => 13700]), $ctx);
    $now = $app->clock()->sql();
    $own = $app->db()->insert('orders', ['number' => 'PROPIO', 'email' => 'x@cliente.test', 'status' => 'completed', 'payment_status' => 'paid', 'total' => 999, 'placed_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $app->db()->insert('order_items', ['order_id' => $own, 'kind' => 'product', 'name' => 'Propio', 'qty' => 1]);
    same(['orders_count' => 2, 'orders_total' => 13614 + 13700, 'order_items_count' => 4], $writer->checksums($ctx));
});
