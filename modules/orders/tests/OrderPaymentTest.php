<?php
/**
 * AIFEC — pruebas del cobro: markPaid solo cuenta la primera vez, importes que no cuadran, las cuatro acciones de la
 * forma de pago, cobro fallido, cobros que llegan tarde y devolución única de las existencias.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\PaymentAction;
use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/**
 * Un pedido sin pagar de dos camisetas (una tienda con diez en existencias). Devuelve [app, id de la camiseta, resultado de place()].
 *
 * @return array{0:App,1:int,2:array<string,mixed>}
 */
function orderPaymentUnpaid(int $qty = 2): array
{
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $placed = aifecOrderPlace($app, new TestClient($app), [[$p, 0, $qty]]);
    check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);

    return [$app, $p, $placed];
}

test('markPaid solo cuenta la primera vez: un correo, un cambio de estado, un cobro y las cifras sumadas una vez', function (): void {
    list($app, $p, $placed) = orderPaymentUnpaid(2);
    $orders = $app->service('orders');
    $id = (int) $placed['id'];
    // Un pedido de verdad (la pasarela de las pruebas los marca de prueba, y esos no suman a las cifras).
    $app->db()->update('orders', ['test' => 0], ['id' => $id]);
    $total = (int) $orders->find($id)['total'];
    $paidEvents = 0;
    $app->hooks()->on('order.paid', static function (array $order) use (&$paidEvents): void {
        ++$paidEvents;
    });

    same(true, $orders->markPaid($id, 'gw', 'PAY-1', $total, ['id' => 'abc']));
    same(false, $orders->markPaid($id, 'gw', 'PAY-1', $total));
    same(false, $orders->markPaid($id, 'gw', 'PAY-1', $total));

    $order = $orders->find($id);
    same('paid', $order['payment_status']);
    same('processing', $order['status']);
    same($total, $order['paid_total']);
    check($order['paid_at'] !== null, 'se apunta cuándo se pagó');
    same(1, $paidEvents);
    same(1, count($order['payments']));
    same(['gw', 'charge', 'PAY-1', $total, 'ok'], [$order['payments'][0]['method'], $order['payments'][0]['kind'], $order['payments'][0]['reference'], $order['payments'][0]['amount'], $order['payments'][0]['status']]);
    same(['abc'], array_values($order['payments'][0]['raw']));
    same(['pending_payment', 'processing'], array_column(array_filter($order['history'], static function (array $h): bool {
        return $h['kind'] === 'status';
    }), 'to_value'));
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
    same('ana@cliente.test', aifecOrderMails($app, 'order_confirmed')[0]['to_email']);
    same(1, count(aifecOrderMails($app, 'admin_new_order')));
    same('tienda@ejemplo.test', aifecOrderMails($app, 'admin_new_order')[0]['to_email']);
    same(2, (int) $app->db()->value('SELECT sales_count FROM {products} WHERE id = ?', [$p]));
    $customer = $app->db()->row('SELECT orders_count, total_spent FROM {customers} WHERE id = ?', [$order['customer_id']]);
    same([1, $total], [(int) $customer['orders_count'], (int) $customer['total_spent']]);
});

test('un pedido de prueba pagado no suma ni a lo vendido del producto ni a lo gastado por el cliente', function (): void {
    list($app, $p, $placed) = orderPaymentUnpaid(2);
    $orders = $app->service('orders');
    $id = (int) $placed['id'];
    $app->db()->update('orders', ['test' => 1], ['id' => $id]);
    same(true, $orders->markPaid($id, 'gw', 'PAY-T', (int) $orders->find($id)['total']));
    same(0, (int) $app->db()->value('SELECT sales_count FROM {products} WHERE id = ?', [$p]), 'no cuenta como vendido');
    $customer = $app->db()->row('SELECT orders_count, total_spent FROM {customers} WHERE id = ?', [$orders->find($id)['customer_id']]);
    same([0, 0], [(int) $customer['orders_count'], (int) $customer['total_spent']], 'ni como gastado');
});

test('el mismo aviso no crea otro cobro y un cobro extra con otra referencia se apunta sin repetir nada', function (): void {
    list($app, $p, $placed) = orderPaymentUnpaid(1);
    $orders = $app->service('orders');
    $id = (int) $placed['id'];
    $app->db()->update('orders', ['test' => 0], ['id' => $id]);
    $total = (int) $orders->find($id)['total'];

    same(true, $orders->markPaid($id, 'gw', 'PAY-1', $total));
    same(false, $orders->markPaid($id, 'gw', 'PAY-1', $total));
    same(1, aifecOrderCount($app, 'order_payments', "order_id = ? AND kind = 'charge'", [$id]));

    same(false, $orders->markPaid($id, 'gw', 'PAY-2', $total));
    same(2, aifecOrderCount($app, 'order_payments', "order_id = ? AND kind = 'charge'", [$id]));
    $notes = array_column(array_filter($orders->find($id)['history'], static function (array $h): bool {
        return $h['to_value'] === 'extra_payment';
    }), 'note');
    same(1, count($notes));
    contains('PAY-2', $notes[0]);
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
    same(1, (int) $app->db()->value('SELECT sales_count FROM {products} WHERE id = ?', [$p]));
});

test('una referencia de cobro que ya es de otro pedido no vale y un pedido que no existe tampoco', function (): void {
    $app = aifecOrderApp();
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 200]);
    $a = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);
    $b = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);
    $orders = $app->service('orders');

    same(true, $orders->markPaid((int) $a['id'], 'gw', 'REF', (int) $orders->find((int) $a['id'])['total']));
    same(false, $orders->markPaid((int) $b['id'], 'gw', 'REF', (int) $orders->find((int) $b['id'])['total']));
    same('pending', $orders->find((int) $b['id'])['payment_status']);
    same(0, aifecOrderCount($app, 'order_payments', 'order_id = ?', [(int) $b['id']]));
    same(false, $orders->markPaid(999, 'gw', 'X', 100));
});

test('un importe distinto del total deja el pedido en espera con una nota con los dos importes, y el cobro justo lo paga', function (): void {
    list($app, $p, $placed) = orderPaymentUnpaid(2);
    $orders = $app->service('orders');
    $id = (int) $placed['id'];
    $total = (int) $orders->find($id)['total'];

    same(false, $orders->markPaid($id, 'gw', 'PAY-SHORT', $total - 1000));
    same(false, $orders->markPaid($id, 'gw', 'PAY-SHORT', $total - 1000));
    $order = $orders->find($id);
    same('on_hold', $order['status']);
    same('pending', $order['payment_status']);
    same(0, $order['paid_total']);
    $notes = array_values(array_filter($order['history'], static function (array $h): bool {
        return $h['to_value'] === 'amount_mismatch';
    }));
    same(1, count($notes));
    contains($app->i18n()->money($total - 1000, 'EUR'), (string) $notes[0]['note']);
    contains($app->i18n()->money($total, 'EUR'), (string) $notes[0]['note']);
    same(0, count(aifecOrderMails($app, 'order_confirmed')));
    same(0, (int) $app->db()->value('SELECT sales_count FROM {products} WHERE id = ?', [$p]));

    same(true, $orders->markPaid($id, 'gw', 'PAY-FULL', $total));
    $after = $orders->find($id);
    same('processing', $after['status']);
    same('paid', $after['payment_status']);
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
});

test('con la acción «paid» el pedido nace pagado, en preparación y marcado como de pruebas', function (): void {
    $app = aifecOrderApp();
    aifecOrderPayment($app)->action = PaymentAction::paid('SIM-1');
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 200]);
    $result = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);

    same(true, $result['ok'], (string) $result['error']);
    same('paid', $result['action']->type);
    $order = $app->service('orders')->find((int) $result['id']);
    same('paid', $order['payment_status']);
    same('processing', $order['status']);
    same('SIM-1', $order['payments'][0]['reference']);
    same(true, $order['test']);
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
});

test('con la acción «instructions» el pedido queda en espera y el correo lleva las instrucciones de pago', function (): void {
    $app = aifecOrderApp();
    aifecOrderPayment($app)->action = PaymentAction::instructions('<p>Transfiere a ES00 1234 con el concepto del pedido</p>');
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 200]);
    $result = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 1]]);
    $orders = $app->service('orders');

    same(true, $result['ok'], (string) $result['error']);
    same('instructions', $result['action']->type);
    $order = $orders->find((int) $result['id']);
    same('on_hold', $order['status']);
    same('pending', $order['payment_status']);
    $mails = aifecOrderMails($app, 'order_on_hold');
    same(1, count($mails));
    same('ana@cliente.test', $mails[0]['to_email']);
    contains('<p>Transfiere a ES00 1234 con el concepto del pedido</p>', (string) $mails[0]['html']);
    contains('Cómo pagar', (string) $mails[0]['html']);
    same(0, count(aifecOrderMails($app, 'order_confirmed')));

    // Cuando el comerciante confirma la transferencia, el pedido se prepara.
    same(true, $orders->markPaid((int) $result['id'], 'transfer', 'TRF-1', (int) $order['total']));
    same('processing', $orders->find((int) $result['id'])['status']);
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
});

test('con la acción «failed» el pedido queda fallido, las existencias vuelven y el carrito se puede reabrir', function (): void {
    $app = aifecOrderApp();
    aifecOrderPayment($app)->action = PaymentAction::failed('Tarjeta rechazada');
    couponsMake($app, ['code' => 'OTONO']);
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 200]);
    $web = new TestClient($app);
    $result = aifecCartRun($web, static function ($cart, App $app) use ($p): array {
        $cart->add($p, 0, 3);
        $cart->applyCoupon('OTONO');
        $cart->setPaymentMethod('gw');

        return $app->service('orders')->place(aifecOrderCheckout());
    });

    same(false, $result['ok']);
    same('Tarjeta rechazada', $result['error']);
    same('failed', $result['action']->type);
    $id = (int) $result['id'];
    check($id > 0 && $result['secret'] !== '', 'el resultado trae el pedido y su ficha para reintentar');
    $order = $app->service('orders')->find($id);
    same('failed', $order['status']);
    same('failed', $order['payment_status']);
    same(10, aifecOrderStock($app, $p));
    same(0, aifecOrderCount($app, 'coupon_uses'));
    same(0, (int) $app->db()->value("SELECT uses FROM {coupons} WHERE code = 'OTONO'"));
    same($id, (int) $app->db()->value('SELECT order_id FROM {carts}'));
    same(0, aifecOrderCount($app, 'order_payments', "status = 'ok'"));
    same(1, aifecOrderCount($app, 'order_payments', "status = 'failed'"));
    contains('Tarjeta rechazada', implode(' | ', array_column($order['history'], 'note')));

    // El cliente vuelve a la caja: el carrito vuelve a ser suyo, con sus tres unidades.
    same(true, aifecCartRun($web, static function ($cart) use ($id, $result): bool {
        return $cart->reopen($id, (string) $result['secret']);
    }));
    same(3, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }));
});

test('si start() lanza, el pedido falla con el motivo y las existencias vuelven una sola vez aunque se avise dos veces', function (): void {
    $app = aifecOrderApp();
    aifecOrderPayment($app)->throwOnStart = true;
    $p = aifecProduct($app, ['stock' => 10, 'weight' => 200]);
    $result = aifecOrderPlace($app, new TestClient($app), [[$p, 0, 3]]);
    $orders = $app->service('orders');

    same(false, $result['ok']);
    contains('No se ha podido empezar el pago', $result['error']);
    $id = (int) $result['id'];
    same('failed', $orders->find($id)['status']);
    same(10, aifecOrderStock($app, $p));
    contains('The gateway is not reachable', implode(' | ', array_column($orders->find($id)['history'], 'note')));

    $orders->paymentFailed($id, 'gw', 'Otra vez');
    $orders->paymentFailed($id, 'gw', 'Y otra');
    same(10, aifecOrderStock($app, $p));
    same(1, aifecOrderCount($app, 'stock_moves', "reason = 'Payment failed'"));
    same(1, aifecOrderCount($app, 'order_payments', "order_id = ? AND status = 'failed'", [$id]));
});

test('un cobro fallido de un pedido ya pagado no hace nada', function (): void {
    list($app, $p, $placed) = orderPaymentUnpaid(2);
    $orders = $app->service('orders');
    $id = (int) $placed['id'];
    $orders->markPaid($id, 'gw', 'PAY-1', (int) $orders->find($id)['total']);

    $orders->paymentFailed($id, 'gw', 'Un aviso tardío');
    $order = $orders->find($id);
    same('processing', $order['status']);
    same('paid', $order['payment_status']);
    same(8, aifecOrderStock($app, $p));
});

test('el cobro de un pedido cancelado lo deja en espera con una nota visible y vuelve a descontar las existencias', function (): void {
    list($app, $p, $placed) = orderPaymentUnpaid(2);
    $orders = $app->service('orders');
    $id = (int) $placed['id'];
    $total = (int) $orders->find($id)['total'];
    same(true, $orders->cancel($id, 'El cliente no pagaba'));
    same(10, aifecOrderStock($app, $p));

    same(false, $orders->markPaid($id, 'gw', 'LATE-1', $total));
    same(false, $orders->markPaid($id, 'gw', 'LATE-1', $total));
    $order = $orders->find($id);
    same('on_hold', $order['status']);
    same('paid', $order['payment_status']);
    same($total, $order['paid_total']);
    same(null, $order['cancelled_at']);
    same(8, aifecOrderStock($app, $p));
    $notes = array_values(array_filter($order['history'], static function (array $h): bool {
        return $h['to_value'] === 'late_payment';
    }));
    same(1, count($notes));
    contains('PAGO TARDÍO', (string) $notes[0]['note']);
    contains('cancelled', (string) $notes[0]['note']);
    same(1, aifecOrderCount($app, 'order_payments', "order_id = ? AND kind = 'charge'", [$id]));
    same(0, count(aifecOrderMails($app, 'order_confirmed')));
});

test('si no quedan existencias cuando llega el cobro tardío, la nota lo dice y no se descuenta nada a ciegas', function (): void {
    list($app, $p, $placed) = orderPaymentUnpaid(2);
    $orders = $app->service('orders');
    $id = (int) $placed['id'];
    $total = (int) $orders->find($id)['total'];
    $orders->paymentFailed($id, 'gw', 'Rechazado');
    $app->db()->run('UPDATE {products} SET stock = 1 WHERE id = ?', [$p]);

    same(false, $orders->markPaid($id, 'gw', 'LATE-2', $total));
    $order = $orders->find($id);
    same('on_hold', $order['status']);
    same(1, aifecOrderStock($app, $p));
    $notes = array_values(array_filter($order['history'], static function (array $h): bool {
        return $h['to_value'] === 'late_payment';
    }));
    contains('Camiseta', (string) $notes[0]['note']);
    contains('ya no quedan existencias', (string) $notes[0]['note']);
    same(['Camiseta'], $notes[0]['data']['missing']);
});

test('en modo silencioso el cobro se apunta pero no se manda ningún correo', function (): void {
    list($app, $p, $placed) = orderPaymentUnpaid(1);
    $orders = $app->service('orders');
    $id = (int) $placed['id'];
    $before = aifecOrderCount($app, 'mail_messages');

    $previous = $app->silence(true);
    try {
        same(true, $orders->markPaid($id, 'gw', 'PAY-1', (int) $orders->find($id)['total']));
    } finally {
        $app->silence($previous);
    }
    same('paid', $orders->find($id)['payment_status']);
    same($before, aifecOrderCount($app, 'mail_messages'));
});
