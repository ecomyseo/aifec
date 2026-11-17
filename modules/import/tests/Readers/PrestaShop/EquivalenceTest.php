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

require_once __DIR__ . '/fixtures/PsTestKit.php';
require_once __DIR__ . '/fixtures/api/WsExport.php';

/**
 * Quita de un registro las claves que el servicio web no puede dar y la copia sí (o al revés).
 *
 * @param array<string,mixed> $record
 * @param string[]            $keys
 *
 * @return array<string,mixed>
 */
function psWithout(array $record, array $keys): array
{
    return array_diff_key($record, array_flip($keys));
}

test('el servicio web y la copia de la base de datos entregan el mismo producto, el mismo cliente y el mismo pedido', function (): void {
    $db = PsTestKit::db();
    $api = PsTestKit::api();
    $api->probe();
    foreach (['1', '2', '4', '5', '6'] as $id) {
        $a = PsTestKit::record($db, 'products', $id);
        $b = PsTestKit::record($api, 'products', $id);
        same(Neutral::hash($a), Neutral::hash($b), 'producto ' . $id);
        same($a, $b, 'producto ' . $id);
    }
    foreach (['1', '2', '3'] as $id) {
        same(Neutral::hash(PsTestKit::record($db, 'customers', $id)), Neutral::hash(PsTestKit::record($api, 'customers', $id)), 'cliente ' . $id);
    }
    foreach (['1', '2', '3', '4'] as $id) {
        $a = PsTestKit::record($db, 'orders', $id);
        $b = PsTestKit::record($api, 'orders', $id);
        same(Neutral::hash($a), Neutral::hash($b), 'pedido ' . $id);
        same($a, $b, 'pedido ' . $id);
    }
});

test('también coinciden la configuración de impuestos, envíos, grupos, marcas, categorías, atributos, cupones y páginas', function (): void {
    $db = PsTestKit::db();
    $api = PsTestKit::api();
    $api->probe();
    foreach (['tax_classes', 'tax_rates', 'shipping_zones', 'carriers', 'customer_groups', 'brands', 'categories', 'attributes', 'pages'] as $type) {
        $a = PsTestKit::drain($db, $type, 2)['records'];
        $b = PsTestKit::drain($api, $type, 3)['records'];
        same(count($a), count($b), $type);
        foreach ($a as $i => $record) {
            same(Neutral::hash($record), Neutral::hash($b[$i]), $type . ' ' . $record['id']);
        }
    }
});

test('las diferencias entre los dos lectores son solo las que el servicio web no expone', function (): void {
    $db = PsTestKit::db();
    $api = PsTestKit::api();
    $api->probe();
    $a = PsTestKit::record($db, 'settings', 'main');
    $b = PsTestKit::record($api, 'settings', 'main');
    same(psWithout($a, ['home_title', 'home_description']), $b, 'el servicio web no da la portada («meta»)');
    foreach (PsTestKit::drain($db, 'team')['records'] as $i => $member) {
        same(psWithout($member, ['role']), psWithout(PsTestKit::drain($api, 'team')['records'][$i], ['role']), 'el servicio web no da los nombres de perfil');
    }
    $virtualDb = PsTestKit::record($db, 'products', '3');
    $virtualApi = PsTestKit::record($api, 'products', '3');
    same(1, count($virtualDb['files']) - count($virtualApi['files']), 'el servicio web no da la descarga de un producto virtual');
    same(psWithout($virtualDb, ['files', 'extra']), psWithout($virtualApi, ['files', 'extra']));
    // El contraste con el código de PrestaShop (utiles/aifec/contraste) demostró que el servicio web no da las reglas de producto
    // de un cupón: un cupón con «product_restriction» llega sin sus productos ni categorías y marcado como no soportado.
    $apiCoupons = PsTestKit::drain($api, 'coupons', 3)['records'];
    $restricted = 0;
    foreach (PsTestKit::drain($db, 'coupons', 2)['records'] as $i => $coupon) {
        if (Neutral::hash($coupon) === Neutral::hash($apiCoupons[$i])) {
            continue;
        }
        $restricted++;
        same(psWithout($coupon, ['product_ids', 'category_ids', 'unsupported']), psWithout($apiCoupons[$i], ['product_ids', 'category_ids', 'unsupported']), 'cupón ' . $coupon['id']);
        same([], $apiCoupons[$i]['category_ids'], 'cupón ' . $coupon['id']);
        check(in_array('product_restriction', $apiCoupons[$i]['unsupported'], true), 'cupón ' . $coupon['id'] . ' sin marcar');
    }
    same(1, $restricted, 'solo el cupón con restricción de productos cambia');
    // Y el servicio web no da las zonas de un transportista (carrier_zone): salen de sus tarifas. Con los datos de ejemplo cada zona
    // del transportista tiene tarifas, así que las dos formas coinciden; sin tarifas el transportista llega sin zonas.
    $dbCarriers = PsTestKit::drain($db, 'carriers', 2)['records'];
    $apiCarriers = PsTestKit::drain($api, 'carriers', 3)['records'];
    foreach ($dbCarriers as $i => $carrier) {
        same($carrier['zone_ids'], $apiCarriers[$i]['zone_ids'], 'zonas del transportista ' . $carrier['id']);
        check($carrier['zone_ids'] !== [], 'el transportista ' . $carrier['id'] . ' tiene zonas');
    }
    $noPrices = PsTestKit::api([], ['mutate' => static function (string $resource, array $list): array {
        return $resource === 'deliveries' ? [] : $list;
    }]);
    $noPrices->probe();
    foreach (PsTestKit::drain($noPrices, 'carriers', 3)['records'] as $carrier) {
        same([], $carrier['zone_ids'], 'sin tarifas, el transportista ' . $carrier['id'] . ' llega sin zonas');
    }
});

test('las respuestas de ejemplo del servicio web siguen siendo lo que sale de los datos inventados', function (): void {
    $pdo = psMemoryDb();
    psSeed($pdo);
    $fresh = WsExport::export($pdo);
    $stored = PsTestKit::resources();
    ksort($stored);
    same(array_keys($fresh), array_keys($stored));
    foreach ($fresh as $resource => $items) {
        same(json_decode((string) json_encode($items), true), $stored[$resource], 'recurso ' . $resource . ': vuelve a ejecutar fixtures/api/build.php');
    }
    // y tienen la forma real: todo cadenas, listas vacías como lista y textos por idioma
    $product = $stored['products'][0];
    same('82.644628', $product['price']);
    same([['id' => '1', 'value' => 'Mesa auxiliar de roble'], ['id' => '2', 'value' => 'Oak side table']], $product['name']);
    same([['id' => '3'], ['id' => '4']], $product['associations']['categories']);
    same([], $stored['products'][2]['associations']['combinations']);
    $detail = $stored['order_details'][0];
    same(false, array_key_exists('tax_rate', $detail), 'los campos ocultos del servicio web no salen');
    same([['id' => '1']], $detail['associations']['taxes']);
});
