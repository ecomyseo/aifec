<?php
/**
 * AIFEC — pruebas de las devoluciones por PayPal: la petición sobre la captura del pedido (total o parcial), el identificador que
 * vuelve, que un «no» de PayPal o un fallo de red no apunten nada, y que se devuelva en el entorno del cobro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Money;
use Aifec\Http\HttpException;
use Aifec\Modules\PaymentsPaypal\PayPalPayment;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;

require_once __DIR__ . '/PayPalKit.php';

test('una devolución total pide a PayPal devolver la captura entera, sin importe, y apunta el identificador que vuelve', function (): void {
    list($app, $fake, $placed, $id) = ppPaidOrder();
    $order = $app->service('orders')->find($id);

    $refundId = $app->service('orders')->refund($id, (int) $order['total'], [], 'Pedido devuelto', true, false);
    check($refundId > 0, 'la devolución se apunta');

    $calls = ppCalls($fake, 'POST', '/refund');
    same(1, count($calls));
    same('https://api-m.sandbox.paypal.com/v2/payments/captures/' . PP_CAPTURE . '/refund', $calls[0]->url, 'sobre la captura del pedido');
    same('Bearer ' . PP_TOKEN, $calls[0]->headers['Authorization']);
    $body = ppBody($calls[0]);
    same(false, isset($body['amount']), 'sin importe: PayPal devuelve todo');
    same('Pedido devuelto', $body['note_to_payer']);

    $order = $app->service('orders')->find($id);
    same('refunded', $order['payment_status']);
    same((int) $order['total'], (int) $order['refunded_total']);
    $refunds = ppPayments($order, 'refund');
    same(1, count($refunds));
    same(['paypal', '1JU08902781691411', 'ok', (int) $order['total']], [$refunds[0]['method'], $refunds[0]['reference'], $refunds[0]['status'], $refunds[0]['amount']], 'la referencia es la devolución de PayPal');
});

test('una devolución parcial manda el importe con dos decimales y punto, y la moneda del pedido', function (): void {
    list($app, $fake, $placed, $id) = ppPaidOrder();
    $order = $app->service('orders')->find($id);

    $app->service('orders')->refund($id, 550, [], 'Una camiseta', true, false);
    $calls = ppCalls($fake, 'POST', '/refund');
    same(1, count($calls));
    $body = ppBody($calls[0]);
    same(['currency_code' => 'EUR', 'value' => '5.50'], $body['amount']);
    $order = $app->service('orders')->find($id);
    same('partially_refunded', $order['payment_status']);
    same(550, (int) $order['refunded_total']);

    // Lo que queda: otra devolución parcial sobre la misma captura, con su importe.
    $rest = (int) $order['total'] - 550;
    $app->service('orders')->refund($id, $rest, [], 'El resto', true, false);
    $calls = ppCalls($fake, 'POST', '/refund');
    same(2, count($calls));
    same(Money::decimal($rest), ppBody($calls[1])['amount']['value'], 'el resto, con su importe');
    same('refunded', $app->service('orders')->find($id)['payment_status']);
});

test('si PayPal rechaza la devolución, la tienda lanza una excepción y no apunta ninguna devolución', function (): void {
    list($app, $fake, $placed, $id) = ppPaidOrder();
    $fake->refundResponse = ppJson(422, ['name' => 'UNPROCESSABLE_ENTITY', 'message' => 'The refund is higher than the capture ' . PP_SECRET, 'debug_id' => 'dbg-r', 'details' => [['issue' => 'CAPTURE_FULLY_REFUNDED']]]);
    $before = [aifecOrderCount($app, 'order_refunds'), aifecOrderCount($app, 'order_payments', "kind = 'refund'")];

    try {
        $app->service('orders')->refund($id, 500, [], 'Una camiseta', true, false);
        throw new \LogicException('debía lanzar');
    } catch (\RuntimeException $e) {
        contains('422', $e->getMessage());
        contains('CAPTURE_FULLY_REFUNDED', $e->getMessage());
        notContains(PP_SECRET, $e->getMessage());
    }
    $order = $app->service('orders')->find($id);
    same(0, (int) $order['refunded_total'], 'el importe reservado se libera');
    same('paid', $order['payment_status']);
    same($before, [aifecOrderCount($app, 'order_refunds'), aifecOrderCount($app, 'order_payments', "kind = 'refund'")]);
    notContains(PP_SECRET, json_encode($app->log()->records, JSON_UNESCAPED_UNICODE));
});

test('un fallo de red, una devolución sin identificador o una que PayPal da por fallida también lanzan excepción', function (): void {
    list($app, $fake, $placed, $id) = ppPaidOrder();
    $method = $app->service('payments')->get('paypal');
    $order = $app->service('orders')->find($id);

    $fake->refundResponse = new HttpException('cURL error 7: connection refused');
    throws(function () use ($method, $order): void {
        $method->refund($order, 500, 'x');
    }, \RuntimeException::class);

    $fake->refundResponse = ppJson(201, ['status' => 'COMPLETED']);
    throws(function () use ($method, $order): void {
        $method->refund($order, 500, 'x');
    }, \RuntimeException::class, 'sin identificador');

    $fake->refundResponse = ppJson(201, ['id' => 'R1', 'status' => 'FAILED']);
    throws(function () use ($method, $order): void {
        $method->refund($order, 500, 'x');
    }, \RuntimeException::class, 'FAILED');

    $fake->refundResponse = ppJson(201, ['id' => 'R2', 'status' => 'PENDING']);
    same('R2', $method->refund($order, 500, 'x'), 'una devolución pendiente sí vale: PayPal la completará');

    throws(function () use ($method, $order): void {
        $method->refund($order, 0, 'x');
    }, \RuntimeException::class, 'sin importe');
});

test('un pedido sin cobro por PayPal no se puede devolver por PayPal', function (): void {
    list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
    $order = ppOrderRow($app, $placed);
    $method = $app->service('payments')->get('paypal');

    throws(function () use ($method, $order): void {
        $method->refund($order, 500, 'x');
    }, \RuntimeException::class);
    same(0, count(ppCalls($fake, 'POST', '/refund')), 'no se pregunta a PayPal');
});

test('se devuelve en el entorno del cobro, no en el que esté elegido ahora', function (): void {
    // Cobrado en el sandbox; la tienda ya está en producción.
    list($app, $fake, $placed, $id) = ppPaidOrder();
    $app->settings()->set(PayPalSettings::ENVIRONMENT, 'live');
    $app->service('orders')->refund($id, 500, [], 'Una camiseta', true, false);
    $calls = ppCalls($fake, 'POST', '/refund');
    same('https://api-m.sandbox.paypal.com/v2/payments/captures/' . PP_CAPTURE . '/refund', $calls[0]->url);
    $auth = ppCalls($fake, 'POST', '/v1/oauth2/token');
    same('Basic ' . base64_encode(PP_CLIENT . ':' . PP_SECRET), $auth[0]->headers['Authorization'], 'con las credenciales del sandbox');

    // Cobrado en producción; la tienda vuelve al sandbox.
    $app = ppApp([PayPalSettings::ENVIRONMENT => 'live']);
    $fake = ppFake($app);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $placed = ppPlace($app, new TestClient($app), $p);
    check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
    $order = ppOrderRow($app, $placed);
    // El aviso de producción se verifica con el identificador del aviso de producción.
    $fake->verifyResponse = null;
    same('OK', ppNotify($app, $fake, ppCompleted($order, ppAttempt($app, $placed)['reference']))->body);
    $order = ppOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same(false, $order['test'], 'un cobro real no es de pruebas');
    $verify = ppCalls($fake, 'POST', '/verify-webhook-signature');
    same(PP_LIVE_WEBHOOK, json_decode((string) $verify[0]->body, true)['webhook_id'], 'con el identificador del aviso de producción');

    $app->settings()->set(PayPalSettings::ENVIRONMENT, 'sandbox');
    $app->service('orders')->refund((int) $placed['id'], 500, [], 'Una camiseta', true, false);
    $calls = ppCalls($fake, 'POST', '/refund');
    same('https://api-m.paypal.com/v2/payments/captures/' . PP_CAPTURE . '/refund', $calls[0]->url, 'el dominio de producción');
    $auth = ppCalls($fake, 'POST', 'https://api-m.paypal.com/v1/oauth2/token');
    same('Basic ' . base64_encode(PP_LIVE_CLIENT . ':' . PP_LIVE_SECRET), $auth[0]->headers['Authorization'], 'con las credenciales de producción');
});

test('devolver un pedido de PayPal sin las credenciales de su entorno lanza una excepción, no un error de PHP', function (): void {
    list($app, $fake, $placed, $id) = ppPaidOrder();
    $app->settings()->set(PayPalSettings::CLIENT_ID_SANDBOX, '');
    $before = (int) $app->service('orders')->find($id)['refunded_total'];

    throws(function () use ($app, $id): void {
        $app->service('orders')->refund($id, 500, [], 'x', true, false);
    }, \RuntimeException::class);
    same($before, (int) $app->service('orders')->find($id)['refunded_total']);
    same('paypal', $app->service('payments')->get(PayPalPayment::CODE)->code(), 'la forma de pago sigue registrada aunque no se ofrezca');
});
