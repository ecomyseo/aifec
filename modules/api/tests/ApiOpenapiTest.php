<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Api\Routes;

require_once __DIR__ . '/helpers.php';

/**
 * Las rutas «MÉTODO ruta» que de verdad tiene el enrutador bajo /api/v1 (sin HEAD y sin las del ERP), en la forma de OpenAPI.
 *
 * @return string[]
 */
function apiRealRoutes(App $app): array
{
    $out = [];
    foreach ($app->router()->all() as $route) {
        // /api/v1/openapi.json solo lleva al documento (/api/openapi.json): no es una operación de la API.
        if (strpos($route->pattern, Routes::PREFIX . '/') !== 0 || strpos($route->pattern, Routes::PREFIX . '/erp') === 0 || $route->pattern === Routes::PREFIX . '/openapi.json') {
            continue;
        }
        $path = Routes::openapiPath(substr($route->pattern, strlen(Routes::PREFIX)));
        foreach ($route->methods as $method) {
            if ($method !== 'HEAD') {
                $out[] = $method . ' ' . $path;
            }
        }
    }
    sort($out);

    return $out;
}

test('el documento OpenAPI es JSON válido, es público y se puede leer desde cualquier origen', function (): void {
    $app = apiApp();
    assistantShop($app);

    $r = apiCall($app, 'GET', '/api/openapi.json');
    $doc = json_decode($r->body, true);

    same(200, $r->status);
    contains('application/json', $r->headers['Content-Type']);
    same('*', $r->headers['Access-Control-Allow-Origin']);
    check(is_array($doc), 'es JSON');
    same('3.1.0', $doc['openapi']);
    same('https://tienda.test/api/v1', $doc['servers'][0]['url']);
    same('Muebles Ejemplo API', $doc['info']['title']);
    check(isset($doc['components']['securitySchemes']['bearerAuth'], $doc['components']['securitySchemes']['cartToken']), 'dice cómo se autentica');
    same('X-Cart-Token', $doc['components']['securitySchemes']['cartToken']['name']);

    // El contrato, como lo lee un programa (revisión externa del 17/11/2026): cada operación con su operationId y la forma de su
    // respuesta; los números, enteros; y lo que la descripción dice «(required)», obligatorio de verdad.
    foreach ($doc['paths'] as $path => $item) {
        foreach ($item as $method => $op) {
            $where = strtoupper($method) . ' ' . $path;
            check(isset($op['operationId']) && $op['operationId'] !== '', $where . ' con operationId');
            $ref = isset($op['responses']['200']['content']['application/json']['schema']['$ref']) ? (string) $op['responses']['200']['content']['application/json']['schema']['$ref'] : '';
            check(in_array($ref, ['#/components/schemas/Answer', '#/components/schemas/ListAnswer'], true), $where . ' dice la forma de su respuesta');
            foreach (isset($op['parameters']) ? $op['parameters'] : [] as $param) {
                if (in_array($param['name'], ['page', 'per_page', 'limit', 'qty', 'id', 'product_id', 'variant_id'], true)) {
                    same('integer', $param['schema']['type'], $where . ' ?' . $param['name']);
                }
                if (strpos(isset($param['description']) ? (string) $param['description'] : '', '(required)') !== false) {
                    same(true, $param['required'], $where . ' ?' . $param['name'] . ' es obligatorio');
                }
            }
        }
    }
    same('#/components/schemas/ListAnswer', $doc['paths']['/products']['get']['responses']['200']['content']['application/json']['schema']['$ref']);
    same('#/components/schemas/Answer', $doc['paths']['/products/{id}']['get']['responses']['200']['content']['application/json']['schema']['$ref']);
    check(isset($doc['components']['schemas']['Answer']['properties']['data'], $doc['components']['schemas']['ListAnswer']['properties']['meta']), 'las dos formas, descritas');
});

test('todas las rutas que existen bajo /api/v1 están en el documento y al revés', function (): void {
    $app = apiApp();
    $doc = apiJson(apiCall($app, 'GET', '/api/openapi.json'));

    $documented = [];
    foreach ($doc['paths'] as $path => $methods) {
        foreach (array_keys($methods) as $method) {
            $documented[] = strtoupper($method) . ' ' . $path;
        }
    }
    sort($documented);
    $real = apiRealRoutes($app);

    same($real, $documented);
    check(count($real) >= 21, 'hay las siete de lectura, las seis del carrito y las ocho de la caja');
    foreach (Routes::table($app) as $row) {
        if (!empty($row['root'])) {
            continue; // cuelga de la raíz del sitio, no de /api/v1: no es del documento
        }
        check(isset($doc['paths'][Routes::openapiPath((string) $row['path'])][strtolower((string) $row['method'])]), 'documentada: ' . $row['name']);
    }
    foreach ($doc['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            check(isset($operation['operationId'], $operation['summary'], $operation['responses']['200']), $method . ' ' . $path . ' está completa');
        }
    }
    // Cada ruta con parámetro de ruta lo declara.
    $declared = array_column($doc['paths']['/products/{id}']['get']['parameters'], 'name');
    check(in_array('id', $declared, true));
    check(isset($doc['paths']['/carts/current/items/{key}']['patch'], $doc['paths']['/carts/current/items/{key}']['delete']));
});

test('lo que cada ruta exige es lo que dice el documento: la ficha del carrito en las del carrito y el ámbito en place', function (): void {
    $app = apiApp();
    $doc = apiJson(apiCall($app, 'GET', '/api/openapi.json'));

    check(!isset($doc['paths']['/store']['get']['security']), 'la lectura no pide nada');
    check(!isset($doc['paths']['/carts']['post']['security']), 'crear el carrito tampoco');
    same([['cartToken' => []]], $doc['paths']['/carts/current']['get']['security']);
    same([['cartToken' => []], ['cartToken' => [], 'bearerAuth' => []]], $doc['paths']['/carts/checkout/place']['post']['security'], 'hacer el pedido: sin clave (con el código del comprador) o con ella');
    same('orders:create', $doc['paths']['/carts/checkout/place']['post']['x-optional-scope']);
    check(!isset($doc['paths']['/carts/checkout/place']['post']['x-required-scope']), 'la clave ya no es obligatoria');
    check(isset($doc['paths']['/carts/checkout/place']['post']['requestBody']['content']['application/json']['schema']['properties']['buyer_code']), 'el cuerpo admite buyer_code');
    same('checkout_place', $doc['paths']['/carts/checkout/place']['post']['x-tool']);
    same('cart_add', $doc['paths']['/carts/current/items']['post']['x-tool']);
    $body = $doc['paths']['/carts/checkout/place']['post']['requestBody']['content']['application/json']['schema'];
    same(['confirm_token', 'buyer_confirmed'], $body['required']);
    same('boolean', $body['properties']['buyer_confirmed']['type']);
    same(true, $doc['paths']['/carts/checkout/place']['post']['requestBody']['required']);
    same(['q', 'category', 'page', 'per_page', 'sort', 'in_stock'], array_column(array_filter($doc['paths']['/products']['get']['parameters'], static function (array $p): bool {
        return $p['in'] === 'query';
    }), 'name'));
});

test('el documento explica el recorrido de la compra entera, con la confirmación de la persona y el cobro', function (): void {
    $app = apiApp();
    $description = apiJson(apiCall($app, 'GET', '/api/openapi.json'))['info']['description'];

    foreach (['POST /carts', 'X-Cart-Token', 'confirm_token', 'buyer_confirmed', 'orders:create', 'checkout-link', 'instructions', 'redirect', 'the person'] as $piece) {
        contains($piece, $description);
    }
});

test('la página /api es del tema, dice qué es y enseña las direcciones y el documento', function (): void {
    $app = apiApp();
    assistantShop($app);

    $r = (new TestClient($app))->get('/api');

    same(200, $r->status);
    contains('API para programas y asistentes', $r->body);
    contains('https://tienda.test/api/openapi.json', $r->body);
    contains('/products/{id}', $r->body);
    contains('/carts/checkout/place', $r->body);
    contains('Todo lo que la tienda dice de un producto', $r->body, 'qué hace cada dirección, en el idioma de la página');
    notContains('Everything the shop says about one product', $r->body);
    contains('curl https://tienda.test/api/v1/search', $r->body);
    contains('<title>', $r->body, 'va dentro del marco de la tienda');
    notContains('<script>alert', $r->body);
});

test('la tienda le dice al asistente que comprar aquí no es pagar: sin eso las IA contestan «no puedo comprar por ti»', function (): void {
    // Sondeo del 16/11/2026: Grok leyó /api («una clave hace falta para hacer pedidos») y contestó «no puedo pedirlas ni
    // pagar por ti, no tengo forma de usar tu PayPal»; Gemini, «no puedo procesar pagos». Con el código del comprador, el
    // asistente no paga ni necesita clave: lo paga la persona después, en la página de la tienda.
    $app = apiApp([], ['semantic']);
    assistantShop($app);
    $web = new TestClient($app);

    $page = $web->get('/api')->body;
    contains('data-af-api-no-payment', $page);
    contains('Un asistente que compra aquí nunca paga ni ve datos de pago.', $page);
    contains('hacer un pedido tampoco: la persona lo autoriza con un código', $page);
    notContains('hace falta para hacer pedidos', $page, 'ya no dice que hace falta una clave');
    contains('never pays and never sees payment details', (string) apiJson(apiCall($app, 'GET', '/api/openapi.json'))['info']['description']);
    contains('never just tell them you cannot', (string) apiJson(apiCall($app, 'GET', '/api/openapi.json'))['info']['description']);
    contains('nunca pagas ni ves datos de pago', $web->get('/llms.txt')->body);
    $guide = apiJson(apiCall($app, 'GET', '/api/v1/go', null, ['accept' => 'application/json']));
    contains('You never pay and you never see payment details', (string) $guide['data']['rules'][0], 'y es la primera regla de la compra con enlaces');

    // Con los pedidos solo por clave, la página dice lo de antes: ahí sí hace falta.
    $byKey = apiApp(['api.agent_orders' => 'key']);
    assistantShop($byKey);
    $pageKey = (new TestClient($byKey))->get('/api')->body;
    notContains('data-af-api-no-payment', $pageKey);
    contains('hace falta para hacer pedidos', $pageKey);
});

test('el documento solo lista lo que la tienda puede atender: sin pedidos, no hay carrito ni caja', function (): void {
    $app = testApp(['modules' => ['api', 'search']]);
    $doc = apiJson(apiCall($app, 'GET', '/api/openapi.json'));

    check(isset($doc['paths']['/store']['get'], $doc['paths']['/products']['get']), 'la lectura sí');
    check(!isset($doc['paths']['/carts']) && !isset($doc['paths']['/carts/checkout/place']), 'el carrito y la caja no');
    same(404, apiCall($app, 'POST', '/api/v1/carts')->status, 'y la ruta contesta 404');
    same(404, apiCall($app, 'POST', '/api/v1/carts/checkout/place')->status);
});

test('el llms.txt cuenta cómo se compra aquí cuando el módulo semántico está', function (): void {
    $app = apiApp([], ['semantic']);
    assistantShop($app);

    $r = (new TestClient($app))->get('/llms.txt');

    same(200, $r->status);
    contains('https://tienda.test/api/openapi.json', $r->body);
    contains('https://tienda.test/api', $r->body);
    contains('## Cómo compra aquí un asistente', $r->body);
    contains('La persona siempre confirma el total', $r->body);
    contains('orders:create', $r->body);
});
