<?php
/**
 * AIFEC — pruebas de la ficha de acceso, la configuración y los importes de PayPal.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Payments\PayPal\PayPalClient;
use Aifec\Payments\PayPal\PayPalConfig;

require_once __DIR__ . '/helpers.php';

test('la ficha se pide una vez con autenticación básica y se reutiliza', function (): void {
    $http = paypalHttp();
    $client = paypalClient($http);

    same(PAYPAL_TEST_TOKEN, $client->accessToken(1000));
    same(PAYPAL_TEST_TOKEN, $client->accessToken(1500));
    same(1, paypalTokenCalls($http), 'solo una petición de ficha');

    $r = $http->requests[0];
    same('POST', $r->method);
    same('https://api-m.sandbox.paypal.com/v1/oauth2/token', $r->url);
    same('Basic ' . base64_encode('CLIENT-ID:' . PAYPAL_TEST_SECRET), $r->headers['Authorization']);
    same('grant_type=client_credentials', $r->body);
    same('application/x-www-form-urlencoded', $r->headers['Content-Type']);
});

test('la ficha caducada, o a menos de 60 segundos de caducar, se vuelve a pedir', function (): void {
    $http = paypalHttp(3600);
    $client = paypalClient($http);

    $client->accessToken(1000);          // caduca en 4600; se renueva desde 4540
    $client->accessToken(4539);
    same(1, paypalTokenCalls($http), 'a 61 s del final aún vale');
    $client->accessToken(4540);
    same(2, paypalTokenCalls($http), 'a 60 s del final ya se renueva');
    $client->accessToken(99999);
    same(3, paypalTokenCalls($http));
});

test('una caché de fichas externa se lee y se escribe', function (): void {
    $store = null;
    $cache = function (?array $set = null) use (&$store): ?array {
        if ($set !== null) {
            $store = $set;
        }

        return $store;
    };

    $http = paypalHttp(3600);
    paypalClient($http, $cache)->accessToken(1000);
    same(['token' => PAYPAL_TEST_TOKEN, 'expires' => 4600], $store);

    // Otra instancia (otra petición web) reutiliza la guardada sin llamar a PayPal.
    $http2 = paypalHttp();
    same(PAYPAL_TEST_TOKEN, paypalClient($http2, $cache)->accessToken(2000));
    same(0, count($http2->requests));

    // Una caché con basura se ignora.
    $junk = function (?array $set = null): ?array {
        return ['token' => 123];
    };
    $http3 = paypalHttp();
    paypalClient($http3, $junk)->accessToken(2000);
    same(1, paypalTokenCalls($http3));
});

test('la configuración elige la dirección y rechaza entornos raros', function (): void {
    same('https://api-m.sandbox.paypal.com', paypalConfig('sandbox')->baseUrl());
    same('https://api-m.paypal.com', paypalConfig('live')->baseUrl());
    same(true, paypalConfig('live')->isLive());
    throws(function (): void {
        new PayPalConfig('a', 'b', 'produccion');
    }, \InvalidArgumentException::class);

    $http = paypalHttp();
    paypalClient($http, null, 'live')->accessToken(1);
    same('https://api-m.paypal.com/v1/oauth2/token', $http->requests[0]->url);
});

test('los importes pasan de céntimos a cadena decimal y vuelta sin float', function (): void {
    same('49.99', PayPalClient::formatAmount(4999, 'EUR'));
    same('0.05', PayPalClient::formatAmount(5, 'EUR'));
    same('0.00', PayPalClient::formatAmount(0, 'eur'));
    same('100.00', PayPalClient::formatAmount(10000, 'USD'));
    same('-1.50', PayPalClient::formatAmount(-150, 'EUR'));
    same('500', PayPalClient::formatAmount(500, 'JPY'));
    same('1200', PayPalClient::formatAmount(1200, 'HUF'));
    same('300', PayPalClient::formatAmount(300, 'TWD'));

    same(4999, PayPalClient::parseAmount('49.99', 'EUR'));
    same(10, PayPalClient::parseAmount('0.10', 'EUR'));
    same(10, PayPalClient::parseAmount('0.1', 'EUR'));
    same(10000, PayPalClient::parseAmount('100', 'EUR'));
    same(10000, PayPalClient::parseAmount('100.00', 'EUR'));
    same(-150, PayPalClient::parseAmount('-1.50', 'EUR'));
    same(500, PayPalClient::parseAmount('500', 'JPY'));
    same(1999, PayPalClient::parseAmount('19.99', 'EUR'), '19.99 es el caso clásico en que el float falla');
    same(29, PayPalClient::parseAmount('0.29', 'EUR'));
    throws(function (): void {
        PayPalClient::parseAmount('abc', 'EUR');
    }, \InvalidArgumentException::class);
    throws(function (): void {
        PayPalClient::parseAmount(12.5, 'EUR');
    }, \InvalidArgumentException::class);
});
