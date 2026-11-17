<?php
/**
 * AIFEC — ERP: el documento OpenAPI y la página de ayuda.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

use Aifec\Modules\Erp\Routes;

/**
 * Las rutas «método ruta» que de verdad tiene el enrutador bajo /api/v1/erp (sin HEAD), en la forma de OpenAPI.
 *
 * @return string[]
 */
function erpRealRoutes(\Aifec\Kernel\App $app): array
{
    $out = [];
    foreach ($app->router()->all() as $route) {
        if (strpos($route->pattern, Routes::PREFIX) !== 0) {
            continue;
        }
        $path = substr($route->pattern, strlen(Routes::PREFIX));
        $path = $path === '' ? '/' : Routes::openapiPath($path);
        foreach ($route->methods as $method) {
            if ($method !== 'HEAD') {
                $out[] = $method . ' ' . $path;
            }
        }
    }
    sort($out);

    return $out;
}

test('el documento OpenAPI es JSON válido, es público y se ve sin clave', function (): void {
    $app = erpApp();
    $r = erpCall($app, 'GET', '/openapi.json');
    same(200, $r->status);
    $doc = json_decode($r->body, true);
    check(is_array($doc), 'es JSON');
    same('3.1.0', $doc['openapi']);
    check(isset($doc['paths']['/orders/{number}/ack']['post']), 'documenta el ack');
    same('https://tienda.test/api/v1/erp', $doc['servers'][0]['url']);
    check(isset($doc['components']['securitySchemes']['bearerAuth']), 'dice cómo se autentica');
});

test('todas las rutas que existen están en el documento y al revés', function (): void {
    $app = erpApp();
    $doc = erpJson(erpCall($app, 'GET', '/openapi.json'));
    $documented = [];
    foreach ($doc['paths'] as $path => $methods) {
        foreach (array_keys($methods) as $method) {
            $documented[] = strtoupper($method) . ' ' . $path;
        }
    }
    sort($documented);
    $real = erpRealRoutes($app);
    same($real, $documented);
    check(count($real) >= 19, 'hay todas las rutas de la API');
    // Las que el documento nombra también existen con el ámbito que dice, y las públicas no piden clave.
    foreach (Routes::table($app) as $row) {
        check(isset($doc['paths'][Routes::openapiPath((string) $row['path'])][strtolower((string) $row['method'])]), 'documentada: ' . $row['name']);
    }
    same(403, erpCall($app, 'GET', '/orders', null, erpConnection($app, ['erp:products'])['key'])->status);
});

test('el ámbito que exige cada ruta es el que dice el documento', function (): void {
    $app = erpApp();
    $doc = erpJson(erpCall($app, 'GET', '/openapi.json'));
    $c = erpConnection($app, ['erp:orders']);
    foreach ($doc['paths'] as $path => $methods) {
        foreach ($methods as $method => $operation) {
            if (!isset($operation['x-required-scope']) || $method !== 'get' || strpos($path, '{') !== false) {
                continue;
            }
            $r = erpCall($app, 'GET', $path, null, $c['key']);
            if ($operation['x-required-scope'] === 'erp:orders') {
                same(200, $r->status, $path);
            } else {
                same(403, $r->status, $path . ' pide ' . $operation['x-required-scope']);
            }
        }
    }
});

test('con el módulo de facturas apagado, el documento tampoco las trae', function (): void {
    $app = erpApp(['invoices' => false]);
    $doc = erpJson(erpCall($app, 'GET', '/openapi.json'));
    check(!isset($doc['paths']['/invoices']), 'sin facturas');
    check(isset($doc['paths']['/orders']), 'con pedidos');
});

test('la página de ayuda es pública, con ejemplos de curl y la tabla de rutas', function (): void {
    $app = erpApp();
    $r = erpCall($app, 'GET', '');
    same(200, $r->status);
    contains('text/html', $r->headers['Content-Type']);
    contains('curl', $r->body);
    contains('Authorization: Bearer', $r->body);
    contains('/orders/{number}/ack', $r->body);
    contains('openapi.json', $r->body);
});
