<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Minificador de CSS sin dependencias. Quita comentarios, espacios sobrantes y el «;» antes de «}», y en los valores de las
 * declaraciones escribe 0.5 como .5, 1.50 como 1.5, 0px como 0 y #ffffff como #fff. No toca lo que no es seguro tocar: las
 * cadenas, el interior de url(), los selectores y las condiciones de @media y @supports, los valores de las propiedades
 * personalizadas (--x), calc(), min(), max(), clamp(), var() y env(), ni las unidades de flex, flex-basis y font.
 */
final class CssMinifier
{
    /** Funciones cuyo interior no se reescribe (un 0 sin unidad dentro de una operación es un error). */
    private const KEEP_FUNCTIONS = ['calc(' => 1, 'min(' => 1, 'max(' => 1, 'clamp(' => 1, 'var(' => 1, 'env(' => 1];

    /** Propiedades donde «0px» no se puede escribir «0». */
    private const KEEP_UNITS = ['flex' => 1, 'flex-basis' => 1, 'font' => 1];

    public static function minify(string $css): string
    {
        // 1. Lo que no se puede tocar (comentarios fuera, cadenas y url()) se aparta con una marca sin cifras.
        $held = [];
        $css = (string) preg_replace_callback(
            '~/\*.*?\*/|"(?:[^"\\\\\n]|\\\\.|\\\\\n)*"|\'(?:[^\'\\\\\n]|\\\\.|\\\\\n)*\'|url\(\s*[^)"\'\s][^)]*\)~s',
            static function (array $m) use (&$held): string {
                if (strncmp($m[0], '/*', 2) === 0) {
                    return ' ';
                }
                $held[] = $m[0];

                return "\x01" . self::letters(count($held) - 1) . "\x02";
            },
            $css
        );
        // 2. Espacios.
        $css = (string) preg_replace('~\s+~', ' ', $css);
        $css = (string) preg_replace('~\s*([{};,])\s*~', '$1', $css);
        $css = (string) preg_replace('~:\s+~', ':', $css);
        $css = (string) preg_replace('~\s*!\s*important~i', '!important', $css);
        $css = trim(str_replace(';}', '}', $css));
        $css = (string) preg_replace('~;+(?=\})~', '', $css);
        // 3. Valores de las declaraciones.
        $css = self::values($css);
        // 4. Lo apartado vuelve.
        return (string) preg_replace_callback('~\x01([a-z]+)\x02~', static function (array $m) use ($held): string {
            return $held[self::number($m[1])] ?? '';
        }, $css);
    }

    /** Reescribe el valor de cada declaración (lo que sigue al primer «:» de un trozo que acaba en «;» o «}»). */
    private static function values(string $css): string
    {
        $out = '';
        $len = strlen($css);
        $start = 0;
        for ($i = 0; $i < $len; ++$i) {
            $c = $css[$i];
            if ($c !== '{' && $c !== '}' && $c !== ';') {
                continue;
            }
            $chunk = substr($css, $start, $i - $start);
            $out .= $c === '{' ? $chunk : self::declaration($chunk);
            $out .= $c;
            $start = $i + 1;
        }

        return $out . substr($css, $start);
    }

    private static function declaration(string $chunk): string
    {
        $colon = strpos($chunk, ':');
        if ($colon === false || $chunk === '' || $chunk[0] === '@') {
            return $chunk;
        }
        $prop = strtolower(trim(substr($chunk, 0, $colon)));
        if ($prop === '' || strncmp($prop, '--', 2) === 0 || preg_match('~^[a-z-]+$~', $prop) !== 1) {
            return $chunk;
        }

        return rtrim(substr($chunk, 0, $colon)) . ':' . self::value(substr($chunk, $colon + 1), !isset(self::KEEP_UNITS[$prop]));
    }

    private static function value(string $v, bool $units): string
    {
        $out = '';
        $buffer = '';
        $len = strlen($v);
        $i = 0;
        while ($i < $len) {
            $keep = null;
            foreach (self::KEEP_FUNCTIONS as $fn => $_) {
                if (strncasecmp(substr($v, $i, strlen($fn)), $fn, strlen($fn)) === 0 && ($i === 0 || preg_match('~\w~', $v[$i - 1]) !== 1)) {
                    $keep = $fn;
                    break;
                }
            }
            if ($keep === null) {
                $buffer .= $v[$i++];
                continue;
            }
            $depth = 0;
            $j = $i;
            while ($j < $len) {
                if ($v[$j] === '(') {
                    ++$depth;
                } elseif ($v[$j] === ')' && --$depth === 0) {
                    break;
                }
                ++$j;
            }
            $out .= self::plain($buffer, $units) . substr($v, $i, $j + 1 - $i);
            $buffer = '';
            $i = $j + 1;
        }

        return $out . self::plain($buffer, $units);
    }

    /** Números y colores de un trozo de valor sin funciones intocables. */
    private static function plain(string $v, bool $units): string
    {
        $edge = '(?<![\w.\\\\#%-])';
        // Colores: #aabbcc → #abc y #aabbccdd → #abcd.
        $v = (string) preg_replace('~#([0-9a-fA-F])\1([0-9a-fA-F])\2([0-9a-fA-F])\3(?:([0-9a-fA-F])\4)?(?![\w-])~', '#$1$2$3$4', $v);
        // Ceros sobrantes: 1.50 → 1.5, 1.0 → 1, 0.5 → .5, 00.5.
        $v = (string) preg_replace('~' . $edge . '(-?\d+\.\d*?)0+(?![\d.]|e[+-]?\d)~', '$1', $v);
        $v = (string) preg_replace('~' . $edge . '(-?\d+)\.(?!\d|e[+-]?\d)~', '$1', $v);
        $v = (string) preg_replace('~' . $edge . '(-?)0+\.(\d)~', '$1.$2', $v);
        if ($units) {
            $v = (string) preg_replace('~' . $edge . '-?0(?:px|em|rem|pt|pc|cm|mm|in|vw|vh|vmin|vmax|ex|ch)(?![\w%-])~i', '0', $v);
        }

        return $v;
    }

    /** 0 → a, 1 → b, … 26 → ba (solo letras: una marca no puede parecerse a un número). */
    private static function letters(int $n): string
    {
        $s = '';
        do {
            $s = chr(97 + $n % 26) . $s;
            $n = intdiv($n, 26);
        } while ($n > 0);

        return $s;
    }

    private static function number(string $letters): int
    {
        $n = 0;
        for ($i = 0, $l = strlen($letters); $i < $l; ++$i) {
            $n = $n * 26 + (ord($letters[$i]) - 97);
        }

        return $n;
    }
}
