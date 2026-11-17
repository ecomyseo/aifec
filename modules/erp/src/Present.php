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

use Aifec\Commerce\Money;
use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * La forma de los datos que salen por la API y por los avisos. Los importes van en céntimos enteros («total») y además en decimal
 * como texto («total_decimal»); las fechas, en ISO 8601 UTC («2026-11-12T12:00:00Z»).
 */
final class Present
{
    /** «2026-11-12 12:00:00» (UTC) → «2026-11-12T12:00:00Z»; null si no hay fecha. */
    public static function iso(?string $sql): ?string
    {
        $time = Clock::parse($sql);

        return $time > 0 ? gmdate('Y-m-d\TH:i:s\Z', $time) : null;
    }

    /**
     * Fecha ISO 8601 o «Y-m-d H:i:s» (UTC) → «Y-m-d H:i:s»; null si no se entiende.
     */
    public static function toSql(string $text): ?string
    {
        $text = trim($text);
        if ($text === '') {
            return null;
        }
        $time = strtotime(preg_match('/(Z|[+-]\d{2}:?\d{2})$/', $text) === 1 ? $text : $text . ' UTC');

        return $time === false ? null : gmdate('Y-m-d H:i:s', $time);
    }

    /**
     * Pone cada importe como «clave» (céntimos) y «clave_decimal» (texto).
     *
     * @param array<string,mixed> $source
     * @param string[]            $keys
     *
     * @return array<string,mixed>
     */
    public static function amounts(array $source, array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $value = isset($source[$key]) && $source[$key] !== null ? (int) $source[$key] : null;
            $out[$key] = $value;
            $out[$key . '_decimal'] = $value === null ? null : Money::decimal($value);
        }

        return $out;
    }

    /**
     * @param mixed $address
     *
     * @return array<string,string>|null
     */
    public static function address($address): ?array
    {
        if (!is_array($address) || $address === []) {
            return null;
        }
        $out = [];
        foreach (['first_name', 'last_name', 'company', 'vat_id', 'phone', 'line1', 'line2', 'postcode', 'city', 'region', 'country'] as $key) {
            $out[$key] = isset($address[$key]) && is_scalar($address[$key]) ? (string) $address[$key] : '';
        }

        return $out;
    }

    /**
     * El pedido completo (lo que devuelve Orders::find()).
     *
     * @param array<string,mixed> $order
     * @param int|null            $connectionId si se da, se añade la confirmación de esa conexión
     *
     * @return array<string,mixed>
     */
    public static function order(App $app, array $order, ?int $connectionId = null): array
    {
        $lines = [];
        foreach (isset($order['items']) && is_array($order['items']) ? $order['items'] : [] as $item) {
            $rate = (int) $item['tax_rate'];
            $lines[] = [
                'position' => (int) $item['position'], 'kind' => (string) $item['kind'], 'sku' => $item['sku'] !== null ? (string) $item['sku'] : null,
                'name' => (string) $item['name'], 'variant_label' => $item['variant_label'] !== null ? (string) $item['variant_label'] : null,
                'product_id' => $item['product_id'], 'variant_id' => $item['variant_id'], 'qty' => (int) $item['qty'],
                'tax_rate' => $rate, 'tax_rate_percent' => Money::decimal($rate), 'tax_name' => $item['tax_name'] !== null ? (string) $item['tax_name'] : null,
                'is_digital' => !empty($item['is_digital']), 'refunded_qty' => (int) $item['refunded_qty'],
            ] + self::amounts($item, ['unit_price', 'discount', 'line_total', 'line_net', 'line_tax', 'refunded_total']);
        }
        $customer = null;
        if (isset($order['customer']) && is_array($order['customer'])) {
            $c = $order['customer'];
            $customer = [
                'id' => (int) $c['id'], 'email' => (string) $c['email'], 'first_name' => (string) ($c['first_name'] ?? ''), 'last_name' => (string) ($c['last_name'] ?? ''),
                'company' => (string) ($c['company'] ?? ''), 'vat_id' => (string) ($c['vat_id'] ?? ''), 'phone' => (string) ($c['phone'] ?? ''),
            ];
        }
        $shipments = [];
        foreach (isset($order['history']) && is_array($order['history']) ? $order['history'] : [] as $entry) {
            if ((string) $entry['kind'] === 'shipment') {
                $shipments[] = [
                    'at' => self::iso((string) $entry['created_at']), 'tracking_number' => (string) ($entry['to_value'] ?? ''),
                    'carrier' => isset($entry['data']['carrier']) ? (string) $entry['data']['carrier'] : null,
                    'url' => isset($entry['data']['url']) ? (string) $entry['data']['url'] : null,
                ];
            }
        }
        $out = [
            'number' => (string) $order['number'], 'status' => (string) $order['status'], 'payment_status' => (string) $order['payment_status'],
            'fulfillment_status' => (string) $order['fulfillment_status'], 'currency' => (string) $order['currency'], 'test' => !empty($order['test']),
            'source' => (string) $order['source'], 'placed_at' => self::iso((string) $order['placed_at']), 'paid_at' => self::iso($order['paid_at'] !== null ? (string) $order['paid_at'] : null),
            'shipped_at' => self::iso($order['shipped_at'] !== null ? (string) $order['shipped_at'] : null),
            'completed_at' => self::iso($order['completed_at'] !== null ? (string) $order['completed_at'] : null),
            'cancelled_at' => self::iso($order['cancelled_at'] !== null ? (string) $order['cancelled_at'] : null),
            'updated_at' => self::iso((string) $order['updated_at']), 'email' => (string) $order['email'], 'customer' => $customer,
            'prices_include_tax' => !empty($order['prices_include_tax']),
            'totals' => self::amounts($order, ['items_total', 'discount_total', 'shipping_total', 'fee_total', 'total_net', 'total_tax', 'total', 'paid_total', 'refunded_total']),
            'lines' => $lines,
            'taxes' => isset($order['taxes']) && is_array($order['taxes']) ? $order['taxes'] : [],
            'discounts' => ['adjustments' => isset($order['adjustments']) && is_array($order['adjustments']) ? $order['adjustments'] : [],
                'coupons' => isset($order['coupons']) && is_array($order['coupons']) ? $order['coupons'] : []],
            'shipping' => [
                'carrier' => $order['carrier_title'] !== null ? (string) $order['carrier_title'] : null,
                'tracking_number' => $order['tracking_code'] !== null ? (string) $order['tracking_code'] : null,
                'shipments' => $shipments, 'address' => self::address($order['shipping'] ?? null),
            ],
            'billing_address' => self::address($order['billing'] ?? null),
            'payment' => ['method' => $order['payment_method'] !== null ? (string) $order['payment_method'] : null,
                'title' => $order['payment_title'] !== null ? (string) $order['payment_title'] : null, 'status' => (string) $order['payment_status']],
            'customer_note' => $order['customer_note'] !== null ? (string) $order['customer_note'] : null,
            'attribution' => isset($order['attribution']) && is_array($order['attribution']) ? $order['attribution'] : [],
        ];
        if ($connectionId !== null) {
            $ack = $app->db()->row('SELECT external_id, acked_at FROM {erp_acks} WHERE order_id = ? AND connection_id = ?', [(int) $order['id'], $connectionId]);
            $out['erp_ack'] = $ack === null ? null : ['external_id' => (string) $ack['external_id'], 'acked_at' => self::iso((string) $ack['acked_at'])];
        }

        return $out;
    }

    /**
     * @param array<string,mixed>            $customer lo que devuelve Customers::find()
     * @param array<int,array<string,mixed>> $addresses
     *
     * @return array<string,mixed>
     */
    public static function customer(array $customer, array $addresses = []): array
    {
        $list = [];
        foreach ($addresses as $a) {
            $list[] = (self::address($a) ?? []) + ['default_shipping' => !empty($a['default_shipping']), 'default_billing' => !empty($a['default_billing'])];
        }

        return [
            'id' => (int) $customer['id'], 'email' => (string) $customer['email'], 'first_name' => (string) ($customer['first_name'] ?? ''),
            'last_name' => (string) ($customer['last_name'] ?? ''), 'company' => (string) ($customer['company'] ?? ''),
            'vat_id' => (string) ($customer['vat_id'] ?? ''), 'phone' => (string) ($customer['phone'] ?? ''), 'locale' => (string) ($customer['locale'] ?? ''),
            'guest' => !empty($customer['guest']), 'active' => !empty($customer['active']), 'marketing' => !empty($customer['marketing']),
            'orders_count' => (int) $customer['orders_count'], 'created_at' => self::iso((string) $customer['created_at']),
            'updated_at' => self::iso((string) $customer['updated_at']), 'addresses' => $list,
        ] + self::amounts($customer, ['total_spent']);
    }

    /**
     * @param array<string,mixed> $invoice lo que devuelve Invoices::find()
     *
     * @return array<string,mixed>
     */
    public static function invoice(App $app, array $invoice): array
    {
        $data = isset($invoice['data']) && is_array($invoice['data']) ? $invoice['data'] : [];
        $number = (string) $app->db()->value('SELECT number FROM {orders} WHERE id = ?', [(int) $invoice['order_id']]);

        return [
            'number' => (string) $invoice['full_number'], 'type' => (string) $invoice['type'], 'order_number' => $number, 'issued_at' => self::iso((string) $invoice['issued_at']),
            'currency' => (string) $invoice['currency'], 'test' => !empty($invoice['test']),
            'receiver' => isset($data['receiver']) && is_array($data['receiver']) ? $data['receiver'] : [],
            'taxes' => isset($data['taxes']) && is_array($data['taxes']) ? $data['taxes'] : [],
            'pdf' => $app->url()->origin() . $app->url()->plain(Routes::PREFIX . '/invoices/' . rawurlencode((string) $invoice['full_number']) . '/pdf'),
        ] + self::amounts($invoice, ['net', 'tax', 'total']);
    }
}
