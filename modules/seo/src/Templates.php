<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Seo;

use Aifec\Util\Str;

/**
 * Plantillas de título y de descripción: sustituye las variables, limpia los separadores que se quedan colgando cuando
 * una variable sale vacía y recorta sin partir palabras. Es una librería pura: no lee la base de datos ni la petición.
 */
final class Templates
{
    /** Largo máximo de un título que sale de una plantilla. */
    public const TITLE_MAX = 65;

    /** Largo máximo de una descripción que sale de una plantilla. */
    public const DESCRIPTION_MAX = 160;

    /** Tipos de página que admiten plantilla. */
    public const TYPES = ['home', 'product', 'category', 'brand', 'page', 'post', 'blog'];

    /** Variables que entiende una plantilla. */
    public const VARIABLES = ['name', 'shop', 'category', 'brand', 'price', 'page'];

    /** Una «palabra» que solo es separador: «|», «-», «·», «:»… */
    private const SEPARATOR = '/^[|·•–—\-:,;\/]+$/u';

    /**
     * Rellena la plantilla. Una variable que no se conoce o viene vacía sale vacía, y los separadores que quedan solos se quitan.
     *
     * @param array<string,mixed> $vars nombre => valor
     */
    public static function render(string $template, array $vars): string
    {
        $text = preg_replace_callback('/\{([a-z_]+)\}/', static function (array $m) use ($vars): string {
            return isset($vars[$m[1]]) && is_scalar($vars[$m[1]]) ? trim((string) $vars[$m[1]]) : '';
        }, $template);

        return self::tidy(is_string($text) ? $text : '');
    }

    /** Quita paréntesis vacíos y separadores repetidos, iniciales o finales: «Mesa |  | Tienda» → «Mesa | Tienda». */
    public static function tidy(string $text): string
    {
        $text = preg_replace('/\(\s*\)|\[\s*\]/u', '', $text);
        $tokens = preg_split('/\s+/u', trim(is_string($text) ? $text : ''), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach (is_array($tokens) ? $tokens : [] as $token) {
            if (self::isSeparator($token) && ($out === [] || self::isSeparator($out[count($out) - 1]))) {
                continue;
            }
            $out[] = $token;
        }
        while ($out !== [] && self::isSeparator($out[count($out) - 1])) {
            array_pop($out);
        }
        // Una palabra con una coma o dos puntos pegados que ahora van delante de un separador o del final.
        $last = count($out) - 1;
        foreach ($out as $i => $token) {
            if (!self::isSeparator($token) && ($i === $last || self::isSeparator($out[$i + 1]))) {
                $trimmed = rtrim($token, ',;:');
                $out[$i] = $trimmed !== '' ? $trimmed : $token;
            }
        }

        return implode(' ', $out);
    }

    /**
     * Recorta a $max caracteres sin partir una palabra. Con $end (por ejemplo «…») la marca cuenta dentro del máximo.
     */
    public static function cut(string $text, int $max, string $end = ''): string
    {
        $clean = preg_replace('/\s+/u', ' ', $text);
        $text = trim(is_string($clean) ? $clean : '');
        if (mb_strlen($text, 'UTF-8') <= $max) {
            return $text;
        }
        $room = max(1, $max - mb_strlen($end, 'UTF-8'));
        $cut = Str::rtrim(Str::limit($text, $room, ''), " ,;:|·•–—-/");

        return $cut . $end;
    }

    private static function isSeparator(string $token): bool
    {
        return preg_match(self::SEPARATOR, $token) === 1;
    }
}
