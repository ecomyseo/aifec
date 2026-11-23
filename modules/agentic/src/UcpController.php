<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La API de compra de UCP (transporte REST): crear, leer, cambiar, completar y cancelar una sesión de compra.
 *
 * Sobre la caja de la tienda: las líneas, el comprador y la entrega se guardan en el carrito de la sesión y los precios, el
 * envío y los avisos los calcula la caja. El comprador revisa y paga desde la página de pago de la tienda.
 *
 * Los errores de negocio van con HTTP 200 y su aviso (como pide UCP); los de la petición, con su código HTTP.
 */
final class UcpController
{
    /** Tamaño máximo del cuerpo de una petición. */
    private const MAX_BODY = 65536;

    /** Líneas como mucho por sesión. */
    private const MAX_LINES = 50;

    /** Cuánto se guarda la respuesta de una petición con Idempotency-Key. */
    private const IDEMPOTENCY_TTL = 86400;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function profile(Request $request, array $params): Response
    {
        if (isset($params['version'])) {
            if (!in_array($params['version'], Ucp::VERSIONS, true)) {
                return $this->error(404, 'version_unsupported', 'Unsupported UCP version.');
            }
            $request->setAttr('ucp_version', $params['version']);
        }
        $response = Response::json(Ucp::profile($this->app));
        $response->headers['Cache-Control'] = 'public, max-age=300';
        $response->headers['Access-Control-Allow-Origin'] = '*';

        return $response;
    }

    /** La pregunta previa (OPTIONS) de quien lee la ficha desde una página: solo lectura, abierta a todos los orígenes. */
    public function preflight(Request $request, array $params): Response
    {
        $response = Response::text('', 204);
        $response->headers['Access-Control-Allow-Origin'] = '*';
        $response->headers['Access-Control-Allow-Methods'] = 'GET, HEAD, OPTIONS';
        $response->headers['Access-Control-Allow-Headers'] = 'Content-Type, Accept, UCP-Agent';
        $response->headers['Access-Control-Max-Age'] = '86400';
        $response->headers['Allow'] = 'GET, HEAD, OPTIONS';

        return $response;
    }

    public function create(Request $request, array $params, string $kind = 'checkout'): Response
    {
        return $this->idempotent($request, function (array $body) use ($request, $kind): Response {
            if ($kind === 'checkout' && isset($body['cart_id']) && is_string($body['cart_id'])) {
                $cart = $this->load(['id' => $body['cart_id']], $request);
                if ($cart['kind'] !== 'cart' || $cart['status'] !== UcpCheckouts::OPEN) {
                    return $this->error(409, 'cart_closed', 'This cart is already converted or closed.');
                }
                UcpCheckouts::bind($this->app, (string) $cart['public_id']);
                UcpCheckouts::update($this->app, (string) $cart['public_id'], ['kind' => 'checkout']);

                return Response::json(UcpView::live($this->app, $cart), 201);
            }
            $lines = isset($body['line_items']) && is_array($body['line_items']) ? $body['line_items'] : [];
            if ($lines === []) {
                return $this->error(400, 'invalid', t('A checkout needs at least one line item.'));
            }
            $key = Ucp::key($this->app, $request);
            $row = UcpCheckouts::create($this->app, Ucp::platform($request), $key !== null ? (int) $key['id'] : null, $kind);
            $id = (string) $row['public_id'];
            UcpCheckouts::bind($this->app, $id);
            $applied = $this->applyLines($id, $lines, false);
            if ($applied['added'] === 0) {
                // Nada se ha podido añadir: no se crea la sesión (UCP: error irrecuperable con 200 y la dirección de la tienda).
                UcpCheckouts::delete($this->app, $id);
                $first = $applied['messages'] !== [] ? $applied['messages'][0] : ['code' => 'item_unavailable', 'content' => t('None of the items can be bought.')];

                return $this->error(200, (string) $first['code'], (string) $first['content'], $this->app->url()->origin() . $this->app->url()->to('/', [], ''));
            }
            $messages = array_merge($applied['messages'], $this->applyDetails($id, $body));
            $row = UcpCheckouts::find($this->app, $id);

            return Response::json(UcpView::live($this->app, is_array($row) ? $row : [], $messages), 201);
        });
    }

    public function show(Request $request, array $params): Response
    {
        $row = $this->load($params, $request);
        if ((string) $row['status'] !== UcpCheckouts::OPEN) {
            return Response::json(UcpView::stored($this->app, $row));
        }
        UcpCheckouts::bind($this->app, (string) $row['public_id']);

        return Response::json(UcpView::live($this->app, $row));
    }

    public function update(Request $request, array $params): Response
    {
        $row = $this->load($params, $request);

        return $this->idempotent($request, function (array $body) use ($row): Response {
            $id = (string) $row['public_id'];
            if ((string) $row['status'] !== UcpCheckouts::OPEN || $row['snapshot'] !== null) {
                return $this->error(409, 'checkout_closed', t('This checkout is already finished.'));
            }
            UcpCheckouts::bind($this->app, $id);
            $messages = [];
            if (isset($body['line_items']) && is_array($body['line_items'])) {
                $messages = $this->applyLines($id, $body['line_items'], true)['messages'];
            }
            $messages = array_merge($messages, $this->applyDetails($id, $body));
            $fresh = UcpCheckouts::find($this->app, $id);

            return Response::json(UcpView::live($this->app, is_array($fresh) ? $fresh : $row, $messages));
        });
    }

    public function complete(Request $request, array $params): Response
    {
        $row = $this->load($params, $request);

        return $this->idempotent($request, function (array $body) use ($row, $request): Response {
            return $this->doComplete($row, $body, $request);
        });
    }

    public function cancel(Request $request, array $params): Response
    {
        $row = $this->load($params, $request);
        if ($row['status'] === UcpCheckouts::OPEN && UcpCheckouts::legacyPaymentLocked($this->app, (string) $row['public_id'])) { throw new HttpError(409, 'The delegated payment needs reconciliation.'); }

        return $this->idempotent($request, function (array $body) use ($row): Response {
            $id = (string) $row['public_id'];
            if ((string) $row['status'] === UcpCheckouts::COMPLETED || ($row['status'] === UcpCheckouts::OPEN && $row['snapshot'] !== null)) {
                return $this->error(409, 'checkout_closed', t('This checkout is already an order: it cannot be canceled here.'));
            }
            if ((string) $row['status'] === UcpCheckouts::OPEN) {
                UcpCheckouts::bind($this->app, $id);
                $view = UcpView::live($this->app, $row);
                unset($view['ucp'], $view['messages'], $view['continue_url']);
                UcpCheckouts::update($this->app, $id, ['status' => UcpCheckouts::CANCELED, 'snapshot' => (string) json_encode($view, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            $fresh = UcpCheckouts::find($this->app, $id);

            return Response::json(UcpView::stored($this->app, is_array($fresh) ? $fresh : $row));
        });
    }

    /** El enlace de continue_url: abre el carrito de la sesión en el navegador de la persona y la lleva a la caja. */
    public function resume(Request $request, array $params): Response
    {
        if (\Aifec\Kernel\BrowserCommerce::blocked($this->app, $request, true)) {
            throw new HttpError(403, t('This browser cannot modify carts through assistant links.'));
        }
        $raw = strtolower(isset($params['token']) ? (string) $params['token'] : '');
        $session = $this->app->db()->row('SELECT * FROM {ucp_checkouts} WHERE continue_hash = ?', [hash('sha256', $raw)]);
        if ($session === null) {
            throw HttpError::notFound();
        }
        if ($session['status'] === UcpCheckouts::OPEN && UcpCheckouts::legacyPaymentLocked($this->app, (string) $session['public_id'])) { throw new HttpError(409, 'The delegated payment needs reconciliation. Do not submit another payment.'); }
        if ($session['status'] === UcpCheckouts::CANCELED
            || ($session['status'] === UcpCheckouts::OPEN && $session['created_at'] < $this->app->clock()->sql($this->app->clock()->now() - 86400))) {
            throw new HttpError(410, 'Checkout expired or canceled');
        }
        $cart = $this->app->db()->row('SELECT id, order_id, source FROM {carts} WHERE token = ?', [hash('sha256', $raw)]);
        if ($cart === null || (string) $cart['source'] !== UcpCheckouts::SOURCE || !$this->app->router()->has('checkout')) {
            throw HttpError::notFound();
        }
        if ($cart['order_id'] !== null && (int) $cart['order_id'] > 0) {
            $order = $this->app->db()->row('SELECT number FROM {orders} WHERE id = ?', [(int) $cart['order_id']]);
            if ($order === null || !$this->app->router()->has('order.thanks')) {
                throw HttpError::notFound();
            }
            $target = $this->app->url()->route('order.thanks', ['number' => (string) $order['number'], 'secret' => $this->app->service('orders')->viewTokenOf((string) $order['number'])]);
        } else {
            $this->app->setCookie($this->app->cookieName('k'), $raw, 30);
            $this->app->setCookie($this->app->cookieName('ck'), '1', 30, true);
            if ($session['snapshot'] === null) {
                UcpCheckouts::bind($this->app, (string) $session['public_id']);
                $view = UcpView::live($this->app, $session);
                unset($view['ucp'], $view['continue_url']);
                UcpCheckouts::update($this->app, (string) $session['public_id'], ['snapshot' => (string) json_encode($view, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
            }
            $target = $this->app->url()->route('checkout');
        }
        $response = Response::redirect($target);
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';
        $response->headers['Referrer-Policy'] = 'no-referrer';

        return $response;
    }

    // ------------------------------------------------------------------ completar

    /**
     * @param array<string,mixed> $row
     * @param array<string,mixed> $body
     */
    private function doComplete(array $row, array $body, Request $request): Response
    {
        $id = (string) $row['public_id'];
        if ($row['status'] === UcpCheckouts::OPEN && UcpCheckouts::legacyPaymentLocked($this->app, $id)) { return $this->error(409, 'payment_pending', t('The shop is confirming the payment. Do not pay again.')); }
        if ((string) $row['status'] !== UcpCheckouts::OPEN) {
            return (string) $row['status'] === UcpCheckouts::COMPLETED
                ? Response::json(UcpView::stored($this->app, $row))
                : $this->error(409, 'checkout_closed', t('This checkout was canceled.'));
        }
        $key = Ucp::key($this->app, $request);
        if ($key === null && $this->app->settings()->bool('agentic.ucp_require_key', true)) {
            return $this->error(401, 'unauthorized', t('Completing a purchase needs the API key that the shop gave to this platform (Authorization: Bearer).'));
        }
        UcpCheckouts::bind($this->app, $id);
        if ($row['snapshot'] === null) { $this->applyDetails($id, $body); }
        $view = UcpView::live($this->app, $row);
        if ($view['status'] !== UcpView::INCOMPLETE) {
            $view['status'] = UcpView::ESCALATION;
        }
        return Response::json($view);
    }

    // ------------------------------------------------------------------ aplicar lo que manda la plataforma

    /**
     * Pone las líneas en el carrito. Con $replace, el carrito queda exactamente con esas líneas.
     *
     * @param mixed $lines
     *
     * @return array{added:int,messages:array<int,array<string,string>>}
     */
    private function applyLines(string $id, $lines, bool $replace): array
    {
        $cart = $this->app->service('cart');
        $messages = [];
        $added = 0;
        if (!is_array($lines) || count($lines) > self::MAX_LINES) {
            return ['added' => 0, 'messages' => [['type' => 'error', 'code' => 'invalid', 'path' => '$.line_items', 'content' => t('The line items are not valid.'), 'severity' => 'recoverable']]];
        }
        $existing = [];
        foreach ($cart->summary()['lines'] as $old) {
            $existing[(string) $old['key']] = $old;
        }
        $kept = [];
        foreach (array_values($lines) as $i => $line) {
            $path = '$.line_items[' . $i . ']';
            $itemId = is_array($line) && isset($line['item']['id']) && is_scalar($line['item']['id']) ? trim((string) $line['item']['id']) : '';
            $quantity = is_array($line) && isset($line['quantity']) ? $line['quantity'] : 1;
            $qty = is_int($quantity) ? $quantity : 0;
            if (preg_match('/^(\d{1,12})(?:-(\d{1,12}))?$/D', $itemId, $m) !== 1) {
                $messages[] = ['type' => 'error', 'code' => 'item_unavailable', 'path' => $path, 'content' => t('Unknown item: {id}', ['id' => mb_substr($itemId, 0, 40, 'UTF-8')]), 'severity' => 'recoverable'];
                continue;
            }
            if ($qty < 1 || $qty > 999) {
                $messages[] = ['type' => 'error', 'code' => 'quantity_invalid_limit_exceeded', 'path' => $path . '.quantity', 'content' => t('Enter a valid quantity'), 'severity' => 'recoverable'];
                continue;
            }
            $variant = isset($m[2]) ? (int) $m[2] : 0;
            $lineKey = \Aifec\Modules\Orders\Cart::lineKey((int) $m[1], $variant);
            $result = $replace && isset($existing[$lineKey]) ? $cart->update($lineKey, $qty) : $cart->add((int) $m[1], $variant, $qty);
            if (!empty($result['ok'])) {
                ++$added;
                $kept[(string) $result['key']] = true;
                continue;
            }
            $error = (string) $result['error'];
            $code = stripos($error, 'stock') !== false || stripos($error, 'existencias') !== false || stripos($error, 'unidades') !== false ? 'out_of_stock' : 'item_unavailable';
            $messages[] = ['type' => 'error', 'code' => $code, 'path' => $path, 'content' => $error, 'severity' => 'recoverable'];
        }
        if ($replace && ($added > 0 || $lines === [])) {
            // Añadir o cambiar primero evita perder el token del carrito al quitar su última línea.
            foreach ($existing as $lineKey => $old) {
                if (!isset($kept[$lineKey])) {
                    $cart->update($lineKey, 0);
                }
            }
        }
        if ($added === 0 && $replace) {
            UcpCheckouts::keep($this->app, $id);
        }

        return ['added' => $added, 'messages' => $messages];
    }

    /**
     * El comprador y la entrega (dirección y opción elegida).
     *
     * @param array<string,mixed> $body
     *
     * @return array<int,array<string,string>>
     */
    private function applyDetails(string $id, array $body): array
    {
        $co = $this->app->service('checkout');
        $messages = [];
        if (isset($body['discounts']['codes']) && is_array($body['discounts']['codes'])) {
            $cart = $this->app->service('cart');
            foreach ($cart->summary()['coupons'] as $current) {
                $code = is_array($current) ? (string) ($current['code'] ?? '') : (string) $current;
                if ($code !== '') {
                    $cart->removeCoupon($code);
                }
            }
            foreach (array_slice($body['discounts']['codes'], 0, 10) as $code) {
                if (!is_string($code) || strlen($code) > 80) {
                    $messages[] = ['type' => 'error', 'code' => 'discount_invalid', 'content' => 'Invalid discount code.', 'severity' => 'recoverable'];
                    continue;
                }
                $applied = $cart->applyCoupon($code);
                if (empty($applied['ok'])) {
                    $messages[] = ['type' => 'error', 'code' => 'discount_invalid', 'content' => (string) $applied['error'], 'severity' => 'recoverable'];
                }
            }
        }
        $fields = [];
        $address = [];
        if (isset($body['buyer']) && is_array($body['buyer'])) {
            $buyer = [];
            foreach (['email' => 191, 'first_name' => 80, 'last_name' => 120, 'phone_number' => 40] as $key => $max) {
                if (isset($body['buyer'][$key]) && is_scalar($body['buyer'][$key]) && trim((string) $body['buyer'][$key]) !== '') {
                    $buyer[$key] = mb_substr(trim((string) $body['buyer'][$key]), 0, $max, 'UTF-8');
                }
            }
            if (isset($buyer['email'])) {
                $fields['email'] = $buyer['email'];
            }
            foreach (['first_name' => 'first_name', 'last_name' => 'last_name', 'phone_number' => 'phone'] as $from => $to) {
                if (isset($buyer[$from])) {
                    $address[$to] = $buyer[$from];
                }
            }
            UcpCheckouts::update($this->app, $id, ['buyer' => (string) json_encode($buyer, JSON_UNESCAPED_UNICODE)]);
        }
        $method = isset($body['fulfillment']['methods'][0]) && is_array($body['fulfillment']['methods'][0]) ? $body['fulfillment']['methods'][0] : null;
        if ($method !== null && isset($method['destinations']) && is_array($method['destinations']) && $method['destinations'] !== []) {
            $destination = null;
            $selected = isset($method['selected_destination_id']) && is_scalar($method['selected_destination_id']) ? (string) $method['selected_destination_id'] : '';
            foreach ($method['destinations'] as $candidate) {
                if (is_array($candidate) && ($destination === null || ($selected !== '' && isset($candidate['id']) && (string) $candidate['id'] === $selected))) {
                    $destination = $candidate;
                }
            }
            $address = self::address(is_array($destination) ? $destination : []) + $address;
        }
        if ($address !== []) {
            $fields['address'] = $address;
        }
        if ($fields !== []) {
            $filled = $co->fill($fields);
            foreach ($filled['errors'] as $field => $text) {
                $isEmail = $field === 'email';
                $messages[] = [
                    'type' => 'error', 'code' => $isEmail ? 'missing_buyer_info' : 'address_undeliverable',
                    'path' => $isEmail ? '$.buyer.email' : '$.fulfillment.methods[0].destinations[0]', 'content' => (string) $text, 'severity' => 'recoverable',
                ];
            }
        }
        $groups = $method !== null && isset($method['groups']) && is_array($method['groups']) ? $method['groups'] : [];
        $option = isset($groups[0]['selected_option_id']) && is_scalar($groups[0]['selected_option_id']) ? (string) $groups[0]['selected_option_id'] : '';
        if ($option !== '') {
            $chosen = preg_match('/^carrier_(\d{1,12})$/D', $option, $m) === 1 ? $co->setShipping((int) $m[1]) : ['ok' => false, 'error' => t('This shipping method is not available for this order')];
            if (empty($chosen['ok'])) {
                $messages[] = ['type' => 'error', 'code' => 'missing_fulfillment_info', 'path' => '$.fulfillment.methods[0].groups[0].selected_option_id', 'content' => (string) $chosen['error'], 'severity' => 'recoverable'];
            }
        }

        return $messages;
    }

    /**
     * Una dirección de UCP con los nombres de la caja.
     *
     * @param array<string,mixed> $in
     *
     * @return array<string,string>
     */
    private static function address(array $in): array
    {
        $out = [];
        foreach ([
            'first_name' => 'first_name', 'last_name' => 'last_name', 'street_address' => 'line1', 'extended_address' => 'line2',
            'address_locality' => 'city', 'address_region' => 'region', 'postal_code' => 'postcode', 'address_country' => 'country', 'phone_number' => 'phone',
        ] as $from => $to) {
            if (isset($in[$from]) && is_scalar($in[$from]) && trim((string) $in[$from]) !== '') {
                $out[$to] = trim((string) $in[$from]);
            }
        }

        return $out;
    }

    /**
     * El instrumento elegido (el marcado como selected, o el primero).
     *
     * @param array<string,mixed> $body
     *
     * @return array<string,mixed>|null
     */
    private static function instrument(array $body): ?array
    {
        $list = isset($body['payment']['instruments']) && is_array($body['payment']['instruments']) ? array_values(array_filter($body['payment']['instruments'], 'is_array')) : [];
        foreach ($list as $instrument) {
            if (!empty($instrument['selected'])) {
                return $instrument;
            }
        }

        return $list !== [] ? $list[0] : null;
    }

    /**
     * El instrumento tal como se devuelve: sin la credencial.
     *
     * @param array<string,mixed>|null $instrument
     *
     * @return array<string,mixed>
     */
    private static function echoInstrument(?array $instrument): array
    {
        $out = [];
        foreach (['id', 'handler_id', 'type', 'selected', 'display'] as $key) {
            if (is_array($instrument) && isset($instrument[$key])) {
                $out[$key] = $instrument[$key];
            }
        }

        return $out;
    }

    /** @param array<string,mixed> $body */
    private static function buyerIp(array $body, string $fallback): string
    {
        $ip = isset($body['signals']['dev.ucp.buyer_ip']) && is_string($body['signals']['dev.ucp.buyer_ip']) ? trim($body['signals']['dev.ucp.buyer_ip']) : '';

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : $fallback;
    }

    // ------------------------------------------------------------------ petición

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    public function load(array $params, Request $request): array
    {
        $row = UcpCheckouts::find($this->app, isset($params['id']) ? (string) $params['id'] : '');
        if ($row === null) {
            throw new HttpError(404, 'Checkout not found');
        }
        $key = Ucp::key($this->app, $request);
        if (!empty($row['key_id']) && ($key === null || (int) $key['id'] !== (int) $row['key_id'])) {
            throw new HttpError(404, 'Checkout not found');
        }
        $row = UcpCheckouts::refreshOrder($this->app, $row);
        if ((string) $row['status'] === UcpCheckouts::OPEN && (string) $row['created_at'] < $this->app->clock()->sql($this->app->clock()->now() - 86400)) {
            throw new HttpError(410, 'Checkout expired');
        }

        return $row;
    }

    /**
     * Ejecuta la operación una sola vez por Idempotency-Key: la misma petición repetida recibe la misma respuesta, y la misma
     * clave con otro cuerpo es un conflicto.
     */
    private function idempotent(Request $request, callable $run): Response
    {
        if (strlen($request->body) > self::MAX_BODY) {
            return $this->error(413, 'invalid', t('The request is too large.'));
        }
        $body = $request->body === '' ? [] : json_decode($request->body, true);
        if (!is_array($body) || ($request->body !== '' && substr(ltrim($request->body), 0, 1) !== '{')) {
            return $this->error(400, 'invalid', t('The body must be a JSON object.'));
        }
        if (array_key_exists('line_items', $body)) {
            if (!is_array($body['line_items']) || count($body['line_items']) > self::MAX_LINES) {
                return $this->error(400, 'invalid', 'Invalid line_items.');
            }
            foreach ($body['line_items'] as $line) {
                if (!is_array($line) || !isset($line['item']['id']) || !is_string($line['item']['id'])
                    || preg_match('/^\d{1,12}(?:-\d{1,12})?$/D', $line['item']['id']) !== 1
                    || (array_key_exists('quantity', $line) && (!is_int($line['quantity']) || $line['quantity'] < 1 || $line['quantity'] > 999))) {
                    return $this->error(400, 'invalid', 'Each line needs a product identifier and an integer quantity between 1 and 999.');
                }
            }
        }
        $idem = trim((string) $request->header('idempotency-key', ''));
        if ($idem === '') {
            return $run($body);
        }
        if (strlen($idem) > 255) {
            return $this->error(400, 'invalid', t('The Idempotency-Key is too long.'));
        }
        $db = $this->app->db();
        $now = $this->app->clock()->now();
        $db->run('DELETE FROM {ucp_requests} WHERE created_at < ?', [$this->app->clock()->sql($now - self::IDEMPOTENCY_TTL)]);
        $key = Ucp::key($this->app, $request);
        $actor = $key !== null ? 'key:' . (int) $key['id'] : 'anonymous:' . $request->ip;
        $hash = hash('sha256', $actor . '|' . $request->method . '|' . $request->path . '|' . $idem);
        $requestHash = (string) $request->attr('commerce_request_hash', hash('sha256', $request->body));
        $saved = $db->insertIgnore('ucp_requests', ['key_hash' => $hash, 'request_hash' => $requestHash, 'status' => 0, 'body' => null, 'created_at' => $this->app->clock()->sql($now)]);
        if (!$saved) {
            $row = $db->row('SELECT request_hash, status, body FROM {ucp_requests} WHERE key_hash = ?', [$hash]);
            if ($row === null || !hash_equals((string) $row['request_hash'], $requestHash)) {
                return $this->error(409, 'idempotency_conflict', t('This Idempotency-Key was already used with another request.'));
            }
            if ((int) $row['status'] === 0) {
                return $this->error(409, 'request_in_progress', t('This request is still being processed.'));
            }
            $replay = Response::json(json_decode((string) $row['body'], true), (int) $row['status']);
            $replay->headers['Idempotent-Replayed'] = 'true';

            return $replay;
        }
        try {
            $response = $run($body);
        } catch (\Throwable $e) {
            $db->delete('ucp_requests', ['key_hash' => $hash]);

            throw $e;
        }
        $db->update('ucp_requests', ['status' => $response->status, 'body' => $response->body], ['key_hash' => $hash]);

        return $response;
    }

    private function error(int $status, string $code, string $content, string $continueUrl = ''): Response
    {
        $data = [
            'ucp' => ['version' => Ucp::version($this->app), 'status' => 'error'],
            'messages' => [['type' => 'error', 'code' => $code, 'content' => $content, 'severity' => 'unrecoverable']],
        ];
        if ($continueUrl !== '') {
            $data['continue_url'] = $continueUrl;
        }

        return Response::json($data, $status);
    }
}
