<?php
/**
 * AIFEC — pruebas de los estados, la cancelación, las devoluciones y la caducidad de los pedidos sin pagar.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;
use Aifec\Modules\Orders\OrderTasks;

require_once __DIR__ . '/helpers.php';

/**
 * Un pedido pagado de dos camisetas de 20,00 € (diez en existencias, ocho después del pedido). Devuelve [app, producto, id].
 *
 * @return array{0:App,1:int,2:int}
 */
function orderStatusPaid(int $qty = 2): array
{
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $id = aifecOrderPaid($app, new TestClient($app), [[$p, 0, $qty]]);

    return [$app, $p, $id];
}

/**
 * Un pedido sin pagar. Devuelve [app, producto, id].
 *
 * @return array{0:App,1:int,2:int}
 */
function orderStatusUnpaid(int $qty = 2): array
{
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $placed = aifecOrderPlace($app, new TestClient($app), [[$p, 0, $qty]]);
    check(!empty($placed['ok']), 'el pedido se crea');

    return [$app, $p, (int) $placed['id']];
}

test('pasar a enviado pone la fecha, el estado de preparación, quién lo hizo y manda el correo con el seguimiento', function (): void {
    list($app, $p, $id) = orderStatusPaid();
    $orders = $app->service('orders');
    $app->db()->run("UPDATE {orders} SET tracking_code = 'ES123456789' WHERE id = ?", [$id]);
    $seen = [];
    $app->hooks()->on('order.status', static function (array $order, string $from, string $to) use (&$seen): void {
        $seen[] = [$from, $to];
    });

    same(true, $orders->setStatus($id, 'shipped', 'Sale hoy', 7));
    $order = $orders->find($id);
    same('shipped', $order['status']);
    same('fulfilled', $order['fulfillment_status']);
    same($app->clock()->sql(), $order['shipped_at']);
    same([['processing', 'shipped']], $seen);
    $entry = $order['history'][count($order['history']) - 1];
    same(['status', 'processing', 'shipped', 'Sale hoy', 7, 'user', true], [$entry['kind'], $entry['from_value'], $entry['to_value'], $entry['note'], (int) $entry['user_id'], $entry['actor'], $entry['is_public']]);
    $mails = aifecOrderMails($app, 'order_shipped');
    same(1, count($mails));
    contains('ES123456789', (string) $mails[0]['html']);
    contains('Código de seguimiento', (string) $mails[0]['html']);

    same(false, $orders->setStatus($id, 'shipped'), 'el mismo estado no cambia nada');
    same(true, $orders->setStatus($id, 'completed'));
    same($app->clock()->sql(), $orders->find($id)['completed_at']);
    same(1, count(aifecOrderMails($app, 'order_completed')));
});

test('un cambio de estado sin aviso no manda correo', function (): void {
    list($app, $p, $id) = orderStatusPaid();
    $before = count(aifecOrderMails($app));

    same(true, $app->service('orders')->setStatus($id, 'shipped', '', null, false));
    same($before, count(aifecOrderMails($app)));
});

test('las transiciones prohibidas y los estados que no existen se rechazan sin dejar rastro', function (): void {
    list($app, $p, $id) = orderStatusUnpaid();
    $orders = $app->service('orders');
    $history = aifecOrderCount($app, 'order_history');

    same(false, $orders->setStatus($id, 'volando'), 'un estado que no existe');
    same(false, $orders->setStatus($id, 'shipped'), 'sin pagar no se envía');
    same(false, $orders->setStatus($id, 'processing'), 'sin pagar no se prepara');
    same(false, $orders->setStatus($id, 'refunded'), 'devuelto se pone con refund()');
    same(false, $orders->setStatus($id, 'failed'), 'fallido se pone con paymentFailed()');
    same(false, $orders->setStatus(999, 'on_hold'), 'un pedido que no existe');
    same($history, aifecOrderCount($app, 'order_history'));
    same('pending_payment', $orders->find($id)['status']);

    // De cancelado no se vuelve atrás.
    same(true, $orders->cancel($id, 'Prueba'));
    same(false, $orders->setStatus($id, 'processing'));
    same(false, $orders->setStatus($id, 'on_hold'));
    same('cancelled', $orders->find($id)['status']);
});

test('a enviado solo se llega si hay algo que enviar y un fallido no se puede dar por pagado a mano', function (): void {
    $app = aifecOrderApp();
    $guia = aifecProduct($app, ['kind' => 'digital', 'price' => 1500, 'manage_stock' => 0]);
    $id = aifecOrderPaid($app, new TestClient($app), [[$guia, 0, 1]], ['billing' => ['first_name' => 'Ana', 'country' => 'ES']]);
    $orders = $app->service('orders');

    same('completed', $orders->find($id)['status']);
    same('not_needed', $orders->find($id)['fulfillment_status']);
    same(false, $orders->setStatus($id, 'shipped'));

    $physical = aifecProduct($app, ['stock' => 5, 'weight' => 100]);
    $placed = aifecOrderPlace($app, new TestClient($app), [[$physical, 0, 1]]);
    $orders->paymentFailed((int) $placed['id'], 'gw', 'Rechazado');
    same(false, $orders->setStatus((int) $placed['id'], 'processing'));
    same(false, $orders->setStatus((int) $placed['id'], 'completed'));
    same('failed', $orders->find((int) $placed['id'])['status']);
});

test('cancelar repone las existencias una vez, libera el cupón, manda el correo y lanza la acción', function (): void {
    $app = aifecOrderApp();
    couponsMake($app, ['code' => 'OTONO']);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $web = new TestClient($app);
    $placed = aifecCartRun($web, static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 2);
        $cart->applyCoupon('OTONO');
        $cart->setPaymentMethod('gw');

        return $app->service('orders')->place(aifecOrderCheckout());
    });
    $id = (int) $placed['id'];
    $orders = $app->service('orders');
    $cancelled = [];
    $app->hooks()->on('order.cancelled', static function (array $order) use (&$cancelled): void {
        $cancelled[] = $order['number'];
    });
    same(8, aifecOrderStock($app, $p));
    same(1, aifecOrderCount($app, 'coupon_uses'));

    same(true, $orders->cancel($id, 'El cliente lo pidió', true, 3));
    same(false, $orders->cancel($id, 'Otra vez'));
    $order = $orders->find($id);
    same('cancelled', $order['status']);
    same('cancelled', $order['payment_status']);
    same($app->clock()->sql(), $order['cancelled_at']);
    same(10, aifecOrderStock($app, $p));
    same(0, aifecOrderCount($app, 'coupon_uses'));
    same(0, (int) $app->db()->value("SELECT uses FROM {coupons} WHERE code = 'OTONO'"));
    same([$order['number']], $cancelled);
    same(1, aifecOrderCount($app, 'stock_moves', "reason = 'Order cancelled'"));
    $mails = aifecOrderMails($app, 'order_cancelled');
    same(1, count($mails));
    same('ana@cliente.test', $mails[0]['to_email']);
    $entries = array_values(array_filter($order['history'], static function (array $h): bool {
        return $h['kind'] === 'status' && $h['to_value'] === 'cancelled';
    }));
    same(1, count($entries));
    same(['cancelled', 'El cliente lo pidió', 3], [$entries[0]['to_value'], $entries[0]['note'], (int) $entries[0]['user_id']]);
    same(['stock_released'], array_column(array_filter($order['history'], static function (array $h): bool {
        return $h['kind'] === 'system';
    }), 'to_value'));
});

test('cancelar sin reponer deja las existencias como estaban y un pedido pagado no se cancela', function (): void {
    list($app, $p, $id) = orderStatusUnpaid();
    $orders = $app->service('orders');
    same(true, $orders->cancel($id, 'Mercancía perdida', false));
    same(8, aifecOrderStock($app, $p));
    same(0, aifecOrderCount($app, 'stock_moves', "reason = 'Order cancelled'"));

    list($app2, $p2, $paid) = orderStatusPaid();
    same(false, $app2->service('orders')->cancel($paid, 'Ya pagado'));
    same(false, $app2->service('orders')->setStatus($paid, 'cancelled'));
    same('processing', $app2->service('orders')->find($paid)['status']);
    same(8, aifecOrderStock($app2, $p2));
});

test('una devolución parcial por la pasarela apunta el dinero, las unidades, repone y avisa', function (): void {
    list($app, $p, $id) = orderStatusPaid();
    $orders = $app->service('orders');
    $order = $orders->find($id);
    $line = (int) $order['items'][0]['id'];
    $seen = [];
    $app->hooks()->on('order.refunded', static function (array $order, array $refund) use (&$seen): void {
        $seen[] = [$order['id'], $refund['amount']];
    });

    $refundId = $orders->refund($id, 2000, [$line => 1], 'Talla incorrecta', true, true, 5);
    $after = $orders->find($id);

    same('partially_refunded', $after['payment_status']);
    same('processing', $after['status']);
    same(2000, $after['refunded_total']);
    same(1, count($after['refunds']));
    same([$refundId, 2000, 'Talla incorrecta', true, [$line => 1]], [$after['refunds'][0]['id'], $after['refunds'][0]['amount'], $after['refunds'][0]['reason'], $after['refunds'][0]['restock'], array_map('intval', $after['refunds'][0]['items'])]);
    check($after['refunds'][0]['tax'] > 0 && $after['refunds'][0]['tax'] < 2000, 'la parte de impuesto de lo devuelto');
    same([[2000, 'Talla incorrecta']], aifecOrderPayment($app)->refunds);
    $refundPayment = $after['payments'][1];
    same(['refund', 'RF-1', 2000, 'ok'], [$refundPayment['kind'], $refundPayment['reference'], $refundPayment['amount'], $refundPayment['status']]);
    same(1, $after['items'][0]['refunded_qty']);
    same(9, aifecOrderStock($app, $p));
    same(1, count(aifecOrderMails($app, 'order_refunded')));
    contains('20,00', (string) aifecOrderMails($app, 'order_refunded')[0]['html']);
    same([[$id, 2000]], $seen);
});

test('devolver lo que queda cierra el pedido como devuelto y repone todo lo pendiente, y no se puede devolver más', function (): void {
    list($app, $p, $id) = orderStatusPaid();
    $orders = $app->service('orders');
    $order = $orders->find($id);
    $line = (int) $order['items'][0]['id'];

    $orders->refund($id, 2000, [$line => 1], 'Una unidad', false, true);
    $remaining = $order['total'] - 2000;
    $orders->refund($id, $remaining, [], 'El resto', false, true);
    $after = $orders->find($id);

    same('refunded', $after['payment_status']);
    same('refunded', $after['status']);
    same($order['total'], $after['refunded_total']);
    same(2, $after['items'][0]['refunded_qty']);
    same(10, aifecOrderStock($app, $p));
    same(2, count($after['refunds']));
    same([], aifecOrderPayment($app)->refunds, 'sin pasar por la pasarela');
    same('refund-', substr((string) $after['payments'][1]['reference'], 0, 7));
    throws(function () use ($orders, $id): void {
        $orders->refund($id, 100, [], 'De más', false, false);
    }, \InvalidArgumentException::class);
    same($order['total'], $orders->find($id)['refunded_total']);
});

test('una devolución mayor que lo cobrado, sin pagar, de cero o con líneas ajenas se rechaza sin apuntar nada', function (): void {
    list($app, $p, $id) = orderStatusPaid();
    $orders = $app->service('orders');
    $total = $orders->find($id)['total'];
    $before = aifecOrderCount($app, 'order_refunds') + aifecOrderCount($app, 'order_payments');

    throws(function () use ($orders, $id, $total): void {
        $orders->refund($id, $total + 1, [], 'Demasiado', false, false);
    }, \InvalidArgumentException::class);
    throws(function () use ($orders, $id): void {
        $orders->refund($id, 0, [], 'Cero', false, false);
    }, \InvalidArgumentException::class);
    throws(function () use ($orders, $id): void {
        $orders->refund($id, 100, [999 => 1], 'Línea ajena', false, false);
    }, \InvalidArgumentException::class);
    throws(function () use ($orders, $id): void {
        $orders->refund($id, 100, [(int) $orders->find($id)['items'][0]['id'] => 3], 'Más unidades de las compradas', false, false);
    }, \InvalidArgumentException::class);
    throws(function () use ($orders): void {
        $orders->refund(999, 100, [], 'No existe', false, false);
    }, \InvalidArgumentException::class);
    same(0, $orders->find($id)['refunded_total']);
    same($before, aifecOrderCount($app, 'order_refunds') + aifecOrderCount($app, 'order_payments'));
    same([], aifecOrderPayment($app)->refunds);

    list($app2, $p2, $unpaid) = orderStatusUnpaid();
    throws(function () use ($app2, $unpaid): void {
        $app2->service('orders')->refund($unpaid, 100, [], 'Sin pagar', false, false);
    }, \InvalidArgumentException::class);
});

test('si la pasarela rechaza la devolución no se apunta nada y la excepción sale tal cual', function (): void {
    list($app, $p, $id) = orderStatusPaid();
    $orders = $app->service('orders');
    aifecOrderPayment($app)->refundThrows = true;
    $before = count(aifecOrderMails($app));

    try {
        $orders->refund($id, 1000, [], 'Prueba', true, true);
        check(false, 'tenía que lanzar');
    } catch (\RuntimeException $e) {
        same('The gateway refused the refund', $e->getMessage());
    }
    $order = $orders->find($id);
    same(0, $order['refunded_total']);
    same('paid', $order['payment_status']);
    same([], $order['refunds']);
    same(1, count($order['payments']));
    same(8, aifecOrderStock($app, $p));
    same($before, count(aifecOrderMails($app)));

    // Una forma de pago que no devuelve por pasarela: lo mismo.
    $gw = aifecOrderPayment($app);
    $gw->refundThrows = false;
    $gw->canRefund = false;
    throws(function () use ($orders, $id): void {
        $orders->refund($id, 1000, [], 'Prueba', true, false);
    }, \RuntimeException::class);
    same(0, $orders->find($id)['refunded_total']);
});

test('dos devoluciones que juntas pasan de lo cobrado: la segunda se rechaza', function (): void {
    list($app, $p, $id) = orderStatusPaid();
    $orders = $app->service('orders');
    $total = $orders->find($id)['total'];

    $orders->refund($id, $total - 100, [], 'Casi todo', false, false);
    throws(function () use ($orders, $id): void {
        $orders->refund($id, 101, [], 'Un poco más', false, false);
    }, \InvalidArgumentException::class);
    same('partially_refunded', $orders->find($id)['payment_status']);
    same($total - 100, $orders->find($id)['refunded_total']);
});

test('los pedidos de pasarela sin pagar caducan; el reciente, la transferencia, el pagado y el muy viejo no se tocan', function (): void {
    $app = aifecOrderApp();
    $gw = aifecOrderPayment($app);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 20, 'weight' => 200]);
    $now = $app->clock()->now();
    $age = static function (int $id, int $seconds) use ($app, $now): void {
        $app->db()->run('UPDATE {orders} SET placed_at = ? WHERE id = ?', [$app->clock()->sql($now - $seconds), $id]);
    };

    $old = (int) aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]])['id'];
    $recent = (int) aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]])['id'];
    $gw->action = PaymentAction::instructions('<p>IBAN</p>');
    $transfer = (int) aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]])['id'];
    $gw->action = null;
    $paid = aifecOrderPaid($app, new TestClient($app), [[$p, 0, 1]]);
    $ancient = (int) aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]])['id'];
    $age($old, 3 * 3600);
    $age($recent, 30 * 60);
    $age($transfer, 3 * 3600);
    $age($paid, 3 * 3600);
    $age($ancient, 3 * 86400);
    same(15, aifecOrderStock($app, $p));
    $before = count(aifecOrderMails($app, 'order_cancelled'));

    $orders = $app->service('orders');
    same(1, $orders->expireUnpaid());
    same(0, $orders->expireUnpaid());
    same('cancelled', $orders->find($old)['status']);
    same('pending_payment', $orders->find($recent)['status']);
    same('on_hold', $orders->find($transfer)['status']);
    same('processing', $orders->find($paid)['status']);
    same('pending_payment', $orders->find($ancient)['status']);
    same(16, aifecOrderStock($app, $p));
    same($before, count(aifecOrderMails($app, 'order_cancelled')), 'caducar no manda correo');

    // El plazo es un ajuste: con diez minutos, el de media hora también caduca.
    $app->settings()->set('orders.pending_minutes', 10);
    same(1, $orders->expireUnpaid());
    same('cancelled', $orders->find($recent)['status']);
});

test('cada pasada de caducidad cancela como mucho 50 pedidos', function (): void {
    $app = aifecOrderApp();
    $app->boot();
    $old = $app->clock()->sql($app->clock()->now() - 3 * 3600);
    for ($i = 1; $i <= 55; ++$i) {
        $app->db()->insert('orders', [
            'number' => 'T-' . $i, 'email' => 'ana@cliente.test', 'placed_at' => $old, 'created_at' => $old, 'updated_at' => $old,
        ]);
    }
    $orders = $app->service('orders');

    same(50, $orders->expireUnpaid());
    same(5, $orders->expireUnpaid());
    same(0, $orders->expireUnpaid());
    same(55, aifecOrderCount($app, 'orders', "status = 'cancelled'"));
});

test('la tarea orders.expire está declarada cada cinco minutos y dice cuántos ha cancelado', function (): void {
    $app = aifecOrderApp();
    $definitions = $app->modules()->definitions('jobs');
    same(300, $definitions['orders']['cron']['orders.expire']['every']);
    same([OrderTasks::class, 'expire'], $definitions['orders']['cron']['orders.expire']['run']);

    same(null, OrderTasks::expire($app));
    $p = aifecProduct($app, ['stock' => 5, 'weight' => 100]);
    $id = (int) aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]])['id'];
    $app->db()->run('UPDATE {orders} SET placed_at = ? WHERE id = ?', [$app->clock()->sql($app->clock()->now() - 3 * 3600), $id]);
    same('1 cancelled', OrderTasks::expire($app));
});

test('las notas se apuntan en el historial, públicas o privadas, y una nota vacía no se apunta', function (): void {
    list($app, $p, $id) = orderStatusPaid();
    $orders = $app->service('orders');
    $before = count($orders->find($id)['history']);

    $orders->addNote($id, 'Llamar antes de entregar', false, 4);
    $orders->addNote($id, 'Tu pedido sale mañana', true);
    $orders->addNote($id, '   ', true);
    $orders->addNote(999, 'No existe', true);
    $history = $orders->find($id)['history'];

    same($before + 2, count($history));
    $notes = array_values(array_filter($history, static function (array $h): bool {
        return $h['kind'] === 'note';
    }));
    same([['Llamar antes de entregar', false, 4], ['Tu pedido sale mañana', true, 0]], array_map(static function (array $h): array {
        return [$h['note'], $h['is_public'], (int) $h['user_id']];
    }, $notes));
});
