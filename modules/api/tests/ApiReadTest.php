<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Api\Present;

require_once __DIR__ . '/helpers.php';

test('la tienda: nombre, moneda, idiomas, contacto y dónde está el resto, con caché corta y CORS abierto', function (): void {
    $app = apiApp();
    assistantShop($app);

    $r = apiCall($app, 'GET', '/api/v1/store');
    $json = apiJson($r);

    same(200, $r->status);
    same('Muebles Ejemplo', $json['data']['name']);
    same('EUR', $json['data']['currency']);
    contains('es', implode(',', $json['data']['languages']));
    same('999000001', $json['data']['contact']['phone']);
    same('https://tienda.test/api/openapi.json', $json['data']['links']['openapi']);
    same('https://tienda.test/api', $json['data']['links']['docs']);
    same('public, max-age=60', $r->headers['Cache-Control']);
    same('*', $r->headers['Access-Control-Allow-Origin']);
});

test('categorías: las de la tienda, con sus datos', function (): void {
    $app = apiApp();
    assistantShop($app);

    $json = apiJson(apiCall($app, 'GET', '/api/v1/categories'));

    $names = array_column($json['data'], 'name');
    check(in_array('Sillas', $names, true) && in_array('Mesas', $names, true), 'salen las categorías: ' . implode(',', $names));
    check($json['data'][0]['id'] > 0 && strpos($json['data'][0]['url'], 'https://tienda.test/') === 0, 'con su dirección completa');
});

test('el listado de productos lleva el precio del catálogo en céntimos y formateado, y los datos de paginación', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);

    $r = apiCall($app, 'GET', '/api/v1/products?per_page=100');
    $json = apiJson($r);

    same(200, $r->status);
    same('public, max-age=60', $r->headers['Cache-Control']);
    $roja = null;
    foreach ($json['data'] as $item) {
        if ($item['id'] === $ids['silla_roja']) {
            $roja = $item;
        }
    }
    check($roja !== null, 'sale la silla roja');
    same(5990, $roja['price'], 'el precio es el del catálogo, en céntimos y entero');
    contains('59,90', $roja['price_formatted']);
    same('EUR', $roja['currency']);
    same('Silla roja', $roja['name']);
    same('https://tienda.test', substr($roja['url'], 0, 19), 'la dirección lleva el dominio');
    check(!array_key_exists('price_cents', $roja), 'no queda el campo de céntimos suelto');
    same(1, $json['meta']['page']);
    same(48, $json['meta']['per_page'], 'el tope por página es 48');
    same(48, $json['meta']['per_page_max'], 'y la respuesta dice cuál es el tope');
    check($json['meta']['total'] >= 9, 'cuenta todos los productos');
});

test('la tienda contesta en un solo idioma y /api/v1 y /api/v1/openapi.json llevan a la documentación', function (): void {
    // Devoluciones no viene encendido de serie: se enciende como lo haría el panel, con «modules.active».
    $active = ['returns', 'orders', 'catalog', 'customers', 'tax', 'shipping', 'coupons', 'mail', 'media', 'jobs', 'api', 'assistant', 'search', 'payments-transfer'];
    $app = apiApp(['modules.active' => $active], ['returns']);
    assistantShop($app);
    $json = apiJson(apiCall($app, 'GET', '/api/v1/store'));
    check(isset($json['data']['returns_payer']), 'con devoluciones, dice quién paga la vuelta');
    check(!array_key_exists('returns_payer_note', $json['data']), 'sin la nota en inglés para el asistente');
    $openapi = apiCall($app, 'GET', '/api/v1/openapi.json');
    same(301, $openapi->status);
    same('/api/openapi.json', $openapi->headers['Location'] ?? '');
    $root = apiCall($app, 'GET', '/api/v1');
    same(301, $root->status);
    same('/api', $root->headers['Location'] ?? '');
    // En /openapi.json y /.well-known/openapi.json, el documento tal cual (17/11/2026: daban 404).
    foreach (['/openapi.json', '/.well-known/openapi.json'] as $path) {
        $doc = apiCall($app, 'GET', $path);
        same(200, $doc->status, $path);
        check(isset(apiJson($doc)['openapi'], apiJson($doc)['paths']), $path . ' es el OpenAPI');
    }
});

test('paginación: la segunda página trae otros productos y el meta lo dice; los parámetros mal puestos dan 400', function (): void {
    $app = apiApp();
    assistantShop($app);

    $one = apiJson(apiCall($app, 'GET', '/api/v1/products?per_page=3&page=1&sort=name'));
    $two = apiJson(apiCall($app, 'GET', '/api/v1/products?per_page=3&page=2&sort=name'));

    same(3, count($one['data']));
    same(3, count($two['data']));
    same(3, $one['meta']['per_page']);
    same(2, $two['meta']['page']);
    check($one['meta']['pages'] >= 3, 'hay varias páginas');
    same($one['meta']['total'], $two['meta']['total']);
    same([], array_intersect(array_column($one['data'], 'id'), array_column($two['data'], 'id')), 'no se repite ninguno');
    same(400, apiCall($app, 'GET', '/api/v1/products?sort=lo-que-sea')->status);
    same(400, apiCall($app, 'GET', '/api/v1/products?page=abc')->status);
});

test('el listado filtra por palabras, por categoría y por existencias', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);

    $silla = apiJson(apiCall($app, 'GET', '/api/v1/products?q=' . rawurlencode('silla roja')));
    $names = array_column($silla['data'], 'name');
    check(in_array('Silla roja', $names, true), 'encuentra la silla roja: ' . implode(',', $names));

    $none = apiJson(apiCall($app, 'GET', '/api/v1/products?q=xyzxyzxyz'));
    same([], $none['data'], 'lo que no existe no devuelve ruido');
    same(0, $none['meta']['total']);

    $sillasId = (int) $app->db()->value("SELECT category_id FROM {product_categories} WHERE product_id = ?", [$ids['silla_roja']]);
    $category = apiJson(apiCall($app, 'GET', '/api/v1/products?per_page=48&category=' . $sillasId));
    $inCategory = array_column($category['data'], 'name');
    check(in_array('Silla roja', $inCategory, true) && !in_array('Mesa de roble', $inCategory, true), 'solo las de esa categoría: ' . implode(',', $inCategory));
    $alias = array_column(apiJson(apiCall($app, 'GET', '/api/v1/products?per_page=48&category_id=' . $sillasId))['data'], 'name');
    same($inCategory, $alias, '«category_id», como en el MCP, filtra igual');

    $stock = apiJson(apiCall($app, 'GET', '/api/v1/products?per_page=48&in_stock=true'));
    check(!in_array('Silla azul', array_column($stock['data'], 'name'), true), 'con existencias no sale la agotada');
});

test('la ficha de un producto: precio, variantes, imágenes y los campos públicos de ProductData', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);

    $r = apiCall($app, 'GET', '/api/v1/products/' . $ids['silla_roja']);
    $d = apiJson($r)['data'];

    same(200, $r->status);
    same($ids['silla_roja'], $d['id']);
    same('Silla roja', $d['name']);
    same(5990, $d['price']);
    contains('59,90', $d['price_formatted']);
    same('SIL-001', $d['sku']);
    same('8412345678905', $d['gtin']);
    same(5990, $d['pricing']['gross']);
    same(2100, $d['pricing']['tax_rate']);
    same(true, $d['pricing']['with_tax']);
    check(is_array($d['images']) && count($d['images']) === 1, 'lleva su imagen');
    same('Nordika', $d['brand']);
    check($d['categories'][0]['name'] === 'Sillas' && $d['categories'][0]['id'] > 0, 'categorías con id');
    check(array_key_exists('description_html', $d) && array_key_exists('seo', $d) && array_key_exists('related', $d) && array_key_exists('dimensions', $d), 'trae los campos de ProductData');
    same('in_stock', $d['availability']['status']);

    $v = apiJson(apiCall($app, 'GET', '/api/v1/products/' . $ids['variantes']))['data'];
    same(2, count($v['variants']));
    same(49900, $v['variants'][0]['price']);
    same(59900, $v['variants'][1]['price']);
    contains('599,00', $v['variants'][1]['price_formatted']);
});

test('un producto que no existe o está en borrador da 404 en JSON, también su disponibilidad', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);
    $draft = aifecProduct($app, ['name' => 'Borrador secreto', 'status' => 'draft']);

    foreach (['/api/v1/products/99999', '/api/v1/products/' . $draft, '/api/v1/products/99999/availability', '/api/v1/products/' . $draft . '/availability'] as $path) {
        $r = apiCall($app, 'GET', $path);
        same(404, $r->status, $path);
        $json = apiJson($r);
        same(404, $json['error']['status']);
        contains('application/json', $r->headers['Content-Type']);
        notContains('Borrador secreto', $r->body);
    }
    notContains('Borrador secreto', apiCall($app, 'GET', '/api/v1/products?per_page=48')->body, 'el borrador no sale en el listado');
    same(404, apiCall($app, 'GET', '/api/v1/no-existe')->status);
    same(405, apiCall($app, 'POST', '/api/v1/store')->status);
    check($ids['silla_roja'] > 0);
});

test('disponibilidad: dice si se puede comprar esa cantidad ahora, y de una variante', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);

    $ok = apiJson(apiCall($app, 'GET', '/api/v1/products/' . $ids['silla_roja'] . '/availability?qty=2'))['data'];
    same(true, $ok['available']);
    same(true, $ok['enough']);
    same(2, $ok['qty']);

    $out = apiJson(apiCall($app, 'GET', '/api/v1/products/' . $ids['silla_azul'] . '/availability'))['data'];
    same(false, $out['enough'], 'la agotada no');

    $many = apiJson(apiCall($app, 'GET', '/api/v1/products/' . $ids['silla_roja'] . '/availability?qty=500'))['data'];
    same(false, $many['enough'], 'más de lo que hay, tampoco');

    $variant = (int) $app->db()->value('SELECT id FROM {variants} WHERE product_id = ? ORDER BY id', [$ids['variantes']]);
    $v = apiJson(apiCall($app, 'GET', '/api/v1/products/' . $ids['variantes'] . '/availability?variant_id=' . $variant))['data'];
    same($variant, $v['variant_id']);
    same(true, $v['enough']);
});

test('búsqueda: hasta ocho productos con los datos que casan; sin palabras, 400', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);

    $r = apiCall($app, 'GET', '/api/v1/search?q=' . rawurlencode('silla roja'));
    $json = apiJson($r);

    same(200, $r->status);
    same('public, max-age=60', $r->headers['Cache-Control']);
    same($ids['silla_roja'], $json['data'][0]['id']);
    same(5990, $json['data'][0]['price']);
    contains('59,90', $json['data'][0]['price_formatted']);
    same('silla roja', $json['meta']['query']);
    check(count($json['data']) <= 8, 'como mucho ocho');

    same(400, apiCall($app, 'GET', '/api/v1/search')->status);
    same(400, apiCall($app, 'GET', '/api/v1/search?q=')->status);
    same([], apiJson(apiCall($app, 'GET', '/api/v1/search?q=xyzxyzxyz'))['data']);
});

test('coste de envío: las opciones con su precio en céntimos; sin producto o sin destino, 400; producto inexistente, 404', function (): void {
    $app = apiApp();
    $ids = assistantShop($app);

    $r = apiCall($app, 'GET', '/api/v1/shipping/quote?postcode=28001&product_id=' . $ids['silla_roja']);
    $d = apiJson($r)['data'];

    same(200, $r->status);
    same(true, $d['needs_shipping']);
    check(count($d['options']) >= 1, 'hay opciones');
    same(495, $d['options'][0]['price'], 'tarifa del transportista para menos de 1 kg');
    contains('4,95', $d['options'][0]['price_formatted']);
    contains('no-store', $r->headers['Cache-Control'], 'el envío no se guarda en caché');
    notContains('public', $r->headers['Cache-Control']);

    same(400, apiCall($app, 'GET', '/api/v1/shipping/quote?postcode=28001')->status);
    same(400, apiCall($app, 'GET', '/api/v1/shipping/quote?product_id=' . $ids['silla_roja'])->status);
    same(404, apiCall($app, 'GET', '/api/v1/shipping/quote?postcode=28001&product_id=99999')->status);
});

test('el límite de uso sin clave: pasado el tope, 429 con Retry-After; otra dirección no lo comparte', function (): void {
    $app = apiApp(['api.rate_per_minute' => 3]);
    assistantShop($app);

    for ($i = 0; $i < 3; ++$i) {
        same(200, apiCall($app, 'GET', '/api/v1/store')->status);
    }
    $r = apiCall($app, 'GET', '/api/v1/store');
    same(429, $r->status);
    check((int) $r->headers['Retry-After'] >= 1 && (int) $r->headers['Retry-After'] <= 60, 'dice cuánto esperar');
    same(429, apiJson($r)['error']['status']);
    same(200, apiCall($app, 'GET', '/api/v1/store', null, [], '203.0.113.9')->status, 'otra dirección tiene su propio contador');

    $app->clock()->advance(61);
    same(200, apiCall($app, 'GET', '/api/v1/store')->status, 'pasado el minuto vuelve a funcionar');
});

test('con una clave buena el límite sube y se apunta el uso; con una mala, 401', function (): void {
    $app = apiApp(['api.rate_per_minute' => 2, 'api.rate_per_minute_key' => 50]);
    assistantShop($app);
    $key = apiKey($app);
    same(null, $app->db()->value('SELECT last_used_at FROM {api_keys} WHERE key_hash = ?', [hash('sha256', $key)]));

    for ($i = 0; $i < 10; ++$i) {
        same(200, apiCall($app, 'GET', '/api/v1/store', null, ['authorization' => 'Bearer ' . $key])->status, 'la vuelta ' . $i);
    }
    check($app->db()->value('SELECT last_used_at FROM {api_keys} WHERE key_hash = ?', [hash('sha256', $key)]) !== null, 'queda apuntado el último uso');

    $bad = apiCall($app, 'GET', '/api/v1/store', null, ['authorization' => 'Bearer aifec_api_mentira']);
    same(401, $bad->status);
    contains('Bearer', $bad->headers['WWW-Authenticate']);
    same(401, apiCall($app, 'GET', '/api/v1/store', null, ['authorization' => 'Basic abc'])->status);

    $app->db()->update('api_keys', ['active' => 0], ['key_hash' => hash('sha256', $key)]);
    same(401, apiCall($app, 'GET', '/api/v1/store', null, ['authorization' => 'Bearer ' . $key])->status, 'una clave apagada no vale');

    $erp = 'aifec_erp_' . str_repeat('a', 48);
    $app->db()->insert('api_keys', ['name' => 'ERP: x', 'key_hash' => hash('sha256', $erp), 'scopes' => '["erp:orders"]', 'active' => 1, 'created_at' => $app->clock()->sql()]);
    same(401, apiCall($app, 'GET', '/api/v1/store', null, ['authorization' => 'Bearer ' . $erp])->status, 'una clave del ERP no entra por esta puerta');
});

test('con el módulo apagado en los ajustes, todas sus direcciones dan 404', function (): void {
    $app = apiApp(['api.enabled' => false]);
    assistantShop($app);

    foreach (['/api/v1/store', '/api/v1/products', '/api/v1/categories', '/api/openapi.json', '/api'] as $path) {
        same(404, apiCall($app, 'GET', $path)->status, $path);
    }
    same(404, apiCall($app, 'POST', '/api/v1/carts')->status);
    same(404, apiCall($app, 'GET', '/carrito/retomar/' . str_repeat('a', 48))->status);
    same(404, apiCall($app, 'OPTIONS', '/api/v1/store')->status, 'ni responde a OPTIONS');
});

test('CORS: la lectura admite cualquier origen y OPTIONS responde; el carrito solo los orígenes de los ajustes o la propia tienda', function (): void {
    $app = apiApp(['api.cors_origins' => "https://app.ejemplo.test\nhttps://otra.ejemplo.test/"]);
    assistantShop($app);

    $pre = apiCall($app, 'OPTIONS', '/api/v1/products/5', null, ['origin' => 'https://cualquiera.test', 'access-control-request-method' => 'GET']);
    same(204, $pre->status);
    same('*', $pre->headers['Access-Control-Allow-Origin']);
    contains('GET', $pre->headers['Access-Control-Allow-Methods']);

    $read = apiCall($app, 'GET', '/api/v1/store', null, ['origin' => 'https://cualquiera.test']);
    same('*', $read->headers['Access-Control-Allow-Origin']);

    $ok = apiCall($app, 'OPTIONS', '/api/v1/carts/current/items', null, ['origin' => 'https://app.ejemplo.test']);
    same(204, $ok->status);
    same('https://app.ejemplo.test', $ok->headers['Access-Control-Allow-Origin']);
    contains('X-Cart-Token', $ok->headers['Access-Control-Allow-Headers']);
    contains('POST', $ok->headers['Access-Control-Allow-Methods']);
    same('Origin', $ok->headers['Vary']);
    same(204, apiCall($app, 'OPTIONS', '/api/v1/carts/current/items', null, ['origin' => 'https://otra.ejemplo.test'])->status, 'la barra final de un origen no importa');

    $bad = apiCall($app, 'OPTIONS', '/api/v1/carts/current/items', null, ['origin' => 'https://malo.test']);
    same(403, $bad->status);
    check(!isset($bad->headers['Access-Control-Allow-Origin']), 'no se le da permiso');

    $create = apiCall($app, 'POST', '/api/v1/carts', null, ['origin' => 'https://malo.test']);
    same(403, $create->status, 'un origen no permitido no puede ni crear el carrito');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {carts}'), 'y no se crea nada');
    $mine = apiCall($app, 'POST', '/api/v1/carts', null, ['origin' => 'https://tienda.test']);
    same(201, $mine->status, 'la propia tienda sí');
    same('https://tienda.test', $mine->headers['Access-Control-Allow-Origin']);
    $allowed = apiCall($app, 'POST', '/api/v1/carts', null, ['origin' => 'https://app.ejemplo.test']);
    same(201, $allowed->status);
    same('https://app.ejemplo.test', $allowed->headers['Access-Control-Allow-Origin']);
    same(404, apiCall($app, 'OPTIONS', '/api/v1/no-existe')->status);
});

test('los importes de las herramientas salen en céntimos y formateados, y un campo de céntimos sin texto al lado se llama igual que los demás', function (): void {
    $out = Present::amounts([
        'price' => '59,90 €', 'price_cents' => 5990, 'compare_price' => null, 'compare_price_cents' => null,
        'items_total_cents' => 100, 'lines' => [['total' => '1,00 €', 'total_cents' => 100, 'name' => 'a']], 'name' => 'x', 'qty' => 2,
    ]);

    same(5990, $out['price']);
    same('59,90 €', $out['price_formatted']);
    same(null, $out['compare_price']);
    same(null, $out['compare_price_formatted']);
    same(100, $out['items_total']);
    check(!array_key_exists('items_total_cents', $out), 'un importe sin texto al lado se llama como los demás');
    same(100, $out['lines'][0]['total']);
    same('1,00 €', $out['lines'][0]['total_formatted']);
    check(!array_key_exists('price_cents', $out) && !array_key_exists('total_cents', $out['lines'][0]), 'no quedan duplicados');
    same('x', $out['name']);
});
