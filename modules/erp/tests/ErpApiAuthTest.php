<?php
/**
 * AIFEC — ERP: claves, ámbitos, límite de uso, idempotencia y forma de los errores.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('sin clave la API contesta 401 con el error en JSON', function (): void {
    $app = erpApp();
    $r = erpCall($app, 'GET', '/orders');
    same(401, $r->status);
    $error = erpJson($r)['error'];
    same(401, $error['status']);
    same('missing_key', $error['code']);
    check(array_key_exists('field', $error), 'el error trae el campo «field»');
    check(is_string($error['message']) && $error['message'] !== '', 'el error trae un mensaje');
    contains('Bearer', $r->headers['WWW-Authenticate']);
    contains('no-store', $r->headers['Cache-Control']);
});

test('una clave que no existe da 401', function (): void {
    $app = erpApp();
    erpConnection($app);
    $r = erpCall($app, 'GET', '/orders', null, 'aifec_erp_esta-clave-no-existe');
    same(401, $r->status);
    same('invalid_key', erpJson($r)['error']['code']);
});

test('la clave también vale en la cabecera X-Api-Key', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    same(200, erpCall($app, 'GET', '/orders', null, null, ['x-api-key' => $c['key']])->status);
});

test('sin el ámbito de la ruta, 403', function (): void {
    $app = erpApp();
    $c = erpConnection($app, ['erp:products']);
    $r = erpCall($app, 'GET', '/orders', null, $c['key']);
    same(403, $r->status);
    same('insufficient_scope', erpJson($r)['error']['code']);
    same(200, erpCall($app, 'GET', '/products', null, $c['key'])->status);
});

test('una escritura con un ámbito de solo lectura da 403', function (): void {
    $app = erpApp();
    $c = erpConnection($app, ['erp:orders', 'erp:products', 'erp:stock']);
    same(403, erpCall($app, 'POST', '/orders/NO-EXISTE/ack', ['external_id' => 'X'], $c['key'])->status);
    same(403, erpCall($app, 'POST', '/stock', ['items' => [['sku' => 'A', 'stock' => 1]]], $c['key'])->status);
    same(403, erpCall($app, 'PUT', '/products/A', ['name' => 'A'], $c['key'])->status);
    // El ámbito de escritura no incluye el de lectura.
    $w = erpConnection($app, ['erp:orders:write'], 'Solo escribe');
    same(403, erpCall($app, 'GET', '/orders', null, $w['key'])->status);
});

test('una clave revocada da 401 para siempre', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    same(200, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
    check($app->service('erp')->connections()->revoke($c['id']), 'se revoca');
    same(401, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
    check(!$app->service('erp')->connections()->setActive($c['id'], true), 'una revocada no se vuelve a encender');
    same(401, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
});

test('apagar y encender una conexión corta y devuelve el acceso', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $app->service('erp')->connections()->setActive($c['id'], false);
    same(401, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
    $app->service('erp')->connections()->setActive($c['id'], true);
    same(200, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
});

test('la clave no está en claro en ninguna tabla ni en el registro', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    erpCall($app, 'GET', '/orders', null, $c['key']);
    $db = $app->db();
    foreach (['api_keys', 'erp_connections', 'settings', 'audit', 'erp_idempotency', 'erp_deliveries', 'jobs', 'rate_limits'] as $table) {
        foreach ($db->select('SELECT * FROM {' . $table . '}') as $row) {
            notContains($c['key'], json_encode($row), 'la clave en claro no puede estar en ' . $table);
        }
    }
    $stored = $db->row('SELECT key_hash, scopes FROM {api_keys} WHERE id = (SELECT api_key_id FROM {erp_connections} WHERE id = ?)', [$c['id']]);
    same(hash('sha256', $c['key']), $stored['key_hash']);
    same(64, strlen($stored['key_hash']));
});

test('se apunta cuándo se usó la clave por última vez', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    same(null, $app->service('erp')->connections()->find($c['id'])['last_used_at']);
    erpCall($app, 'GET', '/orders', null, $c['key']);
    same('2026-11-12 12:00:00', $app->service('erp')->connections()->find($c['id'])['last_used_at']);
});

test('el límite de uso por clave da 429 con Retry-After y cabeceras RateLimit', function (): void {
    $app = erpApp(['settings' => ['erp.rate_per_minute' => 10]]);
    $c = erpConnection($app);
    $other = erpConnection($app, null, 'Otro ERP');
    for ($i = 0; $i < 10; ++$i) {
        $ok = erpCall($app, 'GET', '/orders', null, $c['key']);
        same(200, $ok->status);
    }
    same('10', $ok->headers['RateLimit-Limit']);
    same('0', $ok->headers['RateLimit-Remaining']);
    $r = erpCall($app, 'GET', '/orders', null, $c['key']);
    same(429, $r->status);
    same('rate_limited', erpJson($r)['error']['code']);
    check((int) $r->headers['Retry-After'] >= 1 && (int) $r->headers['Retry-After'] <= 60, 'Retry-After en segundos');
    check(isset($r->headers['RateLimit-Reset']), 'RateLimit-Reset');
    same(200, erpCall($app, 'GET', '/orders', null, $other['key'])->status);
    $app->clock()->advance(61);
    same(200, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
});

test('un cuerpo que no es JSON da 400', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $r = erpCall($app, 'POST', '/stock', '{esto no es json', $c['key']);
    same(400, $r->status);
    same('invalid_json', erpJson($r)['error']['code']);
});

test('la misma escritura con la misma Idempotency-Key se hace una vez y devuelve la misma respuesta', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $id = aifecProduct($app, ['name' => 'Taza', 'sku' => 'TAZA-1', 'price' => 500, 'stock' => 10]);
    $call = static function () use ($app, $c): \Aifec\Kernel\Response {
        return erpCall($app, 'POST', '/stock', ['items' => [['sku' => 'TAZA-1', 'delta' => -3]]], $c['key'], ['idempotency-key' => 'lote-0001']);
    };
    $first = $call();
    $second = $call();
    same(200, $first->status);
    same($first->body, $second->body);
    same('true', $second->headers['Idempotency-Replayed']);
    check(!isset($first->headers['Idempotency-Replayed']), 'la primera no es una repetición');
    same(7, aifecOrderStock($app, $id));
    same(1, aifecOrderCount($app, 'stock_moves', 'product_id = ?', [$id]));
    // Sin la clave, la misma petición sí se vuelve a hacer.
    erpCall($app, 'POST', '/stock', ['items' => [['sku' => 'TAZA-1', 'delta' => -3]]], $c['key']);
    same(4, aifecOrderStock($app, $id));
});

test('la misma Idempotency-Key con otra petición es un error, y un error también se repite igual', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    aifecProduct($app, ['name' => 'Taza', 'sku' => 'TAZA-1', 'price' => 500, 'stock' => 10]);
    $a = erpCall($app, 'POST', '/stock', ['items' => [['sku' => 'TAZA-1', 'delta' => -1]]], $c['key'], ['idempotency-key' => 'k1']);
    same(200, $a->status);
    $b = erpCall($app, 'POST', '/stock', ['items' => [['sku' => 'TAZA-1', 'delta' => -2]]], $c['key'], ['idempotency-key' => 'k1']);
    same(422, $b->status);
    same('idempotency_key_reuse', erpJson($b)['error']['code']);
    $bad1 = erpCall($app, 'POST', '/stock', ['items' => []], $c['key'], ['idempotency-key' => 'k2']);
    $bad2 = erpCall($app, 'POST', '/stock', ['items' => []], $c['key'], ['idempotency-key' => 'k2']);
    same(422, $bad1->status);
    same($bad1->body, $bad2->body);
});

test('una Idempotency-Key caduca a las horas del ajuste', function (): void {
    $app = erpApp(['settings' => ['erp.idempotency_hours' => 1]]);
    $c = erpConnection($app);
    $id = aifecProduct($app, ['name' => 'Taza', 'sku' => 'TAZA-1', 'price' => 500, 'stock' => 10]);
    $body = ['items' => [['sku' => 'TAZA-1', 'delta' => -1]]];
    erpCall($app, 'POST', '/stock', $body, $c['key'], ['idempotency-key' => 'k']);
    $app->clock()->advance(3700);
    erpCall($app, 'POST', '/stock', $body, $c['key'], ['idempotency-key' => 'k']);
    same(8, aifecOrderStock($app, $id));
});

test('lo que escribe un ERP queda en el registro de actividad con su conexión', function (): void {
    $app = erpApp();
    $c = erpConnection($app, null, 'Odoo de la oficina');
    aifecProduct($app, ['name' => 'Taza', 'sku' => 'TAZA-1', 'price' => 500, 'stock' => 10]);
    erpCall($app, 'POST', '/stock', ['items' => [['sku' => 'TAZA-1', 'delta' => -1]]], $c['key']);
    $row = $app->db()->row("SELECT * FROM {audit} WHERE action = 'erp.stock'");
    check($row !== null, 'hay una apuntación');
    same('ERP: Odoo de la oficina', $row['user_name']);
    same(null, $row['user_id']);
});

test('una ruta que no existe y un método no permitido contestan en JSON', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $missing = erpCall($app, 'GET', '/nada', null, $c['key']);
    same(404, $missing->status);
    same(405, erpCall($app, 'DELETE', '/products/A', null, $c['key'])->status);
    contains('error', erpCall($app, 'DELETE', '/products/A', null, $c['key'])->body);
});

test('con el módulo de facturas apagado no hay rutas de facturas', function (): void {
    $app = erpApp(['invoices' => false]);
    $c = erpConnection($app);
    same(404, erpCall($app, 'GET', '/invoices', null, $c['key'])->status);
    same(200, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
});

test('quien prueba claves a ciegas se corta a los 30 fallos por minuto', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    for ($i = 0; $i < 30; ++$i) {
        same(401, erpCall($app, 'GET', '/orders', null, 'aifec_erp_mala-' . $i)->status);
    }
    $r = erpCall($app, 'GET', '/orders', null, 'aifec_erp_mala-31');
    same(429, $r->status);
    same('too_many_failures', erpJson($r)['error']['code']);
    // La clave buena sigue entrando: el límite es de los fallos, no de las peticiones buenas.
    same(200, erpCall($app, 'GET', '/orders', null, $c['key'])->status);
});
