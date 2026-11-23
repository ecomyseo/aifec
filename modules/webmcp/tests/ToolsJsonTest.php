<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * /webmcp/tools.json: la lista de herramientas sale de la tabla compartida (la del asistente, la API y el MCP), con un
 * esquema de entrada válido por herramienta, sin checkout_place y con WebMCP apagado no existe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Assistant\SchemaCheck;

require_once __DIR__ . '/helpers.php';

test('la lista de herramientas son las de la tabla compartida menos checkout_place, y ninguna sobra ni falta', function (): void {
    $app = webmcpApp();
    $web = new TestClient($app);
    $list = webmcpList($web);

    $expected = array_values(array_diff(array_keys(webmcpTools($app)), ['checkout_place']));
    sort($expected);
    $names = array_keys($list);
    sort($names);
    same($expected, $names);
    foreach (['search_products', 'get_product', 'cart_view', 'cart_add', 'cart_update', 'shipping_quote', 'store_info', 'checkout_status', 'checkout_set_customer',
        'checkout_shipping_options', 'checkout_set_shipping', 'checkout_payment_options', 'checkout_set_payment', 'checkout_review'] as $name) {
        check(isset($list[$name]), 'está ' . $name);
    }
    check(!isset($list['checkout_place']), 'el pedido lo confirma la persona en la página: checkout_place no se publica');
});

test('cada herramienta lleva su descripción, su dirección propia y un esquema de entrada válido', function (): void {
    $app = webmcpApp();
    $web = new TestClient($app);
    foreach (webmcpList($web) as $name => $tool) {
        check(strlen((string) $tool['description']) > 20, $name . ' tiene descripción');
        same('/webmcp/call/' . $name, $tool['endpoint'], $name . ': su dirección');
        same('object', $tool['inputSchema']['type'], $name);
        same([], webmcpSchemaProblems($tool['inputSchema'], $name), 'el esquema de ' . $name);
        same(['readOnlyHint'], array_keys($tool['annotations']), $name . ': anotaciones');
    }
});

test('el esquema sale como JSON de verdad: sin propiedades es «{}», no «[]»', function (): void {
    $app = webmcpApp();
    $web = new TestClient($app);
    $body = $web->get('/webmcp/tools.json')->body;
    contains('"name":"cart_view"', $body);
    preg_match('/"name":"cart_view".*?"inputSchema":(\{.*?\}),"annotations"/s', $body, $m);
    same('{"type":"object","properties":{},"additionalProperties":false}', isset($m[1]) ? $m[1] : '', 'cart_view no recibe nada');
    notContains('"properties":[]', $body, 'una lista vacía de propiedades no es un esquema válido');
    $decoded = json_decode($body);
    check(is_object($decoded->tools[0]->inputSchema->properties), 'properties es un objeto');
});

test('el esquema de TODAS las herramientas publicadas es válido: objeto, «required» dentro de «properties» y propiedades como objeto JSON', function (): void {
    // Lighthouse (agentic browsing) lee /webmcp/tools.json: un «required» que nombra una propiedad que no existe, o unas «properties» que
    // salen como lista, estropean el esquema de la herramienta entera (auditoría del 23/11/2026, 19 herramientas válidas).
    $app = webmcpApp();
    $raw = (new TestClient($app))->get('/webmcp/tools.json')->body;
    $tools = json_decode($raw)->tools;
    check(count($tools) >= 15, 'hay herramientas publicadas (' . count($tools) . ')');
    $names = [];
    foreach ($tools as $tool) {
        $name = (string) $tool->name;
        check(!isset($names[$name]), $name . ': el nombre no se repite');
        $names[$name] = true;
        $schema = $tool->inputSchema;
        same('object', $schema->type, $name . ': type object');
        check(isset($schema->properties) && is_object($schema->properties), $name . ': properties es un objeto JSON, no una lista');
        $required = isset($schema->required) ? $schema->required : [];
        check(is_array($required), $name . ': required es una lista');
        foreach ($required as $field) {
            check(is_string($field) && property_exists($schema->properties, $field), $name . ': required pide «' . (is_string($field) ? $field : '?') . '», que no está en properties');
        }
        foreach ($schema->properties as $field => $property) {
            check(isset($property->type) || isset($property->enum), $name . '.' . $field . ': la propiedad dice su tipo');
            check(!isset($property->required) || is_array($property->required), $name . '.' . $field . ': ningún required suelto fuera de su objeto');
        }
        check(isset($tool->annotations->readOnlyHint) && is_bool($tool->annotations->readOnlyHint), $name . ': readOnlyHint es verdadero o falso');
    }
});

test('el esquema publicado es el de la tabla: mismas propiedades, mismas obligatorias, y el validador del módulo acepta lo que dice', function (): void {
    $app = webmcpApp();
    $web = new TestClient($app);
    $tools = webmcpTools($app);
    foreach (webmcpList($web) as $name => $tool) {
        $table = $tools[$name]['schema'];
        $published = $tool['inputSchema'];
        same(array_keys(isset($table['properties']) ? $table['properties'] : []), array_keys(isset($published['properties']) ? $published['properties'] : []), $name . ': propiedades');
        same(isset($table['required']) ? $table['required'] : [], isset($published['required']) ? $published['required'] : [], $name . ': obligatorias');
        same($tools[$name]['description'], $tool['description'], $name . ': descripción');
        // Con el esquema ya descodificado, la entrada vacía vale si no hay obligatorias y no si las hay.
        $empty = SchemaCheck::check($published, []);
        same(empty($published['required']), $empty['ok'], $name . ': entrada vacía');
        // Y una propiedad que no está declarada se rechaza.
        $extra = SchemaCheck::check($published, ['no_existe' => 1]);
        check(!$extra['ok'], $name . ': additionalProperties false rechaza lo que no declara');
    }
});

test('una entrada de ejemplo por herramienta pasa su propio esquema', function (): void {
    $app = webmcpApp();
    $web = new TestClient($app);
    $examples = [
        'search_products' => ['query' => 'silla', 'limit' => 3],
        'get_product' => ['id' => 5],
        'cart_add' => ['product_id' => 5, 'qty' => 2],
        'cart_update' => ['key' => 'p5-v0', 'qty' => 0],
        'shipping_quote' => ['postcode' => '28001', 'country' => 'ES'],
        'checkout_set_shipping' => ['carrier_id' => 1],
        'checkout_set_payment' => ['method' => 'transfer'],
        'checkout_set_customer' => ['text' => 'Ana Ruiz, Calle Mayor 1, 28001 Madrid', 'email' => 'ana@cliente.test'],
    ];
    $list = webmcpList($web);
    foreach ($examples as $name => $input) {
        check(isset($list[$name]), $name . ' está publicada');
        $checked = SchemaCheck::check($list[$name]['inputSchema'], $input);
        same([], $checked['errors'], $name . ': ' . json_encode($input));
    }
});

test('las anotaciones dicen qué herramientas solo leen', function (): void {
    $web = new TestClient(webmcpApp());
    $list = webmcpList($web);
    foreach (['search_products', 'get_product', 'cart_view', 'shipping_quote', 'store_info', 'checkout_status', 'checkout_shipping_options', 'checkout_payment_options'] as $name) {
        same(true, $list[$name]['annotations']['readOnlyHint'], $name . ' solo lee');
    }
    foreach (['cart_add', 'cart_update', 'checkout_set_customer', 'checkout_set_shipping', 'checkout_set_payment', 'checkout_review'] as $name) {
        same(false, $list[$name]['annotations']['readOnlyHint'], $name . ' cambia algo');
    }
});

test('la lista es la misma para todo el mundo y se guarda en la caché de página: ni cookies ni nada del visitante', function (): void {
    $app = webmcpApp();
    $first = (new TestClient($app))->get('/webmcp/tools.json');
    $second = (new TestClient($app))->get('/webmcp/tools.json');
    same(200, $first->status);
    contains('application/json', $first->contentType());
    check($first->cacheTtl > 0, 'cacheable');
    same([], $first->cookies, 'ninguna cookie');
    same($first->body, $second->body, 'la misma para todos');
    contains('public', $first->headers['Cache-Control']);
});

test('con la compra en el chat apagada, las herramientas de la caja salen de la lista porque salen de la tabla', function (): void {
    $app = webmcpApp(['assistant.checkout' => false]);
    $names = array_keys(webmcpList(new TestClient($app)));
    foreach ($names as $name) {
        check(strpos($name, 'checkout_') !== 0 || $name === 'checkout_link', $name . ' no debería estar');
    }
    check(in_array('cart_add', $names, true), 'las demás siguen');
});

test('una herramienta que añade otro módulo con «assistant.tools» sale en la lista sin tocar WebMCP', function (): void {
    $app = webmcpApp();
    $app->hooks()->filter('assistant.tools', static function (array $tools): array {
        $tools['ping_shop'] = [
            'description' => 'Answers pong, only to prove that the list comes from the shared table.',
            'schema' => ['type' => 'object', 'properties' => ['word' => ['type' => 'string', 'maxLength' => 10]], 'additionalProperties' => false],
            'handler' => static function (array $input): array {
                return ['pong' => isset($input['word']) ? $input['word'] : ''];
            },
            'writes' => false,
        ];

        return $tools;
    });
    $web = new TestClient($app);
    $list = webmcpList($web);
    check(isset($list['ping_shop']), 'la herramienta nueva está');
    same(['word'], array_keys($list['ping_shop']['inputSchema']['properties']));
    $r = $web->postJson('/webmcp/call/ping_shop', ['word' => 'hola']);
    same(200, $r->status);
    same(['pong' => 'hola'], $web->json());
});

test('con WebMCP apagado no hay lista, ni llamada, ni guion, ni marcas en los formularios', function (): void {
    $app = webmcpApp(['webmcp.enabled' => false]);
    $ids = assistantShop($app);
    $web = new TestClient($app);

    same(404, $web->get('/webmcp/tools.json')->status, 'la lista');
    same(404, $web->postJson('/webmcp/call/store_info', [])->status, 'la llamada');
    foreach (['/', $app->url()->entity('product', $ids['silla_roja'])] as $path) {
        $body = $web->get($path)->body;
        notContains('webmcp.js', $body, $path . ': el guion');
        notContains('data-webmcp-tools', $body, $path);
        notContains('toolname', $body, $path . ': las marcas');
        notContains('tooldescription', $body, $path);
        notContains('toolparamdescription', $body, $path);
    }
    // Y encendido sí.
    $on = webmcpApp();
    $onIds = assistantShop($on);
    $body = (new TestClient($on))->get($on->url()->entity('product', $onIds['silla_roja']))->body;
    contains('webmcp.js', $body);
    contains('toolname="add_to_cart"', $body);
});
