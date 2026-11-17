<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\PrestaShop;

use Aifec\Modules\Import\Neutral;

/**
 * Pedidos y reglas de carrito de PrestaShop al formato neutro. PrestaShop guarda los importes sin impuestos y con ellos
 * (columnas «_tax_excl» y «_tax_incl»): aquí se entregan SIN impuestos («prices_include_tax» = false) y cada línea lleva su
 * neto, su impuesto y su tipo, de modo que la suma de las líneas da el total del pedido al céntimo.
 */
final class OrderMapper
{
    /** @var array<string,string> configuración de PrestaShop que identifica un estado => significado */
    private const STATE_CONFIG = [
        'PS_OS_CANCELED' => 'cancelled',
        'PS_OS_REFUND' => 'refunded',
        'PS_OS_ERROR' => 'failed',
        'PS_OS_OUTOFSTOCK_UNPAID' => 'pending',
        'PS_OS_OUTOFSTOCK' => 'pending',
        'PS_OS_BANKWIRE' => 'pending',
        'PS_OS_CHEQUE' => 'pending',
        'PS_OS_COD_VALIDATION' => 'pending',
        'PS_OS_OUTOFSTOCK_PAID' => 'paid',
        'PS_OS_PAYMENT' => 'paid',
        'PS_OS_WS_PAYMENT' => 'paid',
        'PS_OS_PREPARATION' => 'processing',
        'PS_OS_SHIPPING' => 'shipped',
        'PS_OS_DELIVERED' => 'delivered',
    ];

    /**
     * Qué significa un estado de pedido: primero los que PrestaShop identifica en su configuración (anulado, reembolsado, error,
     * enviado…) y después las marcas del estado (entregado, enviado, pagado, facturable).
     */
    public static function meaning(int $stateId, Env $env): string
    {
        foreach (self::STATE_CONFIG as $name => $meaning) {
            if ($env->cfgInt($name) === $stateId && $stateId > 0) {
                return $meaning;
            }
        }
        $state = isset($env->orderStates[$stateId]) ? $env->orderStates[$stateId] : null;
        if ($state === null) {
            return 'pending';
        }
        if ($state['delivery']) {
            return 'delivered';
        }
        if ($state['shipped']) {
            return 'shipped';
        }
        if ($state['paid']) {
            return 'paid';
        }

        return $state['logable'] ? 'processing' : 'pending';
    }

    /**
     * Los estados de pedido para la tabla de equivalencias del asistente.
     *
     * @return array<string,array{name:string,meaning:string}>
     */
    public static function states(Env $env): array
    {
        $out = [];
        foreach ($env->orderStates as $id => $state) {
            $out[(string) $id] = ['name' => $env->one($state['name']), 'meaning' => self::meaning($id, $env)];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $r fila de pedido más lines (filas de order_detail con tax_ids), history, payments, invoices, slips, order_carriers,
     *                               cart_rules, email, invoice_address, delivery_address, carrier
     *
     * @return array<string,mixed>
     */
    public static function order(array $r, Env $env): array
    {
        $currency = isset($env->currencies[Mapper::i($r['id_currency'])]) ? $env->currencies[Mapper::i($r['id_currency'])] : '';
        $placed = $env->utc(isset($r['date_add']) ? $r['date_add'] : null);
        if ($currency === '' || $placed === null) {
            throw new \InvalidArgumentException('The order has no currency or date');
        }
        $stateId = Mapper::i($r['current_state']);
        $carrier = isset($r['carrier']) && is_array($r['carrier']) ? $r['carrier'] : null;
        $carrierTitle = $carrier !== null && Mapper::s($carrier['name']) !== '' && Mapper::s($carrier['name']) !== '0' ? Mapper::s($carrier['name']) : 'Shipping';

        list($items, $detailIndex) = self::lines($r, $carrierTitle, $env);
        $totalNet = Mapper::cents($r['total_paid_tax_excl']);
        $total = Mapper::cents($r['total_paid_tax_incl']);
        $sumNet = 0;
        $sumTax = 0;
        foreach ($items as $item) {
            $sumNet += $item['line_net'];
            $sumTax += $item['line_tax'];
        }
        $refunds = self::refunds($r, $detailIndex, $env);
        $refunded = 0;
        foreach ($refunds as $refund) {
            $refunded += $refund['amount'];
        }
        $history = self::history($r, $env);
        $record = [
            'id' => Mapper::s($r['id']),
            'number' => Mapper::s($r['reference']) !== '' ? Mapper::s($r['reference']) : Mapper::s($r['id']),
            'email' => Mapper::s(isset($r['email']) ? $r['email'] : ''),
            'currency' => $currency,
            'state' => (string) $stateId,
            'state_meaning' => self::meaning($stateId, $env),
            'payment_method' => Mapper::s(isset($r['module']) ? $r['module'] : ''),
            'payment_title' => Mapper::s(isset($r['payment']) ? $r['payment'] : ''),
            'carrier_title' => $carrier !== null ? $carrierTitle : '',
            'tracking_code' => self::tracking($r),
            'prices_include_tax' => false,
            'items_total' => Mapper::cents($r['total_products']),
            'discount_total' => Mapper::cents(isset($r['total_discounts_tax_excl']) ? $r['total_discounts_tax_excl'] : 0),
            'shipping_total' => Mapper::cents(isset($r['total_shipping_tax_excl']) ? $r['total_shipping_tax_excl'] : 0),
            'fee_total' => Mapper::cents(isset($r['total_wrapping_tax_excl']) ? $r['total_wrapping_tax_excl'] : 0),
            'total_net' => $totalNet,
            'total_tax' => $total - $totalNet,
            'total' => $total,
            'paid_total' => Mapper::cents(isset($r['total_paid_real']) ? $r['total_paid_real'] : 0),
            'refunded_total' => $refunded,
            'taxes' => self::taxes($items),
            'placed_at' => $placed,
            'paid_at' => self::firstAt($history, $env, 'paid'),
            'shipped_at' => self::firstAt($history, $env, 'shipped'),
            'completed_at' => self::firstAt($history, $env, 'delivery'),
            'cancelled_at' => self::cancelledAt($history, $env),
            'items' => $items,
            'payments' => self::payments($r, $env),
            'refunds' => $refunds,
            'history' => self::historyEvents($history, $env),
            'invoices' => self::invoices($r, $env),
            'coupons' => self::orderCoupons($r),
        ];
        if ($record['paid_at'] === null && $record['payments'] !== []) {
            $record['paid_at'] = $record['payments'][0]['at'];
        }
        if (Mapper::s($r['id_customer']) !== '' && Mapper::i($r['id_customer']) > 0) {
            $record['customer_id'] = (string) Mapper::i($r['id_customer']);
        }
        $iso = $env->iso(Mapper::i(isset($r['id_lang']) ? $r['id_lang'] : 0));
        if ($iso !== '') {
            $record['locale'] = $iso;
        }
        if ($carrier !== null && Mapper::s(isset($carrier['id_reference']) ? $carrier['id_reference'] : '') !== '' && Mapper::s($carrier['id_reference']) !== '0') {
            $record['carrier_id'] = Mapper::s($carrier['id_reference']);
        }
        foreach (['invoice_address' => 'billing', 'delivery_address' => 'shipping'] as $source => $target) {
            if (isset($r[$source]) && is_array($r[$source])) {
                $record[$target] = Mapper::address($r[$source], $env, false);
            }
        }
        $updated = $env->utc(isset($r['date_upd']) ? $r['date_upd'] : null);
        if ($updated !== null) {
            $record['updated_at'] = $updated;
        }
        $extra = ['valid' => Mapper::b(isset($r['valid']) ? $r['valid'] : 0)];
        if ($sumNet !== $totalNet) {
            $extra['lines_net_diff'] = $totalNet - $sumNet;
        }
        if ($sumTax !== $record['total_tax']) {
            $extra['lines_tax_diff'] = $record['total_tax'] - $sumTax;
        }
        $conversion = Mapper::s(isset($r['conversion_rate']) ? $r['conversion_rate'] : '1');
        if ($conversion !== '' && Mapper::dec6($conversion) !== 1000000) {
            $extra['conversion_rate'] = $conversion;
        }
        if (Mapper::b(isset($r['gift']) ? $r['gift'] : 0)) {
            $extra['gift'] = true;
            $extra['gift_message'] = Mapper::s(isset($r['gift_message']) ? $r['gift_message'] : '');
        }
        if (Mapper::s(isset($r['note']) ? $r['note'] : '') !== '') {
            $extra['note'] = Mapper::s($r['note']);
        }
        if (Mapper::i(isset($r['id_cart']) ? $r['id_cart'] : 0) > 0) {
            $extra['id_cart'] = Mapper::s($r['id_cart']);
        }
        $record['extra'] = $extra;

        return $record;
    }

    /**
     * Las líneas del pedido: productos, transporte, envoltorio y descuentos.
     *
     * @param array<string,mixed> $r
     *
     * @return array{0:array<int,array<string,mixed>>,1:array<int,int>} las líneas y la posición de cada order_detail
     */
    private static function lines(array $r, string $carrierTitle, Env $env): array
    {
        $items = [];
        $index = [];
        $details = isset($r['lines']) && is_array($r['lines']) ? array_values($r['lines']) : [];
        usort($details, static function (array $a, array $b): int {
            return Mapper::i($a['id_order_detail']) <=> Mapper::i($b['id_order_detail']);
        });
        foreach ($details as $d) {
            $net = Mapper::cents($d['total_price_tax_excl']);
            $tax = Mapper::cents($d['total_price_tax_incl']) - $net;
            list($rate, $taxName) = self::rateOf(isset($d['tax_ids']) && is_array($d['tax_ids']) ? $d['tax_ids'] : [], $net, $tax, $env);
            $item = [
                'kind' => 'product',
                'sku' => Mapper::s(isset($d['product_reference']) ? $d['product_reference'] : ''),
                'name' => Mapper::s($d['product_name']) !== '' ? Mapper::s($d['product_name']) : 'Product ' . Mapper::s($d['product_id']),
                'qty' => Mapper::i($d['product_quantity']),
                'unit_price' => Mapper::cents($d['unit_price_tax_excl']),
                'discount' => 0,
                'line_total' => $net,
                'line_net' => $net,
                'line_tax' => $tax,
                'tax_rate' => $rate,
                'tax_name' => $taxName,
                'weight' => Neutral::grams(isset($d['product_weight']) ? $d['product_weight'] : 0, $env->weightUnit),
                'is_digital' => Mapper::s(isset($d['download_hash']) ? $d['download_hash'] : '') !== '',
                'refunded_qty' => max(0, Mapper::i(isset($d['product_quantity_refunded']) ? $d['product_quantity_refunded'] : 0)),
                'refunded_total' => Mapper::cents(isset($d['total_refunded_tax_incl']) ? $d['total_refunded_tax_incl'] : 0),
            ];
            if (Mapper::i($d['product_id']) > 0) {
                $item['product_id'] = (string) Mapper::i($d['product_id']);
            }
            if (Mapper::i(isset($d['product_attribute_id']) ? $d['product_attribute_id'] : 0) > 0) {
                $item['variant_id'] = (string) Mapper::i($d['product_attribute_id']);
            }
            $index[Mapper::i($d['id_order_detail'])] = count($items);
            $items[] = $item;
        }
        $shippingNet = Mapper::cents(isset($r['total_shipping_tax_excl']) ? $r['total_shipping_tax_excl'] : 0);
        $shippingTax = Mapper::cents(isset($r['total_shipping_tax_incl']) ? $r['total_shipping_tax_incl'] : 0) - $shippingNet;
        if ($shippingNet !== 0 || $shippingTax !== 0) {
            $rate = Neutral::rate(Mapper::s(isset($r['carrier_tax_rate']) ? $r['carrier_tax_rate'] : '0'));
            $items[] = self::extraLine('shipping', $carrierTitle, $shippingNet, $shippingTax, $rate);
        }
        $wrappingNet = Mapper::cents(isset($r['total_wrapping_tax_excl']) ? $r['total_wrapping_tax_excl'] : 0);
        $wrappingTax = Mapper::cents(isset($r['total_wrapping_tax_incl']) ? $r['total_wrapping_tax_incl'] : 0) - $wrappingNet;
        if ($wrappingNet !== 0 || $wrappingTax !== 0) {
            $items[] = self::extraLine('fee', 'Gift wrapping', $wrappingNet, $wrappingTax, 0);
        }
        $rules = isset($r['cart_rules']) && is_array($r['cart_rules']) ? $r['cart_rules'] : [];
        foreach ($rules as $rule) {
            $net = Mapper::cents(isset($rule['value_tax_excl']) ? $rule['value_tax_excl'] : 0);
            $tax = Mapper::cents(isset($rule['value']) ? $rule['value'] : 0) - $net;
            $items[] = self::extraLine('discount', Mapper::s(isset($rule['name']) ? $rule['name'] : '') !== '' ? Mapper::s($rule['name']) : 'Discount', -$net, -$tax, 0);
        }
        $discountNet = Mapper::cents(isset($r['total_discounts_tax_excl']) ? $r['total_discounts_tax_excl'] : 0);
        if ($rules === [] && $discountNet > 0) {
            $discountTax = Mapper::cents(isset($r['total_discounts_tax_incl']) ? $r['total_discounts_tax_incl'] : 0) - $discountNet;
            $items[] = self::extraLine('discount', 'Discount', -$discountNet, -$discountTax, 0);
        }

        return [$items, $index];
    }

    /**
     * @return array<string,mixed>
     */
    private static function extraLine(string $kind, string $name, int $net, int $tax, int $rate): array
    {
        if ($rate === 0 && $tax !== 0 && $net !== 0) {
            $rate = (int) round(abs($tax) * 10000 / abs($net));
        }

        return [
            'kind' => $kind,
            'name' => $name,
            'qty' => 1,
            'unit_price' => $net,
            'discount' => 0,
            'line_total' => $net,
            'line_net' => $net,
            'line_tax' => $tax,
            'tax_rate' => $rate,
        ];
    }

    /**
     * El tipo de una línea (la suma de los de sus impuestos) y el nombre del primero; sin impuestos conocidos, el que sale de las cifras.
     *
     * @param array<int,mixed> $taxIds
     *
     * @return array{0:int,1:string}
     */
    private static function rateOf(array $taxIds, int $net, int $tax, Env $env): array
    {
        $rate = 0;
        $name = '';
        foreach ($taxIds as $taxId) {
            if (isset($env->taxes[(int) $taxId])) {
                $rate += Neutral::rate($env->taxes[(int) $taxId]['rate']);
                $name = $name !== '' ? $name : $env->one($env->taxes[(int) $taxId]['name']);
            }
        }
        if ($rate === 0 && $tax > 0 && $net > 0) {
            $rate = (int) round($tax * 10000 / $net);
        }

        return [$rate, $name];
    }

    /**
     * Lo cobrado por tipo de impuesto: base y cuota de las líneas con el mismo tipo (el nombre es el del primer impuesto que lo trae).
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private static function taxes(array $items): array
    {
        $groups = [];
        foreach ($items as $item) {
            $rate = (int) $item['tax_rate'];
            $name = isset($item['tax_name']) ? (string) $item['tax_name'] : '';
            if ($rate === 0 && $item['line_tax'] === 0) {
                continue;
            }
            $key = (string) $rate;
            if (!isset($groups[$key])) {
                $groups[$key] = ['name' => '', 'rate' => $rate, 'base' => 0, 'amount' => 0];
            }
            $groups[$key]['name'] = $groups[$key]['name'] !== '' ? $groups[$key]['name'] : $name;
            $groups[$key]['base'] += $item['line_net'];
            $groups[$key]['amount'] += $item['line_tax'];
        }
        usort($groups, static function (array $a, array $b): int {
            return $b['rate'] <=> $a['rate'];
        });

        return array_values($groups);
    }

    /** @param array<string,mixed> $r */
    private static function tracking(array $r): string
    {
        $code = '';
        foreach (isset($r['order_carriers']) && is_array($r['order_carriers']) ? $r['order_carriers'] : [] as $row) {
            if (Mapper::s(isset($row['tracking_number']) ? $row['tracking_number'] : '') !== '') {
                $code = Mapper::s($row['tracking_number']);
            }
        }

        return $code !== '' ? $code : Mapper::s(isset($r['shipping_number']) ? $r['shipping_number'] : '');
    }

    /**
     * @param array<string,mixed> $r
     *
     * @return array<int,array{at:?string,state:int,employee:int}>
     */
    private static function history(array $r, Env $env): array
    {
        $rows = isset($r['history']) && is_array($r['history']) ? array_values($r['history']) : [];
        usort($rows, static function (array $a, array $b): int {
            return Mapper::i($a['id']) <=> Mapper::i($b['id']);
        });
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['at' => $env->utc(isset($row['date_add']) ? $row['date_add'] : null), 'state' => Mapper::i($row['id_order_state']), 'employee' => Mapper::i(isset($row['id_employee']) ? $row['id_employee'] : 0)];
        }

        return $out;
    }

    /**
     * @param array<int,array{at:?string,state:int,employee:int}> $history
     */
    private static function firstAt(array $history, Env $env, string $flag): ?string
    {
        foreach ($history as $event) {
            if ($event['at'] !== null && isset($env->orderStates[$event['state']]) && $env->orderStates[$event['state']][$flag]) {
                return $event['at'];
            }
        }

        return null;
    }

    /**
     * @param array<int,array{at:?string,state:int,employee:int}> $history
     */
    private static function cancelledAt(array $history, Env $env): ?string
    {
        foreach ($history as $event) {
            if ($event['at'] !== null && $event['state'] > 0 && self::meaning($event['state'], $env) === 'cancelled') {
                return $event['at'];
            }
        }

        return null;
    }

    /**
     * @param array<int,array{at:?string,state:int,employee:int}> $history
     *
     * @return array<int,array<string,mixed>>
     */
    private static function historyEvents(array $history, Env $env): array
    {
        $events = [];
        $previous = '';
        foreach ($history as $event) {
            if ($event['at'] === null) {
                continue;
            }
            $events[] = [
                'at' => $event['at'],
                'kind' => 'state',
                'from' => $previous,
                'to' => (string) $event['state'],
                'public' => isset($env->orderStates[$event['state']]) ? $env->orderStates[$event['state']]['logable'] : false,
                'actor' => $event['employee'] > 0 ? 'employee ' . $event['employee'] : 'system',
            ];
            $previous = (string) $event['state'];
        }

        return $events;
    }

    /**
     * @param array<string,mixed> $r
     *
     * @return array<int,array<string,mixed>>
     */
    private static function payments(array $r, Env $env): array
    {
        $out = [];
        foreach (isset($r['payments']) && is_array($r['payments']) ? $r['payments'] : [] as $p) {
            $out[] = [
                'method' => Mapper::s(isset($p['payment_method']) ? $p['payment_method'] : '') !== '' ? Mapper::s($p['payment_method']) : 'unknown',
                'kind' => 'charge',
                'reference' => Mapper::s(isset($p['transaction_id']) ? $p['transaction_id'] : ''),
                'amount' => Mapper::cents($p['amount']),
                'status' => 'ok',
                'at' => $env->utc(isset($p['date_add']) ? $p['date_add'] : null),
            ];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp((string) $a['at'], (string) $b['at']);
        });

        return $out;
    }

    /**
     * Los abonos: «amount» es lo devuelto con impuestos (el dinero que vuelve), «tax» la parte de impuesto y cada línea su importe con impuestos.
     *
     * @param array<string,mixed> $r
     * @param array<int,int>      $detailIndex
     *
     * @return array<int,array<string,mixed>>
     */
    private static function refunds(array $r, array $detailIndex, Env $env): array
    {
        $reinjected = [];
        foreach (isset($r['lines']) && is_array($r['lines']) ? $r['lines'] : [] as $d) {
            $reinjected[Mapper::i($d['id_order_detail'])] = Mapper::i(isset($d['product_quantity_reinjected']) ? $d['product_quantity_reinjected'] : 0);
        }
        $slips = isset($r['slips']) && is_array($r['slips']) ? array_values($r['slips']) : [];
        usort($slips, static function (array $a, array $b): int {
            return Mapper::i($a['id']) <=> Mapper::i($b['id']);
        });
        $out = [];
        foreach ($slips as $slip) {
            $amount = Mapper::centsOf(Mapper::dec6($slip['amount']) + Mapper::dec6(isset($slip['shipping_cost_amount']) ? $slip['shipping_cost_amount'] : 0));
            $tax = Mapper::centsOf(
                Mapper::dec6(isset($slip['total_products_tax_incl']) ? $slip['total_products_tax_incl'] : 0) - Mapper::dec6(isset($slip['total_products_tax_excl']) ? $slip['total_products_tax_excl'] : 0)
                + Mapper::dec6(isset($slip['total_shipping_tax_incl']) ? $slip['total_shipping_tax_incl'] : 0) - Mapper::dec6(isset($slip['total_shipping_tax_excl']) ? $slip['total_shipping_tax_excl'] : 0)
            );
            $lines = [];
            $restock = false;
            foreach (isset($slip['details']) && is_array($slip['details']) ? $slip['details'] : [] as $detail) {
                $detailId = Mapper::i($detail['id_order_detail']);
                if (!isset($detailIndex[$detailId])) {
                    continue;
                }
                $lines[] = ['index' => $detailIndex[$detailId], 'qty' => max(0, Mapper::i($detail['product_quantity'])), 'amount' => Mapper::cents($detail['amount_tax_incl'])];
                $restock = $restock || (isset($reinjected[$detailId]) && $reinjected[$detailId] > 0);
            }
            $out[] = ['amount' => $amount, 'tax' => max(0, $tax), 'reason' => '', 'at' => $env->utc(isset($slip['date_add']) ? $slip['date_add'] : null), 'restock' => $restock, 'lines' => $lines];
        }

        return $out;
    }

    /**
     * Las facturas (números con su prefijo de idioma y seis cifras) y los abonos como facturas rectificativas.
     *
     * @param array<string,mixed> $r
     *
     * @return array<int,array<string,mixed>>
     */
    private static function invoices(array $r, Env $env): array
    {
        $lang = Mapper::i(isset($r['id_lang']) ? $r['id_lang'] : 0);
        $out = [];
        $invoices = isset($r['invoices']) && is_array($r['invoices']) ? array_values($r['invoices']) : [];
        usort($invoices, static function (array $a, array $b): int {
            return Mapper::i($a['number']) <=> Mapper::i($b['number']);
        });
        foreach ($invoices as $invoice) {
            if (Mapper::i($invoice['number']) <= 0) {
                continue;
            }
            $out[] = ['number' => $env->cfgLang('PS_INVOICE_PREFIX', $lang) . str_pad((string) Mapper::i($invoice['number']), 6, '0', STR_PAD_LEFT), 'date' => $env->utc(isset($invoice['date_add']) ? $invoice['date_add'] : null), 'kind' => 'invoice'];
        }
        $slips = isset($r['slips']) && is_array($r['slips']) ? array_values($r['slips']) : [];
        usort($slips, static function (array $a, array $b): int {
            return Mapper::i($a['id']) <=> Mapper::i($b['id']);
        });
        foreach ($slips as $slip) {
            $out[] = ['number' => $env->cfgLang('PS_CREDIT_SLIP_PREFIX', $lang) . str_pad((string) Mapper::i($slip['id']), 6, '0', STR_PAD_LEFT), 'date' => $env->utc(isset($slip['date_add']) ? $slip['date_add'] : null), 'kind' => 'credit'];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $r
     *
     * @return array<int,array<string,mixed>>
     */
    private static function orderCoupons(array $r): array
    {
        $out = [];
        foreach (isset($r['cart_rules']) && is_array($r['cart_rules']) ? $r['cart_rules'] : [] as $rule) {
            $code = Mapper::s(isset($rule['code']) ? $rule['code'] : '');
            $code = $code !== '' ? $code : Mapper::s(isset($rule['name']) ? $rule['name'] : '');
            if ($code === '') {
                continue;
            }
            $out[] = ['code' => $code, 'amount' => Mapper::cents(isset($rule['value_tax_excl']) ? $rule['value_tax_excl'] : 0)];
        }

        return $out;
    }

    // ------------------------------------------------------------------ cupones

    /**
     * Una regla de carrito. Lo que no cabe en un cupón se anota en «unsupported»: regalo, restricciones por transportista, grupo o
     * país, combinaciones con otras reglas, restricciones de producto que no son una lista simple.
     *
     * @param array<string,mixed> $r fila de cart_rule más name, uses, countries, groups, carriers, rule_groups [[rules [[type, items]]]]
     *
     * @return array<string,mixed>
     */
    public static function coupon(array $r, Env $env): array
    {
        $code = Mapper::s($r['code']);
        $automatic = $code === '';
        $percent = Mapper::dec6(isset($r['reduction_percent']) ? $r['reduction_percent'] : 0);
        $amount = Mapper::dec6(isset($r['reduction_amount']) ? $r['reduction_amount'] : 0);
        $free = Mapper::b(isset($r['free_shipping']) ? $r['free_shipping'] : 0);
        $unsupported = [];
        $kind = 'amount';
        if ($percent > 0) {
            $kind = 'percent';
        } elseif ($amount > 0) {
            $kind = 'amount';
        } elseif ($free) {
            $kind = 'free_shipping';
        }
        if ($free && $kind !== 'free_shipping') {
            $unsupported[] = 'free_shipping_with_discount';
        }
        if ($percent <= 0 && $amount <= 0 && !$free) {
            $unsupported[] = Mapper::i(isset($r['gift_product']) ? $r['gift_product'] : 0) > 0 ? 'gift' : 'no_discount';
        } elseif (Mapper::i(isset($r['gift_product']) ? $r['gift_product'] : 0) > 0) {
            $unsupported[] = 'gift';
        }
        if ($automatic) {
            array_unshift($unsupported, 'automatic');
        }
        foreach (['carrier_restriction' => 'carriers', 'group_restriction' => 'groups', 'country_restriction' => 'countries'] as $flag => $list) {
            if (Mapper::b(isset($r[$flag]) ? $r[$flag] : 0)) {
                $unsupported[] = $flag;
            }
        }
        $combinable = !Mapper::b(isset($r['cart_rule_restriction']) ? $r['cart_rule_restriction'] : 0);
        if (!$combinable) {
            $unsupported[] = 'combinations';
        }
        $productIds = [];
        $categoryIds = [];
        $reductionProduct = Mapper::i(isset($r['reduction_product']) ? $r['reduction_product'] : 0);
        if ($reductionProduct > 0) {
            $productIds[] = (string) $reductionProduct;
        } elseif ($reductionProduct < 0 && $kind !== 'free_shipping') {
            $unsupported[] = $reductionProduct === -1 ? 'cheapest_product' : 'selected_products';
        }
        if (Mapper::b(isset($r['product_restriction']) ? $r['product_restriction'] : 0)) {
            $groups = isset($r['rule_groups']) && is_array($r['rule_groups']) ? $r['rule_groups'] : [];
            $simple = count($groups) === 1 && isset($groups[0]['rules']) && count($groups[0]['rules']) === 1;
            $type = $simple ? Mapper::s($groups[0]['rules'][0]['type']) : '';
            if ($simple && ($type === 'products' || $type === 'categories')) {
                foreach ($groups[0]['rules'][0]['items'] as $itemId) {
                    if ($type === 'products') {
                        $productIds[] = (string) (int) $itemId;
                    } else {
                        $categoryIds[] = (string) (int) $itemId;
                    }
                }
            } else {
                $unsupported[] = 'product_restriction';
            }
        }
        $uses = max(0, Mapper::i(isset($r['uses']) ? $r['uses'] : 0));
        $perUser = Mapper::i(isset($r['quantity_per_user']) ? $r['quantity_per_user'] : 0);
        $name = $env->one(isset($r['name']) && is_array($r['name']) ? $r['name'] : []);
        $record = [
            'id' => Mapper::s($r['id']),
            'code' => $automatic ? 'AUTO-' . Mapper::s($r['id']) : $code,
            'kind' => $kind,
            'percent' => $kind === 'percent' ? Neutral::rate(Mapper::s($r['reduction_percent'])) : 0,
            'amount' => $kind === 'amount' ? Mapper::cents($r['reduction_amount']) : 0,
            'amount_includes_tax' => Mapper::b(isset($r['reduction_tax']) ? $r['reduction_tax'] : 0),
            'min_subtotal' => Mapper::dec6(isset($r['minimum_amount']) ? $r['minimum_amount'] : 0) > 0 ? Mapper::cents($r['minimum_amount']) : null,
            'starts_at' => $env->utc(isset($r['date_from']) ? $r['date_from'] : null),
            'ends_at' => $env->utc(isset($r['date_to']) ? $r['date_to'] : null),
            'max_uses' => Mapper::i(isset($r['quantity']) ? $r['quantity'] : 0) >= 0 ? Mapper::i(isset($r['quantity']) ? $r['quantity'] : 0) + $uses : null,
            'max_uses_per_customer' => $perUser > 0 ? $perUser : null,
            'uses' => $uses,
            'first_order_only' => false,
            'exclude_sale' => Mapper::b(isset($r['reduction_exclude_special']) ? $r['reduction_exclude_special'] : 0),
            'combinable' => $combinable,
            'customer_id' => Mapper::i(isset($r['id_customer']) ? $r['id_customer'] : 0) > 0 ? (string) Mapper::i($r['id_customer']) : null,
            'product_ids' => $productIds,
            'category_ids' => $categoryIds,
            'active' => Mapper::b($r['active']) && !$automatic,
            'note' => $name !== '' ? $name : Mapper::s(isset($r['description']) ? $r['description'] : ''),
            'unsupported' => array_values(array_unique($unsupported)),
        ];
        $extra = [];
        if (Mapper::b(isset($r['minimum_amount_tax']) ? $r['minimum_amount_tax'] : 0) && $record['min_subtotal'] !== null) {
            $extra['minimum_includes_tax'] = true;
        }
        if (Mapper::b(isset($r['minimum_amount_shipping']) ? $r['minimum_amount_shipping'] : 0) && $record['min_subtotal'] !== null) {
            $extra['minimum_includes_shipping'] = true;
        }
        if (Mapper::b(isset($r['highlight']) ? $r['highlight'] : 0)) {
            $extra['highlight'] = true;
        }
        if (Mapper::i(isset($r['priority']) ? $r['priority'] : 1) !== 1) {
            $extra['priority'] = Mapper::i($r['priority']);
        }
        if ($extra !== []) {
            $record['extra'] = $extra;
        }

        return $record;
    }
}
