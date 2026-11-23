<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Cli\Console;
use Aifec\Modules\Mcp\Server;

require_once __DIR__ . '/helpers.php';

test('initialize: la versión del protocolo, las capacidades, quién es el servidor y la sesión; una versión vieja conocida se respeta y una desconocida se cambia', function (): void {
    $app = mcpApp();

    $r = mcpPost($app, ['jsonrpc' => '2.0', 'id' => 7, 'method' => 'initialize', 'params' => ['protocolVersion' => '2025-06-18', 'capabilities' => [], 'clientInfo' => ['name' => 'cliente-de-pruebas', 'version' => '1']]]);
    $json = json_decode($r->body, true);

    same(200, $r->status);
    same('2.0', $json['jsonrpc']);
    same(7, $json['id']);
    same('2025-06-18', $json['result']['protocolVersion']);
    same(false, $json['result']['capabilities']['tools']['listChanged']);
    check(isset($json['result']['capabilities']['resources']), 'dice que ofrece recursos');
    same('aifec', $json['result']['serverInfo']['name']);
    same('Muebles Ejemplo', $json['result']['serverInfo']['title']);
    same('1.0.0', $json['result']['serverInfo']['version']);
    contains('checkout_place', $json['result']['instructions']);
    contains('Muebles Ejemplo', $json['result']['instructions']);
    check(preg_match('/^[a-f0-9]{32}$/D', $r->headers['Mcp-Session-Id']) === 1, 'entrega la sesión en Mcp-Session-Id');
    contains('no-store', $r->headers['Cache-Control']);

    $old = mcpRpc($app, 'initialize', ['protocolVersion' => '2025-03-26']);
    same('2025-03-26', $old['result']['protocolVersion'], 'la que pide, si es anterior y la conoce');
    $oldest = mcpRpc($app, 'initialize', ['protocolVersion' => '2024-11-05']);
    same('2024-11-05', $oldest['result']['protocolVersion']);
    $future = mcpRpc($app, 'initialize', ['protocolVersion' => '2099-01-01']);
    same('2025-06-18', $future['result']['protocolVersion'], 'una desconocida: la suya');
    same('2025-06-18', mcpRpc($app, 'initialize')['result']['protocolVersion'], 'sin pedir ninguna');
    $two = mcpPost($app, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
    check($two->headers['Mcp-Session-Id'] !== $r->headers['Mcp-Session-Id'], 'cada initialize abre su sesión');
});

test('notifications/initialized se acepta con un 202 sin cuerpo; ping contesta vacío; una notificación desconocida tampoco se contesta', function (): void {
    $app = mcpApp();

    $note = mcpPost($app, ['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    same(202, $note->status);
    same('', $note->body);
    $other = mcpPost($app, ['jsonrpc' => '2.0', 'method' => 'notifications/lo-que-sea', 'params' => ['x' => 1]]);
    same(202, $other->status);

    $ping = mcpRpc($app, 'ping');
    same(1, $ping['id']);
    same([], $ping['result']);
    check(!isset($ping['error']), 'sin error');
    // Y en el cable es un objeto vacío, como dice el protocolo: «[]» lo marcan los comprobadores (17/11/2026).
    contains('"result":{}', mcpPost($app, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->body);
});

test('tools/list: cada herramienta con nombre, descripción y esquema de entrada válido; por defecto todas menos order_status', function (): void {
    $app = mcpApp();

    $r = mcpPost($app, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']);
    $json = json_decode($r->body, true);
    $tools = [];
    foreach ($json['result']['tools'] as $tool) {
        $tools[$tool['name']] = $tool;
    }

    same(18, count($tools));
    foreach (['search_products', 'get_product', 'list_categories', 'check_availability', 'shipping_quote', 'store_info', 'cart_view', 'cart_add', 'cart_update', 'checkout_link', 'checkout_review', 'checkout_place'] as $name) {
        check(isset($tools[$name]), $name . ' está');
    }
    check(!isset($tools['order_status']), 'order_status no, por defecto');
    foreach ($tools as $name => $tool) {
        check($tool['description'] !== '', $name . ' tiene descripción');
        same('object', $tool['inputSchema']['type'], $name);
        check(is_bool($tool['annotations']['readOnlyHint']), $name . ' dice si solo lee');
        // Los directorios de MCP (mcptry) puntúan las anotaciones y que cada argumento diga qué es: los agentes eligen por ahí.
        same(false, $tool['annotations']['destructiveHint'], $name);
        check(is_bool($tool['annotations']['idempotentHint']) && $tool['annotations']['openWorldHint'] === false, $name . ' anotaciones completas');
        foreach (is_array($tool['inputSchema']['properties']) ? $tool['inputSchema']['properties'] : [] as $arg => $spec) {
            check(isset($spec['description']) && trim((string) $spec['description']) !== '', $name . '.' . $arg . ' dice qué es');
        }
    }
    same(true, $tools['search_products']['annotations']['readOnlyHint']);
    same(false, $tools['cart_add']['annotations']['readOnlyHint']);
    same('string', $tools['search_products']['inputSchema']['properties']['query']['type']);
    same(['product_id'], $tools['cart_add']['inputSchema']['required']);
    same(['confirm_token', 'buyer_confirmed'], $tools['checkout_place']['inputSchema']['required'], 'place pide la ficha y la declaración de la persona');
    contains('confirm_token', $tools['checkout_review']['description']);
    // «properties» es siempre un objeto, nunca una lista: si no, el esquema no es válido. Una herramienta sin entradas propias
    // lleva solo el session_id para los clientes que no guardan la sesión (17/11/2026).
    contains('"name":"list_categories"', $r->body);
    check(preg_match('/"name":"list_categories".{0,2000}?"inputSchema":\{"type":"object","properties":\{"session_id":/s', $r->body) === 1, 'properties como objeto, con session_id');
    notContains('"properties":[]', $r->body);
});

test('tools/call de search_products devuelve productos, con el precio en céntimos y formateado, en texto y en contenido estructurado', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);

    $result = mcpTool($app, 'search_products', ['query' => 'silla roja']);

    same(false, $result['isError']);
    $items = $result['structuredContent']['items'];
    same($ids['silla_roja'], $items[0]['id']);
    same('Silla roja', $items[0]['name']);
    same(5990, $items[0]['price_cents']);
    contains('59,90', $items[0]['price']);
    contains('Silla roja', $result['content'][0]['text']);
    contains('https://tienda.test/', $items[0]['url']);

    $product = mcpTool($app, 'get_product', ['id' => $ids['silla_roja']]);
    same('SIL-001', $product['structuredContent']['sku']);
    $categories = mcpTool($app, 'list_categories');
    check(count($categories['structuredContent']['categories']) >= 3, 'las categorías');
    $info = mcpTool($app, 'store_info');
    same('Muebles Ejemplo', $info['structuredContent']['name']);
});

test('una herramienta desactivada en los ajustes no sale en la lista y llamarla da error; una desconocida, también', function (): void {
    $app = mcpApp(['mcp.tools' => ['search_products', 'get_product']]);
    assistantShop($app);

    $names = array_column(mcpRpc($app, 'tools/list')['result']['tools'], 'name');
    same(['search_products', 'get_product'], $names);

    $off = mcpRpc($app, 'tools/call', ['name' => 'cart_add', 'arguments' => ['product_id' => 1]]);
    same(-32602, $off['error']['code']);
    contains('Unknown tool', $off['error']['message']);
    check(!isset($off['result']), 'sin resultado');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'y no hizo nada');
    same(-32602, mcpRpc($app, 'tools/call', ['name' => 'no_existe'])['error']['code']);
    same(-32602, mcpRpc($app, 'tools/call', ['name' => 'order_status', 'arguments' => ['number' => 'A-1', 'email' => 'x@example.com']])['error']['code'], 'order_status está fuera por defecto');
    same(false, mcpTool($app, 'search_products', ['query' => 'silla'])['isError'], 'las permitidas siguen valiendo');

    $on = mcpApp(['mcp.tools' => array_merge(\Aifec\Modules\Mcp\Agent::DEFAULT_TOOLS, ['order_status'])]);
    contains('order_status', (string) json_encode(array_column(mcpRpc($on, 'tools/list')['result']['tools'], 'name')), 'se puede activar');
});

test('unos argumentos que no cumplen el esquema son un fallo de la herramienta (isError), no del protocolo', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);

    foreach ([['get_product', []], ['get_product', ['id' => 'abc']], ['get_product', ['id' => 0]], ['search_products', ['query' => 'silla', 'precio' => 1]], ['cart_add', ['product_id' => $ids['mesa'], 'price' => 1]],
        ['check_availability', ['product_id' => $ids['mesa'], 'qty' => 5000]]] as [$tool, $args]) {
        $json = mcpRpc($app, 'tools/call', ['name' => $tool, 'arguments' => $args], ['mcp-session-id' => mcpSession($app)]);
        check(isset($json['result']) && !isset($json['error']), $tool . ' no da un error del protocolo: ' . json_encode($json));
        same(true, $json['result']['isError'], $tool . ' ' . json_encode($args));
        check(isset($json['result']['structuredContent']['error']), 'dice por qué');
    }
    $missing = mcpRpc($app, 'tools/call', ['arguments' => []]);
    same(-32602, $missing['error']['code'], 'sin nombre de herramienta, parámetros inválidos');
    same(-32602, mcpRpc($app, 'tools/call', ['name' => 'get_product', 'arguments' => [1, 2]])['error']['code'], 'los argumentos tienen que ser un objeto');
});

test('un método que no existe da -32601; un JSON roto, -32700; lo que no es JSON-RPC, -32600', function (): void {
    $app = mcpApp();

    $unknown = mcpRpc($app, 'lo/que-sea');
    same(-32601, $unknown['error']['code']);
    same(1, $unknown['id']);
    contains('lo/que-sea', $unknown['error']['message']);
    same(200, mcpPost($app, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'lo/que-sea'])->status);

    $broken = mcpPost($app, '{"jsonrpc":"2.0","id":1,"method":"ping"');
    same(400, $broken->status);
    $json = json_decode($broken->body, true);
    same(-32700, $json['error']['code']);
    same(null, $json['id']);
    same(-32700, json_decode(mcpPost($app, 'esto no es json')->body, true)['error']['code']);
    same(-32700, json_decode(mcpPost($app, '')->body, true)['error']['code'], 'un cuerpo vacío tampoco es JSON');

    foreach ([['id' => 1, 'method' => 'ping'], ['jsonrpc' => '1.0', 'id' => 1, 'method' => 'ping'], ['jsonrpc' => '2.0', 'id' => 1], ['jsonrpc' => '2.0', 'id' => 1, 'method' => 5], ['jsonrpc' => '2.0', 'id' => [1], 'method' => 'ping']] as $bad) {
        $r = mcpPost($app, $bad);
        same(400, $r->status, json_encode($bad));
        same(-32600, json_decode($r->body, true)['error']['code'], json_encode($bad));
    }
    same(-32600, json_decode(mcpPost($app, '123')->body, true)['error']['code']);
    same(-32600, json_decode(mcpPost($app, '[]')->body, true)['error']['code'], 'un lote vacío');
    same(-32602, mcpRpc($app, 'resources/read', [])['error']['code']);
});

test('un lote de dos peticiones da dos respuestas en su orden; las notificaciones del lote no se contestan', function (): void {
    $app = mcpApp();
    assistantShop($app);

    $r = mcpPost($app, [
        ['jsonrpc' => '2.0', 'id' => 'a', 'method' => 'ping'],
        ['jsonrpc' => '2.0', 'method' => 'notifications/initialized'],
        ['jsonrpc' => '2.0', 'id' => 'b', 'method' => 'tools/call', 'params' => ['name' => 'search_products', 'arguments' => ['query' => 'mesa']]],
        ['jsonrpc' => '2.0', 'id' => 'c', 'method' => 'no/existe'],
    ]);
    $json = json_decode($r->body, true);

    same(200, $r->status);
    same(3, count($json));
    same(['a', 'b', 'c'], array_column($json, 'id'));
    same([], $json[0]['result']);
    same(false, $json[1]['result']['isError']);
    same(-32601, $json[2]['error']['code']);
    same(202, mcpPost($app, [['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], ['jsonrpc' => '2.0', 'method' => 'notifications/cancelled']])->status, 'solo notificaciones: aceptadas');
    $mixed = json_decode(mcpPost($app, [['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], 5, 'x'])->body, true);
    same(3, count($mixed));
    same(-32600, $mixed[1]['error']['code'], 'un elemento que no es una petición, error propio sin tumbar el resto');
    same([], $mixed[0]['result']);
});

test('el carrito es de la sesión: cart_add y checkout_link con la misma sesión funcionan, con otra el carrito está vacío', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $one = mcpSession($app);
    $two = mcpSession($app);

    $added = mcpTool($app, 'cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 2], $one);
    same(false, $added['isError'], json_encode($added));
    same(2, $added['structuredContent']['cart']['count']);
    same(11980, $added['structuredContent']['cart']['items_total_cents']);

    $view = mcpTool($app, 'cart_view', [], $one)['structuredContent'];
    same(1, count($view['lines']));
    same(5990, $view['lines'][0]['unit_price_cents']);
    $link = mcpTool($app, 'checkout_link', [], $one);
    same(false, $link['isError']);
    // La caja de ESTE carrito, no la caja a secas (que abre el carrito del navegador de la persona, 17/11/2026).
    contains('https://tienda.test/carrito/retomar/', $link['structuredContent']['url']);
    same(2, $link['structuredContent']['cart']['count']);

    same(0, mcpTool($app, 'cart_view', [], $two)['structuredContent']['count'], 'otra sesión no ve este carrito');
    same(true, mcpTool($app, 'checkout_link', [], $two)['isError'], 'y no tiene nada que pagar');
    same(0, mcpTool($app, 'cart_view')['structuredContent']['count'], 'sin sesión, tampoco');
    $row = $app->db()->row('SELECT * FROM {carts}');
    same('mcp', $row['source']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'solo hay un carrito: leer no crea ninguno');
    notContains($one, (string) json_encode($row), 'el identificador de la sesión no se guarda');

    $changed = mcpTool($app, 'cart_update', ['key' => $view['lines'][0]['key'], 'qty' => 3], $one);
    same(17970, $changed['structuredContent']['cart']['items_total_cents']);
});

test('con una sesión mal formada en la cabecera, una herramienta que escribe en el carrito da error y no crea nada', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);

    $bad = mcpTool($app, 'cart_add', ['product_id' => $ids['mesa']], 'esto-no-es-una-sesion');
    same(true, $bad['isError']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'));
});

test('un cliente que no guarda la cabecera de sesión (el de ChatGPT) compra igual: cart_add le da un session_id y con él sigue el mismo carrito', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    // Cada herramienta anuncia el argumento session_id.
    $list = mcpRpc($app, 'tools/list', []);
    $schemas = [];
    foreach ($list['result']['tools'] as $tool) {
        $schemas[$tool['name']] = $tool['inputSchema'];
    }
    check(isset($schemas['cart_view']['properties']['session_id'], $schemas['checkout_link']['properties']['session_id']), 'session_id en el esquema');

    $added = mcpTool($app, 'cart_add', ['product_id' => $ids['silla_roja'], 'qty' => 2]);
    same(false, $added['isError'], (string) json_encode($added['structuredContent']));
    $session = (string) ($added['structuredContent']['session_id'] ?? '');
    check(preg_match('/^[a-f0-9]{32}$/', $session) === 1, 'devuelve la sesión: ' . $session);
    contains('session_id', (string) $added['structuredContent']['session_note']);
    // Y la caja de ese carrito ya en la respuesta: el ChatGPT con la lista de herramientas de antes no puede mandar session_id (R79).
    $direct = (string) ($added['structuredContent']['checkout_url'] ?? '');
    contains('/carrito/retomar/', $direct, 'cart_add devuelve la caja de su carrito');
    $browser = ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36', 'accept' => 'text/html'];
    $navigation = $browser + ['sec-fetch-user' => '?1', 'sec-fetch-dest' => 'document', 'sec-fetch-mode' => 'navigate'];
    $path = (string) preg_replace('#^https?://[^/]+#', '', $direct);
    same(403, apiCall($app, 'GET', $path, null, $browser)->status, 'una apertura automatizada no se queda con el carrito');
    $opened = apiCall($app, 'GET', $path, null, $navigation);
    contains('finalizar-compra', (string) ($opened->headers['Location'] ?? ''), 'y abre la caja');
    $view = mcpTool($app, 'cart_view', ['session_id' => $session]);
    contains((string) $ids['silla_roja'], (string) json_encode($view['structuredContent']), 'con la sesión por argumento, el mismo carrito');
    $link = mcpTool($app, 'checkout_link', ['session_id' => $session]);
    contains('/carrito/retomar/', (string) $link['structuredContent']['url'], 'y la caja de ese carrito');
    // Y la persona que la abre llega a la caja con ese carrito.
    $resume = apiCall($app, 'GET', (string) preg_replace('#^https?://[^/]+#', '', (string) $link['structuredContent']['url']), null,
        $navigation);
    check(in_array($resume->status, [302, 303], true), 'la caja: ' . $resume->status);
    contains('finalizar-compra', (string) ($resume->headers['Location'] ?? ''));
    // Sin la sesión, un carrito vacío: no se mezclan.
    $other = mcpTool($app, 'cart_view', []);
    notContains('"product_id":' . $ids['silla_roja'], (string) json_encode($other['structuredContent']));
});

test('quitar la última línea no pierde el carrito de la sesión; y dos escrituras seguidas en una petición, tras vaciarlo, se rechazan en vez de perder lo añadido', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $sid = mcpSession($app);
    $key = mcpTool($app, 'cart_add', ['product_id' => $ids['mesa']], $sid)['structuredContent']['key'];

    $removed = mcpTool($app, 'cart_update', ['key' => $key, 'qty' => 0], $sid);
    same(false, $removed['isError']);
    same(0, $removed['structuredContent']['cart']['count']);
    $again = mcpTool($app, 'cart_add', ['product_id' => $ids['silla_roja']], $sid);
    same(false, $again['isError']);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'sigue siendo el mismo carrito');
    same(1, mcpTool($app, 'cart_view', [], $sid)['structuredContent']['count'], 'y lo añadido está');

    $newKey = mcpTool($app, 'cart_view', [], $sid)['structuredContent']['lines'][0]['key'];
    $r = mcpPost($app, [
        ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'cart_update', 'arguments' => ['key' => $newKey, 'qty' => 0]]],
        ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => ['name' => 'cart_add', 'arguments' => ['product_id' => $ids['mesa']]]],
    ], ['mcp-session-id' => $sid]);
    $json = json_decode($r->body, true);
    same(false, $json[0]['result']['isError']);
    same(true, $json[1]['result']['isError'], 'la segunda se rechaza');
    contains('new request', $json[1]['result']['structuredContent']['error']);
    same(0, mcpTool($app, 'cart_view', [], $sid)['structuredContent']['count'], 'no se perdió nada: el carrito está vacío y es el de la sesión');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'no hay carritos sueltos');
});

test('los recursos: la información de la tienda como texto y, con el módulo semántico, el llms.txt; uno que no existe da -32002', function (): void {
    $app = mcpApp();
    assistantShop($app);

    $list = mcpRpc($app, 'resources/list')['result']['resources'];
    same(['aifec://store'], array_column($list, 'uri'), 'sin módulo semántico, solo la información de la tienda');
    $store = mcpRpc($app, 'resources/read', ['uri' => 'aifec://store'])['result']['contents'][0];
    same('aifec://store', $store['uri']);
    same('text/plain', $store['mimeType']);
    contains('name: Muebles Ejemplo', $store['text']);
    contains('999000001', $store['text']);
    $missing = mcpRpc($app, 'resources/read', ['uri' => 'aifec://llms.txt']);
    same(-32002, $missing['error']['code'], 'sin módulo semántico no hay llms.txt');
    same(-32002, mcpRpc($app, 'resources/read', ['uri' => 'aifec://otra-cosa'])['error']['code']);
    same([], mcpRpc($app, 'resources/templates/list')['result']['resourceTemplates']);

    $withSemantic = mcpApp([], ['semantic']);
    assistantShop($withSemantic);
    $uris = array_column(mcpRpc($withSemantic, 'resources/list')['result']['resources'], 'uri');
    same(['aifec://llms.txt', 'aifec://store'], $uris);
    $llms = mcpRpc($withSemantic, 'resources/read', ['uri' => 'aifec://llms.txt'])['result']['contents'][0];
    same('text/plain', $llms['mimeType']);
    contains('# Muebles Ejemplo', $llms['text']);
    contains('Silla roja', $llms['text']);
});

test('GET /mcp da 405 con una explicación en JSON; /.well-known/mcp.json dice dónde está el servidor y qué herramientas ofrece', function (): void {
    $app = mcpApp();

    $get = apiCall($app, 'GET', '/mcp');
    same(405, $get->status);
    same('POST', $get->headers['Allow']);
    $json = apiJson($get);
    same(405, $json['error']['status']);
    same('https://tienda.test/mcp', $json['endpoint']);
    contains('JSON-RPC', $json['error']['message']);

    $well = apiCall($app, 'GET', '/.well-known/mcp.json');
    $d = apiJson($well);
    same(200, $well->status);
    same('https://tienda.test/mcp', $d['endpoint']);
    same('2025-06-18', $d['protocolVersion']);
    same('https://tienda.test/api/openapi.json', $d['api']);
    same(18, count($d['tools']));
    same('search_products', $d['tools'][0]['name']);
    same(false, $d['tools'][0]['writes']);
    same(false, $d['authentication']['required']);
    contains('public', $well->headers['Cache-Control']);
});

test('las IA encuentran el MCP: ficha del servidor en las direcciones de descubrimiento, catálogo de IA, /mcp como página y el llms.txt lo pone arriba', function (): void {
    $app = mcpApp();
    foreach (['/.well-known/mcp/server-card.json', '/.well-known/mcp-server-card', '/.well-known/mcp', '/mcp/server-card'] as $path) {
        $card = apiCall($app, 'GET', $path);
        same(200, $card->status, $path);
        $c = apiJson($card);
        same('streamable-http', $c['remotes'][0]['type'], $path);
        same('https://tienda.test/mcp', $c['remotes'][0]['url'], $path);
        same(false, $c['authentication']['required']);
        check(count($c['tools']) >= 10, 'con sus herramientas');
        same('https://tienda.test/mcp/icon.svg', $c['icons'][0]['src'], 'con su icono');
        same('image/svg+xml', $c['icons'][0]['mimeType']);
    }
    $icon = apiCall($app, 'GET', '/mcp/icon.svg');
    same(200, $icon->status);
    contains('image/svg+xml', (string) $icon->headers['Content-Type']);
    contains('<svg', $icon->body);
    $catalog = apiJson(apiCall($app, 'GET', '/.well-known/ai-catalog.json'));
    // ARD (Agentic Resource Discovery): cada entrada lleva identificador urn:air, nombre, tipo IANA, dirección y consultas
    // representativas; el mismo catálogo sale en /.well-known/ard.json (23/11/2026: la auditoría externa lo daba por inválido).
    same('application/mcp-server-card+json', $catalog['entries'][0]['type']);
    same('https://tienda.test/.well-known/mcp/server-card.json', $catalog['entries'][0]['url']);
    foreach ($catalog['entries'] as $entry) {
        check(preg_match('/^urn:air:[a-z0-9.-]+:[a-z0-9:_-]+$/', (string) ($entry['identifier'] ?? '')) === 1, 'identificador ARD: ' . json_encode($entry));
        check(trim((string) ($entry['displayName'] ?? '')) !== '', 'nombre ARD');
        check(preg_match('#^[a-z]+/[a-z0-9.+-]+$#', (string) ($entry['type'] ?? '')) === 1, 'tipo IANA: ' . ($entry['type'] ?? ''));
        check(isset($entry['representativeQueries']) && count($entry['representativeQueries']) >= 1, 'consultas representativas');
        check(isset($entry['url']) && !isset($entry['data']), 'una sola de url y data');
    }
    same($catalog, apiJson(apiCall($app, 'GET', '/.well-known/ard.json')));
    // Abierta como una página (una persona o el lector de una IA de chat), /mcp explica qué es y cómo se conecta; un cliente MCP
    // (JSON o flujo de eventos) sigue recibiendo el 405 del protocolo.
    $page = apiCall($app, 'GET', '/mcp', null, ['accept' => 'text/html,application/xhtml+xml']);
    same(200, $page->status);
    contains('https://tienda.test/mcp', $page->body);
    contains('conector personalizado', $page->body);
    contains('/buy-with-links', $page->body, 'y la compra con enlaces para quien no tiene conectores');
    same(405, apiCall($app, 'GET', '/mcp', null, ['accept' => 'text/event-stream'])->status);
    same(405, apiCall($app, 'GET', '/mcp', null, ['accept' => 'application/json'])->status);
    // Cada página lo dice en su cabecera.
    $home = apiCall($app, 'GET', '/', null, ['accept' => 'text/html']);
    contains('<link rel="mcp" href="https://tienda.test/mcp">', $home->body, 'la cabecera de la portada');
    contains('title="MCP server card" href="https://tienda.test/.well-known/mcp/server-card.json"', $home->body);
    // Y la tienda cerrada a los buscadores deja leer el servidor y su ficha a cualquiera.
    $closed = mcpApp(['seo.noindex_site' => true], ['seo']);
    $robots = apiCall($closed, 'GET', '/robots.txt')->body;
    contains("Allow: /mcp\n", $robots, $robots);
    contains("Allow: /.well-known/\n", $robots);
    // Y el llms.txt lo dice lo primero, tras el resumen.
    $llms = apiCall($app, 'GET', '/llms.txt');
    if ($llms->status === 200) {
        $pos = strpos($llms->body, 'Servidor MCP (sin clave)');
        check($pos !== false && $pos < 600, 'el llms.txt lo pone arriba: ' . substr($llms->body, 0, 400));
    }
});

test('/.well-known/mcp-registry-auth publica la clave del registro oficial en texto plano; sin ella o mal escrita, 404', function (): void {
    same(404, apiCall(mcpApp(), 'GET', '/.well-known/mcp-registry-auth')->status, 'sin clave');
    same(404, apiCall(mcpApp(['mcp.registry_auth' => 'cualquier cosa']), 'GET', '/.well-known/mcp-registry-auth')->status, 'mal escrita');
    $line = 'v=MCPv1; k=ed25519; p=CmELkdW27Q+pySZRD1ezkXAG/SbeDhGWAjtuKKMKixg=';
    $r = apiCall(mcpApp(['mcp.registry_auth' => $line]), 'GET', '/.well-known/mcp-registry-auth');
    same(200, $r->status);
    same($line, trim($r->body));
    contains('text/plain', (string) $r->headers['Content-Type']);
});

test('con el MCP apagado en los ajustes, sus tres direcciones dan 404 en JSON', function (): void {
    $app = mcpApp(['mcp.enabled' => false]);

    $post = mcpPost($app, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']);
    same(404, $post->status);
    same(404, apiJson($post)['error']['status']);
    same(404, apiCall($app, 'GET', '/mcp')->status);
    same(404, apiCall($app, 'GET', '/.well-known/mcp.json')->status);
});

test('el límite de uso: pasado el tope por minuto, 429 con Retry-After; con el MCP el POST no pide ficha csrf', function (): void {
    $app = mcpApp(['api.rate_per_minute' => 2]);

    same(200, mcpPost($app, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'])->status, 'sin ficha csrf ni cookies');
    same(200, mcpPost($app, ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'])->status);
    $r = mcpPost($app, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping']);
    same(429, $r->status);
    same('60', $r->headers['Retry-After']);
    same(200, mcpPost($app, ['jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping'], [], '203.0.113.7')->status, 'otra dirección');
});

test('una clave mala en Authorization da 401; una buena se acepta y se apunta su uso', function (): void {
    $app = mcpApp();
    $key = apiKey($app);

    $bad = mcpPost($app, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['authorization' => 'Bearer aifec_api_mentira']);
    same(401, $bad->status);
    contains('Bearer', $bad->headers['WWW-Authenticate']);
    same(401, mcpPost($app, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['authorization' => 'Basic abc'])->status);
    $good = mcpPost($app, ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], ['authorization' => 'Bearer ' . $key]);
    same(200, $good->status);
    check($app->db()->value('SELECT last_used_at FROM {api_keys}') !== null, 'se apunta el uso');
});

test('mcp:call llama a una herramienta desde la consola y enseña el resultado; sin herramienta o con un JSON roto, error', function (): void {
    $app = mcpApp();
    $ids = assistantShop($app);
    $io = new Console(false);
    $commands = $io->commands($app);
    check(isset($commands['mcp:call']) && $commands['mcp:call']['help'] !== '', 'la orden existe y tiene ayuda');

    $code = $commands['mcp:call']['run']($app, ['search_products', '{"query":"silla roja"}'], $io);
    same(0, $code, $io->output);
    contains('"name": "Silla roja"', $io->output);
    contains('"price_cents": 5990', $io->output);
    contains('session: ', $io->output);

    $io2 = new Console(false);
    $session = Aifec\Modules\Mcp\Sessions::newId();
    same(0, $commands['mcp:call']['run']($app, ['cart_add', '{"product_id":' . $ids['mesa'] . '}', $session], $io2), $io2->output);
    $io3 = new Console(false);
    same(0, $commands['mcp:call']['run']($app, ['cart_view', '{}', $session], $io3));
    contains('"count": 1', $io3->output, 'la misma sesión ve su carrito');

    $io4 = new Console(false);
    same(1, $commands['mcp:call']['run']($app, ['get_product', '{}'], $io4), 'un fallo de la herramienta sale con 1');
    $io5 = new Console(false);
    same(2, $commands['mcp:call']['run']($app, ['search_products', '{roto'], $io5));
    $io6 = new Console(false);
    same(2, $commands['mcp:call']['run']($app, [], $io6));
    $io7 = new Console(false);
    same(1, $commands['mcp:call']['run']($app, ['no_existe', '{}'], $io7));
    contains('-32602', $io7->output);
});

test('el módulo declara sus ajustes, su permiso y su menú; quien no puede cambiarlos recibe 403', function (): void {
    $app = mcpApp();
    $web = adminClient($app);

    $form = $web->get('/admin/settings/mcp');
    same(200, $form->status);
    contains('mcgeneral', $form->body);
    contains('mctools', $form->body);
    contains('Buscar productos', $form->body);
    contains('/admin/settings/mcp', $web->get('/admin/settings')->body, 'sale en la pantalla de ajustes');
    check(isset(\Aifec\Admin\Admin::capabilities($app)['mcp.edit']), 'el permiso está declarado');
    same(403, adminClient($app, 'support')->get('/admin/settings/mcp')->status);

    $web->get('/admin/settings/mcp');
    $saved = $web->post('/admin/settings/mcp', ['_token' => aifecToken($web), 'f' => ['mcp.enabled' => '1', 'mcp.tools' => ['search_products', 'order_status']]]);
    same(302, $saved->status, $saved->body);
    same(['search_products', 'order_status'], $app->settings()->arr('mcp.tools'));
    same(['search_products', 'order_status'], array_column(mcpRpc($app, 'tools/list')['result']['tools'], 'name'), 'se ofrece lo marcado');
    same('1.0.0', Server::VERSION);
});
