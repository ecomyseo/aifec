<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

/**
 * Lo que el cliente PIDE de un producto en su mensaje: una talla («mi talla es 39», «en la M», «size 42»), un color («en
 * azul») o, sin decir cuál, que exista («si hay talla…», «if you have my size»).
 *
 * Sirve para una sola cosa: que añadir al carrito no dependa de que el modelo se acuerde de comprobarlo. Si el cliente pide
 * una opción y el producto no la tiene, NO se añade (lo decide el código, no el modelo). Nace de un fallo del 13/11/2026:
 * «mi talla es 39, si hay talla añade al carrito» sobre un zapato sin tallas → «¡Perfecto! Ya lo he añadido».
 *
 * Funciones puras: texto dentro, lista fuera.
 */
final class OptionAsk
{
    /** Palabras con las que se pide una talla (ya sin tildes y en minúscula). */
    private const SIZE_WORDS = 'talla|tallas|numero|num|pie|size|sizes|taille|grosse|misura';

    /** Tallas con letra. */
    private const LETTER_SIZES = 'xxxs|xxs|xs|s|m|l|xl|xxl|xxxl|xxxxl|unica|one size';

    /** Colores (castellano e inglés, sin tildes). */
    private const COLOURS = 'negro|negra|blanco|blanca|rojo|roja|azul|verde|gris|marron|beige|rosa|amarillo|amarilla|naranja|morado|morada'
        . '|lila|granate|burdeos|turquesa|celeste|marino|plata|plateado|dorado|oro|crema|caqui|camel|fucsia'
        . '|black|white|red|blue|green|grey|gray|brown|pink|yellow|orange|purple|navy|silver|gold|cream|khaki';

    /**
     * Las opciones que pide el mensaje.
     *
     * @return array<int,array{kind:string,value:string,text:string}> kind: size o colour; value: lo pedido («39», «m», «azul»)
     *                                                                 o '' si solo pregunta si la hay; text: como lo dijo
     */
    public static function wanted(string $message): array
    {
        $plain = self::plain($message);
        if ($plain === '') {
            return [];
        }
        $out = [];
        $seen = [];
        $add = static function (string $kind, string $value, string $text) use (&$out, &$seen): void {
            $key = $kind . ':' . $value;
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = ['kind' => $kind, 'value' => $value, 'text' => trim($text)];
            }
        };
        // «talla 39», «mi talla es la 39», «numero 42,5», «size: 10».
        if (preg_match_all('/\b(' . self::SIZE_WORDS . ')\b(?:\s+(?:es|de|del|is|la|el|una?|an?|:))*\s*:?\s*(\d{1,3}(?:[.,]5)?)\b/u', $plain, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $add('size', str_replace(',', '.', $hit[2]), $hit[1] . ' ' . $hit[2]);
            }
        }
        // «talla M», «size XL», «en la talla unica».
        if (preg_match_all('/\b(' . self::SIZE_WORDS . ')\b(?:\s+(?:es|de|is|la|el|una?|an?|:))*\s*:?\s*(' . self::LETTER_SIZES . ')\b/u', $plain, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $add('size', $hit[2], $hit[1] . ' ' . strtoupper($hit[2]));
            }
        }
        // «un 39», «el 42» junto a calzado o ropa no se adivina: solo cuenta con la palabra delante. Lo que sí cuenta es
        // preguntar si existe: «si hay talla», «si teneis mi numero», «if you have my size», «en mi talla».
        $hasSize = false;
        foreach ($out as $row) {
            $hasSize = $hasSize || $row['kind'] === 'size';
        }
        if (!$hasSize && preg_match('/\b(?:si|if|cuando|when)\b[^.?!\n]{0,40}\b(' . self::SIZE_WORDS . ')\b|\b(?:mi|my|su|en mi|in my)\s+(' . self::SIZE_WORDS . ')\b/u', $plain, $hit) === 1) {
            $word = isset($hit[2]) && $hit[2] !== '' ? $hit[2] : $hit[1];
            $add('size', '', $word);
        }
        // «en azul», «color negro», «in black», «de color rojo».
        if (preg_match_all('/\b(?:(?:de\s+)?colou?r|en|in)\s+(?:colou?r\s+)?(' . self::COLOURS . ')\b/u', $plain, $m, PREG_SET_ORDER) > 0) {
            foreach ($m as $hit) {
                $add('colour', $hit[1], $hit[1]);
            }
        }

        return $out;
    }

    /** ¿Es este valor de una opción («39», «Azul marino», «M») lo que se pidió? Sin tildes ni mayúsculas; «39» casa con «39» y con «39 EU». */
    public static function same(string $asked, string $label): bool
    {
        $a = self::plain($asked);
        $b = self::plain($label);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_replace(',', '.', $b) === $a) {
            return true;
        }

        return preg_match('/(?<![\w.])' . preg_quote($a, '/') . '(?![\w.])/u', str_replace(',', '.', $b)) === 1;
    }

    /** ¿Aparece lo pedido, como palabra suelta, en este texto (el nombre del producto)? */
    public static function inText(string $asked, string $text): bool
    {
        return self::same($asked, $text);
    }

    /** En minúscula, sin tildes y con los espacios en uno. */
    private static function plain(string $text): string
    {
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'à' => 'a', 'è' => 'e', 'ì' => 'i', 'ò' => 'o', 'ù' => 'u', 'ö' => 'o', 'ä' => 'a', 'ß' => 'ss']);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
