<?php
/**
 * AIFEC — el lector de WooCommerce por su API REST con clave: todos los tipos, paginación, fallos, secretos y comprobaciones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\HttpException;
use Aifec\Http\HttpResponse;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Readers\WooCommerce\ApiReader;
use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

require_once __DIR__ . '/fixtures/WooTestKit.php';

test('los JSON de ejemplo son JSON válido y traen todas las rutas que usa el lector', function (): void {
    $routes = WooTestKit::routes();
    check(count($routes) > 40, 'hay rutas de ejemplo');
    foreach (['/wp-json/wc/v3/products', '/wp-json/wc/v3/orders', '/wp-json/wc/v3/customers', '/wp-json/wc/store/v1/products'] as $route) {
        check(isset($routes[$route]), $route);
    }
});

test('API: cada registro de cada tipo pasa Neutral::check y hay alguno de cada tipo', function (): void {
    $reader = WooTestKit::api();
    $result = WooTestKit::checkAll($reader);
    same([], $result['problems']);
    same([], $result['empty']);
    same([
        'settings' => 1, 'tax_classes' => 2, 'tax_rates' => 4, 'shipping_zones' => 2, 'carriers' => 4, 'payment_methods' => 2, 'brands' => 1, 'categories' => 4,
        'attributes' => 2, 'products' => 6, 'customers' => 3, 'coupons' => 2, 'orders' => 4, 'reviews' => 2, 'media' => 2, 'pages' => 3, 'post_categories' => 2,
        'posts' => 2, 'menus' => 1, 'team' => 2,
    ], $result['counts']);
    same('woocommerce', $reader->source());
    same('api', $reader->mode());
    same('tienda.test', $reader->key());
    notContains('ck_', $reader->key());
});

test('API: sin la contraseña de aplicación de WordPress no se ofrecen menús ni equipo', function (): void {
    $without = WooTestKit::api(['wp_user' => '', 'wp_password' => '']);
    check(!in_array('menus', $without->types(), true));
    check(!in_array('team', $without->types(), true));
    check(in_array('orders', $without->types(), true));
    check(in_array('menus', WooTestKit::api()->types(), true));
    $http = WooTestKit::http();
    $reader = new ApiReader($http, ['url' => 'https://tienda.test', 'key' => 'ck_clave-de-mentira', 'secret' => 'cs_secreto-de-mentira', 'requests_per_second' => 0]);
    WooTestKit::drain($reader, 'pages');
    foreach ($http->requests as $request) {
        if (strpos($request->url, '/wp/v2/') !== false) {
            check(!isset($request->headers['Authorization']), 'WordPress sin clave: ' . $request->url);
            notContains('context=edit', $request->url);
        }
    }
});

test('API: paginación completa con X-WP-TotalPages, sin solaparse y con next a null al final', function (): void {
    $reader = WooTestKit::api();
    $first = $reader->read('products', '', 4);
    same(4, count($first['records']));
    same('2.4', $first['next']);
    $second = $reader->read('products', (string) $first['next'], 4);
    same(2, count($second['records']));
    same(null, $second['next']);
    same(6, $first['read'] + $second['read']);
    $drained = WooTestKit::drain(WooTestKit::api(), 'products', 2);
    same(3, $drained['batches']);
    same(['101', '102', '103', '104', '105', '106'], array_map(static function (array $p): string {
        return $p['id'];
    }, $drained['records']));
    same(null, WooTestKit::api()->read('products', '', 6)['next']);
    throws(function () use ($reader): void {
        $reader->read('products', 'mala', 4);
    }, SourceError::class);
});

test('API: sin la cabecera X-WP-TotalPages se pide una página más y vacía acaba', function (): void {
    $drained = WooTestKit::drain(WooTestKit::api([], ['no_total_pages' => true]), 'products', 3);
    same(6, count($drained['records']));
    same(3, $drained['batches']);
    $five = WooTestKit::drain(WooTestKit::api([], ['no_total_pages' => true]), 'products', 6);
    same(2, $five['batches']);
    same(6, $five['read']);
});

test('API: un producto roto en medio va a skipped y no corta; read cuenta también los descartados', function (): void {
    $reader = WooTestKit::api([], ['mutate' => static function (string $path, $data) {
        if ($path === '/wp-json/wc/v3/products') {
            $data[2]['name'] = '';
            $data[4] = 'no soy un producto';
        }

        return $data;
    }]);
    $batch = $reader->read('products', '', 6);
    same(4, count($batch['records']));
    same(6, $batch['read']);
    same([['id' => '103', 'reason' => 'product without a name'], ['id' => '?', 'reason' => 'not an object']], $batch['skipped']);
    same(['101', '102', '104', '106'], array_map(static function (array $p): string {
        return $p['id'];
    }, $batch['records']));
    same(null, $batch['next']);
});

test('API: 401 y 403 son SourceDenied y ni la clave ni el secreto salen en el mensaje', function (): void {
    foreach ([401, 403] as $status) {
        $reader = WooTestKit::api([], ['fail' => static function (string $path) use ($status) {
            return new HttpResponse($status, [], '{"code":"woocommerce_rest_cannot_view","message":"ck_clave-de-mentira cs_secreto-de-mentira"}');
        }]);
        try {
            $reader->read('products', '', 10);
            check(false, 'tenía que lanzar');
        } catch (SourceDenied $e) {
            notContains('ck_clave-de-mentira', $e->getMessage());
            notContains('cs_secreto-de-mentira', $e->getMessage());
            notContains('abcd efgh', $e->getMessage());
            contains((string) $status, $e->getMessage());
        }
    }
});

test('API: un 500 y el tiempo agotado son SourceError (no SourceDenied) y sin secretos', function (): void {
    $server = WooTestKit::api([], ['fail' => static function (string $path) {
        return new HttpResponse(500, [], 'Internal error ck_clave-de-mentira');
    }]);
    try {
        $server->read('orders', '', 10);
        check(false, 'tenía que lanzar');
    } catch (SourceError $e) {
        check(!$e instanceof SourceDenied, 'un 500 no es un acceso denegado');
        notContains('ck_clave-de-mentira', $e->getMessage());
        contains('500', $e->getMessage());
    }
    $slow = WooTestKit::api([], ['fail' => static function (string $path) {
        return new HttpException('Operation timed out after 20001 ms con cs_secreto-de-mentira');
    }]);
    try {
        $slow->read('orders', '', 10);
        check(false, 'tenía que lanzar');
    } catch (SourceError $e) {
        check(!$e instanceof SourceDenied);
        notContains('cs_secreto-de-mentira', $e->getMessage());
        contains('timed out', $e->getMessage());
    }
    $rejected = WooTestKit::api([], ['fail' => static function (string $path) {
        return new HttpResponse(400, [], '{}');
    }]);
    throws(function () use ($rejected): void {
        $rejected->read('orders', '', 10);
    }, SourceError::class);
});

test('API: un 429 con Retry-After es un SourceError que lo dice', function (): void {
    $reader = WooTestKit::api([], ['fail' => static function (string $path) {
        return new HttpResponse(429, ['Retry-After' => '30'], '{}');
    }]);
    try {
        $reader->read('customers', '', 10);
        check(false, 'tenía que lanzar');
    } catch (SourceError $e) {
        contains('429', $e->getMessage());
        contains('retry after 30 seconds', $e->getMessage());
        check(!$e instanceof SourceDenied);
    }
});

test('API: la clave va en autenticación básica solo a wc/v3 y la de WordPress solo a wp/v2', function (): void {
    $http = WooTestKit::http();
    $reader = new ApiReader($http, [
        'url' => 'https://tienda.test', 'key' => 'ck_clave-de-mentira', 'secret' => 'cs_secreto-de-mentira', 'wp_user' => 'gestor', 'wp_password' => 'abcd efgh', 'requests_per_second' => 0,
    ]);
    foreach (['customers', 'pages', 'categories', 'team'] as $type) {
        WooTestKit::drain($reader, $type);
    }
    $woo = 0;
    $wp = 0;
    foreach ($http->requests as $request) {
        if (strpos($request->url, '/wc/v3/') !== false) {
            same('Basic ' . base64_encode('ck_clave-de-mentira:cs_secreto-de-mentira'), $request->headers['Authorization'] ?? '', $request->url);
            ++$woo;
        } elseif (strpos($request->url, '/wc/store/v1/') !== false) {
            check(!isset($request->headers['Authorization']), 'la API pública va sin clave');
        } elseif (strpos($request->url, '/wp/v2/') !== false) {
            same('Basic ' . base64_encode('gestor:abcd efgh'), $request->headers['Authorization'] ?? '', $request->url);
            ++$wp;
        }
        notContains('ck_clave', $request->url);
        notContains('cs_secreto', $request->url);
        same(20.0, $request->timeout);
    }
    check($woo > 3 && $wp > 1, 'hubo peticiones de los dos tipos');
});

test('API: los clientes salen sin contraseña (password_hash null, sin esquema)', function (): void {
    $customers = WooTestKit::drain(WooTestKit::api(), 'customers')['records'];
    same(3, count($customers));
    foreach ($customers as $customer) {
        same(null, $customer['password_hash']);
        check(!array_key_exists('password_scheme', $customer));
    }
    same('ana@cliente.test', $customers[0]['email']);
    same(2, count($customers[0]['addresses']));
});

test('API: ajustes con zona horaria, logotipo y siguiente número de pedido; impuestos con la clase estándar', function (): void {
    $reader = WooTestKit::api();
    $settings = $reader->read('settings', '', 10)['records'][0];
    same('Muebles Ñandú & Cía', $settings['shop_name']);
    same('Europe/Madrid', $settings['timezone']);
    same(['url' => 'https://tienda.test/wp-content/uploads/2026/01/logo.png'], $settings['logo']);
    same(9005, $settings['order_number_next']);
    same(['terms' => '8'], $settings['legal_pages']);
    same('es', $settings['default_language']);
    $classes = WooTestKit::drain($reader, 'tax_classes')['records'];
    same(['standard', 'reduced-rate'], array_map(static function (array $c): string {
        return $c['id'];
    }, $classes));
    same(true, $classes[0]['is_default']);
    $rates = WooTestKit::drain($reader, 'tax_rates')['records'];
    same(['standard', 'standard', 'standard', 'reduced-rate'], array_map(static function (array $r): string {
        return $r['class_id'];
    }, $rates));
    same(['35,38', '51'], [$rates[1]['postcodes'], $rates[2]['postcodes']]);
    $pages = WooTestKit::drain($reader, 'pages')['records'];
    same('terms', WooTestKit::byId($pages, '8')['legal']);
    check(!array_key_exists('legal', WooTestKit::byId($pages, '9')));
});

test('API: zonas, transportistas y pasarelas con sus ajustes en secrets', function (): void {
    $reader = WooTestKit::api();
    $zones = WooTestKit::drain($reader, 'shipping_zones')['records'];
    same(['1', '2'], array_map(static function (array $z): string {
        return $z['id'];
    }, $zones));
    $carriers = WooTestKit::drain($reader, 'carriers', 3)['records'];
    same(['11', '12', '13', '21'], array_map(static function (array $c): string {
        return $c['id'];
    }, $carriers));
    same(['rates', 'free', 'pickup', 'rates'], array_map(static function (array $c): string {
        return $c['kind'];
    }, $carriers));
    same([590, 1200], [$carriers[0]['rates'][0]['price'], $carriers[3]['rates'][0]['price']]);
    $gateways = WooTestKit::drain($reader, 'payment_methods')['records'];
    same(['bacs', 'redsys'], array_map(static function (array $g): string {
        return $g['code'];
    }, $gateways));
    same([true, false], [$gateways[0]['enabled'], $gateways[1]['enabled']]);
    same(true, $gateways[1]['sandbox']);
    same('clave-de-mentira', $gateways[1]['settings']['secret_key']);
    same('***', Neutral::redact('payment_methods', $gateways[1])['settings']['secret_key']);
    notContains('clave-de-mentira', json_encode(Neutral::redact('payment_methods', $gateways[1])));
});

test('API: los pedidos traen sus devoluciones y sus notas pedidas aparte', function (): void {
    $http = WooTestKit::http();
    $reader = new ApiReader($http, ['url' => 'https://tienda.test', 'key' => 'ck_x', 'secret' => 'cs_x', 'requests_per_second' => 0]);
    $orders = WooTestKit::drain($reader, 'orders', 2)['records'];
    same(4, count($orders));
    same(9559, WooTestKit::byId($orders, '9002')['refunded_total']);
    same(1, count(WooTestKit::byId($orders, '9002')['refunds']));
    same(2, count(WooTestKit::byId($orders, '9001')['history']));
    same([], WooTestKit::byId($orders, '9004')['history']);
    same('on_hold', WooTestKit::byId($orders, '9004')['state_meaning']);
    same('Tamaño: Mediana', WooTestKit::byId($orders, '9004')['items'][0]['variant_label']);
    check(!array_key_exists('customer_id', WooTestKit::byId($orders, '9004')), 'invitado sin cliente');
    $refundRequests = array_filter($http->requests, static function ($r): bool {
        return strpos($r->url, '/refunds') !== false;
    });
    same(1, count($refundRequests), 'las devoluciones solo se piden a los pedidos que las tienen');
});

test('API: productos con variaciones y términos pedidos una sola vez, categorías con su ruta antigua', function (): void {
    $http = WooTestKit::http();
    $reader = new ApiReader($http, ['url' => 'https://tienda.test', 'key' => 'ck_x', 'secret' => 'cs_x', 'requests_per_second' => 0]);
    $products = WooTestKit::drain($reader, 'products')['records'];
    $silla = WooTestKit::byId($products, '102');
    same(3, count($silla['variants']));
    same('21', $silla['variants'][0]['values'][0]['value_id']);
    $termRequests = array_filter($http->requests, static function ($r): bool {
        return strpos($r->url, '/attributes/1/terms') !== false;
    });
    same(1, count($termRequests));
    $categories = WooTestKit::drain($reader, 'categories')['records'];
    same(['/categoria/muebles/mesas/'], WooTestKit::byId($categories, '12')['old_paths']);
    same('2', WooTestKit::byId($categories, '12')['parent_id']);
    $attributes = WooTestKit::drain($reader, 'attributes')['records'];
    same('tamano', $attributes[0]['code']);
    same(['21', '22', '23'], array_map(static function (array $v): string {
        return $v['id'];
    }, $attributes[0]['values']));
});

test('API: cupones, opiniones, menús y equipo en su forma neutra', function (): void {
    $reader = WooTestKit::api();
    $coupons = WooTestKit::drain($reader, 'coupons')['records'];
    same(['percent', 'free_shipping'], [$coupons[0]['kind'], $coupons[1]['kind']]);
    $reviews = WooTestKit::drain($reader, 'reviews')['records'];
    same(['approved', 'pending'], [$reviews[0]['status'], $reviews[1]['status']]);
    same('Llegó en dos días y sin un golpe.', $reviews[0]['body']);
    $menus = WooTestKit::drain($reader, 'menus')['records'];
    same('primary', $menus[0]['location']);
    same(3, count($menus[0]['items']));
    $team = WooTestKit::drain($reader, 'team')['records'];
    same(['admin', 'manager'], [$team[0]['role_meaning'], $team[1]['role_meaning']]);
    same('es', $team[0]['locale']);
    check(!array_key_exists('locale', $team[1]));
});

test('API: probe con productos va bien; tienda vacía, acceso denegado y WPML se avisan', function (): void {
    $ok = WooTestKit::api()->probe();
    same(true, $ok['ok']);
    same('Connected to the shop', $ok['message']);
    same('WooCommerce 10.8.1, WordPress 7.1.4, HPOS off', $ok['version']);
    same('EUR', $ok['currency']);
    same(['es'], $ok['languages']);
    same(6, $ok['counts']['products']);
    same([], $ok['warnings']);

    $empty = WooTestKit::api([], ['mutate' => static function (string $path, $data) {
        return $path === '/wp-json/wc/v3/products' ? [] : $data;
    }])->probe();
    same(false, $empty['ok']);
    contains('no products', $empty['message']);

    $denied = WooTestKit::api([], ['fail' => static function (string $path) {
        return strpos($path, '/wc/v3/') !== false ? new HttpResponse(401, [], '{}') : null;
    }])->probe();
    same(false, $denied['ok']);
    contains('Access was denied', $denied['message']);

    $unreachable = WooTestKit::api([], ['fail' => static function (string $path) {
        return new HttpException('connection refused');
    }])->probe();
    same(false, $unreachable['ok']);
    contains('could not be reached', $unreachable['message']);

    $multilingual = WooTestKit::api([], ['mutate' => static function (string $path, $data) {
        if ($path === '/wp-json/wc/v3/system_status') {
            $data['active_plugins'][] = ['plugin' => 'sitepress-multilingual-cms/sitepress.php', 'name' => 'WPML'];
        }

        return $data;
    }])->probe();
    same(true, $multilingual['ok']);
    same(['WPML or Polylang found: only the main language is brought and the other languages are not'], $multilingual['warnings']);

    $noLogin = WooTestKit::api(['wp_user' => '', 'wp_password' => ''])->probe();
    same(true, $noLogin['ok']);
    contains('Without a WordPress login', $noLogin['warnings'][0]);
});

test('API: unmapped nombra los plugins, count y checksums dan lo que sabe la API', function (): void {
    $reader = WooTestKit::api();
    $names = array_map(static function (array $u): string {
        return $u['name'];
    }, $reader->unmapped());
    same(['WooCommerce Subscriptions', 'MailPoet', 'Gravity Forms'], $names);
    same(6, $reader->count('products'));
    same(4, $reader->count('orders'));
    same(null, $reader->count('menus'));
    same(['products_count' => 6, 'categories_count' => 4, 'customers_count' => 3, 'orders_count' => 4, 'coupons_count' => 2, 'tax_rates_count' => 4, 'pages_count' => 3, 'posts_count' => 2], $reader->checksums());
});

test('API: describe pide la clave y el secreto (el secreto como tal) y exige un cliente HTTP y una dirección', function (): void {
    $description = ApiReader::describe();
    same('api', $description['mode']);
    same(true, $description['fields']['key']['required']);
    same('secret', $description['fields']['secret']['type']);
    same('secret', $description['fields']['wp_password']['type']);
    throws(function (): void {
        new ApiReader('no soy un cliente', ['url' => 'https://tienda.test']);
    }, InvalidArgumentException::class);
    throws(function (): void {
        new ApiReader(WooTestKit::http(), ['url' => 'tienda sin esquema']);
    }, InvalidArgumentException::class);
    throws(function (): void {
        WooTestKit::api()->read('customer_groups', '', 10);
    }, InvalidArgumentException::class);
});

test('API: no pasa de las peticiones por segundo que se le piden al origen', function (): void {
    $reader = WooTestKit::api(['requests_per_second' => 20]);
    $start = microtime(true);
    for ($i = 0; $i < 4; ++$i) {
        $reader->count('products');
    }
    check(microtime(true) - $start >= 0.14, 'cuatro peticiones a 20 por segundo tardan al menos 150 ms');
    $free = WooTestKit::api(['requests_per_second' => 0]);
    $start = microtime(true);
    for ($i = 0; $i < 4; ++$i) {
        $free->count('products');
    }
    check(microtime(true) - $start < 0.14, 'sin límite no se espera');
});
