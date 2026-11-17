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
 * Las tablas de una copia de la base de datos de WordPress con WooCommerce, con las columnas reales que lee el lector y
 * tipos corrientes (en las pruebas la copia es SQLite). Las de usuarios van con el prefijo base y las demás con el del sitio
 * (en un multisitio, «wp_» y «wp_2_»).
 *
 * @return string[] sentencias CREATE TABLE
 */
function wooSchema(string $site, string $base = ''): array
{
    $base = $base === '' ? $site : $base;

    return [
        'CREATE TABLE ' . $base . 'users (ID INTEGER PRIMARY KEY, user_login TEXT, user_pass TEXT, user_nicename TEXT, user_email TEXT, user_registered TEXT, display_name TEXT)',
        'CREATE TABLE ' . $base . 'usermeta (umeta_id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, meta_key TEXT, meta_value TEXT)',
        'CREATE TABLE ' . $site . 'options (option_id INTEGER PRIMARY KEY AUTOINCREMENT, option_name TEXT, option_value TEXT, autoload TEXT)',
        'CREATE TABLE ' . $site . 'posts (ID INTEGER PRIMARY KEY, post_author INTEGER, post_date TEXT, post_date_gmt TEXT, post_content TEXT, post_title TEXT, post_excerpt TEXT, '
        . 'post_status TEXT, comment_status TEXT, post_name TEXT, post_modified TEXT, post_modified_gmt TEXT, post_parent INTEGER, menu_order INTEGER, post_type TEXT, post_mime_type TEXT)',
        'CREATE TABLE ' . $site . 'postmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, post_id INTEGER, meta_key TEXT, meta_value TEXT)',
        'CREATE TABLE ' . $site . 'terms (term_id INTEGER PRIMARY KEY, name TEXT, slug TEXT, term_group INTEGER)',
        'CREATE TABLE ' . $site . 'term_taxonomy (term_taxonomy_id INTEGER PRIMARY KEY, term_id INTEGER, taxonomy TEXT, description TEXT, parent INTEGER, count INTEGER)',
        'CREATE TABLE ' . $site . 'term_relationships (object_id INTEGER, term_taxonomy_id INTEGER, term_order INTEGER)',
        'CREATE TABLE ' . $site . 'termmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, term_id INTEGER, meta_key TEXT, meta_value TEXT)',
        'CREATE TABLE ' . $site . 'comments (comment_ID INTEGER PRIMARY KEY, comment_post_ID INTEGER, comment_author TEXT, comment_author_email TEXT, comment_date TEXT, comment_date_gmt TEXT, '
        . 'comment_content TEXT, comment_approved TEXT, comment_type TEXT, user_id INTEGER)',
        'CREATE TABLE ' . $site . 'commentmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, comment_id INTEGER, meta_key TEXT, meta_value TEXT)',
        'CREATE TABLE ' . $site . 'woocommerce_attribute_taxonomies (attribute_id INTEGER PRIMARY KEY, attribute_name TEXT, attribute_label TEXT, attribute_type TEXT, attribute_orderby TEXT, attribute_public INTEGER)',
        'CREATE TABLE ' . $site . 'wc_tax_rate_classes (tax_rate_class_id INTEGER PRIMARY KEY, name TEXT, slug TEXT)',
        'CREATE TABLE ' . $site . 'woocommerce_tax_rates (tax_rate_id INTEGER PRIMARY KEY, tax_rate_country TEXT, tax_rate_state TEXT, tax_rate TEXT, tax_rate_name TEXT, tax_rate_priority INTEGER, '
        . 'tax_rate_compound INTEGER, tax_rate_shipping INTEGER, tax_rate_order INTEGER, tax_rate_class TEXT)',
        'CREATE TABLE ' . $site . 'woocommerce_tax_rate_locations (location_id INTEGER PRIMARY KEY AUTOINCREMENT, location_code TEXT, tax_rate_id INTEGER, location_type TEXT)',
        'CREATE TABLE ' . $site . 'woocommerce_shipping_zones (zone_id INTEGER PRIMARY KEY, zone_name TEXT, zone_order INTEGER)',
        'CREATE TABLE ' . $site . 'woocommerce_shipping_zone_locations (location_id INTEGER PRIMARY KEY AUTOINCREMENT, zone_id INTEGER, location_code TEXT, location_type TEXT)',
        'CREATE TABLE ' . $site . 'woocommerce_shipping_zone_methods (zone_id INTEGER, instance_id INTEGER PRIMARY KEY, method_id TEXT, method_order INTEGER, is_enabled INTEGER)',
        'CREATE TABLE ' . $site . 'woocommerce_order_items (order_item_id INTEGER PRIMARY KEY, order_item_name TEXT, order_item_type TEXT, order_id INTEGER)',
        'CREATE TABLE ' . $site . 'woocommerce_order_itemmeta (meta_id INTEGER PRIMARY KEY AUTOINCREMENT, order_item_id INTEGER, meta_key TEXT, meta_value TEXT)',
        'CREATE TABLE ' . $site . 'wc_orders (id INTEGER PRIMARY KEY, status TEXT, currency TEXT, type TEXT, tax_amount TEXT, total_amount TEXT, customer_id INTEGER, billing_email TEXT, '
        . 'date_created_gmt TEXT, date_updated_gmt TEXT, parent_order_id INTEGER, payment_method TEXT, payment_method_title TEXT, transaction_id TEXT, ip_address TEXT, user_agent TEXT, customer_note TEXT)',
        'CREATE TABLE ' . $site . 'wc_order_addresses (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, address_type TEXT, first_name TEXT, last_name TEXT, company TEXT, address_1 TEXT, '
        . 'address_2 TEXT, city TEXT, state TEXT, postcode TEXT, country TEXT, email TEXT, phone TEXT)',
        'CREATE TABLE ' . $site . 'wc_order_operational_data (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, created_via TEXT, woocommerce_version TEXT, prices_include_tax INTEGER, '
        . 'cart_hash TEXT, order_key TEXT, date_paid_gmt TEXT, date_completed_gmt TEXT, shipping_tax_amount TEXT, shipping_total_amount TEXT, discount_total_amount TEXT)',
        'CREATE TABLE ' . $site . 'wc_orders_meta (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, meta_key TEXT, meta_value TEXT)',
        'CREATE TABLE ' . $site . 'wc_product_meta_lookup (product_id INTEGER PRIMARY KEY, sku TEXT, virtual INTEGER, downloadable INTEGER, min_price TEXT, max_price TEXT, onsale INTEGER, '
        . 'stock_quantity TEXT, stock_status TEXT, total_sales INTEGER, tax_class TEXT)',
        'CREATE TABLE ' . $site . 'redirection_items (id INTEGER PRIMARY KEY, url TEXT, regex INTEGER, action_type TEXT, action_code INTEGER, action_data TEXT, status TEXT)',
        'CREATE TABLE ' . $site . 'rank_math_redirections (id INTEGER PRIMARY KEY, sources TEXT, url_to TEXT, header_code INTEGER, status TEXT)',
    ];
}
