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

use Aifec\Front\Page;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las direcciones del MCP:
 *
 *   POST /mcp                    JSON-RPC 2.0, un mensaje o un lote. Sin ficha csrf: lo llaman programas.
 *   GET  /mcp                    405 con una explicación en JSON (este servidor no abre flujos).
 *   GET  /.well-known/mcp.json   dónde está el servidor y qué herramientas ofrece.
 *
 * La sesión (Mcp-Session-Id) la entrega initialize. La clave de la API (Authorization: Bearer) es opcional: sube el límite de uso
 * y es lo que permite a checkout_place hacer pedidos (con el ámbito «orders:create»). Una clave que se manda y no vale es un 401.
 */
final class McpController
{
    /** Lo más grande que se acepta en una petición, en bytes. */
    private const MAX_BODY = 262144;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function post(Request $request, array $params): Response
    {
        if (strlen($request->body) > self::MAX_BODY) {
            return $this->json(Server::error(null, Server::INVALID_REQUEST, 'Invalid Request: the body is too large'), 413);
        }
        $key = null;
        $auth = trim((string) $request->header('authorization', ''));
        if ($auth !== '') {
            $key = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) === 1 ? KeyAuth::authenticate($this->app, $m[1]) : null;
            if ($key === null) {
                $response = Response::json(['error' => ['status' => 401, 'message' => 'The key is wrong, switched off or revoked']], 401);
                $response->headers['WWW-Authenticate'] = 'Bearer realm="mcp"';

                return $response;
            }
            KeyAuth::touch($this->app, $key);
        }

        $decoded = json_decode($request->body, true);
        if (trim($request->body) === '' || json_last_error() !== JSON_ERROR_NONE) {
            return $this->json(Server::error(null, Server::PARSE_ERROR, 'Parse error: the body is not valid JSON'), 400);
        }
        if (!is_array($decoded)) {
            return $this->json(Server::error(null, Server::INVALID_REQUEST, 'Invalid Request: a JSON-RPC 2.0 object is expected'), 400);
        }

        $session = trim((string) $request->header('mcp-session-id', ''));
        $agent = new Agent($this->app, $session !== '' ? strtolower($session) : null, $key);
        $server = new Server($this->app, $agent);
        $batch = $decoded !== [] && array_keys($decoded) === range(0, count($decoded) - 1);
        $messages = $batch ? $decoded : [$decoded];
        if ($decoded === []) {
            return $this->json(Server::error(null, Server::INVALID_REQUEST, 'Invalid Request: an empty batch'), 400);
        }

        $writes = $server->needsCart($messages);
        if ($writes && $key === null && \Aifec\Kernel\BrowserCommerce::blocked($this->app, $request)) {
            return Response::json(['error' => ['status' => 403, 'message' => t('This browser cannot modify carts through assistant links.')]], 403)
                ->header('Cache-Control', 'no-store, private');
        }
        $agent->prepare($writes);
        $responses = [];
        foreach ($messages as $message) {
            $answer = $server->handle($message);
            if ($answer !== null) {
                $responses[] = $answer;
            }
        }
        $newSession = $server->initialized() ? Sessions::newId() : '';

        if ($responses === []) {
            // Solo notificaciones: aceptadas, sin cuerpo.
            $response = new Response('', 202);
        } elseif (!$batch) {
            // Un mensaje inválido (no era un objeto JSON-RPC) es un 400; un método que no existe sigue siendo un 200 con su error.
            $status = isset($responses[0]['error']) && $responses[0]['error']['code'] === Server::INVALID_REQUEST ? 400 : 200;
            $response = $this->json($responses[0], $status);
        } else {
            $response = $this->json($responses, 200);
        }
        if ($newSession !== '') {
            $response->headers['Mcp-Session-Id'] = $newSession;
        }

        return $response;
    }

    /**
     * @param array<string,string> $params
     */
    public function info(Request $request, array $params): Response
    {
        $url = $this->app->url()->abs('/mcp', [], '');
        $accept = strtolower((string) $request->header('accept'));
        if (strpos($accept, 'text/html') !== false && strpos($accept, 'text/event-stream') === false) {
            // Quien abre la dirección como una página (una persona, el lector de una IA de chat) recibe qué es y cómo se conecta,
            // en vez de un 405 que su asistente leía como «el servidor no funciona» (17/11/2026). Un cliente MCP pide JSON o
            // un flujo de eventos y sigue recibiendo el 405 que manda el protocolo.
            return $this->connectPage($url);
        }
        $response = Response::json(['error' => ['status' => 405, 'message' => 'This server speaks JSON-RPC 2.0 over HTTP: send your messages with POST ' . $url . ' (Content-Type: application/json). It opens no streams.'],
            'endpoint' => $url, 'description' => $this->app->url()->abs('/.well-known/mcp.json', [], '')], 405);
        $response->headers['Allow'] = 'POST';

        return $response;
    }

    /**
     * @param array<string,string> $params
     */
    public function wellKnown(Request $request, array $params): Response
    {
        $agent = new Agent($this->app, null, null);
        $tools = [];
        foreach ($agent->tools() as $name => $tool) {
            $tools[] = ['name' => $name, 'description' => $tool['description'], 'writes' => $tool['writes']];
        }
        $url = $this->app->url();
        $response = Response::json([
            'name' => $this->app->settings()->str('store.name', 'AIFEC'),
            'protocol' => 'mcp',
            'protocolVersion' => Server::PROTOCOLS[0],
            'transport' => 'json-rpc-over-http',
            'endpoint' => $url->abs('/mcp', [], ''),
            'authentication' => ['required' => false, 'scheme' => 'bearer', 'note' => 'Optional: an API key raises the usage limit. Placing an order needs the 6-digit code the shop emails to the buyer at the review (buyer_code), or a key with the scope orders:create.'],
            'tools' => $tools,
            'api' => $this->app->modules()->isLoaded('api') ? $url->abs('/api/openapi.json', [], '') : null,
        ]);
        $response->headers['Cache-Control'] = 'public, max-age=60';
        $response->headers['Access-Control-Allow-Origin'] = '*';
        unset($response->headers['Pragma'], $response->headers['Expires']);

        return $response;
    }

    /**
     * La ficha del servidor («server card»): qué es, dónde está y cómo se habla con él, antes de conectarse. Con el formato de
     * server.json del registro de MCP (remotes de tipo streamable-http) y las herramientas.
     *
     * @param array<string,string> $params
     */
    public function serverCard(Request $request, array $params): Response
    {
        $url = $this->app->url();
        $name = $this->app->settings()->str('store.name', 'AIFEC');
        $host = (string) parse_url($url->abs('/', [], ''), PHP_URL_HOST);
        $tools = [];
        foreach ((new Agent($this->app, null, null))->tools() as $tool => $info) {
            $tools[] = ['name' => $tool, 'description' => $info['description']];
        }
        $response = Response::json([
            '$schema' => 'https://static.modelcontextprotocol.io/schemas/2025-12-11/server.schema.json',
            'name' => implode('.', array_reverse(explode('.', $host))) . '/shop',
            'title' => $name,
            'description' => 'The online shop ' . $name . ': search the catalogue, fill a cart and prepare the order. The person confirms it with a code the shop emails them and pays on the page of the shop.',
            'version' => '1.0.0',
            'websiteUrl' => $url->abs('/', [], ''),
            'protocolVersions' => Server::PROTOCOLS,
            'remotes' => [['type' => 'streamable-http', 'url' => $url->abs('/mcp', [], '')]],
            'authentication' => ['required' => false],
            'icons' => [$this->iconEntry()],
            'tools' => $tools,
            'documentation' => $this->app->router()->has('semantic.llms') ? $url->abs('/llms.txt', [], '') : null,
        ]);
        $response->headers['Cache-Control'] = 'public, max-age=300';
        $response->headers['Access-Control-Allow-Origin'] = '*';
        unset($response->headers['Pragma'], $response->headers['Expires']);

        return $response;
    }

    /**
     * El icono del servidor MCP (/mcp/icon.svg): el que eligió la tienda o el de serie, un cuadrado con su inicial en SVG.
     *
     * @param array<string,string> $params
     */
    public function icon(Request $request, array $params): Response
    {
        $url = Page::iconUrl($this->app);
        if (strpos($url, 'data:') !== 0) {
            return Response::redirect($url, 302)->header('Cache-Control', 'public, max-age=86400');
        }

        return (new Response(Page::defaultIcon($this->app), 200, ['Content-Type' => 'image/svg+xml; charset=utf-8']))
            ->header('Cache-Control', 'public, max-age=86400')
            ->header('Access-Control-Allow-Origin', '*');
    }

    /**
     * La entrada «icons» de la ficha: el icono de la tienda con su tipo, o el de serie servido en /mcp/icon.svg.
     *
     * @return array<string,mixed>
     */
    private function iconEntry(): array
    {
        $chosen = Page::iconUrl($this->app);
        if (strpos($chosen, 'data:') !== 0) {
            $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp', 'svg' => 'image/svg+xml'];
            $ext = strtolower((string) pathinfo((string) parse_url($chosen, PHP_URL_PATH), PATHINFO_EXTENSION));
            if (isset($types[$ext])) {
                return ['src' => $this->app->url()->abs($chosen, [], ''), 'mimeType' => $types[$ext]];
            }
        }

        return ['src' => $this->app->url()->abs('/mcp/icon.svg', [], ''), 'mimeType' => 'image/svg+xml', 'sizes' => ['any']];
    }

    /**
     * El catálogo de lo que la tienda ofrece a la IA (/.well-known/ai-catalog.json): el servidor MCP con su ficha, la guía en
     * texto y la API.
     *
     * @param array<string,string> $params
     */
    public function aiCatalog(Request $request, array $params): Response
    {
        $url = $this->app->url();
        $settings = $this->app->settings();
        $store = $settings->str('store.name', 'AIFEC');
        $host = strtolower((string) preg_replace('/[^a-z0-9.-]/i', '', (string) parse_url($url->abs('/', [], ''), PHP_URL_HOST)));
        $host = $host !== '' ? $host : 'localhost';
        $q = static function (string $text) use ($store): string {
            return str_replace('{store}', $store, t($text));
        };
        // Cada entrada lleva lo que ARD exige (identificador con la forma urn:air:<dominio>:<espacio>:<nombre>, nombre, tipo IANA y
        // la dirección) y las consultas representativas con las que un buscador de agentes encuentra la entrada (23/11/2026).
        $entries = [['identifier' => 'urn:air:' . $host . ':server:mcp', 'displayName' => $store . ' MCP', 'type' => 'application/mcp-server-card+json',
            'url' => $url->abs('/.well-known/mcp/server-card.json', [], ''),
            'description' => t('Model Context Protocol server of the shop: search the catalogue, build a cart and get a checkout link'),
            'capabilities' => ['search_products', 'get_product', 'cart_add', 'checkout_link'],
            'representativeQueries' => [$q('Search products in {store}'), $q('Add a product of {store} to a cart'), $q('Get a checkout link for a {store} cart')]]];
        if ($this->app->router()->has('semantic.llms')) {
            $entries[] = ['identifier' => 'urn:air:' . $host . ':docs:llms-txt', 'displayName' => $store . ' llms.txt', 'type' => 'text/plain',
                'url' => $url->abs('/llms.txt', [], ''), 'description' => t('Summary of the shop for language models: categories, best sellers, policies and the doors for agents'),
                'representativeQueries' => [$q('What does {store} sell?'), $q('Read the policies and how to buy at {store}')]];
        }
        if ($this->app->modules()->isLoaded('api')) {
            $entries[] = ['identifier' => 'urn:air:' . $host . ':api:openapi', 'displayName' => $store . ' API', 'type' => 'application/vnd.oai.openapi+json',
                'url' => $url->abs('/api/openapi.json', [], ''), 'description' => t('Description of the shop API: catalogue search, carts and checkout'),
                'representativeQueries' => [$q('Search the {store} catalogue through an API'), $q('Create a cart and check out at {store} through an API')]];
        }
        if ($this->app->modules()->isLoaded('agentic') && $settings->bool('agentic.ucp_enabled', false)) {
            $entries[] = ['identifier' => 'urn:air:' . $host . ':commerce:ucp', 'displayName' => $store . ' UCP', 'type' => 'application/json',
                'url' => $url->abs('/.well-known/ucp', [], ''), 'description' => t('Universal Commerce Protocol profile of the shop'),
                'representativeQueries' => [$q('Buy from {store} with the Universal Commerce Protocol')]];
        }
        if ($this->app->modules()->isLoaded('agentic') && $settings->bool('agentic.acp_enabled', false)) {
            $entries[] = ['identifier' => 'urn:air:' . $host . ':commerce:acp', 'displayName' => $store . ' ACP', 'type' => 'application/json',
                'url' => $url->abs('/.well-known/acp.json', [], ''), 'description' => t('Agentic Commerce Protocol profile of the shop'),
                'representativeQueries' => [$q('Buy from {store} with the Agentic Commerce Protocol')]];
        }
        $response = Response::json(['version' => '1.0', 'entries' => $entries]);
        $response->headers['Cache-Control'] = 'public, max-age=300';
        $response->headers['Access-Control-Allow-Origin'] = '*';
        unset($response->headers['Pragma'], $response->headers['Expires']);

        return $response;
    }

    /**
     * La línea con la clave pública que el registro oficial de MCP lee para comprobar el dominio: texto plano, 200 y sin
     * redirecciones (el registro no las sigue); 404 si la tienda no la tiene.
     *
     * @param array<string,string> $params
     */
    public function registryAuth(Request $request, array $params): Response
    {
        $line = trim($this->app->settings()->str('mcp.registry_auth', ''));
        if (preg_match('/^v=MCPv1; k=(ed25519|ecdsap384); p=[A-Za-z0-9+\/=]+$/', $line) !== 1) {
            return Response::text('Not found', 404, 'text/plain');
        }
        $response = Response::text($line, 200, 'text/plain');
        $response->headers['Cache-Control'] = 'no-cache';

        return $response;
    }

    /** Lo que ve quien abre /mcp como una página: qué es y cómo se conecta un asistente. */
    private function connectPage(string $url): Response
    {
        $name = $this->app->settings()->str('store.name', 'AIFEC');
        $lines = [
            '# ' . t('MCP server of {shop}', ['shop' => $name]),
            '',
            t('Address: {url} · Model Context Protocol over HTTP (JSON-RPC by POST) · no key needed.', ['url' => $url]),
            '',
            '## ' . t('How to connect it'),
            '',
            '- ' . t('Claude: in the settings, «Connectors», add a custom connector with this address.'),
            '- ' . t('ChatGPT: in the settings of apps and connectors (developer mode), create a connector with this address.'),
            '- ' . t('Other MCP clients (Gemini CLI, Cursor, VS Code…): add an HTTP server with this address.'),
            '',
            t('With it, the assistant searches the catalogue, fills a cart and prepares the order; the person confirms it with a code the shop emails them and pays on the page of the shop.'),
            '',
            t('Server card: {card}', ['card' => $this->app->url()->abs('/.well-known/mcp/server-card.json', [], '')]),
        ];
        if ($this->app->modules()->isLoaded('api')) {
            $lines[] = '';
            $lines[] = t('Without connectors, an assistant can also buy here by opening addresses: {url}', ['url' => $this->app->url()->abs('/buy-with-links', [], '')]);
        }
        $body = implode("\n", $lines) . "\n";
        $html = '<!doctype html><html lang="' . e($this->app->lang()) . '"><head><meta charset="utf-8"><meta name="robots" content="noindex">'
            . '<title>' . e(t('MCP server of {shop}', ['shop' => $name])) . '</title></head><body><pre style="white-space:pre-wrap;font:15px/1.5 system-ui,sans-serif;max-width:46rem;margin:2rem auto">'
            . e($body) . '</pre></body></html>';
        $response = Response::html($html, 200);
        $response->headers['Cache-Control'] = 'public, max-age=300';

        return $response;
    }

    /**
     * @param array<mixed> $payload
     */
    private function json(array $payload, int $status): Response
    {
        return Response::json($payload, $status);
    }
}
