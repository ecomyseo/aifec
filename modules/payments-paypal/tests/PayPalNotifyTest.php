<?php
/**
 * AIFEC — pruebas del aviso de PayPal: solo un aviso verificado cambia algo; una captura completada da el pedido por pagado
 * aunque el cliente no vuelva, y convive con la vuelta sin cobrar dos veces; una denegada lo deja fallido; una devolución hecha
 * desde PayPal se apunta como nota; el resto de eventos no hace nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\PaymentsPaypal\PayPalPayment;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;

require_once __DIR__ . '/PayPalKit.php';

test('un aviso verificado de captura completada, sin que el cliente vuelva, da el pedido por pagado y contesta OK', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);

    $r = ppNotify($app, $fake, ppCompleted($order, $paypalOrder));
    same(200, $r->status);
    same('OK', $r->body);
    notContains('no-cache', (string) ($r->headers['Cache-Control'] ?? ''));

    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same('processing', $order['status']);
    same($order['total'], $order['paid_total']);
    same(true, $order['test']);
    $charges = ppPayments($order, 'charge');
    same(1, count($charges));
    same(['paypal', PP_CAPTURE, 'ok', (int) $order['total']], [$charges[0]['method'], $charges[0]['reference'], $charges[0]['status'], $charges[0]['amount']]);
    same('captured', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
    same(0, count(ppCalls($fake, 'POST', '/capture')), 'el servidor no ha capturado nada: lo hizo PayPal');
});

test('el aviso se verifica con la API de PayPal: el identificador del aviso del entorno, las cabeceras de firma y el cuerpo tal cual', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    ppNotify($app, $fake, ppCompleted($order, $paypalOrder));

    $calls = ppCalls($fake, 'POST', '/v1/notifications/verify-webhook-signature');
    same(1, count($calls));
    $sent = json_decode((string) $calls[0]->body, true);
    same(PP_WEBHOOK, $sent['webhook_id']);
    same('f7d3a1b0-0000-0000-0000-000000000001', $sent['transmission_id']);
    same('firma-de-mentira', $sent['transmission_sig']);
    same('WH-EVT-' . PP_CAPTURE, $sent['webhook_event']['id'], 'el aviso va dentro tal como llegó');
    same('Bearer ' . PP_TOKEN, $calls[0]->headers['Authorization']);
});

test('después del aviso llega la vuelta del cliente: sigue habiendo un solo cobro y un solo correo, y no se captura de nuevo', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    $paidEvents = 0;
    $app->hooks()->on('order.paid', static function (array $order) use (&$paidEvents): void {
        ++$paidEvents;
    });
    $order = ppOrderRow($app, $placed);
    same('OK', ppNotify($app, $fake, ppCompleted($order, $paypalOrder, 'CAP-' . $paypalOrder))->body);

    $r = ppBack(new TestClient($app), $placed);
    same(302, $r->status);
    same('/pedido/' . $placed['number'] . '/' . $placed['secret'], $r->headers['Location']);
    same(0, count(ppCalls($fake, 'POST', '/capture')), 'ya pagado: no se captura');
    $order = ppOrderRow($app, $placed);
    same(1, count(ppPayments($order, 'charge')), 'un solo cobro');
    same(1, count(aifecOrderMails($app, 'order_confirmed')), 'un solo correo');
    same(1, $paidEvents, 'un solo aviso de pedido pagado');
});

test('primero la vuelta y después el aviso (con la misma captura): un solo cobro, un solo correo, y el aviso repetido tampoco suma', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    $paidEvents = 0;
    $app->hooks()->on('order.paid', static function (array $order) use (&$paidEvents): void {
        ++$paidEvents;
    });
    ppBack(new TestClient($app), $placed);
    $order = ppOrderRow($app, $placed);
    $event = ppCompleted($order, $paypalOrder, 'CAP-' . $paypalOrder);

    same('OK', ppNotify($app, $fake, $event)->body);
    same('OK', ppNotify($app, $fake, $event)->body);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same(1, count(ppPayments($order, 'charge')), 'un solo cobro');
    same(1, count(aifecOrderMails($app, 'order_confirmed')), 'un solo correo');
    same(1, $paidEvents);
    same(1, aifecOrderCount($app, 'order_payments', "status = 'ok'"));
});

test('un aviso que PayPal no confirma da 400 y no cambia nada, tampoco sin cabeceras de firma', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    $event = ppCompleted($order, $paypalOrder);
    $before = aifecOrderSnapshot($app);

    $r = ppNotify($app, $fake, $event, false);
    same(400, $r->status);
    notContains('OK', $r->body);
    same(400, ppNotify($app, $fake, $event, true, false)->status, 'sin las cabeceras de firma PayPal ni se consulta');
    $bad = $event;
    $bad['event_type'] = 'PAYMENT.CAPTURE.DENIED';
    same(400, ppNotify($app, $fake, $bad, false)->status, 'una denegación falsa tampoco');
    same($before, aifecOrderSnapshot($app), 'ninguna tabla ha cambiado');
    same('pending', ppOrderRow($app, $placed)['payment_status']);
    same('pending_payment', ppOrderRow($app, $placed)['status']);

    // Con cuerpo que no es JSON: la verificación lo rechaza antes de nada.
    $request = Request::create('POST', '/pago/paypal/aviso', [], [], ['paypal-auth-algo' => 'a', 'paypal-cert-url' => 'b', 'paypal-transmission-id' => 'c', 'paypal-transmission-sig' => 'd', 'paypal-transmission-time' => 'e'], 'no es json');
    $request->host = 'tienda.test';
    $request->secure = true;
    same(400, $app->handle($request)->status);
    same($before, aifecOrderSnapshot($app));
});

test('sin identificador del aviso en los ajustes nadie puede dar un pedido por pagado', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    $app->settings()->set(PayPalSettings::WEBHOOK_ID_SANDBOX, '');
    $verifications = count(ppCalls($fake, 'POST', '/verify-webhook-signature'));

    same(400, ppNotify($app, $fake, ppCompleted($order, $paypalOrder))->status);
    same($verifications, count(ppCalls($fake, 'POST', '/verify-webhook-signature')), 'ni siquiera se llama a PayPal');
    same('pending', ppOrderRow($app, $placed)['payment_status']);
});

test('si PayPal falla al verificar contesta 500 para que reintente, y lo apunta sin secretos ni datos del aviso', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    $event = ppCompleted($order, $paypalOrder);
    // Un aviso con las cabeceras de firma y la verificación de PayPal contestando 503.
    $fake->verifyResponse = ppJson(503, ['name' => 'SERVICE_UNAVAILABLE', 'message' => 'Try later ' . PP_SECRET, 'debug_id' => 'dbg-503']);
    $headers = [
        'user-agent' => 'PayPal/AUHD-214', 'content-type' => 'application/json', 'paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://x.test/c',
        'paypal-transmission-id' => 'id-1', 'paypal-transmission-sig' => 'firma-1', 'paypal-transmission-time' => '2026-11-12T10:30:00Z',
    ];
    $request = Request::create('POST', '/pago/paypal/aviso', [], [], $headers, (string) json_encode($event));
    $request->host = 'tienda.test';
    $request->secure = true;

    $r = $app->handle($request);
    same(500, $r->status);
    notContains('OK', $r->body);
    same('pending', ppOrderRow($app, $placed)['payment_status']);
    $logged = json_encode($app->log()->records, JSON_UNESCAPED_UNICODE);
    contains('paypal.notify', $logged, 'el fallo queda apuntado');
    notContains(PP_SECRET, $logged);
    notContains(PP_TOKEN, $logged);
    notContains('firma-1', $logged, 'ni la firma del aviso');
    notContains((string) $order['email'], $logged, 'ni el correo del cliente');
});

test('el aviso no necesita sesión ni ficha de formulario: lo manda el servidor de PayPal', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $match = $app->router()->match('POST', '/pago/paypal/aviso');

    check($match !== null, 'la ruta existe');
    same('payment.paypal.notify', $match['route']->name);
    same([], $match['route']->middleware, 'sin el intermediario csrf');
    throws(function () use ($app): void {
        $app->router()->match('GET', '/pago/paypal/aviso');
    }, \Aifec\Kernel\HttpError::class, 'solo se acepta POST');
});

test('una captura denegada deja el pedido fallido si aún esperaba el pago, y no toca uno que ya está pagado', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    $event = ppCompleted($order, $paypalOrder);
    $event['event_type'] = 'PAYMENT.CAPTURE.DENIED';
    $event['resource']['status'] = 'DECLINED';

    same('OK', ppNotify($app, $fake, $event)->body);
    $order = ppOrderRow($app, $placed);
    same('failed', $order['status']);
    same('failed', $order['payment_status']);
    same('failed', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    same(10, aifecOrderStock($app, $p), 'las existencias se reponen');
    same('OK', ppNotify($app, $fake, $event)->body, 'repetido, no pasa nada más');

    list($app2, $fake2, $placed2, $id2) = ppPaidOrder();
    $paid = $app2->service('orders')->find($id2);
    $deny = ppCompleted($paid, ppAttempt($app2, $placed2)['reference'], 'OTRA-CAPTURA');
    $deny['event_type'] = 'PAYMENT.CAPTURE.DENIED';
    same('OK', ppNotify($app2, $fake2, $deny)->body);
    same('paid', $app2->service('orders')->find($id2)['payment_status'], 'un pedido pagado no se estropea');
});

/**
 * El evento PAYMENT.CAPTURE.REFUNDED de una devolución de PayPal sobre la captura PP_CAPTURE.
 *
 * @param array<string,mixed> $order
 *
 * @return array<string,mixed>
 */
function ppRefundedEvent(array $order, string $refundId, string $importe, bool $enlace = true): array
{
    $resource = ['id' => $refundId, 'status' => 'COMPLETED', 'amount' => ['currency_code' => (string) $order['currency'], 'value' => $importe]];
    if ($enlace) {
        $resource['links'] = [
            ['href' => 'https://api.sandbox.paypal.com/v2/payments/refunds/' . $refundId, 'rel' => 'self', 'method' => 'GET'],
            ['href' => 'https://api.sandbox.paypal.com/v2/payments/captures/' . PP_CAPTURE, 'rel' => 'up', 'method' => 'GET'],
        ];
    } else {
        $resource['custom_id'] = (string) $order['number'];
    }

    return ['id' => 'WH-EVT-' . $refundId, 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource_type' => 'refund', 'resource' => $resource];
}

test('una devolución hecha desde PayPal se apunta como nota en el historial, una sola vez, y no se devuelve dinero otra vez', function (): void {
    list($app, $fake, $placed, $id) = ppPaidOrder();
    $order = $app->service('orders')->find($id);
    $event = ppRefundedEvent($order, 'REFUND-DESDE-PAYPAL-1', '5.00');
    $before = [(int) $order['refunded_total'], aifecOrderCount($app, 'order_refunds'), aifecOrderCount($app, 'order_payments', "kind = 'refund'")];

    same('OK', ppNotify($app, $fake, $event)->body);
    same('OK', ppNotify($app, $fake, $event)->body, 'el mismo aviso otra vez');
    $order = $app->service('orders')->find($id);
    $notes = array_values(array_filter($order['history'], static function (array $h): bool {
        return strpos((string) $h['note'], 'REFUND-DESDE-PAYPAL-1') !== false;
    }));
    same(1, count($notes), 'una sola nota');
    contains('5,00', $notes[0]['note']);
    contains('desde PayPal', $notes[0]['note']);
    same(false, $notes[0]['is_public'], 'la nota es solo para la tienda');
    same($before, [(int) $order['refunded_total'], aifecOrderCount($app, 'order_refunds'), aifecOrderCount($app, 'order_payments', "kind = 'refund'")], 'la tienda no devuelve nada por su cuenta');
    same('paid', $order['payment_status']);
    same(0, count(ppCalls($fake, 'POST', '/refund')), 'y no se llama a la API de devoluciones');

    // Sin el enlace a la captura, el pedido se encuentra por su referencia propia.
    $second = ppRefundedEvent($order, 'REFUND-DESDE-PAYPAL-2', '1.00', false);
    same('OK', ppNotify($app, $fake, $second)->body);
    $notes2 = array_filter($app->service('orders')->find($id)['history'], static function (array $h): bool {
        return strpos((string) $h['note'], 'REFUND-DESDE-PAYPAL-2') !== false;
    });
    same(1, count($notes2));
});

test('una devolución hecha desde la tienda (ya apuntada con su identificador) no genera nota cuando PayPal avisa', function (): void {
    list($app, $fake, $placed, $id) = ppPaidOrder();
    $refundId = $app->service('orders')->refund($id, 500, [], 'Camiseta rota', true, false);
    check($refundId > 0, 'la devolución se apunta');
    $order = $app->service('orders')->find($id);
    $mine = ppPayments($order, 'refund')[0]['reference'];
    $notesBefore = count($order['history']);

    same('OK', ppNotify($app, $fake, ppRefundedEvent($order, $mine, '5.00'))->body);
    same($notesBefore, count($app->service('orders')->find($id)['history']), 'el historial no cambia');
});

test('los demás eventos y los avisos de pedidos que no son de esta tienda contestan OK sin hacer nada', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    $before = aifecOrderSnapshot($app);

    foreach (['PAYMENT.CAPTURE.PENDING', 'CHECKOUT.ORDER.APPROVED', 'BILLING.SUBSCRIPTION.CREATED', 'ALGO.QUE.NO.EXISTE'] as $type) {
        $event = ppCompleted($order, $paypalOrder);
        $event['event_type'] = $type;
        $r = ppNotify($app, $fake, $event);
        same(200, $r->status, $type);
        same('OK', $r->body);
    }
    same($before, aifecOrderSnapshot($app));
    same('pending', ppOrderRow($app, $placed)['payment_status']);

    $unknown = ppCompleted($order, 'PPORDER-DE-OTRA-TIENDA');
    $unknown['resource']['custom_id'] = '9999-999999';
    $unknown['resource']['invoice_id'] = '9999-999999';
    same('OK', ppNotify($app, $fake, $unknown)->body, 'una captura que no es de ningún pedido nuestro');
    $unknown['event_type'] = 'PAYMENT.CAPTURE.DENIED';
    same('OK', ppNotify($app, $fake, $unknown)->body);
    $unknown['event_type'] = 'PAYMENT.CAPTURE.REFUNDED';
    same('OK', ppNotify($app, $fake, $unknown)->body);
    same($before, aifecOrderSnapshot($app));
});

test('un aviso de captura completada con otro importe o con otra moneda deja el pedido en espera y no lo da por pagado', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    $low = ppCompleted($order, $paypalOrder);
    $low['resource']['amount']['value'] = '0.01';

    same('OK', ppNotify($app, $fake, $low)->body);
    $row = ppOrderRow($app, $placed);
    same('on_hold', $row['status']);
    same('pending', $row['payment_status']);

    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    $usd = ppCompleted($order, $paypalOrder);
    $usd['resource']['amount']['currency_code'] = 'USD';
    same('OK', ppNotify($app, $fake, $usd)->body);
    $row = ppOrderRow($app, $placed);
    same('on_hold', $row['status']);
    same('pending', $row['payment_status']);
    same([], ppPayments($row, 'charge'));
});
