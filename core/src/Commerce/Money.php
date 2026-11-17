<?php
/**
 * AIFEC — aritmética del dinero. Todo en céntimos enteros: nunca se usa coma flotante para un importe.
 *
 * Los tipos (impuestos, descuentos) van en centésimas de punto: 2100 = 21 %, 1000 = 10 %.
 * Se redondea al céntimo más cercano y el medio céntimo se aleja de cero (0,5 → 1; −0,5 → −1).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Commerce;

final class Money
{
    public const SCALE = 10000;

    /** División entera redondeada al más cercano (el medio se aleja de cero). $b > 0. */
    public static function divRound(int $a, int $b): int
    {
        if ($b <= 0) {
            throw new \InvalidArgumentException('Divisor must be positive');
        }
        if ($a >= 0) {
            return intdiv(2 * $a + $b, 2 * $b);
        }

        return -intdiv(2 * -$a + $b, 2 * $b);
    }

    /** El tanto por ciento de un importe: percent(1999, 2100) → 420. */
    public static function percent(int $amount, int $rate): int
    {
        return self::divRound($amount * $rate, self::SCALE);
    }

    /**
     * Separa un importe que lleva los impuestos dentro: split(1999, 2100) → [1652, 347].
     *
     * @return array{0:int,1:int} [base, cuota]; base + cuota = $gross siempre
     */
    public static function split(int $gross, int $rate): array
    {
        if ($rate <= 0) {
            return [$gross, 0];
        }
        $net = self::divRound($gross * self::SCALE, self::SCALE + $rate);

        return [$net, $gross - $net];
    }

    /**
     * Suma los impuestos a una base: add(1652, 2100) → [347, 1999].
     *
     * @return array{0:int,1:int} [cuota, total con impuestos]
     */
    public static function add(int $net, int $rate): array
    {
        $tax = $rate > 0 ? self::percent($net, $rate) : 0;

        return [$tax, $net + $tax];
    }

    /**
     * Reparte un importe entre varias partes en proporción a sus pesos, sin perder ni un céntimo:
     * la suma de lo repartido es exactamente $total. Los céntimos sueltos van a las partes con mayor resto.
     *
     * @param array<int|string,int> $weights pesos no negativos; se conservan las claves
     *
     * @return array<int|string,int>
     */
    public static function allocate(int $total, array $weights): array
    {
        $out = [];
        $sum = 0;
        foreach ($weights as $key => $weight) {
            if ($weight < 0) {
                throw new \InvalidArgumentException('Weights cannot be negative');
            }
            $out[$key] = 0;
            $sum += $weight;
        }
        if ($sum === 0 || $total === 0) {
            return $out;
        }
        $sign = $total < 0 ? -1 : 1;
        $abs = abs($total);
        $given = 0;
        $remainders = [];
        foreach ($weights as $key => $weight) {
            $share = intdiv($abs * $weight, $sum);
            $out[$key] = $share;
            $given += $share;
            $remainders[$key] = ($abs * $weight) % $sum;
        }
        $left = $abs - $given;
        if ($left > 0) {
            // Mayor resto primero; a igualdad, el que va antes (reparto estable).
            $order = array_keys($remainders);
            usort($order, static function ($a, $b) use ($remainders, $weights): int {
                if ($remainders[$a] !== $remainders[$b]) {
                    return $remainders[$b] <=> $remainders[$a];
                }

                return array_search($a, array_keys($weights), true) <=> array_search($b, array_keys($weights), true);
            });
            foreach ($order as $key) {
                if ($left === 0) {
                    break;
                }
                if ($weights[$key] > 0) {
                    ++$out[$key];
                    --$left;
                }
            }
        }
        if ($sign < 0) {
            foreach ($out as $key => $value) {
                $out[$key] = -$value;
            }
        }

        return $out;
    }

    /** Céntimos → «19.99» (para APIs, feeds y pasarelas; para enseñar a una persona, money()). */
    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);

        return $sign . intdiv($abs, 100) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }
}
