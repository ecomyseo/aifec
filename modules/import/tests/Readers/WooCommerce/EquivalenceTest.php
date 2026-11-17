<?php
/**
 * AIFEC — la API y la copia de la base de datos entregan el MISMO registro neutro para el mismo producto, el mismo cliente (salvo la
 * contraseña) y el mismo pedido: se comparan con Neutral::hash.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Import\Neutral;

require_once __DIR__ . '/fixtures/WooTestKit.php';

/**
 * Compara dos registros y, si difieren, dice qué campos.
 *
 * @param array<string,mixed> $a
 * @param array<string,mixed> $b
 */
function wooSameRecord(array $a, array $b, string $what): void
{
    if (Neutral::hash($a) === Neutral::hash($b)) {
        check(true);

        return;
    }
    $different = [];
    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $field) {
        if (json_encode($a[$field] ?? '<falta>') !== json_encode($b[$field] ?? '<falta>')) {
            $different[] = $field . ': API ' . json_encode($a[$field] ?? '<falta>', JSON_UNESCAPED_UNICODE) . ' / copia ' . json_encode($b[$field] ?? '<falta>', JSON_UNESCAPED_UNICODE);
        }
    }
    check(false, $what . ' difiere en ' . implode(' | ', $different));
}

test('API y copia: el mismo producto simple sale idéntico (oferta, peso, medidas, imágenes, etiquetas, marca, ruta antigua)', function (): void {
    $api = WooTestKit::byId(WooTestKit::drain(WooTestKit::api(), 'products')['records'], '101');
    $db = WooTestKit::byId(WooTestKit::drain(WooTestKit::db(), 'products')['records'], '101');
    same([], Neutral::check('products', $api));
    same([], Neutral::check('products', $db));
    wooSameRecord($api, $db, 'producto 101');
    same(10661, $db['price']);
    same(8900, $db['sale_price']);
    same(6500, $db['weight']);
});

test('API y copia: el mismo producto variable sale idéntico (variantes, atributos, imágenes de variación)', function (): void {
    $api = WooTestKit::byId(WooTestKit::drain(WooTestKit::api(), 'products')['records'], '102');
    $db = WooTestKit::byId(WooTestKit::drain(WooTestKit::db(), 'products')['records'], '102');
    wooSameRecord($api, $db, 'producto 102');
    same(3, count($db['variants']));
    same(1, $db['variants'][0]['image_index']);
});

test('API y copia: el mismo cliente sale idéntico salvo la contraseña, que solo da la copia', function (): void {
    $api = WooTestKit::byId(WooTestKit::drain(WooTestKit::api(), 'customers')['records'], '77');
    $db = WooTestKit::byId(WooTestKit::drain(WooTestKit::db(), 'customers')['records'], '77');
    same(null, $api['password_hash']);
    same('wordpress', $db['password_scheme']);
    foreach (['password_hash', 'password_scheme'] as $field) {
        unset($api[$field], $db[$field]);
    }
    wooSameRecord($api, $db, 'cliente 77');
    same(2, count($db['addresses']));
});

test('API y copia: los mismos pedidos salen idénticos, con devolución y notas, con las tablas de entradas y con las HPOS', function (): void {
    $api = WooTestKit::drain(WooTestKit::api(), 'orders')['records'];
    foreach ([false, true] as $hpos) {
        $db = WooTestKit::drain(WooTestKit::db($hpos), 'orders')['records'];
        foreach (['9001', '9002', '9003', '9004'] as $id) {
            wooSameRecord(WooTestKit::byId($api, $id), WooTestKit::byId($db, $id), 'pedido ' . $id . ($hpos ? ' (HPOS)' : ''));
        }
    }
    $order = WooTestKit::byId($api, '9002');
    same(9559, $order['refunded_total']);
    same(19832, $order['total']);
});

test('API y copia: lo que solo puede dar uno de los dos no se inventa en el otro', function (): void {
    $apiCustomers = WooTestKit::drain(WooTestKit::api(), 'customers')['records'];
    $dbCustomers = WooTestKit::drain(WooTestKit::db(), 'customers')['records'];
    same(count($apiCustomers), count($dbCustomers));
    foreach ($apiCustomers as $i => $customer) {
        same($customer['email'], $dbCustomers[$i]['email']);
        same(null, $customer['password_hash']);
        check($dbCustomers[$i]['password_hash'] !== null && $dbCustomers[$i]['password_hash'] !== '');
    }
    $apiTypes = WooTestKit::api()->types();
    $dbTypes = WooTestKit::db()->types();
    check(!in_array('redirects', $apiTypes, true), 'la API no lee redirecciones');
    check(in_array('redirects', $dbTypes, true), 'la copia sí');
    same([], array_values(array_diff($apiTypes, $dbTypes)), 'la copia lee todo lo que lee la API');
});
