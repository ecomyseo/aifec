<?php
/**
 * AIFEC — pruebas de la verificación de avisos de PayPal y de sus errores.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\HttpException;
use Aifec\Payments\PayPal\PayPalException;

require_once __DIR__ . '/helpers.php';

/** Cabeceras de un aviso, con el nombre en minúsculas como las entrega el núcleo. */
function paypalWebhookHeaders(): array
{
    return [
        'paypal-transmission-id' => 'b1c2d3e4-0000-1111-2222-333344445555',
        'paypal-transmission-time' => '2026-11-12T10:31:00Z',
        'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42',
        'paypal-auth-algo' => 'SHA256withRSA',
        'paypal-transmission-sig' => 'dGVzdC1zaWc=',
    ];
}

const PAYPAL_WEBHOOK_BODY = '{"id":"WH-1","event_type":"PAYMENT.CAPTURE.COMPLETED","resource":{"custom_id":"PED-1001","amount":{"value":"49.99"},"note":"ñ & \"q\"","extra":{}}}';

test('un aviso con verification_status SUCCESS es auténtico y se manda a PayPal tal cual llegó', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(200, ['verification_status' => 'SUCCESS']));

    same(true, paypalClient($http)->verifyWebhook(paypalWebhookHeaders(), PAYPAL_WEBHOOK_BODY, 1000));

    $r = paypalApiCalls($http)[0];
    same('https://api-m.sandbox.paypal.com/v1/notifications/verify-webhook-signature', $r->url);
    same('Bearer ' . PAYPAL_TEST_TOKEN, $r->headers['Authorization']);
    $b = paypalBody($r);
    same('b1c2d3e4-0000-1111-2222-333344445555', $b['transmission_id']);
    same('2026-11-12T10:31:00Z', $b['transmission_time']);
    same('https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42', $b['cert_url']);
    same('SHA256withRSA', $b['auth_algo']);
    same('dGVzdC1zaWc=', $b['transmission_sig']);
    same('WH-123', $b['webhook_id']);
    same('PAYMENT.CAPTURE.COMPLETED', $b['webhook_event']['event_type']);
    same('ñ & "q"', $b['webhook_event']['resource']['note']);
    contains('"extra":{}', (string) $r->body, 'el objeto vacío no se convierte en lista');
    contains(PAYPAL_WEBHOOK_BODY, (string) $r->body, 'el cuerpo viaja sin recodificar');
});

test('un aviso con FAILURE, o sin estado, no es auténtico', function (): void {
    $http = paypalHttp();
    $http->queue(
        paypalJson(200, ['verification_status' => 'FAILURE']),
        paypalJson(200, []),
        paypalJson(200, ['verification_status' => 'success'])
    );
    $client = paypalClient($http);
    same(false, $client->verifyWebhook(paypalWebhookHeaders(), PAYPAL_WEBHOOK_BODY, 1));
    same(false, $client->verifyWebhook(paypalWebhookHeaders(), PAYPAL_WEBHOOK_BODY, 2));
    same(false, $client->verifyWebhook(paypalWebhookHeaders(), PAYPAL_WEBHOOK_BODY, 3), 'solo SUCCESS exacto');
});

test('si falta una cabecera, el webhook id o el cuerpo es JSON, es falso sin llamar a PayPal', function (): void {
    $http = paypalHttp();
    $client = paypalClient($http);
    foreach (array_keys(paypalWebhookHeaders()) as $name) {
        $h = paypalWebhookHeaders();
        unset($h[$name]);
        same(false, $client->verifyWebhook($h, PAYPAL_WEBHOOK_BODY, 1), 'falta ' . $name);
        $h = paypalWebhookHeaders();
        $h[$name] = '  ';
        same(false, $client->verifyWebhook($h, PAYPAL_WEBHOOK_BODY, 1), 'vacía ' . $name);
    }
    same(false, $client->verifyWebhook([], PAYPAL_WEBHOOK_BODY, 1));
    same(false, $client->verifyWebhook(paypalWebhookHeaders(), 'esto no es json', 1));
    same(false, $client->verifyWebhook(paypalWebhookHeaders(), '"un texto"', 1));
    same(false, $client->verifyWebhook(paypalWebhookHeaders(), '', 1));
    same(false, paypalClient($http, null, 'sandbox', '')->verifyWebhook(paypalWebhookHeaders(), PAYPAL_WEBHOOK_BODY, 1), 'sin webhook id');
    same(0, count($http->requests), 'ninguna llamada a PayPal');
});

test('los nombres de cabecera con otras mayúsculas también valen', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(200, ['verification_status' => 'SUCCESS']));
    $h = [];
    foreach (paypalWebhookHeaders() as $k => $v) {
        $h[strtoupper($k)] = $v;
    }
    same(true, paypalClient($http)->verifyWebhook($h, PAYPAL_WEBHOOK_BODY, 1));
});

test('un error de PayPal al verificar lanza, para que el aviso se reintente', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(503, ['name' => 'SERVICE_UNAVAILABLE', 'message' => 'Try later', 'debug_id' => 'd5']));
    try {
        paypalClient($http)->verifyWebhook(paypalWebhookHeaders(), PAYPAL_WEBHOOK_BODY, 1);
        throw new \LogicException('debía lanzar');
    } catch (PayPalException $e) {
        same(503, $e->status());
        same(true, $e->isTransient());
    }
});

test('un 401 lanza con el estado, el nombre y el identificador, sin secreto ni ficha', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(401, [
        'name' => 'AUTHENTICATION_FAILURE',
        'message' => 'Authentication failed due to invalid authentication credentials ' . PAYPAL_TEST_SECRET . ' ' . PAYPAL_TEST_TOKEN,
        'debug_id' => 'dbg-401',
    ]));
    try {
        paypalClient($http)->getOrder('ORD-1', 1);
        throw new \LogicException('debía lanzar');
    } catch (PayPalException $e) {
        same(401, $e->status());
        same('AUTHENTICATION_FAILURE', $e->paypalName());
        same('dbg-401', $e->debugId());
        same(false, $e->isTransient());
        contains('401', $e->getMessage());
        contains('AUTHENTICATION_FAILURE', $e->getMessage());
        notContains(PAYPAL_TEST_SECRET, $e->getMessage());
        notContains(PAYPAL_TEST_TOKEN, $e->getMessage());
        notContains(PAYPAL_TEST_SECRET, (string) $e);
    }
});

test('si PayPal rechaza las credenciales al pedir la ficha, el error sale sin el secreto', function (): void {
    $http = new \Aifec\Http\FakeHttpClient();
    $http->queue(paypalJson(401, ['error' => 'invalid_client', 'error_description' => 'Client Authentication failed']));
    try {
        paypalClient($http)->accessToken(1);
        throw new \LogicException('debía lanzar');
    } catch (PayPalException $e) {
        same(401, $e->status());
        same('invalid_client', $e->paypalName());
        contains('Client Authentication failed', $e->getMessage());
        notContains(PAYPAL_TEST_SECRET, $e->getMessage());
        notContains(base64_encode('CLIENT-ID:' . PAYPAL_TEST_SECRET), $e->getMessage());
    }

    $http2 = new \Aifec\Http\FakeHttpClient();
    $http2->queue(paypalJson(200, ['token_type' => 'Bearer']));
    throws(function () use ($http2): void {
        paypalClient($http2)->accessToken(1);
    }, PayPalException::class, 'una respuesta sin access_token es un error');
});

test('un fallo de red se envuelve en PayPalException transitoria y sin secretos', function (): void {
    $http = paypalHttp();
    $http->queue(new HttpException('cURL error 28: timed out with ' . PAYPAL_TEST_SECRET));
    try {
        paypalClient($http)->getOrder('ORD-1', 1);
        throw new \LogicException('debía lanzar');
    } catch (PayPalException $e) {
        same(0, $e->status());
        same(true, $e->isTransient());
        contains('network', $e->getMessage());
        notContains(PAYPAL_TEST_SECRET, $e->getMessage());
        check($e->getPrevious() instanceof HttpException, 'conserva la causa');
    }
});

test('los 5xx y el 429 son transitorios; el 400 no; una respuesta que no es JSON también falla bien', function (): void {
    $http = paypalHttp();
    $http->queue(
        paypalJson(500, ['name' => 'INTERNAL_SERVER_ERROR']),
        paypalJson(429, ['name' => 'RATE_LIMIT_REACHED']),
        paypalJson(400, ['name' => 'INVALID_REQUEST']),
        new \Aifec\Http\HttpResponse(502, [], '<html>Bad gateway</html>'),
        new \Aifec\Http\HttpResponse(200, [], '<html>no json</html>')
    );
    $client = paypalClient($http);
    $expected = [[500, true], [429, true], [400, false], [502, true]];
    foreach ($expected as $case) {
        try {
            $client->getOrder('ORD-1', 1);
            throw new \LogicException('debía lanzar');
        } catch (PayPalException $e) {
            same($case[0], $e->status());
            same($case[1], $e->isTransient(), 'estado ' . $case[0]);
        }
    }
    throws(function () use ($client): void {
        $client->getOrder('ORD-1', 1);
    }, PayPalException::class, 'un 200 con basura');
});

test('el identificador de pedido se codifica en la ruta y no puede estar vacío', function (): void {
    $http = paypalHttp();
    $http->queue(paypalJson(200, ['id' => 'X', 'status' => 'CREATED']));
    paypalClient($http)->getOrder('a/b c?d', 1);
    same('https://api-m.sandbox.paypal.com/v2/checkout/orders/a%2Fb%20c%3Fd', paypalApiCalls($http)[0]->url);

    throws(function (): void {
        paypalClient(paypalHttp())->getOrder('', 1);
    }, \InvalidArgumentException::class);
});
