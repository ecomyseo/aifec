<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Front;

use Aifec\Commerce\Money;
use Aifec\Commerce\Totals;
use Aifec\Kernel\App;

/**
 * El desglose que se enseña en el carrito, en el resumen de la caja y en la página del pedido.
 *
 * No calcula dinero: parte del resultado de Totals::calculate() (el del carrito) o de lo que el pedido congeló, y solo
 * lo presenta según cómo se enseñen los precios (con impuestos o sin ellos). Cada fila lleva sus céntimos para que una
 * prueba pueda sumarlas, y el total de abajo es siempre el del cálculo, nunca una suma hecha aquí.
 *
 * Cada fila: ['kind' => discount|shipping|fee|adjustment|tax, 'label' => texto, 'value' => importe ya formateado o un
 * texto, 'cents' => int o null].
 */
final class TotalsView
{
    /**
     * El desglose de un carrito con precios (Cart::summary()).
     *
     * @param array<string,mixed> $summary
     * @param bool                $shippingKnown false = el envío aún no se ha calculado (carrito sin destino): se enseña
     *                                          «se calcula en el siguiente paso» y el total va sin envío
     * @param bool                $estimated     true = el envío que se suma es una estimación (aún no hay código postal): la
     *                                          fila se llama «Envío estimado» y el total lo lleva dentro
     *
     * @return array{rows:array<int,array<string,mixed>>,subtotal:int,subtotal_formatted:string,total:int,total_formatted:string,note:string,shipping_pending:bool,with_tax:bool}
     */
    public static function forCart(App $app, array $summary, bool $shippingKnown, bool $estimated = false): array
    {
        $totals = $summary['totals'];
        $currency = (string) $summary['currency'];
        $withTax = !empty($summary['display_with_tax']);
        $inclusive = !empty($totals['prices_include_tax']);
        $exempt = !empty($totals['tax_exempt']);
        $money = static function (int $cents) use ($app, $currency): string {
            return $app->i18n()->money($cents, $currency);
        };
        $shown = static function (array $parts) use ($withTax): int {
            return $withTax ? (int) $parts['gross'] : (int) $parts['net'];
        };

        $itemsShown = 0;
        $afterShown = 0;
        foreach ($summary['lines'] as $line) {
            if (empty($line['available'])) {
                continue;
            }
            $itemsShown += $shown(Totals::tax((int) $line['subtotal'], (int) $line['tax_rate'], $inclusive, $exempt));
            $afterShown += $shown($line);
        }

        $rows = [];
        $discountShown = max(0, $itemsShown - $afterShown);
        $weights = [];
        foreach ($totals['discounts'] as $i => $discount) {
            if ((string) $discount['kind'] !== 'free_shipping' && (int) $discount['amount'] > 0) {
                $weights[$i] = (int) $discount['amount'];
            }
        }
        if ($discountShown > 0 && $weights !== []) {
            foreach (Money::allocate($discountShown, $weights) as $i => $cents) {
                $rows[] = self::row('discount', self::discountLabel($totals['discounts'][$i]), '-' . $money((int) $cents), -(int) $cents);
            }
        }

        $pending = false;
        $shippingPending = 0;
        if (!empty($summary['needs_shipping'])) {
            $ship = $totals['shipping'];
            if ($ship === null) {
                $rows[] = self::row('shipping', t('Shipping'), t('Not available'), null);
            } elseif (!$shippingKnown) {
                $pending = true;
                $shippingPending = (int) $ship['net'] + (int) $ship['tax'];
                // Con lo que costaría al destino de serie, para que el total no dé un salto sin avisar (ronda 3, 14/11/2026).
                $from = empty($ship['free']) && $shippingPending > 0 ? $money($shown($ship)) : '';
                $rows[] = self::row('shipping', t('Shipping'), $from !== '' ? t('Calculated in the next step (from {price})', ['price' => $from]) : t('Calculated in the next step'), null);
            } else {
                $cents = $shown($ship);
                // Antes de tener el código postal, lo que suma es una estimación y así se dice.
                $rows[] = self::row('shipping', $estimated ? t('Estimated shipping') : t('Shipping'), !empty($ship['free']) ? t('Free') : $money($cents), $cents);
            }
        }
        foreach ($totals['fees'] as $fee) {
            $cents = $shown($fee);
            $rows[] = self::row('fee', (string) $fee['label'], $money($cents), $cents);
        }
        foreach ($totals['adjustments'] as $adjustment) {
            $cents = $shown($adjustment);
            $rows[] = self::row('adjustment', (string) $adjustment['label'] !== '' ? (string) $adjustment['label'] : t('Price adjustment'), ($cents < 0 ? '-' : '') . $money(abs($cents)), $cents);
        }

        // Los impuestos por tipo. Con el envío pendiente se le quita su parte al tipo que le corresponde.
        $taxes = [];
        foreach ($totals['taxes'] as $tax) {
            $amount = (int) $tax['amount'];
            if ($pending && !empty($totals['shipping']) && (string) $tax['name'] === (string) $totals['shipping']['tax_name'] && (int) $tax['rate'] === (int) $totals['shipping']['tax_rate']) {
                $amount -= (int) $totals['shipping']['tax'];
            }
            if ($amount > 0) {
                $taxes[] = ['name' => (string) $tax['name'], 'amount' => $amount];
            }
        }
        foreach ($taxes as $tax) {
            $rows[] = self::taxRow($tax['name'], $tax['amount'], $withTax, $money);
        }

        $total = (int) $totals['total'] - $shippingPending;
        if ($exempt) {
            $note = t('Tax exempt');
        } else {
            $note = $withTax ? t('Tax included') : t('Plus tax');
        }

        return [
            'rows' => $rows,
            'subtotal' => $itemsShown,
            'subtotal_formatted' => $money($itemsShown),
            'total' => $total,
            'total_formatted' => $money($total),
            'note' => $note,
            'shipping_pending' => $pending,
            'with_tax' => $withTax,
        ];
    }

    /**
     * El desglose del carrito tal como lo enseña la página del carrito y lo repiten summary.json y las respuestas de las
     * acciones del carrito: con destino (código postal), el envío de ese destino y «Total»; sin destino, «Se calcula en el
     * siguiente paso» y «Total sin envío». Un solo sitio para que la página, el minicarrito y el JSON no se separen.
     *
     * @param array<string,mixed> $summary Cart::summary()
     *
     * @return array{rows:array<int,array<string,mixed>>,subtotal:int,subtotal_formatted:string,total:int,total_formatted:string,note:string,shipping_pending:bool,with_tax:bool}
     */
    public static function forCartPage(App $app, array $summary): array
    {
        $destination = is_array($summary['destination']) ? $summary['destination'] : [];
        $known = isset($destination['postcode']) && (string) $destination['postcode'] !== '';

        return self::forCart($app, $summary, $known);
    }

    /**
     * El desglose de un pedido ya hecho. Se enseña tal como se cobró: con impuestos dentro si los precios los llevaban y
     * con los impuestos aparte si no.
     *
     * @param array<string,mixed> $order Orders::find()
     *
     * @return array{rows:array<int,array<string,mixed>>,subtotal:int,subtotal_formatted:string,total:int,total_formatted:string,note:string,shipping_pending:bool,with_tax:bool}
     */
    public static function forOrder(App $app, array $order): array
    {
        $currency = (string) $order['currency'];
        $inclusive = !empty($order['prices_include_tax']);
        $money = static function (int $cents) use ($app, $currency): string {
            return $app->i18n()->money($cents, $currency);
        };
        $subtotal = 0;
        $rows = [];
        $extra = [];
        foreach (is_array($order['items']) ? $order['items'] : [] as $item) {
            $kind = (string) $item['kind'];
            $data = is_array($item['data']) ? $item['data'] : [];
            if ($kind === 'product') {
                $subtotal += (int) $item['unit_price'] * (int) $item['qty'];
            } elseif ($kind === 'discount' && !empty($data['informational'])) {
                $rows[] = self::row('discount', self::discountLabel(['label' => (string) $item['name'], 'code' => '']), '-' . $money(abs((int) $item['line_total'])), (int) $item['line_total']);
            } elseif ($kind === 'shipping') {
                $cents = (int) $item['line_total'];
                $extra[] = self::row('shipping', t('Shipping'), $cents === 0 ? t('Free') : $money($cents), $cents);
            } elseif ($kind === 'fee' || $kind === 'discount') {
                $cents = (int) $item['line_total'];
                $extra[] = self::row(!empty($data['adjustment']) ? 'adjustment' : 'fee', (string) $item['name'], ($cents < 0 ? '-' : '') . $money(abs($cents)), $cents);
            }
        }
        $rows = array_merge($rows, $extra);
        foreach (is_array($order['taxes']) ? $order['taxes'] : [] as $tax) {
            if ((int) $tax['amount'] > 0) {
                $rows[] = self::taxRow((string) $tax['name'], (int) $tax['amount'], $inclusive, $money);
            }
        }

        return [
            'rows' => $rows,
            'subtotal' => $subtotal,
            'subtotal_formatted' => $money($subtotal),
            'total' => (int) $order['total'],
            'total_formatted' => $money((int) $order['total']),
            'note' => (int) $order['total_tax'] === 0 ? '' : ($inclusive ? t('Tax included') : t('Plus tax')),
            'shipping_pending' => false,
            'with_tax' => $inclusive,
        ];
    }

    /**
     * Lo que se enseña como precio de una opción de envío: con o sin impuestos según cómo se enseñen los precios.
     *
     * @param array<string,mixed> $summary
     * @param array<string,mixed> $option  una de $summary['shipping_options']
     *
     * @return array{cents:int,free:bool}
     */
    public static function optionPrice(App $app, array $summary, array $option): array
    {
        $tax = $app->service('tax');
        $lineRates = [];
        foreach ($summary['lines'] as $line) {
            if (!empty($line['available'])) {
                $lineRates[] = ['rate' => (int) $line['tax_rate'], 'name' => (string) $line['tax_name']];
            }
        }
        $classId = isset($option['tax_class_id']) && $option['tax_class_id'] !== null ? (int) $option['tax_class_id'] : null;
        $rate = $classId !== null ? $tax->rate($classId, $summary['destination']) : $tax->shippingRate($summary['destination'], $lineRates);
        $parts = Totals::tax((int) $option['price'], (int) $rate['rate'], !empty($summary['totals']['prices_include_tax']), !empty($summary['totals']['tax_exempt']));
        $cents = !empty($summary['display_with_tax']) ? $parts['gross'] : $parts['net'];

        return ['cents' => $cents, 'free' => (int) $option['price'] === 0];
    }

    /**
     * El recargo de una forma de pago tal como se enseña (con o sin impuestos).
     *
     * @param array<string,mixed> $summary
     */
    public static function feeShown(App $app, array $summary, int $fee): int
    {
        if ($fee <= 0) {
            return 0;
        }
        $rate = $app->service('tax')->rate(null, $summary['destination']);
        $parts = Totals::tax($fee, (int) $rate['rate'], !empty($summary['totals']['prices_include_tax']), !empty($summary['totals']['tax_exempt']));

        return !empty($summary['display_with_tax']) ? $parts['gross'] : $parts['net'];
    }

    /**
     * @return array<string,mixed>
     */
    private static function row(string $kind, string $label, string $value, ?int $cents): array
    {
        return ['kind' => $kind, 'label' => $label, 'value' => $value, 'cents' => $cents];
    }

    /**
     * @param callable $money
     *
     * @return array<string,mixed>
     */
    private static function taxRow(string $name, int $amount, bool $included, callable $money): array
    {
        $label = $included
            ? t('Includes {name}', ['name' => $name !== '' ? $name : t('Tax')])
            : ($name !== '' ? t('Tax: {name}', ['name' => $name]) : t('Tax'));

        return self::row('tax', $label, $money($amount), $included ? null : $amount);
    }

    /**
     * @param array<string,mixed> $discount
     */
    private static function discountLabel(array $discount): string
    {
        $label = trim((string) $discount['label']);
        if ($label === '') {
            $label = trim(isset($discount['code']) ? (string) $discount['code'] : '');
        }

        return $label !== '' ? t('Discount: {name}', ['name' => $label]) : t('Discount');
    }
}
