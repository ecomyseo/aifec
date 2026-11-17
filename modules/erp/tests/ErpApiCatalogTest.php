<?php
/**
 * AIFEC — ERP: productos, existencias, precios, clientes y facturas por la API.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

test('PUT crea un producto por SKU y otro PUT lo actualiza sin duplicarlo', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $r = erpCall($app, 'PUT', '/products/ERP-001', ['name' => 'Silla de oficina', 'price' => 12100, 'weight' => 8500, 'status' => 'published', 'gtin' => '8412345678905', 'tax_class' => 'standard'], $c['key']);
    same(201, $r->status);
    $data = erpJson($r)['data'];
    same('ERP-001', $data['sku']);
    same('Silla de oficina', $data['name']);
    same(12100, $data['price']);
    same('121.00', $data['price_decimal']);
    same(8500, $data['weight']);
    same('published', $data['status']);
    same('8412345678905', $data['gtin']);
    same('standard', $data['tax_class']);
    $again = erpCall($app, 'PUT', '/products/ERP-001', ['price' => 9900, 'name' => 'Silla ergonómica'], $c['key']);
    same(200, $again->status);
    same(9900, erpJson($again)['data']['price']);
    same('Silla ergonómica', erpJson($again)['data']['name']);
    same('8412345678905', erpJson($again)['data']['gtin']);
    same(1, aifecOrderCount($app, 'products', 'sku = ?', ['ERP-001']));
});

test('PUT no admite lo que no entiende y no crea un producto sin nombre', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $r = erpCall($app, 'PUT', '/products/SIN-NOMBRE', ['price' => 100], $c['key']);
    same(422, $r->status);
    same(0, aifecOrderCount($app, 'products', 'sku = ?', ['SIN-NOMBRE']));
    same('price', erpJson(erpCall($app, 'PUT', '/products/X', ['name' => 'X', 'price' => -5], $c['key']))['error']['field']);
    same('status', erpJson(erpCall($app, 'PUT', '/products/X', ['name' => 'X', 'status' => 'borrado'], $c['key']))['error']['field']);
    same('tax_class', erpJson(erpCall($app, 'PUT', '/products/X', ['name' => 'X', 'tax_class' => 'no-existe'], $c['key']))['error']['field']);
    // Nunca se borra: no hay ruta para ello.
    same(405, erpCall($app, 'DELETE', '/products/ERP-001', null, $c['key'])->status);
});

test('PUT convierte el precio si el ERP lo manda con otro criterio de impuestos que la tienda', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    // La tienda guarda precios CON impuestos (21 %): un precio sin impuestos de 100,00 € se guarda como 121,00 €.
    erpCall($app, 'PUT', '/products/CONV-1', ['name' => 'Convertido', 'price' => 10000, 'prices_include_tax' => false, 'tax_class' => 'standard'], $c['key']);
    same(12100, (int) $app->db()->value('SELECT price FROM {products} WHERE sku = ?', ['CONV-1']));
    // Y con el mismo criterio de la tienda no se toca.
    erpCall($app, 'PUT', '/products/CONV-2', ['name' => 'Igual', 'price_decimal' => '19.99', 'prices_include_tax' => true], $c['key']);
    same(1999, (int) $app->db()->value('SELECT price FROM {products} WHERE sku = ?', ['CONV-2']));
});

test('PUT escribe una variante cuando se le dice el producto', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    erpCall($app, 'PUT', '/products/PADRE', ['name' => 'Camiseta', 'price' => 2000], $c['key']);
    $r = erpCall($app, 'PUT', '/products/PADRE-XL', ['name' => 'XL', 'price' => 2200, 'parent_sku' => 'PADRE'], $c['key']);
    same(201, $r->status);
    $product = erpJson($r)['data'];
    same('PADRE', $product['sku']);
    same('PADRE-XL', $product['variants'][0]['sku']);
    same(2200, $product['variants'][0]['price']);
    // Un SKU de variante sin decir el producto no crea otro producto con el mismo SKU.
    $dup = erpCall($app, 'PUT', '/products/PADRE-XL', ['name' => 'Otro', 'price' => 1], $c['key']);
    same(422, $dup->status);
    same('sku_is_variant', erpJson($dup)['error']['code']);
});

test('GET de productos: lista por cursor y ficha por SKU, también de una variante', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    aifecProduct($app, ['name' => 'Uno', 'sku' => 'P-1', 'price' => 1000, 'stock' => 4, 'sale_price' => 800, 'sale_from' => '2026-11-01 00:00:00', 'sale_to' => '2026-12-01 00:00:00']);
    aifecProduct($app, ['name' => 'Dos', 'sku' => 'P-2', 'price' => 2000, 'stock' => 6, 'variants' => [['sku' => 'P-2-A', 'price' => 2100, 'stock' => 3, 'label' => 'A']]]);
    $list = erpJson(erpCall($app, 'GET', '/products?limit=1', null, $c['key']));
    same(1, count($list['data']));
    same(true, $list['has_more']);
    $next = erpJson(erpCall($app, 'GET', '/products?limit=5&cursor=' . $list['next_cursor'], null, $c['key']));
    same(1, count($next['data']));
    same('P-2', $next['data'][0]['sku']);
    same(false, $next['has_more']);
    $one = erpJson(erpCall($app, 'GET', '/products/P-1', null, $c['key']))['data'];
    same(1000, $one['price']);
    same(800, $one['sale_price']);
    same('2026-11-01T00:00:00Z', $one['sale_from']);
    same(4, $one['stock']);
    $variant = erpJson(erpCall($app, 'GET', '/products/P-2-A', null, $c['key']))['data'];
    same('P-2', $variant['sku']);
    same('P-2-A', $variant['matched_variant_sku']);
    same(2100, $variant['variants'][0]['price']);
    same(404, erpCall($app, 'GET', '/products/NO-HAY', null, $c['key'])->status);
});

test('el stock en lote con tres líneas buenas y dos malas aplica las buenas y dice las malas', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $a = aifecProduct($app, ['name' => 'A', 'sku' => 'S-A', 'stock' => 10]);
    $b = aifecProduct($app, ['name' => 'B', 'sku' => 'S-B', 'stock' => 10]);
    $v = aifecProduct($app, ['name' => 'V', 'sku' => 'S-V', 'stock' => 0, 'variants' => [['sku' => 'S-V1', 'stock' => 5, 'label' => 'Uno']]]);
    $r = erpCall($app, 'POST', '/stock', ['items' => [
        ['sku' => 'S-A', 'stock' => 25],
        ['sku' => 'S-B', 'delta' => -4],
        ['sku' => 'S-V1', 'stock' => 9],
        ['sku' => 'NO-EXISTE', 'stock' => 3],
        ['sku' => 'S-A', 'stock' => -1],
    ]], $c['key']);
    same(200, $r->status);
    $data = erpJson($r)['data'];
    same(['done', 'done', 'done', 'unknown_sku', 'invalid_value'], array_column($data['results'], 'status'));
    same(25, $data['results'][0]['stock']);
    same(3, $data['summary']['done']);
    same(2, $data['summary']['failed']);
    same(25, aifecOrderStock($app, $a));
    same(6, aifecOrderStock($app, $b));
    same(9, (int) $app->db()->value('SELECT stock FROM {variants} WHERE sku = ?', ['S-V1']));
    same(3, aifecOrderCount($app, 'stock_moves', "reason = 'erp'"));
    // Una línea sin stock ni delta, o con los dos, es un valor no válido.
    $bad = erpJson(erpCall($app, 'POST', '/stock', ['items' => [['sku' => 'S-A'], ['sku' => 'S-A', 'stock' => 1, 'delta' => 1], 'texto']], $c['key']))['data'];
    same(['invalid_value', 'invalid_value', 'invalid_value'], array_column($bad['results'], 'status'));
});

test('un delta que dejaría existencias negativas se rechaza si la tienda no admite pedidos sin existencias', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $no = aifecProduct($app, ['name' => 'No', 'sku' => 'N-1', 'stock' => 2, 'backorders' => 'no']);
    $yes = aifecProduct($app, ['name' => 'Si', 'sku' => 'N-2', 'stock' => 2, 'backorders' => 'yes']);
    $data = erpJson(erpCall($app, 'POST', '/stock', ['items' => [['sku' => 'N-1', 'delta' => -5], ['sku' => 'N-2', 'delta' => -5]]], $c['key']))['data'];
    same(['insufficient_stock', 'done'], array_column($data['results'], 'status'));
    same(2, aifecOrderStock($app, $no));
    same(-3, aifecOrderStock($app, $yes));
});

test('un lote de más de 500 líneas o vacío se rechaza entero', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    aifecProduct($app, ['name' => 'A', 'sku' => 'S-A', 'stock' => 10]);
    $many = array_fill(0, 501, ['sku' => 'S-A', 'delta' => 1]);
    $r = erpCall($app, 'POST', '/stock', ['items' => $many], $c['key']);
    same(422, $r->status);
    same('batch_too_large', erpJson($r)['error']['code']);
    same(10, (int) $app->db()->value('SELECT stock FROM {products} WHERE sku = ?', ['S-A']));
    same(422, erpCall($app, 'POST', '/stock', ['items' => []], $c['key'])->status);
    // 500 justos sí valen, y la lista sin «items» también.
    same(200, erpCall($app, 'POST', '/stock', array_fill(0, 500, ['sku' => 'S-A', 'delta' => 1]), $c['key'])->status);
    same(510, (int) $app->db()->value('SELECT stock FROM {products} WHERE sku = ?', ['S-A']));
});

test('GET /stock lista las existencias de cada SKU, las variantes aparte', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    aifecProduct($app, ['name' => 'A', 'sku' => 'S-A', 'stock' => 10]);
    aifecProduct($app, ['name' => 'V', 'sku' => 'S-V', 'variants' => [['sku' => 'S-V1', 'stock' => 5, 'label' => 'Uno'], ['sku' => 'S-V2', 'stock' => 7, 'label' => 'Dos']]]);
    $rows = erpJson(erpCall($app, 'GET', '/stock', null, $c['key']))['data'];
    same(['S-A', 'S-V1', 'S-V2'], array_column($rows, 'sku'));
    same([10, 5, 7], array_column($rows, 'stock'));
});

test('los precios en lote cambian precio, oferta y fechas, y borran la oferta con null', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $id = aifecProduct($app, ['name' => 'A', 'sku' => 'PR-A', 'price' => 1000]);
    $r = erpCall($app, 'POST', '/prices', ['prices_include_tax' => true, 'items' => [
        ['sku' => 'PR-A', 'price' => 1500, 'sale_price' => 1200, 'sale_from' => '2026-11-10T00:00:00Z', 'sale_to' => '2026-11-30T23:59:59Z'],
        ['sku' => 'NO-EXISTE', 'price' => 1],
        ['sku' => 'PR-A', 'price' => 'caro'],
    ]], $c['key']);
    same(200, $r->status);
    same(['done', 'unknown_sku', 'invalid_value'], array_column(erpJson($r)['data']['results'], 'status'));
    $row = $app->db()->row('SELECT price, sale_price, sale_from, sale_to FROM {products} WHERE id = ?', [$id]);
    same(1500, (int) $row['price']);
    same(1200, (int) $row['sale_price']);
    same('2026-11-10 00:00:00', $row['sale_from']);
    same('2026-11-30 23:59:59', $row['sale_to']);
    erpCall($app, 'POST', '/prices', ['items' => [['sku' => 'PR-A', 'sale_price' => null, 'sale_from' => null, 'sale_to' => null]]], $c['key']);
    $row = $app->db()->row('SELECT price, sale_price, sale_from FROM {products} WHERE id = ?', [$id]);
    same(1500, (int) $row['price']);
    same(null, $row['sale_price']);
    same(null, $row['sale_from']);
});

test('los precios sin impuestos se pasan al criterio de la tienda y una variante solo admite precio', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $id = aifecProduct($app, ['name' => 'A', 'sku' => 'PR-B', 'price' => 1000, 'tax_class_id' => 'standard', 'variants' => [['sku' => 'PR-B1', 'price' => 1100, 'stock' => 1, 'label' => 'Uno']]]);
    $data = erpJson(erpCall($app, 'POST', '/prices', ['prices_include_tax' => false, 'items' => [
        ['sku' => 'PR-B', 'price' => 1000],
        ['sku' => 'PR-B1', 'price_decimal' => '20.00'],
        ['sku' => 'PR-B1', 'sale_price' => 100],
        ['sku' => 'PR-B'],
        ['sku' => 'PR-B', 'price' => 100, 'sale_from' => '2026-12-31', 'sale_to' => '2026-01-01'],
    ]], $c['key']))['data'];
    same(['done', 'done', 'invalid_value', 'invalid_value', 'invalid_value'], array_column($data['results'], 'status'));
    same(1210, (int) $app->db()->value('SELECT price FROM {products} WHERE id = ?', [$id]));
    same(2420, (int) $app->db()->value('SELECT price FROM {variants} WHERE sku = ?', ['PR-B1']));
});

test('clientes: PUT crea un cliente sin cuenta por correo, lo actualiza y nunca toca una contraseña', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $r = erpCall($app, 'PUT', '/customers', ['email' => 'Nuevo@Cliente.test', 'first_name' => 'Nora', 'last_name' => 'Pérez', 'company' => 'Pérez & Hijos', 'phone' => '999000001'], $c['key']);
    same(201, $r->status);
    $data = erpJson($r)['data'];
    same('nuevo@cliente.test', $data['email']);
    same('Pérez & Hijos', $data['company']);
    same(true, $data['guest']);
    same(1, aifecOrderCount($app, 'customers', 'email = ?', ['nuevo@cliente.test']));
    same(null, $app->db()->value('SELECT password FROM {customers} WHERE email = ?', ['nuevo@cliente.test']));
    $up = erpCall($app, 'PUT', '/customers', ['email' => 'nuevo@cliente.test', 'first_name' => 'Nora María'], $c['key']);
    same(200, $up->status);
    same('Nora María', erpJson($up)['data']['first_name']);
    same('Pérez & Hijos', erpJson($up)['data']['company']);
    // Una cuenta con contraseña: se actualiza y la contraseña queda como estaba, aunque el ERP intente mandarla.
    $id = aifecCartCustomer($app, 'cuenta@cliente.test');
    $hash = (string) $app->db()->value('SELECT password FROM {customers} WHERE id = ?', [$id]);
    $r2 = erpCall($app, 'PUT', '/customers', ['email' => 'cuenta@cliente.test', 'phone' => '999000002', 'password' => 'otra-clave-1234', 'marketing' => true, 'group_id' => 9], $c['key']);
    same(200, $r2->status);
    same($hash, (string) $app->db()->value('SELECT password FROM {customers} WHERE id = ?', [$id]));
    same(0, (int) $app->db()->value('SELECT marketing FROM {customers} WHERE id = ?', [$id]));
    same('999000002', (string) $app->db()->value('SELECT phone FROM {customers} WHERE id = ?', [$id]));
    same(422, erpCall($app, 'PUT', '/customers', ['email' => 'no-es-un-correo'], $c['key'])->status);
    same(422, erpCall($app, 'PUT', '/customers', ['first_name' => 'Sin correo'], $c['key'])->status);
});

test('clientes: lista por cursor con direcciones y ficha por identificador', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $id = aifecCartCustomer($app, 'ana@cliente.test');
    $app->service('customers')->saveAddress($id, ['first_name' => 'Ana', 'last_name' => 'Ruiz', 'line1' => 'Calle Mayor 1', 'postcode' => '35001', 'city' => 'Las Palmas de Gran Canaria', 'country' => 'ES', 'default_billing' => 1]);
    aifecCartCustomer($app, 'luis@cliente.test');
    $list = erpJson(erpCall($app, 'GET', '/customers?limit=1', null, $c['key']));
    same(1, count($list['data']));
    same(true, $list['has_more']);
    same('Calle Mayor 1', $list['data'][0]['addresses'][0]['line1']);
    $one = erpJson(erpCall($app, 'GET', '/customers/' . $id, null, $c['key']))['data'];
    same('ana@cliente.test', $one['email']);
    notContains('password', json_encode($one));
    same(404, erpCall($app, 'GET', '/customers/99999', null, $c['key'])->status);
    $since = erpJson(erpCall($app, 'GET', '/customers?updated_since=2030-01-01T00:00:00Z', null, $c['key']));
    same([], $since['data']);
});

test('facturas: lista, ficha y PDF', function (): void {
    $app = erpApp();
    $c = erpConnection($app);
    $id = erpOrder($app, 1, 2420);
    $invoice = $app->service('invoices')->forOrder($id)[0];
    $list = erpJson(erpCall($app, 'GET', '/invoices', null, $c['key']));
    same(1, count($list['data']));
    same($invoice['full_number'], $list['data'][0]['number']);
    $one = erpJson(erpCall($app, 'GET', '/invoices/' . rawurlencode((string) $invoice['full_number']), null, $c['key']))['data'];
    same((int) $invoice['total'], $one['total']);
    same((int) $invoice['net'], $one['net']);
    same(adminOrdersNumber($app, $id), $one['order_number']);
    same('invoice', $one['type']);
    $pdf = erpCall($app, 'GET', '/invoices/' . rawurlencode((string) $invoice['full_number']) . '/pdf', null, $c['key']);
    same(200, $pdf->status);
    same('application/pdf', $pdf->headers['Content-Type']);
    check($pdf->file !== null && str_starts_with((string) file_get_contents($pdf->file), '%PDF'), 'es un PDF');
    same(404, erpCall($app, 'GET', '/invoices/F9999-00001', null, $c['key'])->status);
    $noScope = erpConnection($app, ['erp:orders'], 'Sin facturas');
    same(403, erpCall($app, 'GET', '/invoices', null, $noScope['key'])->status);
});
