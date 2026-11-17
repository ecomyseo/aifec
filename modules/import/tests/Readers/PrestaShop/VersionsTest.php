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

test('PrestaShop 1.7.8: un esquema con las columnas de entonces se lee entero y bien', function (): void {
    $pdo = PsTestKit::pdo('1.7', '1.7.8');
    $reader = PsTestKit::db([], $pdo);
    $result = PsTestKit::checkAll($reader, 3, ['redirects']);
    same([], $result['problems']);
    same(19, count($reader->types()));
    $probe = $reader->probe();
    same([true, 'PrestaShop 1.7.8'], [$probe['ok'], $probe['version']]);
    $table = PsTestKit::record($reader, 'products', '1');
    same(8264, $table['price']);
    same(false, isset($table['extra']['unit_price']), 'la 1.7 no tiene unit_price y esa mesa no tiene precio por unidad');
    same(6000, PsTestKit::record($reader, 'products', '4')['extra']['unit_price'], 'el precio por unidad sale del ratio, que existe en todas las versiones');
});

test('PrestaShop 1.7.8: el impuesto del transportista sale de la columna de entonces, la 1.7 no tiene category_lang.additional_description y la dirección del pedido usa shipping_number', function (): void {
    $pdo = PsTestKit::pdo('1.7', '1.7.8');
    $pdo->exec('DELETE FROM ps_carrier_tax_rules_group_shop');
    $pdo->exec('UPDATE ps_carrier SET id_tax_rules_group = 2 WHERE id_carrier = 3');
    $pdo->exec('DELETE FROM ps_order_carrier');
    $pdo->exec("UPDATE ps_orders SET shipping_number = 'ENV-9' WHERE id_order = 1");
    $reader = PsTestKit::db([], $pdo);
    $courier = PsTestKit::byId(PsTestKit::drain($reader, 'carriers')['records'], '1');
    same('2', $courier['tax_class_id']);
    same('ENV-9', PsTestKit::record($reader, 'orders', '1')['tracking_code']);
    same(['es' => 'Mesas', 'en' => 'Tables'], PsTestKit::record($reader, 'categories', '4')['name']);
    same(['/es/mesas/1-mesa-auxiliar-de-roble-8400000000017.html', '/en/tables/1-oak-side-table-8400000000017.html'], PsTestKit::record($reader, 'products', '1')['old_paths']);
});

test('PrestaShop 1.7.8: sin la tabla del módulo de suscripción solo salen los clientes con boletín', function (): void {
    $reader = PsTestKit::db([], PsTestKit::pdo('1.7', '1.7.8'));
    $subscribers = PsTestKit::drain($reader, 'subscribers')['records'];
    same(['c1'], array_column($subscribers, 'id'));
    same(1, $reader->count('subscribers'));
});

test('PrestaShop 9.1: columnas nuevas (posición de los valores de característica, cart_rule) y la ruta de producto de serie sin categoría', function (): void {
    $pdo = PsTestKit::pdo('9.1', '9.1.1');
    $pdo->exec('UPDATE ps_feature_value SET position = 5 WHERE id_feature_value = 2');
    $reader = PsTestKit::db([], $pdo);
    $result = PsTestKit::checkAll($reader, 3, ['redirects']);
    same([], $result['problems']);
    same([], $result['empty']);
    $attributes = PsTestKit::drain($reader, 'attributes', 10)['records'];
    $material = PsTestKit::byId($attributes, 'f1');
    same(['1', '2'], array_column($material['values'], 'id'));
    same([0, 5], array_column($material['values'], 'position'), 'la posición sale de feature_value.position, que solo trae la 9');
    same(['/es/1-mesa-auxiliar-de-roble.html', '/en/1-oak-side-table.html'], PsTestKit::record($reader, 'products', '1')['old_paths']);
    $redirect = PsTestKit::drain($reader, 'redirects')['records'][0];
    same(['/es/6-producto-retirado.html', '/es/1-mesa-auxiliar-de-roble.html'], [$redirect['source'], $redirect['target']], 'la redirección usa la misma regla');
    $probe = $reader->probe();
    same([true, 'PrestaShop 9.1.1'], [$probe['ok'], $probe['version']]);
    same(3, count(PsTestKit::drain($reader, 'coupons')['records']));
});

test('PrestaShop 8.2 con una ruta de producto propia conservada tras actualizar a 9: la configuración manda sobre la de serie', function (): void {
    $pdo = PsTestKit::pdo('9.1', '9.1.1');
    $pdo->exec("INSERT INTO ps_configuration (id_configuration, name, value) VALUES (900, 'PS_ROUTE_product_rule', '{category:/}{id}-{rewrite}.html')");
    same(['/es/mesas/1-mesa-auxiliar-de-roble.html', '/en/tables/1-oak-side-table.html'], PsTestKit::record(PsTestKit::db([], $pdo), 'products', '1')['old_paths']);
});

test('PrestaShop 1.6 o anterior se rechaza al comprobar y no se lee', function (): void {
    foreach (['1.6.1.24', ''] as $version) {
        $pdo = PsTestKit::pdo('8.2', $version);
        $probe = PsTestKit::db([], $pdo)->probe();
        same(false, $probe['ok']);
        contains('not supported', $probe['message']);
        same('PrestaShop ' . ($version === '' ? '?' : $version), $probe['version']);
    }
    $new = PsTestKit::db([], PsTestKit::pdo('8.2', '8.2.7'))->probe();
    same(true, $new['ok']);
});

test('todos los registros de todas las versiones pasan Neutral::check y no repiten identificador', function (): void {
    foreach ([['8.2', '8.2.0'], ['1.7', '1.7.8'], ['9.1', '9.1.1']] as $pair) {
        $reader = PsTestKit::db([], PsTestKit::pdo($pair[0], $pair[1]));
        foreach ($reader->types() as $type) {
            $seen = [];
            foreach (PsTestKit::drain($reader, $type, 2)['records'] as $record) {
                same([], Neutral::check($type, $record), $pair[1] . ' ' . $type . ' ' . $record['id']);
                check(!isset($seen[$record['id']]), 'identificador repetido en ' . $type);
                $seen[$record['id']] = true;
            }
        }
    }
});
