<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Search;

use Aifec\Search\Tokenizer;

/**
 * El análisis de texto de un idioma: la misma función se aplica a lo que se indexa y a lo que se busca, así «sillas» y
 * «silla» dan la misma raíz. Envuelve a Aifec\Search\Tokenizer y le añade lo que necesita el buscador: las palabras tal
 * como se escribieron (para enseñar «Mostrando resultados de silla») y la forma más legible de cada raíz.
 */
final class Analyzer
{
    /** Palabra: letras, marcas y números, con guiones, puntos o guiones bajos en medio («SE-01»). Es la misma que usa el Tokenizer. */
    private const CHUNK_RE = '/[\p{L}\p{M}\p{N}]+(?:[-._][\p{L}\p{M}\p{N}]+)*/u';

    /** @var string */
    private $lang;

    /** @var Tokenizer */
    private $tokenizer;

    public function __construct(string $lang)
    {
        $this->lang = $lang;
        $this->tokenizer = new Tokenizer($lang);
    }

    public function lang(): string
    {
        return $this->lang;
    }

    /**
     * Raíces de las palabras de un texto, sin palabras vacías.
     *
     * @return string[]
     */
    public function tokens(string $text): array
    {
        return $this->tokenizer->tokens($text);
    }

    /** Raíz de una palabra ya normalizada (minúsculas y sin tildes). */
    public function stem(string $word): string
    {
        return $this->tokenizer->stem($word);
    }

    /** Minúsculas, sin tildes ni signos y con los espacios colapsados. */
    public function normalize(string $text): string
    {
        return $this->tokenizer->normalize($text);
    }

    /** Minúsculas y sin tildes, conservando los signos. */
    public function fold(string $text): string
    {
        return Tokenizer::stripAccents(mb_strtolower(self::clean($text), 'UTF-8'));
    }

    /**
     * Las palabras de un texto tal como se escribieron (en minúsculas y con sus tildes), en orden.
     *
     * @return string[]
     */
    public function chunks(string $text, int $max = 0): array
    {
        if (preg_match_all(self::CHUNK_RE, mb_strtolower(self::clean($text), 'UTF-8'), $m) === false) {
            return [];
        }
        $words = $m[0];

        return $max > 0 ? array_slice($words, 0, $max) : $words;
    }

    /**
     * Para cada raíz de palabras solo letras, la forma más corta con que aparece escrita («silla» y no «sillas»).
     * Sirve para enseñar una raíz como palabra.
     *
     * @return array<string,string> raíz => palabra
     */
    public function surfaces(string $text): array
    {
        $out = [];
        foreach ($this->chunks($text) as $chunk) {
            $folded = Tokenizer::stripAccents($chunk);
            if (preg_match('/^[a-z]{2,}$/', $folded) !== 1) {
                continue;
            }
            $stem = $this->tokenizer->stem($folded);
            if (!isset($out[$stem]) || mb_strlen($chunk, 'UTF-8') < mb_strlen($out[$stem], 'UTF-8')) {
                $out[$stem] = $chunk;
            }
        }

        return $out;
    }

    /** Texto en UTF-8 válido: lo que no lo sea se limpia en vez de romper la búsqueda. */
    public static function clean(string $text): string
    {
        if ($text !== '' && !mb_check_encoding($text, 'UTF-8')) {
            return mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        return $text;
    }
}
