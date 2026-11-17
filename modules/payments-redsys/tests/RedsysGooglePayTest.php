<?php
/**
 * AIFEC — pruebas de Google Pay cobrado por Redsys con el token de una plataforma (UCP): la petición REST que sale al banco
 * (firmada, con el token en DS_XPAYDATA), que un «sí» firmado da el pedido por pagado y un «no», una respuesta falsificada
 * o un error del banco no; que no sale en la caja normal; la declaración del manejador para UCP, y la devolución.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;
use Aifec\Kernel\App;
use Aifec\Modules\PaymentsRedsys\GooglePayCredential;
use Aifec\Modules\PaymentsRedsys\RedsysGooglePay;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

require_once __DIR__ . '/RedsysKit.php';

/** Un token de Google Pay de mentira, con la forma de los de verdad (ECv2 cifrado para la pasarela): no vale para nada. */
const GPAY_TOKEN = '{"signature":"MEUCIQ-de-mentira","intermediateSigningKey":{"signedKey":"{}","signatures":["x"]},"protocolVersion":"ECv2","signedMessage":"{\"encryptedMessage\":\"cifrado-de-mentira\",\"ephemeralPublicKey\":\"k\",\"tag\":\"t\"}"}';

/** La tienda de pruebas con Google Pay encendido y un producto de 20 €. @return array{0:App,1:int} */
function gpayShop(array $ajustes = []): array
{
    $app = rdsApp($ajustes + [RedsysSettings::GOOGLEPAY => true]);
    $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);

    return [$app, $p];
}

/** Hace el pedido con Google Pay, con el token puesto solo mientras dura. @return array<string,mixed> */
function gpayPlace(App $app, int $producto): array
{
    GooglePayCredential::stage(GPAY_TOKEN, 'VISA', '4242', 'Visa •••• 4242');
    try {
        return rdsPlace($app, new TestClient($app), RedsysGooglePay::CODE, $producto);
    } finally {
        GooglePayCredential::clear();
    }
}

/** El cliente HTTP de mentira de la tienda: ningún banco de verdad. */
function gpayHttp(App $app): FakeHttpClient
{
    $http = $app->http();
    check($http instanceof FakeHttpClient, 'las pruebas no llaman a ningún banco de verdad');

    return $http;
}

/** La respuesta firmada del banco a una devolución (tipo 3). */
function gpayRefundAnswer(string $redsysOrder, int $amount): HttpResponse
{
    $data = [
        'Ds_Amount' => (string) $amount, 'Ds_Currency' => '978', 'Ds_Order' => $redsysOrder, 'Ds_MerchantCode' => RDS_MERCHANT,
        'Ds_Terminal' => '001', 'Ds_Response' => '0900', 'Ds_AuthorisationCode' => '987654', 'Ds_TransactionType' => '3',
        'Ds_SecurePayment' => '0', 'Ds_Language' => '1',
    ];

    return new HttpResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(rdsSigned($data)));
}

/** La respuesta firmada del banco a un cobro. Saca el número de pedido de Redsys de la propia petición. */
function gpayAnswer(string $response = '0000', string $key = RDS_KEY, ?int $importe = null): callable
{
    return static function (HttpRequest $request) use ($response, $key, $importe): HttpResponse {
        $body = json_decode((string) $request->body, true);
        $params = json_decode((string) base64_decode((string) $body['Ds_MerchantParameters'], true), true);
        $data = [
            'Ds_Amount' => (string) ($importe !== null ? $importe : (int) $params['DS_MERCHANT_AMOUNT']), 'Ds_Currency' => '978', 'Ds_Order' => (string) $params['DS_MERCHANT_ORDER'],
            'Ds_MerchantCode' => RDS_MERCHANT, 'Ds_Terminal' => '001', 'Ds_Response' => $response, 'Ds_AuthorisationCode' => '654321',
            'Ds_TransactionType' => '0', 'Ds_SecurePayment' => '1', 'Ds_Language' => '1',
        ];

        return new HttpResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(rdsSigned($data, $key)));
    };
}

test('Google Pay no sale en la caja normal: solo se ofrece cuando llega un token de una plataforma', function (): void {
    [$app] = gpayShop();
    $method = $app->service('payments')->get(RedsysGooglePay::CODE);
    check($method !== null, 'la forma de pago está registrada (una devolución tiene que encontrarla)');
    same(false, $method->available(['currency' => 'EUR']), 'sin token no se ofrece');
    GooglePayCredential::stage(GPAY_TOKEN);
    try {
        same(true, $method->available(['currency' => 'EUR']), 'con token sí');
        same(false, $method->available(['currency' => 'USD']), 'y solo en euros');
    } finally {
        GooglePayCredential::clear();
    }
    $off = rdsApp([RedsysSettings::GOOGLEPAY => false]);
    GooglePayCredential::stage(GPAY_TOKEN);
    try {
        same(false, $off->service('payments')->get(RedsysGooglePay::CODE)->available(['currency' => 'EUR']), 'apagado en los ajustes, tampoco');
    } finally {
        GooglePayCredential::clear();
    }
});

test('el cobro sale al REST de Redsys firmado, con el token en DS_XPAYDATA, y un «sí» firmado deja el pedido pagado', function (): void {
    [$app, $p] = gpayShop();
    $http = gpayHttp($app);
    $http->when('POST', '/sis/rest/trataPeticionREST', gpayAnswer('0000'));

    $placed = gpayPlace($app, $p);
    same(true, $placed['ok'], (string) $placed['error']);
    same(1, count($http->requests), 'una sola petición al banco');
    $request = $http->requests[0];
    same('https://sis-t.redsys.es:25443/sis/rest/trataPeticionREST', $request->url, 'el banco de pruebas, por la interfaz REST');
    $body = json_decode((string) $request->body, true);
    check(rdsRequestSignatureOk($body['Ds_MerchantParameters'], $body['Ds_Signature'], RDS_KEY), 'la petición va firmada con la clave del comercio');
    $params = json_decode((string) base64_decode($body['Ds_MerchantParameters'], true), true);
    same('0', $params['DS_MERCHANT_TRANSACTIONTYPE'], 'una autorización');
    same('Google', $params['DS_XPAYTYPE']);
    same('WEB', $params['DS_XPAYORIGEN']);
    same(RDS_MERCHANT, $params['DS_MERCHANT_MERCHANTCODE']);
    $paymentData = json_decode((string) base64_decode($params['DS_XPAYDATA'], true), true);
    same('CARD', $paymentData['type']);
    same(['type' => 'PAYMENT_GATEWAY', 'token' => GPAY_TOKEN], $paymentData['tokenizationData'], 'el token va tal cual, sin tocar');
    same(['cardNetwork' => 'VISA', 'cardDetails' => '4242'], $paymentData['info']);
    check(preg_match('/^[0-9]{4,}G1$/', (string) $params['DS_MERCHANT_ORDER']) === 1, 'un número de Redsys propio de Google Pay: ' . $params['DS_MERCHANT_ORDER']);

    $order = rdsOrderRow($app, $placed);
    same('paid', $order['payment_status']);
    same(RedsysGooglePay::CODE, $order['payment_method']);
    $charges = rdsPayments($order, 'charge');
    same(1, count($charges));
    same((string) $params['DS_MERCHANT_ORDER'], (string) $charges[0]['reference'], 'el cobro queda con la referencia del banco');
    same([], rdsPayments($order, 'attempt'), 'y el intento pendiente desaparece');
    same('paid', RedsysGooglePay::lastOutcome());
    same(null, GooglePayCredential::current(), 'el token no se queda en ningún sitio');
});

test('un «no» del banco deja el pedido fallido y sin cobrar; pedir 3D Secure se distingue de un rechazo', function (): void {
    [$app, $p] = gpayShop();
    $http = gpayHttp($app);
    $http->when('POST', '/sis/rest/trataPeticionREST', gpayAnswer('0190'));
    $placed = gpayPlace($app, $p);
    same(false, $placed['ok']);
    contains('0190', (string) $placed['error'], 'el motivo lleva el código del banco');
    $order = rdsOrderRow($app, $placed);
    same('failed', $order['status']);
    check((string) $order['payment_status'] !== 'paid', 'no se da por pagado');
    same('declined', RedsysGooglePay::lastOutcome());
    same('failed', (string) rdsPayments($order, 'attempt')[0]['status'], 'el intento queda como fallido');

    [$app2, $p2] = gpayShop();
    gpayHttp($app2)->when('POST', '/sis/rest/trataPeticionREST', gpayAnswer('0195'));
    same(false, gpayPlace($app2, $p2)['ok']);
    same('authentication', RedsysGooglePay::lastOutcome(), 'el banco pide autenticar al titular: eso se termina en la caja de la tienda');
});

test('una respuesta falsificada, de otro importe, un error del banco o un fallo de red no dan nada por pagado', function (): void {
    $casos = [
        'firma de otra clave' => gpayAnswer('0000', RDS_LIVE_KEY),
        'otro importe' => gpayAnswer('0000', RDS_KEY, 1),
        'error SIS' => static function (): HttpResponse {
            return new HttpResponse(200, ['Content-Type' => 'application/json'], '{"errorCode":"SIS0042"}');
        },
        'basura' => static function (): HttpResponse {
            return new HttpResponse(200, [], 'no es json');
        },
        'red' => static function (): HttpResponse {
            throw new \Aifec\Http\HttpException('timeout');
        },
    ];
    foreach ($casos as $nombre => $respuesta) {
        [$app, $p] = gpayShop();
        gpayHttp($app)->when('POST', '/sis/rest/trataPeticionREST', $respuesta);
        $placed = gpayPlace($app, $p);
        same(false, $placed['ok'], $nombre . ': el pedido no sale bien');
        check((string) rdsOrderRow($app, $placed)['payment_status'] !== 'paid', $nombre . ': no se da por pagado');
        $buenos = array_filter(rdsPayments(rdsOrderRow($app, $placed), 'charge'), static function (array $row): bool {
            return (string) $row['status'] === 'ok';
        });
        same([], $buenos, $nombre . ': no hay ningún cobro bueno apuntado');
    }
});

test('la declaración del manejador «com.google.pay» de UCP: pasarela redsys con el código de comercio, y solo si está listo', function (): void {
    $off = new RedsysSettings(rdsApp());
    same(null, $off->googlePayHandler(), 'apagado, no se declara');

    [$app] = gpayShop();
    $handler = (new RedsysSettings($app))->googlePayHandler();
    check($handler !== null, 'encendido y con los datos de Redsys, sí');
    same(RedsysSettings::GOOGLEPAY_HANDLER_ID, $handler['id']);
    same('2026-01-23', $handler['version']);
    same('https://pay.google.com/gp/p/ucp/2026-01-23/', $handler['spec']);
    $config = $handler['config'];
    same('TEST', $config['environment'], 'con Redsys en pruebas, Google Pay en pruebas');
    same(2, $config['api_version']);
    $method = $config['allowed_payment_methods'][0];
    same('CARD', $method['type']);
    same(['type' => 'PAYMENT_GATEWAY', 'parameters' => ['gateway' => 'redsys', 'gatewayMerchantId' => RDS_MERCHANT]], $method['tokenization_specification']);
    same(['VISA', 'MASTERCARD'], $method['parameters']['allowed_card_networks']);
    same(['CRYPTOGRAM_3DS'], $method['parameters']['allowed_auth_methods'], 'solo la tarjeta del móvil: ya va autenticada');
    check(!isset($config['merchant_info']['merchant_id']), 'en pruebas no hace falta el identificador de Google');

    $app->settings()->setMany([RedsysSettings::GOOGLEPAY_PAN_ONLY => true, RedsysSettings::GOOGLEPAY_NETWORKS => 'amex, visa, nada']);
    $handler = (new RedsysSettings($app))->googlePayHandler();
    same(['PAN_ONLY', 'CRYPTOGRAM_3DS'], $handler['config']['allowed_payment_methods'][0]['parameters']['allowed_auth_methods']);
    same(['VISA', 'AMEX'], $handler['config']['allowed_payment_methods'][0]['parameters']['allowed_card_networks'], 'solo las redes que Google Pay conoce');

    // En producción hace falta el identificador de comercio de Google.
    $app->settings()->set(RedsysSettings::ENVIRONMENT, 'live');
    $app->setSecret(RedsysSettings::SECRET_LIVE, RDS_LIVE_KEY);
    same(null, (new RedsysSettings($app))->googlePayHandler(), 'en producción sin identificador de Google no se declara');
    $app->settings()->set(RedsysSettings::GOOGLEPAY_MERCHANT_ID, 'BCR2DN4TEXAMPLE1');
    $live = (new RedsysSettings($app))->googlePayHandler();
    same('PRODUCTION', $live['config']['environment']);
    same('BCR2DN4TEXAMPLE1', $live['config']['merchant_info']['merchant_id']);

    // Y el filtro que lee UCP la incluye.
    $handlers = $app->hooks()->apply('ucp.payment_handlers', []);
    same(RedsysSettings::GOOGLEPAY_HANDLER_ID, $handlers['com.google.pay'][0]['id']);
});

test('una devolución de un pedido pagado con Google Pay va por el REST, de tipo 3, sobre la referencia del cobro', function (): void {
    [$app, $p] = gpayShop();
    $http = gpayHttp($app);
    $http->when('POST', '/sis/rest/trataPeticionREST', static function (HttpRequest $request): HttpResponse {
        $params = json_decode((string) base64_decode((string) json_decode((string) $request->body, true)['Ds_MerchantParameters'], true), true);
        if ((string) $params['DS_MERCHANT_TRANSACTIONTYPE'] === '3') {
            return gpayRefundAnswer((string) $params['DS_MERCHANT_ORDER'], (int) $params['DS_MERCHANT_AMOUNT']);
        }

        return gpayAnswer('0000')($request);
    });
    $placed = gpayPlace($app, $p);
    same(true, $placed['ok'], (string) $placed['error']);
    $charge = rdsPayments(rdsOrderRow($app, $placed), 'charge')[0];

    $refundId = $app->service('orders')->refund((int) $placed['id'], 500, [], 'Talla equivocada', true, false);
    check($refundId > 0, 'la devolución se apunta');
    $refundRequest = $http->requests[1];
    $params = json_decode((string) base64_decode((string) json_decode((string) $refundRequest->body, true)['Ds_MerchantParameters'], true), true);
    same('3', $params['DS_MERCHANT_TRANSACTIONTYPE']);
    same((string) $charge['reference'], $params['DS_MERCHANT_ORDER'], 'sobre el número del cobro de Google Pay');
    same('500', $params['DS_MERCHANT_AMOUNT']);
});
