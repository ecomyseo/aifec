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
 * Una fila en una tabla.
 *
 * @param array<string,mixed> $row
 */
function wooRow(PDO $pdo, string $table, array $row): void
{
    $names = array_keys($row);
    $statement = $pdo->prepare('INSERT INTO ' . $table . ' (' . implode(', ', $names) . ') VALUES (' . implode(', ', array_fill(0, count($names), '?')) . ')');
    $statement->execute(array_values($row));
}

/**
 * La tienda de ejemplo (datos inventados): 6 productos —simple, variable con 3 variaciones, descargable, en oferta, sin existencias,
 * borrador—, 4 categorías con jerarquía, 2 atributos, 3 clientes, 4 pedidos —uno con devolución parcial, uno anulado—, 2 cupones,
 * impuestos de península, Canarias y Ceuta, 2 zonas de envío con tarifa plana y envío gratis, 2 pasarelas, 3 páginas, 2 entradas y
 * un menú. Los pedidos van a las tablas de entradas o a las HPOS según $hpos; lo demás es igual.
 */
function wooInsertData(PDO $pdo, string $site, bool $hpos, string $base = ''): void
{
    $base = $base === '' ? $site : $base;
    $t = static function (string $name) use ($site): string {
        return $site . $name;
    };
    $meta = static function (string $table, string $idColumn, int $id, array $pairs) use ($pdo, $t): void {
        foreach ($pairs as $key => $value) {
            wooRow($pdo, $t($table), [$idColumn => $id, 'meta_key' => (string) $key, 'meta_value' => (string) $value]);
        }
    };
    $post = static function (array $row) use ($pdo, $t): void {
        $row += [
            'post_author' => 1, 'post_date' => '2026-01-10 10:00:00', 'post_date_gmt' => '2026-01-10 09:00:00', 'post_content' => '', 'post_title' => '', 'post_excerpt' => '',
            'post_status' => 'publish', 'comment_status' => 'closed', 'post_name' => '', 'post_modified' => '2026-01-10 10:00:00', 'post_modified_gmt' => '2026-01-10 09:00:00',
            'post_parent' => 0, 'menu_order' => 0, 'post_type' => 'post', 'post_mime_type' => '',
        ];
        wooRow($pdo, $t('posts'), $row);
    };

    // ---- opciones
    $options = [
        'blogname' => 'Muebles Ñandú & Cía', 'blogdescription' => 'Mesas, sillas y estanterías', 'timezone_string' => 'Europe/Madrid', 'gmt_offset' => '1',
        'admin_email' => 'hola@tienda.test', 'WPLANG' => 'es_ES', 'siteurl' => 'https://tienda.test', 'permalink_structure' => '/%postname%/', 'stylesheet' => 'tienda-tema',
        'site_logo' => '410', 'wp_page_for_privacy_policy' => '7', 'woocommerce_terms_page_id' => '8', 'woocommerce_version' => '10.8.1',
        'woocommerce_store_address' => 'Polígono Sur, nave 4', 'woocommerce_store_address_2' => '', 'woocommerce_store_city' => 'Sevilla', 'woocommerce_store_postcode' => '41001',
        'woocommerce_default_country' => 'ES:SE', 'woocommerce_currency' => 'EUR', 'woocommerce_currency_pos' => 'right_space', 'woocommerce_price_num_decimals' => '2',
        'woocommerce_weight_unit' => 'kg', 'woocommerce_dimension_unit' => 'cm', 'woocommerce_prices_include_tax' => 'no', 'woocommerce_tax_based_on' => 'shipping',
        'woocommerce_tax_display_shop' => 'incl', 'woocommerce_calc_taxes' => 'yes', 'woocommerce_enable_guest_checkout' => 'yes', 'woocommerce_manage_stock' => 'yes',
        'woocommerce_email_from_name' => 'Muebles Ñandú', 'woocommerce_email_from_address' => 'hola@tienda.test',
        'woocommerce_custom_orders_table_enabled' => $hpos ? 'yes' : 'no',
        'woocommerce_permalinks' => serialize(['product_base' => '/producto', 'category_base' => 'categoria', 'tag_base' => '']),
        'woocommerce_gateway_order' => serialize(['bacs' => 0, 'redsys' => 1]),
        'woocommerce_bacs_settings' => serialize(['enabled' => 'yes', 'title' => 'Transferencia bancaria', 'description' => 'Paga por transferencia']),
        'woocommerce_redsys_settings' => serialize(['enabled' => 'no', 'title' => 'Tarjeta', 'testmode' => 'yes', 'merchant_code' => '000000000', 'secret_key' => 'clave-de-mentira']),
        'woocommerce_flat_rate_11_settings' => serialize(['title' => 'Mensajería 24 h', 'tax_status' => 'taxable', 'cost' => '5.90', 'class_cost_7' => '3.00', 'no_class_cost' => '']),
        'woocommerce_free_shipping_12_settings' => serialize(['title' => 'Envío gratis', 'requires' => 'min_amount', 'min_amount' => '60']),
        'woocommerce_local_pickup_13_settings' => serialize(['title' => 'Recogida en taller', 'tax_status' => 'none', 'cost' => '0']),
        'woocommerce_flat_rate_21_settings' => serialize(['title' => 'Mensajería Canarias', 'tax_status' => 'taxable', 'cost' => '12.00']),
        'theme_mods_tienda-tema' => serialize(['nav_menu_locations' => ['primary' => 50]]),
        'active_plugins' => serialize(['woocommerce/woocommerce.php', 'wordpress-seo/wp-seo.php', 'woocommerce-subscriptions/woocommerce-subscriptions.php', 'mailpoet/mailpoet.php', 'gravityforms/gravityforms.php']),
        $site . 'user_roles' => serialize(['administrator' => ['name' => 'Administrator'], 'customer' => ['name' => 'Customer'], 'shop_manager' => ['name' => 'Shop manager'], 'mayorista' => ['name' => 'Mayorista']]),
    ];
    foreach ($options as $name => $value) {
        wooRow($pdo, $t('options'), ['option_name' => (string) $name, 'option_value' => (string) $value, 'autoload' => 'yes']);
    }

    // ---- usuarios (las tablas de usuarios son compartidas)
    $users = [
        [1, 'admin', 'x', 'admin', 'admin@tienda.test', '2023-01-01 00:00:00', 'Administrador', ['administrator' => true]],
        [77, 'ana', '$P$B5xK0e8nOcRyGm1WjQh4T0Z9R2vQ1a0', 'ana', 'ana@cliente.test', '2024-05-02 16:20:00', 'Ana Ruiz Peña', ['customer' => true]],
        [78, 'luis', '$2y$10$abcdefghijklmnopqrstuuJ9Qe1h0sO9dJ3m1uQq7hYw9vJmVvF0S', 'luis', 'luis@cliente.test', '2024-06-11 09:00:00', 'Luis Gil', ['customer' => true]],
        [79, 'marta', '$wp$2y$10$abcdefghijklmnopqrstuuJ9Qe1h0sO9dJ3m1uQq7hYw9vJmVvF0S', 'marta', 'marta@cliente.test', '2025-01-20 12:00:00', 'Marta Soler', ['customer' => true]],
        [80, 'gestor', 'x', 'gestor', 'gestor@tienda.test', '2023-02-01 00:00:00', 'Gestor de tienda', ['shop_manager' => true]],
    ];
    $address = static function (string $first, string $last, string $line1, string $city, string $state, string $postcode): array {
        return ['first_name' => $first, 'last_name' => $last, 'company' => '', 'address_1' => $line1, 'address_2' => '', 'city' => $city, 'state' => $state, 'postcode' => $postcode, 'country' => 'ES'];
    };
    $profiles = [
        77 => array_replace($address('Ana', 'Ruiz Peña', 'Calle Mayor 1', 'Madrid', 'M', '28013'), ['address_2' => '2.º B', 'phone' => '999000001', 'email' => 'ana@cliente.test']),
        78 => $address('Luis', 'Gil', 'Calle Larga 2', 'Sevilla', 'SE', '41001') + ['phone' => '999000002', 'email' => 'luis@cliente.test'],
        79 => $address('Marta', 'Soler', 'Calle Corta 3', 'Ceuta', 'CE', '51001') + ['phone' => '999000003', 'email' => 'marta@cliente.test'],
    ];
    foreach ($users as $u) {
        wooRow($pdo, $base . 'users', ['ID' => $u[0], 'user_login' => $u[1], 'user_pass' => $u[2], 'user_nicename' => $u[3], 'user_email' => $u[4], 'user_registered' => $u[5], 'display_name' => $u[6]]);
        wooRow($pdo, $base . 'usermeta', ['user_id' => $u[0], 'meta_key' => $site . 'capabilities', 'meta_value' => serialize($u[7])]);
        if (isset($profiles[$u[0]])) {
            wooRow($pdo, $base . 'usermeta', ['user_id' => $u[0], 'meta_key' => 'first_name', 'meta_value' => $profiles[$u[0]]['first_name']]);
            wooRow($pdo, $base . 'usermeta', ['user_id' => $u[0], 'meta_key' => 'last_name', 'meta_value' => $profiles[$u[0]]['last_name']]);
            foreach (['billing_', 'shipping_'] as $prefix) {
                foreach ($profiles[$u[0]] as $field => $value) {
                    if (($prefix === 'shipping_' && in_array($field, ['email', 'phone'], true))) {
                        continue;
                    }
                    wooRow($pdo, $base . 'usermeta', ['user_id' => $u[0], 'meta_key' => $prefix . $field, 'meta_value' => $value]);
                }
            }
        }
    }

    // ---- términos
    $terms = [
        [2, 'Muebles', 'muebles', 'product_cat', 0], [12, 'Mesas', 'mesas', 'product_cat', 2], [13, 'Sillas', 'sillas', 'product_cat', 2], [14, 'Iluminación', 'iluminacion', 'product_cat', 0],
        [9, 'Nordika', 'nordika', 'product_brand', 0], [30, 'roble', 'roble', 'product_tag', 0], [31, 'auxiliar', 'auxiliar', 'product_tag', 0],
        [15, 'simple', 'simple', 'product_type', 0], [16, 'variable', 'variable', 'product_type', 0], [44, 'featured', 'featured', 'product_visibility', 0],
        [21, 'Pequeña', 'pequena', 'pa_tamano', 0], [22, 'Mediana', 'mediana', 'pa_tamano', 0], [23, 'Grande', 'grande', 'pa_tamano', 0],
        [25, 'Natural', 'natural', 'pa_acabado', 0], [26, 'Barnizado', 'barnizado', 'pa_acabado', 0],
        [4, 'Cuidados', 'cuidados', 'category', 0], [5, 'Novedades', 'novedades', 'category', 4], [32, 'roble', 'roble-entrada', 'post_tag', 0], [50, 'Principal', 'principal', 'nav_menu', 0],
    ];
    foreach ($terms as $term) {
        wooRow($pdo, $t('terms'), ['term_id' => $term[0], 'name' => $term[1], 'slug' => $term[2], 'term_group' => 0]);
        wooRow($pdo, $t('term_taxonomy'), ['term_taxonomy_id' => $term[0], 'term_id' => $term[0], 'taxonomy' => $term[3], 'description' => $term[3] === 'product_cat' ? '<p>' . $term[1] . '</p>' : '', 'parent' => $term[4], 'count' => 0]);
    }
    $meta('termmeta', 'term_id', 9, ['thumbnail_id' => 410]);
    $meta('termmeta', 'term_id', 12, ['order' => 1]);
    foreach ([[1, 'tamano', 'Tamaño'], [2, 'acabado', 'Acabado']] as $a) {
        wooRow($pdo, $t('woocommerce_attribute_taxonomies'), ['attribute_id' => $a[0], 'attribute_name' => $a[1], 'attribute_label' => $a[2], 'attribute_type' => 'select', 'attribute_orderby' => 'menu_order', 'attribute_public' => 0]);
    }
    $relate = static function (int $object, array $termIds) use ($pdo, $t): void {
        foreach ($termIds as $i => $id) {
            wooRow($pdo, $t('term_relationships'), ['object_id' => $object, 'term_taxonomy_id' => $id, 'term_order' => $i]);
        }
    };

    // ---- imágenes
    $attachments = [
        [310, '2026/03/taller.jpg', 'El taller', 'image/jpeg'], [401, '2025/02/mesa-1.jpg', 'Mesa auxiliar de roble', 'image/jpeg'], [402, '2025/02/mesa-2.jpg', '', 'image/jpeg'],
        [403, '2025/03/silla-p.jpg', '', 'image/jpeg'], [404, '2025/03/silla-1.jpg', 'Silla de nogal', 'image/jpeg'], [410, '2026/01/logo.png', 'Logotipo', 'image/png'],
    ];
    foreach ($attachments as $a) {
        $post(['ID' => $a[0], 'post_title' => basename($a[1], '.' . pathinfo($a[1], PATHINFO_EXTENSION)), 'post_name' => basename($a[1], '.' . pathinfo($a[1], PATHINFO_EXTENSION)), 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => $a[3]]);
        $meta('postmeta', 'post_id', $a[0], ['_wp_attached_file' => $a[1], '_wp_attachment_image_alt' => $a[2]]);
    }

    // ---- productos
    $product = static function (int $id, string $title, string $slug, string $status, array $m, array $termIds, array $extra = []) use ($post, $meta, $relate): void {
        $post($extra + [
            'ID' => $id, 'post_title' => $title, 'post_name' => $slug, 'post_status' => $status, 'post_type' => 'product', 'comment_status' => 'open',
            'post_date' => '2025-02-12 11:00:00', 'post_date_gmt' => '2025-02-12 10:00:00', 'post_modified' => '2026-10-01 10:00:00', 'post_modified_gmt' => '2026-10-01 08:00:00',
        ]);
        $meta('postmeta', 'post_id', $id, $m);
        $relate($id, $termIds);
    };
    $product(101, 'Mesa auxiliar de roble', 'mesa-auxiliar-roble', 'publish', [
        '_sku' => 'MESA-ROBLE', '_global_unique_id' => '8400000000017', '_regular_price' => '106.61', '_sale_price' => '89.00', '_price' => '89.00',
        '_sale_price_dates_from' => (string) strtotime('2026-10-31 23:00:00 UTC'), '_manage_stock' => 'yes', '_stock' => '14', '_stock_status' => 'instock', '_backorders' => 'no',
        '_weight' => '6.5', '_length' => '40', '_width' => '40', '_height' => '48', '_tax_status' => 'taxable', '_tax_class' => '', '_thumbnail_id' => '401', '_product_image_gallery' => '402',
        '_virtual' => 'no', '_downloadable' => 'no',
    ], [12, 9, 30, 31, 15, 44], ['post_content' => '<p>Roble macizo con acabado al agua.</p>', 'post_excerpt' => '<p>Redonda, de una pata.</p>']);
    $product(102, 'Silla de nogal', 'silla-de-nogal', 'publish', [
        '_sku' => 'SILLA-NOGAL', '_price' => '50.00', '_regular_price' => '', '_manage_stock' => 'no', '_stock_status' => 'instock', '_weight' => '5.2', '_length' => '45', '_width' => '45',
        '_height' => '90', '_tax_status' => 'taxable', '_thumbnail_id' => '404', '_virtual' => 'no', '_downloadable' => 'no',
        '_product_attributes' => serialize([
            'pa_tamano' => ['name' => 'pa_tamano', 'value' => '', 'position' => 0, 'is_visible' => 1, 'is_variation' => 1, 'is_taxonomy' => 1],
            'pa_acabado' => ['name' => 'pa_acabado', 'value' => '', 'position' => 1, 'is_visible' => 1, 'is_variation' => 0, 'is_taxonomy' => 1],
        ]),
    ], [13, 16, 21, 22, 23, 25], ['post_content' => '<p>Silla de nogal en tres tamaños.</p>']);
    $product(103, 'Manual de cuidados', 'manual-de-cuidados', 'publish', [
        '_sku' => 'MANUAL-PDF', '_regular_price' => '9.99', '_price' => '9.99', '_virtual' => 'yes', '_downloadable' => 'yes', '_manage_stock' => 'no', '_stock_status' => 'instock', '_tax_status' => 'taxable',
        '_downloadable_files' => serialize(['d1' => ['id' => 'd1', 'name' => 'Manual PDF', 'file' => 'https://tienda.test/wp-content/uploads/woocommerce_uploads/manual.pdf']]),
    ], [13, 15]);
    $product(104, 'Estantería en oferta', 'estanteria-en-oferta', 'publish', [
        '_sku' => 'ESTANTE-OF', '_regular_price' => '99.00', '_sale_price' => '79.00', '_price' => '79.00', '_manage_stock' => 'yes', '_stock' => '3', '_stock_status' => 'instock', '_backorders' => 'no',
        '_weight' => '12', '_tax_status' => 'taxable', '_virtual' => 'no', '_downloadable' => 'no',
    ], [13, 15]);
    $product(105, 'Banco sin existencias', 'banco-sin-existencias', 'publish', [
        '_sku' => 'BANCO-S', '_regular_price' => '150.00', '_price' => '150.00', '_manage_stock' => 'yes', '_stock' => '0', '_stock_status' => 'outofstock', '_backorders' => 'no', '_tax_status' => 'taxable',
        '_virtual' => 'no', '_downloadable' => 'no',
    ], [13, 15]);
    $product(106, 'Lámpara (borrador)', 'lampara-borrador', 'draft', ['_sku' => 'LAMP-B', '_regular_price' => '45.00', '_price' => '45.00', '_manage_stock' => 'no', '_tax_status' => 'taxable', '_virtual' => 'no', '_downloadable' => 'no'], [14, 15]);
    $product(150, 'Producto en la papelera', 'en-la-papelera', 'trash', ['_sku' => 'PAPELERA', '_regular_price' => '1.00', '_price' => '1.00'], [15]);
    foreach ([[201, 'SILLA-NOGAL-P', '50.00', '', 9, 403, 21, 0, 'publish'], [202, 'SILLA-NOGAL-M', '60.00', '', 5, 0, 22, 1, 'publish'], [203, 'SILLA-NOGAL-G', '70.00', '63.00', 2, 0, 23, 2, 'publish']] as $v) {
        $post(['ID' => $v[0], 'post_title' => 'Silla de nogal - ' . $v[1], 'post_name' => 'silla-de-nogal-' . $v[0], 'post_type' => 'product_variation', 'post_parent' => 102, 'post_status' => $v[8], 'menu_order' => $v[7]]);
        $slugs = [21 => 'pequena', 22 => 'mediana', 23 => 'grande'];
        $meta('postmeta', 'post_id', $v[0], [
            '_sku' => $v[1], '_regular_price' => $v[2], '_sale_price' => $v[3], '_price' => $v[3] !== '' ? $v[3] : $v[2], '_manage_stock' => 'yes', '_stock' => (string) $v[4],
            '_thumbnail_id' => (string) $v[5], '_weight' => $v[0] === 203 ? '9' : '', 'attribute_pa_tamano' => $slugs[$v[6]],
        ]);
    }

    // ---- cupones
    $post(['ID' => 301, 'post_title' => 'otono10', 'post_name' => 'otono10', 'post_type' => 'shop_coupon', 'post_excerpt' => 'Campaña de otoño', 'post_modified' => '2026-09-21 10:00:00', 'post_modified_gmt' => '2026-09-21 08:00:00']);
    $meta('postmeta', 'post_id', 301, [
        'discount_type' => 'percent', 'coupon_amount' => '10', 'individual_use' => 'yes', 'minimum_amount' => '50', 'usage_limit' => '200', 'usage_limit_per_user' => '1', 'usage_count' => '37',
        'date_expires' => (string) strtotime('2026-12-21 00:00:00 UTC'), 'exclude_sale_items' => 'yes', 'product_categories' => serialize([12]),
    ]);
    $post(['ID' => 302, 'post_title' => 'envio-gratis', 'post_name' => 'envio-gratis', 'post_type' => 'shop_coupon']);
    $meta('postmeta', 'post_id', 302, [
        'discount_type' => 'fixed_cart', 'coupon_amount' => '0', 'free_shipping' => 'yes', 'exclude_product_ids' => '105', 'customer_email' => serialize(['ana@cliente.test']), 'usage_count' => '0',
    ]);

    // ---- impuestos
    wooRow($pdo, $t('wc_tax_rate_classes'), ['tax_rate_class_id' => 1, 'name' => 'Tipo reducido', 'slug' => 'reduced-rate']);
    $rates = [
        [1, 'ES', '', '21.0000', 'IVA 21 %', 1, ''], [2, 'ES', 'TF', '7.0000', 'IGIC 7 %', 1, ''], [3, 'ES', 'CE', '4.0000', 'IPSI Ceuta', 1, ''], [4, 'ES', '', '10.0000', 'IVA reducido', 1, 'reduced-rate'],
    ];
    foreach ($rates as $r) {
        wooRow($pdo, $t('woocommerce_tax_rates'), [
            'tax_rate_id' => $r[0], 'tax_rate_country' => $r[1], 'tax_rate_state' => $r[2], 'tax_rate' => $r[3], 'tax_rate_name' => $r[4], 'tax_rate_priority' => $r[5],
            'tax_rate_compound' => 0, 'tax_rate_shipping' => 1, 'tax_rate_order' => $r[0], 'tax_rate_class' => $r[6],
        ]);
    }
    foreach ([[2, '35*'], [2, '38*'], [3, '51*']] as $l) {
        wooRow($pdo, $t('woocommerce_tax_rate_locations'), ['location_code' => $l[1], 'tax_rate_id' => $l[0], 'location_type' => 'postcode']);
    }

    // ---- envíos
    wooRow($pdo, $t('woocommerce_shipping_zones'), ['zone_id' => 1, 'zone_name' => 'Península', 'zone_order' => 0]);
    wooRow($pdo, $t('woocommerce_shipping_zones'), ['zone_id' => 2, 'zone_name' => 'Canarias', 'zone_order' => 1]);
    foreach ([[1, 'ES', 'country'], [1, 'PT', 'country'], [2, 'ES:TF', 'state'], [2, 'ES:GC', 'state'], [2, '35*', 'postcode'], [2, '38*', 'postcode']] as $l) {
        wooRow($pdo, $t('woocommerce_shipping_zone_locations'), ['zone_id' => $l[0], 'location_code' => $l[1], 'location_type' => $l[2]]);
    }
    foreach ([[1, 11, 'flat_rate', 1], [1, 12, 'free_shipping', 2], [1, 13, 'local_pickup', 3], [2, 21, 'flat_rate', 1]] as $m) {
        wooRow($pdo, $t('woocommerce_shipping_zone_methods'), ['zone_id' => $m[0], 'instance_id' => $m[1], 'method_id' => $m[2], 'method_order' => $m[3], 'is_enabled' => 1]);
    }

    // ---- contenidos
    $post(['ID' => 7, 'post_title' => 'Política de privacidad', 'post_name' => 'politica-de-privacidad', 'post_type' => 'page', 'post_content' => '<h2>Quién trata tus datos</h2><p>Ñandú Muebles S.L.</p>']);
    $post(['ID' => 8, 'post_title' => 'Condiciones de venta', 'post_name' => 'condiciones-de-venta', 'post_type' => 'page', 'post_content' => '<p>Condiciones.</p>']);
    $post(['ID' => 9, 'post_title' => 'Quiénes somos', 'post_name' => 'quienes-somos', 'post_type' => 'page', 'post_content' => "<!-- wp:paragraph -->\n<p>Un taller de Sevilla.</p>\n<!-- /wp:paragraph -->"]);
    $meta('postmeta', 'post_id', 9, ['_yoast_wpseo_title' => 'Quiénes somos en Ñandú', '_yoast_wpseo_metadesc' => 'Conoce el taller.']);
    $post(['ID' => 230, 'post_title' => 'Cómo cuidar una mesa de roble', 'post_name' => 'como-cuidar-una-mesa-de-roble', 'post_type' => 'post', 'post_excerpt' => 'Tres cosas que alargan su vida.',
        'post_content' => "<!-- wp:paragraph -->\n<p>Un paño húmedo y nada de sol directo.</p>\n<!-- /wp:paragraph -->", 'post_date' => '2026-03-04 08:30:00', 'post_date_gmt' => '2026-03-04 07:30:00', 'post_author' => 80]);
    $meta('postmeta', 'post_id', 230, ['_thumbnail_id' => '310']);
    $relate(230, [4, 32]);
    $post(['ID' => 231, 'post_title' => 'Novedades del taller', 'post_name' => 'novedades-del-taller', 'post_type' => 'post', 'post_content' => '<p>Nuevas sillas.</p>', 'post_date' => '2026-04-01 08:30:00', 'post_date_gmt' => '2026-04-01 06:30:00']);
    $relate(231, [5]);
    $menuItems = [
        [601, '', 1, 'post_type', 'page', 7, 0, ''], [602, 'Tienda', 0, 'custom', 'custom', 602, 0, '/tienda/'], [603, '', 0, 'taxonomy', 'product_cat', 12, 602, ''], [604, 'Inicio', 2, 'custom', 'custom', 604, 0, 'https://tienda.test/'],
    ];
    foreach ($menuItems as $m) {
        $post(['ID' => $m[0], 'post_title' => $m[1], 'post_type' => 'nav_menu_item', 'menu_order' => $m[2]]);
        $meta('postmeta', 'post_id', $m[0], ['_menu_item_type' => $m[3], '_menu_item_object' => $m[4], '_menu_item_object_id' => $m[5], '_menu_item_menu_item_parent' => $m[6], '_menu_item_url' => $m[7]]);
        $relate($m[0], [50]);
    }

    // ---- opiniones y notas
    $comment = static function (array $row) use ($pdo, $t): void {
        wooRow($pdo, $t('comments'), $row + ['comment_author_email' => '', 'comment_date' => '2026-10-10 20:00:00', 'comment_date_gmt' => '2026-10-10 18:00:00', 'comment_approved' => '1', 'user_id' => 0]);
    };
    $comment(['comment_ID' => 15, 'comment_post_ID' => 101, 'comment_author' => 'Ana R.', 'comment_content' => 'Llegó en dos días y sin un golpe.', 'comment_type' => 'review']);
    $meta('commentmeta', 'comment_id', 15, ['rating' => '5', 'verified' => '1']);
    $comment(['comment_ID' => 16, 'comment_post_ID' => 104, 'comment_author' => 'Luis', 'comment_content' => 'Falta una balda.', 'comment_type' => 'review', 'comment_approved' => '0']);
    $meta('commentmeta', 'comment_id', 16, ['rating' => '3']);
    $comment(['comment_ID' => 701, 'comment_post_ID' => 9001, 'comment_author' => 'WooCommerce', 'comment_content' => 'Pago recibido con tarjeta', 'comment_type' => 'order_note', 'comment_date_gmt' => '2026-10-01 08:16:00']);
    $meta('commentmeta', 'comment_id', 701, ['is_customer_note' => '0']);
    $comment(['comment_ID' => 702, 'comment_post_ID' => 9001, 'comment_author' => 'WooCommerce', 'comment_content' => 'Pedido enviado por mensajería', 'comment_type' => 'order_note', 'comment_date_gmt' => '2026-10-02 06:00:00']);
    $meta('commentmeta', 'comment_id', 702, ['is_customer_note' => '1']);

    // ---- redirecciones
    foreach ([[1, '/mesas-antiguas/', 0, 'url', 301, '/mesas/', 'enabled'], [2, '/viejo/(.*)', 1, 'url', 301, '/nuevo/$1', 'enabled'], [3, '/gone/', 0, 'error', 410, '', 'enabled']] as $r) {
        wooRow($pdo, $t('redirection_items'), ['id' => $r[0], 'url' => $r[1], 'regex' => $r[2], 'action_type' => $r[3], 'action_code' => $r[4], 'action_data' => $r[5], 'status' => $r[6]]);
    }
    wooRow($pdo, $t('rank_math_redirections'), ['id' => 1, 'sources' => serialize([['pattern' => 'oferta', 'comparison' => 'exact']]), 'url_to' => '/ofertas/', 'header_code' => 301, 'status' => 'active']);
    wooRow($pdo, $t('rank_math_redirections'), ['id' => 2, 'sources' => serialize([['pattern' => 'promo', 'comparison' => 'contains']]), 'url_to' => '/promos/', 'header_code' => 301, 'status' => 'active']);

    // ---- pedidos
    $ana = $profiles[77];
    $luis = $profiles[78];
    $invitado = ['first_name' => 'Pepe', 'last_name' => 'Invitado', 'company' => '', 'address_1' => 'Calle Nueva 4', 'address_2' => '', 'city' => 'Cádiz', 'state' => 'CA', 'postcode' => '11001', 'country' => 'ES', 'email' => 'invitado@cliente.test', 'phone' => '999000004'];
    $orders = [
        9001 => [
            'status' => 'processing', 'customer' => 77, 'date' => '2026-10-01 10:15:00', 'date_gmt' => '2026-10-01 08:15:00', 'modified' => '2026-10-03 11:00:00', 'modified_gmt' => '2026-10-03 09:00:00',
            'note' => 'Llamar antes de entregar', 'total' => '136.14', 'tax' => '22.39', 'ship_tax' => '1.24', 'paid' => '2026-10-01 08:16:00', 'completed' => '', 'billing' => $ana, 'shipping' => $ana,
            'key' => 'wc_order_abc123', 'hash' => 'cafe0001', 'ip' => '203.0.113.5', 'transaction' => '000001042',
        ],
        9002 => [
            'status' => 'completed', 'customer' => 78, 'date' => '2026-10-05 12:00:00', 'date_gmt' => '2026-10-05 10:00:00', 'modified' => '2026-10-06 11:00:00', 'modified_gmt' => '2026-10-06 09:00:00',
            'note' => '', 'total' => '198.32', 'tax' => '33.18', 'ship_tax' => '1.24', 'paid' => '2026-10-05 10:05:00', 'completed' => '2026-10-06 08:00:00', 'billing' => $luis, 'shipping' => $luis,
            'key' => 'wc_order_def456', 'hash' => 'cafe0002', 'ip' => '203.0.113.6', 'transaction' => '000001043',
        ],
        9003 => [
            'status' => 'cancelled', 'customer' => 79, 'date' => '2026-10-07 09:00:00', 'date_gmt' => '2026-10-07 07:00:00', 'modified' => '2026-10-08 09:00:00', 'modified_gmt' => '2026-10-08 07:00:00',
            'note' => '', 'total' => '181.50', 'tax' => '31.50', 'ship_tax' => '0', 'paid' => '', 'completed' => '', 'billing' => $profiles[79], 'shipping' => $profiles[79],
            'key' => 'wc_order_ghi789', 'hash' => 'cafe0003', 'ip' => '203.0.113.7', 'transaction' => '',
        ],
        9004 => [
            'status' => 'on-hold', 'customer' => 0, 'date' => '2026-10-09 15:00:00', 'date_gmt' => '2026-10-09 13:00:00', 'modified' => '2026-10-09 15:00:00', 'modified_gmt' => '2026-10-09 13:00:00',
            'note' => '', 'total' => '79.74', 'tax' => '12.60', 'ship_tax' => '1.24', 'paid' => '', 'completed' => '', 'billing' => $invitado, 'shipping' => $invitado,
            'key' => 'wc_order_jkl012', 'hash' => 'cafe0004', 'ip' => '203.0.113.8', 'transaction' => '',
        ],
    ];
    $item = static function (int $orderId, int $itemId, string $name, string $type, array $m) use ($pdo, $t, $meta): void {
        wooRow($pdo, $t('woocommerce_order_items'), ['order_item_id' => $itemId, 'order_item_name' => $name, 'order_item_type' => $type, 'order_id' => $orderId]);
        $meta('woocommerce_order_itemmeta', 'order_item_id', $itemId, $m);
    };
    $line = static function (int $product, int $variation, int $qty, string $subtotal, string $tax, array $more = []): array {
        return $more + [
            '_product_id' => $product, '_variation_id' => $variation, '_qty' => $qty, '_tax_class' => '', '_line_subtotal' => $subtotal, '_line_subtotal_tax' => $tax, '_line_total' => $subtotal, '_line_tax' => $tax,
            '_line_tax_data' => serialize(['total' => [1 => $tax], 'subtotal' => [1 => $tax]]),
        ];
    };
    $shipping = static function (string $tax): array {
        return ['method_id' => 'flat_rate', 'instance_id' => 11, 'cost' => '5.90', 'total_tax' => $tax, 'taxes' => serialize(['total' => [1 => $tax]])];
    };
    $taxLine = static function (string $amount, string $shippingAmount): array {
        return ['rate_id' => 1, 'label' => 'IVA 21 %', 'compound' => 0, 'tax_amount' => $amount, 'shipping_tax_amount' => $shippingAmount, 'rate_percent' => '21'];
    };
    $item(9001, 5001, 'Mesa auxiliar de roble', 'line_item', $line(101, 0, 1, '106.61', '22.39'));
    $item(9001, 5002, 'Mensajería 24 h', 'shipping', $shipping('1.24'));
    $item(9001, 5003, 'ES-IVA 21 %-1', 'tax', $taxLine('22.39', '1.24'));
    $item(9002, 5011, 'Estantería en oferta', 'line_item', $line(104, 0, 2, '158.00', '33.18'));
    $item(9002, 5012, 'Mensajería 24 h', 'shipping', $shipping('1.24'));
    $item(9002, 5013, 'ES-IVA 21 %-1', 'tax', $taxLine('33.18', '1.24'));
    $item(9003, 5031, 'Banco sin existencias', 'line_item', $line(105, 0, 1, '150.00', '31.50'));
    $item(9003, 5033, 'ES-IVA 21 %-1', 'tax', $taxLine('31.50', '0'));
    $item(9004, 5041, 'Silla de nogal - Mediana', 'line_item', $line(102, 202, 1, '60.00', '12.60', ['pa_tamano' => 'mediana']));
    $item(9004, 5042, 'Mensajería 24 h', 'shipping', $shipping('1.24'));
    $item(9004, 5043, 'ES-IVA 21 %-1', 'tax', $taxLine('12.60', '1.24'));
    // La devolución parcial del pedido 9002: una de las dos unidades.
    $item(9102, 5021, 'Estantería en oferta', 'line_item', [
        '_product_id' => 104, '_variation_id' => 0, '_qty' => -1, '_tax_class' => '', '_line_subtotal' => '-79.00', '_line_subtotal_tax' => '-16.59', '_line_total' => '-79.00', '_line_tax' => '-16.59',
        '_line_tax_data' => serialize(['total' => [1 => '-16.59'], 'subtotal' => [1 => '-16.59']]), '_refunded_item_id' => 5011,
    ]);

    foreach ($orders as $id => $o) {
        if ($hpos) {
            wooRow($pdo, $t('wc_orders'), [
                'id' => $id, 'status' => 'wc-' . $o['status'], 'currency' => 'EUR', 'type' => 'shop_order', 'tax_amount' => number_format((float) $o['tax'] + (float) $o['ship_tax'], 2, '.', ''), 'total_amount' => $o['total'],
                'customer_id' => $o['customer'], 'billing_email' => $o['billing']['email'], 'date_created_gmt' => $o['date_gmt'], 'date_updated_gmt' => $o['modified_gmt'], 'parent_order_id' => 0,
                'payment_method' => 'redsys', 'payment_method_title' => 'Tarjeta', 'transaction_id' => $o['transaction'], 'ip_address' => $o['ip'], 'user_agent' => '', 'customer_note' => $o['note'],
            ]);
            foreach (['billing', 'shipping'] as $type) {
                wooRow($pdo, $t('wc_order_addresses'), ['order_id' => $id, 'address_type' => $type] + array_intersect_key($o[$type], array_flip(['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'])));
            }
            wooRow($pdo, $t('wc_order_operational_data'), [
                'order_id' => $id, 'created_via' => 'checkout', 'woocommerce_version' => '10.8.1', 'prices_include_tax' => 0, 'cart_hash' => $o['hash'], 'order_key' => $o['key'],
                'date_paid_gmt' => $o['paid'], 'date_completed_gmt' => $o['completed'], 'shipping_tax_amount' => $o['ship_tax'], 'shipping_total_amount' => '5.90', 'discount_total_amount' => '0.00',
            ]);
        } else {
            $post([
                'ID' => $id, 'post_type' => 'shop_order', 'post_status' => 'wc-' . $o['status'], 'post_date' => $o['date'], 'post_date_gmt' => $o['date_gmt'], 'post_modified' => $o['modified'],
                'post_modified_gmt' => $o['modified_gmt'], 'post_excerpt' => $o['note'],
            ]);
            $m = [
                '_order_total' => $o['total'], '_order_tax' => $o['tax'], '_order_shipping_tax' => $o['ship_tax'], '_order_currency' => 'EUR', '_prices_include_tax' => 'no', '_customer_user' => $o['customer'],
                '_payment_method' => 'redsys', '_payment_method_title' => 'Tarjeta', '_transaction_id' => $o['transaction'], '_order_key' => $o['key'], '_created_via' => 'checkout',
                '_customer_ip_address' => $o['ip'], '_cart_hash' => $o['hash'],
            ];
            if ($o['paid'] !== '') {
                $m['_date_paid'] = (string) strtotime($o['paid'] . ' UTC');
            }
            if ($o['completed'] !== '') {
                $m['_date_completed'] = (string) strtotime($o['completed'] . ' UTC');
            }
            foreach (['billing' => '_billing_', 'shipping' => '_shipping_'] as $type => $prefix) {
                foreach (array_intersect_key($o[$type], array_flip(['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'])) as $field => $value) {
                    $m[$prefix . $field] = $value;
                }
            }
            $meta('postmeta', 'post_id', $id, $m);
        }
    }
    if ($hpos) {
        wooRow($pdo, $t('wc_orders'), [
            'id' => 9102, 'status' => 'wc-completed', 'currency' => 'EUR', 'type' => 'shop_order_refund', 'tax_amount' => '-16.59', 'total_amount' => '-95.59', 'customer_id' => 0, 'billing_email' => '',
            'date_created_gmt' => '2026-10-06 07:00:00', 'date_updated_gmt' => '2026-10-06 07:00:00', 'parent_order_id' => 9002, 'payment_method' => '', 'payment_method_title' => '', 'transaction_id' => '',
            'ip_address' => '', 'user_agent' => '', 'customer_note' => 'Una unidad llegó golpeada',
        ]);
    } else {
        $post(['ID' => 9102, 'post_type' => 'shop_order_refund', 'post_status' => 'wc-completed', 'post_parent' => 9002, 'post_date_gmt' => '2026-10-06 07:00:00', 'post_date' => '2026-10-06 09:00:00', 'post_excerpt' => 'Una unidad llegó golpeada']);
        $meta('postmeta', 'post_id', 9102, ['_refund_amount' => '95.59']);
    }
    // Un pedido a medias (el pago sin acabar) que no hay que traer.
    if ($hpos) {
        wooRow($pdo, $t('wc_orders'), [
            'id' => 9190, 'status' => 'wc-checkout-draft', 'currency' => 'EUR', 'type' => 'shop_order', 'tax_amount' => '0', 'total_amount' => '0', 'customer_id' => 0, 'billing_email' => '',
            'date_created_gmt' => '2026-10-10 08:00:00', 'date_updated_gmt' => '2026-10-10 08:00:00', 'parent_order_id' => 0, 'payment_method' => '', 'payment_method_title' => '', 'transaction_id' => '',
            'ip_address' => '', 'user_agent' => '', 'customer_note' => '',
        ]);
    } else {
        $post(['ID' => 9190, 'post_type' => 'shop_order', 'post_status' => 'wc-checkout-draft']);
    }
}

/**
 * Una copia de la base de datos de ejemplo en SQLite en memoria, lista para pasársela a DbReader.
 */
function wooMemoryDb(bool $hpos = false, string $site = 'wp_', string $base = '', string $class = 'PDO', bool $empty = false): PDO
{
    require_once __DIR__ . '/schema.php';
    $pdo = new $class('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (wooSchema($site, $base) as $sql) {
        $pdo->exec($sql);
    }
    if (!$empty) {
        wooInsertData($pdo, $site, $hpos, $base);
    }

    return $pdo;
}
