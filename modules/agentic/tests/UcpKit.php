<?php
/**
 * AIFEC — piezas de las pruebas de UCP. No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
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
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\PaymentsRedsys\RedsysSettings;

require_once __DIR__ . '/../../payments-redsys/tests/RedsysKit.php';

/** Un token de Google Pay de mentira (la forma de uno de verdad, sin nada que valga). */
if (!defined('UCP_GPAY_TOKEN')) {
    define('UCP_GPAY_TOKEN', '{"signature":"MEUCIQ-de-mentira","protocolVersion":"ECv2","signedMessage":"{\"encryptedMessage\":\"cifrado-de-mentira\",\"tag\":\"t\"}"}');
}

if (!function_exists('ucpApp')) {
    /**
     * Una tienda con Redsys de pruebas, Google Pay y UCP encendidos, y un producto de 20 € (200 g).
     *
     * @param array<string,mixed> $ajustes
     *
     * @return array{0:App,1:int}
     */
    function ucpApp(array $ajustes = []): array
    {
        $ajustes += [
            'modules.active' => ['orders', 'mail', 'media', 'jobs', 'payments-redsys', 'agentic'],
            'agentic.ucp_enabled' => true,
            RedsysSettings::GOOGLEPAY => true,
        ];
        $app = aifecOrderApp(['payment' => false, 'modules' => ['payments-redsys', 'agentic'], 'settings' => $ajustes]);
        $app->settings()->setMany([RedsysSettings::ENVIRONMENT => 'test', RedsysSettings::MERCHANT_CODE => RDS_MERCHANT, RedsysSettings::TERMINAL => '1']);
        $app->setSecret(RedsysSettings::SECRET_TEST, RDS_KEY);
        $product = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);

        return [$app, $product];
    }

    /**
     * @param array<string,mixed>|string|null $cuerpo
     * @param array<string,string>            $cabeceras
     */
    function ucpCall(App $app, string $metodo, string $ruta, $cuerpo = null, array $cabeceras = [], string $ip = '203.0.113.10'): Response
    {
        $raw = $cuerpo === null ? '' : (is_string($cuerpo) ? $cuerpo : (string) json_encode($cuerpo === [] ? new \stdClass() : $cuerpo));
        $request = Request::create($metodo, $ruta, [], [], $cabeceras + ($raw !== '' ? ['content-type' => 'application/json'] : []) + ['ucp-agent' => 'profile="https://plataforma.test/profile"'], $raw);
        $request->host = 'tienda.test';
        $request->secure = true;
        $request->ip = $ip;

        return $app->handle($request);
    }

    /** @return array<string,mixed> */
    function ucpJson(Response $r): array
    {
        $data = json_decode($r->body, true);
        check(is_array($data), 'el cuerpo es JSON: ' . substr($r->body, 0, 300));

        return $data;
    }

    /** Una clave de la API con esos permisos. */
    function ucpKey(App $app, array $ambitos = ['orders:create']): string
    {
        $key = 'aifec_api_' . bin2hex(random_bytes(16));
        $app->db()->insert('api_keys', [
            'name' => 'Plataforma de pruebas', 'key_hash' => hash('sha256', $key), 'scopes' => (string) json_encode($ambitos), 'active' => 1, 'created_at' => $app->clock()->sql(),
        ]);

        return $key;
    }

    /**
     * Lo que manda una plataforma al crear la compra: el producto, el comprador y la dirección (inventados).
     *
     * @return array<string,mixed>
     */
    function ucpBody(int $producto, int $unidades = 2, bool $comprador = true): array
    {
        $body = [
            'line_items' => [['item' => ['id' => (string) $producto], 'quantity' => $unidades]],
            'fulfillment' => ['methods' => [[
                'type' => 'shipping',
                'destinations' => [[
                    'id' => 'dest_casa', 'first_name' => 'Ana', 'last_name' => 'Ruiz', 'street_address' => 'Calle de Ejemplo 1',
                    'address_locality' => 'Madrid', 'postal_code' => '28013', 'address_country' => 'ES', 'phone_number' => '999000201',
                ]],
            ]]],
        ];
        if ($comprador) {
            $body['buyer'] = ['email' => 'ana@cliente.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz'];
        }

        return $body;
    }

    /** @return array<string,mixed> el pago con Google Pay por el manejador de la tienda */
    function ucpPayment(string $handler = RedsysSettings::GOOGLEPAY_HANDLER_ID): array
    {
        return ['payment' => ['instruments' => [[
            'id' => 'pi_1', 'handler_id' => $handler, 'type' => 'card', 'selected' => true,
            'display' => ['brand' => 'visa', 'last_digits' => '4242', 'description' => 'Visa •••• 4242'],
            'credential' => ['type' => 'PAYMENT_GATEWAY', 'token' => UCP_GPAY_TOKEN],
        ]]], 'signals' => ['dev.ucp.buyer_ip' => '198.51.100.23']];
    }

    /** La respuesta firmada del banco a un cobro: saca el número de pedido de Redsys de la propia petición. */
    function ucpBank(string $response = '0000'): callable
    {
        return static function (HttpRequest $request) use ($response): HttpResponse {
            $body = json_decode((string) $request->body, true);
            $params = json_decode((string) base64_decode((string) $body['Ds_MerchantParameters'], true), true);
            $data = [
                'Ds_Amount' => (string) (int) $params['DS_MERCHANT_AMOUNT'], 'Ds_Currency' => '978', 'Ds_Order' => (string) $params['DS_MERCHANT_ORDER'],
                'Ds_MerchantCode' => RDS_MERCHANT, 'Ds_Terminal' => '001', 'Ds_Response' => $response, 'Ds_AuthorisationCode' => '654321',
                'Ds_TransactionType' => '0', 'Ds_SecurePayment' => '1', 'Ds_Language' => '1',
            ];

            return new HttpResponse(200, ['Content-Type' => 'application/json'], (string) json_encode(rdsSigned($data)));
        };
    }

    /** Un segundo transportista, «Urgente» (12,95 € a la Península), para que haya que elegir. Devuelve su id. */
    function ucpFast(App $app): int
    {
        $fast = shippingAddCarrier($app, 'fast', ['name' => 'Urgente', 'delay' => '24 h']);
        foreach (['Peninsula' => 1295, 'Balearic Islands' => 1995, 'Canary Islands' => 2995, 'Ceuta and Melilla' => 2495] as $zone => $price) {
            shippingAddRate($app, $fast, shippingZoneId($app, $zone), 'weight', 0, 0, $price);
        }

        return $fast;
    }

    function ucpHttp(App $app): FakeHttpClient
    {
        $http = $app->http();
        check($http instanceof FakeHttpClient, 'las pruebas no llaman a ningún banco ni a ninguna plataforma de verdad');

        return $http;
    }

    /** @return array<int,array<string,mixed>> los pedidos de la tienda */
    function ucpOrders(App $app): array
    {
        return $app->db()->select('SELECT id, number, status, payment_status, payment_method, source, attribution, total, ip FROM {orders} ORDER BY id');
    }

    /**
     * @param array<string,mixed> $view
     *
     * @return string[] los códigos de los avisos
     */
    function ucpCodes(array $view): array
    {
        $codes = [];
        foreach (isset($view['messages']) && is_array($view['messages']) ? $view['messages'] : [] as $message) {
            $codes[] = (string) $message['code'];
        }

        return $codes;
    }

    /**
     * @param array<string,mixed> $view
     */
    function ucpTotal(array $view, string $tipo): ?int
    {
        foreach ($view['totals'] as $total) {
            if ($total['type'] === $tipo) {
                return (int) $total['amount'];
            }
        }

        return null;
    }
}
