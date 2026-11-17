<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\Readers\PrestaShop\Batch;
use Aifec\Modules\Import\Readers\PrestaShop\Env;
use Aifec\Modules\Import\Readers\PrestaShop\Mapper;
use Aifec\Modules\Import\Readers\PrestaShop\OrderMapper;
use Aifec\Modules\Import\Readers\PrestaShop\Routes;
use Aifec\Modules\Import\Readers\PrestaShop\Webservice;
use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

require_once __DIR__ . '/fixtures/PsTestKit.php';

/**
 * Un entorno armado a mano: idiomas es y en, España con tres provincias, impuestos y la configuración justa.
 *
 * @param array<string,string> $cfg
 */
function psEnv(array $cfg = []): Env
{
    $env = new Env();
    $env->cfg = $cfg + ['PS_LANG_DEFAULT' => '1', 'PS_CURRENCY_DEFAULT' => '1', 'PS_HOME_CATEGORY' => '2', 'PS_ROOT_CATEGORY' => '1', 'PS_SHIPPING_METHOD' => '1'];
    $env->langs = [1 => 'es', 2 => 'en'];
    $env->allLangs = [1 => 'es', 2 => 'en', 3 => 'fr'];
    $env->defaultLang = 1;
    $env->currencies = [1 => 'EUR'];
    $env->countries = [
        1 => ['iso' => 'ES', 'zone' => 2, 'states' => true, 'active' => true], 2 => ['iso' => 'PT', 'zone' => 1, 'states' => false, 'active' => true],
        3 => ['iso' => 'FR', 'zone' => 1, 'states' => false, 'active' => false],
    ];
    $env->states = [
        1 => ['iso' => 'M', 'country' => 1, 'zone' => 2, 'active' => true], 2 => ['iso' => 'ES-GC', 'country' => 1, 'zone' => 3, 'active' => true],
        3 => ['iso' => 'TF', 'country' => 1, 'zone' => 3, 'active' => true],
    ];
    $env->taxes = [1 => ['rate' => '21.000', 'name' => [1 => 'IVA 21 %']], 2 => ['rate' => '10.000', 'name' => [1 => 'IVA 10 %']]];
    $env->orderStates = [
        1 => ['name' => [1 => 'Pendiente'], 'paid' => false, 'shipped' => false, 'delivery' => false, 'logable' => false],
        2 => ['name' => [1 => 'Pagado'], 'paid' => true, 'shipped' => false, 'delivery' => false, 'logable' => true],
        3 => ['name' => [1 => 'Enviado'], 'paid' => true, 'shipped' => true, 'delivery' => false, 'logable' => true],
        4 => ['name' => [1 => 'Entregado'], 'paid' => true, 'shipped' => true, 'delivery' => true, 'logable' => true],
        5 => ['name' => [1 => 'En curso'], 'paid' => false, 'shipped' => false, 'delivery' => false, 'logable' => true],
    ];
    $env->groups = [3 => ['display_with_tax' => false, 'name' => [], 'reduction' => '0']];
    $env->timezone = 'Europe/Madrid';
    $env->baseUrl = 'https://tienda.test';
    $env->now = '2026-11-01T00:00:00Z';
    $env->version = '8.2.0';
    $env->loaders = ['attribute_groups' => static function (): array {
        return [1 => 1, 2 => 1, 3 => 2];
    }];

    return $env;
}

test('importes: céntimos sin coma flotante, medio hacia arriba y con coma decimal', function (): void {
    same(8264, Mapper::cents('82.644628'));
    same(1240, Mapper::cents('12.396694'));
    same(1, Mapper::cents('0.005'));
    same(-1, Mapper::cents('-0.005'));
    same(1999, Mapper::cents(19.99));
    same(150, Mapper::cents('1,5'));
    same(0, Mapper::cents(null));
    same(0, Mapper::cents('no es un número'));
    same(-1500000, Mapper::dec6('-1,5'));
    same(82644628, Mapper::dec6('82.644628'));
    same(1000000, Mapper::dec6(1));
    same(1, Mapper::dec6('0.0000019'));
});

test('precio de una combinación: el precio final se redondea una sola vez', function (): void {
    $env = psEnv();
    $base = ['id' => 7, 'name' => [1 => 'Silla'], 'price' => '82.644628', 'active' => 1, 'images' => [], 'stock' => [0 => 0, 11 => 4], 'combinations' => [
        ['id' => 11, 'reference' => 'S-1', 'price' => '12.396694', 'weight' => '0', 'values' => [1, 3], 'images' => []],
    ]];
    $product = Mapper::product($base, $env);
    same(8264, $product['price']);
    same(9504, $product['variants'][0]['price'], '82,644628 + 12,396694 = 95,041322');
    same(4, $product['variants'][0]['stock']);
    $rounding = $base;
    $rounding['price'] = '10.004';
    $rounding['combinations'][0]['price'] = '0.004';
    same(1001, Mapper::product($rounding, $env)['variants'][0]['price'], '10,004 + 0,004 son 10,008 y no 10,00 + 0,00');
    $negative = $base;
    $negative['combinations'][0]['price'] = '-10.000000';
    same(7264, Mapper::product($negative, $env)['variants'][0]['price'], 'un impacto negativo');
});

test('producto: una combinación sin valores de atributo se deja fuera y se apunta', function (): void {
    $env = psEnv();
    $product = Mapper::product(['id' => 7, 'name' => [1 => 'Silla'], 'price' => '10', 'active' => 1, 'combinations' => [
        ['id' => 11, 'price' => '0', 'values' => [], 'images' => []], ['id' => 12, 'price' => '0', 'values' => [1], 'images' => []],
    ]], $env);
    same(['12'], array_column($product['variants'], 'id'));
    same(1, $product['extra']['skipped_combinations']);
    same([], Neutral::check('products', $product));
    throws(function () use ($env): void {
        Mapper::product(['id' => 8, 'name' => [1 => ''], 'price' => '1'], $env);
    }, InvalidArgumentException::class);
});

test('precios específicos: solo la rebaja general que no ha caducado es la oferta', function (): void {
    $env = psEnv();
    $sp = ['id_group' => 0, 'id_country' => 0, 'id_currency' => 0, 'id_customer' => 0, 'id_product_attribute' => 0, 'from_quantity' => 1, 'price' => '-1.000000', 'reduction' => '0', 'reduction_tax' => 0, 'reduction_type' => 'percentage', 'from' => '0000-00-00 00:00:00', 'to' => '0000-00-00 00:00:00'];
    $make = static function (array $specific) use ($env): array {
        return Mapper::product(['id' => 1, 'name' => [1 => 'X'], 'price' => '100.000000', 'active' => 1, 'specific_prices' => $specific], $env);
    };
    same(8000, $make([['id' => 1, 'reduction' => '0.200000'] + $sp])['sale_price']);
    same(9000, $make([['id' => 2, 'reduction' => '10.000000', 'reduction_type' => 'amount'] + $sp])['sale_price']);
    same(7000, $make([['id' => 3, 'price' => '70.000000', 'reduction' => '0'] + $sp])['sale_price'], 'un precio fijo menor');
    $best = $make([['id' => 4, 'reduction' => '0.100000'] + $sp, ['id' => 5, 'reduction' => '0.300000'] + $sp]);
    same(7000, $best['sale_price'], 'la mayor rebaja');
    same(['4'], array_column($best['extra']['specific_prices'], 'id'));
    $expired = $make([['id' => 6, 'reduction' => '0.5', 'to' => '2026-01-01 00:00:00'] + $sp]);
    same([null, 1], [$expired['sale_price'], count($expired['extra']['specific_prices'])]);
    $future = $make([['id' => 7, 'reduction' => '0.5', 'from' => '2026-12-01 00:00:00', 'to' => '2026-12-31 00:00:00'] + $sp]);
    same([5000, '2026-11-30T23:00:00Z', '2026-12-30T23:00:00Z'], [$future['sale_price'], $future['sale_from'], $future['sale_to']]);
    same(null, $make([['id' => 8, 'reduction' => '0.5', 'id_country' => 1] + $sp])['sale_price']);
    same(null, $make([['id' => 9, 'reduction' => '0.5', 'from_quantity' => 3] + $sp])['sale_price']);
    same(null, $make([['id' => 10, 'reduction' => '10', 'reduction_type' => 'amount', 'reduction_tax' => 1] + $sp])['sale_price'], 'un importe con impuestos no se puede pasar a neto sin saber el tipo');
    same(null, $make([['id' => 11, 'reduction' => '0'] + $sp])['sale_price'], 'sin rebaja no hay oferta');
    same(null, $make([['id' => 12, 'price' => '150.000000', 'reduction' => '0'] + $sp])['sale_price'], 'un precio fijo mayor no es una oferta');
});

test('reglas de impuestos: códigos postales, comportamiento y país', function (): void {
    $env = psEnv();
    $rule = ['id' => 1, 'id_tax_rules_group' => 1, 'id_country' => 1, 'id_state' => 0, 'zipcode_from' => '0', 'zipcode_to' => '0', 'behavior' => 0, 'tax_rate' => '7.000', 'tax_name' => [1 => 'IGIC 7 %'], 'tax_active' => 1];
    same('', Mapper::taxRate($rule, $env)['postcodes']);
    same('35000-35999', Mapper::taxRate(['zipcode_from' => '35000', 'zipcode_to' => '35999'] + $rule, $env)['postcodes']);
    same('28001', Mapper::taxRate(['zipcode_from' => '28001', 'zipcode_to' => '28001'] + $rule, $env)['postcodes']);
    same('28001', Mapper::taxRate(['zipcode_from' => '28001', 'zipcode_to' => '0'] + $rule, $env)['postcodes']);
    same('1000-1999', Mapper::taxRate(['zipcode_from' => '1 000', 'zipcode_to' => '1999'] + $rule, $env)['postcodes'], 'sin espacios');
    same([700, false, true], [Mapper::taxRate($rule, $env)['rate'], Mapper::taxRate($rule, $env)['compound'], Mapper::taxRate($rule, $env)['active']]);
    same(true, Mapper::taxRate(['behavior' => 2] + $rule, $env)['compound']);
    same('combine', Mapper::taxRate(['behavior' => 1] + $rule, $env)['extra']['behavior']);
    same('GC', Mapper::taxRate(['id_state' => 2] + $rule, $env)['region'], 'ES-GC sin el prefijo del país');
    same(false, Mapper::taxRate(['tax_active' => 0] + $rule, $env)['active']);
    throws(function () use ($rule, $env): void {
        Mapper::taxRate(['id_country' => 99] + $rule, $env);
    }, InvalidArgumentException::class);
});

test('zonas: país entero, sus provincias o nada', function (): void {
    $env = psEnv();
    same([['country' => 'PT', 'region' => '', 'postcodes' => '']], Mapper::zoneLocations($env, 1), 'Francia está desactivada');
    same([['country' => 'ES', 'region' => 'M', 'postcodes' => '']], Mapper::zoneLocations($env, 2));
    same([['country' => 'ES', 'region' => 'GC', 'postcodes' => ''], ['country' => 'ES', 'region' => 'TF', 'postcodes' => '']], Mapper::zoneLocations($env, 3));
    same([], Mapper::zoneLocations($env, 9));
    $env->states[1]['zone'] = 3;
    same([['country' => 'ES', 'region' => '', 'postcodes' => '']], Mapper::zoneLocations($env, 3), 'todas sus provincias en la zona: el país entero');
    same([], Mapper::zoneLocations($env, 2));
});

test('transportista: método por peso o por importe, el de la tienda si es «por defecto», y los gratuitos sin tarifas', function (): void {
    $carrier = ['id_carrier' => 5, 'id_reference' => 9, 'name' => 'X', 'url' => '', 'is_free' => 0, 'shipping_method' => 0, 'shipping_handling' => 0, 'position' => 0, 'max_weight' => '0', 'emitted_zones' => [1, 2],
        'zones' => [1, 2, 7], 'weight_ranges' => [1 => ['0.000000', '2.500000']], 'price_ranges' => [1 => ['0.000000', '50.000000'], 2 => ['50.000000', '100.000000']],
        'deliveries' => [['id_range_price' => 0, 'id_range_weight' => 1, 'id_zone' => 1, 'price' => '4.950000'], ['id_range_price' => 1, 'id_range_weight' => 0, 'id_zone' => 2, 'price' => '3.000000'],
            ['id_range_price' => 2, 'id_range_weight' => 0, 'id_zone' => 7, 'price' => '1.000000']]];
    $byWeight = Mapper::carrier($carrier, psEnv());
    same('9', $byWeight['id'], 'el identificador de origen es id_reference');
    same([['zone_id' => '1', 'basis' => 'weight', 'min' => 0, 'max' => 2500, 'price' => 495, 'price_micros' => 4950000]], $byWeight['rates']);
    same(['1', '2'], $byWeight['zone_ids'], 'solo las zonas que salen');
    same([null, null], [$byWeight['max_weight'], $byWeight['tax_class_id']]);
    $byPrice = Mapper::carrier($carrier, psEnv(['PS_SHIPPING_METHOD' => '0']));
    same([['zone_id' => '2', 'basis' => 'subtotal', 'min' => 0, 'max' => 5000, 'price' => 300, 'price_micros' => 3000000]], $byPrice['rates']);
    $explicit = Mapper::carrier(['shipping_method' => 2] + $carrier, psEnv());
    same('subtotal', $explicit['rates'][0]['basis']);
    $free = Mapper::carrier(['is_free' => 1] + $carrier, psEnv());
    same(['free', []], [$free['kind'], $free['rates']]);
    $handling = Mapper::carrier(['shipping_handling' => 1, 'max_weight' => '12.5'] + $carrier, psEnv(['PS_SHIPPING_HANDLING' => '3.5', 'PS_SHIPPING_FREE_PRICE' => '49.9']));
    same([350, 4990, 12500], [$handling['handling_fee'], $handling['free_from'], $handling['max_weight']]);
    $envLb = psEnv();
    $envLb->weightUnit = 'lb';
    same(4536, Mapper::carrier(['max_weight' => '10'] + $carrier, $envLb)['max_weight'], '10 libras');
});

test('estado de pedido: lo que dice la configuración de PrestaShop gana a las marcas del estado', function (): void {
    $env = psEnv(['PS_OS_CANCELED' => '9', 'PS_OS_REFUND' => '8', 'PS_OS_ERROR' => '7', 'PS_OS_SHIPPING' => '3', 'PS_OS_PAYMENT' => '2']);
    same('cancelled', OrderMapper::meaning(9, $env));
    same('refunded', OrderMapper::meaning(8, $env));
    same('failed', OrderMapper::meaning(7, $env));
    same('shipped', OrderMapper::meaning(3, $env));
    same('paid', OrderMapper::meaning(2, $env));
    same('delivered', OrderMapper::meaning(4, $env), 'sin configuración: por las marcas');
    same('processing', OrderMapper::meaning(5, $env));
    same('pending', OrderMapper::meaning(1, $env));
    same('pending', OrderMapper::meaning(99, $env), 'un estado que ya no existe');
});

test('cupones: lo que no cabe se apunta en «unsupported»', function (): void {
    $env = psEnv();
    $rule = ['id' => 1, 'code' => 'X1', 'reduction_percent' => '0.00', 'reduction_amount' => '0', 'free_shipping' => 0, 'quantity' => 5, 'uses' => 2, 'quantity_per_user' => 0, 'active' => 1, 'cart_rule_restriction' => 0,
        'product_restriction' => 0, 'date_from' => '0000-00-00 00:00:00', 'date_to' => '0000-00-00 00:00:00', 'minimum_amount' => '0'];
    $amount = OrderMapper::coupon(['reduction_amount' => '5.5', 'reduction_tax' => 1] + $rule, $env);
    same(['amount', 550, true, 7, null, []], [$amount['kind'], $amount['amount'], $amount['amount_includes_tax'], $amount['max_uses'], $amount['max_uses_per_customer'], $amount['unsupported']]);
    $both = OrderMapper::coupon(['reduction_percent' => '15.00', 'free_shipping' => 1] + $rule, $env);
    same(['percent', 1500, ['free_shipping_with_discount']], [$both['kind'], $both['percent'], $both['unsupported']]);
    $product = OrderMapper::coupon(['reduction_percent' => '5', 'reduction_product' => 33] + $rule, $env);
    same(['33'], $product['product_ids']);
    same(['cheapest_product'], OrderMapper::coupon(['reduction_percent' => '5', 'reduction_product' => -1] + $rule, $env)['unsupported']);
    $complex = OrderMapper::coupon(['reduction_percent' => '5', 'product_restriction' => 1, 'rule_groups' => [['rules' => [['type' => 'products', 'items' => [4]], ['type' => 'categories', 'items' => [2]]]]]] + $rule, $env);
    same(['product_restriction'], $complex['unsupported']);
    $simple = OrderMapper::coupon(['reduction_percent' => '5', 'product_restriction' => 1, 'rule_groups' => [['rules' => [['type' => 'products', 'items' => [4, 8]]]]]] + $rule, $env);
    same([['4', '8'], []], [$simple['product_ids'], $simple['unsupported']]);
    $manufacturers = OrderMapper::coupon(['reduction_percent' => '5', 'product_restriction' => 1, 'rule_groups' => [['rules' => [['type' => 'manufacturers', 'items' => [4]]]]]] + $rule, $env);
    same(['product_restriction'], $manufacturers['unsupported']);
    $gift = OrderMapper::coupon(['gift_product' => 4, 'carrier_restriction' => 1, 'country_restriction' => 1] + $rule, $env);
    same(['gift', 'carrier_restriction', 'country_restriction'], $gift['unsupported']);
    $automatic = OrderMapper::coupon(['code' => '', 'reduction_percent' => '5'] + $rule, $env);
    same(['AUTO-1', false, ['automatic']], [$automatic['code'], $automatic['active'], $automatic['unsupported']]);
    same([], Neutral::check('coupons', $automatic));
});

test('pedido: sin impuestos conocidos el tipo sale de las cifras, y si no cuadra se anota la diferencia', function (): void {
    $env = psEnv();
    $order = ['id' => 5, 'reference' => 'ZZZ', 'id_currency' => 1, 'id_customer' => 0, 'current_state' => 2, 'date_add' => '2026-10-01 10:00:00', 'total_paid_tax_excl' => '100.00', 'total_paid_tax_incl' => '121.00',
        'total_products' => '100.00', 'total_shipping_tax_excl' => '0', 'total_shipping_tax_incl' => '0', 'valid' => 1, 'email' => 'x@cliente.test', 'lines' => [
            ['id_order_detail' => 1, 'product_id' => 4, 'product_name' => 'Mesa', 'product_quantity' => 2, 'total_price_tax_excl' => '99.99', 'total_price_tax_incl' => '120.99', 'unit_price_tax_excl' => '49.995', 'tax_ids' => []],
        ]];
    $record = OrderMapper::order($order, $env);
    same([2100, 9999, 2100], [$record['items'][0]['tax_rate'], $record['items'][0]['line_net'], $record['items'][0]['line_tax'] === 2100 ? 2100 : 0]);
    same(1, $record['extra']['lines_net_diff'], 'las líneas suman 99,99 y el pedido dice 100,00');
    same(false, isset($record['carrier_id']));
    same(false, isset($record['customer_id']));
    same([], Neutral::check('orders', $record));
    $noCurrency = $order;
    $noCurrency['id_currency'] = 9;
    throws(function () use ($noCurrency, $env): void {
        OrderMapper::order($noCurrency, $env);
    }, InvalidArgumentException::class);
});

test('direcciones antiguas: reglas de serie, versión 9 y palabras clave', function (): void {
    $env = psEnv();
    $env->loaders['categories'] = static function (): array {
        return [2 => ['parent' => 1, 'root' => true, 'rewrite' => [1 => 'inicio', 2 => 'home']], 3 => ['parent' => 2, 'root' => false, 'rewrite' => [1 => 'muebles', 2 => 'furniture']],
            4 => ['parent' => 3, 'root' => false, 'rewrite' => [1 => 'mesas', 2 => 'tables']]];
    };
    $env->loaders['manufacturers'] = static function (): array {
        return [5 => 'Ñandú Taller'];
    };
    $product = ['id' => 9, 'link_rewrite' => [1 => 'mesa-roja', 2 => 'red-table'], 'ean13' => '', 'id_category_default' => 4, 'reference' => 'MES 01/A', 'id_manufacturer' => 5, 'meta_title' => [1 => 'Mesa Ñoña', 2 => '']];
    same(['/es/mesas/9-mesa-roja.html', '/en/tables/9-red-table.html'], Routes::paths($env, 'product_rule', Routes::productParams($env, $product)));
    $env->cfg['PS_ROUTE_product_rule'] = '{categories}/{manufacturer}/{reference}/{meta_title}/{id}-{rewrite}';
    same('/es/muebles/mesas/nandu-taller/mes-01-a/mesa-nona/9-mesa-roja', Routes::paths($env, 'product_rule', Routes::productParams($env, $product))[0]);
    unset($env->cfg['PS_ROUTE_product_rule']);
    $env->version = '9.1.1';
    same(['/es/9-mesa-roja.html', '/en/9-red-table.html'], Routes::paths($env, 'product_rule', Routes::productParams($env, $product)), 'la regla de serie de 9 no lleva categoría');
    $env->langs = [1 => 'es'];
    $env->baseUrl = 'https://tienda.test/shop';
    same(['/shop/9-mesa-roja.html'], Routes::paths($env, 'product_rule', Routes::productParams($env, $product)), 'la tienda en una subcarpeta y un solo idioma');
    same(['/shop/content/3-aviso'], Routes::paths($env, 'cms_rule', ['id' => '3', 'rewrite' => [1 => 'aviso']]));
    same(['/shop/content/-'], Routes::paths($env, 'cms_rule', []), 'una regla sin datos no rompe');
    same(true, Routes::hasKeyword('{category:/}{id}-{rewrite}', 'category'));
    same(false, Routes::hasKeyword('{id}-{rewrite}', 'category'));
    same('mesa-nona-con-n', Routes::str2url('  Mesa Ñoña con Ñ!  '));
});

test('tandas: trozos de una lista, fases con cursor y un elemento roto que no corta la tanda', function (): void {
    same([[1, 2], '2'], Batch::slice([1, 2, 3, 4, 5], '', 2));
    same([[3, 4], '4'], Batch::slice([1, 2, 3, 4, 5], '2', 2));
    same([[5], null], Batch::slice([1, 2, 3, 4, 5], '4', 2));
    same([[], null], Batch::slice([], '', 2));
    throws(function (): void {
        Batch::slice([1], 'x', 2);
    }, SourceError::class);
    $stages = [
        static function (int $last, int $n): array {
            return array_slice(array_filter([['id' => 1], ['id' => 2], ['id' => 3]], static function (array $r) use ($last): bool {
                return $r['id'] > $last;
            }), 0, $n);
        },
        static function (int $last, int $n): array {
            return array_slice(array_filter([['id' => 1], ['id' => 4]], static function (array $r) use ($last): bool {
                return $r['id'] > $last;
            }), 0, $n);
        },
    ];
    $seen = [];
    $cursor = '';
    $loops = 0;
    do {
        list($rows, $cursor) = Batch::stages($stages, $cursor, 2);
        $seen[] = array_column($rows, 'id');
    } while ($cursor !== null && ++$loops < 20);
    same([[1, 2], [3], [1, 4]], $seen, 'cada fase se agota y pasa a la siguiente');
    list($records, $skipped) = Batch::map([['id' => 1], 'roto', ['id' => 3]], static function (array $item): array {
        if ($item['id'] === 3) {
            throw new RuntimeException('se rompe');
        }

        return $item;
    });
    same([[['id' => 1]], [['id' => '?', 'reason' => 'not an object'], ['id' => '3', 'reason' => 'unexpected RuntimeException: se rompe']]], [$records, $skipped]);
    throws(function (): void {
        Batch::map([['id' => 1]], static function (array $item): array {
            throw new SourceDenied('sin permiso');
        });
    }, SourceDenied::class);
});

test('servicio web: listas vacías, idiomas, asociaciones y envoltorios', function (): void {
    same([], Webservice::unwrap([]));
    same([], Webservice::unwrap(['products' => []]));
    same([['id' => '1']], Webservice::unwrap(['products' => [['id' => '1']]]));
    same([['id' => '1'], ['id' => '2']], Webservice::unwrap([['id' => '1'], ['id' => '2']]));
    same([['id' => '9']], Webservice::unwrap(['product' => ['id' => '9']]));
    same([1 => 'Mesa', 2 => 'Table'], Webservice::lang([['id' => '1', 'value' => 'Mesa'], ['id' => '2', 'value' => 'Table']]));
    same([], Webservice::lang('texto suelto'));
    same([3, 5], Webservice::ids(['associations' => ['categories' => [['id' => '3'], ['id' => '5']]]], 'categories'));
    same([], Webservice::ids(['associations' => ['categories' => []]], 'categories'));
    same([], Webservice::ids([], 'categories'));
});

test('entorno: textos por idioma con el idioma de la tienda primero y la provincia sin el prefijo del país', function (): void {
    $env = psEnv();
    same(['es' => 'Mesa', 'en' => 'Table'], $env->text([2 => 'Table', 1 => 'Mesa']));
    same(['es' => 'Mesa'], $env->text([1 => 'Mesa', 3 => 'Table']), 'un idioma que la tienda no usa no sale');
    same(['fr' => 'Table'], $env->text([3 => 'Table']), 'si solo hay texto de un idioma ya sin uso, se conserva');
    same('Mesa', $env->one([2 => 'Table', 1 => 'Mesa']));
    same('Table', $env->one([1 => '', 2 => 'Table']));
    same('GC', $env->region(2));
    same('', $env->region(99));
    same('B', Env::regionCode('ES-B'));
    same('img/p/1/2/3/123.jpg', Env::productImagePath(123));
    same(['url' => 'https://tienda.test/img/m/4.jpg'], $env->image('img/m/4.jpg'));
    same('2026-10-01T08:30:00Z', $env->utc('2026-10-01 10:30:00'));
    same(null, $env->utc('0000-00-00 00:00:00'));
});

test('cliente: invitado sin contraseña, md5 y bcrypt, y sin correo no hay cliente', function (): void {
    $env = psEnv();
    $row = ['id' => 4, 'email' => 'a@cliente.test', 'firstname' => 'A', 'lastname' => 'B', 'passwd' => str_repeat('ab', 16), 'newsletter' => 0, 'optin' => 0, 'is_guest' => 0, 'active' => 1, 'addresses' => []];
    $md5 = Mapper::customer($row, $env);
    same(['prestashop-md5', true], [$md5['password_scheme'], $md5['extra']['cookie_key_needed']]);
    same('bcrypt', Mapper::customer(['passwd' => '$2y$10$abcdefghijklmnopqrstuuJ9Qe1h0sO9dJ3m1uQq7hYw9vJmVvF0S'] + $row, $env)['password_scheme']);
    same(['unknown', false], [Mapper::customer(['passwd' => 'otra-cosa'] + $row, $env)['password_scheme'], isset(Mapper::customer(['passwd' => 'otra-cosa'] + $row, $env)['extra']['cookie_key_needed'])]);
    same(null, Mapper::customer(['is_guest' => 1] + $row, $env)['password_hash']);
    same(null, Mapper::customer(['passwd' => ''] + $row, $env)['password_hash']);
    throws(function () use ($row, $env): void {
        Mapper::customer(['email' => ''] + $row, $env);
    }, InvalidArgumentException::class);
    same([], Neutral::check('customers', $md5));
});

test('configuración: zona horaria, unidades raras, correo sin servidor y numeración de facturas', function (): void {
    $env = psEnv(['PS_SHOP_NAME' => 'T', 'PS_WEIGHT_UNIT' => 'stone', 'PS_DIMENSION_UNIT' => 'ft', 'PS_MAIL_METHOD' => '3', 'PS_MAIL_SMTP_ENCRYPTION' => 'ssl', 'PS_INVOICE_START_NUMBER' => '40']);
    $settings = Mapper::settings($env, []);
    same(['kg', 'cm'], [$settings['weight_unit'], $settings['dimension_unit']], 'una unidad que el formato neutro no conoce');
    same(['other', 'ssl'], [$settings['mail']['transport'], $settings['mail']['encryption']]);
    same(40, $settings['invoice_number_next']);
    same(false, isset($settings['home_title']));
    $env->nextInvoice = '77';
    same(77, Mapper::settings($env, [])['invoice_number_next'], 'el siguiente número real gana al de arranque');
    same([], Neutral::check('settings', $settings));
});
