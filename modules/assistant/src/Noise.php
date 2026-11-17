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
 * Lo que no es un mensaje de un cliente: el contenido de un fichero pegado (un PDF, una imagen en base64), un trozo de código
 * o una ristra de símbolos. No se le pregunta nada al modelo por ello (cada pregunta cuesta dinero) ni se busca en el catálogo:
 * se contesta con una frase fija.
 *
 * Solo mira la forma, nunca el idioma ni el tema: una frase larga y rara de un cliente sigue su camino.
 */
final class Noise
{
    /** Hasta este largo un mensaje se atiende siempre: no hay ahorro en mirarlo. */
    private const SHORT = 120;

    /** Una «palabra» más larga que esto no la escribe una persona (base64, hexadecimal, código pegado). */
    private const LONG_WORD = 60;

    /** Marcas que solo aparecen juntas en código o en el contenido de un fichero. */
    private const MARKS = ['<?php', '</', '/>', '=>', '->', '::', '();', '){', ') {', '==', '!=', '&&', '||', '{"', '":', '\\x', '\\u', 'SELECT ', 'function ', 'import ', '#include', 'console.', 'endobj', 'stream'];

    public static function looks(string $message): bool
    {
        if (strpos($message, '%PDF-') !== false || strpos($message, 'base64,') !== false) {
            return true;
        }
        $length = mb_strlen($message, 'UTF-8');
        if ($length <= self::SHORT) {
            return false;
        }
        // Palabras imposibles: lo pegado de un fichero no lleva espacios. Una dirección web larga sí es de una persona.
        $words = preg_split('/\s+/u', $message, -1, PREG_SPLIT_NO_EMPTY);
        foreach (is_array($words) ? $words : [] as $word) {
            if (mb_strlen($word, 'UTF-8') > self::LONG_WORD && preg_match('/^(https?:\/\/|www\.)/i', $word) !== 1) {
                return true;
            }
        }
        // Lo que escribe una persona son sobre todo letras y espacios; el código y los ficheros, símbolos y cifras.
        $letters = (int) preg_match_all('/[\p{L}\s]/u', $message);
        if ($letters < $length * 0.6) {
            return true;
        }
        $marks = 0;
        foreach (self::MARKS as $mark) {
            $marks += substr_count($message, $mark);
        }

        return $marks >= 6;
    }
}
