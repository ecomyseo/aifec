<?php
/**
 * AIFEC — pruebas del cobro con las formas de pago de PayPal que no son la cartera: la orden que se crea (payment_source,
 * vuelta, importe y moneda), la redirección al banco, la vuelta que consulta en vez de capturar, el pago que el banco confirma
 * tarde, el aviso (que paga una vez, aunque llegue dos), el «no» de PayPal explicado en el historial, la tarjeta en la página de
 * PayPal y las devoluciones, también con todo lo nuevo apagado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Money;
use Aifec\Commerce\PaymentAction;
use Aifec\Http\HttpException;
use Aifec\Modules\PaymentsPaypal\PayPalPayment;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;

require_once __DIR__ . '/PayPalKit.php';

/**
 * La orden que se mandó a PayPal al crear el pedido.
 *
 * @return array<string,mixed>
 */
function pplfCreatedBody(PpFake $fake): array
{
    $calls = ppCalls($fake, 'POST', '/v2/checkout/orders');
    $creates = array_values(array_filter($calls, static function ($r): bool {
        return substr($r->url, -strlen('/v2/checkout/orders')) === '/v2/checkout/orders';
    }));
    check($creates !== [], 'se ha creado una orden en PayPal');

    return ppBody($creates[count($creates) - 1]);
}

/** El evento PAYMENT.CAPTURE.COMPLETED de la orden de PayPal de un pedido (captura «CAP-<orden>»). */
function pplfCompleted(array $order, string $paypalOrder): array
{
    return ppCompleted($order, $paypalOrder, 'CAP-' . $paypalOrder);
}

test('iDEAL: la orden lleva payment_source.ideal con el titular y el país de la factura, cobrar al aprobar, la vuelta y el importe', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('ideal', 'NL');
    $b = pplfCreatedBody($fake);

    same(['ideal'], array_keys($b['payment_source']));
    same('Ana Ruiz', $b['payment_source']['ideal']['name']);
    same('NL', $b['payment_source']['ideal']['country_code']);
    same('ORDER_COMPLETE_ON_PAYMENT_APPROVAL', $b['processing_instruction']);
    $ctx = $b['payment_source']['ideal']['experience_context'];
    same('https://tienda.test/pago/paypal/vuelta/' . $placed['number'] . '/' . $placed['secret'], $ctx['return_url']);
    same('https://tienda.test/pago/paypal/cancelado/' . $placed['number'] . '/' . $placed['secret'], $ctx['cancel_url']);
    same('NO_SHIPPING', $ctx['shipping_preference'], 'un producto digital no se envía');
    same('EUR', $b['purchase_units'][0]['amount']['currency_code']);
    same(Money::decimal((int) ppOrderRow($app, $placed)['total']), $b['purchase_units'][0]['amount']['value']);
    same((string) $placed['number'], $b['purchase_units'][0]['custom_id']);

    same('https://www.sandbox.paypal.com/payment/ideal?token=' . $paypalOrder, $placed['action']->url, 'el comprador va a la página que dice PayPal');
    $attempt = ppAttempt($app, $placed);
    same(['paypal_ideal', 'pending', $paypalOrder], [$attempt['method'], $attempt['status'], $attempt['reference']], 'el intento lleva la forma de pago concreta');
    $order = ppOrderRow($app, $placed);
    same('paypal_ideal', $order['payment_method']);
    same('iDEAL por PayPal', $order['payment_title'], 'el pedido dice con qué se paga');
});

test('Trustly y Przelewy24 mandan además el correo del pedido, que exigen', function (): void {
    list($app, $fake) = ppLocalPlaced('trustly', 'ES');
    $node = pplfCreatedBody($fake)['payment_source']['trustly'];
    same('ana@cliente.test', $node['email']);
    same('ES', $node['country_code']);

    list($app2, $fake2) = ppLocalPlaced('p24', 'PL', ['shop.currency' => 'PLN']);
    $b = pplfCreatedBody($fake2);
    same('ana@cliente.test', $b['payment_source']['p24']['email']);
    same('PLN', $b['purchase_units'][0]['amount']['currency_code'], 'el importe en la moneda del pedido');
});

test('BLIK en una tienda en złotys: su payment_source y la redirección a la página de BLIK', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('blik', 'PL', ['shop.currency' => 'PLN']);
    $b = pplfCreatedBody($fake);
    same(['blik'], array_keys($b['payment_source']));
    same('PL', $b['payment_source']['blik']['country_code']);
    same('PLN', $b['purchase_units'][0]['amount']['currency_code']);
    same('https://www.sandbox.paypal.com/payment/blik?token=' . $paypalOrder, $placed['action']->url);
});

test('la vuelta de iDEAL no captura: consulta la orden, da el pedido por pagado una vez y apunta con qué se pagó', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('ideal', 'NL');
    ppThanksRoute($app);
    $web = new TestClient($app);

    $r = ppBack($web, $placed);
    same(302, $r->status);
    same('/pedido/' . $placed['number'] . '/' . $placed['secret'], $r->headers['Location']);
    same(0, count(ppCalls($fake, 'POST', '/capture')), 'PayPal ya la cobró al aprobarla: capturar daría error');
    same(1, count(ppCalls($fake, 'GET', '/v2/checkout/orders/' . $paypalOrder)), 'se consulta');

    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same('completed', $order['status'], 'digital: completado');
    $charges = ppPayments($order, 'charge');
    same(1, count($charges));
    same(['paypal_ideal', 'CAP-' . $paypalOrder, 'ok'], [$charges[0]['method'], $charges[0]['reference'], $charges[0]['status']]);
    same('ideal', $charges[0]['raw']['source']);
    same(1, count(ppNotes($order, 'Pagado con iDEAL por PayPal.')), 'el pedido dice con qué se pagó');
    same('captured', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    same(1, count(aifecOrderMails($app, 'order_confirmed')));

    ppBack($web, $placed);
    same(1, count(ppCalls($fake, 'GET', '/v2/checkout/orders/' . $paypalOrder)), 'la segunda vuelta no vuelve a consultar');
    same(1, count(ppPayments(ppOrderRow($app, $placed), 'charge')), 'ni a cobrar');
});

test('Trustly: el banco no ha confirmado al volver; el pedido espera sin pagar, no lo cancela la tarea de los impagados, y lo paga el aviso', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('trustly', 'ES');
    ppThanksRoute($app);
    $order = ppOrderRow($app, $placed);
    $fake->getResponse = ppJson(200, ppLocalOrder($paypalOrder, 'trustly', 'COMPLETED', 'PENDING', Money::decimal((int) $order['total']), 'EUR', (string) $order['number']));
    $web = new TestClient($app);

    same(302, ppBack($web, $placed)->status);
    $order = ppOrderRow($app, $placed);
    same('pending', $order['payment_status'], 'sin pagar');
    same('on_hold', $order['status'], 'en espera');
    same('review', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
    same(1, count(ppNotes($order, 'Trustly: el banco aún no ha confirmado el pago (PENDING)')), 'una nota lo explica');
    same([], ppPayments($order, 'charge'));

    $app->clock()->advance(3 * 3600);
    $app->service('orders')->expireUnpaid();
    same('on_hold', ppOrderRow($app, $placed)['status'], 'tres horas después sigue esperando el pago');

    same('OK', ppNotify($app, $fake, pplfCompleted($order, $paypalOrder))->body);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same('completed', $order['status']);
    same(1, count(ppPayments($order, 'charge')));
    same('paypal_trustly', ppPayments($order, 'charge')[0]['method']);
    same('captured', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
});

test('el mismo aviso repetido no paga dos veces: un cobro, una nota, un correo', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('trustly', 'ES');
    $order = ppOrderRow($app, $placed);
    $event = pplfCompleted($order, $paypalOrder);

    same('OK', ppNotify($app, $fake, $event)->body);
    same('OK', ppNotify($app, $fake, $event)->body);
    same('OK', ppNotify($app, $fake, $event)->body);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same((int) $order['total'], (int) $order['paid_total']);
    same(1, count(ppPayments($order, 'charge')), 'un solo cobro');
    same(1, count(ppNotes($order, 'Pagado con Trustly por PayPal.')), 'una sola nota');
    same(1, count(aifecOrderMails($app, 'order_confirmed')), 'un solo correo');
});

test('un aviso de iDEAL sin que el cliente vuelva da el pedido por pagado; después la vuelta no cobra otra vez', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('ideal', 'NL');
    ppThanksRoute($app);
    $order = ppOrderRow($app, $placed);

    same('OK', ppNotify($app, $fake, pplfCompleted($order, $paypalOrder))->body);
    same('paid', ppOrderRow($app, $placed)['payment_status']);
    same(302, ppBack(new TestClient($app), $placed)->status);
    $order = ppOrderRow($app, $placed);
    same(1, count(ppPayments($order, 'charge')));
    same(0, count(ppCalls($fake, 'GET', '/v2/checkout/orders/' . $paypalOrder)), 'ya pagado: ni se consulta');
    same(1, count(aifecOrderMails($app, 'order_confirmed')));
});

test('un aviso con la firma mala sobre un pedido de iDEAL da 400 y no cambia nada', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('ideal', 'NL');
    $before = ppOrderRow($app, $placed);

    same(400, ppNotify($app, $fake, pplfCompleted($before, $paypalOrder), false)->status);
    $after = ppOrderRow($app, $placed);
    same('pending', $after['payment_status']);
    same('pending_payment', $after['status']);
    same([], ppPayments($after, 'charge'));
});

test('una captura denegada por aviso deja fallido un pedido de Trustly que esperaba al banco', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('trustly', 'ES');
    $order = ppOrderRow($app, $placed);
    $event = pplfCompleted($order, $paypalOrder);
    $event['event_type'] = 'PAYMENT.CAPTURE.DENIED';
    $event['resource']['status'] = 'DECLINED';

    same('OK', ppNotify($app, $fake, $event)->body);
    $order = ppOrderRow($app, $placed);
    same('failed', $order['payment_status']);
    same('failed', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
});

test('si al volver el comprador aún no ha aprobado en su banco, el intento queda libre y el pedido sigue esperando el pago', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('bancontact', 'BE');
    ppThanksRoute($app);
    $order = ppOrderRow($app, $placed);
    $fake->getResponse = ppJson(200, ppLocalOrder($paypalOrder, 'bancontact', 'PAYER_ACTION_REQUIRED', '', Money::decimal((int) $order['total']), 'EUR', (string) $order['number']));

    same(302, ppBack(new TestClient($app), $placed)->status);
    $order = ppOrderRow($app, $placed);
    same('pending_payment', $order['status']);
    same('pending', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status'], 'libre para la vuelta o el aviso de después');
    same('OK', ppNotify($app, $fake, pplfCompleted($order, $paypalOrder))->body);
    same('paid', ppOrderRow($app, $placed)['payment_status']);
});

test('una orden local rechazada al volver deja el pedido fallido y lleva a la caja con el carrito libre', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('eps', 'AT');
    $order = ppOrderRow($app, $placed);
    $fake->getResponse = ppJson(200, ppLocalOrder($paypalOrder, 'eps', 'COMPLETED', 'DECLINED', Money::decimal((int) $order['total']), 'EUR', (string) $order['number']));

    $r = ppBack(new TestClient($app), $placed);
    same(302, $r->status);
    same('/finalizar-compra', $r->headers['Location']);
    $order = ppOrderRow($app, $placed);
    same('failed', $order['payment_status']);
    same('failed', ppPayments($order, PayPalPayment::ATTEMPT)[0]['status']);
});

test('PayPal caído al consultar una orden local: 503 y el intento libre; al recargar se paga una vez', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('mybank', 'IT');
    ppThanksRoute($app);
    $fake->getResponse = new HttpException('connection reset');
    $web = new TestClient($app);

    same(503, ppBack($web, $placed)->status);
    same('pending', ppAttempt($app, $placed)['status']);
    $fake->getResponse = null;
    same(302, ppBack($web, $placed)->status);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same(1, count(ppPayments($order, 'charge')));
});

test('cancelar en el banco reabre el carrito y esa orden ya no se cobra al volver', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('ideal', 'NL');
    $web = new TestClient($app);

    $r = $web->get('/pago/paypal/cancelado/' . $placed['number'] . '/' . $placed['secret']);
    same(302, $r->status);
    same('/finalizar-compra', $r->headers['Location']);
    same('cancelled', ppAttempt($app, $placed)['status']);
    ppBack($web, $placed);
    same(0, count(ppCalls($fake, 'GET', '/v2/checkout/orders/' . $paypalOrder)), 'una orden cancelada no se consulta ni se cobra');
});

test('la vuelta de un pedido de iDEAL con la orden de PayPal de otro pedido da 404', function (): void {
    list($app, $fake, $p, $placed) = ppLocalPlaced('ideal', 'NL');
    $other = ppPlaceWith($app, new TestClient($app), $p, 'paypal_ideal', ['billing' => ppBuyer('NL')]);
    $otherOrder = ppAttempt($app, $other)['reference'];

    same(404, ppBack(new TestClient($app), $placed, $otherOrder)->status);
    same(0, count(ppCalls($fake, 'GET', '/v2/checkout/orders/')));
});

test('si la factura no es de un país de la forma de pago, no se manda a PayPal: fallo para el cliente y nota para la tienda', function (): void {
    list($app, $fake, $p, $placed) = ppOrder([PayPalSettings::enabledKey('ideal') => 1]);
    $creates = count(ppCalls($fake, 'POST', '/v2/checkout/orders'));
    $order = ppOrderRow($app, $placed);

    $action = $app->service('payments')->get('paypal_ideal')->start(['secret' => (string) $placed['secret']] + $order);
    same(PaymentAction::FAILED, $action->type);
    same('No se puede pagar con iDEAL en esta compra. Elige otra forma de pago.', $action->message);
    same($creates, count(ppCalls($fake, 'POST', '/v2/checkout/orders')), 'sin llamar a PayPal');
    same(1, count(ppNotes(ppOrderRow($app, $placed), 'iDEAL no se ofrece a un comprador de ES que paga en EUR')));
});

test('iDEAL sin activar en la cuenta de PayPal: el cliente elige otra forma de pago y el historial dice por qué', function (): void {
    $app = ppSourcesApp(['ideal']);
    $fake = ppFake($app);
    $fake->createResponse = ppJson(422, [
        'name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The requested action could not be performed ' . PP_SECRET, 'debug_id' => 'dbg-apm',
        'details' => [['issue' => 'NOT_ENABLED_FOR_PAYMENT_SOURCE', 'field' => '/payment_source/ideal']],
    ]);
    $p = aifecProduct($app, ['name' => 'Guía en PDF', 'price' => 2000, 'stock' => 10, 'kind' => 'digital']);

    $placed = ppPlaceWith($app, new TestClient($app), $p, 'paypal_ideal', ['billing' => ppBuyer('NL')]);
    same(false, $placed['ok']);
    same('No se puede pagar con iDEAL en esta compra. Elige otra forma de pago.', $placed['error'], 'una frase para el cliente');
    notContains('NOT_ENABLED', $placed['error'], 'nada técnico al cliente');
    $order = ppOrderRow($app, $placed);
    same('failed', $order['payment_status']);
    $notes = ppNotes($order, 'PayPal ha rechazado iDEAL: no está activada en tu cuenta de PayPal');
    same(1, count($notes), 'el historial lo explica');
    contains('NOT_ENABLED_FOR_PAYMENT_SOURCE', (string) $notes[0]['note']);
    same(false, (bool) $notes[0]['is_public'], 'la nota no la ve el cliente');
    notContains(PP_SECRET, json_encode($order['history'], JSON_UNESCAPED_UNICODE));
    notContains(PP_SECRET, json_encode($app->log()->records, JSON_UNESCAPED_UNICODE));
});

test('la tarjeta en la página de PayPal: se crea con GUEST_CHECKOUT, se captura al volver y el pedido dice «tarjeta»', function (): void {
    $app = ppApp();
    $fake = ppFake($app);
    ppThanksRoute($app);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $placed = ppPlaceWith($app, new TestClient($app), $p, 'paypal_card');
    check(!empty($placed['ok']), 'el pedido se crea');
    $b = pplfCreatedBody($fake);
    same('GUEST_CHECKOUT', $b['payment_source']['paypal']['experience_context']['landing_page']);
    same('SET_PROVIDED_ADDRESS', $b['payment_source']['paypal']['experience_context']['shipping_preference']);
    $paypalOrder = ppAttempt($app, $placed)['reference'];
    same('paypal_card', ppAttempt($app, $placed)['method']);

    same(302, ppBack(new TestClient($app), $placed)->status);
    same(1, count(ppCalls($fake, 'POST', '/v2/checkout/orders/' . $paypalOrder . '/capture')), 'se captura en el servidor');
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same('Tarjeta por PayPal', $order['payment_title']);
    same('paypal_card', ppPayments($order, 'charge')[0]['method']);
    same(1, count(ppNotes($order, 'Pagado con tarjeta por PayPal.')));
});

test('la cartera no apunta la nota «Pagado con…»: su título ya lo dice', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    ppThanksRoute($app);
    ppBack(new TestClient($app), $placed);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same([], ppNotes($order, 'Pagado con'));
});

test('devolver un pedido de iDEAL pide a PayPal devolver su captura, también después de apagar iDEAL', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('ideal', 'NL');
    $order = ppOrderRow($app, $placed);
    ppNotify($app, $fake, pplfCompleted($order, $paypalOrder));
    $app->settings()->set(PayPalSettings::enabledKey('ideal'), 0);

    $orders = $app->service('orders');
    $orders->refund((int) $placed['id'], 500, [], 'Devolución parcial', true, false);
    $calls = ppCalls($fake, 'POST', '/v2/payments/captures/CAP-' . $paypalOrder . '/refund');
    same(1, count($calls));
    same(['currency_code' => 'EUR', 'value' => '5.00'], ppBody($calls[0])['amount']);
    $refunds = ppPayments(ppOrderRow($app, $placed), 'refund');
    same(1, count($refunds));
    same('paypal_ideal', $refunds[0]['method']);
    same('1JU08902781691411', $refunds[0]['reference']);
});

test('un pedido antiguo pagado con «paypal» se devuelve con todo lo nuevo apagado (y la cartera también)', function (): void {
    list($app, $fake, $placed, $orderId) = ppPaidOrder();
    foreach (array_keys(\Aifec\Payments\PayPal\PayPalFundingSources::redirectable()) as $source) {
        $app->settings()->set(PayPalSettings::enabledKey((string) $source), 0);
    }

    $app->service('orders')->refund($orderId, (int) ppOrderRow($app, $placed)['total'], [], 'Devolución total', true, false);
    $calls = ppCalls($fake, 'POST', '/v2/payments/captures/' . PP_CAPTURE . '/refund');
    same(1, count($calls));
    same(false, isset(ppBody($calls[0])['amount']), 'devolución total: sin importe');
    same('refunded', ppOrderRow($app, $placed)['payment_status']);
});

test('una devolución hecha desde PayPal de un pedido de Trustly se apunta como nota, una vez', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppLocalPlaced('trustly', 'ES');
    $order = ppOrderRow($app, $placed);
    ppNotify($app, $fake, pplfCompleted($order, $paypalOrder));
    $refund = ['id' => 'WH-RF-1', 'event_type' => 'PAYMENT.CAPTURE.REFUNDED', 'resource' => [
        'id' => 'RF-DESDE-PAYPAL', 'status' => 'COMPLETED', 'amount' => ['currency_code' => 'EUR', 'value' => '3.00'],
        'links' => [['href' => 'https://api-m.sandbox.paypal.com/v2/payments/captures/CAP-' . $paypalOrder, 'rel' => 'up']],
    ]];

    ppNotify($app, $fake, $refund);
    ppNotify($app, $fake, $refund);
    same(1, count(ppNotes(ppOrderRow($app, $placed), 'RF-DESDE-PAYPAL')));
    same([], ppPayments(ppOrderRow($app, $placed), 'refund'), 'no se devuelve dinero otra vez');
});
