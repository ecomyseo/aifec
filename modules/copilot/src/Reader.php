<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Validation\Validator;

/**
 * Piezas para entender un texto escrito de cualquier manera, en castellano o en inglés: quitar tildes, sacar un porcentaje,
 * un importe, una fecha o un número de pedido. Sin estado y sin base de datos.
 *
 * Hay dos versiones del texto. «fold» solo quita las tildes y conserva mayúsculas y signos, carácter a carácter (así una
 * posición de «fold» es la misma posición del texto original y se puede recuperar lo escrito tal cual: un nombre con tildes).
 * «norm» es el texto para comparar: minúsculas, sin tildes y sin signos, salvo los que forman parte de un número, una fecha o
 * un correo.
 */
final class Reader
{
    private const MONTHS = [
        'enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12,
        'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4, 'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
        'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
    ];

    /** Quita las tildes sin cambiar la longitud ni las mayúsculas: la posición de un carácter no se mueve. */
    public static function fold(string $text): string
    {
        $text = mb_scrub($text, 'UTF-8');

        return strtr($text, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a', 'Á' => 'A', 'À' => 'A', 'Ä' => 'A', "\u{00C2}" => 'A', "\u{00C3}" => 'A', 'Å' => 'A',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e', 'É' => 'E', 'È' => 'E', 'Ë' => 'E', 'Ê' => 'E',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i', 'Í' => 'I', 'Ì' => 'I', 'Ï' => 'I', 'Î' => 'I',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o', 'Ó' => 'O', 'Ò' => 'O', 'Ö' => 'O', 'Ô' => 'O', 'Õ' => 'O',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u', 'Ú' => 'U', 'Ù' => 'U', 'Ü' => 'U', 'Û' => 'U',
            'ñ' => 'n', 'Ñ' => 'N', 'ç' => 'c', 'Ç' => 'C', "\xc2\xa0" => ' ', "\t" => ' ', "\n" => ' ', "\r" => ' ',
        ]);
    }

    /** El texto para comparar: minúsculas, sin tildes y sin signos (quedan los de un número, una fecha o un correo). */
    public static function norm(string $text): string
    {
        $s = mb_strtolower(self::fold($text), 'UTF-8');
        $s = (string) preg_replace('/[^a-z0-9@€%#:+\-.,\/_ ]/u', ' ', $s);
        // Un punto o una coma solo valen dentro de un número («59,90»); un guion, pegado a algo.
        $s = (string) preg_replace('/(?<!\d)[.,]|[.,](?!\d)/', ' ', $s);
        $s = (string) preg_replace('/(?<![a-z0-9])[-+](?![0-9])|(?<![a-z0-9 ])[-+]\s/', ' ', $s);
        $s = (string) preg_replace('/\s+/', ' ', $s);

        return trim($s);
    }

    /**
     * Lo que se escribió (con sus tildes y mayúsculas) en el lugar donde «fold» encontró un grupo de una expresión.
     * Vale porque fold no cambia el número de caracteres.
     *
     * @param string $original el texto tal cual
     * @param string $fold     fold($original)
     *
     * @return string|null null si la expresión no encaja
     */
    public static function capture(string $original, string $fold, string $regex, int $group = 1): ?string
    {
        if (preg_match($regex, $fold, $m, PREG_OFFSET_CAPTURE) !== 1 || !isset($m[$group]) || $m[$group][1] < 0) {
            return null;
        }
        $start = mb_strlen((string) substr($fold, 0, (int) $m[$group][1]), 'UTF-8');
        $length = mb_strlen((string) $m[$group][0], 'UTF-8');

        return trim(mb_substr($original, $start, $length, 'UTF-8'));
    }

    /**
     * Un porcentaje en centésimas de punto («5 %» → 500, «-2,5%» → -250). El signo solo es el escrito (+ o -).
     *
     * @return array{0:int|null,1:string} el valor (null si no hay) y el texto sin él
     */
    public static function takePercent(string $norm): array
    {
        if (preg_match('/([+-]?)\s*(\d+(?:[.,]\d+)?)\s*(?:%|por ?ciento|per ?cent|percent|pct)(?![a-z])/', $norm, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [null, $norm];
        }
        $basis = (int) round(((float) str_replace(',', '.', (string) $m[2][0])) * 100);
        if ($m[1][0] === '-') {
            $basis = -$basis;
        }

        return [$basis, self::cut($norm, (int) $m[0][1], strlen((string) $m[0][0]))];
    }

    /**
     * Un importe escrito con su moneda («59,90 €», «12 euros», «€12») en céntimos. El signo solo es el escrito.
     *
     * @return array{0:int|null,1:string}
     */
    public static function takeMoney(string $norm): array
    {
        $found = null;
        if (preg_match('/([+-]?)\s*(\d[\d.,]*)\s*(?:€|euros?(?![a-z])|eur(?![a-z]))/', $norm, $m, PREG_OFFSET_CAPTURE) === 1) {
            $found = [$m[1][0] . trim((string) $m[2][0], '.,'), (int) $m[0][1], strlen((string) $m[0][0])];
        } elseif (preg_match('/€\s*([+-]?\d[\d.,]*)/', $norm, $m, PREG_OFFSET_CAPTURE) === 1) {
            $found = [trim((string) $m[1][0], '.,'), (int) $m[0][1], strlen((string) $m[0][0])];
        }
        if ($found === null) {
            return [null, $norm];
        }
        $cents = Validator::toCents($found[0]);

        return $cents === null ? [null, $norm] : [$cents, self::cut($norm, $found[1], $found[2])];
    }

    /**
     * Un importe detrás de una preposición o un igual («a 59,90», «to 59.90 €», «= 12»), con moneda o sin ella: el último del texto.
     *
     * @return array{0:int|null,1:string}
     */
    public static function takeAmountAfter(string $norm): array
    {
        if (preg_match_all('/(?<![a-z0-9])(?:a|en|to|at|=|:)\s*([+-]?\d[\d.,]*)(?:\s*(?:€|euros?(?![a-z])|eur(?![a-z])))?(?![\d.,]*[a-z%€])/', $norm, $all, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) < 1) {
            return [null, $norm];
        }
        $m = $all[count($all) - 1];
        $cents = Validator::toCents(trim((string) $m[1][0], '.,'));

        return $cents === null ? [null, $norm] : [$cents, self::cut($norm, (int) $m[0][1], strlen((string) $m[0][0]))];
    }

    /**
     * Un número entero con su unidad o detrás de una preposición («5 unidades», «a 20», «by 4»).
     *
     * @return array{0:int|null,1:string}
     */
    public static function takeInt(string $norm): array
    {
        if (preg_match('/(?<![a-z0-9])(?:(?:a|en|to|at|by|por|=)\s+)?([+-]?\d+)\s*(?:unidades|unidad|uds?|units?|piezas|pcs)?(?![\d.,a-z%€])/', $norm, $m, PREG_OFFSET_CAPTURE) !== 1) {
            return [null, $norm];
        }

        return [(int) $m[1][0], self::cut($norm, (int) $m[0][1], strlen((string) $m[0][0]))];
    }

    /** El número de un pedido («pedido 12», «order #2026-000012», «compra n.º 7»). */
    public static function orderRef(string $norm): string
    {
        if (preg_match('/(?:pedido|pedidos|order|orders|compra|purchase)\s*(?:n(?:umero|o|um|r)?\.?|num(?:ber)?|no)?\s*#?\s*(\d[\d\-]*)/', $norm, $m) === 1) {
            return trim($m[1], '-');
        }
        if (preg_match('/#\s*(\d[\d\-]*)/', $norm, $m) === 1) {
            return trim($m[1], '-');
        }

        return '';
    }

    /** Una fecha escrita de varias maneras («2026-12-31», «31/12/2026», «31 de diciembre de 2026») como «Y-m-d», o null. */
    public static function date(string $text): ?string
    {
        $n = mb_strtolower(self::fold($text), 'UTF-8');
        $y = 0;
        $mo = 0;
        $d = 0;
        if (preg_match('/(\d{4})-(\d{1,2})-(\d{1,2})/', $n, $m) === 1) {
            list($y, $mo, $d) = [(int) $m[1], (int) $m[2], (int) $m[3]];
        } elseif (preg_match('/(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{4})/', $n, $m) === 1) {
            list($y, $mo, $d) = [(int) $m[3], (int) $m[2], (int) $m[1]];
        } elseif (preg_match('/(\d{1,2})\s+(?:de\s+)?([a-z]{3,10})\s+(?:de\s+|of\s+)?(\d{4})/', $n, $m) === 1 && isset(self::MONTHS[$m[2]])) {
            list($y, $mo, $d) = [(int) $m[3], self::MONTHS[$m[2]], (int) $m[1]];
        } elseif (preg_match('/([a-z]{3,10})\s+(\d{1,2})(?:st|nd|rd|th)?,?\s+(\d{4})/', $n, $m) === 1 && isset(self::MONTHS[$m[1]])) {
            list($y, $mo, $d) = [(int) $m[3], self::MONTHS[$m[1]], (int) $m[2]];
        }
        if ($y < 1970 || !checkdate($mo, $d, $y)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /** ¿Aparece alguna de estas expresiones (ya sin tildes y en minúsculas, separadas por «|») como palabra entera? */
    public static function has(string $norm, string $alternatives): bool
    {
        return preg_match('/(?<![a-z0-9])(?:' . $alternatives . ')(?![a-z0-9])/', $norm) === 1;
    }

    /** Sí o no: null si el texto no lo dice. Un «no» o un «desactiva» gana al «activa» que lleva dentro. */
    public static function yesNo(string $norm): ?bool
    {
        if (self::has($norm, 'desactiva|desactivar|apaga|apagar|deshabilita|deshabilitar|bloquea|bloquear|oculta|ocultar|quita|quitar|elimina|sin|no|off|disable|disabled|block|hide|without|remove|false|nunca|never')) {
            return false;
        }
        if (self::has($norm, 'activa|activar|enciende|encender|habilita|habilitar|permite|permitir|muestra|mostrar|con|si|on|enable|enabled|allow|show|with|true|yes|siempre|always')) {
            return true;
        }

        return null;
    }

    /** Quita el tramo [$start, $start + $length) y deja un espacio. */
    public static function cut(string $text, int $start, int $length): string
    {
        return trim((string) preg_replace('/\s+/', ' ', substr($text, 0, $start) . ' ' . substr($text, $start + $length)));
    }

    /**
     * Quita palabras de relleno de los dos extremos de un texto («pon el precio de la silla roja a» → «silla roja»).
     * Solo en los extremos: lo de dentro («mesa de roble») no se toca.
     *
     * @param string[] $leading palabras que se quitan por delante
     * @param string[] $trailing palabras que se quitan por detrás
     */
    public static function trimWords(string $norm, array $leading, array $trailing): string
    {
        $words = $norm === '' ? [] : explode(' ', $norm);
        while ($words !== [] && in_array($words[0], $leading, true)) {
            array_shift($words);
        }
        while ($words !== [] && in_array($words[count($words) - 1], $trailing, true)) {
            array_pop($words);
        }

        return implode(' ', $words);
    }
}
