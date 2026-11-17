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
use Aifec\Modules\Import\Readers\PrestaShop\DbReader;
use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

require_once __DIR__ . '/fixtures/PsTestKit.php';

test('PrestaShop (copia de la base de datos): todo lo que sale cumple el formato neutro y no hay tipos vacíos', function (): void {
    $reader = PsTestKit::db();
    $result = PsTestKit::checkAll($reader, 3, ['redirects']);
    same([], $result['problems']);
    same([], $result['empty']);
    same(19, count($reader->types()));
    same(6, $result['counts']['products']);
    same(4, $result['counts']['orders']);
});

test('PrestaShop (copia): la ficha dice qué pide y el lector se identifica sin credenciales', function (): void {
    $card = DbReader::describe();
    same('prestashop', $card['source']);
    same('db', $card['mode']);
    foreach (['prefix', 'shop', 'files', 'base_url', 'cookie_key'] as $field) {
        check(isset($card['fields'][$field]), 'falta el campo ' . $field);
    }
    same('secret', $card['fields']['cookie_key']['type']);
    $reader = PsTestKit::db();
    same('prestashop', $reader->source());
    same('db', $reader->mode());
    check(strlen($reader->key()) <= 60, 'la clave de la tienda pasa de 60 caracteres');
    contains('ps_', $reader->key());
});

test('PrestaShop (copia): solo ejecuta SELECT, incluso al comprobar, contar, sumar y listar lo que no se trae', function (): void {
    $pdo = new PsRecordingPdo('sqlite::memory:');
    $source = PsTestKit::pdo();
    foreach (psTables() as $table => $columns) {
        $defs = [];
        foreach (explode(',', $columns) as $column) {
            $defs[] = '`' . $column . '` ' . psColumnType($column);
        }
        $pdo->exec('CREATE TABLE `ps_' . $table . '` (' . implode(', ', $defs) . ')');
    }
    psSeed($pdo);
    $pdo->statements = [];
    $reader = new DbReader($pdo, ['prefix' => 'ps_', 'base_url' => 'https://tienda.test', 'now' => '2026-11-01T00:00:00Z']);
    $reader->probe();
    foreach ($reader->types() as $type) {
        PsTestKit::drain($reader, $type, 2);
        $reader->count($type);
    }
    $reader->checksums();
    $reader->unmapped();
    $reader->orderStates();
    check(count($pdo->statements) > 100, 'el lector apenas ha consultado');
    foreach ($pdo->statements as $statement) {
        check(stripos(ltrim($statement), 'SELECT') === 0, 'consulta que no es SELECT: ' . $statement);
    }
    unset($source);
});

test('PrestaShop (copia): configuración de la tienda', function (): void {
    $settings = PsTestKit::record(PsTestKit::db(), 'settings', 'main');
    same('Muebles Ñandú & Cía', $settings['shop_name']);
    same('EUR', $settings['currency']);
    same(['es', 'en'], $settings['languages']);
    same('es', $settings['default_language']);
    same('Europe/Madrid', $settings['timezone']);
    same('kg', $settings['weight_unit']);
    same('cm', $settings['dimension_unit']);
    same(false, $settings['prices_include_tax']);
    same(true, $settings['display_prices_with_tax']);
    same('shipping', $settings['tax_based_on']);
    same(true, $settings['guest_checkout']);
    same(true, $settings['stock_management']);
    same(['line1' => 'Polígono Sur, nave 4', 'line2' => '', 'postcode' => '41001', 'city' => 'Sevilla', 'region' => 'SE', 'country' => 'ES'], $settings['address']);
    same('F2026-', $settings['invoice_number_prefix']);
    same(3, $settings['invoice_number_next']);
    same(['terms' => '3'], $settings['legal_pages']);
    same(['es' => 'Muebles de madera maciza'], $settings['home_title']);
    same('smtp', $settings['mail']['transport']);
    same('tls', $settings['mail']['encryption']);
    same(587, $settings['mail']['port']);
    same('https://tienda.test/img/logo-1700000000.png', $settings['logo']['url']);
    same('Ñandú Muebles S.L. - B00000000', $settings['extra']['shop_details']);
    same([], Neutral::check('settings', $settings));
});

test('PrestaShop (copia): los impuestos salen por grupo y regla; los borrados o inactivos no salen', function (): void {
    $reader = PsTestKit::db();
    $classes = PsTestKit::drain($reader, 'tax_classes')['records'];
    same(['1', '2'], array_column($classes, 'id'));
    same('es-iva-general', $classes[0]['code']);
    $rates = PsTestKit::drain($reader, 'tax_rates')['records'];
    same(['1', '2', '3', '4', '5', '6', '8', '9'], array_column($rates, 'id'));
    $igic = PsTestKit::byId($rates, '2');
    same(['35000-35999', 700, 'IGIC 7 %', 'ES', ''], [$igic['postcodes'], $igic['rate'], $igic['name'], $igic['country'], $igic['region']]);
    same('38000-38999', PsTestKit::byId($rates, '3')['postcodes']);
    $ipsi = PsTestKit::byId($rates, '4');
    same(['CE', 400, ''], [$ipsi['region'], $ipsi['rate'], $ipsi['postcodes']]);
    same('ML', PsTestKit::byId($rates, '5')['region']);
    same(2100, PsTestKit::byId($rates, '1')['rate']);
    same('', PsTestKit::byId($rates, '1')['postcodes']);
    $compound = PsTestKit::byId($rates, '8');
    same(true, $compound['compound']);
    same('FR', $compound['country']);
    $combine = PsTestKit::byId($rates, '9');
    same('combine', $combine['extra']['behavior']);
    same(false, $combine['compound']);
});

test('PrestaShop (copia): las zonas cubren un país entero o sus provincias, y las vacías o apagadas no salen', function (): void {
    $zones = PsTestKit::drain(PsTestKit::db(), 'shipping_zones')['records'];
    same(['1', '2', '3'], array_column($zones, 'id'));
    same([['country' => 'PT', 'region' => '', 'postcodes' => ''], ['country' => 'FR', 'region' => '', 'postcodes' => '']], $zones[0]['locations']);
    same(['M', 'B', 'CE', 'ML', 'SE'], array_column($zones[1]['locations'], 'region'));
    same(['GC', 'TF'], array_column($zones[2]['locations'], 'region'));
    same('Canarias', $zones[2]['name']);
});

test('PrestaShop (copia): transportistas: editado una sola vez, tarifas por peso y gratis', function (): void {
    $carriers = PsTestKit::drain(PsTestKit::db(), 'carriers')['records'];
    same(['1', '2'], array_column($carriers, 'id'), 'el editado sale una vez (por id_reference) y el apagado no sale');
    $courier = $carriers[0];
    same('Mensajería 24 h', $courier['name']);
    same('rates', $courier['kind']);
    same('1', $courier['tax_class_id']);
    same(6000, $courier['free_from']);
    same(200, $courier['handling_fee']);
    same(20000, $courier['max_weight']);
    same(false, $courier['price_includes_tax']);
    same('https://envios.test/seguir/@', $courier['tracking_url']);
    same(['es' => 'Entrega en 24-48 horas', 'en' => 'Delivery in 24-48 hours'], $courier['delay']);
    same(['1', '2', '3'], $courier['zone_ids']);
    same(9, count($courier['rates']));
    // 4,2 kg a cada zona: 4200 g cae en el primer rango (0 a 5 kg)
    same(990, psRateFor($courier, '1', 4200));
    same(590, psRateFor($courier, '2', 4200));
    same(1250, psRateFor($courier, '3', 4200));
    same(790, psRateFor($courier, '2', 5000));
    same(2200, psRateFor($courier, '3', 12000));
    $free = $carriers[1];
    same(['free', [], null, 0], [$free['kind'], $free['rates'], $free['free_from'], $free['handling_fee']]);
    same(['2'], $free['zone_ids']);
});

test('PrestaShop (copia): dos filas vigentes con el mismo id_reference salen como un solo transportista, el más nuevo', function (): void {
    $pdo = PsTestKit::pdo();
    $pdo->exec("INSERT INTO ps_carrier (id_carrier, id_reference, name, active, deleted, is_free, shipping_method, position, max_weight) VALUES (6, 1, 'Mensajería 24 h v2', 1, 0, 0, 1, 0, '0')");
    $pdo->exec('INSERT INTO ps_carrier_shop (id_carrier, id_shop) VALUES (6, 1)');
    $carriers = PsTestKit::drain(PsTestKit::db([], $pdo), 'carriers')['records'];
    same(['1', '2'], array_column($carriers, 'id'));
    same('Mensajería 24 h v2', $carriers[0]['name']);
    same('6', $carriers[0]['extra']['id_carrier']);
    same(1, count(array_filter($carriers, static function (array $c): bool {
        return $c['id'] === '1';
    })));
});

test('PrestaShop (copia): el transportista de un pedido antiguo casa con el vigente por id_reference', function (): void {
    $reader = PsTestKit::db();
    $order = PsTestKit::record($reader, 'orders', '1');
    $ids = array_column(PsTestKit::drain($reader, 'carriers')['records'], 'id');
    same('1', $order['carrier_id']);
    check(in_array($order['carrier_id'], $ids, true), 'el pedido apunta a un transportista que no sale');
    same('Mensajería 24 h', $order['carrier_title']);
    same('2', PsTestKit::record($reader, 'orders', '4')['carrier_id']);
});

test('PrestaShop (copia): grupos de clientes y marcas', function (): void {
    $reader = PsTestKit::db();
    $groups = PsTestKit::drain($reader, 'customer_groups')['records'];
    same(['1', '2', '3', '4'], array_column($groups, 'id'));
    $pro = PsTestKit::byId($groups, '4');
    same(1000, $pro['discount']);
    same(['es' => 'Profesionales', 'en' => 'Professionals'], $pro['name']);
    same(true, PsTestKit::byId($groups, '3')['is_default']);
    same(false, PsTestKit::byId($groups, '4')['is_default']);
    $brands = PsTestKit::drain($reader, 'brands')['records'];
    $nordika = PsTestKit::byId($brands, '1');
    same(['/es/brand/1-nordika', '/en/brand/1-nordika'], $nordika['old_paths']);
    same(['es' => 'nordika'], $nordika['slug']);
    same('https://tienda.test/img/m/1.jpg', $nordika['logo']['url']);
    same(false, PsTestKit::byId($brands, '2')['active']);
});

test('PrestaShop (copia): categorías sin la raíz del sistema, con su padre y sus direcciones antiguas', function (): void {
    $categories = PsTestKit::drain(PsTestKit::db(), 'categories')['records'];
    same(['2', '3', '4', '5'], array_column($categories, 'id'));
    same(null, PsTestKit::byId($categories, '2')['parent_id']);
    same('2', PsTestKit::byId($categories, '3')['parent_id']);
    $tables = PsTestKit::byId($categories, '4');
    same('3', $tables['parent_id']);
    same(['es' => 'Mesas', 'en' => 'Tables'], $tables['name']);
    same(['/es/4-mesas', '/en/4-tables'], $tables['old_paths']);
    same(['es' => 'Mesas de madera', 'en' => 'Tables of wood'], $tables['meta_title']);
    same(2, PsTestKit::byId($categories, '5')['position']);
});

test('PrestaShop (copia): atributos y características con sus valores', function (): void {
    $attributes = PsTestKit::drain(PsTestKit::db(), 'attributes', 2)['records'];
    same(['a1', 'a2', 'f1', 'f2'], array_column($attributes, 'id'));
    $color = PsTestKit::byId($attributes, 'a2');
    same(['es' => 'Color', 'en' => 'Colour'], $color['name'], 'el nombre de cara al cliente');
    same('color', $color['type']);
    same(true, $color['variant']);
    same('#C19A6B', $color['values'][0]['color']);
    same(['3', '4'], array_column($color['values'], 'id'));
    $size = PsTestKit::byId($attributes, 'a1');
    same('select', $size['type']);
    same(false, isset($size['values'][0]['color']));
    $material = PsTestKit::byId($attributes, 'f1');
    same(false, $material['variant']);
    same(['1', '2'], array_column($material['values'], 'id'));
    same([], PsTestKit::byId($attributes, 'f2')['values'], 'los valores personalizados no son valores de la lista');
});

test('PrestaShop (copia): precio sin impuestos en céntimos (82.644628 son 8264) y todos los datos del producto', function (): void {
    $table = PsTestKit::record(PsTestKit::db(), 'products', '1');
    same(8264, $table['price']);
    same(false, $table['price_includes_tax']);
    same(4000, $table['cost']);
    same('physical', $table['kind']);
    same('MESA-ROBLE', $table['sku']);
    same('8400000000017', $table['gtin']);
    same('1', $table['brand_id']);
    same(['3', '4'], $table['category_ids']);
    same('4', $table['main_category_id']);
    same('1', $table['tax_class_id']);
    same(6500, $table['weight']);
    same([400, 400, 480], [$table['length'], $table['width'], $table['height']], 'depth, width y height de 40, 40 y 48 cm en milímetros');
    same(14, $table['stock']);
    same('no', $table['backorders']);
    same('published', $table['status']);
    same('visible', $table['visibility']);
    same(['2'], $table['related_ids']);
    same(['es' => ['roble'], 'en' => ['oak']], $table['tags']);
    same([['attribute_id' => 'f1', 'value_ids' => ['1']], ['attribute_id' => 'f2', 'text' => ['es' => '48', 'en' => '48']]], $table['attributes']);
    same('2025-02-11T09:00:00Z', $table['created_at']);
    same('2026-10-01T06:00:00Z', $table['updated_at']);
    same(['name' => 'manual-mesa.pdf', 'bytes' => 20480, 'path' => 'download/a1b2c3d4'], $table['files'][0]);
    same([true, false], array_column($table['images'], 'cover'));
    same('https://tienda.test/img/p/1/1.jpg', $table['images'][0]['url']);
    same(['/es/mesas/1-mesa-auxiliar-de-roble-8400000000017.html', '/en/tables/1-oak-side-table-8400000000017.html'], $table['old_paths']);
    same('Mesa auxiliar de roble | Ñandú', $table['meta_title']['es']);
    same(['supplier' => '1', 'reference' => 'NRD-44', 'price' => 4000], $table['extra']['suppliers'][0]);
    same([['product' => '2', 'combination' => '0', 'qty' => 2]], $table['extra']['pack']);
    same(true, $table['extra']['available_for_order']);
});

test('PrestaShop (copia): combinaciones con precio final, existencias, imagen y valores de dos atributos', function (): void {
    $chair = PsTestKit::record(PsTestKit::db(), 'products', '2');
    same(5000, $chair['price']);
    same(4, count($chair['variants']));
    same([5000, 6240, 6000, 7240], array_column($chair['variants'], 'price'), '50 + 12,396694 son 62,396694: 6240');
    same([10, 5, 7, 3], array_column($chair['variants'], 'stock'));
    same([4000, 4500, 5000, 5500], array_column($chair['variants'], 'weight'));
    same([0, 1, 2, null], array_map(static function (array $v) {
        return isset($v['image_index']) ? $v['image_index'] : null;
    }, $chair['variants']));
    same([['attribute_id' => 'a1', 'value_id' => '1'], ['attribute_id' => 'a2', 'value_id' => '4']], $chair['variants'][1]['values']);
    same('SILLA-P-NOG', $chair['variants'][1]['sku']);
    same(25, $chair['stock']);
    same('1', $chair['extra']['default_variant']);
    same([], Neutral::check('products', $chair));
});

test('PrestaShop (copia): precio específico vigente a oferta, y los demás a «extra»', function (): void {
    $reader = PsTestKit::db();
    $armchair = PsTestKit::record($reader, 'products', '4');
    same(12000, $armchair['price']);
    same(9600, $armchair['sale_price'], 'un 20 % de rebaja general sobre 120,00');
    same('2025-12-31T23:00:00Z', $armchair['sale_from']);
    same(null, $armchair['sale_to']);
    same(2, count($armchair['extra']['specific_prices']), 'el de un grupo y el caducado');
    same([4, '0.300000'], [$armchair['extra']['specific_prices'][0]['group'], $armchair['extra']['specific_prices'][0]['reduction']]);
    same('2025-12-31T22:59:59Z', $armchair['extra']['specific_prices'][1]['to']);
    same('refurbished', $armchair['extra']['condition']);
    same(150, $armchair['extra']['ecotax']);
    same('ud', $armchair['extra']['unity']);
    same(6000, $armchair['extra']['unit_price']);
    $cloth = PsTestKit::record($reader, 'products', '5');
    same(null, $cloth['sale_price'], 'un descuento por cantidad no es una oferta');
    same(5, $cloth['extra']['specific_prices'][0]['from_quantity']);
    same('yes', $cloth['backorders']);
    same('2026-12-01', $cloth['extra']['available_date']);
    same(2, $cloth['extra']['text_fields']);
    same(1, $cloth['extra']['customizable']);
});

test('PrestaShop (copia): producto virtual, inactivo y oculto', function (): void {
    $reader = PsTestKit::db();
    $guide = PsTestKit::record($reader, 'products', '3');
    same('digital', $guide['kind']);
    same(992, $guide['price']);
    same('2', $guide['tax_class_id']);
    same([['name' => 'guia-de-cuidado.pdf', 'path' => 'download/dl9f8e7d6c']], $guide['files']);
    same(['times' => 3, 'days' => 30], $guide['extra']['download']);
    $old = PsTestKit::record($reader, 'products', '6');
    same(['draft', 'hidden'], [$old['status'], $old['visibility']]);
    same(false, $old['extra']['available_for_order']);
    same(false, $old['extra']['show_price']);
});

test('PrestaShop (copia): clientes con direcciones no borradas, grupos, consentimientos y contraseña heredada', function (): void {
    $reader = PsTestKit::db();
    $customers = PsTestKit::drain($reader, 'customers')['records'];
    same(['1', '2', '3'], array_column($customers, 'id'), 'sin el de la otra tienda y sin el borrado');
    $ana = PsTestKit::byId($customers, '1');
    same('ana@cliente.test', $ana['email']);
    same('3', $ana['group_id']);
    same(2, count($ana['addresses']), 'la dirección borrada no sale');
    same(['Casa', 'Oficina'], array_column($ana['addresses'], 'label'));
    same(['ES', 'ES'], array_column($ana['addresses'], 'country'));
    same(['M', 'GC'], array_column($ana['addresses'], 'region'));
    same('999000001', $ana['addresses'][0]['phone']);
    same('12345678Z', $ana['vat_id']);
    same(true, $ana['marketing']);
    same('2024-05-02T16:20:00Z', $ana['marketing_at']);
    same([['kind' => 'marketing', 'granted' => true, 'at' => '2024-05-02T16:20:00Z', 'context' => 'newsletter'], ['kind' => 'other', 'granted' => true, 'at' => null, 'context' => 'partner_offers']], $ana['consents']);
    same('bcrypt', $ana['password_scheme']);
    same(['3', '4'], $ana['extra']['group_ids']);
    same('es', $ana['locale']);
    same(false, $ana['guest']);
    $guest = PsTestKit::byId($customers, '3');
    same([true, null], [$guest['guest'], $guest['password_hash']]);
    same('2', $guest['group_id']);
    $luis = PsTestKit::byId($customers, '2');
    same('prestashop-md5', $luis['password_scheme']);
    same(false, $luis['marketing']);
    same([], $luis['consents']);
});

test('PrestaShop (copia): contraseñas md5 piden la clave de cookies y esa clave no sale en ningún registro', function (): void {
    $without = PsTestKit::record(PsTestKit::db(), 'customers', '2');
    same(true, $without['extra']['cookie_key_needed']);
    $reader = PsTestKit::db(['cookie_key' => 'clave-de-cookies-de-mentira']);
    $with = PsTestKit::record($reader, 'customers', '2');
    same(false, $with['extra']['cookie_key_needed']);
    foreach (PsTestKit::drain($reader, 'customers')['records'] as $record) {
        notContains('clave-de-cookies-de-mentira', (string) json_encode($record));
    }
    foreach ($reader->types() as $type) {
        foreach (PsTestKit::drain($reader, $type)['records'] as $record) {
            notContains('clave-de-cookies-de-mentira', (string) json_encode($record));
        }
    }
    notContains('clave-de-cookies-de-mentira', (string) json_encode($reader->probe()));
    $redacted = Neutral::redact('customers', PsTestKit::record(PsTestKit::db(), 'customers', '1'));
    same('***', $redacted['password_hash']);
    $mail = Neutral::redact('settings', PsTestKit::record(PsTestKit::db(), 'settings', 'main'));
    same('***', $mail['mail']['password']);
    notContains('clave-de-mentira', (string) json_encode($mail));
});

test('PrestaShop (copia): pedido entero, con las cifras a mano y la suma de las líneas al céntimo', function (): void {
    $order = PsTestKit::record(PsTestKit::db(), 'orders', '1');
    same('AAAAAAAAA', $order['number']);
    same('ana@cliente.test', $order['email']);
    same(false, $order['prices_include_tax']);
    same([20744, 0, 488, 0], [$order['items_total'], $order['discount_total'], $order['shipping_total'], $order['fee_total']]);
    same([21232, 4459, 25691, 25691], [$order['total_net'], $order['total_tax'], $order['total'], $order['paid_total']]);
    same(3, count($order['items']));
    same(['product', 'product', 'shipping'], array_column($order['items'], 'kind'));
    same([8264, 12480, 488], array_column($order['items'], 'line_net'));
    same([1736, 2621, 102], array_column($order['items'], 'line_tax'));
    same(21232, array_sum(array_column($order['items'], 'line_net')), 'las líneas suman la base del pedido');
    same(4459, array_sum(array_column($order['items'], 'line_tax')), 'y el impuesto');
    same(25691, array_sum(array_column($order['items'], 'line_net')) + array_sum(array_column($order['items'], 'line_tax')), 'y el total');
    same([2100, 2100, 2100], array_column($order['items'], 'tax_rate'));
    same('IVA 21 %', $order['items'][0]['tax_name']);
    same([['name' => 'IVA 21 %', 'rate' => 2100, 'base' => 21232, 'amount' => 4459]], $order['taxes']);
    same(['1', '2'], [$order['items'][0]['product_id'], $order['items'][1]['product_id']]);
    same('2', $order['items'][1]['variant_id']);
    same(['SILLA-P-NOG', 2, 6240, 12480], [$order['items'][1]['sku'], $order['items'][1]['qty'], $order['items'][1]['unit_price'], $order['items'][1]['line_total']]);
    same(['4', 'shipped'], [$order['state'], $order['state_meaning']]);
    same('TRK123', $order['tracking_code']);
    same('2026-10-01T10:15:00Z', $order['placed_at']);
    same('2026-10-01T10:16:00Z', $order['paid_at']);
    same('2026-10-02T08:00:00Z', $order['shipped_at']);
    same(null, $order['completed_at']);
    same([['method' => 'Transferencia bancaria', 'kind' => 'charge', 'reference' => 'TR-1', 'amount' => 25691, 'status' => 'ok', 'at' => '2026-10-01T10:16:00Z']], $order['payments']);
    same([['number' => 'F2026-000001', 'date' => '2026-10-01T10:16:00Z', 'kind' => 'invoice']], $order['invoices']);
    same(['', '1', '2', '3'], array_column($order['history'], 'from'));
    same(['1', '2', '3', '4'], array_column($order['history'], 'to'));
    same('ps_wirepayment', $order['payment_method']);
    same('es', $order['locale']);
    same(['M', 'ES'], [$order['billing']['region'], $order['shipping']['country']]);
    same(true, $order['extra']['valid']);
    same(false, isset($order['extra']['lines_net_diff']), 'las cuentas cuadran');
});

test('PrestaShop (copia): todos los pedidos cuadran: la suma de las líneas da el total al céntimo', function (): void {
    foreach (PsTestKit::drain(PsTestKit::db(), 'orders')['records'] as $order) {
        $net = array_sum(array_column($order['items'], 'line_net'));
        $tax = array_sum(array_column($order['items'], 'line_tax'));
        same($order['total_net'], $net, 'base del pedido ' . $order['number']);
        same($order['total_tax'], $tax, 'impuesto del pedido ' . $order['number']);
        same($order['total'], $net + $tax, 'total del pedido ' . $order['number']);
        same([], Neutral::check('orders', $order));
    }
});

test('PrestaShop (copia): pedido con cupón, abono y anulado', function (): void {
    $reader = PsTestKit::db();
    $coupon = PsTestKit::record($reader, 'orders', '2');
    same(['product', 'shipping', 'discount'], array_column($coupon['items'], 'kind'));
    same([-793, -167], [$coupon['items'][2]['line_net'], $coupon['items'][2]['line_tax']]);
    same([['code' => 'OTOÑO10', 'amount' => 793]], $coupon['coupons']);
    same([793, 9230], [$coupon['discount_total'], $coupon['total']]);
    same('paid', $coupon['state_meaning']);
    same('redsys', $coupon['payment_method']);
    $refunded = PsTestKit::record($reader, 'orders', '3');
    same([['amount' => 1091, 'tax' => 99, 'reason' => '', 'at' => '2026-10-13T07:00:00Z', 'restock' => true, 'lines' => [['index' => 1, 'qty' => 1, 'amount' => 1091]]]], $refunded['refunds']);
    same(1091, $refunded['refunded_total']);
    same([1, 1091], [$refunded['items'][1]['refunded_qty'], $refunded['items'][1]['refunded_total']]);
    same(true, $refunded['items'][1]['is_digital']);
    same(['F2026-000002', 'AB000001'], array_column($refunded['invoices'], 'number'));
    same(['invoice', 'credit'], array_column($refunded['invoices'], 'kind'));
    same('delivered', $refunded['state_meaning']);
    same('2026-10-12T08:00:00Z', $refunded['completed_at']);
    $cancelled = PsTestKit::record($reader, 'orders', '4');
    same(['cancelled', '2026-10-10T06:00:00Z'], [$cancelled['state_meaning'], $cancelled['cancelled_at']]);
    same(false, $cancelled['extra']['valid']);
    same(0, $cancelled['paid_total']);
    same('Cheque', $cancelled['payment_title']);
});

test('PrestaShop (copia): estados de pedido para la tabla de equivalencias', function (): void {
    $states = PsTestKit::db()->orderStates();
    same(8, count($states));
    same(['name' => 'Enviado', 'meaning' => 'shipped'], $states['4']);
    same('cancelled', $states['6']['meaning']);
    same('refunded', $states['7']['meaning']);
    same('failed', $states['8']['meaning']);
    same('pending', $states['1']['meaning']);
    same('paid', $states['2']['meaning']);
    same('processing', $states['3']['meaning']);
    same('delivered', $states['5']['meaning']);
});

test('PrestaShop (copia): reglas de carrito a cupones, con lo que no cabe en «unsupported»', function (): void {
    $coupons = PsTestKit::drain(PsTestKit::db(), 'coupons')['records'];
    $autumn = PsTestKit::byId($coupons, '1');
    same(['OTOÑO10', 'percent', 1000, 5000, 200, 1, 1], [$autumn['code'], $autumn['kind'], $autumn['percent'], $autumn['min_subtotal'], $autumn['max_uses'], $autumn['uses'], $autumn['max_uses_per_customer']]);
    same(['4'], $autumn['category_ids']);
    same([true, []], [$autumn['exclude_sale'], $autumn['unsupported']]);
    same('2026-09-20T22:00:00Z', $autumn['starts_at']);
    $shipping = PsTestKit::byId($coupons, '2');
    same(['free_shipping', 8000, ['carrier_restriction']], [$shipping['kind'], $shipping['min_subtotal'], $shipping['unsupported']]);
    same(null, $shipping['max_uses_per_customer']);
    $automatic = PsTestKit::byId($coupons, '3');
    same(['amount', 500, true, false], [$automatic['kind'], $automatic['amount'], $automatic['amount_includes_tax'], $automatic['active']]);
    same(['automatic', 'gift', 'group_restriction', 'combinations'], $automatic['unsupported']);
    same('AUTO-3', $automatic['code']);
});

test('PrestaShop (copia): páginas, equipo, opiniones, suscritos y formas de pago', function (): void {
    $reader = PsTestKit::db();
    $pages = PsTestKit::drain($reader, 'pages')['records'];
    same(['1', '2', '3'], array_column($pages, 'id'));
    same('terms', PsTestKit::byId($pages, '3')['legal']);
    same(false, isset(PsTestKit::byId($pages, '1')['legal']));
    same(['/es/content/3-terminos-y-condiciones', '/en/content/3-terms-and-conditions'], PsTestKit::byId($pages, '3')['old_paths']);
    same('Envíos | Ñandú', PsTestKit::byId($pages, '1')['meta_title']['es']);
    same(['cms_category' => '1', 'noindex' => true], PsTestKit::byId($pages, '2')['extra']);
    contains('Ñandú Muebles S.L.', PsTestKit::byId($pages, '2')['content']['es']);
    $team = PsTestKit::drain($reader, 'team')['records'];
    same(['admin', 'manager'], array_column($team, 'role_meaning'));
    same(['SuperAdmin', 'Logística'], array_column($team, 'role'));
    same([true, false], array_column($team, 'active'));
    same(['es', 'en'], array_column($team, 'locale'));
    notContains('no-se-lee-nunca', (string) json_encode($team));
    same('2026-10-29T23:00:00Z', $team[0]['last_login_at']);
    same(null, $team[1]['last_login_at']);
    $reviews = PsTestKit::drain($reader, 'reviews')['records'];
    same(['1', '2'], array_column($reviews, 'id'));
    same(['approved', 'pending'], array_column($reviews, 'status'));
    same([5, 3], array_column($reviews, 'rating'));
    same('1', $reviews[0]['customer_id']);
    $subscribers = PsTestKit::drain($reader, 'subscribers', 2)['records'];
    same(['e1', 'e2', 'c1'], array_column($subscribers, 'id'));
    same(['en'], [$subscribers[1]['locale']]);
    same('2024-05-02T16:20:00Z', $subscribers[2]['granted_at']);
    $payments = PsTestKit::drain($reader, 'payment_methods')['records'];
    same(['ps_wirepayment', 'ps_checkpayment', 'paypal'], array_column($payments, 'id'));
    same([true, false, true], array_column($payments, 'enabled'));
    same(true, $payments[2]['sandbox']);
    same('Ñandú Muebles S.L.', $payments[0]['settings']['BANK_WIRE_OWNER']);
    same('***', Neutral::redact('payment_methods', $payments[2])['settings']['PAYPAL_API_PASSWORD']);
    notContains('clave-de-mentira', (string) json_encode(Neutral::redact('payment_methods', $payments[2])));
});

test('PrestaShop (copia): redirecciones de productos con redirect_type 301 o 302', function (): void {
    $redirects = PsTestKit::drain(PsTestKit::db(), 'redirects')['records'];
    same([['id' => 'p6', 'source' => '/es/muebles/6-producto-retirado.html', 'target' => '/es/mesas/1-mesa-auxiliar-de-roble-8400000000017.html', 'code' => 301, 'active' => true]], $redirects);
});

test('PrestaShop (copia): direcciones antiguas con la ruta de serie y con una ruta propia con {category}', function (): void {
    $pdo = PsTestKit::pdo();
    $pdo->exec("INSERT INTO ps_configuration (id_configuration, name, value) VALUES (900, 'PS_ROUTE_product_rule', '{categories}/{id}-{rewrite}.html')");
    $pdo->exec("INSERT INTO ps_configuration (id_configuration, name, value) VALUES (901, 'PS_ROUTE_category_rule', 'c/{id}-{rewrite}')");
    $custom = PsTestKit::record(PsTestKit::db([], $pdo), 'products', '1');
    same(['/es/muebles/mesas/1-mesa-auxiliar-de-roble.html', '/en/furniture/tables/1-oak-side-table.html'], $custom['old_paths']);
    same(['/es/c/4-mesas', '/en/c/4-tables'], PsTestKit::record(PsTestKit::db([], $pdo), 'categories', '4')['old_paths']);
    $pdo->exec("UPDATE ps_configuration SET value = '{category}/{id}-{rewrite}-{ean13}' WHERE id_configuration = 900");
    same('/es/mesas/1-mesa-auxiliar-de-roble-8400000000017', PsTestKit::record(PsTestKit::db([], $pdo), 'products', '1')['old_paths'][0]);
});

test('PrestaShop (copia): con un solo idioma activo las direcciones no llevan prefijo', function (): void {
    $pdo = PsTestKit::pdo();
    $pdo->exec('UPDATE ps_lang SET active = 0 WHERE id_lang = 2');
    $reader = PsTestKit::db([], $pdo);
    same(['/mesas/1-mesa-auxiliar-de-roble-8400000000017.html'], PsTestKit::record($reader, 'products', '1')['old_paths']);
    same(['/4-mesas'], PsTestKit::record($reader, 'categories', '4')['old_paths']);
    same(['/content/3-terminos-y-condiciones'], PsTestKit::record($reader, 'pages', '3')['old_paths']);
    same(['es'], PsTestKit::record($reader, 'settings', 'main')['languages']);
    same(['es'], array_keys(PsTestKit::record($reader, 'products', '1')['name']));
});

test('PrestaShop (copia): multitienda: con shop = 2 salen el nombre, los precios y los productos de esa tienda', function (): void {
    $reader = PsTestKit::db(['shop' => '2']);
    same('Segunda Tienda', PsTestKit::record($reader, 'settings', 'main')['shop_name']);
    $products = PsTestKit::drain($reader, 'products')['records'];
    same(['1', '2'], array_column($products, 'id'));
    same([9000, 5500], array_column($products, 'price'));
    same('Mesa auxiliar de roble (tienda 2)', $products[0]['name']['es']);
    same(3, $products[0]['stock']);
    same(['marta@cliente.test'], array_column(PsTestKit::drain($reader, 'customers')['records'], 'email'));
    $default = PsTestKit::db();
    same('Muebles Ñandú & Cía', PsTestKit::record($default, 'settings', 'main')['shop_name']);
    same(8264, PsTestKit::record($default, 'products', '1')['price']);
    same(2, $reader->count('products'));
    same(6, $default->count('products'));
});

test('PrestaShop (copia): la imagen es el fichero de la carpeta si está y la dirección de la tienda si no', function (): void {
    $dir = tmpDir('pscopy');
    mkdir($dir . '/img/p/1', 0777, true);
    file_put_contents($dir . '/img/p/1/1.jpg', 'x');
    $table = PsTestKit::record(PsTestKit::db(['files' => $dir]), 'products', '1');
    same(['path' => 'img/p/1/1.jpg', 'position' => 0, 'cover' => true], $table['images'][0]);
    same(['url' => 'https://tienda.test/img/p/2/2.jpg', 'position' => 1, 'cover' => false], $table['images'][1]);
    same(['url' => 'https://tienda.test/img/logo-1700000000.png'], PsTestKit::record(PsTestKit::db(['files' => $dir]), 'settings', 'main')['logo']);
    file_put_contents($dir . '/img/logo-1700000000.png', 'x');
    same(['path' => 'img/logo-1700000000.png'], PsTestKit::record(PsTestKit::db(['files' => $dir]), 'settings', 'main')['logo']);
});

test('PrestaShop (copia): cursor por clave sin saltos ni repeticiones con huecos en los identificadores', function (): void {
    $pdo = PsTestKit::pdo();
    $pdo->exec("INSERT INTO ps_product (id_product, id_category_default, price, active, date_add) VALUES (50, 3, '5.000000', 1, '2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO ps_product_shop (id_product, id_shop, id_category_default, price, active, date_add) VALUES (50, 1, 3, '5.000000', 1, '2026-01-01 00:00:00')");
    $pdo->exec("INSERT INTO ps_product_lang (id_product, id_shop, id_lang, name, link_rewrite) VALUES (50, 1, 1, 'Producto 50', 'producto-50')");
    $pdo->exec('DELETE FROM ps_product_shop WHERE id_product = 3');
    $reader = PsTestKit::db([], $pdo);
    foreach ([1, 2, 3, 100] as $limit) {
        $drained = PsTestKit::drain($reader, 'products', $limit);
        same(['1', '2', '4', '5', '6', '50'], array_column($drained['records'], 'id'), 'límite ' . $limit);
        same(6, $drained['read']);
        same([], $drained['skipped']);
    }
    $first = $reader->read('products', '', 2);
    same('2', $first['next']);
    $second = $reader->read('products', $first['next'], 2);
    same(['4', '5'], array_column($second['records'], 'id'));
    same('5', $second['next']);
    $last = $reader->read('products', '6', 5);
    same(['50'], array_column($last['records'], 'id'));
    same(null, $last['next']);
    $empty = $reader->read('products', '50', 5);
    same([[], null, 0], [$empty['records'], $empty['next'], $empty['read']]);
    throws(function () use ($reader): void {
        $reader->read('products', 'no-es-un-cursor', 5);
    }, SourceError::class);
});

test('PrestaShop (copia): un registro roto va a «skipped», cuenta como leído y no corta la tanda', function (): void {
    $pdo = PsTestKit::pdo();
    $pdo->exec("UPDATE ps_product_lang SET name = '' WHERE id_product = 2");
    $pdo->exec("DELETE FROM ps_country WHERE id_country = 2");
    $reader = PsTestKit::db([], $pdo);
    $batch = $reader->read('products', '', 3);
    same(['1', '3'], array_column($batch['records'], 'id'));
    same(3, $batch['read']);
    same(1, count($batch['skipped']));
    same('2', $batch['skipped'][0]['id']);
    contains('no name', $batch['skipped'][0]['reason']);
    same('3', $batch['next']);
    $all = PsTestKit::drain($reader, 'products', 2);
    same(6, $all['read']);
    same(5, count($all['records']));
    // un pedido a un país que ya no existe sigue saliendo sin esa dirección
    $order = PsTestKit::record($reader, 'orders', '2');
    same(false, isset($order['billing']['country']));
});

test('PrestaShop (copia): comprobar la conexión hace la primera lectura real y avisa de lo que conviene saber', function (): void {
    $result = PsTestKit::db()->probe();
    same(true, $result['ok']);
    same('PrestaShop 8.2.0', $result['version']);
    same(['es', 'en'], $result['languages']);
    same('EUR', $result['currency']);
    same(6, $result['counts']['products']);
    same(4, $result['counts']['orders']);
    same(3, $result['counts']['customers']);
    $text = implode(' | ', $result['warnings']);
    contains('cookie key', $text);
    contains('more than one currency', $text);
    contains('multistore', $text);
    same(['1', '2'], array_column($result['shops'], 'id'));
    $withKey = PsTestKit::db(['cookie_key' => 'clave-de-cookies-de-mentira'])->probe();
    notContains('cookie key', implode(' | ', $withKey['warnings']));
    $pdo = PsTestKit::pdo();
    $pdo->exec('DELETE FROM ps_product_shop WHERE id_shop = 1');
    $empty = PsTestKit::db([], $pdo)->probe();
    same(false, $empty['ok'], 'sin productos no es un «conecta»');
    contains('no products', $empty['message']);
});

test('PrestaShop (copia): un usuario sin permiso de lectura es SourceDenied y la clave de conexión no sale en el mensaje', function (): void {
    $pdo = new PsRecordingPdo('sqlite::memory:');
    $pdo->deny = true;
    $reader = new DbReader($pdo, ['prefix' => 'ps_']);
    try {
        $reader->read('products', '', 5);
        check(false, 'tenía que lanzar');
    } catch (SourceDenied $e) {
        notContains('secreto-de-mentira', $e->getMessage());
    }
    $probe = $reader->probe();
    same(false, $probe['ok']);
    notContains('secreto-de-mentira', (string) json_encode($probe));
    $broken = new DbReader(new PDO('sqlite::memory:'), ['prefix' => 'ps_']);
    throws(function () use ($broken): void {
        $broken->read('products', '', 5);
    }, SourceError::class);
    same(false, $broken->probe()['ok']);
});

test('PrestaShop (copia): recuentos y sumas de control', function (): void {
    $reader = PsTestKit::db();
    same([6, 4, 3, 3, 3, 8, 2, 4], [$reader->count('products'), $reader->count('orders'), $reader->count('customers'), $reader->count('coupons'), $reader->count('pages'),
        $reader->count('tax_rates'), $reader->count('carriers'), $reader->count('categories')]);
    same(3, $reader->count('subscribers'));
    same(4, $reader->count('attributes'));
    same(1, $reader->count('redirects'));
    same(3, $reader->count('shipping_zones'));
    $sums = $reader->checksums();
    same(6, $sums['products_count']);
    same(4, $sums['variants_count']);
    same(4, $sums['categories_count']);
    same(3, $sums['customers_count']);
    same(4, $sums['addresses_count']);
    same(4, $sums['orders_count']);
    same(3, $sums['orders_valid_count']);
    same(25691 + 9230 + 11681 + 7550, $sums['orders_total']);
    same(6, $sums['order_items_count']);
    same(3, $sums['coupons_count']);
    same(8, $sums['tax_rates_count']);
    same(3, $sums['pages_count']);
    same(14 + 25 + 999 + 6 + 0 + 2, $sums['stock_total']);
});

test('PrestaShop (copia): lo que no se trae: módulos de terceros y funciones sin equivalente, con su recuento', function (): void {
    $unmapped = PsTestKit::db()->unmapped();
    $names = array_column($unmapped, 'name');
    check(in_array('ecomextra', $names, true), 'falta el módulo de terceros activo');
    check(in_array('otromodulo', $names, true), 'falta el módulo de terceros inactivo');
    foreach (['ps_banner', 'statsdata', 'paypal', 'ps_wirepayment', 'productcomments'] as $known) {
        check(!in_array($known, $names, true), $known . ' no es un módulo sin equivalente');
    }
    $byName = [];
    foreach ($unmapped as $entry) {
        $byName[$entry['name']] = $entry;
    }
    same(['module', 1], [$byName['ecomextra']['kind'], $byName['ecomextra']['count']]);
    contains('active', $byName['ecomextra']['note']);
    contains('inactive', $byName['otromodulo']['note']);
    same(1, $byName['pack products']['count']);
    same(1, $byName['products with customization']['count']);
    same(1, $byName['quantity discounts']['count']);
    same(1, $byName['prices by customer group']['count']);
    same(1, $byName['suppliers']['count']);
    same(1, $byName['physical stores']['count']);
    same(1, $byName['warehouses']['count']);
    same(2, $byName['customer service messages']['count']);
    same(2, $byName['abandoned carts']['count']);
    same(1, $byName['additional currencies']['count']);
    same(1, $byName['additional languages']['count']);
    same(1, $byName['tax rules that combine taxes']['count']);
    foreach ($unmapped as $entry) {
        same(['kind', 'name', 'count', 'note'], array_keys($entry));
    }
});
