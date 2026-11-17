<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Readers\PrestaShop\ApiReader;
use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

require_once __DIR__ . '/fixtures/PsTestKit.php';

/**
 * Los recursos de ejemplo con una clave de configuración cambiada.
 *
 * @return array<string,array<int,array<string,mixed>>>
 */
function psResourcesWith(string $name, string $value): array
{
    $resources = PsTestKit::resources();
    foreach ($resources['configurations'] as $i => $row) {
        if ($row['name'] === $name) {
            $resources['configurations'][$i]['value'] = $value;
        }
    }

    return $resources;
}

test('PrestaShop (servicio web): todo lo que sale cumple el formato neutro y los tipos con permiso no salen vacíos', function (): void {
    $reader = PsTestKit::api();
    same(15, count($reader->types()));
    $result = PsTestKit::checkAll($reader, 3);
    same([], $result['problems']);
    same([], $result['empty']);
    same(6, $result['counts']['products']);
    same(4, $result['counts']['orders']);
    same(3, $result['counts']['customers']);
    same(8, $result['counts']['tax_rates']);
});

test('PrestaShop (servicio web): la ficha pide la dirección y la clave, y el lector se identifica sin la clave', function (): void {
    $card = ApiReader::describe();
    same(['prestashop', 'api'], [$card['source'], $card['mode']]);
    same('secret', $card['fields']['key']['type']);
    same(true, $card['fields']['key']['required']);
    same(true, $card['fields']['url']['required']);
    $reader = PsTestKit::api(['shop' => '2']);
    same(['prestashop', 'api', 'tienda.test#2'], [$reader->source(), $reader->mode(), $reader->key()]);
    notContains('clave-ws-de-mentira', $reader->key());
    throws(function (): void {
        new ApiReader(new stdClass(), []);
    }, InvalidArgumentException::class);
});

test('PrestaShop (servicio web): pide cada recurso en JSON, completo, ordenado y con límite, con la clave como usuario', function (): void {
    $http = PsTestKit::http();
    $reader = new ApiReader($http, ['url' => 'https://tienda.test/', 'key' => 'clave-ws-de-mentira', 'requests_per_second' => 0]);
    $reader->read('products', '', 4);
    $products = null;
    foreach ($http->requests as $request) {
        if (strpos($request->url, '/api/products?') !== false) {
            $products = $request;
            break;
        }
    }
    check($products instanceof HttpRequest, 'no pidió productos');
    same('GET', $products->method);
    contains('https://tienda.test/api/products?', $products->url);
    parse_str((string) parse_url($products->url, PHP_URL_QUERY), $query);
    same(['JSON', 'full', '0,5', '[id_ASC]'], [$query['output_format'], $query['display'], $query['limit'], $query['sort']], 'limit=desde,cuántos pide uno más para saber si queda algo');
    same('Basic ' . base64_encode('clave-ws-de-mentira:'), $products->headers['Authorization']);
    notContains('clave-ws-de-mentira', $products->url);
});

test('PrestaShop (servicio web): el cursor es la posición, termina en null y cuenta lo leído', function (): void {
    $reader = PsTestKit::api();
    $reader->probe();
    $first = $reader->read('products', '', 2);
    same(['1', '2'], array_column($first['records'], 'id'));
    same(['2', 2], [$first['next'], $first['read']]);
    $second = $reader->read('products', '2', 2);
    same(['3', '4'], array_column($second['records'], 'id'));
    same('4', $second['next']);
    $last = $reader->read('products', '4', 2);
    same(['5', '6'], array_column($last['records'], 'id'));
    same([null, 2], [$last['next'], $last['read']]);
    $beyond = $reader->read('products', '6', 2);
    same([[], null, 0], [$beyond['records'], $beyond['next'], $beyond['read']]);
    foreach ([1, 4, 6, 100] as $limit) {
        same(['1', '2', '3', '4', '5', '6'], array_column(PsTestKit::drain($reader, 'products', $limit)['records'], 'id'), 'límite ' . $limit);
    }
    throws(function () use ($reader): void {
        $reader->read('products', 'abc', 2);
    }, SourceError::class);
});

test('PrestaShop (servicio web): un registro roto va a «skipped» y cuenta como leído', function (): void {
    $resources = PsTestKit::resources();
    foreach ($resources['products'] as $i => $product) {
        if ($product['id'] === '2') {
            $resources['products'][$i]['name'] = [['id' => '1', 'value' => ''], ['id' => '2', 'value' => '']];
        }
    }
    $reader = PsTestKit::api([], ['resources' => $resources]);
    $batch = $reader->read('products', '', 3);
    same(['1', '3'], array_column($batch['records'], 'id'));
    same([3, '3'], [$batch['read'], $batch['next']]);
    same('2', $batch['skipped'][0]['id']);
    $all = PsTestKit::drain($reader, 'products', 2);
    same([6, 5], [$all['read'], count($all['records'])]);
});

test('PrestaShop (servicio web): un 401 en el primer recurso es SourceDenied y la clave no sale', function (): void {
    $reader = PsTestKit::api([], ['fail' => static function (string $resource) {
        return $resource === 'configurations' ? new HttpResponse(401, [], 'clave-ws-de-mentira no vale') : null;
    }]);
    try {
        $reader->read('settings', '', 1);
        check(false, 'tenía que lanzar');
    } catch (SourceDenied $e) {
        notContains('clave-ws-de-mentira', $e->getMessage());
        contains('401', $e->getMessage());
    }
    $probe = $reader->probe();
    same(false, $probe['ok']);
    contains('Access was denied', $probe['message']);
    notContains('clave-ws-de-mentira', (string) json_encode($probe));
    $wrong = PsTestKit::api(['key' => 'otra-clave-de-mentira']);
    throws(function () use ($wrong): void {
        $wrong->read('products', '', 1);
    }, SourceDenied::class);
});

test('PrestaShop (servicio web): un 401 solo en «employees» deja ese tipo sin leer y avisa', function (): void {
    $reader = PsTestKit::api([], ['fail' => static function (string $resource) {
        return $resource === 'employees' ? new HttpResponse(401, [], '{"errors":[]}') : null;
    }]);
    same(true, in_array('team', $reader->types(), true));
    $probe = $reader->probe();
    same(true, $probe['ok'], 'un recurso sin permiso no es un SourceDenied general');
    same(['team'], $probe['unavailable']);
    contains('not available', implode(' | ', $probe['warnings']));
    same(false, in_array('team', $reader->types(), true));
    same(14, count($reader->types()));
    throws(function () use ($reader): void {
        $reader->read('team', '', 5);
    }, InvalidArgumentException::class);
    same(6, count(PsTestKit::drain($reader, 'products')['records']), 'lo demás se lee con normalidad');
});

test('PrestaShop (servicio web): un 500, una caída de red y una respuesta que no es JSON son SourceError sin la clave', function (): void {
    $cases = [
        new HttpResponse(500, [], 'clave-ws-de-mentira se ha roto'),
        new HttpException('connection refused for Authorization: Basic ' . base64_encode('clave-ws-de-mentira:') . ' clave-ws-de-mentira'),
        new HttpResponse(200, [], '<html>clave-ws-de-mentira</html>'),
        new HttpResponse(400, [], 'mal'),
    ];
    foreach ($cases as $i => $failure) {
        $reader = PsTestKit::api([], ['fail' => static function (string $resource) use ($failure) {
            return $resource === 'products' ? $failure : null;
        }]);
        try {
            $reader->read('products', '', 2);
            check(false, 'tenía que lanzar en el caso ' . $i);
        } catch (SourceError $e) {
            check(!$e instanceof SourceDenied, 'no es un acceso denegado');
            notContains('clave-ws-de-mentira', $e->getMessage());
            notContains(base64_encode('clave-ws-de-mentira:'), $e->getMessage());
        }
    }
});

test('PrestaShop (servicio web): 429 y 503 con Retry-After piden esperar', function (): void {
    foreach ([429, 503] as $status) {
        $reader = PsTestKit::api([], ['fail' => static function (string $resource) use ($status) {
            return $resource === 'products' ? new HttpResponse($status, ['Retry-After' => '30'], '') : null;
        }]);
        try {
            $reader->read('products', '', 2);
            check(false, 'tenía que lanzar');
        } catch (SourceError $e) {
            contains('retry after 30 seconds', $e->getMessage());
        }
    }
});

test('PrestaShop (servicio web): una lista vacía llega como [] y no rompe nada', function (): void {
    $reader = PsTestKit::api([], ['mutate' => static function (string $resource, array $list): array {
        return in_array($resource, ['products', 'customers', 'orders'], true) ? [] : $list;
    }]);
    foreach (['products', 'customers', 'orders'] as $type) {
        $batch = $reader->read($type, '', 5);
        same([[], null, 0, []], [$batch['records'], $batch['next'], $batch['read'], $batch['skipped']]);
    }
    $probe = $reader->probe();
    same(false, $probe['ok'], 'una lista vacía es un aviso y no un «conecta»');
    contains('no products', $probe['message']);
    same(0, $probe['counts']['products']);
});

test('PrestaShop (servicio web): un recurso que no existe (404) es una lista vacía', function (): void {
    $resources = PsTestKit::resources();
    unset($resources['order_slip'], $resources['order_invoices'], $resources['tags']);
    $reader = PsTestKit::api([], ['resources' => $resources]);
    $order = PsTestKit::record($reader, 'orders', '3');
    same([[], []], [$order['refunds'], $order['invoices']]);
    same([], PsTestKit::record($reader, 'products', '1')['tags']);
});

test('PrestaShop (servicio web): comprobar la conexión hace la primera lectura real', function (): void {
    $probe = PsTestKit::api()->probe();
    same(true, $probe['ok']);
    same('PrestaShop 8.2.0', $probe['version']);
    same(['es', 'en'], $probe['languages']);
    same('EUR', $probe['currency']);
    same([], $probe['unavailable']);
    same([6, 4, 3, 4], [$probe['counts']['products'], $probe['counts']['orders'], $probe['counts']['customers'], $probe['counts']['categories']]);
    $text = implode(' | ', $probe['warnings']);
    contains('cookie key', $text);
    contains('multistore', $text);
    contains('more than one currency', $text);
    same(['1', '2'], array_column($probe['shops'], 'id'));
    notContains('cookie key', implode(' | ', PsTestKit::api(['cookie_key' => 'clave-de-cookies-de-mentira'])->probe()['warnings']));
});

test('PrestaShop (servicio web): una versión anterior a la 1.7 se rechaza', function (): void {
    foreach (['1.6.1.24', ''] as $version) {
        $reader = PsTestKit::api([], ['resources' => psResourcesWith('PS_VERSION_DB', $version)]);
        $probe = $reader->probe();
        same(false, $probe['ok']);
        contains('not supported', $probe['message']);
    }
    $ok = PsTestKit::api([], ['resources' => psResourcesWith('PS_VERSION_DB', '1.7.8')])->probe();
    same(true, $ok['ok']);
    same('PrestaShop 1.7.8', $ok['version']);
});

test('PrestaShop (servicio web): transportistas, zonas e impuestos como en la copia', function (): void {
    $reader = PsTestKit::api();
    $carriers = PsTestKit::drain($reader, 'carriers')['records'];
    same(['1', '2'], array_column($carriers, 'id'));
    same(9, count($carriers[0]['rates']));
    same(990, psRateFor($carriers[0], '1', 4200));
    same(590, psRateFor($carriers[0], '2', 4200));
    same(1250, psRateFor($carriers[0], '3', 4200));
    same(['1', '2'], [PsTestKit::record($reader, 'orders', '1')['carrier_id'], PsTestKit::record($reader, 'orders', '4')['carrier_id']]);
    $zones = PsTestKit::drain($reader, 'shipping_zones')['records'];
    same(['1', '2', '3'], array_column($zones, 'id'));
    same(['GC', 'TF'], array_column($zones[2]['locations'], 'region'));
    $rates = PsTestKit::drain($reader, 'tax_rates')['records'];
    same(['1', '2', '3', '4', '5', '6', '8', '9'], array_column($rates, 'id'));
    same('35000-35999', PsTestKit::byId($rates, '2')['postcodes']);
    same(['1', '2'], array_column(PsTestKit::drain($reader, 'tax_classes')['records'], 'id'));
});

test('PrestaShop (servicio web): atributos y características con cursor de fase y posición', function (): void {
    $reader = PsTestKit::api();
    $seen = [];
    $cursor = '';
    $batches = 0;
    do {
        $batch = $reader->read('attributes', $cursor, 1);
        $seen[] = array_column($batch['records'], 'id');
        $cursor = $batch['next'];
        ++$batches;
    } while ($cursor !== null && $batches < 20);
    same([['a1'], ['a2'], ['f1'], ['f2']], $seen, 'cada fase se agota y pasa a la siguiente');
    $attributes = PsTestKit::drain($reader, 'attributes', 10)['records'];
    same('#C19A6B', PsTestKit::byId($attributes, 'a2')['values'][0]['color']);
    same([], PsTestKit::byId($attributes, 'f2')['values']);
    same(['1', '2'], array_column(PsTestKit::byId($attributes, 'f1')['values'], 'id'));
});

test('PrestaShop (servicio web): productos con combinaciones, oferta y características', function (): void {
    $reader = PsTestKit::api();
    $chair = PsTestKit::record($reader, 'products', '2');
    same([5000, 6240, 6000, 7240], array_column($chair['variants'], 'price'));
    same([10, 5, 7, 3], array_column($chair['variants'], 'stock'));
    same(25, $chair['stock']);
    $armchair = PsTestKit::record($reader, 'products', '4');
    same([9600, '2025-12-31T23:00:00Z', 2], [$armchair['sale_price'], $armchair['sale_from'], count($armchair['extra']['specific_prices'])]);
    $table = PsTestKit::record($reader, 'products', '1');
    same(8264, $table['price']);
    same([['attribute_id' => 'f1', 'value_ids' => ['1']], ['attribute_id' => 'f2', 'text' => ['es' => '48', 'en' => '48']]], $table['attributes']);
    same(['es' => ['roble'], 'en' => ['oak']], $table['tags']);
    same([true, false], array_column($table['images'], 'cover'));
    same('manual-mesa.pdf', $table['files'][0]['name']);
});

test('PrestaShop (servicio web): clientes, cupones, pedidos y páginas', function (): void {
    $reader = PsTestKit::api(['cookie_key' => 'clave-de-cookies-de-mentira']);
    $ana = PsTestKit::record($reader, 'customers', '1');
    same(['bcrypt', 2, '12345678Z'], [$ana['password_scheme'], count($ana['addresses']), $ana['vat_id']]);
    same(false, PsTestKit::record($reader, 'customers', '2')['extra']['cookie_key_needed']);
    foreach (['customers', 'orders', 'products', 'settings', 'coupons'] as $type) {
        foreach (PsTestKit::drain($reader, $type)['records'] as $record) {
            notContains('clave-de-cookies-de-mentira', (string) json_encode($record));
            notContains('clave-ws-de-mentira', (string) json_encode($record));
        }
    }
    $coupons = PsTestKit::drain($reader, 'coupons')['records'];
    // El servicio web no da las reglas de producto de un cupón (contraste con el código de PrestaShop): sale sin su categoría y no soportado.
    same([200, [], ['product_restriction']], [PsTestKit::byId($coupons, '1')['max_uses'], PsTestKit::byId($coupons, '1')['category_ids'], PsTestKit::byId($coupons, '1')['unsupported']]);
    same(['carrier_restriction'], PsTestKit::byId($coupons, '2')['unsupported']);
    same(['automatic', 'gift', 'group_restriction', 'combinations'], PsTestKit::byId($coupons, '3')['unsupported']);
    $order = PsTestKit::record($reader, 'orders', '3');
    same([[1, 1091]], array_map(static function (array $r): array {
        return [count($r['lines']), $r['amount']];
    }, $order['refunds']));
    same(['F2026-000002', 'AB000001'], array_column($order['invoices'], 'number'));
    same('terms', PsTestKit::record($reader, 'pages', '3')['legal']);
    same(['admin', 'manager'], array_column(PsTestKit::drain($reader, 'team')['records'], 'role_meaning'));
    notContains('no-se-lee-nunca', (string) json_encode(PsTestKit::drain($reader, 'team')['records']));
});

test('PrestaShop (servicio web): sumas de control iguales a las de la copia de la base de datos', function (): void {
    $api = PsTestKit::api();
    $api->probe();
    $apiSums = $api->checksums();
    $dbSums = PsTestKit::db()->checksums();
    foreach (['products_count', 'variants_count', 'categories_count', 'customers_count', 'addresses_count', 'orders_count', 'orders_valid_count', 'orders_total', 'order_items_count', 'coupons_count',
        'tax_rates_count', 'stock_total', 'pages_count', 'carriers_count', 'brands_count', 'customer_groups_count'] as $key) {
        same($dbSums[$key], $apiSums[$key], $key);
    }
    same(54152, $apiSums['orders_total']);
    same(1046, $apiSums['stock_total']);
    same(4, $api->count('customer_groups'));
    same(6, $api->count('products'));
    same(3, $api->count('shipping_zones'));
    same(4, $api->count('attributes'));
});

test('PrestaShop (servicio web): lo que no se trae, con lo que el servicio web sí cuenta', function (): void {
    $unmapped = PsTestKit::api()->unmapped();
    $byName = [];
    foreach ($unmapped as $entry) {
        $byName[$entry['name']] = $entry;
        same(['kind', 'name', 'count', 'note'], array_keys($entry));
    }
    same(1, $byName['suppliers']['count']);
    same(1, $byName['physical stores']['count']);
    same(1, $byName['warehouses']['count']);
    same(2, $byName['customer service messages']['count']);
    same(1, $byName['quantity discounts']['count']);
    same(1, $byName['prices by customer group']['count']);
    contains('web service does not list', $byName['installed modules']['note']);
    // Lo que el contraste con el código de PrestaShop demostró que el servicio web no expone: las reglas de producto de un cupón…
    same(1, $byName['coupons with product restrictions']['count']);
    contains('product rules of a coupon', $byName['coupons with product restrictions']['note']);
    // …y las zonas de un transportista (salen de sus tarifas: con tarifas no hay nada que contar, sin ellas sí)
    check(!isset($byName['carriers without zones']), 'con tarifas, todos los transportistas tienen zonas');
    $noPrices = PsTestKit::api([], ['mutate' => static function (string $resource, array $list): array {
        return $resource === 'deliveries' ? [] : $list;
    }]);
    $withoutZones = [];
    foreach ($noPrices->unmapped() as $entry) {
        $withoutZones[$entry['name']] = $entry;
    }
    same(2, $withoutZones['carriers without zones']['count']);
});
