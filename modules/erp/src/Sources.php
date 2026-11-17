<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Kernel\App;

/**
 * De dónde salen los datos de una exportación: los campos que se pueden elegir de cada tipo y la lectura por tandas.
 *
 * Tipos de campo: text, int, money (céntimos), rate (centésimas de punto), date («Y-m-d H:i:s» UTC) y bool.
 *
 * Tres formas de leer (la «ventana»):
 *   cursor  solo lo nuevo o cambiado desde una marca «<fecha>|<id>» (se avanza por lo ENTREGADO: next es la marca de la última fila leída)
 *   range   un intervalo de fechas a mano (next es el último id leído)
 *   all     todo, por orden de id (next es el último id leído)
 */
final class Sources
{
    public const KINDS = ['orders' => 'Orders (one row each)', 'order_lines' => 'Orders (one row per line)', 'customers' => 'Customers',
        'products' => 'Products and variants', 'stock' => 'Stock', 'invoices' => 'Invoices and credit notes'];

    /** Marca de «todo lo que ya existe»: el id más alto posible, para que una fecha igual no cuente como nueva. */
    public const MAX_ID = 9007199254740991;

    private const TABLES = [
        'orders' => ['table' => 'orders', 'ts' => 'updated_at', 'range' => 'placed_at'],
        'order_lines' => ['table' => 'orders', 'ts' => 'updated_at', 'range' => 'placed_at'],
        'customers' => ['table' => 'customers', 'ts' => 'updated_at', 'range' => 'created_at'],
        'products' => ['table' => 'products', 'ts' => 'updated_at', 'range' => 'updated_at'],
        'stock' => ['table' => 'products', 'ts' => 'updated_at', 'range' => 'updated_at'],
        'invoices' => ['table' => 'invoices', 'ts' => 'issued_at', 'range' => 'issued_at'],
    ];

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Los campos que se pueden elegir de un tipo: código => [etiqueta, tipo].
     *
     * @return array<string,array{0:string,1:string}>
     */
    public static function fields(string $kind): array
    {
        $order = [
            'number' => ['Order number', 'text'], 'status' => ['Status', 'text'], 'payment_status' => ['Payment status', 'text'], 'fulfillment_status' => ['Fulfilment status', 'text'],
            'placed_at' => ['Placed at', 'date'], 'paid_at' => ['Paid at', 'date'], 'shipped_at' => ['Shipped at', 'date'], 'updated_at' => ['Updated at', 'date'],
            'currency' => ['Currency', 'text'], 'email' => ['Customer email', 'text'], 'customer_id' => ['Customer id', 'int'], 'customer_name' => ['Customer name', 'text'],
            'payment_method' => ['Payment method', 'text'], 'payment_title' => ['Payment method name', 'text'], 'carrier_title' => ['Carrier', 'text'],
            'tracking_code' => ['Tracking number', 'text'], 'source' => ['Source', 'text'], 'test' => ['Test order', 'bool'],
        ];
        $address = static function (string $prefix, string $label): array {
            $out = [];
            foreach (['name' => 'name', 'company' => 'company', 'vat_id' => 'tax ID', 'phone' => 'phone', 'line1' => 'address', 'line2' => 'address line 2',
                'postcode' => 'postcode', 'city' => 'city', 'region' => 'province', 'country' => 'country'] as $key => $text) {
                $out[$prefix . $key] = [$label . ' ' . $text, 'text'];
            }

            return $out;
        };
        switch ($kind) {
            case 'orders':
                return $order + [
                    'items_count' => ['Units', 'int'], 'items_total' => ['Items total', 'money'], 'discount_total' => ['Discounts', 'money'], 'shipping_total' => ['Shipping', 'money'],
                    'fee_total' => ['Fees', 'money'], 'total_net' => ['Total without tax', 'money'], 'total_tax' => ['Tax', 'money'], 'total' => ['Total', 'money'],
                    'paid_total' => ['Paid', 'money'], 'refunded_total' => ['Refunded', 'money'], 'coupon_codes' => ['Coupons', 'text'], 'customer_note' => ['Customer note', 'text'],
                ] + $address('billing_', 'Billing') + $address('shipping_', 'Shipping');
            case 'order_lines':
                return $order + [
                    'line_position' => ['Line number', 'int'], 'line_kind' => ['Line kind', 'text'], 'line_sku' => ['SKU', 'text'], 'line_name' => ['Description', 'text'],
                    'line_variant' => ['Variant', 'text'], 'line_qty' => ['Quantity', 'int'], 'line_unit_price' => ['Unit price', 'money'], 'line_discount' => ['Line discount', 'money'],
                    'line_total' => ['Line total', 'money'], 'line_net' => ['Line without tax', 'money'], 'line_tax' => ['Line tax', 'money'], 'line_tax_rate' => ['Tax rate', 'rate'],
                    'line_tax_name' => ['Tax name', 'text'], 'line_is_digital' => ['Digital', 'bool'],
                ] + $address('billing_', 'Billing') + $address('shipping_', 'Shipping');
            case 'customers':
                return [
                    'id' => ['Id', 'int'], 'email' => ['Email', 'text'], 'first_name' => ['First name', 'text'], 'last_name' => ['Last name', 'text'], 'company' => ['Company', 'text'],
                    'vat_id' => ['Tax ID', 'text'], 'phone' => ['Phone', 'text'], 'locale' => ['Language', 'text'], 'guest' => ['Guest', 'bool'], 'active' => ['Active', 'bool'],
                    'marketing' => ['Accepts marketing', 'bool'], 'orders_count' => ['Orders', 'int'], 'total_spent' => ['Total spent', 'money'], 'created_at' => ['Created at', 'date'],
                    'updated_at' => ['Updated at', 'date'], 'address_line1' => ['Address', 'text'], 'address_line2' => ['Address line 2', 'text'], 'address_postcode' => ['Postcode', 'text'],
                    'address_city' => ['City', 'text'], 'address_region' => ['Province', 'text'], 'address_country' => ['Country', 'text'],
                ];
            case 'products':
                return [
                    'product_id' => ['Product id', 'int'], 'variant_id' => ['Variant id', 'int'], 'sku' => ['SKU', 'text'], 'parent_sku' => ['Product SKU (for variants)', 'text'],
                    'name' => ['Name', 'text'], 'kind' => ['Kind', 'text'], 'status' => ['Status', 'text'], 'gtin' => ['Barcode (GTIN)', 'text'], 'mpn' => ['MPN', 'text'],
                    'price' => ['Price', 'money'], 'compare_price' => ['Previous price', 'money'], 'sale_price' => ['Offer price', 'money'], 'sale_from' => ['Offer from', 'date'],
                    'sale_to' => ['Offer until', 'date'], 'tax_class' => ['Tax class', 'text'], 'stock' => ['Stock', 'int'], 'manage_stock' => ['Controls stock', 'bool'],
                    'weight' => ['Weight (g)', 'int'], 'updated_at' => ['Updated at', 'date'],
                ];
            case 'stock':
                return [
                    'sku' => ['SKU', 'text'], 'product_id' => ['Product id', 'int'], 'variant_id' => ['Variant id', 'int'], 'name' => ['Name', 'text'], 'stock' => ['Stock', 'int'],
                    'manage_stock' => ['Controls stock', 'bool'], 'backorders' => ['Accepts orders without stock', 'text'], 'updated_at' => ['Updated at', 'date'],
                ];
            case 'invoices':
                return [
                    'number' => ['Invoice number', 'text'], 'type' => ['Type', 'text'], 'issued_at' => ['Issued at', 'date'], 'order_number' => ['Order number', 'text'], 'currency' => ['Currency', 'text'],
                    'net' => ['Taxable base', 'money'], 'tax' => ['Tax', 'money'], 'total' => ['Total', 'money'], 'test' => ['Test', 'bool'], 'receiver_name' => ['Customer name', 'text'],
                    'receiver_vat_id' => ['Customer tax ID', 'text'], 'receiver_country' => ['Customer country', 'text'],
                ];
        }

        return [];
    }

    /**
     * Las columnas de un perfil nuevo: todos los campos del tipo, con su nombre.
     *
     * @return array<int,array<string,string>>
     */
    public static function defaultColumns(string $kind): array
    {
        $columns = [];
        foreach (self::fields($kind) as $code => $def) {
            $columns[] = ['field' => $code, 'header' => $code, 'value' => ''];
        }

        return $columns;
    }

    /**
     * Una tanda de filas.
     *
     * @param array<string,mixed> $filter statuses (pedidos)
     * @param array<string,mixed> $window type (cursor|range|all) y sus datos: value; from, to, after; after
     *
     * @return array{rows:array<int,array<string,mixed>>,next:?string,scanned:int} next = marca de la última fila leída (null si no hay filas)
     */
    public function fetch(string $kind, array $filter, array $window, int $limit): array
    {
        if (!isset(self::TABLES[$kind])) {
            throw new \InvalidArgumentException('Unknown kind');
        }
        if ($kind === 'stock' && $window['type'] === 'cursor') {
            return $this->stockMoves(isset($window['value']) ? (string) $window['value'] : '', $limit);
        }
        $def = self::TABLES[$kind];
        $db = $this->app->db();
        $table = $def['table'];
        $sql = 'SELECT * FROM {' . $table . '} WHERE 1 = 1';
        $args = [];
        if (in_array($kind, ['orders', 'order_lines'], true) && isset($filter['statuses']) && is_array($filter['statuses']) && $filter['statuses'] !== []) {
            $sql .= ' AND status IN (' . $db->in(array_map('strval', $filter['statuses']), $args) . ')';
        }
        $order = ' ORDER BY id ASC';
        if ($window['type'] === 'cursor') {
            list($at, $id) = self::parseCursor(isset($window['value']) ? (string) $window['value'] : '');
            $sql .= ' AND (' . $def['ts'] . ' > ? OR (' . $def['ts'] . ' = ? AND id > ?))';
            array_push($args, $at, $at, $id);
            $order = ' ORDER BY ' . $def['ts'] . ' ASC, id ASC';
        } else {
            $sql .= ' AND id > ?';
            $args[] = (int) (isset($window['after']) ? $window['after'] : 0);
            if ($window['type'] === 'range') {
                if (!empty($window['from'])) {
                    $sql .= ' AND ' . $def['range'] . ' >= ?';
                    $args[] = (string) $window['from'];
                }
                if (!empty($window['to'])) {
                    $sql .= ' AND ' . $def['range'] . ' <= ?';
                    $args[] = (string) $window['to'];
                }
            }
        }
        $args[] = $limit;
        $rows = $db->select($sql . $order . ' LIMIT ?', $args);
        if ($rows === []) {
            return ['rows' => [], 'next' => null, 'scanned' => 0];
        }
        $last = $rows[count($rows) - 1];
        $next = $window['type'] === 'cursor' ? (string) $last[$def['ts']] . '|' . (int) $last['id'] : (string) (int) $last['id'];

        return ['rows' => $this->build($kind, $rows), 'next' => $next, 'scanned' => count($rows)];
    }

    /**
     * Cuántos registros entrarían en un intervalo (para que el comerciante lo confirme viendo la cifra).
     *
     * @param array<string,mixed> $filter
     */
    public function countRange(string $kind, array $filter, ?string $from, ?string $to): int
    {
        if (!isset(self::TABLES[$kind])) {
            return 0;
        }
        $def = self::TABLES[$kind];
        $db = $this->app->db();
        $sql = 'SELECT COUNT(*) FROM {' . $def['table'] . '} WHERE 1 = 1';
        $args = [];
        if (in_array($kind, ['orders', 'order_lines'], true) && isset($filter['statuses']) && is_array($filter['statuses']) && $filter['statuses'] !== []) {
            $sql .= ' AND status IN (' . $db->in(array_map('strval', $filter['statuses']), $args) . ')';
        }
        if ($from !== null) {
            $sql .= ' AND ' . $def['range'] . ' >= ?';
            $args[] = $from;
        }
        if ($to !== null) {
            $sql .= ' AND ' . $def['range'] . ' <= ?';
            $args[] = $to;
        }

        return (int) $db->value($sql, $args);
    }

    /**
     * La marca de «ahora»: lo que ya existe no cuenta como nuevo.
     */
    public function nowCursor(string $kind): string
    {
        return $this->app->clock()->sql() . '|' . self::MAX_ID;
    }

    /**
     * @return array{0:string,1:int}
     */
    public static function parseCursor(string $value): array
    {
        $parts = explode('|', $value);

        return [isset($parts[0]) && $parts[0] !== '' ? $parts[0] : '1970-01-01 00:00:00', isset($parts[1]) && ctype_digit($parts[1]) ? (int) $parts[1] : 0];
    }

    // ------------------------------------------------------------------ construir las filas

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function build(string $kind, array $rows): array
    {
        switch ($kind) {
            case 'orders':
            case 'order_lines':
                return $this->orders($rows, $kind === 'order_lines');
            case 'customers':
                return $this->customers($rows);
            case 'products':
                return $this->products($rows);
            case 'stock':
                return $this->stockRows($rows);
            case 'invoices':
                return $this->invoices($rows);
        }

        return [];
    }

    /**
     * @param array<int,array<string,mixed>> $orders
     *
     * @return array<int,array<string,mixed>>
     */
    private function orders(array $orders, bool $lines): array
    {
        $db = $this->app->db();
        $ids = array_map(static function (array $o): int {
            return (int) $o['id'];
        }, $orders);
        $args = [];
        $in = $db->in($ids, $args);
        $units = [];
        $items = [];
        if ($lines) {
            foreach ($db->select('SELECT * FROM {order_items} WHERE order_id IN (' . $in . ') ORDER BY order_id, position, id', $args) as $item) {
                $items[(int) $item['order_id']][] = $item;
            }
        } else {
            foreach ($db->select("SELECT order_id, SUM(qty) AS n FROM {order_items} WHERE kind = 'product' AND order_id IN (" . $in . ') GROUP BY order_id', $args) as $row) {
                $units[(int) $row['order_id']] = (int) $row['n'];
            }
        }
        $customers = [];
        $customerIds = array_values(array_unique(array_filter(array_map(static function (array $o): int {
            return $o['customer_id'] !== null ? (int) $o['customer_id'] : 0;
        }, $orders))));
        if ($customerIds !== []) {
            $cargs = [];
            foreach ($db->select('SELECT id, first_name, last_name FROM {customers} WHERE id IN (' . $db->in($customerIds, $cargs) . ')', $cargs) as $c) {
                $customers[(int) $c['id']] = trim((string) $c['first_name'] . ' ' . (string) $c['last_name']);
            }
        }
        $out = [];
        foreach ($orders as $o) {
            $billing = json_decode((string) $o['billing'], true);
            $shipping = json_decode((string) $o['shipping'], true);
            $billing = is_array($billing) ? $billing : [];
            $shipping = is_array($shipping) && $shipping !== [] ? $shipping : $billing;
            $coupons = json_decode((string) $o['coupons'], true);
            $codes = [];
            foreach (is_array($coupons) ? $coupons : [] as $coupon) {
                if (is_array($coupon) && isset($coupon['code'])) {
                    $codes[] = (string) $coupon['code'];
                }
            }
            $cid = $o['customer_id'] !== null ? (int) $o['customer_id'] : null;
            $base = [
                'number' => (string) $o['number'], 'status' => (string) $o['status'], 'payment_status' => (string) $o['payment_status'], 'fulfillment_status' => (string) $o['fulfillment_status'],
                'placed_at' => $o['placed_at'], 'paid_at' => $o['paid_at'], 'shipped_at' => $o['shipped_at'], 'updated_at' => $o['updated_at'], 'currency' => (string) $o['currency'],
                'email' => (string) $o['email'], 'customer_id' => $cid, 'customer_name' => $cid !== null && isset($customers[$cid]) ? $customers[$cid] : self::name($billing),
                'payment_method' => $o['payment_method'], 'payment_title' => $o['payment_title'], 'carrier_title' => $o['carrier_title'], 'tracking_code' => $o['tracking_code'],
                'source' => (string) $o['source'], 'test' => (int) $o['test'],
            ] + self::addressFields('billing_', $billing) + self::addressFields('shipping_', $shipping);
            if (!$lines) {
                $out[] = $base + [
                    'items_count' => isset($units[(int) $o['id']]) ? $units[(int) $o['id']] : 0, 'items_total' => (int) $o['items_total'], 'discount_total' => (int) $o['discount_total'],
                    'shipping_total' => (int) $o['shipping_total'], 'fee_total' => (int) $o['fee_total'], 'total_net' => (int) $o['total_net'], 'total_tax' => (int) $o['total_tax'],
                    'total' => (int) $o['total'], 'paid_total' => (int) $o['paid_total'], 'refunded_total' => (int) $o['refunded_total'], 'coupon_codes' => implode(' ', $codes),
                    'customer_note' => $o['customer_note'],
                ];
                continue;
            }
            foreach (isset($items[(int) $o['id']]) ? $items[(int) $o['id']] : [] as $item) {
                $out[] = $base + [
                    'line_position' => (int) $item['position'], 'line_kind' => (string) $item['kind'], 'line_sku' => $item['sku'], 'line_name' => (string) $item['name'],
                    'line_variant' => $item['variant_label'], 'line_qty' => (int) $item['qty'], 'line_unit_price' => (int) $item['unit_price'], 'line_discount' => (int) $item['discount'],
                    'line_total' => (int) $item['line_total'], 'line_net' => (int) $item['line_net'], 'line_tax' => (int) $item['line_tax'], 'line_tax_rate' => (int) $item['tax_rate'],
                    'line_tax_name' => $item['tax_name'], 'line_is_digital' => (int) $item['is_digital'],
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function customers(array $rows): array
    {
        $db = $this->app->db();
        $ids = array_map(static function (array $r): int {
            return (int) $r['id'];
        }, $rows);
        $args = [];
        $addresses = [];
        foreach ($db->select('SELECT * FROM {addresses} WHERE customer_id IN (' . $db->in($ids, $args) . ') ORDER BY customer_id, default_billing DESC, default_shipping DESC, id', $args) as $a) {
            if (!isset($addresses[(int) $a['customer_id']])) {
                $addresses[(int) $a['customer_id']] = $a;
            }
        }
        $out = [];
        foreach ($rows as $r) {
            $a = isset($addresses[(int) $r['id']]) ? $addresses[(int) $r['id']] : [];
            $out[] = [
                'id' => (int) $r['id'], 'email' => (string) $r['email'], 'first_name' => $r['first_name'], 'last_name' => $r['last_name'], 'company' => $r['company'],
                'vat_id' => $r['vat_id'], 'phone' => $r['phone'], 'locale' => $r['locale'], 'guest' => (int) $r['guest'], 'active' => (int) $r['active'], 'marketing' => (int) $r['marketing'],
                'orders_count' => (int) $r['orders_count'], 'total_spent' => (int) $r['total_spent'], 'created_at' => $r['created_at'], 'updated_at' => $r['updated_at'],
                'address_line1' => isset($a['line1']) ? $a['line1'] : null, 'address_line2' => isset($a['line2']) ? $a['line2'] : null, 'address_postcode' => isset($a['postcode']) ? $a['postcode'] : null,
                'address_city' => isset($a['city']) ? $a['city'] : null, 'address_region' => isset($a['region']) ? $a['region'] : null, 'address_country' => isset($a['country']) ? $a['country'] : null,
            ];
        }

        return $out;
    }

    /**
     * Un producto = una fila; si tiene variantes, además una fila por variante.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function products(array $rows): array
    {
        $db = $this->app->db();
        $names = $this->names($rows);
        $codes = [];
        if ($db->tableExists('tax_classes')) {
            foreach ($db->select('SELECT id, code FROM {tax_classes}') as $c) {
                $codes[(int) $c['id']] = (string) $c['code'];
            }
        }
        $ids = array_map(static function (array $r): int {
            return (int) $r['id'];
        }, $rows);
        $args = [];
        $variants = [];
        foreach ($db->select('SELECT * FROM {variants} WHERE product_id IN (' . $db->in($ids, $args) . ') ORDER BY product_id, position, id', $args) as $v) {
            $variants[(int) $v['product_id']][] = $v;
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $class = $r['tax_class_id'] !== null && isset($codes[(int) $r['tax_class_id']]) ? $codes[(int) $r['tax_class_id']] : null;
            $out[] = [
                'product_id' => $id, 'variant_id' => null, 'sku' => $r['sku'], 'parent_sku' => null, 'name' => isset($names[$id]) ? $names[$id] : null, 'kind' => (string) $r['kind'],
                'status' => (string) $r['status'], 'gtin' => $r['gtin'], 'mpn' => $r['mpn'], 'price' => (int) $r['price'], 'compare_price' => $r['compare_price'] !== null ? (int) $r['compare_price'] : null,
                'sale_price' => $r['sale_price'] !== null ? (int) $r['sale_price'] : null, 'sale_from' => $r['sale_from'], 'sale_to' => $r['sale_to'], 'tax_class' => $class,
                'stock' => (int) $r['has_variants'] === 1 ? null : (int) $r['stock'], 'manage_stock' => (int) $r['manage_stock'], 'weight' => (int) $r['weight'], 'updated_at' => $r['updated_at'],
            ];
            foreach (isset($variants[$id]) ? $variants[$id] : [] as $v) {
                $out[] = [
                    'product_id' => $id, 'variant_id' => (int) $v['id'], 'sku' => $v['sku'], 'parent_sku' => $r['sku'], 'name' => $v['label'], 'kind' => (string) $r['kind'],
                    'status' => (int) $v['active'] === 1 ? (string) $r['status'] : 'archived', 'gtin' => $v['gtin'], 'mpn' => null, 'price' => $v['price'] !== null ? (int) $v['price'] : (int) $r['price'],
                    'compare_price' => $v['compare_price'] !== null ? (int) $v['compare_price'] : null, 'sale_price' => null, 'sale_from' => null, 'sale_to' => null, 'tax_class' => $class,
                    'stock' => (int) $v['stock'], 'manage_stock' => (int) $r['manage_stock'], 'weight' => $v['weight'] !== null ? (int) $v['weight'] : (int) $r['weight'], 'updated_at' => $v['updated_at'],
                ];
            }
        }

        return $out;
    }

    /**
     * Existencias: una fila por producto sin variantes y una por variante.
     *
     * @param array<int,array<string,mixed>> $rows productos
     *
     * @return array<int,array<string,mixed>>
     */
    private function stockRows(array $rows): array
    {
        $names = $this->names($rows);
        $ids = array_map(static function (array $r): int {
            return (int) $r['id'];
        }, $rows);
        $args = [];
        $variants = [];
        foreach ($this->app->db()->select('SELECT * FROM {variants} WHERE product_id IN (' . $this->app->db()->in($ids, $args) . ') ORDER BY product_id, position, id', $args) as $v) {
            $variants[(int) $v['product_id']][] = $v;
        }
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            if ((int) $r['has_variants'] === 1) {
                foreach (isset($variants[$id]) ? $variants[$id] : [] as $v) {
                    $out[] = ['sku' => $v['sku'], 'product_id' => $id, 'variant_id' => (int) $v['id'], 'name' => isset($names[$id]) ? $names[$id] . ' / ' . (string) $v['label'] : $v['label'],
                        'stock' => (int) $v['stock'], 'manage_stock' => (int) $r['manage_stock'], 'backorders' => (string) $r['backorders'], 'updated_at' => $v['updated_at']];
                }
                continue;
            }
            $out[] = ['sku' => $r['sku'], 'product_id' => $id, 'variant_id' => null, 'name' => isset($names[$id]) ? $names[$id] : null, 'stock' => (int) $r['stock'],
                'manage_stock' => (int) $r['manage_stock'], 'backorders' => (string) $r['backorders'], 'updated_at' => $r['updated_at']];
        }

        return $out;
    }

    /**
     * «Solo lo nuevo» de las existencias: lo que se ha movido desde la marca, una fila por producto o variante (con su valor de ahora).
     *
     * @return array{rows:array<int,array<string,mixed>>,next:?string,scanned:int}
     */
    private function stockMoves(string $cursor, int $limit): array
    {
        $db = $this->app->db();
        list($at, $id) = self::parseCursor($cursor);
        $moves = $db->select('SELECT id, product_id, variant_id, created_at FROM {stock_moves} WHERE (created_at > ? OR (created_at = ? AND id > ?)) ORDER BY created_at ASC, id ASC LIMIT ?', [$at, $at, $id, $limit]);
        if ($moves === []) {
            return ['rows' => [], 'next' => null, 'scanned' => 0];
        }
        $seen = [];
        $productIds = [];
        foreach ($moves as $m) {
            $seen[(int) $m['product_id'] . ':' . (int) $m['variant_id']] = [(int) $m['product_id'], (int) $m['variant_id']];
            $productIds[(int) $m['product_id']] = true;
        }
        $args = [];
        $products = [];
        foreach ($db->select('SELECT * FROM {products} WHERE id IN (' . $db->in(array_keys($productIds), $args) . ')', $args) as $p) {
            $products[(int) $p['id']] = $p;
        }
        $names = $this->names(array_values($products));
        $out = [];
        foreach ($seen as $pair) {
            if (!isset($products[$pair[0]])) {
                continue;
            }
            $p = $products[$pair[0]];
            if ($pair[1] > 0) {
                $v = $db->row('SELECT * FROM {variants} WHERE id = ?', [$pair[1]]);
                if ($v === null) {
                    continue;
                }
                $out[] = ['sku' => $v['sku'], 'product_id' => $pair[0], 'variant_id' => $pair[1], 'name' => isset($names[$pair[0]]) ? $names[$pair[0]] . ' / ' . (string) $v['label'] : $v['label'],
                    'stock' => (int) $v['stock'], 'manage_stock' => (int) $p['manage_stock'], 'backorders' => (string) $p['backorders'], 'updated_at' => $v['updated_at']];
            } else {
                $out[] = ['sku' => $p['sku'], 'product_id' => $pair[0], 'variant_id' => null, 'name' => isset($names[$pair[0]]) ? $names[$pair[0]] : null, 'stock' => (int) $p['stock'],
                    'manage_stock' => (int) $p['manage_stock'], 'backorders' => (string) $p['backorders'], 'updated_at' => $p['updated_at']];
            }
        }
        $last = $moves[count($moves) - 1];

        return ['rows' => $out, 'next' => (string) $last['created_at'] . '|' . (int) $last['id'], 'scanned' => count($moves)];
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function invoices(array $rows): array
    {
        $db = $this->app->db();
        $ids = array_values(array_unique(array_map(static function (array $r): int {
            return (int) $r['order_id'];
        }, $rows)));
        $args = [];
        $numbers = [];
        foreach ($db->select('SELECT id, number FROM {orders} WHERE id IN (' . $db->in($ids, $args) . ')', $args) as $o) {
            $numbers[(int) $o['id']] = (string) $o['number'];
        }
        $out = [];
        foreach ($rows as $r) {
            $data = json_decode((string) $r['data'], true);
            $receiver = is_array($data) && isset($data['receiver']) && is_array($data['receiver']) ? $data['receiver'] : [];
            $out[] = [
                'number' => (string) $r['full_number'], 'type' => (string) $r['type'], 'issued_at' => $r['issued_at'], 'order_number' => isset($numbers[(int) $r['order_id']]) ? $numbers[(int) $r['order_id']] : '',
                'currency' => (string) $r['currency'], 'net' => (int) $r['net'], 'tax' => (int) $r['tax'], 'total' => (int) $r['total'], 'test' => (int) $r['test'],
                'receiver_name' => isset($receiver['name']) ? (string) $receiver['name'] : '', 'receiver_vat_id' => isset($receiver['vat_id']) ? (string) $receiver['vat_id'] : '',
                'receiver_country' => isset($receiver['country']) ? (string) $receiver['country'] : '',
            ];
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $products
     *
     * @return array<int,string> id del producto => nombre en el idioma principal
     */
    private function names(array $products): array
    {
        if ($products === []) {
            return [];
        }
        $db = $this->app->db();
        $ids = array_map(static function (array $r): int {
            return (int) $r['id'];
        }, $products);
        $args = [$this->app->defaultLang()];
        $names = [];
        foreach ($db->select('SELECT product_id, name FROM {product_i18n} WHERE lang = ? AND product_id IN (' . $db->in($ids, $args) . ')', $args) as $row) {
            $names[(int) $row['product_id']] = (string) $row['name'];
        }

        return $names;
    }

    /**
     * @param array<string,mixed> $address
     */
    private static function name(array $address): string
    {
        return trim((isset($address['first_name']) ? (string) $address['first_name'] : '') . ' ' . (isset($address['last_name']) ? (string) $address['last_name'] : ''));
    }

    /**
     * @param array<string,mixed> $a
     *
     * @return array<string,string>
     */
    private static function addressFields(string $prefix, array $a): array
    {
        $out = [$prefix . 'name' => self::name($a)];
        foreach (['company', 'vat_id', 'phone', 'line1', 'line2', 'postcode', 'city', 'region', 'country'] as $key) {
            $out[$prefix . $key] = isset($a[$key]) && is_scalar($a[$key]) ? (string) $a[$key] : '';
        }

        return $out;
    }
}
