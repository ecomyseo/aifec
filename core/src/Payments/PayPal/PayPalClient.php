<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Payments\PayPal;

use Aifec\Http\HttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;

/**
 * Cliente de PayPal: Orders v2, Payments v2 (devoluciones), OAuth 2.0 y verificación de avisos.
 *
 * Todo el tráfico sale por el HttpClient recibido. Los importes entran y salen en céntimos (enteros);
 * a PayPal viajan como cadena decimal con los decimales de la moneda. Nunca se usa float.
 *
 * Aviso: los nombres de campos y rutas están escritos de la documentación pública de PayPal que se
 * conocía al escribirlo (Orders v2, Payments v2, Webhooks verify-webhook-signature, OAuth 2.0). No se ha
 * podido consultar la documentación en línea ni probar contra PayPal real; lo marcado «SIN CONFIRMAR»
 * hay que comprobarlo contra el sandbox. Las formas de pago locales (payment_source, processing_instruction y
 * landing_page GUEST_CHECKOUT) se cotejaron el 13/11/2026 con el esquema de Orders v2 2.36 y las páginas «orders-api»
 * de cada una (ver PayPalFundingSources); tampoco se han probado contra PayPal real.
 */
final class PayPalClient
{
    /** Margen, en segundos, con el que se renueva la ficha antes de que caduque. */
    private const TOKEN_MARGIN = 60;

    /** Monedas sin decimales según PayPal. */
    private const ZERO_DECIMAL = ['JPY', 'HUF', 'TWD'];

    /** @var PayPalConfig */
    private $config;

    /** @var HttpClient */
    private $http;

    /** @var callable|null */
    private $tokenCache;

    /** @var array<string,mixed>|null ficha guardada en la propia instancia (sin $tokenCache) */
    private $localToken;

    public function __construct(PayPalConfig $config, HttpClient $http, ?callable $tokenCache = null)
    {
        $this->config = $config;
        $this->http = $http;
        $this->tokenCache = $tokenCache;
        $this->localToken = null;
    }

    /** Número de decimales de una moneda (2 salvo JPY, HUF y TWD). */
    public static function currencyDecimals(string $currency): int
    {
        return in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
    }

    /** Céntimos (o unidad mínima) a cadena decimal: 4999 → «49.99»; 500 JPY → «500». */
    public static function formatAmount(int $minor, string $currency): string
    {
        $decimals = self::currencyDecimals($currency);
        $sign = $minor < 0 ? '-' : '';
        $abs = $minor < 0 ? -$minor : $minor;
        if ($decimals === 0) {
            return $sign . (string) $abs;
        }
        $pow = 10 ** $decimals;

        return $sign . (string) intdiv($abs, $pow) . '.' . str_pad((string) ($abs % $pow), $decimals, '0', STR_PAD_LEFT);
    }

    /**
     * Cadena decimal de PayPal a céntimos: «49.99» → 4999, «0.10» → 10, «100» → 10000.
     *
     * @param mixed $value cadena o entero (PayPal manda cadenas)
     */
    public static function parseAmount($value, string $currency): int
    {
        if (!is_string($value) && !is_int($value)) {
            throw new \InvalidArgumentException('Amount must be a decimal string');
        }
        if (preg_match('/^(-?)(\d+)(?:\.(\d+))?$/', trim((string) $value), $m) !== 1) {
            throw new \InvalidArgumentException('Invalid decimal amount');
        }
        $decimals = self::currencyDecimals($currency);
        $fraction = str_pad((string) substr(isset($m[3]) ? $m[3] : '', 0, $decimals), $decimals, '0');
        $minor = (int) ($m[2] . $fraction);

        return $m[1] === '-' ? -$minor : $minor;
    }

    /** Ficha de acceso (OAuth 2.0, credenciales de cliente). Se reutiliza hasta 60 s antes de caducar. */
    public function accessToken(int $now): string
    {
        $cached = $this->readToken();
        if ($cached !== null && $now < $cached['expires'] - self::TOKEN_MARGIN) {
            return $cached['token'];
        }

        $auth = 'Basic ' . base64_encode($this->config->clientId() . ':' . $this->config->clientSecret());
        $response = $this->send(HttpRequest::postForm(
            $this->config->baseUrl() . '/v1/oauth2/token',
            ['grant_type' => 'client_credentials'],
            ['Authorization' => $auth, 'Accept' => 'application/json']
        ));
        $this->assertOk($response);
        $data = $response->json();
        $token = is_array($data) && isset($data['access_token']) && is_string($data['access_token']) ? $data['access_token'] : '';
        if ($token === '') {
            throw new PayPalException('PayPal token response has no access_token', $response->status, '', $this->debugIdOf($response));
        }
        $ttl = is_array($data) && isset($data['expires_in']) ? (int) $data['expires_in'] : 0;
        $this->writeToken(['token' => $token, 'expires' => $now + max(0, $ttl)]);

        return $token;
    }

    /**
     * Crea un pedido (intención CAPTURE).
     *
     * Claves de $o: reference*, amount* (céntimos), currency*, description, returnUrl*, cancelUrl*,
     * items (name, quantity, unit_amount en céntimos, sku), shipping, tax, discount (céntimos),
     * shippingAddress (name, line1, line2, city, state, postalCode, country), noShipping (bool),
     * source (forma de pago del catálogo PayPalFundingSources; «paypal» por defecto) y payer (name, country, email: el
     * titular que exigen las formas locales).
     *
     * Las formas locales (Bancontact, iDEAL…) llevan su nodo en payment_source y processing_instruction
     * ORDER_COMPLETE_ON_PAYMENT_APPROVAL: PayPal cobra al aprobar y no hay que capturar. Una forma que no está en el catálogo,
     * que necesita el SDK, o a la que le falta el titular, lanza \InvalidArgumentException sin llamar a PayPal.
     *
     * @param array<string,mixed> $o
     *
     * @return array{id:string,status:string,approveUrl:string}
     */
    public function createOrder(array $o, int $now): array
    {
        $reference = isset($o['reference']) ? trim((string) $o['reference']) : '';
        $currency = isset($o['currency']) ? strtoupper(trim((string) $o['currency'])) : '';
        $amount = isset($o['amount']) ? (int) $o['amount'] : 0;
        $returnUrl = isset($o['returnUrl']) ? (string) $o['returnUrl'] : '';
        $cancelUrl = isset($o['cancelUrl']) ? (string) $o['cancelUrl'] : '';
        if ($reference === '' || $amount <= 0 || preg_match('/^[A-Z]{3}$/', $currency) !== 1 || $returnUrl === '' || $cancelUrl === '') {
            throw new \InvalidArgumentException('createOrder needs reference, a positive amount, a 3-letter currency, returnUrl and cancelUrl');
        }

        $amountNode = ['currency_code' => $currency, 'value' => self::formatAmount($amount, $currency)];
        $unit = [
            'custom_id' => $this->cut($reference, 255),
            'invoice_id' => $this->cut($reference, 127),
        ];
        $description = isset($o['description']) ? trim((string) $o['description']) : '';
        if ($description !== '') {
            $unit['description'] = $this->cut($description, 127);
        }

        $items = $this->buildItems($o, $currency, $amount);
        if ($items !== null) {
            $amountNode['breakdown'] = $items['breakdown'];
            $unit['items'] = $items['items'];
        }
        $unit['amount'] = $amountNode;

        $address = $this->buildAddress($o);
        $noShipping = !empty($o['noShipping']);
        if ($address !== null && !$noShipping) {
            $unit['shipping'] = $address;
        }

        $source = isset($o['source']) && is_string($o['source']) && trim($o['source']) !== '' ? strtolower(trim($o['source'])) : 'paypal';
        $known = PayPalFundingSources::get($source);
        if ($known === null || !PayPalFundingSources::isOfferable($source)) {
            throw new \InvalidArgumentException('Unknown or unsupported PayPal funding source: ' . $source);
        }

        $context = [
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
            'shipping_preference' => $noShipping ? 'NO_SHIPPING' : ($address !== null ? 'SET_PROVIDED_ADDRESS' : 'GET_FROM_FILE'),
        ];
        if ($this->config->brandName() !== '') {
            $context['brand_name'] = $this->cut($this->config->brandName(), 127);
        }
        if ($this->config->locale() !== '') {
            $context['locale'] = $this->config->locale();
        }

        $body = [
            'intent' => 'CAPTURE',
            'purchase_units' => [$unit],
        ];
        if ($known['flow'] === PayPalFundingSources::FLOW_SDK) {
            // Las del guion: el navegador aprueba con el guion de PayPal y el servidor captura después.
            $sdkSource = $this->sdkSource($source, $context);
            if ($sdkSource !== []) {
                $body['payment_source'] = $sdkSource;
            }
        } elseif ($known['capture'] === PayPalFundingSources::CAPTURE_SERVER) {
            // Cartera y tarjeta: la página de PayPal; con GUEST_CHECKOUT abre directamente el formulario de tarjeta.
            $wallet = $context + ['user_action' => 'PAY_NOW', 'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED'];
            if ($source === 'card') {
                $wallet['landing_page'] = 'GUEST_CHECKOUT';
            }
            $body['payment_source'] = ['paypal' => ['experience_context' => $wallet]];
        } else {
            $body['payment_source'] = [$source => $this->localSource($known, $o, $context)];
            // PayPal cobra solo cuando el comprador aprueba en su banco: no hay captura desde el servidor.
            $body['processing_instruction'] = 'ORDER_COMPLETE_ON_PAYMENT_APPROVAL';
        }

        // El identificador de idempotencia: el mismo pedido y la misma forma dan la misma orden; con «attempt» (los reintentos
        // de la página de pago tras una tarjeta rechazada) cada intento crea la suya.
        $key = $source === 'paypal' ? $reference : $reference . '|' . $source;
        if (isset($o['attempt']) && (int) $o['attempt'] > 0) {
            $key .= '|' . (int) $o['attempt'];
        }
        $response = $this->api(
            'POST',
            '/v2/checkout/orders',
            $body,
            ['PayPal-Request-Id' => $this->requestId('order', $key), 'Prefer' => 'return=representation'],
            $now
        );
        $this->assertOk($response);
        $data = $this->decoded($response);
        $id = isset($data['id']) && is_string($data['id']) ? $data['id'] : '';
        if ($id === '') {
            throw new PayPalException('PayPal create order response has no id', $response->status, '', $this->debugIdOf($response));
        }

        return [
            'id' => $id,
            'status' => isset($data['status']) ? (string) $data['status'] : '',
            'approveUrl' => $this->approveUrl($data),
        ];
    }

    /**
     * El payment_source de una forma del guion al crear la orden en el servidor:
     * - Paga más tarde: payment_source.paypal con su experiencia (el botón del guion pone la financiación);
     * - Venmo: payment_source.venmo con la marca y la preferencia de envío;
     * - tarjeta con campos en la página: payment_source.card con 3-D Secure cuando haga falta (SCA_WHEN_REQUIRED) y las
     *   direcciones de vuelta por si el banco pide salir de la página; los datos de la tarjeta los pone el guion de PayPal;
     * - Google Pay y Apple Pay: nada (el guion los añade al confirmar la orden con confirmOrder).
     *
     * @param array<string,string> $context
     *
     * @return array<string,mixed>
     */
    private function sdkSource(string $source, array $context): array
    {
        switch ($source) {
            case 'paylater':
                return ['paypal' => ['experience_context' => $context + ['user_action' => 'PAY_NOW']]];
            case 'venmo':
                $venmo = ['shipping_preference' => $context['shipping_preference']];
                if (isset($context['brand_name'])) {
                    $venmo['brand_name'] = $context['brand_name'];
                }

                return ['venmo' => ['experience_context' => $venmo]];
            case 'card_fields':
                return ['card' => [
                    'attributes' => ['verification' => ['method' => 'SCA_WHEN_REQUIRED']],
                    'experience_context' => ['return_url' => $context['return_url'], 'cancel_url' => $context['cancel_url']],
                ]];
            default:
                return [];
        }
    }

    /**
     * El payment_source de una forma de pago local: titular, país, correo si lo pide o lo admite, y la experiencia de vuelta.
     *
     * @param array<string,mixed> $known   la ficha del catálogo
     * @param array<string,mixed> $o       la entrada de createOrder (payer: name, country, email)
     * @param array<string,string> $context
     *
     * @return array<string,mixed>
     */
    private function localSource(array $known, array $o, array $context): array
    {
        $payer = isset($o['payer']) && is_array($o['payer']) ? $o['payer'] : [];
        $name = trim($this->str($payer, 'name'));
        $country = strtoupper(trim($this->str($payer, 'country')));
        $email = trim($this->str($payer, 'email'));
        if ($name === '') {
            throw new \InvalidArgumentException('This PayPal funding source needs the name of the account holder');
        }
        if (preg_match('/^[A-Z]{2}$/', $country) !== 1 || ($known['countries'] !== [] && !in_array($country, $known['countries'], true))) {
            throw new \InvalidArgumentException('This PayPal funding source does not accept buyers from ' . ($country !== '' ? $country : 'an unknown country'));
        }
        if (in_array('email', $known['requires'], true) && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('This PayPal funding source needs the email of the buyer');
        }
        $node = ['name' => $this->cut($name, 300), 'country_code' => $country];
        if (!empty($known['accepts_email']) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
            $node['email'] = $this->cut($email, 254);
        }
        $node['experience_context'] = $context;

        return $node;
    }

    /**
     * Captura un pedido aprobado por el cliente. Si ya estaba capturado devuelve su captura.
     *
     * @return array<string,mixed>
     */
    public function captureOrder(string $paypalOrderId, int $now): array
    {
        $id = $this->segment($paypalOrderId);
        $response = $this->api(
            'POST',
            '/v2/checkout/orders/' . $id . '/capture',
            new \stdClass(),
            ['PayPal-Request-Id' => $this->requestId('capture', $paypalOrderId), 'Prefer' => 'return=representation'],
            $now
        );
        if (!$response->ok()) {
            if ($response->status === 422 && $this->hasIssue($response->json(), 'ORDER_ALREADY_CAPTURED')) {
                return $this->getOrder($paypalOrderId, $now);
            }
            $this->assertOk($response);
        }

        return $this->normalizeOrder($this->decoded($response));
    }

    /**
     * Consulta un pedido.
     *
     * @return array<string,mixed>
     */
    public function getOrder(string $paypalOrderId, int $now): array
    {
        $response = $this->api('GET', '/v2/checkout/orders/' . $this->segment($paypalOrderId), null, [], $now);
        $this->assertOk($response);

        return $this->normalizeOrder($this->decoded($response));
    }

    /**
     * Devuelve dinero de una captura. $amount nulo = devolución total.
     *
     * @return array{id:string,status:string,amount:?int,currency:string}
     */
    public function refund(string $captureId, ?int $amount, string $currency, string $note, int $now): array
    {
        $currency = strtoupper(trim($currency));
        if ($amount !== null && $amount <= 0) {
            throw new \InvalidArgumentException('Refund amount must be positive');
        }
        $body = [];
        if ($amount !== null) {
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new \InvalidArgumentException('A partial refund needs a 3-letter currency');
            }
            $body = ['amount' => ['currency_code' => $currency, 'value' => self::formatAmount($amount, $currency)]];
        }
        if (trim($note) !== '') {
            $body['note_to_payer'] = $this->cut(trim($note), 255);
        }

        $response = $this->api(
            'POST',
            '/v2/payments/captures/' . $this->segment($captureId) . '/refund',
            $body === [] ? new \stdClass() : $body,
            ['PayPal-Request-Id' => $this->requestId('refund', $captureId . '|' . ($amount === null ? 'full' : (string) $amount)), 'Prefer' => 'return=representation'],
            $now
        );
        $this->assertOk($response);
        $data = $this->decoded($response);
        $paid = self::sub($data, 'amount');
        $cur = isset($paid['currency_code']) ? strtoupper((string) $paid['currency_code']) : $currency;

        return [
            'id' => isset($data['id']) ? (string) $data['id'] : '',
            'status' => isset($data['status']) ? (string) $data['status'] : '',
            'amount' => isset($paid['value']) ? self::parseAmount($paid['value'], $cur) : $amount,
            'currency' => $cur,
        ];
    }

    /**
     * Comprueba con PayPal que un aviso (webhook) es auténtico.
     *
     * Las cabeceras llegan con el nombre en minúsculas. Si falta alguna o no hay webhookId, devuelve
     * falso sin llamar a PayPal. El cuerpo se pasa a PayPal tal cual llegó (sin recodificarlo, para no
     * alterar lo firmado). Un error de PayPal (no 2xx) lanza PayPalException: así el que recibe el
     * aviso puede contestar 5xx y que PayPal lo reintente.
     *
     * @param array<string,mixed> $headers
     */
    public function verifyWebhook(array $headers, string $rawBody, int $now): bool
    {
        $webhookId = $this->config->webhookId();
        if ($webhookId === '') {
            return false;
        }
        $h = array_change_key_case($headers, CASE_LOWER);
        $map = [
            'auth_algo' => 'paypal-auth-algo',
            'cert_url' => 'paypal-cert-url',
            'transmission_id' => 'paypal-transmission-id',
            'transmission_sig' => 'paypal-transmission-sig',
            'transmission_time' => 'paypal-transmission-time',
        ];
        $fields = [];
        foreach ($map as $field => $header) {
            $value = isset($h[$header]) ? $h[$header] : '';
            if (is_array($value)) {
                $value = $value === [] ? '' : reset($value);
            }
            $value = is_string($value) ? trim($value) : '';
            if ($value === '') {
                return false;
            }
            $fields[$field] = $value;
        }
        $fields['webhook_id'] = $webhookId;

        if (!is_array(json_decode($rawBody, true))) {
            return false;
        }
        $head = json_encode($fields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $json = substr($head, 0, -1) . ',"webhook_event":' . $rawBody . '}';

        $token = $this->accessToken($now);
        $response = $this->send(new HttpRequest(
            'POST',
            $this->config->baseUrl() . '/v1/notifications/verify-webhook-signature',
            ['Authorization' => 'Bearer ' . $token, 'Accept' => 'application/json', 'Content-Type' => 'application/json'],
            $json
        ));
        $this->assertOk($response);
        $data = $response->json();

        return is_array($data) && isset($data['verification_status']) && $data['verification_status'] === 'SUCCESS';
    }

    // ---------------------------------------------------------------------------------------------

    /**
     * Petición autenticada. $body: null (sin cuerpo), array u objeto (JSON).
     *
     * @param mixed                $body
     * @param array<string,string> $headers
     */
    private function api(string $method, string $path, $body, array $headers, int $now): HttpResponse
    {
        $headers = ['Authorization' => 'Bearer ' . $this->accessToken($now), 'Accept' => 'application/json'] + $headers;
        $url = $this->config->baseUrl() . $path;
        if ($body === null) {
            return $this->send(new HttpRequest($method, $url, $headers));
        }
        try {
            $json = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException('Request body cannot be encoded as JSON');
        }

        return $this->send(new HttpRequest($method, $url, $headers + ['Content-Type' => 'application/json'], $json));
    }

    private function send(HttpRequest $request): HttpResponse
    {
        try {
            return $this->http->send($request);
        } catch (HttpException $e) {
            throw new PayPalException('PayPal network error: ' . $this->redact($e->getMessage()), 0, '', '', true, $e);
        }
    }

    /** Lanza PayPalException si la respuesta no es 2xx. */
    private function assertOk(HttpResponse $response): void
    {
        if ($response->ok()) {
            return;
        }
        $data = $response->json();
        $data = is_array($data) ? $data : [];
        $name = $this->str($data, 'name') !== '' ? $this->str($data, 'name') : $this->str($data, 'error');
        $text = $this->str($data, 'message') !== '' ? $this->str($data, 'message') : $this->str($data, 'error_description');
        $issue = '';
        $issueCode = '';
        $details = self::sub($data, 'details');
        if ($details !== [] && isset($details[0]) && is_array($details[0])) {
            $issueCode = $this->str($details[0], 'issue');
            $issue = $issueCode !== '' ? $issueCode : $this->str($details[0], 'description');
        }
        if (preg_match('/^[A-Z0-9_]{2,100}$/', $issueCode) !== 1) {
            $issueCode = '';
        }
        $debugId = $this->debugIdOf($response);

        $message = 'PayPal HTTP ' . $response->status
            . ($name !== '' ? ' ' . $name : '')
            . ($text !== '' ? ': ' . $text : '')
            . ($issue !== '' ? ' (' . $issue . ')' : '')
            . ($debugId !== '' ? ' [debug_id ' . $debugId . ']' : '');
        $transient = $response->status === 429 || $response->status >= 500;

        throw new PayPalException($this->cut($this->redact($message), 500), $response->status, $this->cut($this->redact($name), 100), $this->cut($this->redact($debugId), 100), $transient, null, $issueCode);
    }

    /** @return array<string,mixed> */
    private function decoded(HttpResponse $response): array
    {
        $data = $response->json();
        if (!is_array($data)) {
            throw new PayPalException('PayPal response is not valid JSON', $response->status, '', $this->debugIdOf($response));
        }

        return $data;
    }

    private function debugIdOf(HttpResponse $response): string
    {
        $data = $response->json();
        if (is_array($data) && isset($data['debug_id']) && is_string($data['debug_id'])) {
            return $data['debug_id'];
        }

        return (string) $response->header('paypal-debug-id');
    }

    /** @param mixed $data */
    private function hasIssue($data, string $issue): bool
    {
        if (!is_array($data)) {
            return false;
        }
        if (isset($data['name']) && $data['name'] === $issue) {
            return true;
        }
        foreach (self::sub($data, 'details') as $detail) {
            if (is_array($detail) && isset($detail['issue']) && $detail['issue'] === $issue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normaliza un pedido de PayPal.
     *
     * «status» es el estado del pedido; «captureStatus» el de su primera captura (COMPLETED, PENDING…).
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    private function normalizeOrder(array $order): array
    {
        $units = self::sub($order, 'purchase_units');
        $unit = isset($units[0]) && is_array($units[0]) ? $units[0] : [];
        $captures = self::sub(self::sub($unit, 'payments'), 'captures');
        $capture = isset($captures[0]) && is_array($captures[0]) ? $captures[0] : [];

        $money = self::sub($capture, 'amount');
        if ($money === []) {
            $money = self::sub($unit, 'amount');
        }
        $currency = isset($money['currency_code']) ? strtoupper((string) $money['currency_code']) : '';
        $amount = isset($money['value']) ? self::parseAmount($money['value'], $currency) : 0;

        $payer = self::sub($order, 'payer');
        $email = $this->str($payer, 'email_address');
        if ($email === '') {
            $email = $this->str(self::sub(self::sub($order, 'payment_source'), 'paypal'), 'email_address');
        }
        $reference = $this->str($capture, 'custom_id');
        if ($reference === '') {
            $reference = $this->str($unit, 'custom_id');
        }
        // La forma de pago con que se pagó: la clave de payment_source (paypal, ideal, trustly…).
        $paymentSource = self::sub($order, 'payment_source');
        $sources = array_keys($paymentSource);
        $source = isset($sources[0]) && is_string($sources[0]) ? $sources[0] : '';
        // El resultado de 3-D Secure: en payment_source.card (campos de tarjeta) o en payment_source.google_pay.card.
        $card = self::sub($paymentSource, 'card');
        if ($card === []) {
            $card = self::sub(self::sub($paymentSource, 'google_pay'), 'card');
        }
        $auth = self::sub($card, 'authentication_result');
        $threeDs = self::sub($auth, 'three_d_secure');
        $liability = $auth === [] ? [] : [
            'shift' => $this->str($auth, 'liability_shift'),
            'enrollment' => $this->str($threeDs, 'enrollment_status'),
            'authentication' => $this->str($threeDs, 'authentication_status'),
        ];

        return [
            'id' => $this->str($order, 'id'),
            'status' => $this->str($order, 'status'),
            'captureStatus' => $this->str($capture, 'status'),
            'captureId' => $this->str($capture, 'id'),
            'amount' => $amount,
            'currency' => $currency,
            'payerEmail' => $email,
            'reference' => $reference,
            'source' => $source,
            'liability' => $liability,
        ];
    }

    /** @param array<string,mixed> $order */
    private function approveUrl(array $order): string
    {
        foreach (self::sub($order, 'links') as $link) {
            if (is_array($link) && isset($link['rel'], $link['href']) && ($link['rel'] === 'payer-action' || $link['rel'] === 'approve')) {
                return (string) $link['href'];
            }
        }

        return '';
    }

    /**
     * Líneas y desglose; null si no hay líneas o el desglose no cuadra al céntimo con el total.
     *
     * @param array<string,mixed> $o
     *
     * @return array{items:array<int,array<string,mixed>>,breakdown:array<string,mixed>}|null
     */
    private function buildItems(array $o, string $currency, int $amount): ?array
    {
        if (!isset($o['items']) || !is_array($o['items']) || $o['items'] === []) {
            return null;
        }
        $shipping = isset($o['shipping']) ? (int) $o['shipping'] : 0;
        $tax = isset($o['tax']) ? (int) $o['tax'] : 0;
        $discount = isset($o['discount']) ? (int) $o['discount'] : 0;
        if ($shipping < 0 || $tax < 0 || $discount < 0) {
            return null;
        }

        $lines = [];
        $itemTotal = 0;
        foreach ($o['items'] as $item) {
            if (!is_array($item)) {
                return null;
            }
            $name = isset($item['name']) ? trim((string) $item['name']) : '';
            $qty = isset($item['quantity']) ? (int) $item['quantity'] : 0;
            $unit = isset($item['unit_amount']) ? (int) $item['unit_amount'] : -1;
            if ($name === '' || $qty < 1 || $unit < 0) {
                return null;
            }
            $line = [
                'name' => $this->cut($name, 127),
                'quantity' => (string) $qty,
                'unit_amount' => ['currency_code' => $currency, 'value' => self::formatAmount($unit, $currency)],
            ];
            $sku = isset($item['sku']) ? trim((string) $item['sku']) : '';
            if ($sku !== '') {
                $line['sku'] = $this->cut($sku, 127);
            }
            $lines[] = $line;
            $itemTotal += $unit * $qty;
        }
        if ($itemTotal + $shipping + $tax - $discount !== $amount) {
            return null;
        }

        $money = static function (int $minor) use ($currency): array {
            return ['currency_code' => $currency, 'value' => self::formatAmount($minor, $currency)];
        };

        return [
            'items' => $lines,
            'breakdown' => [
                'item_total' => $money($itemTotal),
                'shipping' => $money($shipping),
                'tax_total' => $money($tax),
                'discount' => $money($discount),
            ],
        ];
    }

    /**
     * Dirección de envío para PayPal; null si no se dio o le faltan la calle o el país.
     *
     * @param array<string,mixed> $o
     *
     * @return array<string,mixed>|null
     */
    private function buildAddress(array $o): ?array
    {
        $a = isset($o['shippingAddress']) && is_array($o['shippingAddress']) ? $o['shippingAddress'] : [];
        $line1 = $this->str($a, 'line1');
        $country = strtoupper($this->str($a, 'country'));
        if ($line1 === '' || preg_match('/^[A-Z]{2}$/', $country) !== 1) {
            return null;
        }
        $address = ['address_line_1' => $this->cut($line1, 300), 'country_code' => $country];
        foreach (['line2' => 'address_line_2', 'city' => 'admin_area_2', 'state' => 'admin_area_1', 'postalCode' => 'postal_code'] as $key => $target) {
            if ($this->str($a, $key) !== '') {
                $address[$target] = $this->cut($this->str($a, $key), 300);
            }
        }
        $node = ['address' => $address];
        if ($this->str($a, 'name') !== '') {
            $node['name'] = ['full_name' => $this->cut($this->str($a, 'name'), 300)];
        }

        return $node;
    }

    /** Identificador de idempotencia estable: mismo origen, mismo identificador. */
    private function requestId(string $kind, string $source): string
    {
        return 'aifec-' . $kind . '-' . substr(hash('sha256', $kind . '|' . $source), 0, 40);
    }

    /** Un identificador de PayPal listo para ir en la ruta. */
    private function segment(string $id): string
    {
        $id = trim($id);
        if ($id === '') {
            throw new \InvalidArgumentException('PayPal id must not be empty');
        }

        return rawurlencode($id);
    }

    private function cut(string $text, int $max): string
    {
        return mb_strlen($text, 'UTF-8') > $max ? mb_substr($text, 0, $max, 'UTF-8') : $text;
    }

    /** Quita del texto el secreto y la ficha, por si algún mensaje los repitiera. */
    private function redact(string $text): string
    {
        $secrets = [$this->config->clientSecret()];
        $cached = $this->readToken();
        if ($cached !== null) {
            $secrets[] = $cached['token'];
        }
        foreach ($secrets as $secret) {
            if ($secret !== '') {
                $text = str_replace($secret, '***', $text);
            }
        }

        return $text;
    }

    /**
     * @param array<string,mixed> $a
     */
    private function str(array $a, string $key): string
    {
        return isset($a[$key]) && (is_string($a[$key]) || is_int($a[$key])) ? (string) $a[$key] : '';
    }

    /**
     * Subárbol de un array o vacío (nunca falso ni nulo).
     *
     * @param array<string,mixed> $a
     *
     * @return array<mixed>
     */
    private static function sub(array $a, string $key): array
    {
        return isset($a[$key]) && is_array($a[$key]) ? $a[$key] : [];
    }

    /** @return array{token:string,expires:int}|null */
    private function readToken(): ?array
    {
        $stored = $this->tokenCache !== null ? ($this->tokenCache)(null) : $this->localToken;
        if (!is_array($stored) || !isset($stored['token'], $stored['expires']) || !is_string($stored['token']) || $stored['token'] === '') {
            return null;
        }

        return ['token' => $stored['token'], 'expires' => (int) $stored['expires']];
    }

    /** @param array{token:string,expires:int} $token */
    private function writeToken(array $token): void
    {
        if ($this->tokenCache !== null) {
            ($this->tokenCache)($token);

            return;
        }
        $this->localToken = $token;
    }
}
