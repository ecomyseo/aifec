<?php
/**
 * AIFEC — apoyo de las pruebas del módulo de pago por PayPal.
 *
 * No es una prueba (no acaba en «Test.php»): la cargan las pruebas con require_once.
 *
 *   ppApp(array $ajustes = [], bool $claves = true): App
 *       Aplicación de pedidos con el módulo de PayPal encendido, el entorno sandbox, su identificador y su secreto (cifrado,
 *       como lo hace el panel) y el identificador del aviso. Con $claves = false no hay ninguna credencial.
 *
 *   ppFake(App $app): PpFake
 *       El cliente HTTP de mentira de la aplicación, ya preparado para contestar como PayPal: ficha de acceso, crear orden,
 *       capturar, devolver y verificar avisos. Sus propiedades (createResponse, captureResponse…) se cambian para provocar
 *       un fallo. Apunta cada orden creada para que la captura devuelva lo mismo que se pidió.
 *
 *   ppPlace(App $app, TestClient $web, int $producto): array
 *       En UNA petición: añade el producto al carrito, elige PayPal y llama a Orders::place().
 *
 *   ppOrder(array $ajustes = []): array
 *       Un pedido recién hecho. Devuelve [app, PpFake, producto, resultado de place(), id de la orden de PayPal].
 *
 *   ppBack(TestClient $web, array $placed, string $token = ''): Response
 *       La vuelta del cliente desde PayPal (con la orden del intento por defecto).
 *
 *   ppNotify(App $app, PpFake $fake, array $event, bool $firma = true, bool $cabeceras = true): Response
 *       Lo que hace el servidor de PayPal: un POST sin sesión ni ficha, con las cabeceras de firma y el cuerpo tal cual.
 *
 *   ppCompleted(array $order, string $paypalOrder, string $captureId = PP_CAPTURE): array
 *       El evento PAYMENT.CAPTURE.COMPLETED de ese pedido.
 *
 *   ppCalls(PpFake $fake, string $metodo, string $trozo): array
 *       Las peticiones que se han hecho a PayPal con ese método y ese trozo de dirección.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Commerce\Money;
use Aifec\Commerce\PaymentAction;
use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\PaymentsPaypal\PayPalSettings;

require_once __DIR__ . '/../../orders/tests/helpers.php';

if (!defined('PP_CLIENT')) {
    define('PP_CLIENT', 'CLIENT-ID-SANDBOX-1');
    define('PP_SECRET', 'S3CRET-sandbox-do-not-leak');
    define('PP_LIVE_CLIENT', 'CLIENT-ID-LIVE-1');
    define('PP_LIVE_SECRET', 'S3CRET-live-do-not-leak');
    define('PP_TOKEN', 'A21AAF-token-do-not-leak');
    define('PP_WEBHOOK', 'WHSANDBOX12345');
    define('PP_LIVE_WEBHOOK', 'WHLIVE123456789');
    define('PP_CAPTURE', '3C679366HH908993F');
}

if (!class_exists('PpFake')) {
    /**
     * El PayPal de mentira. Cada «…Response» es una HttpResponse, una HttpException (fallo de red) o un callable que recibe la
     * petición y devuelve una de las dos; null = la respuesta por defecto.
     */
    final class PpFake
    {
        /** @var FakeHttpClient */
        public $http;

        /** @var mixed */
        public $tokenResponse;

        /** @var mixed */
        public $createResponse;

        /** @var mixed */
        public $captureResponse;

        /** @var mixed */
        public $refundResponse;

        /** @var mixed */
        public $verifyResponse;

        /** @var mixed la consulta de una orden (GET); null = la orden tal como quedaría tras aprobarla */
        public $getResponse;

        /** @var int */
        public $counter = 0;

        /** @var int devoluciones hechas */
        public $refunds = 0;

        /** @var array<string,array<string,string>> lo que se pidió al crear cada orden, por identificador de PayPal */
        public $created = [];

        public function __construct(FakeHttpClient $http)
        {
            $this->http = $http;
            $http->when('POST', '/v1/oauth2/token', function (HttpRequest $r): HttpResponse {
                return $this->answer($this->tokenResponse, $r, function (): HttpResponse {
                    return ppJson(200, ['access_token' => PP_TOKEN, 'token_type' => 'Bearer', 'expires_in' => 32400]);
                });
            });
            // «/capture» a secas también estaría en «/captures/…/refund»: la captura de una orden acaba en «/capture».
            $http->when('POST', '/capture', function (HttpRequest $r): HttpResponse {
                if (strpos($r->url, '/refund') !== false) {
                    return $this->answer($this->refundResponse, $r, function (HttpRequest $r): HttpResponse {
                        return $this->defaultRefund($r);
                    });
                }

                return $this->answer($this->captureResponse, $r, function (HttpRequest $r): HttpResponse {
                    return $this->defaultCapture($r);
                });
            });
            $http->when('POST', '/verify-webhook-signature', function (HttpRequest $r): HttpResponse {
                return $this->answer($this->verifyResponse, $r, function (): HttpResponse {
                    return ppJson(200, ['verification_status' => 'SUCCESS']);
                });
            });
            $http->when('POST', '/v2/checkout/orders', function (HttpRequest $r): HttpResponse {
                return $this->answer($this->createResponse, $r, function (HttpRequest $r): HttpResponse {
                    return $this->defaultCreate($r);
                });
            });
            $http->when('GET', '/v2/checkout/orders/', function (HttpRequest $r): HttpResponse {
                return $this->answer($this->getResponse, $r, function (HttpRequest $r): HttpResponse {
                    return $this->defaultGet($r);
                });
            });
        }

        /**
         * La orden consultada: una forma local ya aprobada queda COMPLETED con su captura COMPLETED (PayPal la cobra al
         * aprobarla); la cartera y la tarjeta, APPROVED y sin captura.
         */
        private function defaultGet(HttpRequest $r): HttpResponse
        {
            preg_match('#/v2/checkout/orders/([^/?]+)#', $r->url, $m);
            $id = isset($m[1]) ? rawurldecode($m[1]) : '';
            if (!isset($this->created[$id])) {
                return ppJson(404, ['name' => 'RESOURCE_NOT_FOUND', 'message' => 'The specified resource does not exist.', 'debug_id' => 'dbg-404']);
            }
            $o = $this->created[$id];
            if (in_array($o['source'], ['paypal', ''], true)) {
                return ppJson(200, ['id' => $id, 'status' => 'APPROVED', 'purchase_units' => [['custom_id' => $o['custom_id'], 'amount' => ['currency_code' => $o['currency'], 'value' => $o['value']]]]]);
            }

            return ppJson(200, ppLocalOrder($id, $o['source'], 'COMPLETED', 'COMPLETED', $o['value'], $o['currency'], $o['custom_id']));
        }

        /**
         * @param mixed $custom
         */
        private function answer($custom, HttpRequest $request, callable $default): HttpResponse
        {
            if (is_callable($custom)) {
                $custom = $custom($request);
            }
            if ($custom instanceof HttpException) {
                throw $custom;
            }
            if ($custom instanceof HttpResponse) {
                return $custom;
            }

            return $default($request);
        }

        private function defaultCreate(HttpRequest $r): HttpResponse
        {
            ++$this->counter;
            $id = 'PPORDER' . $this->counter;
            $body = ppBody($r);
            $unit = $body['purchase_units'][0];
            $sources = isset($body['payment_source']) && is_array($body['payment_source']) ? array_keys($body['payment_source']) : [];
            $source = isset($sources[0]) ? (string) $sources[0] : '';
            $this->created[$id] = ['value' => $unit['amount']['value'], 'currency' => $unit['amount']['currency_code'], 'custom_id' => $unit['custom_id'], 'source' => $source];
            // La cartera y la tarjeta van a la página de PayPal; las locales, a la de su banco (payment/<forma>).
            $action = $source === 'paypal' || $source === '' ? 'https://www.sandbox.paypal.com/checkoutnow?token=' . $id : 'https://www.sandbox.paypal.com/payment/' . $source . '?token=' . $id;

            return ppJson(201, ['id' => $id, 'status' => 'PAYER_ACTION_REQUIRED', 'links' => [
                ['href' => 'https://api-m.sandbox.paypal.com/v2/checkout/orders/' . $id, 'rel' => 'self', 'method' => 'GET'],
                ['href' => $action, 'rel' => 'payer-action', 'method' => 'GET'],
            ]]);
        }

        private function defaultRefund(HttpRequest $r): HttpResponse
        {
            $body = ppBody($r);
            $amount = isset($body['amount']) ? $body['amount'] : ['currency_code' => 'EUR', 'value' => '0.00'];
            ++$this->refunds;

            // Un identificador distinto en cada devolución, como los de PayPal; la primera es «1JU08902781691411».
            return ppJson(201, ['id' => '1JU0890278' . (1691411 + $this->refunds - 1), 'status' => 'COMPLETED', 'amount' => $amount]);
        }

        private function defaultCapture(HttpRequest $r): HttpResponse
        {
            preg_match('#/v2/checkout/orders/([^/]+)/capture#', $r->url, $m);
            $id = isset($m[1]) ? rawurldecode($m[1]) : '';
            if (!isset($this->created[$id])) {
                return ppJson(404, ['name' => 'RESOURCE_NOT_FOUND', 'message' => 'The specified resource does not exist.', 'debug_id' => 'dbg-404']);
            }
            $o = $this->created[$id];

            return ppJson(201, ppCapturedOrder($id, $o['value'], $o['currency'], $o['custom_id']));
        }
    }
}

if (!function_exists('ppApp')) {
    /**
     * @param array<string,mixed> $ajustes
     */
    function ppApp(array $ajustes = [], bool $claves = true): App
    {
        // El módulo no viene encendido de serie: se enciende como lo haría el panel.
        $ajustes += ['modules.active' => ['orders', 'mail', 'media', 'jobs', 'payments-paypal']];
        $app = aifecOrderApp(['payment' => false, 'modules' => ['payments-paypal'], 'settings' => $ajustes]);
        // El entorno de serie es el sandbox; una prueba que quiera «live» lo pasa en $ajustes.
        if ($claves) {
            $app->settings()->setMany([
                PayPalSettings::CLIENT_ID_SANDBOX => PP_CLIENT, PayPalSettings::WEBHOOK_ID_SANDBOX => PP_WEBHOOK,
                PayPalSettings::CLIENT_ID_LIVE => PP_LIVE_CLIENT, PayPalSettings::WEBHOOK_ID_LIVE => PP_LIVE_WEBHOOK,
            ]);
            $app->setSecret(PayPalSettings::SECRET_SANDBOX, PP_SECRET);
            $app->setSecret(PayPalSettings::SECRET_LIVE, PP_LIVE_SECRET);
        }

        return $app;
    }

    function ppFake(App $app): PpFake
    {
        $http = $app->http();
        check($http instanceof FakeHttpClient, 'las pruebas no llaman a ningún PayPal de verdad');

        return new PpFake($http);
    }

    /**
     * @param array<string,mixed> $data
     */
    function ppJson(int $status, array $data): HttpResponse
    {
        return new HttpResponse($status, ['Content-Type' => 'application/json'], (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @return array<string,mixed>
     */
    function ppBody(HttpRequest $request): array
    {
        $data = json_decode((string) $request->body, true);

        return is_array($data) ? $data : [];
    }

    /**
     * Una orden capturada tal como la devuelve PayPal (importe como cadena). $estado es el de la captura.
     *
     * @return array<string,mixed>
     */
    function ppCapturedOrder(string $id, string $value, string $currency, string $customId, string $estado = 'COMPLETED'): array
    {
        return [
            'id' => $id,
            'status' => $estado === 'COMPLETED' ? 'COMPLETED' : 'APPROVED',
            'payer' => ['email_address' => 'comprador@example.test', 'payer_id' => 'QYR5Z8XDVJNXQ', 'name' => ['given_name' => 'Ana']],
            'purchase_units' => [[
                'reference_id' => 'default',
                'custom_id' => $customId,
                'payments' => ['captures' => [[
                    'id' => 'CAP-' . $id, 'status' => $estado, 'amount' => ['currency_code' => $currency, 'value' => $value],
                    'custom_id' => $customId, 'invoice_id' => $customId,
                ]]],
            ]],
        ];
    }

    /** La página de gracias de mentira, para que la vuelta tenga a dónde redirigir. */
    function ppThanksRoute(App $app): void
    {
        $app->router()->get('/pedido/{number}/{secret}', static function (Request $request, App $app): Response {
            return Response::text('gracias');
        })->name('order.thanks');
    }

    function ppPlace(App $app, TestClient $web, int $producto): array
    {
        return aifecCartRun($web, static function ($cart, App $app) use ($producto): array {
            $added = $cart->add($producto, 0, 1);
            check(!empty($added['ok']), 'la línea se añade al carrito: ' . (isset($added['error']) ? (string) $added['error'] : ''));
            $cart->setPaymentMethod('paypal');

            return $app->service('orders')->place(aifecOrderCheckout());
        });
    }

    /**
     * @param array<string,mixed> $ajustes
     *
     * @return array{0:App,1:PpFake,2:int,3:array<string,mixed>,4:string}
     */
    function ppOrder(array $ajustes = []): array
    {
        $app = ppApp($ajustes);
        $fake = ppFake($app);
        $p = aifecProduct($app, ['name' => 'Camiseta', 'price' => 2000, 'stock' => 10, 'weight' => 200]);
        $placed = ppPlace($app, new TestClient($app), $p);
        check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
        check($placed['action'] instanceof PaymentAction && $placed['action']->type === PaymentAction::REDIRECT, 'la caja recibe una redirección a PayPal');

        return [$app, $fake, $p, $placed, ppAttempt($app, $placed)['reference']];
    }

    /**
     * La fila del intento de un pedido.
     *
     * @param array<string,mixed> $placed
     *
     * @return array<string,mixed>
     */
    function ppAttempt(App $app, array $placed): array
    {
        $row = $app->db()->row("SELECT * FROM {order_payments} WHERE order_id = ? AND kind = 'attempt'", [(int) $placed['id']]);
        check($row !== null, 'el intento está apuntado');

        return $row;
    }

    /**
     * @param array<string,mixed> $placed
     */
    function ppBack(TestClient $web, array $placed, string $token = ''): Response
    {
        if ($token === '') {
            $token = ppAttempt($web->app, $placed)['reference'];
        }

        return $web->get('/pago/paypal/vuelta/' . $placed['number'] . '/' . $placed['secret'] . '?token=' . rawurlencode($token));
    }

    /**
     * @param array<string,mixed> $placed
     *
     * @return array<string,mixed>
     */
    function ppOrderRow(App $app, array $placed): array
    {
        $order = $app->service('orders')->find((int) $placed['id']);
        check($order !== null, 'el pedido existe');

        return $order;
    }

    /**
     * Lo que hace el servidor de PayPal al avisar: un POST sin sesión ni ficha, con las cabeceras de firma y el cuerpo tal cual.
     *
     * @param array<string,mixed> $event
     */
    function ppNotify(App $app, PpFake $fake, array $event, bool $firma = true, bool $cabeceras = true): Response
    {
        $fake->verifyResponse = ppJson(200, ['verification_status' => $firma ? 'SUCCESS' : 'FAILURE']);
        $headers = ['user-agent' => 'PayPal/AUHD-214', 'content-type' => 'application/json'];
        if ($cabeceras) {
            $headers += [
                'paypal-auth-algo' => 'SHA256withRSA', 'paypal-cert-url' => 'https://api.sandbox.paypal.com/v1/notifications/certs/CERT-360caa42',
                'paypal-transmission-id' => 'f7d3a1b0-0000-0000-0000-000000000001', 'paypal-transmission-sig' => 'firma-de-mentira',
                'paypal-transmission-time' => '2026-11-12T10:30:00Z',
            ];
        }
        $request = Request::create('POST', '/pago/paypal/aviso', [], [], $headers, (string) json_encode($event, JSON_UNESCAPED_SLASHES));
        $request->host = 'tienda.test';
        $request->secure = true;

        return $app->handle($request);
    }

    /**
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    function ppCompleted(array $order, string $paypalOrder, string $captureId = PP_CAPTURE): array
    {
        return [
            'id' => 'WH-EVT-' . $captureId, 'event_type' => 'PAYMENT.CAPTURE.COMPLETED', 'resource_type' => 'capture',
            'resource' => [
                'id' => $captureId, 'status' => 'COMPLETED', 'amount' => ['currency_code' => (string) $order['currency'], 'value' => Money::decimal((int) $order['total'])],
                'custom_id' => (string) $order['number'], 'invoice_id' => (string) $order['number'],
                'supplementary_data' => ['related_ids' => ['order_id' => $paypalOrder]],
            ],
        ];
    }

    /**
     * Las peticiones hechas a PayPal con ese método y ese trozo de dirección.
     *
     * @return HttpRequest[]
     */
    function ppCalls(PpFake $fake, string $metodo, string $trozo): array
    {
        return array_values(array_filter($fake->http->requests, static function (HttpRequest $r) use ($metodo, $trozo): bool {
            return $r->method === $metodo && strpos($r->url, $trozo) !== false;
        }));
    }

    /**
     * Un pedido ya cobrado por PayPal con el aviso. Devuelve [app, PpFake, resultado de place(), id del pedido].
     *
     * @return array{0:App,1:PpFake,2:array<string,mixed>,3:int}
     */
    function ppPaidOrder(): array
    {
        list($app, $fake, $p, $placed, $paypalOrder) = ppOrder();
        $order = ppOrderRow($app, $placed);
        same('OK', ppNotify($app, $fake, ppCompleted($order, $paypalOrder))->body);
        same('paid', ppOrderRow($app, $placed)['payment_status']);

        return [$app, $fake, $placed, (int) $placed['id']];
    }

    /**
     * Los cobros de un pedido de un tipo dado.
     *
     * @param array<string,mixed> $order
     *
     * @return array<int,array<string,mixed>>
     */
    function ppPayments(array $order, string $tipo): array
    {
        $out = [];
        foreach ($order['payments'] as $payment) {
            if ((string) $payment['kind'] === $tipo) {
                $out[] = $payment;
            }
        }

        return $out;
    }
}

if (!function_exists('ppForm')) {
    /**
     * Los campos del formulario de ajustes tal como los manda el navegador (las tres pestañas van en el mismo formulario).
     *
     * @param array<string,mixed> $cambios
     *
     * @return array<string,mixed>
     */
    function ppForm(array $cambios = []): array
    {
        return $cambios + [
            PayPalSettings::ENVIRONMENT => 'sandbox', PayPalSettings::TITLE => '',
            PayPalSettings::CLIENT_ID_SANDBOX => PP_CLIENT, PayPalSettings::SECRET_SANDBOX => '', PayPalSettings::WEBHOOK_ID_SANDBOX => PP_WEBHOOK,
            PayPalSettings::CLIENT_ID_LIVE => '', PayPalSettings::SECRET_LIVE => '', PayPalSettings::WEBHOOK_ID_LIVE => '',
        ] + ppSourcesForm();
    }

    /**
     * La pestaña «Formas de pago» tal como la manda el navegador sin tocarla: cada casilla lleva su campo oculto «0» y, si está
     * marcada, también el «1» (PHP se queda con el último); el orden, con el número que se pinta.
     *
     * @param string[] $marcadas
     *
     * @return array<string,string>
     */
    function ppSourcesForm(array $marcadas = ['paypal', 'card']): array
    {
        $out = [];
        $i = 0;
        foreach (array_keys(\Aifec\Payments\PayPal\PayPalFundingSources::offerable()) as $source) {
            $out[PayPalSettings::enabledKey((string) $source)] = in_array($source, $marcadas, true) ? '1' : '0';
            $out[PayPalSettings::positionKey((string) $source)] = (string) ++$i;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $formulario
     */
    function ppSave(TestClient $web, array $formulario, string $pestana): Response
    {
        $web->get('/admin/settings/payments-paypal');

        return $web->post('/admin/settings/payments-paypal', ['_token' => aifecToken($web), '_tab' => $pestana, 'f' => $formulario]);
    }
}

if (!function_exists('ppLocalOrder')) {
    /**
     * Una orden de una forma local tal como la devuelve PayPal al consultarla (o al capturar), con su payment_source.
     *
     * @return array<string,mixed>
     */
    function ppLocalOrder(string $id, string $source, string $estadoOrden, string $estadoCaptura, string $value, string $currency, string $customId): array
    {
        $unit = ['reference_id' => 'default', 'custom_id' => $customId, 'amount' => ['currency_code' => $currency, 'value' => $value]];
        if ($estadoCaptura !== '') {
            $unit['payments'] = ['captures' => [[
                'id' => 'CAP-' . $id, 'status' => $estadoCaptura, 'amount' => ['currency_code' => $currency, 'value' => $value],
                'custom_id' => $customId, 'invoice_id' => $customId,
            ]]];
        }

        return [
            'id' => $id, 'status' => $estadoOrden, 'processing_instruction' => 'ORDER_COMPLETE_ON_PAYMENT_APPROVAL',
            'payment_source' => [$source => ['name' => 'Ana Ruiz', 'country_code' => 'NL']],
            'purchase_units' => [$unit],
        ];
    }

    /**
     * La dirección de facturación inventada de un comprador de ese país.
     *
     * @return array<string,string>
     */
    function ppBuyer(string $country): array
    {
        return ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '1000', 'city' => 'Ciudad de prueba', 'region' => '', 'country' => $country, 'phone' => '999345678'];
    }

    /**
     * Una tienda con PayPal configurado y esas formas de pago encendidas (además de la cartera y la tarjeta de serie).
     *
     * @param string[]            $formas
     * @param array<string,mixed> $ajustes
     */
    function ppSourcesApp(array $formas, array $ajustes = []): App
    {
        foreach ($formas as $forma) {
            $ajustes[PayPalSettings::enabledKey($forma)] = 1;
        }

        return ppApp($ajustes);
    }

    /**
     * En UNA petición: añade el producto, elige esa forma de pago y hace el pedido con la caja dada.
     *
     * @param array<string,mixed> $caja
     *
     * @return array<string,mixed>
     */
    function ppPlaceWith(App $app, TestClient $web, int $producto, string $codigo, array $caja = []): array
    {
        return aifecCartRun($web, static function ($cart, App $app) use ($producto, $codigo, $caja): array {
            $added = $cart->add($producto, 0, 1);
            check(!empty($added['ok']), 'la línea se añade al carrito: ' . (isset($added['error']) ? (string) $added['error'] : ''));
            $cart->setPaymentMethod($codigo);

            return $app->service('orders')->place(aifecOrderCheckout($caja));
        });
    }

    /**
     * Un pedido pagado con una forma local por un comprador de ese país (producto digital: no hace falta envío, y el país es el
     * de la factura). Devuelve [app, PpFake, producto, resultado de place(), id de la orden de PayPal].
     *
     * @param array<string,mixed> $ajustes
     *
     * @return array{0:App,1:PpFake,2:int,3:array<string,mixed>,4:string}
     */
    function ppLocalPlaced(string $forma, string $pais, array $ajustes = [], int $precio = 2000): array
    {
        $app = ppSourcesApp([$forma], $ajustes);
        $fake = ppFake($app);
        $p = aifecProduct($app, ['name' => 'Guía en PDF', 'price' => $precio, 'stock' => 10, 'kind' => 'digital']);
        $placed = ppPlaceWith($app, new TestClient($app), $p, 'paypal_' . $forma, ['billing' => ppBuyer($pais)]);
        check(!empty($placed['ok']), 'el pedido se crea: ' . (string) $placed['error']);
        check($placed['action'] instanceof PaymentAction && $placed['action']->type === PaymentAction::REDIRECT, 'la caja recibe una redirección');

        return [$app, $fake, $p, $placed, ppAttempt($app, $placed)['reference']];
    }

    /**
     * Las notas del historial de un pedido que contienen ese trozo.
     *
     * @param array<string,mixed> $order
     *
     * @return array<int,array<string,mixed>>
     */
    function ppNotes(array $order, string $trozo): array
    {
        return array_values(array_filter($order['history'], static function (array $h) use ($trozo): bool {
            return strpos((string) $h['note'], $trozo) !== false;
        }));
    }
}
