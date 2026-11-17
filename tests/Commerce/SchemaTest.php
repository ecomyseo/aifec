<?php
/**
 * AIFEC — el esquema del comercio se crea entero y con sus datos de serie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

test('el esquema del comercio: todas las tablas, sus claves y los datos de serie', function (): void {
    $app = testApp(['modules' => ['orders']]);
    $app->boot();
    $db = $app->db();
    $loaded = $app->modules()->loaded();
    sort($loaded);
    same(['catalog', 'coupons', 'customers', 'orders', 'shipping', 'tax'], $loaded, 'pedir «orders» carga también lo que necesita, y nada más');
    same([], $app->modules()->failed());

    $tables = [
        'products', 'product_i18n', 'variants', 'variant_values', 'attributes', 'attribute_i18n', 'attribute_values', 'attribute_value_i18n', 'families',
        'family_attributes', 'product_attributes', 'categories', 'category_i18n', 'product_categories', 'brands', 'tags', 'product_tags', 'product_relations',
        'product_faqs', 'product_files', 'product_media', 'customers', 'addresses', 'customer_groups', 'consents', 'withdrawals', 'tax_classes', 'tax_rates',
        'shipping_zones', 'zone_locations', 'carriers', 'carrier_rates', 'coupons', 'coupon_uses', 'sequences', 'carts', 'orders', 'order_items',
        'order_history', 'order_payments', 'order_refunds', 'order_downloads', 'order_states',
    ];
    foreach ($tables as $table) {
        check($db->tableExists($table), 'existe la tabla ' . $table);
    }

    same(4, (int) $db->value('SELECT COUNT(*) FROM {tax_classes}'));
    same(2100, (int) $db->value("SELECT r.rate FROM {tax_rates} r JOIN {tax_classes} c ON c.id = r.tax_class_id WHERE c.code = 'standard' AND r.postcodes = ''"));
    same(700, (int) $db->value("SELECT r.rate FROM {tax_rates} r JOIN {tax_classes} c ON c.id = r.tax_class_id WHERE c.code = 'standard' AND r.postcodes = '35,38'"));
    same(8, (int) $db->value('SELECT COUNT(*) FROM {order_states}'));
    same('open', $db->value("SELECT kind FROM {order_states} WHERE code = 'pending_payment'"));
    same(1, (int) $db->value('SELECT COUNT(*) FROM {customer_groups} WHERE is_default = 1'));

    // Las claves únicas que sostienen la idempotencia.
    $now = $app->clock()->sql();
    $db->insert('order_payments', ['order_id' => 1, 'method' => 'redsys', 'kind' => 'charge', 'reference' => 'R1', 'amount' => 100, 'created_at' => $now]);
    same(false, $db->insertIgnore('order_payments', ['order_id' => 1, 'method' => 'redsys', 'kind' => 'charge', 'reference' => 'R1', 'amount' => 100, 'created_at' => $now]), 'el mismo aviso de la pasarela no se apunta dos veces');
    same(true, $db->insertIgnore('order_payments', ['order_id' => 1, 'method' => 'redsys', 'kind' => 'refund', 'reference' => 'R1', 'amount' => 100, 'created_at' => $now]));
    $db->insert('product_i18n', ['product_id' => 1, 'lang' => 'es', 'name' => 'Silla']);
    same(false, $db->insertIgnore('product_i18n', ['product_id' => 1, 'lang' => 'es', 'name' => 'Otra']));
    $db->insert('customers', ['email' => 'a@b.es', 'created_at' => $now, 'updated_at' => $now]);
    same(false, $db->insertIgnore('customers', ['email' => 'a@b.es', 'created_at' => $now, 'updated_at' => $now]));

    // Sin el módulo, sus tablas no existen: cada sitio solo tiene lo que usa.
    $bare = testApp();
    $bare->boot();
    same(false, $bare->db()->tableExists('products'));
});
