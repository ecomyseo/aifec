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
 * ¿Es este mensaje una afirmación clara («sí», «confirmo», «adelante», «vale, pídelo»)? Lo decide una regla, sin IA: el
 * pedido se hace con esto y no con lo que el modelo crea haber entendido.
 *
 * Es clara cuando TODO el mensaje son frases de la lista (con algún relleno de cortesía: «por favor», «gracias»). Un «sí,
 * pero cambia la dirección» no lo es: sobra una parte, y entonces no se confirma nada. La lista de frases está en el catálogo
 * de idioma (una sola cadena, separada por «|»); además de la del idioma de la tienda se acepta siempre la inglesa.
 */
final class Affirmation
{
    /** Las frases en inglés. La traducción de esta misma cadena es la lista del idioma de la tienda. */
    public const WORDS = 'yes|yes please|i confirm|confirm|confirmed|go ahead|place it|place the order|order it|do it|ok|okay|sure|that is right|that is correct|perfect|fine';

    /** Relleno de cortesía que no cambia el sentido. */
    private const FILLER = ['por favor', 'gracias', 'muchas gracias', 'please', 'thanks', 'thank you', 'ya', 'ahora'];

    /** Lo más largo que puede ser una afirmación, en palabras. */
    private const MAX_WORDS = 8;

    public static function isClear(string $message): bool
    {
        if (strpos($message, '?') !== false || strpos($message, '¿') !== false) {
            // Una pregunta («¿lo confirmo?») no es una confirmación.
            return false;
        }
        $n = RuleBrain::norm($message);
        if ($n === '') {
            return false;
        }
        $words = explode(' ', $n);
        if (count($words) > self::MAX_WORDS) {
            return false;
        }

        $phrases = [];
        foreach ([t(self::WORDS), self::WORDS] as $list) {
            foreach (explode('|', $list) as $phrase) {
                $phrase = RuleBrain::norm($phrase);
                if ($phrase !== '') {
                    $phrases[$phrase] = explode(' ', $phrase);
                }
            }
        }
        $fillers = [];
        foreach (self::FILLER as $filler) {
            $fillers[$filler] = explode(' ', $filler);
        }
        // Las más largas primero: «yes please» antes que «yes».
        uasort($phrases, static function (array $a, array $b): int {
            return count($b) <=> count($a);
        });
        uasort($fillers, static function (array $a, array $b): int {
            return count($b) <=> count($a);
        });

        $i = 0;
        $total = count($words);
        $matched = false;
        while ($i < $total) {
            $step = self::matchAt($words, $i, $phrases);
            if ($step > 0) {
                $matched = true;
                $i += $step;
                continue;
            }
            $skip = self::matchAt($words, $i, $fillers);
            if ($skip > 0) {
                $i += $skip;
                continue;
            }

            return false;
        }

        return $matched;
    }

    /**
     * Cuántas palabras, a partir de $at, forman una de las frases (0 si ninguna).
     *
     * @param string[]                 $words
     * @param array<string,string[]>   $phrases
     */
    private static function matchAt(array $words, int $at, array $phrases): int
    {
        foreach ($phrases as $parts) {
            $length = count($parts);
            if ($at + $length > count($words)) {
                continue;
            }
            if (array_slice($words, $at, $length) === $parts) {
                return $length;
            }
        }

        return 0;
    }
}
