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
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Orders\OrderLog;

/**
 * La API de compra de UCP (transporte REST): crear, leer, cambiar, completar y cancelar una sesión de compra.
 *
 * Sobre la caja de la tienda: las líneas, el comprador y la entrega se guardan en el carrito de la sesión y los precios, el
 * envío y los avisos los calcula la caja. Completar la compra exige un instrumento de pago que algún módulo sepa cobrar
 * (Google Pay por Redsys) y, con «agentic.ucp_require_key» encendido, la clave de la API que la tienda le dio a la plataforma.
 * Sin una forma de pagar aquí, la sesión pide terminar en la caja de la tienda (requires_escalation y continue_url).
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
        $response = Response::json(Ucp::profile($this->app));
        $response->headers['Cache-Control'] = 'public, max-age=300';
        $response->headers['Access-Control-Allow-Origin'] = '*';

        return $response;
    }

    public function create(Request $request, array $params): Response
    {
        return $this->idempotent($request, function (array $body) use ($request): Response {
            $lines = isset($body['line_items']) && is_array($body['line_items']) ? $body['line_items'] : [];
            if ($lines === []) {
                return $this->error(400, 'invalid', t('A checkout needs at least one line item.'));
            }
            $key = Ucp::key($this->app, $request);
            $row = UcpCheckouts::create($this->app, Ucp::platform($request), $key !== null ? (int) $key['id'] : null);
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
        $row = $this->load($params);
        if ((string) $row['status'] !== UcpCheckouts::OPEN) {
            return Response::json(UcpView::stored($this->app, $row));
        }
        UcpCheckouts::bind($this->app, (string) $row['public_id']);

        return Response::json(UcpView::live($this->app, $row));
    }

    public function update(Request $request, array $params): Response
    {
        $row = $this->load($params);

        return $this->idempotent($request, function (array $body) use ($row): Response {
            $id = (string) $row['public_id'];
            if ((string) $row['status'] !== UcpCheckouts::OPEN) {
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
        $row = $this->load($params);

        return $this->idempotent($request, function (array $body) use ($row, $request): Response {
            return $this->doComplete($row, $body, $request);
        });
    }

    public function cancel(Request $request, array $params): Response
    {
        $row = $this->load($params);

        return $this->idempotent($request, function (array $body) use ($row): Response {
            $id = (string) $row['public_id'];
            if ((string) $row['status'] === UcpCheckouts::COMPLETED) {
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
        $raw = strtolower(isset($params['token']) ? (string) $params['token'] : '');
        $cart = $this->app->db()->row('SELECT id, order_id, source FROM {carts} WHERE token = ?', [hash('sha256', $raw)]);
        if ($cart === null || (string) $cart['source'] !== UcpCheckouts::SOURCE || !$this->app->router()->has('checkout')) {
            throw HttpError::notFound();
        }
        if ($cart['order_id'] !== null && (int) $cart['order_id'] > 0) {
            $order = $this->app->db()->row('SELECT number, secret FROM {orders} WHERE id = ?', [(int) $cart['order_id']]);
            if ($order === null || !$this->app->router()->has('order.thanks')) {
                throw HttpError::notFound();
            }
            $target = $this->app->url()->route('order.thanks', ['number' => (string) $order['number'], 'secret' => (string) $order['secret']]);
        } else {
            $this->app->setCookie($this->app->cookieName('k'), $raw, 30);
            $this->app->setCookie($this->app->cookieName('ck'), '1', 30, true);
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
        if ((string) $row['status'] !== UcpCheckouts::OPEN) {
            return (string) $row['status'] === UcpCheckouts::COMPLETED
                ? Response::json(UcpView::stored($this->app, $row))
                : $this->error(409, 'checkout_closed', t('This checkout was canceled.'));
        }
        $key = Ucp::key($this->app, $request);
        if ($key === null && $this->app->settings()->bool('agentic.ucp_require_key', true)) {
            return $this->error(401, 'unauthorized', t('Completing a purchase needs the API key that the shop gave to this platform (Authorization: Bearer).'));
        }
        $instrument = self::instrument($body);
        $method = '';
        if ($instrument !== null) {
            // El módulo de pago que sabe cobrar ese instrumento lo prepara (el token, solo en memoria) y dice su forma de pago.
            $method = (string) $this->app->hooks()->apply('ucp.payment_instrument', '', $instrument);
        }
        $outcome = '';
        try {
            UcpCheckouts::bind($this->app, $id);
            if ($method === '') {
                $view = UcpView::live($this->app, $row, [[
                    'type' => 'error', 'code' => 'payment_ineligible', 'path' => '$.payment.instruments',
                    'content' => t('This payment cannot be taken here. Finish the purchase on the checkout page of the shop.'), 'severity' => 'recoverable',
                ]]);
                if ($view['status'] === UcpView::READY || $view['status'] === UcpView::ESCALATION) {
                    $view['status'] = UcpView::ESCALATION;
                }

                return Response::json($view);
            }
            $co = $this->app->service('checkout');
            if ($instrument !== null && isset($instrument['billing_address']) && is_array($instrument['billing_address'])) {
                $billing = self::address($instrument['billing_address']);
                if ($billing !== []) {
                    $co->fill(['billing' => $billing]);
                }
            }
            $chosen = $co->setPayment($method);
            if (empty($chosen['ok'])) {
                return Response::json($this->escalated($row, 'payment_ineligible', t('This payment cannot be taken for this purchase. Finish it on the checkout page of the shop.')));
            }
            $review = $co->review();
            if (empty($review['ready'])) {
                return Response::json(UcpView::live($this->app, $row));
            }
            $total = (int) $review['total'];
            $max = max(0, $this->app->settings()->int('api.max_order_cents', 0));
            if ($max > 0 && $total > $max) {
                return Response::json($this->escalated($row, 'amount_limit_exceeded',t('This order is above the highest amount the shop takes from an AI platform. Finish it on the checkout page of the shop.')));
            }
            $limiter = new RateLimiter($this->app->db(), $this->app->clock());
            $bucket = $key !== null ? 'ucp-orders:' . (int) $key['id'] : 'ucp-orders-ip:' . (string) $request->ip;
            $hourly = max(0, $this->app->settings()->int('api.max_orders_per_hour', 10));
            if ($hourly > 0 && $limiter->remaining($bucket, $hourly, 3600) < 1) {
                return $this->error(429, 'rate_limited', t('Too many orders in an hour. Try again later.'));
            }

            // La foto de la compra antes de hacer el pedido (después, el carrito ya es un pedido).
            $snapshot = UcpView::live($this->app, $row);
            $platform = (string) $row['platform'];
            $cartId = (int) $this->app->db()->value('SELECT id FROM {carts} WHERE token = ?', [hash('sha256', UcpCheckouts::cartToken($id))]);
            $result = $co->place((string) $review['confirm_token'], [
                'source' => UcpCheckouts::SOURCE,
                'ip' => self::buyerIp($body, (string) $request->ip),
                'accept_terms' => true,
                'marketing' => false,
                'attribution' => ['channel' => 'ucp', 'source' => $platform !== '' ? $platform : 'UCP', 'key_id' => $key !== null ? (int) $key['id'] : 0, 'key_name' => $key !== null ? (string) $key['name'] : ''],
            ]);
            $outcome = (string) $this->app->hooks()->apply('ucp.payment_finish', '', $method);
            $method = '';
            if (empty($result['ok'])) {
                $view = $this->failed($row, $result, $outcome);
                // La caja reabre el carrito con una ficha nueva al azar: vuelve a la de la sesión, o la siguiente petición no lo encuentra.
                UcpCheckouts::restoreCart($this->app, $id, $cartId);

                return Response::json($view);
            }
            if ($hourly > 0) {
                $limiter->hit($bucket, $hourly, 3600);
            }
            $orderUrl = Ucp::absolute($this->app, (string) $result['order_url']);
            try {
                (new OrderLog($this->app))->add((int) $result['id'], 'note', null, null, 'Placed through UCP' . ($platform !== '' ? ' by ' . $platform : '') . ($key !== null ? ' with the key ' . (string) $key['name'] : ''), false);
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'agentic.order.log');
            }
            unset($snapshot['ucp'], $snapshot['messages'], $snapshot['continue_url']);
            $snapshot['payment'] = ['instruments' => [self::echoInstrument($instrument)]];
            UcpCheckouts::update($this->app, $id, [
                'status' => UcpCheckouts::COMPLETED, 'order_id' => (int) $result['id'], 'order_number' => (string) $result['number'], 'order_url' => $orderUrl,
                'snapshot' => (string) json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);
            $fresh = UcpCheckouts::find($this->app, $id);

            return Response::json(UcpView::stored($this->app, is_array($fresh) ? $fresh : $row));
        } finally {
            if ($method !== '') {
                // Si algo cortó antes de cobrar, el token no se queda en memoria.
                $this->app->hooks()->apply('ucp.payment_finish', '', $method);
            }
        }
    }

    /**
     * El cobro no ha salido: el pedido queda fallido y el carrito vuelve a estar libre (otra tarjeta, o la caja de la tienda).
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $result
     *
     * @return array<string,mixed>
     */
    private function failed(array $row, array $result, string $outcome): array
    {
        $error = (string) (isset($result['error']) ? $result['error'] : '');
        if ($outcome === 'authentication') {
            return $this->escalated($row, 'requires_3ds', t('The bank asks to confirm the card. Finish the purchase on the checkout page of the shop.'));
        }
        if (empty($result['reopened'])) {
            return UcpView::live($this->app, $row, [['type' => 'error', 'code' => 'totals_changed', 'content' => $error !== '' ? $error : t('The order has changed. Review it again.'), 'severity' => 'recoverable']]);
        }
        $code = $outcome === 'declined' ? 'payment_declined' : 'payment_failed';

        return UcpView::live($this->app, $row, [['type' => 'error', 'code' => $code, 'path' => '$.payment.instruments', 'content' => $error !== '' ? $error : t('The payment did not go through. Nothing was charged.'), 'severity' => 'recoverable']]);
    }

    /**
     * La sesión enviada a la caja de la tienda: requires_escalation, con su aviso.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function escalated(array $row, string $code, string $content): array
    {
        $view = UcpView::live($this->app, $row, [['type' => 'error', 'code' => $code, 'content' => $content, 'severity' => 'recoverable']]);
        $view['status'] = UcpView::ESCALATION;

        return $view;
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
        if ($replace) {
            foreach ($cart->summary()['lines'] as $line) {
                $cart->update((string) $line['key'], 0);
            }
            UcpCheckouts::keep($this->app, $id);
        }
        foreach (array_values($lines) as $i => $line) {
            $path = '$.line_items[' . $i . ']';
            $itemId = is_array($line) && isset($line['item']['id']) && is_scalar($line['item']['id']) ? trim((string) $line['item']['id']) : '';
            $qty = is_array($line) && isset($line['quantity']) && is_numeric($line['quantity']) ? (int) $line['quantity'] : 1;
            if (preg_match('/^(\d{1,12})(?:-(\d{1,12}))?$/D', $itemId, $m) !== 1) {
                $messages[] = ['type' => 'error', 'code' => 'item_unavailable', 'path' => $path, 'content' => t('Unknown item: {id}', ['id' => mb_substr($itemId, 0, 40, 'UTF-8')]), 'severity' => 'recoverable'];
                continue;
            }
            if ($qty < 1 || $qty > 999) {
                $messages[] = ['type' => 'error', 'code' => 'quantity_invalid_limit_exceeded', 'path' => $path . '.quantity', 'content' => t('Enter a valid quantity'), 'severity' => 'recoverable'];
                continue;
            }
            $result = $cart->add((int) $m[1], isset($m[2]) ? (int) $m[2] : 0, $qty);
            if (!empty($result['ok'])) {
                ++$added;
                continue;
            }
            $error = (string) $result['error'];
            $code = stripos($error, 'stock') !== false || stripos($error, 'existencias') !== false || stripos($error, 'unidades') !== false ? 'out_of_stock' : 'item_unavailable';
            $messages[] = ['type' => 'error', 'code' => $code, 'path' => $path, 'content' => $error, 'severity' => 'recoverable'];
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
    private function load(array $params): array
    {
        $row = UcpCheckouts::find($this->app, isset($params['id']) ? (string) $params['id'] : '');
        if ($row === null) {
            throw new HttpError(404, 'Checkout not found');
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
        if (!is_array($body)) {
            return $this->error(400, 'invalid', t('The body must be a JSON object.'));
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
        $hash = hash('sha256', $request->method . '|' . $request->path . '|' . $idem);
        $requestHash = hash('sha256', $request->body);
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
            'ucp' => ['version' => Ucp::VERSION, 'status' => 'error'],
            'messages' => [['type' => 'error', 'code' => $code, 'content' => $content, 'severity' => 'unrecoverable']],
        ];
        if ($continueUrl !== '') {
            $data['continue_url'] = $continueUrl;
        }

        return Response::json($data, $status);
    }
}
