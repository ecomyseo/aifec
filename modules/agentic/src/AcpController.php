<?php
/**
 * AIFEC — Agentic Commerce Protocol sobre la caja UCP.
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

final class AcpController
{
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
        require_once $app->root() . '/protocol/Acp.php';
    }

    public function create(Request $request, array $params): Response
    {
        return $this->dispatch('create', $request, $params);
    }

    public function discovery(Request $request, array $params): Response
    {
        $base = $this->app->url()->origin() . rtrim($this->app->url()->to('/', [], ''), '/');
        $discovery = \AifecCommerceAcp::discovery($base, $this->app->settings()->str('shop.currency', 'EUR'));
        // Cómo se autentica quien crea y gestiona sesiones de compra. La ficha y el feed son públicos.
        $discovery['authentication'] = [
            'type' => 'bearer',
            'scheme' => 'Bearer',
            'header' => 'Authorization',
            'alternative_header' => 'X-API-Key',
            'scopes' => ['acp', 'orders:create'],
            'required_for' => ['create', 'retrieve', 'update', 'complete', 'cancel'],
            'not_required_for' => ['discovery', 'feed'],
            'without_credentials' => ['status' => 401],
        ];
        // Con qué formas de pago cuenta la caja y que el pago lo hace la persona en ella (nunca el agente).
        if (class_exists('Aifec\\Modules\\Orders\\PaymentSummary')) {
            $payment = \Aifec\Modules\Orders\PaymentSummary::declaration($this->app);
            if ($payment['methods'] !== []) {
                $discovery['payment'] = $payment;
            }
        }
        $response = Response::json($discovery);
        $response->headers['Cache-Control'] = 'public, max-age=3600';
        unset($response->headers['Pragma'], $response->headers['Expires']);
        $response->headers['Access-Control-Allow-Origin'] = '*';
        $response->headers['X-Content-Type-Options'] = 'nosniff';

        return $response;
    }

    public static function enabled(App $app): bool
    {
        return $app->settings()->bool('agentic.acp_enabled', false);
    }

    public function show(Request $request, array $params): Response
    {
        return $this->dispatch('show', $request, $params);
    }

    public function update(Request $request, array $params): Response
    {
        return $this->dispatch('update', $request, $params);
    }

    public function complete(Request $request, array $params): Response
    {
        return $this->dispatch('complete', $request, $params);
    }

    public function cancel(Request $request, array $params): Response
    {
        return $this->dispatch('cancel', $request, $params);
    }

    private function dispatch(string $operation, Request $request, array $params): Response
    {
        $version = trim((string) $request->header('api-version', ''));
        if ($version !== \AifecCommerceAcp::VERSION) {
            return $this->error(400, 'unsupported_version', 'The supported ACP version is ' . \AifecCommerceAcp::VERSION . '.');
        }
        if (preg_match('/^Bearer\s+\S+$/iD', trim((string) $request->header('authorization', ''))) !== 1 || Ucp::key($this->app, $request) === null) {
            return $this->error(401, 'unauthorized', 'A valid Bearer API key with a checkout scope is required.');
        }
        if ($operation !== 'show' && trim((string) $request->header('idempotency-key', '')) === '') {
            return $this->error(400, 'idempotency_key_required', 'Idempotency-Key is required for checkout mutations.');
        }
        if (strlen($request->body) > 65536) {
            return $this->error(413, 'request_too_large', 'The request body is too large.');
        }
        if ($request->body !== '' && strtolower(trim(explode(';', (string) $request->header('content-type', ''))[0])) !== 'application/json') {
            return $this->error(415, 'unsupported_media_type', 'Use application/json for the checkout request body.');
        }
        $body = array();
        if ($operation !== 'show') {
            if ($request->body !== '') {
                $decoded = json_decode($request->body);
                if (!is_object($decoded)) {
                    return $this->error(400, 'invalid_json', 'The request body must be a JSON object.');
                }
                if ($operation === 'create' && (!isset($decoded->capabilities) || !is_object($decoded->capabilities))) {
                    return $this->error(400, 'invalid_request', 'capabilities must be a JSON object.');
                }
                $body = json_decode($request->body, true);
            }
            try {
                $converted = \AifecCommerceAcp::request($body, $operation);
            } catch (\InvalidArgumentException $e) {
                return $this->error(400, 'invalid_request', $e->getMessage());
            }
            if (isset($body['currency']) && strtoupper((string) $body['currency']) !== strtoupper($this->app->settings()->str('shop.currency', 'EUR'))) {
                return $this->error(400, 'unsupported_currency', 'The checkout currency must match the shop currency.');
            }
        } else {
            $converted = array();
        }
        $internal = clone $request;
        $internal->body = $operation === 'show' ? '' : (string) json_encode((object) $converted, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $internal->headers['content-type'] = 'application/json';
        // El hash conserva también campos ACP ignorados: una clave no se puede reutilizar con otro cuerpo.
        $internal->setAttr('commerce_request_hash', hash('sha256', $request->body));
        $internal->setAttr('commerce_protocol', 'acp');
        $internal->setAttr('ucp_version', Ucp::VERSION);
        try {
            $response = ProtocolGate::handle($internal, $this->app, function (Request $checked) use ($operation, $params): Response {
                $controller = new UcpController($this->app);
                // ACP actualiza campos; UCP PUT reemplaza el estado. El resto de la compra debe conservarse.
                if ($operation === 'update') {
                    $state = json_decode($controller->show($checked, $params)->body, true);
                    $input = json_decode($checked->body, true);
                    if (is_array($state) && is_array($input)) {
                        foreach (array('line_items', 'buyer', 'fulfillment', 'discounts') as $field) {
                            if (!array_key_exists($field, $input) && isset($state[$field]) && is_array($state[$field])) {
                                $input[$field] = $state[$field];
                            } elseif (isset($input[$field], $state[$field]) && is_array($input[$field]) && is_array($state[$field]) && in_array($field, array('buyer', 'fulfillment'), true)) {
                                $input[$field] = array_replace_recursive($state[$field], $input[$field]);
                            }
                        }
                        $checked->body = (string) json_encode((object) $input, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    }
                }

                $wasCanceled = false;
                if ($operation === 'cancel') {
                    $state = json_decode($controller->show($checked, $params)->body, true);
                    $wasCanceled = is_array($state) && isset($state['status']) && $state['status'] === 'canceled';
                }
                $result = $controller->{$operation}($checked, $params);
                if ($wasCanceled && !isset($result->headers['Idempotent-Replayed'])) {
                    return Response::json(['messages' => [['code' => 'checkout_closed', 'content' => 'This checkout is already canceled.']]], 405);
                }

                return $result;
            });
        } catch (HttpError $e) {
            return $this->error($e->status(), $e->status() === 404 ? 'not_found' : 'invalid_request', $e->getMessage());
        }
        $data = json_decode($response->body, true);
        if (!is_array($data)) {
            return $this->error(500, 'invalid_response', 'The checkout could not be read.');
        }
        if ($response->status >= 400 || !isset($data['id'])) {
            $message = isset($data['messages'][0]) && is_array($data['messages'][0]) ? $data['messages'][0] : array();
            $code = isset($message['code']) ? (string) $message['code'] : 'invalid_request';
            $status = $response->status < 400 ? 400 : $response->status;
            if ($code === 'idempotency_conflict') {
                $status = 422;
            }
            if ($operation === 'cancel' && $code === 'checkout_closed') {
                $status = 405;
            }
            $response->status = $status;
            $response->body = (string) json_encode(\AifecCommerceAcp::error($code, isset($message['content']) ? (string) $message['content'] : 'The checkout request failed.', $status), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } else {
            $convertedResponse = \AifecCommerceAcp::response($data);
            $response->body = (string) json_encode($convertedResponse, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        $response->headers['API-Version'] = \AifecCommerceAcp::VERSION;
        foreach (array('Idempotency-Key' => 'idempotency-key', 'Request-Id' => 'request-id') as $out => $in) {
            $value = (string) $request->header($in, '');
            if ($value !== '' && strlen($value) <= 255 && strpos($value, "\r") === false && strpos($value, "\n") === false) {
                $response->headers[$out] = $value;
            }
        }

        return $response;
    }

    private function error(int $status, string $code, string $message): Response
    {
        $response = Response::json(\AifecCommerceAcp::error($code, $message, $status), $status);
        $response->headers['API-Version'] = \AifecCommerceAcp::VERSION;

        return $response;
    }
}
