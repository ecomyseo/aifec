<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Ayudas de las pruebas de la API y del MCP. No es una prueba (no acaba en «Test.php»): se carga con require_once.
 *
 *   apiApp(array $ajustes = [], array $modulos = []): App
 *       Tienda de pruebas con la API, el asistente, el buscador, el carrito y los pedidos (chatApp): dos formas de pago, «gw»
 *       (pasarela: se paga en línea) y «transfer» (transferencia), y el transportista «std». $modulos se suma a los cargados.
 *
 *   apiCall(App $app, string $metodo, string $ruta, $cuerpo = null, array $cabeceras = [], string $ip = '127.0.0.1'): Response
 *       Una petición como la haría un programa: sin cookies, con el cuerpo en JSON si se da un array.
 *
 *   apiJson(Response $r): array
 *       El cuerpo de la respuesta como array.
 *
 *   apiKey(App $app, array $ambitos = [], string $nombre = 'Agente de pruebas'): string
 *       Da de alta una clave y devuelve la clave en claro.
 *
 *   apiCart(App $app, array $lineas = [], array $cabeceras = []): string
 *       Crea un carrito por la API y le añade las líneas ([[producto, variante, unidades], …]); devuelve su ficha.
 *
 *   apiBuy(App $app, string $token, string $pago = 'transfer'): array
 *       Rellena la caja de ese carrito (datos de contacto, el primer envío y la forma de pago) y devuelve la revisión (data).
 *
 *   apiPlace(App $app, string $token, string $key, array $cuerpo, array $extra = []): Response
 *       Hace el pedido por la API con la ficha del carrito y la clave.
 *
 *   apiOrders(App $app): array
 *       Las filas de orders, de la más antigua a la más nueva.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Api\Keys;

require_once __DIR__ . '/../../assistant/tests/OrderChatKit.php';

if (!function_exists('apiApp')) {
    /**
     * @param array<string,mixed> $ajustes
     * @param string[]            $modulos
     */
    function apiApp(array $ajustes = [], array $modulos = []): App
    {
        return chatApp($ajustes, ['modules' => array_merge(['api'], $modulos)]);
    }

    /**
     * @param array<string,mixed>|string|null $cuerpo
     * @param array<string,string>            $cabeceras
     */
    function apiCall(App $app, string $metodo, string $ruta, $cuerpo = null, array $cabeceras = [], string $ip = '127.0.0.1'): Response
    {
        $raw = $cuerpo === null ? '' : (is_string($cuerpo) ? $cuerpo : (string) json_encode($cuerpo));
        $request = Request::create($metodo, $ruta, [], [], $cabeceras + ($raw !== '' ? ['content-type' => 'application/json'] : []), $raw);
        $request->host = 'tienda.test';
        $request->secure = true;
        $request->ip = $ip;

        return $app->handle($request);
    }

    /**
     * @return array<string,mixed>
     */
    function apiJson(Response $r): array
    {
        $data = json_decode($r->body, true);
        check(is_array($data), 'el cuerpo es JSON: ' . substr($r->body, 0, 200));

        return $data;
    }

    /**
     * @param string[] $ambitos
     */
    function apiKey(App $app, array $ambitos = [], string $nombre = 'Agente de pruebas'): string
    {
        $app->boot();
        $key = Keys::generate();
        $app->db()->insert('api_keys', [
            'name' => $nombre, 'key_hash' => Keys::hash($key), 'scopes' => (string) json_encode($ambitos), 'active' => 1, 'created_at' => $app->clock()->sql(),
        ]);

        return $key;
    }

    /**
     * @param array<int,array<int,int>> $lineas
     * @param array<string,string>      $cabeceras
     */
    function apiCart(App $app, array $lineas = [], array $cabeceras = []): string
    {
        $r = apiCall($app, 'POST', '/api/v1/carts', null, $cabeceras);
        same(201, $r->status, 'el carrito se crea');
        $token = (string) apiJson($r)['data']['token'];
        foreach ($lineas as $linea) {
            $added = apiCall($app, 'POST', '/api/v1/carts/current/items', ['product_id' => $linea[0], 'variant_id' => isset($linea[1]) ? $linea[1] : 0, 'qty' => isset($linea[2]) ? $linea[2] : 1], ['x-cart-token' => $token] + $cabeceras);
            same(201, $added->status, 'la línea se añade: ' . $added->body);
        }

        return $token;
    }

    /**
     * @return array<string,mixed>
     */
    function apiBuy(App $app, string $token, string $pago = 'transfer'): array
    {
        $h = ['x-cart-token' => $token];
        $customer = apiCall($app, 'POST', '/api/v1/carts/checkout/customer', ['text' => CHAT_DATA], $h);
        same(200, $customer->status, 'los datos se guardan: ' . $customer->body);
        $options = apiJson(apiCall($app, 'POST', '/api/v1/carts/checkout/shipping-options', null, $h))['data']['options'];
        check(count($options) >= 1, 'hay envío');
        $shipping = apiCall($app, 'POST', '/api/v1/carts/checkout/shipping', ['carrier_id' => $options[0]['id']], $h);
        same(200, $shipping->status, 'el envío se elige: ' . $shipping->body);
        $payment = apiCall($app, 'POST', '/api/v1/carts/checkout/payment', ['method' => $pago], $h);
        same(200, $payment->status, 'el pago se elige: ' . $payment->body);
        $review = apiCall($app, 'POST', '/api/v1/carts/checkout/review', null, $h);
        same(200, $review->status, 'la revisión sale: ' . $review->body);

        return apiJson($review)['data'];
    }

    /**
     * POST /api/v1/carts/checkout/place con la ficha del carrito y la clave dadas.
     *
     * @param array<string,mixed>  $cuerpo
     * @param array<string,string> $extra
     */
    function apiPlace(App $app, string $token, string $key, array $cuerpo, array $extra = []): Response
    {
        return apiCall($app, 'POST', '/api/v1/carts/checkout/place', $cuerpo, ['x-cart-token' => $token, 'authorization' => 'Bearer ' . $key] + $extra);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    function apiOrders(App $app): array
    {
        return $app->db()->select('SELECT * FROM {orders} ORDER BY id');
    }
}
