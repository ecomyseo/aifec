<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/**
 * Inserta una fila ignorando las columnas que la versión de PrestaShop no tiene (así el mismo juego de datos vale para 1.7, 8.2 y 9.1).
 *
 * @param array<string,mixed> $row
 */
function psInsert(\PDO $pdo, string $table, array $row): void
{
    static $columns = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (!isset($columns[$key])) {
        $columns[$key] = [];
        foreach ($pdo->query('PRAGMA table_info(`ps_' . $table . '`)') as $info) {
            $columns[$key][(string) $info['name']] = true;
        }
    }
    $row = array_intersect_key($row, $columns[$key]);
    if ($row === []) {
        return;
    }
    $names = array_map(static function (string $name): string {
        return '`' . $name . '`';
    }, array_keys($row));
    $statement = $pdo->prepare('INSERT INTO `ps_' . $table . '` (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($row), '?')) . ')');
    $statement->execute(array_values($row));
}

/**
 * La tienda de mentira: una tienda en España (idiomas es y en) con IVA, IGIC en Canarias (códigos 35000-35999 y 38000-38999) e IPSI
 * en Ceuta y Melilla; 3 zonas, 2 transportistas vigentes (uno por peso con tres rangos, otro gratis; el de peso se editó y su fila
 * vieja está borrada), 6 productos, 3 clientes (más uno de la segunda tienda y uno borrado), 4 pedidos, 3 reglas de carrito, 3 páginas.
 * Una segunda tienda (id 2) vende solo dos productos y con otros precios.
 *
 * @param array<string,mixed> $opts «version» (el valor de PS_VERSION_DB, por defecto «8.2.0»)
 */
function psSeed(\PDO $pdo, array $opts = []): void
{
    $version = isset($opts['version']) ? (string) $opts['version'] : '8.2.0';
    $i = static function (string $table, array $row) use ($pdo): void {
        psInsert($pdo, $table, $row);
    };
    // Una fila por idioma (1 = es, 2 = en): $texts = [columna => [texto es, texto en]].
    $l = static function (string $table, array $keys, array $texts) use ($i): void {
        foreach ([1, 2] as $lang) {
            $row = $keys + ['id_lang' => $lang];
            foreach ($texts as $column => $pair) {
                $row[$column] = $pair[$lang - 1];
            }
            $i($table, $row);
        }
    };

    // ---------------------------------------------------------------- tiendas, idiomas, monedas, configuración
    $i('shop', ['id_shop' => 1, 'id_shop_group' => 1, 'name' => 'Muebles Ñandú', 'id_category' => 2, 'active' => 1, 'deleted' => 0]);
    $i('shop', ['id_shop' => 2, 'id_shop_group' => 1, 'name' => 'Segunda Tienda', 'id_category' => 2, 'active' => 1, 'deleted' => 0]);
    $i('shop_url', ['id_shop_url' => 1, 'id_shop' => 1, 'domain' => 'tienda.test', 'domain_ssl' => 'tienda.test', 'physical_uri' => '/', 'virtual_uri' => '', 'main' => 1, 'active' => 1]);
    $i('lang', ['id_lang' => 1, 'name' => 'Español', 'active' => 1, 'iso_code' => 'es', 'language_code' => 'es-es']);
    $i('lang', ['id_lang' => 2, 'name' => 'English', 'active' => 1, 'iso_code' => 'en', 'language_code' => 'en-us']);
    $i('lang', ['id_lang' => 3, 'name' => 'Français', 'active' => 0, 'iso_code' => 'fr', 'language_code' => 'fr-fr']);
    $i('currency', ['id_currency' => 1, 'name' => 'Euro', 'iso_code' => 'EUR', 'deleted' => 0, 'active' => 1]);
    $i('currency', ['id_currency' => 2, 'name' => 'Dólar', 'iso_code' => 'USD', 'deleted' => 0, 'active' => 1]);
    $config = [
        'PS_SHOP_NAME' => 'Muebles Ñandú & Cía', 'PS_SHOP_EMAIL' => 'hola@tienda.test', 'PS_SHOP_PHONE' => '999500007', 'PS_SHOP_ADDR1' => 'Polígono Sur, nave 4',
        'PS_SHOP_ADDR2' => '', 'PS_SHOP_CODE' => '41001', 'PS_SHOP_CITY' => 'Sevilla', 'PS_SHOP_COUNTRY_ID' => '1', 'PS_SHOP_STATE_ID' => '7',
        'PS_SHOP_DETAILS' => 'Ñandú Muebles S.L. - B00000000', 'PS_SHOP_DOMAIN' => 'tienda.test', 'PS_CURRENCY_DEFAULT' => '1', 'PS_LANG_DEFAULT' => '1',
        'PS_COUNTRY_DEFAULT' => '1', 'PS_TIMEZONE' => 'Europe/Madrid', 'PS_WEIGHT_UNIT' => 'kg', 'PS_DIMENSION_UNIT' => 'cm', 'PS_CUSTOMER_GROUP' => '3',
        'PS_TAX_ADDRESS_TYPE' => 'id_address_delivery', 'PS_TAX' => '1', 'PS_GUEST_CHECKOUT_ENABLED' => '1', 'PS_STOCK_MANAGEMENT' => '1', 'PS_ORDER_OUT_OF_STOCK' => '0',
        'PS_INVOICE_START_NUMBER' => '1', 'PS_MAIL_METHOD' => '2', 'PS_MAIL_SERVER' => 'smtp.tienda.test', 'PS_MAIL_USER' => 'envios@tienda.test',
        'PS_MAIL_PASSWD' => 'clave-de-mentira', 'PS_MAIL_SMTP_ENCRYPTION' => 'tls', 'PS_MAIL_SMTP_PORT' => '587', 'PS_LOGO' => 'logo-1700000000.png',
        'PS_FAVICON' => 'favicon.ico', 'PS_CONDITIONS_CMS_ID' => '3', 'PS_CATALOG_MODE' => '0', 'PS_HOME_CATEGORY' => '2', 'PS_ROOT_CATEGORY' => '1',
        'PS_SHIPPING_METHOD' => '1', 'PS_SHIPPING_HANDLING' => '2.000000', 'PS_SHIPPING_FREE_PRICE' => '60.000000', 'PS_SHIPPING_FREE_WEIGHT' => '0.000000',
        'PS_VERSION_DB' => $version, 'PS_SHOP_DEFAULT' => '1', 'PS_OS_BANKWIRE' => '1', 'PS_OS_PAYMENT' => '2', 'PS_OS_PREPARATION' => '3', 'PS_OS_SHIPPING' => '4',
        'PS_OS_DELIVERED' => '5', 'PS_OS_CANCELED' => '6', 'PS_OS_REFUND' => '7', 'PS_OS_ERROR' => '8',
        'BANK_WIRE_OWNER' => 'Ñandú Muebles S.L.', 'BANK_WIRE_DETAILS' => 'ES00 0000 0000 0000 0000 0000', 'PAYPAL_SANDBOX' => '1', 'PAYPAL_API_USER' => 'usuario-de-mentira',
        'PAYPAL_API_PASSWORD' => 'clave-de-mentira', 'PS_ROUTE_other' => 'no-se-lee',
    ];
    $id = 0;
    foreach ($config as $name => $value) {
        $i('configuration', ['id_configuration' => ++$id, 'id_shop_group' => null, 'id_shop' => null, 'name' => $name, 'value' => $value]);
    }
    $i('configuration', ['id_configuration' => ++$id, 'id_shop_group' => 1, 'id_shop' => 2, 'name' => 'PS_SHOP_NAME', 'value' => 'Segunda Tienda']);
    $prefix = ++$id;
    $i('configuration', ['id_configuration' => $prefix, 'id_shop_group' => null, 'id_shop' => null, 'name' => 'PS_INVOICE_PREFIX', 'value' => null]);
    $l('configuration_lang', ['id_configuration' => $prefix], ['value' => ['F2026-', 'F2026-']]);
    $credit = ++$id;
    $i('configuration', ['id_configuration' => $credit, 'id_shop_group' => null, 'id_shop' => null, 'name' => 'PS_CREDIT_SLIP_PREFIX', 'value' => null]);
    $l('configuration_lang', ['id_configuration' => $credit], ['value' => ['AB', 'CR']]);
    $i('meta', ['id_meta' => 1, 'page' => 'index', 'configurable' => 1]);
    $i('meta_lang', ['id_meta' => 1, 'id_shop' => 1, 'id_lang' => 1, 'title' => 'Muebles de madera maciza', 'description' => 'Mesas, sillas y estanterías hechas en Sevilla.']);

    // ---------------------------------------------------------------- países, zonas, impuestos
    foreach ([[1, 'Europa'], [2, 'Península y Baleares'], [3, 'Canarias']] as $zone) {
        $i('zone', ['id_zone' => $zone[0], 'name' => $zone[1], 'active' => 1]);
    }
    $i('zone', ['id_zone' => 4, 'name' => 'Zona vacía', 'active' => 1]);
    $i('zone', ['id_zone' => 5, 'name' => 'Zona apagada', 'active' => 0]);
    $i('country', ['id_country' => 1, 'id_zone' => 2, 'id_currency' => 1, 'iso_code' => 'ES', 'active' => 1, 'contains_states' => 1]);
    $i('country', ['id_country' => 2, 'id_zone' => 1, 'id_currency' => 1, 'iso_code' => 'PT', 'active' => 1, 'contains_states' => 0]);
    $i('country', ['id_country' => 3, 'id_zone' => 1, 'id_currency' => 1, 'iso_code' => 'FR', 'active' => 1, 'contains_states' => 0]);
    $i('country', ['id_country' => 4, 'id_zone' => 1, 'id_currency' => 1, 'iso_code' => 'IT', 'active' => 0, 'contains_states' => 0]);
    foreach ([[1, 'Madrid', 'M', 2], [2, 'Barcelona', 'B', 2], [3, 'Las Palmas', 'GC', 3], [4, 'Santa Cruz de Tenerife', 'TF', 3], [5, 'Ceuta', 'ES-CE', 2], [6, 'Melilla', 'ML', 2], [7, 'Sevilla', 'SE', 2]] as $state) {
        $i('state', ['id_state' => $state[0], 'id_country' => 1, 'id_zone' => $state[3], 'name' => $state[1], 'iso_code' => $state[2], 'active' => 1]);
    }
    foreach ([[1, '21.000', 0, 'IVA 21 %', 'VAT 21 %'], [2, '10.000', 0, 'IVA 10 %', 'VAT 10 %'], [3, '7.000', 0, 'IGIC 7 %', 'IGIC 7 %'], [4, '4.000', 0, 'IPSI 4 %', 'IPSI 4 %'], [5, '3.000', 1, 'Impuesto borrado', 'Deleted tax']] as $tax) {
        $i('tax', ['id_tax' => $tax[0], 'rate' => $tax[1], 'active' => 1, 'deleted' => $tax[2]]);
        $l('tax_lang', ['id_tax' => $tax[0]], ['name' => [$tax[3], $tax[4]]]);
    }
    foreach ([[1, 'ES IVA general', 1, 0], [2, 'ES IVA reducido', 1, 0], [3, 'Grupo borrado', 1, 1], [4, 'Grupo inactivo', 0, 0]] as $group) {
        $i('tax_rules_group', ['id_tax_rules_group' => $group[0], 'name' => $group[1], 'active' => $group[2], 'deleted' => $group[3]]);
        $i('tax_rules_group_shop', ['id_tax_rules_group' => $group[0], 'id_shop' => 1]);
    }
    foreach ([
        [1, 1, 1, 0, '0', '0', 1, 0], [2, 1, 1, 0, '35000', '35999', 3, 0], [3, 1, 1, 0, '38000', '38999', 3, 0], [4, 1, 1, 5, '0', '0', 4, 0], [5, 1, 1, 6, '0', '0', 4, 0],
        [6, 2, 1, 0, '0', '0', 2, 0], [7, 3, 1, 0, '0', '0', 1, 0], [8, 2, 3, 0, '0', '0', 2, 2], [9, 1, 2, 0, '0', '0', 1, 1], [10, 1, 1, 0, '0', '0', 5, 0],
    ] as $rule) {
        $i('tax_rule', ['id_tax_rule' => $rule[0], 'id_tax_rules_group' => $rule[1], 'id_country' => $rule[2], 'id_state' => $rule[3], 'zipcode_from' => $rule[4], 'zipcode_to' => $rule[5], 'id_tax' => $rule[6], 'behavior' => $rule[7], 'description' => '']);
    }

    // ---------------------------------------------------------------- transportistas
    $carrier = ['url' => '', 'active' => 1, 'deleted' => 0, 'shipping_handling' => 0, 'range_behavior' => 0, 'is_module' => 0, 'is_free' => 0, 'need_range' => 1, 'shipping_method' => 1, 'position' => 0, 'max_weight' => '0'];
    $i('carrier', ['id_carrier' => 1, 'id_reference' => 1, 'name' => 'Mensajería 24 h', 'deleted' => 1, 'shipping_method' => 1, 'max_weight' => '15.000000'] + $carrier);
    $i('carrier', ['id_carrier' => 2, 'id_reference' => 2, 'name' => 'Recogida en taller', 'is_free' => 1, 'shipping_method' => 3, 'position' => 1] + $carrier);
    $i('carrier', ['id_carrier' => 3, 'id_reference' => 1, 'name' => 'Mensajería 24 h', 'url' => 'https://envios.test/seguir/@', 'shipping_handling' => 1, 'max_weight' => '20.000000', 'id_tax_rules_group' => 0] + $carrier);
    $i('carrier', ['id_carrier' => 4, 'id_reference' => 4, 'name' => 'Transporte apagado', 'active' => 0] + $carrier);
    foreach ([1, 2, 3, 4] as $carrierId) {
        $i('carrier_shop', ['id_carrier' => $carrierId, 'id_shop' => 1]);
    }
    $i('carrier_tax_rules_group_shop', ['id_carrier' => 3, 'id_tax_rules_group' => 1, 'id_shop' => 1]);
    $i('carrier_lang', ['id_carrier' => 3, 'id_shop' => 1, 'id_lang' => 1, 'delay' => 'Entrega en 24-48 horas']);
    $i('carrier_lang', ['id_carrier' => 3, 'id_shop' => 1, 'id_lang' => 2, 'delay' => 'Delivery in 24-48 hours']);
    $i('carrier_lang', ['id_carrier' => 2, 'id_shop' => 1, 'id_lang' => 1, 'delay' => 'Recógelo cuando quieras']);
    foreach ([[3, 1], [3, 2], [3, 3], [2, 2], [1, 2]] as $cz) {
        $i('carrier_zone', ['id_carrier' => $cz[0], 'id_zone' => $cz[1]]);
    }
    $i('range_weight', ['id_range_weight' => 1, 'id_carrier' => 3, 'delimiter1' => '0.000000', 'delimiter2' => '5.000000']);
    $i('range_weight', ['id_range_weight' => 2, 'id_carrier' => 3, 'delimiter1' => '5.000000', 'delimiter2' => '10.000000']);
    $i('range_weight', ['id_range_weight' => 3, 'id_carrier' => 3, 'delimiter1' => '10.000000', 'delimiter2' => '20.000000']);
    $i('range_weight', ['id_range_weight' => 4, 'id_carrier' => 1, 'delimiter1' => '0.000000', 'delimiter2' => '15.000000']);
    $i('range_price', ['id_range_price' => 1, 'id_carrier' => 2, 'delimiter1' => '0.000000', 'delimiter2' => '1000.000000']);
    $prices = [1 => ['9.900000', '12.900000', '18.000000'], 2 => ['5.900000', '7.900000', '11.500000'], 3 => ['12.500000', '15.000000', '22.000000']];
    $deliveryId = 0;
    foreach ($prices as $zoneId => $list) {
        foreach ($list as $index => $price) {
            $i('delivery', ['id_delivery' => ++$deliveryId, 'id_shop' => null, 'id_shop_group' => null, 'id_carrier' => 3, 'id_range_price' => 0, 'id_range_weight' => $index + 1, 'id_zone' => $zoneId, 'price' => $price]);
        }
    }
    $i('delivery', ['id_delivery' => ++$deliveryId, 'id_carrier' => 1, 'id_range_price' => 0, 'id_range_weight' => 4, 'id_zone' => 2, 'price' => '6.000000']);
    $i('delivery', ['id_delivery' => ++$deliveryId, 'id_carrier' => 2, 'id_range_price' => 1, 'id_range_weight' => 0, 'id_zone' => 2, 'price' => '0.000000']);

    // ---------------------------------------------------------------- grupos, marcas, categorías, atributos, características
    foreach ([[1, '0.000000', 1, 'Visitante', 'Visitor'], [2, '0.000000', 1, 'Invitado', 'Guest'], [3, '0.000000', 0, 'Cliente', 'Customer'], [4, '10.000000', 1, 'Profesionales', 'Professionals']] as $group) {
        $i('group', ['id_group' => $group[0], 'reduction' => $group[1], 'price_display_method' => $group[2], 'show_prices' => 1]);
        $l('group_lang', ['id_group' => $group[0]], ['name' => [$group[3], $group[4]]]);
    }
    $i('manufacturer', ['id_manufacturer' => 1, 'name' => 'Nordika', 'date_add' => '2025-01-01 09:00:00', 'date_upd' => '2026-02-01 09:00:00', 'active' => 1]);
    $l('manufacturer_lang', ['id_manufacturer' => 1], ['description' => ['<p>Madera con certificado.</p>', '<p>Certified wood.</p>'], 'short_description' => ['Madera certificada', 'Certified wood'], 'meta_title' => ['Nordika muebles', 'Nordika furniture'], 'meta_description' => ['Todo Nordika', 'All Nordika']]);
    $i('manufacturer', ['id_manufacturer' => 2, 'name' => 'Ñandú Taller', 'active' => 0]);
    $l('manufacturer_lang', ['id_manufacturer' => 2], ['description' => ['', ''], 'short_description' => ['', ''], 'meta_title' => ['', ''], 'meta_description' => ['', '']]);
    foreach ([1, 2] as $manufacturerId) {
        $i('manufacturer_shop', ['id_manufacturer' => $manufacturerId, 'id_shop' => 1]);
    }
    $categories = [
        [1, 0, 'Raíz', 'Root', 'raiz', 'root', 0, 0], [2, 1, 'Inicio', 'Home', 'inicio', 'home', 1, 0], [3, 2, 'Muebles', 'Furniture', 'muebles', 'furniture', 0, 1],
        [4, 3, 'Mesas', 'Tables', 'mesas', 'tables', 0, 1], [5, 3, 'Sillas', 'Chairs', 'sillas', 'chairs', 0, 2],
    ];
    foreach ($categories as $c) {
        $i('category', ['id_category' => $c[0], 'id_parent' => $c[1], 'id_shop_default' => 1, 'active' => 1, 'date_add' => '2025-01-01 09:00:00', 'date_upd' => '2026-03-01 09:00:00', 'position' => $c[7], 'is_root_category' => $c[6]]);
        $i('category_shop', ['id_category' => $c[0], 'id_shop' => 1, 'position' => $c[7]]);
        $l('category_lang', ['id_category' => $c[0], 'id_shop' => 1], [
            'name' => [$c[2], $c[3]], 'description' => ['<p>' . $c[2] . ' de calidad.</p>', '<p>Quality ' . $c[3] . '.</p>'], 'link_rewrite' => [$c[4], $c[5]],
            'meta_title' => [$c[2] . ' de madera', $c[3] . ' of wood'], 'meta_description' => ['Descubre ' . $c[2], 'Discover ' . $c[3]],
        ]);
    }
    $i('attribute_group', ['id_attribute_group' => 1, 'is_color_group' => 0, 'group_type' => 'select', 'position' => 0]);
    $l('attribute_group_lang', ['id_attribute_group' => 1], ['name' => ['Tamaño', 'Size'], 'public_name' => ['Tamaño', 'Size']]);
    $i('attribute_group', ['id_attribute_group' => 2, 'is_color_group' => 1, 'group_type' => 'color', 'position' => 1]);
    $l('attribute_group_lang', ['id_attribute_group' => 2], ['name' => ['Color (interno)', 'Colour (internal)'], 'public_name' => ['Color', 'Colour']]);
    foreach ([[1, 1, '', 0, 'Pequeña', 'Small'], [2, 1, '', 1, 'Grande', 'Large'], [3, 2, '#C19A6B', 0, 'Roble', 'Oak'], [4, 2, '#5C4033', 1, 'Nogal', 'Walnut']] as $a) {
        $i('attribute', ['id_attribute' => $a[0], 'id_attribute_group' => $a[1], 'color' => $a[2], 'position' => $a[3]]);
        $l('attribute_lang', ['id_attribute' => $a[0]], ['name' => [$a[4], $a[5]]]);
    }
    $i('feature', ['id_feature' => 1, 'position' => 0]);
    $l('feature_lang', ['id_feature' => 1], ['name' => ['Material', 'Material']]);
    $i('feature', ['id_feature' => 2, 'position' => 1]);
    $l('feature_lang', ['id_feature' => 2], ['name' => ['Altura (cm)', 'Height (cm)']]);
    foreach ([[1, 1, 0, 'Madera', 'Wood', 0], [2, 1, 0, 'Acero', 'Steel', 1], [3, 2, 1, '48', '48', 0]] as $fv) {
        $i('feature_value', ['id_feature_value' => $fv[0], 'id_feature' => $fv[1], 'custom' => $fv[2], 'position' => $fv[5]]);
        $l('feature_value_lang', ['id_feature_value' => $fv[0]], ['value' => [$fv[3], $fv[4]]]);
    }

    // ---------------------------------------------------------------- productos
    $base = [
        'id_supplier' => 0, 'id_manufacturer' => 0, 'id_category_default' => 3, 'id_shop_default' => 1, 'id_tax_rules_group' => 1, 'on_sale' => 0, 'online_only' => 0, 'ean13' => '', 'isbn' => '', 'upc' => '',
        'mpn' => '', 'ecotax' => '0.000000', 'quantity' => 0, 'minimal_quantity' => 1, 'price' => '0.000000', 'wholesale_price' => '0.000000', 'unity' => '', 'unit_price' => '0.000000',
        'unit_price_ratio' => '0.000000', 'reference' => '', 'supplier_reference' => '', 'width' => '0', 'height' => '0', 'depth' => '0', 'weight' => '0', 'out_of_stock' => 2,
        'customizable' => 0, 'uploadable_files' => 0, 'text_fields' => 0, 'active' => 1, 'redirect_type' => '', 'id_type_redirected' => 0, 'available_for_order' => 1,
        'available_date' => '0000-00-00', 'show_condition' => 0, 'condition' => 'new', 'show_price' => 1, 'indexed' => 1, 'visibility' => 'both', 'is_virtual' => 0,
        'date_add' => '2025-02-11 10:00:00', 'date_upd' => '2026-10-01 08:00:00',
    ];
    $products = [
        1 => ['id_manufacturer' => 1, 'id_supplier' => 1, 'id_category_default' => 4, 'price' => '82.644628', 'wholesale_price' => '40.000000', 'reference' => 'MESA-ROBLE', 'ean13' => '8400000000017',
            'weight' => '6.500000', 'width' => '40.000000', 'height' => '48.000000', 'depth' => '40.000000', 'supplier_reference' => 'NRD-44'],
        2 => ['id_category_default' => 5, 'price' => '50.000000', 'wholesale_price' => '25.000000', 'reference' => 'SILLA-NORDICA', 'weight' => '4.000000', 'width' => '45', 'height' => '85', 'depth' => '50'],
        3 => ['id_category_default' => 3, 'id_tax_rules_group' => 2, 'price' => '9.917355', 'reference' => 'GUIA-PDF', 'is_virtual' => 1, 'show_price' => 1, 'out_of_stock' => 0],
        4 => ['id_category_default' => 5, 'price' => '120.000000', 'reference' => 'SILLON-4', 'weight' => '12.000000', 'condition' => 'refurbished', 'on_sale' => 1, 'ecotax' => '1.500000', 'unity' => 'ud', 'unit_price_ratio' => '2.000000'],
        5 => ['id_category_default' => 4, 'price' => '30.000000', 'reference' => 'MANTEL-5', 'customizable' => 1, 'text_fields' => 2, 'available_date' => '2026-12-01', 'out_of_stock' => 1],
        6 => ['id_category_default' => 3, 'price' => '10.000000', 'reference' => 'VIEJO-6', 'active' => 0, 'visibility' => 'none', 'redirect_type' => '301-product', 'id_type_redirected' => 1, 'available_for_order' => 0, 'show_price' => 0],
    ];
    $names = [
        1 => ['Mesa auxiliar de roble', 'Oak side table', 'mesa-auxiliar-de-roble', 'oak-side-table'], 2 => ['Silla nórdica', 'Nordic chair', 'silla-nordica', 'nordic-chair'],
        3 => ['Guía de cuidado (PDF)', 'Care guide (PDF)', 'guia-de-cuidado', 'care-guide'], 4 => ['Sillón "Cuatro"', 'Armchair Four', 'sillon-cuatro', 'armchair-four'],
        5 => ['Mantel de lino', 'Linen tablecloth', 'mantel-de-lino', 'linen-tablecloth'], 6 => ['Producto retirado', 'Withdrawn product', 'producto-retirado', 'withdrawn-product'],
    ];
    foreach ($products as $productId => $values) {
        $row = ['id_product' => $productId] + $values + $base;
        $i('product', $row);
        $i('product_shop', $row + ['id_shop' => 1]);
        $l('product_lang', ['id_product' => $productId, 'id_shop' => 1], [
            'name' => [$names[$productId][0], $names[$productId][1]], 'link_rewrite' => [$names[$productId][2], $names[$productId][3]],
            'description' => ['<p>Descripción de ' . $names[$productId][0] . ' & más.</p>', '<p>Description of ' . $names[$productId][1] . ' &amp; more.</p>'],
            'description_short' => ['<p>Resumen ' . $productId . '</p>', '<p>Summary ' . $productId . '</p>'], 'meta_title' => [$names[$productId][0] . ' | Ñandú', $names[$productId][1] . ' | Nandu'],
            'meta_description' => ['Compra ' . $names[$productId][0], 'Buy ' . $names[$productId][1]],
        ]);
    }
    foreach ([1 => [3, 4], 2 => [3, 5], 3 => [3], 4 => [5], 5 => [4], 6 => [3]] as $productId => $cats) {
        foreach ($cats as $cat) {
            $i('category_product', ['id_category' => $cat, 'id_product' => $productId, 'position' => 0]);
        }
    }
    // segunda tienda: solo los productos 1 y 2, el 1 más caro y con otro nombre
    foreach ([1 => '90.000000', 2 => '55.000000'] as $productId => $price) {
        $i('product_shop', ['id_product' => $productId, 'id_shop' => 2, 'price' => $price, 'active' => 1, 'id_tax_rules_group' => 1, 'id_category_default' => $products[$productId]['id_category_default'], 'visibility' => 'both', 'minimal_quantity' => 1,
            'available_for_order' => 1, 'show_price' => 1, 'condition' => 'new', 'date_add' => '2025-02-11 10:00:00', 'date_upd' => '2026-10-01 08:00:00']);
        $l('product_lang', ['id_product' => $productId, 'id_shop' => 2], ['name' => [$names[$productId][0] . ' (tienda 2)', $names[$productId][1] . ' (shop 2)'], 'link_rewrite' => [$names[$productId][2] . '-t2', $names[$productId][3] . '-t2'],
            'description' => ['<p>Tienda 2</p>', '<p>Shop 2</p>'], 'description_short' => ['', ''], 'meta_title' => ['', ''], 'meta_description' => ['', '']]);
    }
    foreach ([[1, 1, 1, 1], [2, 1, 2, null], [3, 2, 1, 1], [4, 2, 2, null], [5, 2, 3, null], [6, 3, 1, 1], [7, 4, 1, 1]] as $img) {
        $i('image', ['id_image' => $img[0], 'id_product' => $img[1], 'position' => $img[2], 'cover' => $img[3]]);
        $i('image_shop', ['id_product' => $img[1], 'id_image' => $img[0], 'id_shop' => 1, 'cover' => $img[3]]);
    }
    // existencias
    $stock = [[1, 0, 14], [2, 0, 25], [3, 0, 999], [4, 0, 6], [5, 0, 0], [6, 0, 2], [2, 1, 10], [2, 2, 5], [2, 3, 7], [2, 4, 3]];
    foreach ($stock as $n => $s) {
        $i('stock_available', ['id_stock_available' => $n + 1, 'id_product' => $s[0], 'id_product_attribute' => $s[1], 'id_shop' => 1, 'id_shop_group' => 0, 'quantity' => $s[2], 'out_of_stock' => $products[$s[0]]['out_of_stock'] ?? 2]);
    }
    $i('stock_available', ['id_stock_available' => 20, 'id_product' => 1, 'id_product_attribute' => 0, 'id_shop' => 2, 'id_shop_group' => 0, 'quantity' => 3, 'out_of_stock' => 2]);
    // combinaciones de la silla: dos atributos, cuatro combinaciones
    $combinations = [
        [1, 'SILLA-P-ROB', '0.000000', '0.000000', 1, [1, 3], [3]], [2, 'SILLA-P-NOG', '12.396694', '0.500000', 0, [1, 4], [4]],
        [3, 'SILLA-G-ROB', '10.000000', '1.000000', 0, [2, 3], [5]], [4, 'SILLA-G-NOG', '22.396694', '1.500000', 0, [2, 4], []],
    ];
    foreach ($combinations as $c) {
        $row = ['id_product_attribute' => $c[0], 'id_product' => 2, 'reference' => $c[1], 'supplier_reference' => '', 'ean13' => '', 'isbn' => '', 'upc' => '', 'mpn' => '', 'wholesale_price' => '0.000000',
            'price' => $c[2], 'ecotax' => '0', 'weight' => $c[3], 'unit_price_impact' => '0', 'default_on' => $c[4], 'minimal_quantity' => 1, 'available_date' => '0000-00-00'];
        $i('product_attribute', $row);
        $i('product_attribute_shop', $row + ['id_shop' => 1]);
        foreach ($c[5] as $attribute) {
            $i('product_attribute_combination', ['id_attribute' => $attribute, 'id_product_attribute' => $c[0]]);
        }
        foreach ($c[6] as $image) {
            $i('product_attribute_image', ['id_product_attribute' => $c[0], 'id_image' => $image]);
        }
    }
    // características, etiquetas, relacionados, adjuntos, descarga, proveedores, pack
    $i('feature_product', ['id_feature' => 1, 'id_product' => 1, 'id_feature_value' => 1]);
    $i('feature_product', ['id_feature' => 2, 'id_product' => 1, 'id_feature_value' => 3]);
    $i('feature_product', ['id_feature' => 1, 'id_product' => 2, 'id_feature_value' => 2]);
    $i('tag', ['id_tag' => 1, 'id_lang' => 1, 'name' => 'roble']);
    $i('tag', ['id_tag' => 2, 'id_lang' => 2, 'name' => 'oak']);
    $i('product_tag', ['id_product' => 1, 'id_tag' => 1, 'id_lang' => 1]);
    $i('product_tag', ['id_product' => 1, 'id_tag' => 2, 'id_lang' => 2]);
    $i('accessory', ['id_product_1' => 1, 'id_product_2' => 2]);
    $i('attachment', ['id_attachment' => 1, 'file' => 'a1b2c3d4', 'file_name' => 'manual-mesa.pdf', 'file_size' => 20480, 'mime' => 'application/pdf']);
    $l('attachment_lang', ['id_attachment' => 1], ['name' => ['Manual de montaje', 'Assembly manual'], 'description' => ['', '']]);
    $i('product_attachment', ['id_product' => 1, 'id_attachment' => 1]);
    $i('product_download', ['id_product_download' => 1, 'id_product' => 3, 'display_filename' => 'guia-de-cuidado.pdf', 'filename' => 'dl9f8e7d6c', 'nb_days_accessible' => 30, 'nb_downloadable' => 3, 'active' => 1, 'is_shareable' => 0]);
    $i('supplier', ['id_supplier' => 1, 'name' => 'Nordika Proveedor', 'active' => 1]);
    $i('product_supplier', ['id_product_supplier' => 1, 'id_product' => 1, 'id_product_attribute' => 0, 'id_supplier' => 1, 'product_supplier_reference' => 'NRD-44', 'product_supplier_price_te' => '40.000000', 'id_currency' => 1]);
    $i('pack', ['id_product_pack' => 1, 'id_product_item' => 2, 'id_product_attribute_item' => 0, 'quantity' => 2]);
    // precios específicos
    $sp = ['id_specific_price_rule' => 0, 'id_cart' => 0, 'id_shop' => 0, 'id_shop_group' => 0, 'id_currency' => 0, 'id_country' => 0, 'id_group' => 0, 'id_customer' => 0, 'id_product_attribute' => 0, 'price' => '-1.000000',
        'from_quantity' => 1, 'reduction' => '0.000000', 'reduction_tax' => 1, 'reduction_type' => 'percentage', 'from' => '0000-00-00 00:00:00', 'to' => '0000-00-00 00:00:00'];
    $i('specific_price', ['id_specific_price' => 1, 'id_product' => 4, 'reduction' => '0.200000', 'from' => '2026-01-01 00:00:00'] + $sp);
    $i('specific_price', ['id_specific_price' => 2, 'id_product' => 4, 'id_group' => 4, 'reduction' => '0.300000'] + $sp);
    $i('specific_price', ['id_specific_price' => 3, 'id_product' => 4, 'reduction' => '5.000000', 'reduction_tax' => 0, 'reduction_type' => 'amount', 'to' => '2025-12-31 23:59:59'] + $sp);
    $i('specific_price', ['id_specific_price' => 4, 'id_product' => 5, 'from_quantity' => 5, 'reduction' => '0.050000'] + $sp);

    // ---------------------------------------------------------------- clientes y direcciones
    $customer = ['id_shop_group' => 1, 'id_shop' => 1, 'id_gender' => 0, 'id_default_group' => 3, 'id_lang' => 1, 'company' => '', 'siret' => '', 'ape' => '', 'birthday' => '0000-00-00', 'newsletter' => 0, 'newsletter_date_add' => null,
        'optin' => 0, 'website' => '', 'note' => '', 'active' => 1, 'is_guest' => 0, 'deleted' => 0, 'date_add' => '2024-05-02 18:20:00', 'date_upd' => '2026-09-01 10:00:00'];
    $i('customer', ['id_customer' => 1, 'firstname' => 'Ana', 'lastname' => 'Ruiz Peña', 'email' => 'ana@cliente.test', 'passwd' => '$2y$10$abcdefghijklmnopqrstuuJ9Qe1h0sO9dJ3m1uQq7hYw9vJmVvF0S', 'id_gender' => 2, 'birthday' => '1990-04-12',
        'newsletter' => 1, 'newsletter_date_add' => '2024-05-02 18:20:00', 'optin' => 1, 'note' => 'Cliente de confianza'] + $customer);
    $i('customer', ['id_customer' => 2, 'firstname' => 'Luis', 'lastname' => 'Gómez', 'email' => 'luis@cliente.test', 'passwd' => '0123456789abcdef0123456789abcdef'] + $customer);
    $i('customer', ['id_customer' => 3, 'firstname' => 'Invitado', 'lastname' => 'Prueba', 'email' => 'invitado@cliente.test', 'passwd' => '0123456789abcdef0123456789abcdee', 'is_guest' => 1, 'id_default_group' => 2] + $customer);
    $i('customer', ['id_customer' => 4, 'id_shop' => 2, 'firstname' => 'Marta', 'lastname' => 'Gil', 'email' => 'marta@cliente.test', 'passwd' => '$2y$10$abcdefghijklmnopqrstuuJ9Qe1h0sO9dJ3m1uQq7hYw9vJmVvF0S'] + $customer);
    $i('customer', ['id_customer' => 5, 'firstname' => 'Borrado', 'lastname' => 'Antiguo', 'email' => 'borrado@cliente.test', 'passwd' => '', 'deleted' => 1] + $customer);
    foreach ([[1, 3], [1, 4], [2, 3], [3, 2], [4, 3]] as $cg) {
        $i('customer_group', ['id_customer' => $cg[0], 'id_group' => $cg[1]]);
    }
    $address = ['id_manufacturer' => 0, 'id_supplier' => 0, 'id_warehouse' => 0, 'company' => '', 'address2' => '', 'other' => '', 'phone' => '', 'phone_mobile' => '', 'vat_number' => '', 'dni' => '', 'active' => 1, 'deleted' => 0,
        'date_add' => '2024-05-02 18:20:00', 'date_upd' => '2024-05-02 18:20:00'];
    $i('address', ['id_address' => 1, 'id_country' => 1, 'id_state' => 1, 'id_customer' => 1, 'alias' => 'Casa', 'lastname' => 'Ruiz Peña', 'firstname' => 'Ana', 'address1' => 'Calle Mayor 1', 'address2' => '2.º B',
        'postcode' => '28013', 'city' => 'Madrid', 'phone' => '999500008', 'phone_mobile' => '999000001', 'vat_number' => '12345678Z'] + $address);
    $i('address', ['id_address' => 2, 'id_country' => 1, 'id_state' => 2, 'id_customer' => 1, 'alias' => 'Antigua', 'lastname' => 'Ruiz Peña', 'firstname' => 'Ana', 'address1' => 'Calle Mayor 9', 'postcode' => '08001', 'city' => 'Barcelona', 'deleted' => 1] + $address);
    $i('address', ['id_address' => 3, 'id_country' => 1, 'id_state' => 3, 'id_customer' => 1, 'alias' => 'Oficina', 'lastname' => 'Ruiz Peña', 'firstname' => 'Ana', 'company' => 'Ruiz y Cía', 'address1' => 'Calle Mayor 3', 'postcode' => '35001', 'city' => 'Las Palmas', 'phone' => '999500015'] + $address);
    $i('address', ['id_address' => 4, 'id_country' => 2, 'id_state' => 0, 'id_customer' => 2, 'alias' => 'Mi dirección', 'lastname' => 'Gómez', 'firstname' => 'Luis', 'address1' => 'Rua Mayor 3', 'postcode' => '1000-001', 'city' => 'Lisboa', 'phone_mobile' => '999000002'] + $address);
    $i('address', ['id_address' => 5, 'id_country' => 1, 'id_state' => 7, 'id_customer' => 3, 'alias' => 'Envío', 'lastname' => 'Prueba', 'firstname' => 'Invitado', 'address1' => 'Calle Mayor 2', 'postcode' => '41004', 'city' => 'Sevilla'] + $address);
    $i('emailsubscription', ['id' => 1, 'id_shop' => 1, 'id_shop_group' => 1, 'email' => 'suscrito1@cliente.test', 'newsletter_date_add' => '2025-06-01 14:00:00', 'http_referer' => 'footer', 'active' => 1, 'id_lang' => 1]);
    $i('emailsubscription', ['id' => 2, 'id_shop' => 1, 'id_shop_group' => 1, 'email' => 'suscrito2@cliente.test', 'newsletter_date_add' => '2025-07-01 14:00:00', 'http_referer' => '', 'active' => 1, 'id_lang' => 2]);

    // ---------------------------------------------------------------- reglas de carrito
    $rule = ['id_customer' => 0, 'date_from' => '0000-00-00 00:00:00', 'date_to' => '0000-00-00 00:00:00', 'description' => '', 'quantity' => 1, 'quantity_per_user' => 1, 'priority' => 1, 'partial_use' => 1, 'code' => '',
        'minimum_amount' => '0.000000', 'minimum_amount_tax' => 0, 'minimum_amount_currency' => 1, 'minimum_amount_shipping' => 0, 'country_restriction' => 0, 'carrier_restriction' => 0, 'group_restriction' => 0,
        'cart_rule_restriction' => 0, 'product_restriction' => 0, 'shop_restriction' => 0, 'free_shipping' => 0, 'reduction_percent' => '0.00', 'reduction_amount' => '0.000000', 'reduction_tax' => 0, 'reduction_currency' => 1,
        'reduction_product' => 0, 'reduction_exclude_special' => 0, 'gift_product' => 0, 'gift_product_attribute' => 0, 'highlight' => 0, 'active' => 1, 'date_add' => '2026-09-01 10:00:00', 'date_upd' => '2026-09-01 10:00:00'];
    $i('cart_rule', ['id_cart_rule' => 1, 'code' => 'OTOÑO10', 'date_from' => '2026-09-21 00:00:00', 'date_to' => '2026-12-21 00:00:00', 'quantity' => 199, 'minimum_amount' => '50.000000', 'reduction_percent' => '10.00',
        'reduction_exclude_special' => 1, 'product_restriction' => 1, 'description' => 'Campaña de otoño'] + $rule);
    $l('cart_rule_lang', ['id_cart_rule' => 1], ['name' => ['Otoño 10 %', 'Autumn 10 %']]);
    $i('cart_rule_product_rule_group', ['id_product_rule_group' => 1, 'id_cart_rule' => 1, 'quantity' => 1]);
    $i('cart_rule_product_rule', ['id_product_rule' => 1, 'id_product_rule_group' => 1, 'type' => 'categories']);
    $i('cart_rule_product_rule_value', ['id_product_rule' => 1, 'id_item' => 4]);
    $i('cart_rule', ['id_cart_rule' => 2, 'code' => 'ENVIOGRATIS', 'free_shipping' => 1, 'minimum_amount' => '80.000000', 'carrier_restriction' => 1, 'quantity' => 10, 'quantity_per_user' => 0] + $rule);
    $l('cart_rule_lang', ['id_cart_rule' => 2], ['name' => ['Envío gratis', 'Free shipping']]);
    $i('cart_rule_carrier', ['id_cart_rule' => 2, 'id_carrier' => 3]);
    $i('cart_rule', ['id_cart_rule' => 3, 'code' => '', 'reduction_amount' => '5.000000', 'reduction_tax' => 1, 'group_restriction' => 1, 'cart_rule_restriction' => 1, 'gift_product' => 2] + $rule);
    $l('cart_rule_lang', ['id_cart_rule' => 3], ['name' => ['Descuento automático', 'Automatic discount']]);
    $i('cart_rule_group', ['id_cart_rule' => 3, 'id_group' => 4]);
    $i('cart_rule_combination', ['id_cart_rule_1' => 3, 'id_cart_rule_2' => 1]);
    $i('cart_rule_shop', ['id_cart_rule' => 1, 'id_shop' => 1]);

    // ---------------------------------------------------------------- estados y pedidos
    foreach ([
        [1, 'En espera de transferencia', 'Awaiting bank wire', 0, 0, 0, 0, 0], [2, 'Pago aceptado', 'Payment accepted', 1, 1, 0, 0, 1], [3, 'Preparación en curso', 'Processing', 1, 1, 0, 0, 1],
        [4, 'Enviado', 'Shipped', 1, 1, 1, 0, 1], [5, 'Entregado', 'Delivered', 1, 1, 1, 1, 1], [6, 'Cancelado', 'Canceled', 0, 0, 0, 0, 0], [7, 'Reembolsado', 'Refunded', 0, 0, 0, 0, 0],
        [8, 'Error en el pago', 'Payment error', 0, 0, 0, 0, 0],
    ] as $state) {
        $i('order_state', ['id_order_state' => $state[0], 'logable' => $state[4], 'paid' => $state[3], 'shipped' => $state[5], 'delivery' => $state[6], 'invoice' => $state[7], 'send_email' => 1, 'hidden' => 0, 'deleted' => 0]);
        $l('order_state_lang', ['id_order_state' => $state[0]], ['name' => [$state[1], $state[2]], 'template' => ['', '']]);
    }
    $order = ['id_shop_group' => 1, 'id_shop' => 1, 'id_lang' => 1, 'id_currency' => 1, 'conversion_rate' => '1.000000', 'recyclable' => 0, 'gift' => 0, 'gift_message' => '', 'total_discounts' => '0.000000',
        'total_discounts_tax_incl' => '0.000000', 'total_discounts_tax_excl' => '0.000000', 'total_shipping' => '0.000000', 'total_shipping_tax_incl' => '0.000000', 'total_shipping_tax_excl' => '0.000000',
        'carrier_tax_rate' => '21.000', 'total_wrapping' => '0.000000', 'total_wrapping_tax_incl' => '0.000000', 'total_wrapping_tax_excl' => '0.000000', 'round_mode' => 2, 'round_type' => 1,
        'invoice_number' => 0, 'delivery_number' => 0, 'valid' => 1, 'note' => '', 'module' => 'ps_wirepayment', 'payment' => 'Transferencia bancaria'];
    $i('orders', ['id_order' => 1, 'reference' => 'AAAAAAAAA', 'id_carrier' => 1, 'id_customer' => 1, 'id_cart' => 1, 'id_address_delivery' => 1, 'id_address_invoice' => 1, 'current_state' => 4,
        'total_paid' => '256.910000', 'total_paid_tax_incl' => '256.910000', 'total_paid_tax_excl' => '212.320000', 'total_paid_real' => '256.910000', 'total_products' => '207.440000', 'total_products_wt' => '251.010000',
        'total_shipping' => '5.900000', 'total_shipping_tax_incl' => '5.900000', 'total_shipping_tax_excl' => '4.880000', 'invoice_number' => 1, 'date_add' => '2026-10-01 12:15:00', 'date_upd' => '2026-10-03 11:00:00'] + $order);
    $i('orders', ['id_order' => 2, 'reference' => 'BBBBBBBBB', 'id_carrier' => 3, 'id_customer' => 2, 'id_cart' => 2, 'id_address_delivery' => 4, 'id_address_invoice' => 4, 'current_state' => 2, 'module' => 'redsys', 'payment' => 'Tarjeta',
        'total_discounts' => '9.600000', 'total_discounts_tax_incl' => '9.600000', 'total_discounts_tax_excl' => '7.930000',
        'total_paid' => '92.300000', 'total_paid_tax_incl' => '92.300000', 'total_paid_tax_excl' => '76.290000', 'total_paid_real' => '92.300000', 'total_products' => '79.340000', 'total_products_wt' => '96.000000',
        'total_shipping' => '5.900000', 'total_shipping_tax_incl' => '5.900000', 'total_shipping_tax_excl' => '4.880000', 'date_add' => '2026-10-05 09:00:00', 'date_upd' => '2026-10-05 09:05:00'] + $order);
    $i('orders', ['id_order' => 3, 'reference' => 'CCCCCCCCC', 'id_carrier' => 3, 'id_customer' => 1, 'id_cart' => 3, 'id_address_delivery' => 1, 'id_address_invoice' => 3, 'current_state' => 5,
        'total_paid' => '116.810000', 'total_paid_tax_incl' => '116.810000', 'total_paid_tax_excl' => '97.440000', 'total_paid_real' => '116.810000', 'total_products' => '92.560000', 'total_products_wt' => '110.910000',
        'total_shipping' => '5.900000', 'total_shipping_tax_incl' => '5.900000', 'total_shipping_tax_excl' => '4.880000', 'invoice_number' => 2, 'date_add' => '2026-10-08 18:30:00', 'date_upd' => '2026-10-12 10:00:00'] + $order);
    $i('orders', ['id_order' => 4, 'reference' => 'DDDDDDDDD', 'id_carrier' => 2, 'id_customer' => 3, 'id_cart' => 4, 'id_address_delivery' => 5, 'id_address_invoice' => 5, 'current_state' => 6, 'valid' => 0, 'module' => 'ps_checkpayment', 'payment' => 'Cheque',
        'total_paid' => '75.500000', 'total_paid_tax_incl' => '75.500000', 'total_paid_tax_excl' => '62.400000', 'total_paid_real' => '0.000000', 'total_products' => '62.400000', 'total_products_wt' => '75.500000',
        'date_add' => '2026-10-09 08:00:00', 'date_upd' => '2026-10-10 08:00:00'] + $order);
    $detail = ['id_order_invoice' => 0, 'id_warehouse' => 0, 'id_shop' => 1, 'id_customization' => 0, 'product_quantity_refunded' => 0, 'product_quantity_reinjected' => 0, 'reduction_percent' => '0.00', 'reduction_amount' => '0.000000',
        'product_ean13' => '', 'product_weight' => '0.000000', 'tax_computation_method' => 0, 'tax_name' => '', 'tax_rate' => '0.000', 'download_hash' => '', 'total_refunded_tax_excl' => '0.000000', 'total_refunded_tax_incl' => '0.000000'];
    $details = [
        [1, 1, 1, 0, 'Mesa auxiliar de roble', 1, 'MESA-ROBLE', '82.640000', '100.000000', '82.640000', '100.000000', '6.500000', 1, '21.000', 'IVA 21 %'],
        [2, 1, 2, 2, 'Silla nórdica - Tamaño: Pequeña - Color: Nogal', 2, 'SILLA-P-NOG', '124.800000', '151.010000', '62.400000', '75.505000', '4.500000', 1, '21.000', 'IVA 21 %'],
        [3, 2, 4, 0, 'Sillón "Cuatro"', 1, 'SILLON-4', '79.340000', '96.000000', '79.340000', '96.000000', '12.000000', 1, '21.000', 'IVA 21 %'],
        [4, 3, 1, 0, 'Mesa auxiliar de roble', 1, 'MESA-ROBLE', '82.640000', '100.000000', '82.640000', '100.000000', '6.500000', 1, '21.000', 'IVA 21 %'],
        [5, 3, 3, 0, 'Guía de cuidado (PDF)', 1, 'GUIA-PDF', '9.920000', '10.910000', '9.920000', '10.910000', '0.000000', 2, '10.000', 'IVA 10 %'],
        [6, 4, 2, 1, 'Silla nórdica - Tamaño: Pequeña - Color: Roble', 1, 'SILLA-P-ROB', '62.400000', '75.500000', '62.400000', '75.500000', '4.000000', 1, '21.000', 'IVA 21 %'],
    ];
    foreach ($details as $d) {
        $row = ['id_order_detail' => $d[0], 'id_order' => $d[1], 'product_id' => $d[2], 'product_attribute_id' => $d[3], 'product_name' => $d[4], 'product_quantity' => $d[5], 'product_reference' => $d[6],
            'total_price_tax_excl' => $d[7], 'total_price_tax_incl' => $d[8], 'unit_price_tax_excl' => $d[9], 'unit_price_tax_incl' => $d[10], 'product_weight' => $d[11], 'id_tax_rules_group' => $d[12], 'tax_rate' => $d[13],
            'tax_name' => $d[14]] + $detail;
        if ($d[0] === 5) {
            $row['download_hash'] = 'hash-de-descarga';
            $row['product_quantity_refunded'] = 1;
            $row['product_quantity_reinjected'] = 1;
            $row['total_refunded_tax_excl'] = '9.920000';
            $row['total_refunded_tax_incl'] = '10.910000';
        }
        $i('order_detail', $row);
        $i('order_detail_tax', ['id_order_detail' => $d[0], 'id_tax' => $d[12] === 2 ? 2 : 1, 'unit_amount' => '0', 'total_amount' => '0']);
    }
    foreach ([[1, 1, 1, '2026-10-01 12:15:00'], [2, 1, 2, '2026-10-01 12:16:00'], [3, 1, 3, '2026-10-01 14:00:00'], [4, 1, 4, '2026-10-02 10:00:00'], [5, 2, 2, '2026-10-05 09:05:00'],
        [6, 3, 2, '2026-10-08 18:31:00'], [7, 3, 4, '2026-10-09 10:00:00'], [8, 3, 5, '2026-10-12 10:00:00'], [9, 4, 1, '2026-10-09 08:00:00'], [10, 4, 6, '2026-10-10 08:00:00']] as $h) {
        $i('order_history', ['id_order_history' => $h[0], 'id_employee' => $h[2] === 1 ? 0 : 1, 'id_order' => $h[1], 'id_order_state' => $h[2], 'date_add' => $h[3]]);
    }
    $i('order_payment', ['id_order_payment' => 1, 'order_reference' => 'AAAAAAAAA', 'id_currency' => 1, 'amount' => '256.910000', 'payment_method' => 'Transferencia bancaria', 'conversion_rate' => '1.000000', 'transaction_id' => 'TR-1', 'date_add' => '2026-10-01 12:16:00']);
    $i('order_payment', ['id_order_payment' => 2, 'order_reference' => 'BBBBBBBBB', 'id_currency' => 1, 'amount' => '92.300000', 'payment_method' => 'Tarjeta', 'conversion_rate' => '1.000000', 'transaction_id' => '000001042', 'date_add' => '2026-10-05 09:05:00']);
    $i('order_payment', ['id_order_payment' => 3, 'order_reference' => 'CCCCCCCCC', 'id_currency' => 1, 'amount' => '116.810000', 'payment_method' => 'Transferencia bancaria', 'conversion_rate' => '1.000000', 'transaction_id' => 'TR-3', 'date_add' => '2026-10-08 18:31:00']);
    $i('order_invoice', ['id_order_invoice' => 1, 'id_order' => 1, 'number' => 1, 'date_add' => '2026-10-01 12:16:00']);
    $i('order_invoice', ['id_order_invoice' => 2, 'id_order' => 3, 'number' => 2, 'date_add' => '2026-10-08 18:31:00']);
    $i('order_slip', ['id_order_slip' => 1, 'conversion_rate' => '1.000000', 'id_customer' => 1, 'id_order' => 3, 'total_products_tax_excl' => '9.920000', 'total_products_tax_incl' => '10.910000', 'total_shipping_tax_excl' => '0.000000',
        'total_shipping_tax_incl' => '0.000000', 'amount' => '10.910000', 'shipping_cost' => 0, 'shipping_cost_amount' => '0.000000', 'partial' => 1, 'order_slip_type' => 0, 'date_add' => '2026-10-13 09:00:00']);
    $i('order_slip_detail', ['id_order_slip' => 1, 'id_order_detail' => 5, 'product_quantity' => 1, 'unit_price_tax_excl' => '9.920000', 'unit_price_tax_incl' => '10.910000', 'total_price_tax_excl' => '9.920000', 'total_price_tax_incl' => '10.910000', 'amount_tax_excl' => '9.920000', 'amount_tax_incl' => '10.910000']);
    $i('order_carrier', ['id_order_carrier' => 1, 'id_order' => 1, 'id_carrier' => 1, 'tracking_number' => 'TRK123', 'date_add' => '2026-10-02 10:00:00']);
    $i('order_carrier', ['id_order_carrier' => 2, 'id_order' => 3, 'id_carrier' => 3, 'tracking_number' => '', 'date_add' => '2026-10-09 10:00:00']);
    $i('order_cart_rule', ['id_order_cart_rule' => 1, 'id_order' => 2, 'id_cart_rule' => 1, 'name' => 'OTOÑO10', 'value' => '9.600000', 'value_tax_excl' => '7.930000', 'free_shipping' => 0, 'deleted' => 0]);
    foreach ([1 => 1, 2 => 2, 3 => 1] as $cartId => $customerId) {
        $i('cart', ['id_cart' => $cartId, 'id_shop' => 1, 'id_customer' => $customerId, 'id_currency' => 1, 'id_lang' => 1]);
    }
    $i('cart', ['id_cart' => 10, 'id_shop' => 1, 'id_customer' => 1, 'id_currency' => 1, 'id_lang' => 1]);
    $i('cart', ['id_cart' => 11, 'id_shop' => 1, 'id_customer' => 2, 'id_currency' => 1, 'id_lang' => 1]);

    // ---------------------------------------------------------------- contenidos, equipo, módulos, opiniones
    foreach ([[1, 1, 'Entrega y envíos', 'Delivery and shipping', 'entrega-y-envios', 'delivery-and-shipping', 1], [2, 1, 'Aviso legal', 'Legal notice', 'aviso-legal', 'legal-notice', 0], [3, 2, 'Términos y condiciones', 'Terms and conditions', 'terminos-y-condiciones', 'terms-and-conditions', 1]] as $page) {
        $i('cms', ['id_cms' => $page[0], 'id_cms_category' => $page[1], 'position' => $page[0] - 1, 'active' => 1, 'indexation' => $page[6]]);
        $i('cms_shop', ['id_cms' => $page[0], 'id_shop' => 1]);
        $l('cms_lang', ['id_cms' => $page[0], 'id_shop' => 1], ['meta_title' => [$page[2], $page[3]], 'head_seo_title' => [$page[0] === 1 ? 'Envíos | Ñandú' : '', ''], 'meta_description' => ['Texto de ' . $page[2], 'Text of ' . $page[3]],
            'content' => ['<h2>' . $page[2] . '</h2><p>Ñandú Muebles S.L.</p>', '<h2>' . $page[3] . '</h2><p>Nandu</p>'], 'link_rewrite' => [$page[4], $page[5]]]);
    }
    $i('employee', ['id_employee' => 1, 'id_profile' => 1, 'id_lang' => 1, 'lastname' => 'Gil', 'firstname' => 'Marta', 'email' => 'marta@tienda.test', 'passwd' => 'no-se-lee-nunca', 'active' => 1, 'last_connection_date' => '2026-10-30']);
    $i('employee', ['id_employee' => 2, 'id_profile' => 2, 'id_lang' => 2, 'lastname' => 'Soto', 'firstname' => 'Raúl', 'email' => 'raul@tienda.test', 'passwd' => 'no-se-lee-nunca', 'active' => 0, 'last_connection_date' => '0000-00-00']);
    $i('profile', ['id_profile' => 1]);
    $i('profile', ['id_profile' => 2]);
    $l('profile_lang', ['id_profile' => 1], ['name' => ['SuperAdmin', 'SuperAdmin']]);
    $l('profile_lang', ['id_profile' => 2], ['name' => ['Logística', 'Logistician']]);
    foreach ([[1, 'ps_wirepayment', 1], [2, 'ps_checkpayment', 0], [3, 'paypal', 1], [4, 'ps_emailsubscription', 1], [5, 'ps_banner', 1], [6, 'ecomextra', 1], [7, 'otromodulo', 0], [8, 'productcomments', 1], [9, 'statsdata', 1]] as $module) {
        $i('module', ['id_module' => $module[0], 'name' => $module[1], 'active' => $module[2], 'version' => '1.0.0']);
        if (!in_array($module[0], [2, 7], true)) {
            $i('module_shop', ['id_module' => $module[0], 'id_shop' => 1, 'enable_device' => 7]);
        }
    }
    $i('product_comment', ['id_product_comment' => 1, 'id_product' => 1, 'id_customer' => 1, 'id_guest' => 0, 'title' => 'Muy bien acabada', 'content' => 'Llegó en dos días y sin un golpe.', 'customer_name' => 'Ana R.', 'grade' => '5', 'validate' => 1, 'deleted' => 0, 'date_add' => '2026-10-10 20:00:00']);
    $i('product_comment', ['id_product_comment' => 2, 'id_product' => 2, 'id_customer' => 0, 'id_guest' => 4, 'title' => 'Regular', 'content' => 'Esperaba más.', 'customer_name' => 'Visitante', 'grade' => '3', 'validate' => 0, 'deleted' => 0, 'date_add' => '2026-10-11 20:00:00']);
    $i('product_comment', ['id_product_comment' => 3, 'id_product' => 2, 'id_customer' => 0, 'id_guest' => 5, 'title' => 'Borrada', 'content' => 'x', 'customer_name' => 'x', 'grade' => '1', 'validate' => 1, 'deleted' => 1, 'date_add' => '2026-10-12 20:00:00']);
    $i('customer_message', ['id_customer_message' => 1, 'id_customer_thread' => 1, 'message' => 'Hola', 'date_add' => '2026-10-01 10:00:00']);
    $i('customer_message', ['id_customer_message' => 2, 'id_customer_thread' => 1, 'message' => 'Adiós', 'date_add' => '2026-10-02 10:00:00']);
    $i('store', ['id_store' => 1, 'id_country' => 1, 'city' => 'Sevilla', 'postcode' => '41001', 'active' => 1]);
    $i('warehouse', ['id_warehouse' => 1, 'id_currency' => 1, 'reference' => 'ALM1', 'name' => 'Almacén', 'management_type' => 'WA', 'deleted' => 0]);
}
