<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Themes;

/**
 * Cuentas con colores «#rrggbb»: luminancia y contraste según WCAG 2.1, mezclas y ajuste hasta llegar a un contraste.
 * Librería pura: no enseña textos.
 */
final class Color
{
    /** Contraste mínimo AA para texto normal. */
    public const AA = 4.5;

    /**
     * @return int[]|null [r, g, b] o null si no es «#rrggbb»
     */
    public static function parse(string $hex): ?array
    {
        if (preg_match('/^#([0-9a-fA-F]{2})([0-9a-fA-F]{2})([0-9a-fA-F]{2})$/', $hex, $m) !== 1) {
            return null;
        }

        return [(int) hexdec($m[1]), (int) hexdec($m[2]), (int) hexdec($m[3])];
    }

    public static function isHex(string $hex): bool
    {
        return self::parse($hex) !== null;
    }

    /**
     * @param int[] $rgb
     */
    public static function toHex(array $rgb): string
    {
        return sprintf('#%02x%02x%02x', max(0, min(255, (int) $rgb[0])), max(0, min(255, (int) $rgb[1])), max(0, min(255, (int) $rgb[2])));
    }

    /** Luminancia relativa (0 negro, 1 blanco). Un color que no se entiende cuenta como negro. */
    public static function luminance(string $hex): float
    {
        $rgb = self::parse($hex);
        if ($rgb === null) {
            return 0.0;
        }
        $channels = [];
        foreach ($rgb as $value) {
            $c = $value / 255;
            $channels[] = $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }

    /** Relación de contraste entre dos colores (de 1 a 21). */
    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** Mezcla de $a hacia $b: 0 = $a, 1 = $b. */
    public static function mix(string $a, string $b, float $t): string
    {
        $ca = self::parse($a);
        $cb = self::parse($b);
        if ($ca === null || $cb === null) {
            return $a;
        }
        $t = max(0.0, min(1.0, $t));

        return self::toHex([
            (int) round($ca[0] + ($cb[0] - $ca[0]) * $t),
            (int) round($ca[1] + ($cb[1] - $ca[1]) * $t),
            (int) round($ca[2] + ($cb[2] - $ca[2]) * $t),
        ]);
    }

    /** El mejor texto sobre un fondo: blanco o negro, el de más contraste. */
    public static function textOn(string $background): string
    {
        return self::ratio('#ffffff', $background) >= self::ratio('#000000', $background) ? '#ffffff' : '#000000';
    }

    /**
     * Acerca $color a negro o a blanco (el que más contraste da con el fondo) hasta llegar a $min. Devuelve el mismo color
     * si ya llegaba, y null si ni siquiera llegando al extremo se alcanza.
     */
    public static function ensure(string $color, string $background, float $min = self::AA): ?string
    {
        if (!self::isHex($color) || !self::isHex($background)) {
            return null;
        }
        if (self::ratio($color, $background) >= $min) {
            return strtolower($color);
        }
        $toward = self::luminance($background) > 0.4 ? '#000000' : '#ffffff';
        for ($i = 1; $i <= 20; ++$i) {
            $candidate = self::mix($color, $toward, $i * 0.05);
            if (self::ratio($candidate, $background) >= $min) {
                return $candidate;
            }
        }

        return null;
    }
}
