<?php
/**
 * AIFEC — pruebas de las devoluciones por Redsys: la petición que sale al banco (firmada, de tipo 3, sobre el número del
 * cobro), la referencia que vuelve, y que un «no» del banco, una respuesta falsificada o un fallo de red no apuntan nada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpResponse;
use Aifec\Kernel\App;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

require_once __DIR__ . '/RedsysKit.php';

/**
 * Un pedido ya cobrado por Redsys. Devuelve [app, id del pedido, número de Redsys del cobro, total].
 *
 * @return array{0:App,1:int,2:string,3:int}
 */
function rdsPaidOrder(string $code = 'redsys'): array
{
    list($app, $p, $placed, $params) = rdsOrder($code);
    $order = rdsOrderRow($app, $placed);
    same('OK', rdsNotify($app, rdsAviso($params['DS_MERCHANT_ORDER'], (int) $order['total']))->body);
    same('paid', rdsOrderRow($app, $placed)['payment_status']);

    return [$app, (int) $placed['id'], $params['DS_MERCHANT_ORDER'], (int) $order['total']];
}

/**
 * La respuesta del banco a una devolución, firmada como la firma Redsys.
 *
 * @return HttpResponse
 */
function rdsRefundAnswer(string $redsysOrder, int $amount, string $response = '0900', string $key = RDS_KEY): HttpResponse
{
    $data = [
        'Ds_Amount' => (string) $amount, 'Ds_Currency' => '978', 'Ds_Order' => $redsysOrder, 'Ds_MerchantCode' => RDS_MERCHANT,
        'Ds_Terminal' => '001', 'Ds_Response' => $response, 'Ds_AuthorisationCode' => '987654', 'Ds_TransactionType' => '3',
        'Ds_SecurePayment' => '0', 'Ds_Language' => '1',
    ];

    return new HttpResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(rdsSigned($data, $key)));
}

/** @return FakeHttpClient */
function rdsHttp(App $app): FakeHttpClient
{
    $http = $app->http();
    check($http instanceof FakeHttpClient, 'las pruebas no llaman a ningún banco de verdad');

    return $http;
}

test('una devolución manda al banco una petición REST firmada, de tipo 3, sobre el número del cobro, y devuelve su referencia', function (): void {
    list($app, $id, $redsysOrder, $total) = rdsPaidOrder();
    $http = rdsHttp($app);
    $http->queue(rdsRefundAnswer($redsysOrder, 500));

    $refundId = $app->service('orders')->refund($id, 500, [], 'Camiseta rota', true, false);
    check($refundId > 0, 'la devolución se apunta');

    same(1, count($http->requests));
    $request = $http->requests[0];
    same('POST', $request->method);
    same('https://sis-t.redsys.es:25443/sis/rest/trataPeticionREST', $request->url, 'el banco de pruebas, por la interfaz REST');
    same('application/json', $request->headers['Content-Type']);
    $body = json_decode((string) $request->body, true);
    same(['Ds_SignatureVersion', 'Ds_MerchantParameters', 'Ds_Signature'], array_keys($body));
    same('HMAC_SHA256_V1', $body['Ds_SignatureVersion']);
    $params = json_decode((string) base64_decode($body['Ds_MerchantParameters'], true), true);
    same('3', $params['DS_MERCHANT_TRANSACTIONTYPE'], 'tipo de operación: devolución');
    same('500', $params['DS_MERCHANT_AMOUNT'], 'el importe devuelto, en céntimos');
    same($redsysOrder, $params['DS_MERCHANT_ORDER'], 'sobre el número de pedido del cobro');
    same('978', $params['DS_MERCHANT_CURRENCY']);
    same(RDS_MERCHANT, $params['DS_MERCHANT_MERCHANTCODE']);
    same('1', $params['DS_MERCHANT_TERMINAL']);
    same(true, rdsRequestSignatureOk($body['Ds_MerchantParameters'], $body['Ds_Signature'], RDS_KEY), 'firmada con la clave');
    notContains(RDS_KEY, (string) $request->body, 'la clave no sale de la tienda');

    $order = $app->service('orders')->find($id);
    same('partially_refunded', $order['payment_status']);
    same(500, $order['refunded_total']);
    $refunds = rdsPayments($order, 'refund');
    same(1, count($refunds));
    same($redsysOrder . '-R500', $refunds[0]['reference']);
    same(500, $refunds[0]['amount']);
});

test('cada devolución tiene su referencia, y devolver todo deja el pedido devuelto', function (): void {
    list($app, $id, $redsysOrder, $total) = rdsPaidOrder();
    $http = rdsHttp($app);
    $http->queue(rdsRefundAnswer($redsysOrder, 300), rdsRefundAnswer($redsysOrder, $total - 300));

    $app->service('orders')->refund($id, 300, [], 'Primera', true, false);
    $app->service('orders')->refund($id, $total - 300, [], 'El resto', true, false);

    $order = $app->service('orders')->find($id);
    same('refunded', $order['payment_status']);
    $references = array_map(static function (array $p): string {
        return (string) $p['reference'];
    }, rdsPayments($order, 'refund'));
    same([$redsysOrder . '-R300', $redsysOrder . '-R' . $total], $references);
    same(2, count($http->requests));
    $second = json_decode((string) base64_decode(json_decode((string) $http->requests[1]->body, true)['Ds_MerchantParameters'], true), true);
    same((string) ($total - 300), $second['DS_MERCHANT_AMOUNT']);
});

test('un «no» del banco lanza la excepción con el código y no apunta nada', function (): void {
    list($app, $id, $redsysOrder, $total) = rdsPaidOrder();
    rdsHttp($app)->queue(rdsRefundAnswer($redsysOrder, 500, '0950'));
    $before = aifecOrderSnapshot($app);

    $message = '';
    try {
        $app->service('orders')->refund($id, 500, [], 'Camiseta rota', true, false);
    } catch (\RuntimeException $e) {
        $message = $e->getMessage();
    }
    contains('950', $message);
    contains('Refund not allowed', $message);
    $order = $app->service('orders')->find($id);
    same('paid', $order['payment_status']);
    same(0, (int) $order['refunded_total'], 'el importe reservado vuelve');
    same([], rdsPayments($order, 'refund'));
    same(0, aifecOrderCount($app, 'order_refunds'));
    same($before, aifecOrderSnapshot($app), 'ninguna tabla ha cambiado');
});

test('un error del banco, un fallo de red, una respuesta ilegible o con la firma falsa tampoco apuntan nada', function (): void {
    $cases = [
        'errorCode' => new HttpResponse(200, [], '{"errorCode":"SIS0057"}'),
        'HTTP 500' => new HttpResponse(500, [], 'error'),
        'ilegible' => new HttpResponse(200, [], '<html>mantenimiento</html>'),
        'firma falsa' => 'forged',
        'red' => new HttpException('timeout'),
    ];
    foreach ($cases as $name => $answer) {
        list($app, $id, $redsysOrder, $total) = rdsPaidOrder();
        rdsHttp($app)->queue($answer === 'forged' ? rdsRefundAnswer($redsysOrder, 500, '0900', RDS_LIVE_KEY) : $answer);

        $failed = false;
        try {
            $app->service('orders')->refund($id, 500, [], 'Prueba', true, false);
        } catch (\RuntimeException $e) {
            $failed = true;
        }
        same(true, $failed, 'falla con: ' . $name);
        $order = $app->service('orders')->find($id);
        same('paid', $order['payment_status'], $name);
        same(0, (int) $order['refunded_total'], $name);
        same([], rdsPayments($order, 'refund'), $name);
    }
});

test('un pedido de pruebas se devuelve contra el banco de pruebas aunque la tienda ya esté en producción', function (): void {
    list($app, $id, $redsysOrder, $total) = rdsPaidOrder();
    $app->settings()->set(RedsysSettings::ENVIRONMENT, 'live');
    $app->setSecret(RedsysSettings::SECRET_LIVE, RDS_LIVE_KEY);
    $http = rdsHttp($app);
    $http->queue(rdsRefundAnswer($redsysOrder, 500));

    $app->service('orders')->refund($id, 500, [], 'Prueba', true, false);
    same('https://sis-t.redsys.es:25443/sis/rest/trataPeticionREST', $http->requests[0]->url);
    $body = json_decode((string) $http->requests[0]->body, true);
    same(true, rdsRequestSignatureOk($body['Ds_MerchantParameters'], $body['Ds_Signature'], RDS_KEY), 'con la clave de pruebas');
});

test('un pedido cobrado en producción se devuelve por el banco de producción y con su clave', function (): void {
    $app = rdsApp();
    $app->settings()->set(RedsysSettings::ENVIRONMENT, 'live');
    $app->setSecret(RedsysSettings::SECRET_LIVE, RDS_LIVE_KEY);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
    $placed = rdsPlace($app, new TestClient($app), 'redsys', $p);
    check(!empty($placed['ok']), 'el pedido se crea');
    $number = rdsParams($placed['action'])['DS_MERCHANT_ORDER'];
    $total = (int) rdsOrderRow($app, $placed)['total'];
    same('OK', rdsNotify($app, rdsAviso($number, $total, [], RDS_LIVE_KEY))->body);
    $http = rdsHttp($app);
    $http->queue(rdsRefundAnswer($number, $total, '0900', RDS_LIVE_KEY));

    $app->service('orders')->refund((int) $placed['id'], $total, [], 'Todo', true, true);
    same('https://sis.redsys.es/sis/rest/trataPeticionREST', $http->requests[0]->url);
    $body = json_decode((string) $http->requests[0]->body, true);
    same(true, rdsRequestSignatureOk($body['Ds_MerchantParameters'], $body['Ds_Signature'], RDS_LIVE_KEY));
    same(false, rdsRequestSignatureOk($body['Ds_MerchantParameters'], $body['Ds_Signature'], RDS_KEY), 'y no con la de pruebas');
    same('refunded', rdsOrderRow($app, $placed)['payment_status']);
    same(10, aifecOrderStock($app, $p), 'las existencias vuelven');
});

test('un pedido de Bizum se devuelve por la misma vía, sobre su número de cobro', function (): void {
    list($app, $id, $redsysOrder, $total) = rdsPaidOrder('bizum');
    $http = rdsHttp($app);
    $http->queue(rdsRefundAnswer($redsysOrder, $total));

    $app->service('orders')->refund($id, $total, [], 'Todo', true, false);
    $params = json_decode((string) base64_decode(json_decode((string) $http->requests[0]->body, true)['Ds_MerchantParameters'], true), true);
    same($redsysOrder, $params['DS_MERCHANT_ORDER']);
    same('refunded', $app->service('orders')->find($id)['payment_status']);
});

test('refund() directo: sin cobro de Redsys en el pedido o sin clave, lanza antes de llamar al banco', function (): void {
    $app = rdsApp();
    $method = $app->service('payments')->get('redsys');
    $http = rdsHttp($app);

    throws(function () use ($method): void {
        $method->refund(['test' => true, 'payments' => [], 'refunded_total' => 100], 100, 'x');
    }, \RuntimeException::class);
    throws(function () use ($method): void {
        $method->refund(['test' => true, 'payments' => [['kind' => 'charge', 'status' => 'pending', 'method' => 'redsys', 'reference' => '0001A']], 'refunded_total' => 100], 100, 'x');
    }, \RuntimeException::class);
    $paid = ['test' => false, 'payments' => [['kind' => 'charge', 'status' => 'ok', 'method' => 'redsys', 'reference' => '0001A']], 'refunded_total' => 100];
    throws(function () use ($method, $paid): void {
        $method->refund($paid, 100, 'x');
    }, \RuntimeException::class);
    throws(function () use ($method): void {
        $method->refund(['test' => true, 'payments' => [['kind' => 'charge', 'status' => 'ok', 'method' => 'redsys', 'reference' => '0001A']], 'refunded_total' => 0], 0, 'x');
    }, \RuntimeException::class);
    same(0, count($http->requests), 'ninguna llamada al banco');
});
