<?php
/**
 * AIFEC — pruebas de la vuelta del cliente desde PayPal: la captura en el servidor, que no se cobre dos veces, el «token» que tiene
 * que ser el del pedido, el importe o la moneda distintos, los rechazos, los fallos pasajeros y la cancelación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\HttpException;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\PaymentsPaypal\PayPalPayment;

require_once __DIR__ . '/PayPalKit.php';

test('la vuelta captura el pago en el servidor, da el pedido por pagado una vez y lleva a la página de gracias', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    $order = ppOrderRow($app, $placed);
    $web = new TestClient($app);

    $r = ppBack($web, $placed);
    same(302, $r->status);
    same('/pedido/' . $placed['number'] . '/' . $placed['secret'], $r->headers['Location']);

    $captures = ppCalls($fake, 'POST', '/v2/checkout/orders/' . $paypalOrder . '/capture');
    same(1, count($captures), 'una captura');
    same('Bearer ' . PP_TOKEN, $captures[0]->headers['Authorization']);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same('processing', $order['status']);
    same($order['total'], $order['paid_total']);
    same(true, $order['test'], 'el sandbox deja el pedido marcado como de pruebas');
    $charges = ppPayments($order, 'charge');
    same(1, count($charges), 'un cobro');
    same(['paypal', 'CAP-' . $paypalOrder, 'ok', (int) $order['total'], true], [$charges[0]['method'], $charges[0]['reference'], $charges[0]['status'], $charges[0]['amount'], $charges[0]['test']], 'la referencia es la captura');
    same('CAP-' . $paypalOrder, $charges[0]['raw']['capture']);
    notContains('comprador@example.test', json_encode($order['payments']), 'no se guarda el correo del comprador');
    same('captured', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
    same(9, aifecOrderStock($app, $p), 'las existencias siguen descontadas');
});

test('abrir la vuelta dos veces no captura dos veces ni cobra dos veces', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    $paidEvents = 0;
    $app->hooks()->on('order.paid', static function (array $order) use (&$paidEvents): void {
        ++$paidEvents;
    });
    $web = new TestClient($app);

    same(302, ppBack($web, $placed)->status);
    $again = ppBack($web, $placed);
    same(302, $again->status);
    same('/pedido/' . $placed['number'] . '/' . $placed['secret'], $again->headers['Location'], 'la segunda también lleva a las gracias');
    ppBack($web, $placed);

    same(1, count(ppCalls($fake, 'POST', '/capture')), 'PayPal recibe una sola captura');
    $order = ppOrderRow($app, $placed);
    same(1, count(ppPayments($order, 'charge')), 'un solo cobro');
    same(1, $paidEvents, 'un solo aviso de pedido pagado');
    same(1, count(aifecOrderMails($app, 'order_confirmed')), 'un solo correo');
});

test('un «token» que no es la orden apuntada para ese pedido da 404 y no captura nada', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $other = ppPlace($app, new TestClient($app), $p);
    $otherOrder = ppAttempt($app, $other)['reference'];
    check($otherOrder !== $paypalOrder, 'cada pedido tiene su orden de PayPal');
    $web = new TestClient($app);
    $url = '/pago/paypal/vuelta/' . $placed['number'] . '/' . $placed['secret'];

    same(404, ppBack($web, $placed, $otherOrder)->status, 'la orden de otro pedido');
    same(404, ppBack($web, $placed, 'PPORDER-INVENTADA')->status, 'una orden que no existe');
    same(404, $web->get($url)->status, 'sin «token»');
    same(404, $web->get($url . '?token=')->status, 'con el «token» vacío');
    same(404, $web->get($url . '?token[]=' . $paypalOrder)->status, 'con el «token» como lista');
    same(0, count(ppCalls($fake, 'POST', '/capture')), 'no se ha capturado nada');
    same('pending', ppOrderRow($app, $placed)['payment_status']);
    same('pending', ppOrderRow($app, $other)['payment_status']);
});

test('la vuelta de un pedido ajeno, con una ficha mala o de otra forma de pago da 404', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $app->service('payments')->register(new AifecOrderPayment('gw'));
    $gw = ppPlace($app, new TestClient($app), $p);
    $web = new TestClient($app);

    same(404, $web->get('/pago/paypal/vuelta/' . $placed['number'] . '/ficha-de-mentira?token=' . $paypalOrder)->status, 'una ficha mala');
    same(404, $web->get('/pago/paypal/vuelta/9999-999999/' . $placed['secret'] . '?token=' . $paypalOrder)->status, 'un número que no existe');
    same(404, $web->get('/pago/paypal/cancelado/' . $placed['number'] . '/ficha-de-mentira')->status, 'la cancelación con ficha mala');

    $app->db()->update('orders', ['payment_method' => 'gw'], ['id' => (int) $gw['id']]);
    same(404, $web->get('/pago/paypal/vuelta/' . $gw['number'] . '/' . $gw['secret'] . '?token=' . $paypalOrder)->status, 'un pedido de otra forma de pago');
    same(404, $web->get('/pago/paypal/cancelado/' . $gw['number'] . '/' . $gw['secret'])->status);
    same(0, count(ppCalls($fake, 'POST', '/capture')));
});

test('un importe capturado distinto al del pedido lo deja en espera y no lo da por pagado', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    $order = ppOrderRow($app, $placed);
    $fake->captureResponse = ppJson(201, ppCapturedOrder($paypalOrder, '0.01', 'EUR', (string) $order['number']));

    same(302, ppBack(new TestClient($app), $placed)->status);
    $order = ppOrderRow($app, $placed);
    same('on_hold', $order['status']);
    same('pending', $order['payment_status'], 'no está pagado');
    same(0, (int) $order['paid_total']);
    same(0, count(aifecOrderMails($app, 'order_confirmed')), 'ni confirmación al cliente');
    $notes = array_filter($order['history'], static function (array $h): bool {
        return (string) $h['kind'] === 'payment' && (string) $h['to_value'] === 'amount_mismatch';
    });
    same(1, count($notes), 'una nota avisa a quien lleva la tienda');
});

test('una moneda capturada distinta a la del pedido también lo deja en espera, con una nota que el cliente no ve', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    $order = ppOrderRow($app, $placed);
    $fake->captureResponse = ppJson(201, ppCapturedOrder($paypalOrder, \Aifec\Commerce\Money::decimal((int) $order['total']), 'USD', (string) $order['number']));

    ppBack(new TestClient($app), $placed);
    $order = ppOrderRow($app, $placed);
    same('on_hold', $order['status']);
    same('pending', $order['payment_status']);
    same([], ppPayments($order, 'charge'));
    $notes = array_values(array_filter($order['history'], static function (array $h): bool {
        return strpos((string) $h['note'], 'otra moneda (USD)') !== false;
    }));
    same(1, count($notes));
    same(false, $notes[0]['is_public'], 'la nota es solo para la tienda');
});

test('una captura rechazada deja el pedido fallido, reabre el carrito y lleva a la caja con un aviso; la segunda vuelta no vuelve a capturar', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    $fake->captureResponse = ppJson(201, ppCapturedOrder($paypalOrder, \Aifec\Commerce\Money::decimal((int) $order['total']), 'EUR', (string) $order['number'], 'DECLINED'));
    $web = new TestClient($app);
    same(1, aifecOrderCount($app, 'carts', 'order_id IS NOT NULL'), 'el carrito está ligado al pedido');

    $r = ppBack($web, $placed);
    same(302, $r->status);
    same('/finalizar-compra', $r->headers['Location']);
    $order = ppOrderRow($app, $placed);
    same('failed', $order['status']);
    same('failed', $order['payment_status']);
    same(0, aifecOrderCount($app, 'carts', 'order_id IS NOT NULL'), 'el carrito vuelve a estar libre');
    contains('El pago no se ha completado. Puedes intentarlo de nuevo.', $web->follow()->body);
    same(10, aifecOrderStock($app, $p), 'las existencias se reponen');
    same('failed', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);

    $again = ppBack($web, $placed);
    same(302, $again->status);
    same('/finalizar-compra', $again->headers['Location']);
    same(1, count(ppCalls($fake, 'POST', '/capture')), 'no se captura otra vez');
});

test('un «no» de PayPal al capturar (instrumento rechazado) también deja el pedido fallido y el carrito libre', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $fake->captureResponse = ppJson(422, ['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed', 'debug_id' => 'dbg-ic', 'details' => [['issue' => 'INSTRUMENT_DECLINED']]]);

    $r = ppBack(new TestClient($app), $placed);
    same('/finalizar-compra', $r->headers['Location']);
    $order = ppOrderRow($app, $placed);
    same('failed', $order['payment_status']);
    same(0, aifecOrderCount($app, 'carts', 'order_id IS NOT NULL'));
    $failed = array_filter($order['history'], static function (array $h): bool {
        return strpos((string) $h['note'], 'INSTRUMENT_DECLINED') !== false;
    });
    check(count($failed) === 1, 'el motivo queda en el historial');
});

test('una captura pendiente de revisión deja el pedido en espera con una nota, y el aviso de captura completada lo da por pagado', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    $order = ppOrderRow($app, $placed);
    $fake->captureResponse = ppJson(201, ppCapturedOrder($paypalOrder, \Aifec\Commerce\Money::decimal((int) $order['total']), 'EUR', (string) $order['number'], 'PENDING'));
    $web = new TestClient($app);

    same(302, ppBack($web, $placed)->status);
    $order = ppOrderRow($app, $placed);
    same('on_hold', $order['status']);
    same('pending', $order['payment_status']);
    same('review', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    $notes = array_filter($order['history'], static function (array $h): bool {
        return strpos((string) $h['note'], 'PayPal está revisando el pago') !== false;
    });
    check(count($notes) === 1, 'una nota explica la espera');
    ppBack($web, $placed);
    same(1, count(ppCalls($fake, 'POST', '/capture')), 'recargar no vuelve a capturar');

    same('OK', ppNotify($app, $fake, ppCompleted($order, $paypalOrder, 'CAP-' . $paypalOrder))->body);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same('processing', $order['status'], 'sale de la espera');
    same(1, count(ppPayments($order, 'charge')));
    same('captured', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
});

test('un fallo pasajero (red caída) al capturar da un 503 y deja el intento libre: al recargar se captura y se cobra una vez', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    $fake->captureResponse = new HttpException('cURL error 28: timed out with ' . PP_SECRET);
    $web = new TestClient($app);

    $r = ppBack($web, $placed);
    same(503, $r->status);
    notContains(PP_SECRET, $r->body);
    notContains(PP_SECRET, json_encode($app->log()->records, JSON_UNESCAPED_UNICODE));
    $order = ppOrderRow($app, $placed);
    same('pending', $order['payment_status'], 'sigue sin pagar');
    same('pending_payment', $order['status'], 'y no se ha dado por fallido');
    same('pending', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status'], 'el intento queda libre');

    $fake->captureResponse = null;
    same(302, ppBack($web, $placed)->status);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same(1, count(ppPayments($order, 'charge')));
});

test('cancelar en PayPal reabre el carrito, lleva a la caja con un aviso y no toca el pago; esa orden ya no se captura', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    $web = new TestClient($app);
    same(1, aifecOrderCount($app, 'carts', 'order_id IS NOT NULL'));

    $r = $web->get('/pago/paypal/cancelado/' . $placed['number'] . '/' . $placed['secret']);
    same(302, $r->status);
    same('/finalizar-compra', $r->headers['Location']);
    same(0, aifecOrderCount($app, 'carts', 'order_id IS NOT NULL'), 'el carrito vuelve a estar libre');
    contains('El pago no se ha completado. Puedes intentarlo de nuevo.', $web->follow()->body);
    $order = ppOrderRow($app, $placed);
    same('pending', $order['payment_status'], 'cancelar no cobra ni rechaza nada');
    same('cancelled', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);

    // Si el cliente vuelve atrás y abre la dirección de vuelta de esa orden, no se captura.
    $back = ppBack($web, $placed);
    same(302, $back->status);
    same('/finalizar-compra', $back->headers['Location']);
    same(0, count(ppCalls($fake, 'POST', '/capture')), 'no se captura una orden cancelada');
});

test('cancelar un pedido que PayPal ya había cobrado lleva a la página de gracias y no toca el carrito', function (): void {
    list($app, $fake, $placed, $id) = ppPaidOrder();
    ppThanksRoute($app);

    $r = (new TestClient($app))->get('/pago/paypal/cancelado/' . $placed['number'] . '/' . $placed['secret']);
    same(302, $r->status);
    same('/pedido/' . $placed['number'] . '/' . $placed['secret'], $r->headers['Location']);
    same('paid', ppOrderRow($app, $placed)['payment_status']);
});
