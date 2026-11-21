<?php
/** AIFEC — binding MCP de las operaciones UCP de la tienda. */
declare(strict_types=1);

namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Mcp\RpcError;
use Aifec\Modules\Mcp\Server;

final class UcpMcp
{
    // Nombre MCP => método y ruta REST, operación y nombre del cuerpo en los argumentos MCP.
    private const TOOLS = [
        'search_catalog' => ['POST', '/catalog/search', UcpCatalog::class, 'search', 'catalog'],
        'lookup_catalog' => ['POST', '/catalog/lookup', UcpCatalog::class, 'lookup', 'catalog'],
        'get_product' => ['POST', '/catalog/product', UcpCatalog::class, 'lookup', 'catalog'],
        'create_cart' => ['POST', '/carts', UcpCarts::class, 'create', 'cart'],
        'get_cart' => ['GET', '/carts/{id}', UcpCarts::class, 'show', ''],
        'update_cart' => ['PUT', '/carts/{id}', UcpCarts::class, 'update', 'cart'],
        'cancel_cart' => ['POST', '/carts/{id}/cancel', UcpCarts::class, 'cancel', ''],
        'create_checkout' => ['POST', '/checkout-sessions', UcpController::class, 'create', 'checkout'],
        'get_checkout' => ['GET', '/checkout-sessions/{id}', UcpController::class, 'show', ''],
        'update_checkout' => ['PUT', '/checkout-sessions/{id}', UcpController::class, 'update', 'checkout'],
        'complete_checkout' => ['POST', '/checkout-sessions/{id}/complete', UcpController::class, 'complete', 'checkout'],
        'cancel_checkout' => ['POST', '/checkout-sessions/{id}/cancel', UcpController::class, 'cancel', ''],
    ];

    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function post(Request $request): Response
    {
        $app = $this->app;
        if (!$app->modules()->isLoaded('mcp')) {
            throw HttpError::notFound();
        }
        $origin = $request->header('origin');
        if ($origin !== null && rtrim($origin, '/') !== $app->url()->origin()) {
            throw HttpError::forbidden('Origin is not allowed');
        }
        if (strlen($request->body) > 65536) {
            return Response::json(Server::error(null, Server::INVALID_REQUEST, 'Request is too large'), 413);
        }
        if (strtolower(trim(explode(';', (string) $request->header('content-type'))[0])) !== 'application/json') {
            return Response::json(Server::error(null, Server::INVALID_REQUEST, 'Expected application/json'), 415);
        }
        $message = json_decode($request->body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return Response::json(Server::error(null, Server::PARSE_ERROR, 'Invalid JSON'), 400);
        }
        $reply = Server::respond($app, $message, function (string $method, array $args) use ($request): array {
            return $this->dispatch($request, $method, $args);
        });
        $response = $reply === null ? new Response('', 202) : Response::json($reply,
            ($reply['error']['code'] ?? null) === Server::INVALID_REQUEST ? 400 : 200);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $reply === null ? $response : UcpSignatures::sign($app, $response);
    }

    private function dispatch(Request $request, string $method, array $params): array
    {
        switch ($method) {
            case 'initialize':
                $version = $params['protocolVersion'] ?? '';
                return ['protocolVersion' => in_array($version, Server::PROTOCOLS, true) ? $version : Server::PROTOCOLS[0],
                    'serverInfo' => ['name' => 'aifec-ucp', 'version' => Ucp::VERSION], 'capabilities' => ['tools' => ['listChanged' => false]]];
            case 'ping':
                return [];
            case 'tools/list':
                return ['tools' => $this->toolList()];
            case 'tools/call':
                $name = $params['name'] ?? '';
                $args = $params['arguments'] ?? [];
                if (!is_string($name) || !isset(self::TOOLS[$name]) || !is_array($args)) {
                    throw new RpcError(Server::INVALID_PARAMS, 'Unknown UCP tool or invalid arguments');
                }
                return $this->call($request, self::TOOLS[$name], $args);
        }
        throw new RpcError(Server::METHOD_NOT_FOUND, 'Method not found');
    }

    private function toolList(): array
    {
        $tools = [];
        foreach (self::TOOLS as $name => [$method, $path, , , $body]) {
            $properties = ['meta' => ['type' => 'object', 'properties' => [
                'ucp-agent' => ['type' => 'object', 'properties' => ['profile' => ['type' => 'string', 'format' => 'uri']]],
                'idempotency-key' => ['type' => 'string'],
                'request-id' => ['type' => 'string'],
            ]]];
            $required = [];
            if (strpos($path, '{id}') !== false) {
                $properties['id'] = ['type' => 'string', 'pattern' => '^chk_[a-f0-9]{32}$'];
                $required[] = 'id';
            }
            if ($body !== '') {
                $properties[$body] = ['type' => 'object', 'description' => 'UCP ' . $body . ' payload'];
                $required[] = $body;
            }
            $tools[] = ['name' => $name, 'description' => ucfirst(str_replace('_', ' ', $name)) . '. Checkout payment takes place on the shop.',
                'inputSchema' => ['type' => 'object', 'properties' => $properties, 'required' => $required],
                'annotations' => ['readOnlyHint' => $method === 'GET' || $body === 'catalog', 'openWorldHint' => false]];
        }
        return $tools;
    }

    private function call(Request $request, array $tool, array $args): array
    {
        [$method, $path, $class, $operation, $body] = $tool;
        $read = clone $request;
        $read->method = $method;
        $resource = [];
        if (strpos($path, '{id}') !== false) {
            $id = $args['id'] ?? null;
            if (!is_string($id) || preg_match('/^chk_[a-f0-9]{32}$/D', $id) !== 1) {
                throw new RpcError(Server::INVALID_PARAMS, 'Invalid commerce session identifier');
            }
            $resource['id'] = $id;
            $path = str_replace('{id}', $id, $path);
        }
        $read->path = '/ucp/v1' . $path;
        $payload = $body === '' ? [] : ($args[$body] ?? null);
        if (!is_array($payload) || ($payload !== [] && array_keys($payload) === range(0, count($payload) - 1))) {
            throw new RpcError(Server::INVALID_PARAMS, 'A commerce payload object is required');
        }
        $read->body = $method === 'GET' ? '' : (string) json_encode((object) $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $read->headers['ucp-version'] = Ucp::VERSION;
        $meta = $args['meta'] ?? [];
        if (!is_array($meta)) {
            throw new RpcError(Server::INVALID_PARAMS, 'Invalid UCP metadata');
        }
        foreach (['idempotency-key', 'request-id'] as $name) {
            if (isset($meta[$name])) {
                if (!is_string($meta[$name]) || strlen($meta[$name]) > 255 || preg_match('/[\r\n]/', $meta[$name]) === 1
                    || ($request->header($name) !== null && $request->header($name) !== $meta[$name])) {
                    throw new RpcError(Server::INVALID_PARAMS, 'Invalid or conflicting ' . $name);
                }
                $read->headers[$name] = $meta[$name];
            }
        }
        $profile = $meta['ucp-agent']['profile'] ?? null;
        if (is_string($profile) && preg_match('~^https://[^\s"\\\\]{1,492}$~D', $profile) === 1 && $read->header('ucp-agent') === null) {
            $read->headers['ucp-agent'] = 'profile="' . $profile . '"';
        }
        if (in_array($operation, ['complete', 'cancel'], true) && trim((string) $read->header('idempotency-key')) === '') {
            throw new RpcError(Server::INVALID_PARAMS, 'Idempotency key is required');
        }
        try {
            $response = ProtocolGate::handle($read, $this->app, function (Request $guarded) use ($class, $operation, $resource): Response {
                return (new $class($this->app))->{$operation}($guarded, $resource);
            });
        } catch (HttpError $error) {
            $response = Response::json(['ucp' => ['version' => Ucp::VERSION], 'messages' => [['type' => 'error',
                'code' => 'http_' . $error->status(), 'content' => $error->getMessage(), 'severity' => 'unrecoverable']]], $error->status());
        }
        return ['structuredContent' => json_decode($response->body), 'content' => [['type' => 'text', 'text' => $response->body]],
            'isError' => $response->status >= 400];
    }
}
