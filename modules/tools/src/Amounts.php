<?php
/**
 * AIFEC — importes y números de las hojas de cálculo: «19,99», «1.999,50» o «19.99» ↔ céntimos enteros.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

/**
 * Nada de coma flotante: el importe se corta como texto. Lo que se escribe es siempre con coma decimal y dos decimales
 * («19,99»); lo que se lee admite coma o punto como separador decimal y el otro como separador de miles. Un texto que se
 * puede leer de dos maneras («1.999» o «1,999») no se adivina: es un error, y la fila se salta.
 */
final class Amounts
{
    /** Tope de un importe en céntimos (las columnas son enteros de 32 bits). */
    private const MAX_CENTS = 2000000000;

    /** «1999» céntimos → «19,99». */
    public static function format(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return $sign . intdiv($cents, 100) . ',' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    /**
     * «19,99» → 1999. Lanza \InvalidArgumentException si no es un importe.
     */
    public static function parse(string $text): int
    {
        list($int, $frac) = self::split($text, 2);
        if (strlen(ltrim($int, '0')) > 8) {
            throw new \InvalidArgumentException('The amount is too large');
        }
        $cents = (int) $int * 100 + (int) str_pad($frac, 2, '0');
        if ($cents > self::MAX_CENTS) {
            throw new \InvalidArgumentException('The amount is too large');
        }

        return $cents;
    }

    /**
     * Un número decimal cualquiera (hasta 4 decimales) en forma normal: «130,5» → «130.5». Lanza si no es un número.
     */
    public static function decimal(string $text): string
    {
        list($int, $frac) = self::split($text, 4);
        $frac = rtrim($frac, '0');

        return ltrim($int, '0') === '' ? '0' . ($frac !== '' ? '.' . $frac : '') : ltrim($int, '0') . ($frac !== '' ? '.' . $frac : '');
    }

    /** Un número decimal tal como lo guarda la base («130.5000») → «130,5». */
    public static function showDecimal(string $stored): string
    {
        $stored = trim($stored);
        if (strpos($stored, '.') !== false) {
            $stored = rtrim(rtrim($stored, '0'), '.');
        }

        return str_replace('.', ',', $stored === '' ? '0' : $stored);
    }

    /**
     * Parte un número en parte entera y parte decimal.
     *
     * @return array{0:string,1:string}
     */
    private static function split(string $text, int $maxDecimals): array
    {
        $s = trim(str_replace(["\xC2\xA0", ' ', '€'], '', $text));
        if ($s === '' || preg_match('/^\d[\d.,]*$/', $s) !== 1) {
            throw new \InvalidArgumentException('It is not a number');
        }
        $dot = strrpos($s, '.');
        $comma = strrpos($s, ',');
        if ($dot !== false && $comma !== false) {
            $decimalSep = $dot > $comma ? '.' : ',';
        } elseif ($dot !== false) {
            $decimalSep = substr_count($s, '.') > 1 ? '' : '.';
        } elseif ($comma !== false) {
            $decimalSep = substr_count($s, ',') > 1 ? '' : ',';
        } else {
            $decimalSep = '';
        }
        $frac = '';
        if ($decimalSep === '') {
            $int = str_replace(['.', ','], '', $s);
        } else {
            $pos = (int) strrpos($s, $decimalSep);
            $int = str_replace($decimalSep === '.' ? ',' : '.', '', substr($s, 0, $pos));
            $frac = substr($s, $pos + 1);
        }
        if ($int === '' || preg_match('/^\d+$/', $int) !== 1 || ($frac !== '' && preg_match('/^\d+$/', $frac) !== 1)) {
            throw new \InvalidArgumentException('It is not a number');
        }
        if (strlen($frac) > $maxDecimals) {
            throw new \InvalidArgumentException('It has too many decimals');
        }

        return [$int, $frac];
    }
}
