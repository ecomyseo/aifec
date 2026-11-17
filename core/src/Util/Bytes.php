<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Util;

/**
 * Tamaños de fichero legibles y su lectura inversa. Base 1024.
 */
final class Bytes
{
    private const UNITS = ['B', 'KB', 'MB', 'GB', 'TB'];

    /** «1,5 MB» con coma decimal para español; «1.5 MB» en el resto. */
    public static function format(int $bytes, int $decimals = 1, string $locale = 'es'): string
    {
        $spanish = strtolower(substr($locale, 0, 2)) === 'es';
        $decimalSep = $spanish ? ',' : '.';
        $thousandsSep = $spanish ? '.' : ',';

        $sign = $bytes < 0 ? '-' : '';
        $value = (float) abs($bytes);
        $unit = 0;
        while ($value >= 1024 && $unit < count(self::UNITS) - 1) {
            $value /= 1024;
            ++$unit;
        }

        if ($unit === 0) {
            return $sign . number_format($value, 0, $decimalSep, $thousandsSep) . ' B';
        }

        return $sign . number_format($value, max(0, $decimals), $decimalSep, $thousandsSep) . ' ' . self::UNITS[$unit];
    }

    /** Lee «64M», «2 GB», «512» o «1.5K» (como en php.ini, con base 1024). Lanza si no entiende el texto. */
    public static function parse(string $size): int
    {
        if (preg_match('/^(\d+(?:\.\d+)?)\s*([KMGT]?)B?$/i', trim($size), $m) !== 1) {
            throw new \InvalidArgumentException('Invalid size: ' . $size);
        }
        $multipliers = ['' => 1, 'K' => 1024, 'M' => 1024 ** 2, 'G' => 1024 ** 3, 'T' => 1024 ** 4];

        return (int) ((float) $m[1] * $multipliers[strtoupper($m[2])]);
    }
}
