<?php
/**
 * AIFEC — el cliente HTTP repite UNA vez cuando el DNS del servidor no resuelve el nombre. El 13/11/2026 el servidor de
 * demostración contestó «Could not resolve host: generativelanguage.googleapis.com» durante unos segundos y el asistente,
 * sin IA, contestó por reglas a quien estaba comprando. Ninguna prueba sale a la red: la ejecución de cURL se sustituye.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\CurlHttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;

/**
 * Un cliente cuyo cURL devuelve, por orden, lo que diga $script; $calls cuenta las ejecuciones.
 *
 * @param array<int,array{0:string|false,1:int,2:string}> $script
 */
function curlDnsClient(array $script, int &$calls, string $doh = ''): CurlHttpClient
{
    $calls = 0;

    return new CurlHttpClient('AIFEC-test', false, null, $doh, static function ($ch) use (&$script, &$calls): array {
        ++$calls;

        return $script !== [] ? array_shift($script) : [false, 7, 'no more answers'];
    });
}

test('un fallo de DNS se repite una vez y, si a la segunda resuelve, la respuesta llega como si nada', function (): void {
    if (!function_exists('curl_init')) {
        return;
    }
    $calls = 0;
    $client = curlDnsClient([[false, 6, 'Could not resolve host: api.ejemplo.test'], ['{"ok":true}', 0, '']], $calls);

    $response = $client->send(new HttpRequest('POST', 'https://api.ejemplo.test/v1', ['Content-Type' => 'application/json'], '{"a":1}', 5.0));

    same(2, $calls, 'dos ejecuciones: la que falló y la repetida');
    same('{"ok":true}', $response->body);
});

test('si el DNS falla las dos veces se lanza el error con su código, y no hay tercer intento', function (): void {
    if (!function_exists('curl_init')) {
        return;
    }
    $calls = 0;
    $client = curlDnsClient([[false, 6, 'Could not resolve host: api.ejemplo.test'], [false, 6, 'Could not resolve host: api.ejemplo.test'], ['tarde', 0, '']], $calls);

    $message = '';
    try {
        $client->send(new HttpRequest('GET', 'https://api.ejemplo.test/v1', [], null, 5.0));
    } catch (HttpException $e) {
        $message = $e->getMessage();
    }

    same(2, $calls);
    contains('Could not resolve host', $message);
    contains('(6)', $message);
});

test('si no se llega a conectar se repite una vez (por IPv4): no ha salido nada hacia el otro lado', function (): void {
    // 13/11/2026, en vivo: «Failed to connect to generativelanguage.googleapis.com port 443 after 10002 ms: Timeout was reached»
    // a ratos; el asistente esperaba diez segundos y contestaba por reglas.
    if (!function_exists('curl_init')) {
        return;
    }
    foreach ([
        [7, 'Failed to connect to api.ejemplo.test port 443: Connection refused'],
        [28, 'Failed to connect to api.ejemplo.test port 443 after 4001 ms: Timeout was reached'],
        [28, 'Connection timed out after 4001 milliseconds'],
        [60, "SSL: no alternative certificate subject name matches target host name 'api.ejemplo.test'"],
    ] as [$errno, $text]) {
        $calls = 0;
        $client = curlDnsClient([[false, $errno, $text], ['{"ok":true}', 0, '']], $calls);
        same('{"ok":true}', $client->send(new HttpRequest('POST', 'https://api.ejemplo.test/v1', [], '{"a":1}', 12.0))->body, $text);
        same(2, $calls, 'la que no conectó y la repetida');
    }
    $calls = 0;
    $client = curlDnsClient([[false, 7, 'Failed to connect'], [false, 7, 'Failed to connect'], ['tarde', 0, '']], $calls);
    $message = '';
    try {
        $client->send(new HttpRequest('POST', 'https://api.ejemplo.test/v1', [], 'x', 12.0));
    } catch (HttpException $e) {
        $message = $e->getMessage();
    }
    same(2, $calls, 'si tampoco conecta a la segunda, no hay tercera');
    contains('(7)', $message);
    same(true, CurlHttpClient::neverConnected(7, 'x'));
    same(false, CurlHttpClient::neverConnected(28, 'Operation timed out after 12000 milliseconds with 0 bytes received'), 'esperando la respuesta: la petición sí llegó');
    check(CurlHttpClient::CONNECT_MS <= 4000, 'conectar tiene su propio tope, corto');
});

test('cualquier otro fallo no se repite: la petición pudo haber llegado al otro lado', function (): void {
    if (!function_exists('curl_init')) {
        return;
    }
    foreach ([[28, 'Operation timed out after 5001 milliseconds with 0 bytes received'], [35, 'SSL connect error'], [52, 'Empty reply from server'], [56, 'Recv failure: Connection reset by peer']] as [$errno, $text]) {
        $calls = 0;
        $client = curlDnsClient([[false, $errno, $text], ['no debería llegar', 0, '']], $calls);
        $thrown = false;
        try {
            $client->send(new HttpRequest('POST', 'https://api.ejemplo.test/v1', [], 'x', 5.0));
        } catch (HttpException $e) {
            $thrown = strpos($e->getMessage(), '(' . $errno . ')') !== false;
        }
        same(1, $calls, 'un solo intento con el error ' . $errno);
        check($thrown, 'y se lanza con su código: ' . $errno);
    }
});

test('una respuesta buena a la primera no se repite', function (): void {
    if (!function_exists('curl_init')) {
        return;
    }
    $calls = 0;
    $client = curlDnsClient([['hola', 0, '']], $calls, 'https://doh.ejemplo.test/dns-query');

    same('hola', $client->send(new HttpRequest('GET', 'https://api.ejemplo.test/', [], null, 5.0))->body);
    same(1, $calls);
});
