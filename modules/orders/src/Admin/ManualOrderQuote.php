<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

/**
 * El resumen del pedido a mano, tal como lo calcula el carrito de la tienda (Cart::summary(), que pasa por Totals): líneas,
 * subtotal, descuentos, envío, recargos, precios de esta venta, base, impuestos por tipo y total. Lo pintan la pantalla (sin
 * JavaScript) y el guion (con POST /admin/orders/new/quote, en JSON): el guion copia los textos de «rows» y no suma nada.
 *
 * También dice qué falta para poder crear el pedido (missing), qué problemas hay (problems: existencias, cupones…) y, si está
 * listo, su ficha de confirmación (token): un HMAC con la clave del sitio de todo lo que se va a cobrar. Si al confirmar la
 * ficha ya no es la de ahora (cambiaron existencias, un precio, un cupón…), el pedido no se crea y changes() dice qué cambió.
 */
final class ManualOrderQuote
{
    /**
     * @return array<string,mixed>
     */
    public static function of(ManualOrderDraft $draft): array
    {
        $app = $draft->app();
        $s = $draft->summary();
        $state = $draft->state();
        $customer = $draft->customer();
        $currency = (string) $s['currency'];
        $money = static function (int $cents) use ($currency): string {
            return money($cents, $currency);
        };
        $totals = $s['totals'];

        $lines = [];
        $rows = [];
        $count = 0;
        foreach ($s['lines'] as $key => $line) {
            $key = (string) $key;
            $price = isset($state['prices'][$key]) ? $state['prices'][$key] : null;
            $subtotal = (int) $line['unit_price'] * (int) $line['qty'];
            $count += (int) $line['qty'];
            $lines[] = [
                'key' => $key,
                'product_id' => (int) $line['product_id'],
                'variant_id' => (int) $line['variant_id'],
                'name' => (string) $line['name'],
                'variant' => (string) $line['variant_label'],
                'sku' => (string) $line['sku'],
                'qty' => (int) $line['qty'],
                'max' => (int) $line['max_qty'],
                'unit' => (int) $line['unit_price'],
                'unit_formatted' => $money((int) $line['unit_price']),
                'subtotal' => $subtotal,
                'subtotal_formatted' => $money($subtotal),
                'price' => $price !== null ? (int) $price['price'] : null,
                'price_formatted' => $price !== null ? $money((int) $price['price']) : '',
                'reason' => $price !== null ? (string) $price['reason'] : '',
                'available' => !empty($line['available']),
                'warnings' => array_values(array_map('strval', is_array($line['warnings']) ? $line['warnings'] : [])),
            ];
            $rows[] = ['kind' => 'line', 'label' => ManualOrderPrices::lineName($line) . ' × ' . (int) $line['qty'], 'value' => $money($subtotal)];
        }

        $rows[] = ['kind' => 'sub', 'label' => t('Products'), 'value' => $money((int) $totals['items_total'])];
        $discounts = [];
        foreach ($totals['discounts'] as $discount) {
            if ((int) $discount['amount'] < 1) {
                continue;
            }
            $label = trim((string) $discount['label']) !== '' ? (string) $discount['label'] : (string) $discount['code'];
            $discounts[] = ['label' => $label, 'code' => (string) $discount['code'], 'amount' => (int) $discount['amount'], 'amount_formatted' => $money(-(int) $discount['amount'])];
            $rows[] = ['kind' => 'discount', 'label' => $label, 'value' => $money(-(int) $discount['amount'])];
        }
        $shipping = null;
        if (!empty($s['needs_shipping']) && $totals['shipping'] !== null) {
            $free = !empty($totals['shipping']['free']);
            $shipping = [
                'carrier_id' => $s['carrier_id'] !== null ? (int) $s['carrier_id'] : 0,
                'label' => (string) $totals['shipping']['label'],
                'amount' => (int) $totals['shipping']['price'],
                'amount_formatted' => $free ? t('Free') : $money((int) $totals['shipping']['price']),
                'free' => $free,
            ];
            $rows[] = ['kind' => 'sub', 'label' => t('Shipping: {name}', ['name' => $shipping['label']]), 'value' => $shipping['amount_formatted']];
        }
        $extras = [];
        foreach (['fees', 'adjustments'] as $group) {
            foreach ($totals[$group] as $extra) {
                $extras[$group][] = ['label' => (string) $extra['label'], 'amount' => (int) $extra['amount'], 'amount_formatted' => $money((int) $extra['amount'])];
                $rows[] = ['kind' => $group === 'fees' ? 'sub' : 'adjustment', 'label' => (string) $extra['label'], 'value' => $money((int) $extra['amount'])];
            }
        }
        $rows[] = ['kind' => 'tax', 'label' => t('Tax base'), 'value' => $money((int) $totals['total_net'])];
        $taxes = [];
        foreach ($totals['taxes'] as $tax) {
            $name = trim((string) $tax['name']) !== '' ? (string) $tax['name'] : t('Tax');
            $taxes[] = [
                'name' => $name, 'rate' => (int) $tax['rate'], 'base' => (int) $tax['base'], 'base_formatted' => $money((int) $tax['base']),
                'amount' => (int) $tax['amount'], 'amount_formatted' => $money((int) $tax['amount']),
            ];
            $rows[] = ['kind' => 'tax', 'label' => $name, 'value' => $money((int) $tax['amount'])];
        }
        $total = (int) $totals['total'];
        $rows[] = ['kind' => 'total', 'label' => t('Total'), 'value' => $money($total)];

        $missing = self::missing($draft, $s, $lines, $customer, $state);
        $problems = array_values(array_map('strval', is_array($s['problems']) ? $s['problems'] : []));
        $ready = $missing === [] && $problems === [];

        $quote = [
            'ready' => $ready,
            'missing' => $missing,
            'problems' => $problems,
            'currency' => $currency,
            'count' => $count,
            'lines' => $lines,
            'items' => (int) $totals['items_total'],
            'items_formatted' => $money((int) $totals['items_total']),
            'discount' => (int) $totals['discount_total'],
            'discounts' => $discounts,
            'coupons' => isset($s['coupons']) && is_array($s['coupons']) ? array_values($s['coupons']) : [],
            'shipping' => $shipping,
            'needs_shipping' => !empty($s['needs_shipping']),
            'fees' => isset($extras['fees']) ? $extras['fees'] : [],
            'adjustments' => isset($extras['adjustments']) ? $extras['adjustments'] : [],
            'base' => (int) $totals['total_net'],
            'base_formatted' => $money((int) $totals['total_net']),
            'tax' => (int) $totals['total_tax'],
            'tax_formatted' => $money((int) $totals['total_tax']),
            'taxes' => $taxes,
            'total' => $total,
            'total_formatted' => $money($total),
            'prices_include_tax' => !empty($totals['prices_include_tax']),
            'payment_method' => $s['payment_method'] !== null ? (string) $s['payment_method'] : '',
            'rows' => $rows,
            'status' => $lines === [] ? t('The order is empty.') : t('Total of the order: {total}', ['total' => $money($total)]),
            'token' => '',
        ];
        if ($ready) {
            $quote['token'] = self::token($app->site()->secret(), $quote, $state, $customer);
            $draft->remember($quote['token'], self::snapshot($quote));
        }

        return $quote;
    }

    /**
     * Lo que falta, en el orden de la pantalla: paso => qué hacer.
     *
     * @param array<string,mixed>            $s
     * @param array<int,array<string,mixed>> $lines
     * @param array<string,mixed>|null       $customer
     * @param array<string,mixed>            $state
     *
     * @return array<string,string>
     */
    private static function missing(ManualOrderDraft $draft, array $s, array $lines, ?array $customer, array $state): array
    {
        $out = [];
        if ($customer === null) {
            $out['customer'] = t('Choose the customer.');
        } elseif (trim((string) $customer['email']) === '') {
            $out['customer'] = t('The customer has no email: the confirmation of the order goes there.');
        }
        if ($lines === []) {
            $out['products'] = t('Add at least one product.');
        }
        $needs = !empty($s['needs_shipping']);
        if ($customer !== null && $needs && $draft->address() === null) {
            $out['delivery'] = t('Choose the delivery address.');
        }
        if ($needs && $lines !== [] && ($s['carrier_id'] === null || $s['totals']['shipping'] === null)) {
            $out['shipping'] = t('Choose the shipping.');
        }
        $pay = (string) $state['pay'];
        $method = $s['payment_method'] !== null ? (string) $s['payment_method'] : '';
        $paid = $pay === 'paid' && $method === ManualOrderPayment::CODE && trim((string) $state['paid_with']) !== '';
        $offline = strpos($pay, 'method:') === 0 && $method === substr($pay, 7);
        if (!$paid && !$offline) {
            $out['payment'] = t('Choose how the order is paid.');
        }

        return $out;
    }

    /**
     * La ficha de confirmación: todo lo que se va a cobrar y a quién.
     *
     * @param array<string,mixed>      $quote
     * @param array<string,mixed>      $state
     * @param array<string,mixed>|null $customer
     */
    public static function token(string $secret, array $quote, array $state, ?array $customer): string
    {
        $lines = [];
        foreach ($quote['lines'] as $line) {
            $lines[$line['key']] = [$line['qty'], $line['unit'], $line['price'], $line['available']];
        }
        $data = [
            'customer' => $customer !== null ? [(int) $customer['id'], (string) $customer['email']] : null,
            'address' => (int) $state['address_id'],
            'pay' => [(string) $state['pay'], (string) $state['paid_with'], (string) $quote['payment_method']],
            'lines' => $lines,
            'discounts' => $quote['discounts'],
            'shipping' => $quote['shipping'] !== null ? [$quote['shipping']['carrier_id'], $quote['shipping']['amount']] : null,
            'fees' => $quote['fees'],
            'adjustments' => $quote['adjustments'],
            'taxes' => $quote['taxes'],
            'total' => $quote['total'],
        ];

        return hash_hmac('sha256', (string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'manual-order|' . $secret);
    }

    /**
     * Lo que se guarda de un resumen enseñado, para comparar al confirmar.
     *
     * @param array<string,mixed> $quote
     *
     * @return array<string,mixed>
     */
    public static function snapshot(array $quote): array
    {
        $lines = [];
        foreach ($quote['lines'] as $line) {
            $lines[$line['key']] = ['name' => ManualOrderPrices::lineName(['name' => $line['name'], 'variant_label' => $line['variant']]), 'qty' => $line['qty'], 'unit' => $line['unit'], 'unit_formatted' => $line['unit_formatted']];
        }
        $coupons = [];
        foreach ($quote['discounts'] as $discount) {
            if ($discount['code'] !== '') {
                $coupons[$discount['code']] = $discount['amount'];
            }
        }

        return [
            'lines' => $lines,
            'coupons' => $coupons,
            'shipping' => $quote['shipping'] !== null ? $quote['shipping']['amount_formatted'] : '',
            'total' => $quote['total'],
            'total_formatted' => $quote['total_formatted'],
        ];
    }

    /**
     * Qué cambió entre lo que se enseñó y lo de ahora: una lista de [key (clave de línea o «»), text].
     *
     * @param array<string,mixed>|null $before snapshot() de lo enseñado (null si ya no se conserva)
     * @param array<string,mixed>      $now    of()
     *
     * @return array<int,array{key:string,text:string}>
     */
    public static function changes(?array $before, array $now): array
    {
        $out = [];
        $current = [];
        foreach ($now['lines'] as $line) {
            $current[$line['key']] = $line;
        }
        foreach ($now['lines'] as $line) {
            if (!$line['available'] || $line['warnings'] !== []) {
                $reason = $line['warnings'] !== [] ? (string) $line['warnings'][0] : t('This product is no longer available');
                $out[] = ['key' => $line['key'], 'text' => ManualOrderPrices::lineName(['name' => $line['name'], 'variant_label' => $line['variant']]) . ': ' . $reason];
            }
        }
        // Los problemas del carrito que no son de una línea (los de las líneas ya están arriba): un cupón que se cayó, un destino sin envío.
        $ofLines = [];
        foreach ($now['lines'] as $line) {
            foreach ($line['warnings'] as $warning) {
                $ofLines[(string) $line['name'] . ': ' . (string) $warning] = true;
            }
        }
        foreach ($now['problems'] as $problem) {
            if (!isset($ofLines[(string) $problem])) {
                $out[] = ['key' => '', 'text' => (string) $problem];
            }
        }
        if ($before === null) {
            if ($out === []) {
                $out[] = ['key' => '', 'text' => t('The order changed since the total you saw. Check it and confirm again.')];
            }

            return $out;
        }
        foreach ($before['lines'] as $key => $old) {
            if (!isset($current[$key])) {
                $out[] = ['key' => (string) $key, 'text' => t('{product} is no longer in the order.', ['product' => (string) $old['name']])];
            } elseif ((int) $current[$key]['unit'] !== (int) $old['unit']) {
                $out[] = ['key' => (string) $key, 'text' => t('{product}: the price changed from {old} to {new}.', [
                    'product' => (string) $old['name'], 'old' => (string) $old['unit_formatted'], 'new' => (string) $current[$key]['unit_formatted'],
                ])];
            }
        }
        $codes = [];
        foreach ($now['discounts'] as $discount) {
            $codes[$discount['code']] = $discount['amount'];
        }
        foreach ($before['coupons'] as $code => $amount) {
            if (!isset($codes[$code])) {
                $out[] = ['key' => '', 'text' => t('The coupon {code} no longer applies.', ['code' => (string) $code])];
            }
        }
        $shipping = $now['shipping'] !== null ? $now['shipping']['amount_formatted'] : '';
        if ($shipping !== (string) $before['shipping']) {
            $out[] = ['key' => '', 'text' => t('The shipping changed from {old} to {new}.', ['old' => (string) $before['shipping'], 'new' => $shipping])];
        }
        if ((int) $before['total'] !== (int) $now['total']) {
            $out[] = ['key' => '', 'text' => t('The total changed from {old} to {new}.', ['old' => (string) $before['total_formatted'], 'new' => (string) $now['total_formatted']])];
        }
        if ($out === []) {
            $out[] = ['key' => '', 'text' => t('The order changed since the total you saw. Check it and confirm again.')];
        }

        return $out;
    }
}
