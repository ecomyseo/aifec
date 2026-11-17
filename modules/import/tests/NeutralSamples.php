<?php
/**
 * AIFEC — un registro neutro de ejemplo, válido y completo, de cada tipo de migración.
 *
 * Sirve de modelo a quien escribe un lector (así tiene que salir) y de entrada a quien escribe un escritor (así
 * llega). Los datos son inventados: ninguna persona, tienda ni clave es real.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/**
 * @return array<string,mixed>
 */
function neutralSample(string $type): array
{
    $address = [
        'id' => '5', 'label' => 'Casa', 'first_name' => 'Ana', 'last_name' => 'Ruiz Peña', 'company' => '', 'vat_id' => '', 'phone' => '999000001',
        'line1' => 'Calle Mayor 1', 'line2' => '2.º B', 'postcode' => '28013', 'city' => 'Madrid', 'region' => 'M', 'country' => 'ES',
        'default_shipping' => true, 'default_billing' => true,
    ];
    $samples = [
        'settings' => [
            'id' => 'main', 'shop_name' => 'Muebles Ñandú & Cía', 'legal_name' => 'Ñandú Muebles S.L.', 'vat_id' => 'B00000000', 'email' => 'hola@tienda.test',
            'phone' => '999500007', 'address' => ['line1' => 'Polígono Sur, nave 4', 'postcode' => '41001', 'city' => 'Sevilla', 'region' => 'SE', 'country' => 'ES'],
            'currency' => 'EUR', 'languages' => ['es', 'en'], 'default_language' => 'es', 'timezone' => 'Europe/Madrid', 'weight_unit' => 'kg', 'dimension_unit' => 'cm',
            'prices_include_tax' => false, 'display_prices_with_tax' => true, 'tax_based_on' => 'shipping', 'guest_checkout' => true, 'stock_management' => true,
            'logo' => ['url' => 'https://tienda.test/img/logo.png'], 'icon' => null, 'order_number_prefix' => 'P', 'order_number_next' => 1043,
            'invoice_number_prefix' => 'F2026-', 'invoice_number_next' => 318,
            'mail' => ['transport' => 'smtp', 'host' => 'smtp.tienda.test', 'port' => 587, 'encryption' => 'tls', 'username' => 'envios@tienda.test', 'password' => 'clave-de-mentira', 'from_email' => 'hola@tienda.test', 'from_name' => 'Muebles Ñandú'],
            'legal_pages' => ['terms' => '3', 'privacy' => '7'], 'home_title' => ['es' => 'Muebles de madera maciza'], 'home_description' => ['es' => 'Mesas, sillas y estanterías hechas en Sevilla.'],
            'extra' => ['catalog_mode' => false],
        ],
        'tax_classes' => ['id' => '1', 'code' => 'standard', 'name' => 'IVA general', 'is_default' => true],
        'tax_rates' => ['id' => '11', 'class_id' => '1', 'country' => 'ES', 'region' => '', 'postcodes' => '35,38', 'rate' => 700, 'name' => 'IGIC 7 %', 'priority' => 10, 'active' => true, 'compound' => false, 'applies_to_shipping' => true],
        'shipping_zones' => ['id' => '2', 'name' => 'Península', 'locations' => [['country' => 'ES', 'region' => '', 'postcodes' => ''], ['country' => 'PT']], 'position' => 0, 'active' => true],
        'carriers' => [
            'id' => '4', 'name' => 'Mensajería 24 h', 'delay' => ['es' => 'Entrega en 24-48 horas'], 'kind' => 'rates', 'tax_class_id' => '1', 'price_includes_tax' => false,
            'free_from' => 6000, 'max_weight' => 30000, 'min_days' => 1, 'max_days' => 2, 'tracking_url' => 'https://envios.test/seguir/@', 'logo' => null, 'active' => true,
            'position' => 0, 'handling_fee' => 0, 'handling_fee_micros' => 0,
            'rates' => [['zone_id' => '2', 'basis' => 'weight', 'min' => 0, 'max' => 5000, 'price' => 590, 'price_micros' => 5900000], ['zone_id' => '2', 'basis' => 'weight', 'min' => 5000, 'max' => null, 'price' => 990]],
        ],
        'payment_methods' => ['id' => 'redsys', 'code' => 'redsys', 'title' => ['es' => 'Tarjeta'], 'enabled' => true, 'sandbox' => false, 'settings' => ['merchant_code' => '000000000', 'terminal' => '1', 'secret_key' => 'clave-de-mentira'], 'min_amount' => null, 'max_amount' => null, 'countries' => [], 'position' => 0],
        'customer_groups' => ['id' => '3', 'name' => ['es' => 'Profesionales'], 'discount' => 1000, 'tax_exempt' => false, 'is_default' => false],
        'brands' => ['id' => '9', 'name' => 'Nordika', 'description' => ['es' => '<p>Madera con certificado.</p>'], 'website' => 'https://nordika.test', 'logo' => ['url' => 'https://tienda.test/img/m/9.jpg'], 'active' => true, 'position' => 0, 'slug' => ['es' => 'nordika'], 'old_paths' => ['/9_nordika']],
        'categories' => ['id' => '12', 'parent_id' => '2', 'name' => ['es' => 'Mesas', 'en' => 'Tables'], 'description' => ['es' => '<p>Mesas de comedor y auxiliares.</p>'], 'slug' => ['es' => 'mesas', 'en' => 'tables'], 'position' => 1, 'active' => true, 'in_menu' => true, 'image' => null, 'meta_title' => ['es' => 'Mesas de madera'], 'meta_description' => ['es' => 'Mesas de roble y nogal.'], 'old_paths' => ['/12-mesas']],
        'attributes' => ['id' => 'a1', 'code' => 'size', 'name' => ['es' => 'Tamaño'], 'type' => 'select', 'unit' => '', 'variant' => true, 'filterable' => true, 'visible' => true, 'position' => 0, 'values' => [['id' => '21', 'label' => ['es' => 'Pequeña'], 'color' => null, 'position' => 0], ['id' => '22', 'label' => ['es' => 'Grande'], 'position' => 1]]],
        'products' => [
            'id' => '101', 'updated_at' => '2026-10-01T08:00:00Z', 'created_at' => '2025-02-11T10:00:00Z', 'published_at' => '2025-02-12T09:00:00Z', 'kind' => 'physical', 'sku' => 'MESA-ROBLE', 'gtin' => '8400000000017', 'mpn' => '',
            'brand_id' => '9', 'category_ids' => ['12'], 'main_category_id' => '12', 'tax_class_id' => '1',
            'name' => ['es' => 'Mesa auxiliar de roble', 'en' => 'Oak side table'], 'summary' => ['es' => '<p>Redonda, de una pata.</p>'], 'description' => ['es' => '<p>Roble macizo con acabado al agua.</p>'], 'slug' => ['es' => 'mesa-auxiliar-roble'],
            'price' => 10661, 'price_includes_tax' => false, 'compare_price' => null, 'sale_price' => 8900, 'sale_from' => '2026-11-01T00:00:00Z', 'sale_to' => null, 'cost' => 5200,
            'price_micros' => 106611570, 'compare_price_micros' => null, 'sale_price_micros' => 89000000,
            'manage_stock' => true, 'stock' => 14, 'backorders' => 'no', 'min_qty' => 1, 'max_qty' => null, 'weight' => 6500, 'length' => 400, 'width' => 400, 'height' => 480,
            'status' => 'published', 'visibility' => 'visible', 'featured' => true, 'reviews_allowed' => true,
            'images' => [['url' => 'https://tienda.test/img/p/1/0/1/101.jpg', 'alt' => ['es' => 'Mesa auxiliar de roble'], 'position' => 0, 'cover' => true], ['url' => 'https://tienda.test/img/p/1/0/2/102.jpg', 'position' => 1]],
            'attributes' => [['attribute_id' => 'f3', 'text' => ['es' => 'Roble macizo']], ['attribute_id' => 'f4', 'number' => 48.0]],
            'tags' => ['es' => ['roble', 'auxiliar']], 'files' => [],
            'variants' => [
                ['id' => '501', 'sku' => 'MESA-ROBLE-P', 'gtin' => '', 'price' => 10661, 'price_micros' => 106611570, 'compare_price' => null, 'compare_price_micros' => null, 'cost' => 5200, 'stock' => 9, 'weight' => 6500, 'image_index' => 0, 'active' => true, 'position' => 0, 'values' => [['attribute_id' => 'a1', 'value_id' => '21']]],
                ['id' => '502', 'sku' => 'MESA-ROBLE-G', 'price' => 13140, 'stock' => 5, 'weight' => 8200, 'image_index' => 1, 'active' => true, 'position' => 1, 'values' => [['attribute_id' => 'a1', 'value_id' => '22']]],
            ],
            'related_ids' => ['102'], 'upsell_ids' => [], 'cross_sell_ids' => [], 'meta_title' => ['es' => 'Mesa auxiliar de roble macizo'], 'meta_description' => ['es' => 'Mesa auxiliar redonda de roble.'],
            'old_paths' => ['/mesas/101-mesa-auxiliar-roble.html'], 'extra' => ['ecotax' => 0, 'supplier_reference' => 'NRD-44'],
        ],
        'customers' => [
            'id' => '77', 'created_at' => '2024-05-02T16:20:00Z', 'email' => 'ana@cliente.test', 'first_name' => 'Ana', 'last_name' => 'Ruiz Peña', 'company' => '', 'vat_id' => '', 'phone' => '999000001',
            'group_id' => '3', 'locale' => 'es', 'active' => true, 'guest' => false, 'marketing' => true, 'marketing_at' => '2024-05-02T16:20:00Z',
            'password_hash' => '$2y$10$abcdefghijklmnopqrstuuJ9Qe1h0sO9dJ3m1uQq7hYw9vJmVvF0S', 'password_scheme' => 'bcrypt', 'notes' => '', 'last_login_at' => null,
            'addresses' => [$address], 'consents' => [['kind' => 'marketing', 'granted' => true, 'at' => '2024-05-02T16:20:00Z', 'context' => 'registro']],
        ],
        'coupons' => ['id' => '8', 'code' => 'OTOÑO10', 'kind' => 'percent', 'percent' => 1000, 'amount' => 0, 'min_subtotal' => 5000, 'starts_at' => '2026-09-21T00:00:00Z', 'ends_at' => '2026-12-21T00:00:00Z', 'max_uses' => 200, 'max_uses_per_customer' => 1, 'uses' => 37, 'first_order_only' => false, 'exclude_sale' => true, 'combinable' => false, 'customer_id' => null, 'product_ids' => [], 'category_ids' => ['12'], 'active' => true, 'note' => 'Campaña de otoño', 'unsupported' => []],
        'orders' => [
            'id' => '9001', 'updated_at' => '2026-10-03T09:00:00Z', 'number' => 'P001042', 'customer_id' => '77', 'email' => 'ana@cliente.test', 'locale' => 'es', 'currency' => 'EUR', 'state' => '4', 'state_meaning' => 'shipped',
            'payment_method' => 'redsys', 'payment_title' => 'Tarjeta', 'carrier_id' => '4', 'carrier_title' => 'Mensajería 24 h', 'tracking_code' => 'TRK123',
            'prices_include_tax' => false, 'items_total' => 10661, 'discount_total' => 0, 'shipping_total' => 590, 'fee_total' => 0, 'total_net' => 11251, 'total_tax' => 2363, 'total' => 13614, 'paid_total' => 13614, 'refunded_total' => 0,
            'taxes' => [['name' => 'IVA 21 %', 'rate' => 2100, 'base' => 11251, 'amount' => 2363]], 'billing' => $address, 'shipping' => $address, 'customer_note' => 'Llamar antes de entregar',
            'placed_at' => '2026-10-01T10:15:00Z', 'paid_at' => '2026-10-01T10:16:00Z', 'shipped_at' => '2026-10-02T08:00:00Z', 'completed_at' => null, 'cancelled_at' => null,
            'items' => [
                ['kind' => 'product', 'product_id' => '101', 'variant_id' => '501', 'sku' => 'MESA-ROBLE-P', 'name' => 'Mesa auxiliar de roble', 'variant_label' => 'Tamaño: Pequeña', 'qty' => 1, 'unit_price' => 10661, 'discount' => 0, 'line_total' => 10661, 'line_net' => 10661, 'line_tax' => 2239, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %', 'weight' => 6500, 'is_digital' => false, 'refunded_qty' => 0, 'refunded_total' => 0],
                ['kind' => 'shipping', 'name' => 'Mensajería 24 h', 'qty' => 1, 'unit_price' => 590, 'line_total' => 590, 'line_net' => 590, 'line_tax' => 124, 'tax_rate' => 2100],
            ],
            'payments' => [['method' => 'redsys', 'kind' => 'charge', 'reference' => '000001042', 'amount' => 13614, 'status' => 'ok', 'at' => '2026-10-01T10:16:00Z']],
            'refunds' => [], 'history' => [['at' => '2026-10-01T10:16:00Z', 'kind' => 'state', 'from' => '1', 'to' => '2', 'note' => '', 'public' => true, 'actor' => 'sistema'], ['at' => '2026-10-02T08:00:00Z', 'kind' => 'state', 'from' => '2', 'to' => '4']],
            'invoices' => [['number' => 'F2026-000317', 'date' => '2026-10-01T10:16:00Z', 'kind' => 'invoice']], 'coupons' => [],
        ],
        'reviews' => ['id' => '15', 'product_id' => '101', 'customer_id' => '77', 'author' => 'Ana R.', 'rating' => 5, 'title' => 'Muy bien acabada', 'body' => 'Llegó en dos días y sin un golpe.', 'status' => 'approved', 'verified' => true, 'at' => '2026-10-10T18:00:00Z', 'locale' => 'es'],
        'media' => ['id' => '310', 'url' => 'https://tienda.test/wp-content/uploads/2026/03/taller.jpg', 'alt' => ['es' => 'El taller'], 'title' => 'taller', 'mime' => 'image/jpeg', 'old_paths' => ['/wp-content/uploads/2026/03/taller.jpg']],
        'pages' => ['id' => '7', 'title' => ['es' => 'Política de privacidad'], 'slug' => ['es' => 'politica-de-privacidad'], 'content' => ['es' => '<h2>Quién trata tus datos</h2><p>Ñandú Muebles S.L.</p>'], 'content_format' => 'html', 'status' => 'published', 'parent_id' => null, 'published_at' => '2024-01-10T09:00:00Z', 'legal' => 'privacy', 'old_paths' => ['/content/7-politica-de-privacidad']],
        'post_categories' => ['id' => '4', 'name' => ['es' => 'Cuidados'], 'slug' => ['es' => 'cuidados'], 'parent_id' => null, 'old_paths' => ['/category/cuidados/']],
        'posts' => ['id' => '230', 'title' => ['es' => 'Cómo cuidar una mesa de roble'], 'slug' => ['es' => 'como-cuidar-una-mesa-de-roble'], 'excerpt' => ['es' => 'Tres cosas que alargan su vida.'], 'content' => ['es' => "<!-- wp:paragraph -->\n<p>Un paño húmedo y nada de sol directo.</p>\n<!-- /wp:paragraph -->"], 'content_format' => 'gutenberg', 'status' => 'published', 'category_ids' => ['4'], 'tags' => ['es' => ['roble']], 'image' => ['url' => 'https://tienda.test/wp-content/uploads/2026/03/taller.jpg'], 'author' => 'Marta', 'published_at' => '2026-03-04T07:30:00Z', 'old_paths' => ['/como-cuidar-una-mesa-de-roble/']],
        'menus' => ['id' => 'main', 'location' => 'main', 'name' => 'Principal', 'items' => [['label' => ['es' => 'Mesas'], 'type' => 'category', 'ref' => '12', 'children' => [['label' => ['es' => 'Blog'], 'type' => 'url', 'url' => '/blog/']]], ['label' => ['es' => 'Privacidad'], 'type' => 'page', 'ref' => '7']]],
        'redirects' => ['id' => '5', 'source' => '/mesas-antiguas/', 'target' => '/mesas/', 'code' => 301, 'active' => true],
        'team' => ['id' => '2', 'email' => 'marta@tienda.test', 'name' => 'Marta Gil', 'role' => 'Logística', 'role_meaning' => 'manager', 'active' => true, 'locale' => 'es', 'last_login_at' => '2026-10-30T07:00:00Z'],
        'subscribers' => ['id' => '40', 'email' => 'luis@cliente.test', 'name' => 'Luis', 'granted_at' => '2025-06-01T12:00:00Z', 'source' => 'pie de página', 'confirmed' => true, 'locale' => 'es'],
    ];
    if (!isset($samples[$type])) {
        throw new InvalidArgumentException('No sample for ' . $type);
    }

    return $samples[$type];
}
