<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mcp;

use Aifec\Kernel\App;

/**
 * El servidor MCP (Model Context Protocol): JSON-RPC 2.0 sobre HTTP, una respuesta JSON por petición, sin flujo.
 *
 * Métodos: initialize, notifications/initialized (y cualquier otra notificación, que no se contesta), ping, tools/list, tools/call,
 * resources/list, resources/templates/list y resources/read. Un método que no existe da el error -32601; unos parámetros que no
 * valen, -32602. Los fallos de una herramienta NO son errores del protocolo: salen como un resultado con isError = true.
 *
 * Esta clase no sabe de HTTP: recibe cada mensaje ya descodificado y devuelve la respuesta (o null si era una notificación).
 */
final class Server
{
    public const VERSION = '1.0.0';

    /** La última versión del protocolo que habla este servidor y las anteriores que conoce, de la más nueva a la más vieja. */
    public const PROTOCOLS = ['2025-06-18', '2025-03-26', '2024-11-05'];

    public const PARSE_ERROR = -32700;
    public const INVALID_REQUEST = -32600;
    public const METHOD_NOT_FOUND = -32601;
    public const INVALID_PARAMS = -32602;
    public const INTERNAL_ERROR = -32603;
    public const RESOURCE_NOT_FOUND = -32002;

    /** @var App */
    private $app;

    /** @var Agent */
    private $agent;

    /** @var bool ¿ha atendido un initialize esta instancia? */
    private $initialized = false;

    public function __construct(App $app, Agent $agent)
    {
        $this->app = $app;
        $this->agent = $agent;
    }

    public function initialized(): bool
    {
        return $this->initialized;
    }

    /**
     * ¿Alguno de los mensajes llama a una herramienta que escribe en el carrito? Entonces el carrito de la sesión se crea antes de
     * procesar nada, para que todas las llamadas de la petición trabajen con el mismo.
     *
     * @param array<int,mixed> $messages
     */
    public function needsCart(array $messages): bool
    {
        foreach ($messages as $message) {
            if (is_array($message) && isset($message['method']) && $message['method'] === 'tools/call' && isset($message['params']['name'])
                && is_string($message['params']['name']) && $this->agent->writes($message['params']['name'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Atiende un mensaje.
     *
     * @param mixed $message
     *
     * @return array<string,mixed>|null la respuesta, o null si era una notificación (o una respuesta del cliente)
     */
    public function handle($message): ?array
    {
        return self::respond($this->app, $message, [$this, 'dispatch']);
    }

    /** Permite reutilizar JSON-RPC con un conjunto distinto de herramientas MCP. */
    public static function respond(App $app, $message, callable $dispatch): ?array
    {
        if (!is_array($message) || array_keys($message) === range(0, count($message) - 1) && $message !== [] || !isset($message['jsonrpc']) || $message['jsonrpc'] !== '2.0') {
            return self::error(is_array($message) && isset($message['id']) && (is_int($message['id']) || is_string($message['id'])) ? $message['id'] : null, self::INVALID_REQUEST, 'Invalid Request: a JSON-RPC 2.0 object is expected');
        }
        $hasId = array_key_exists('id', $message);
        $id = $hasId ? $message['id'] : null;
        if ($hasId && !is_int($id) && !is_string($id)) {
            return self::error(null, self::INVALID_REQUEST, 'Invalid Request: the id must be a number or a string');
        }
        if (!isset($message['method'])) {
            // Una respuesta del cliente a algo que le pedimos (no pedimos nada): se ignora.
            return $hasId && (array_key_exists('result', $message) || array_key_exists('error', $message)) ? null : self::error($hasId ? $id : null, self::INVALID_REQUEST, 'Invalid Request: the method is missing');
        }
        if (!is_string($message['method'])) {
            return self::error($hasId ? $id : null, self::INVALID_REQUEST, 'Invalid Request: the method must be a string');
        }
        $params = isset($message['params']) && is_array($message['params']) ? $message['params'] : [];
        if (isset($message['params']) && !is_array($message['params'])) {
            return $hasId ? self::error($id, self::INVALID_PARAMS, 'Invalid params: an object is expected') : null;
        }

        if (!$hasId) {
            // Una notificación no se contesta, ni siquiera si el método no existe (notifications/initialized, notifications/cancelled…).
            return null;
        }
        try {
            $result = $dispatch($message['method'], $params);

            // Un resultado vacío (ping) es un objeto: «[]» no es lo que dice el protocolo.
            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result === [] ? new \stdClass() : $result];
        } catch (RpcError $e) {
            return self::error($id, $e->rpcCode(), $e->getMessage());
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'mcp ' . $message['method']);

            return self::error($id, self::INTERNAL_ERROR, 'Internal error');
        }
    }

    /**
     * Un error JSON-RPC.
     *
     * @param int|string|null $id
     *
     * @return array<string,mixed>
     */
    public static function error($id, int $code, string $message): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]];
    }

    // ------------------------------------------------------------------ métodos

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     *
     * @throws RpcError
     */
    private function dispatch(string $method, array $params): array
    {
        switch ($method) {
            case 'initialize':
                return $this->initialize($params);
            case 'ping':
                return [];
            case 'tools/list':
                return ['tools' => $this->toolList()];
            case 'tools/call':
                return $this->callTool($params);
            case 'resources/list':
                return ['resources' => $this->resources()];
            case 'resources/templates/list':
                return ['resourceTemplates' => []];
            case 'resources/read':
                return $this->readResource($params);
        }

        throw new RpcError(self::METHOD_NOT_FOUND, 'Method not found: ' . mb_substr($method, 0, 80, 'UTF-8'));
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     */
    private function initialize(array $params): array
    {
        $this->initialized = true;
        $asked = isset($params['protocolVersion']) && is_string($params['protocolVersion']) ? $params['protocolVersion'] : '';
        $version = in_array($asked, self::PROTOCOLS, true) ? $asked : self::PROTOCOLS[0];
        $shop = $this->app->settings()->str('store.name', 'AIFEC');

        return [
            'protocolVersion' => $version,
            'capabilities' => ['tools' => ['listChanged' => false], 'resources' => ['subscribe' => false, 'listChanged' => false]],
            'serverInfo' => ['name' => 'aifec', 'title' => $shop, 'version' => self::VERSION],
            'instructions' => 'This is the online shop ' . $shop . '. Use search_products and get_product to find things, then cart_add to build a cart (it belongs to this session). '
                . 'To buy: checkout_set_customer, checkout_shipping_options and checkout_set_shipping, checkout_payment_options and checkout_set_payment, then checkout_review. '
                . 'Show the person the total from the review and wait for a clear yes before calling checkout_place with the confirm_token and buyer_confirmed true. For security the shop emails a 6-digit verification code to the buyer when you review: tell the person, ask for it, wait, and send it as buyer_code (an API key with the scope orders:create does not need it). '
                . 'When the person describes a need instead of a product, show them two or three products of this shop that fit (name and price), when there are, and call cart_add only for the one they choose. '
                . 'The delivery needs a phone: the carrier asks for it. '
                . 'Prices always come from the shop: never calculate them. The person pays; checkout_link gives an address where they can pay on the web instead.',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function toolList(): array
    {
        $out = [];
        foreach ($this->agent->tools() as $name => $tool) {
            $out[] = [
                'name' => $name,
                'description' => $tool['description'],
                'inputSchema' => self::schema($tool['schema']),
                // Lo que el cliente puede saber antes de llamarla: si solo lee, si repetirla da lo mismo y que no sale de la tienda.
                'annotations' => [
                    'readOnlyHint' => !$tool['writes'],
                    'destructiveHint' => false,
                    'idempotentHint' => !$tool['writes'],
                    'openWorldHint' => false,
                ],
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     *
     * @throws RpcError
     */
    private function callTool(array $params): array
    {
        $name = isset($params['name']) && is_string($params['name']) ? $params['name'] : '';
        if ($name === '') {
            throw new RpcError(self::INVALID_PARAMS, 'Invalid params: the name of the tool is missing');
        }
        if (isset($params['arguments']) && (!is_array($params['arguments']) || ($params['arguments'] !== [] && array_keys($params['arguments']) === range(0, count($params['arguments']) - 1)))) {
            throw new RpcError(self::INVALID_PARAMS, 'Invalid params: arguments must be an object');
        }
        $arguments = isset($params['arguments']) && is_array($params['arguments']) ? $params['arguments'] : [];
        if (!isset($this->agent->tools()[$name])) {
            throw new RpcError(self::INVALID_PARAMS, 'Unknown tool: ' . mb_substr($name, 0, 60, 'UTF-8'));
        }
        $result = $this->agent->call($name, $arguments);
        $error = isset($result['error']);
        $json = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return [
            'content' => [['type' => 'text', 'text' => is_string($json) ? $json : '{}']],
            'structuredContent' => (object) $result,
            'isError' => $error,
        ];
    }

    /**
     * Los recursos: el llms.txt (si hay módulo semántico) y la información de la tienda.
     *
     * @return array<int,array<string,string>>
     */
    private function resources(): array
    {
        $out = [];
        if ($this->llms() !== null) {
            $out[] = ['uri' => 'aifec://llms.txt', 'name' => 'llms.txt', 'title' => 'What this shop offers to assistants', 'description' => 'A summary of the shop for language models: categories, best sellers, policies and where the feeds are.', 'mimeType' => 'text/plain'];
        }
        $out[] = ['uri' => 'aifec://store', 'name' => 'store', 'title' => 'Shop information', 'description' => 'Name, contact data, opening hours, returns policy and payment methods.', 'mimeType' => 'text/plain'];

        return $out;
    }

    /**
     * @param array<string,mixed> $params
     *
     * @return array<string,mixed>
     *
     * @throws RpcError
     */
    private function readResource(array $params): array
    {
        $uri = isset($params['uri']) && is_string($params['uri']) ? $params['uri'] : '';
        if ($uri === '') {
            throw new RpcError(self::INVALID_PARAMS, 'Invalid params: the uri is missing');
        }
        if ($uri === 'aifec://llms.txt' && ($text = $this->llms()) !== null) {
            return ['contents' => [['uri' => $uri, 'mimeType' => 'text/plain', 'text' => $text]]];
        }
        if ($uri === 'aifec://store') {
            $info = $this->app->service('tools')->call('store_info', ['topic' => 'all']);
            $lines = [];
            foreach ($info as $key => $value) {
                $lines[] = (string) $key . ': ' . (is_scalar($value) ? (string) $value : (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            }

            return ['contents' => [['uri' => $uri, 'mimeType' => 'text/plain', 'text' => implode("\n", $lines)]]];
        }

        throw new RpcError(self::RESOURCE_NOT_FOUND, 'Resource not found: ' . mb_substr($uri, 0, 100, 'UTF-8'));
    }

    /** El llms.txt de la tienda, o null si no hay módulo semántico. */
    private function llms(): ?string
    {
        if (!$this->app->has('semantic') || !class_exists('Aifec\\Modules\\Semantic\\Llms')) {
            return null;
        }

        return \Aifec\Modules\Semantic\Llms::build($this->app->service('semantic'));
    }

    /**
     * Un esquema de una herramienta como JSON Schema válido: un «properties» vacío sale como {} y no como [].
     *
     * @param array<string,mixed> $schema
     *
     * @return array<string,mixed>
     */
    public static function schema(array $schema): array
    {
        if (isset($schema['properties']) && is_array($schema['properties'])) {
            $properties = [];
            foreach ($schema['properties'] as $name => $property) {
                $properties[$name] = is_array($property) ? self::schema($property) : $property;
            }
            $schema['properties'] = $properties === [] ? new \stdClass() : $properties;
        }
        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::schema($schema['items']);
        }

        return $schema;
    }
}
