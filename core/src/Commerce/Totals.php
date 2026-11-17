<?php
/**
 * AIFEC — el cálculo de un carrito o de un pedido. Es la ÚNICA cuenta de dinero del sistema: la usan el carrito,
 * la caja, el pedido, el panel, la API y el asistente, y por eso todos dan siempre la misma cifra.
 *
 * Es una función pura: no lee la base de datos ni los ajustes. Quien la llama le da los precios «tal como se
 * escriben» (con impuestos dentro o no, según prices_include_tax), los tipos ya resueltos para el destino y los
 * descuentos ya validados. Cada línea se redondea por separado y los totales son la suma de las líneas: así el
 * desglose cuadra al céntimo en la pantalla, en el correo y en la factura.
 *
 * Entrada:
 *   prices_include_tax  bool
 *   tax_exempt          bool   cliente o destino que no paga impuestos: se cobra solo la base
 *   lines        [['key' => 'p12-v3', 'unit_price' => 1999, 'qty' => 2, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %',
 *                  'discountable' => true]]
 *   discounts    en orden de aplicación:
 *                [['code' => 'OTOÑO', 'label' => '…', 'kind' => 'percent', 'percent' => 1000, 'keys' => null | ['p12-v3']],
 *                 ['kind' => 'fixed', 'amount' => 500, …], ['kind' => 'free_shipping', …]]
 *   shipping     null o ['label' => 'GLS 24 h', 'price' => 495, 'tax_rate' => 2100, 'tax_name' => 'IVA 21 %']
 *   fees         [['label' => 'Contra reembolso', 'amount' => 300, 'tax_rate' => 2100, 'tax_name' => …]]
 *   adjustments  líneas de ajuste visibles (reglas de precio de módulos): igual que fees, con importe positivo o negativo
 *                y 'source' (quién la pone)
 *
 * Salida: lines (por clave: subtotal, discount, total, net, tax, gross…), items_total, discount_total, discounts
 * (lo que descontó de verdad cada uno), shipping, fees, fee_total, adjustments, adjustment_total, total_net,
 * total_tax, total, taxes ([['name','rate','base','amount']]) y prices_include_tax.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Commerce;

final class Totals
{
    /**
     * @param array<string,mixed> $input
     *
     * @return array<string,mixed>
     */
    public static function calculate(array $input): array
    {
        $inclusive = !empty($input['prices_include_tax']);
        $exempt = !empty($input['tax_exempt']);

        // 1. Líneas: importe antes de descuentos.
        $lines = [];
        foreach (isset($input['lines']) && is_array($input['lines']) ? $input['lines'] : [] as $i => $line) {
            $key = isset($line['key']) ? (string) $line['key'] : 'l' . $i;
            $qty = max(0, (int) (isset($line['qty']) ? $line['qty'] : 1));
            $unit = max(0, (int) (isset($line['unit_price']) ? $line['unit_price'] : 0));
            $lines[$key] = [
                'key' => $key,
                'unit_price' => $unit,
                'qty' => $qty,
                'subtotal' => $unit * $qty,
                'discount' => 0,
                'total' => $unit * $qty,
                'tax_rate' => max(0, (int) (isset($line['tax_rate']) ? $line['tax_rate'] : 0)),
                'tax_name' => isset($line['tax_name']) ? (string) $line['tax_name'] : '',
                'discountable' => !isset($line['discountable']) || !empty($line['discountable']),
            ];
        }
        $itemsTotal = 0;
        foreach ($lines as $line) {
            $itemsTotal += $line['subtotal'];
        }

        // 2. Descuentos, uno detrás de otro sobre lo que va quedando. Nunca dejan una línea en negativo.
        $freeShipping = false;
        $applied = [];
        foreach (isset($input['discounts']) && is_array($input['discounts']) ? $input['discounts'] : [] as $discount) {
            $kind = isset($discount['kind']) ? (string) $discount['kind'] : '';
            $entry = [
                'code' => isset($discount['code']) ? (string) $discount['code'] : '',
                'label' => isset($discount['label']) ? (string) $discount['label'] : '',
                'kind' => $kind,
                'amount' => 0,
            ];
            if ($kind === 'free_shipping') {
                $freeShipping = true;
                $applied[] = $entry;
                continue;
            }
            $only = isset($discount['keys']) && is_array($discount['keys']) ? array_map('strval', $discount['keys']) : null;
            $weights = [];
            foreach ($lines as $key => $line) {
                if ($line['discountable'] && ($only === null || in_array((string) $key, $only, true)) && $line['total'] > 0) {
                    $weights[$key] = $line['total'];
                }
            }
            $base = array_sum($weights);
            if ($kind === 'percent') {
                $amount = Money::percent($base, max(0, min(Money::SCALE, (int) (isset($discount['percent']) ? $discount['percent'] : 0))));
            } elseif ($kind === 'fixed') {
                $amount = min($base, max(0, (int) (isset($discount['amount']) ? $discount['amount'] : 0)));
            } else {
                throw new \InvalidArgumentException('Unknown discount kind: ' . $kind);
            }
            foreach (Money::allocate($amount, $weights) as $key => $share) {
                $lines[$key]['discount'] += $share;
                $lines[$key]['total'] -= $share;
            }
            $entry['amount'] = $amount;
            $applied[] = $entry;
        }
        $discountTotal = 0;
        foreach ($applied as $entry) {
            $discountTotal += $entry['amount'];
        }

        // 3. Impuestos línea a línea.
        $taxes = [];
        $totalNet = 0;
        $totalTax = 0;
        foreach ($lines as $key => $line) {
            $parts = self::tax($line['total'], $line['tax_rate'], $inclusive, $exempt);
            $lines[$key] += $parts;
            unset($lines[$key]['discountable']);
            self::collect($taxes, $line['tax_name'], $exempt ? 0 : $line['tax_rate'], $parts['net'], $parts['tax']);
            $totalNet += $parts['net'];
            $totalTax += $parts['tax'];
        }

        // 4. Envío.
        $shipping = null;
        if (isset($input['shipping']) && is_array($input['shipping'])) {
            $price = $freeShipping ? 0 : max(0, (int) (isset($input['shipping']['price']) ? $input['shipping']['price'] : 0));
            $rate = max(0, (int) (isset($input['shipping']['tax_rate']) ? $input['shipping']['tax_rate'] : 0));
            $name = isset($input['shipping']['tax_name']) ? (string) $input['shipping']['tax_name'] : '';
            $parts = self::tax($price, $rate, $inclusive, $exempt);
            $shipping = ['label' => isset($input['shipping']['label']) ? (string) $input['shipping']['label'] : '', 'price' => $price, 'free' => $price === 0, 'tax_rate' => $rate, 'tax_name' => $name] + $parts;
            if ($price > 0) {
                self::collect($taxes, $name, $exempt ? 0 : $rate, $parts['net'], $parts['tax']);
            }
            $totalNet += $parts['net'];
            $totalTax += $parts['tax'];
        }

        // 5. Recargos y líneas de ajuste.
        $extras = ['fees' => [], 'adjustments' => []];
        $extraTotals = ['fees' => 0, 'adjustments' => 0];
        foreach (['fees', 'adjustments'] as $group) {
            foreach (isset($input[$group]) && is_array($input[$group]) ? $input[$group] : [] as $extra) {
                $amount = (int) (isset($extra['amount']) ? $extra['amount'] : 0);
                if ($group === 'fees') {
                    $amount = max(0, $amount);
                }
                if ($amount === 0) {
                    continue;
                }
                $rate = max(0, (int) (isset($extra['tax_rate']) ? $extra['tax_rate'] : 0));
                $name = isset($extra['tax_name']) ? (string) $extra['tax_name'] : '';
                $parts = self::tax($amount, $rate, $inclusive, $exempt);
                $extras[$group][] = ['label' => isset($extra['label']) ? (string) $extra['label'] : '', 'source' => isset($extra['source']) ? (string) $extra['source'] : '', 'amount' => $amount, 'tax_rate' => $rate, 'tax_name' => $name] + $parts;
                self::collect($taxes, $name, $exempt ? 0 : $rate, $parts['net'], $parts['tax']);
                $extraTotals[$group] += $amount;
                $totalNet += $parts['net'];
                $totalTax += $parts['tax'];
            }
        }

        // Un ajuste negativo no puede dejar el pedido por debajo de cero.
        if ($totalNet + $totalTax < 0) {
            throw new \DomainException('Adjustments exceed the order total');
        }

        $taxList = array_values($taxes);
        usort($taxList, static function (array $a, array $b): int {
            return $b['rate'] <=> $a['rate'];
        });

        return [
            'prices_include_tax' => $inclusive,
            'tax_exempt' => $exempt,
            'lines' => $lines,
            'items_total' => $itemsTotal,
            'discount_total' => $discountTotal,
            'discounts' => $applied,
            'shipping' => $shipping,
            'fees' => $extras['fees'],
            'fee_total' => $extraTotals['fees'],
            'adjustments' => $extras['adjustments'],
            'adjustment_total' => $extraTotals['adjustments'],
            'total_net' => $totalNet,
            'total_tax' => $totalTax,
            'total' => $totalNet + $totalTax,
            'taxes' => $taxList,
        ];
    }

    /**
     * Base, cuota e importe con impuestos de un importe «tal como se escribe».
     *
     * @return array{net:int,tax:int,gross:int}
     */
    public static function tax(int $amount, int $rate, bool $inclusive, bool $exempt): array
    {
        if ($inclusive) {
            list($net, $tax) = Money::split($amount, $rate);
            if ($exempt) {
                // Quien no paga impuestos paga solo la base del precio publicado.
                return ['net' => $net, 'tax' => 0, 'gross' => $net];
            }

            return ['net' => $net, 'tax' => $tax, 'gross' => $amount];
        }
        if ($exempt) {
            return ['net' => $amount, 'tax' => 0, 'gross' => $amount];
        }
        list($tax, $gross) = Money::add($amount, $rate);

        return ['net' => $amount, 'tax' => $tax, 'gross' => $gross];
    }

    /**
     * @param array<string,array{name:string,rate:int,base:int,amount:int}> $taxes
     */
    private static function collect(array &$taxes, string $name, int $rate, int $net, int $tax): void
    {
        $key = $rate . '|' . $name;
        if (!isset($taxes[$key])) {
            $taxes[$key] = ['name' => $name, 'rate' => $rate, 'base' => 0, 'amount' => 0];
        }
        $taxes[$key]['base'] += $net;
        $taxes[$key]['amount'] += $tax;
    }
}
