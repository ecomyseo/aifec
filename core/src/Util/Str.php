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
 * Funciones de cadenas puras: slugs, capitalización, recortes, plantillas y codificación.
 * Todo trabaja en UTF-8; lo que llegue en otra codificación se convierte antes.
 */
final class Str
{
    /** Letras latinas con diacríticos y ligaduras, en minúscula, a su equivalente ASCII. */
    private const TRANSLIT = [
        'à' => 'a', 'á' => 'a', 'â' => 'a', 'ã' => 'a', 'ä' => 'a', 'å' => 'a', 'ā' => 'a', 'ă' => 'a', 'ą' => 'a',
        'ç' => 'c', 'ć' => 'c', 'ĉ' => 'c', 'ċ' => 'c', 'č' => 'c',
        'ď' => 'd', 'đ' => 'd', 'ð' => 'd',
        'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e', 'ē' => 'e', 'ĕ' => 'e', 'ė' => 'e', 'ę' => 'e', 'ě' => 'e',
        'ĝ' => 'g', 'ğ' => 'g', 'ġ' => 'g', 'ģ' => 'g',
        'ĥ' => 'h', 'ħ' => 'h',
        'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i', 'ĩ' => 'i', 'ī' => 'i', 'ĭ' => 'i', 'į' => 'i', 'ı' => 'i',
        'ĵ' => 'j', 'ķ' => 'k',
        'ĺ' => 'l', 'ļ' => 'l', 'ľ' => 'l', 'ŀ' => 'l', 'ł' => 'l',
        'ñ' => 'n', 'ń' => 'n', 'ņ' => 'n', 'ň' => 'n', 'ŉ' => 'n',
        'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ö' => 'o', 'ø' => 'o', 'ō' => 'o', 'ŏ' => 'o', 'ő' => 'o',
        'œ' => 'oe',
        'ŕ' => 'r', 'ŗ' => 'r', 'ř' => 'r',
        'ś' => 's', 'ŝ' => 's', 'ş' => 's', 'š' => 's', 'ș' => 's', 'ß' => 'ss',
        'ţ' => 't', 'ť' => 't', 'ŧ' => 't', 'ț' => 't', 'þ' => 'th',
        'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u', 'ũ' => 'u', 'ū' => 'u', 'ŭ' => 'u', 'ů' => 'u', 'ű' => 'u', 'ų' => 'u',
        'ŵ' => 'w',
        'ý' => 'y', 'ÿ' => 'y', 'ŷ' => 'y',
        'ź' => 'z', 'ż' => 'z', 'ž' => 'z',
        'æ' => 'ae',
    ];

    /** Partículas de los nombres propios: van en minúscula salvo al principio. */
    private const PARTICLES = ['de', 'del', 'la', 'las', 'los', 'y', 'e', 'van', 'von', 'da', 'do', 'dos'];

    /** Slug ASCII en minúsculas: «¡Añadir & Más!» → «anadir-mas». Vacío → ''. */
    public static function slug(string $text, int $maxLength = 120): string
    {
        if ($maxLength <= 0) {
            return '';
        }
        $text = self::toUtf8($text);
        $text = (string) preg_replace('/\p{Mn}+/u', '', mb_strtolower($text, 'UTF-8'));
        $text = strtr($text, self::TRANSLIT);
        $text = trim((string) preg_replace('/[^a-z0-9]+/', '-', $text), '-');

        if (strlen($text) > $maxLength) {
            $cut = substr($text, 0, $maxLength);
            if ($text[$maxLength] !== '-') {
                $pos = strrpos($cut, '-');
                if ($pos !== false) {
                    $cut = substr($cut, 0, $pos);
                }
            }
            $text = trim($cut, '-');
        }

        return $text;
    }

    /** «cms-pages» → «CmsPages». */
    public static function studly(string $text): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? implode('', array_map('ucfirst', $parts)) : '';
    }

    /** «CmsPages» → «cms-pages». */
    public static function kebab(string $text): string
    {
        return implode('-', self::splitWords($text));
    }

    /** «CmsPages» → «cms_pages». */
    public static function snake(string $text): string
    {
        return implode('_', self::splitWords($text));
    }

    /** «cms-pages» → «cmsPages». */
    public static function camel(string $text): string
    {
        return lcfirst(self::studly($text));
    }

    /** Corta por palabra sin pasar de $chars caracteres (multibyte) y añade $end si corta. */
    public static function limit(string $text, int $chars, string $end = '…'): string
    {
        if (mb_strlen($text, 'UTF-8') <= $chars) {
            return $text;
        }
        if ($chars <= 0) {
            return $end;
        }
        $cut = mb_substr($text, 0, $chars, 'UTF-8');
        $next = mb_substr($text, $chars, 1, 'UTF-8');
        if ($next !== '' && preg_match('/\s/u', $next) !== 1 && preg_match('/^(.*)\s/us', $cut, $m) === 1) {
            $cut = $m[1];
        }
        $cut = (string) preg_replace('/\s+$/u', '', $cut);

        return $cut . $end;
    }

    /** Primeras $n palabras, con $end si se recorta. */
    public static function words(string $text, int $n, string $end = '…'): string
    {
        $parts = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($parts) || $parts === []) {
            return '';
        }
        if (count($parts) <= $n) {
            return implode(' ', $parts);
        }
        if ($n <= 0) {
            return $end;
        }

        return implode(' ', array_slice($parts, 0, $n)) . $end;
    }

    public static function contains(string $haystack, string $needle, bool $ignoreCase = false): bool
    {
        if ($needle === '') {
            return true;
        }
        if ($ignoreCase) {
            $haystack = mb_strtolower($haystack, 'UTF-8');
            $needle = mb_strtolower($needle, 'UTF-8');
        }

        return strpos($haystack, $needle) !== false;
    }

    public static function startsWith(string $haystack, string $needle, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $haystack = mb_strtolower($haystack, 'UTF-8');
            $needle = mb_strtolower($needle, 'UTF-8');
        }

        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }

    public static function endsWith(string $haystack, string $needle, bool $ignoreCase = false): bool
    {
        if ($ignoreCase) {
            $haystack = mb_strtolower($haystack, 'UTF-8');
            $needle = mb_strtolower($needle, 'UTF-8');
        }
        $len = strlen($needle);
        if ($len === 0) {
            return true;
        }

        return $len <= strlen($haystack) && substr($haystack, -$len) === $needle;
    }

    /** Texto entre la primera aparición de $from y la siguiente de $to. Si falta alguna, ''. */
    public static function between(string $s, string $from, string $to): string
    {
        $start = 0;
        if ($from !== '') {
            $pos = strpos($s, $from);
            if ($pos === false) {
                return '';
            }
            $start = $pos + strlen($from);
        }
        $end = strlen($s);
        if ($to !== '') {
            $pos = strpos($s, $to, $start);
            if ($pos === false) {
                return '';
            }
            $end = $pos;
        }

        return (string) substr($s, $start, $end - $start);
    }

    /**
     * Nombre propio: «juan carlos de la fuente» → «Juan Carlos de la Fuente».
     * Lo que ya trae mayúsculas internas («McDonald») no se toca.
     */
    public static function titleName(string $name): string
    {
        $tokens = preg_split('/(\s+)/u', $name, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($tokens)) {
            return $name;
        }

        $out = '';
        $first = true;
        foreach ($tokens as $token) {
            if ($token === '') {
                continue;
            }
            if (preg_match('/^\s+$/u', $token) === 1) {
                $out .= $token;
                continue;
            }
            if (!$first && in_array(mb_strtolower($token, 'UTF-8'), self::PARTICLES, true)) {
                $out .= mb_strtolower($token, 'UTF-8');
            } else {
                $out .= self::capitalizeWord($token);
            }
            $first = false;
        }

        return $out;
    }

    /** Enmascara por el centro: mask('clave1234') → «cl****34». */
    public static function mask(string $s, int $visibleStart = 2, int $visibleEnd = 2): string
    {
        $len = mb_strlen($s, 'UTF-8');
        $vs = max(0, $visibleStart);
        $ve = max(0, $visibleEnd);
        if ($len <= $vs + $ve) {
            return str_repeat('*', $len);
        }
        $head = mb_substr($s, 0, $vs, 'UTF-8');
        $tail = $ve > 0 ? mb_substr($s, $len - $ve, $ve, 'UTF-8') : '';

        return $head . str_repeat('*', $len - $vs - $ve) . $tail;
    }

    /**
     * trim() con caracteres de cualquier alfabeto en la lista. El trim() de PHP va byte a byte: con «·», «—» o unas comillas
     * tipográficas en su lista se come medio carácter de los que comparten bytes con ellos («¿», «¡», «…», «€», una letra cirílica) y
     * deja el texto roto. Toda lista de caracteres que no sea ASCII pasa por aquí.
     */
    public static function trim(string $text, string $chars = " \t\n\r\0\x0B"): string
    {
        return self::trimSides($text, $chars, true, true);
    }

    /** Como Str::trim, solo por la izquierda. */
    public static function ltrim(string $text, string $chars = " \t\n\r\0\x0B"): string
    {
        return self::trimSides($text, $chars, true, false);
    }

    /** Como Str::trim, solo por la derecha. */
    public static function rtrim(string $text, string $chars = " \t\n\r\0\x0B"): string
    {
        return self::trimSides($text, $chars, false, true);
    }

    private static function trimSides(string $text, string $chars, bool $left, bool $right): string
    {
        if ($chars === '' || $text === '') {
            return $text;
        }
        $class = '[' . preg_quote($chars, '/') . ']+';
        $out = preg_replace('/' . ($left ? '\A' . $class : '') . ($left && $right ? '|' : '') . ($right ? $class . '\z' : '') . '/u', '', $text);

        // Un texto que no es UTF-8 válido no se puede recortar por caracteres: se devuelve como llegó.
        return is_string($out) ? $out : $text;
    }

    public static function isUtf8(string $s): bool
    {
        return preg_match('//u', $s) === 1;
    }

    /** Si no es UTF-8 válido, lo trata como Windows-1252 y lo convierte. */
    public static function toUtf8(string $s): string
    {
        if (self::isUtf8($s)) {
            return $s;
        }

        return mb_convert_encoding($s, 'UTF-8', 'Windows-1252');
    }

    public static function stripBom(string $s): string
    {
        if (strncmp($s, "\xEF\xBB\xBF", 3) === 0) {
            return (string) substr($s, 3);
        }

        return $s;
    }

    /** Sustituye {nombre} por su valor. Las llaves sin variable se dejan tal cual. */
    public static function template(string $text, array $vars): string
    {
        return (string) preg_replace_callback(
            '/\{([^{}]*)\}/',
            function (array $m) use ($vars): string {
                if (!array_key_exists($m[1], $vars)) {
                    return $m[0];
                }
                $value = $vars[$m[1]];
                if ($value === null) {
                    return '';
                }

                return is_scalar($value) ? (string) $value : $m[0];
            },
            $text
        );
    }

    /** @return string[] */
    private static function splitWords(string $text): array
    {
        $text = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])|(?<=[A-Z])(?=[A-Z][a-z])/', ' ', $text);
        $parts = preg_split('/[^A-Za-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? array_map('strtolower', $parts) : [];
    }

    private static function capitalizeWord(string $token): string
    {
        $pieces = preg_split("/([-'’])/u", $token, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($pieces)) {
            return $token;
        }
        $out = '';
        foreach ($pieces as $i => $piece) {
            // Con PREG_SPLIT_DELIM_CAPTURE los separadores quedan en las posiciones impares.
            $out .= $i % 2 === 1 ? $piece : self::capitalizePart($piece);
        }

        return $out;
    }

    private static function capitalizePart(string $part): string
    {
        if ($part === '') {
            return $part;
        }
        $lower = mb_strtolower($part, 'UTF-8');
        $upper = mb_strtoupper($part, 'UTF-8');
        if ($part !== $lower && $part !== $upper) {
            return $part; // ya viene con mayúsculas internas: se respeta
        }
        $base = $part === $upper ? $lower : $part;

        return mb_strtoupper(mb_substr($base, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($base, 1, null, 'UTF-8');
    }
}
