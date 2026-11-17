<?php
/**
 * AIFEC — ayudas comunes de las pruebas de PayPal (no es una prueba: el lanzador solo carga *Test.php).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;
use Aifec\Payments\PayPal\PayPalClient;
use Aifec\Payments\PayPal\PayPalConfig;

const PAYPAL_TEST_SECRET = 'S3CRET-do-not-leak';

const PAYPAL_TEST_TOKEN = 'A21AAF-token-do-not-leak';

function paypalConfig(string $env = 'sandbox', string $webhookId = 'WH-123'): PayPalConfig
{
    return new PayPalConfig('CLIENT-ID', PAYPAL_TEST_SECRET, $env, $webhookId, 'Mi Tienda', 'es-ES');
}

function paypalJson(int $status, array $data): HttpResponse
{
    return new HttpResponse($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Cliente HTTP de mentira que ya sabe contestar a la petición de la ficha (9 horas de vida). */
function paypalHttp(int $expiresIn = 32400): FakeHttpClient
{
    $http = new FakeHttpClient();
    $http->when('POST', '/v1/oauth2/token', function (HttpRequest $r) use ($expiresIn): HttpResponse {
        return paypalJson(200, ['access_token' => PAYPAL_TEST_TOKEN, 'token_type' => 'Bearer', 'expires_in' => $expiresIn]);
    });

    return $http;
}

function paypalClient(FakeHttpClient $http, ?callable $cache = null, string $env = 'sandbox', string $webhookId = 'WH-123'): PayPalClient
{
    return new PayPalClient(paypalConfig($env, $webhookId), $http, $cache);
}

/**
 * Peticiones que no son de la ficha.
 *
 * @return HttpRequest[]
 */
function paypalApiCalls(FakeHttpClient $http): array
{
    return array_values(array_filter($http->requests, static function (HttpRequest $r): bool {
        return strpos($r->url, '/v1/oauth2/token') === false;
    }));
}

function paypalTokenCalls(FakeHttpClient $http): int
{
    return count($http->requests) - count(paypalApiCalls($http));
}

/** Pedido capturado, tal como lo devuelve PayPal (importe como cadena). */
function paypalCapturedOrder(string $value = '49.99', string $currency = 'EUR'): array
{
    return [
        'id' => '5O190127TN364715T',
        'status' => 'COMPLETED',
        'payer' => ['email_address' => 'cliente@example.test', 'payer_id' => 'QYR5Z8XDVJNXQ'],
        'purchase_units' => [[
            'reference_id' => 'default',
            'custom_id' => 'PED-1001',
            'payments' => ['captures' => [[
                'id' => '3C679366HH908993F',
                'status' => 'COMPLETED',
                'amount' => ['currency_code' => $currency, 'value' => $value],
                'custom_id' => 'PED-1001',
                'invoice_id' => 'PED-1001',
            ]]],
        ]],
    ];
}

/** Datos mínimos de un pedido nuevo. */
function paypalOrderInput(array $extra = []): array
{
    return $extra + [
        'reference' => 'PED-1001',
        'amount' => 4999,
        'currency' => 'EUR',
        'description' => 'Pedido PED-1001',
        'returnUrl' => 'https://tienda.test/pago/paypal/vuelta',
        'cancelUrl' => 'https://tienda.test/pago/paypal/cancelado',
    ];
}

function paypalBody(HttpRequest $request): array
{
    $data = json_decode((string) $request->body, true);

    return is_array($data) ? $data : [];
}
