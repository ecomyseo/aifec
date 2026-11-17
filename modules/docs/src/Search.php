<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Docs;

/**
 * El buscador de la documentación: coincidencia por palabras en el título y en el texto, sin IA. Una página sale si
 * contiene TODAS las palabras de la consulta; el título pesa más que el texto. Cada resultado trae el fragmento donde
 * aparece la primera palabra.
 */
final class Search
{
    /** Palabras de la consulta que se tienen en cuenta (el resto se ignora). */
    private const MAX_WORDS = 8;

    /** Largo del fragmento que se enseña, en caracteres. */
    private const SNIPPET = 180;

    /** @var Docs */
    private $docs;

    public function __construct(Docs $docs)
    {
        $this->docs = $docs;
    }

    /**
     * Las palabras de una consulta: en minúsculas, sin signos, de dos letras o más y sin repetir.
     *
     * @return string[]
     */
    public static function words(string $query): array
    {
        $text = mb_strtolower($query);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = [];
        foreach (is_array($parts) ? $parts : [] as $part) {
            if (mb_strlen($part) >= 2 && !in_array($part, $words, true)) {
                $words[] = $part;
            }
        }

        return array_slice($words, 0, self::MAX_WORDS);
    }

    /**
     * @return array<int,array{page:array<string,string>,snippet:string,html:string,score:int}> los mejores primero; `html` es el
     *                                                                                          fragmento ya escapado y con las palabras en <mark>
     */
    public function run(string $query, int $limit = 20): array
    {
        $words = self::words($query);
        if ($words === []) {
            return [];
        }
        $results = [];
        foreach ($this->docs->library()->pages() as $page) {
            $text = $this->docs->plain($this->docs->library()->read($page));
            $lower = mb_strtolower($text);
            $title = mb_strtolower($page['title']);
            $score = 0;
            $first = -1;
            foreach ($words as $word) {
                $inText = mb_substr_count($lower, $word);
                $inTitle = mb_substr_count($title, $word);
                if ($inText === 0 && $inTitle === 0) {
                    continue 2;
                }
                $score += $inTitle * 10 + min($inText, 20);
                $at = mb_strpos($lower, $word);
                if ($at !== false && ($first < 0 || $at < $first)) {
                    $first = (int) $at;
                }
            }
            $snippet = $this->snippet($text, $first);
            $results[] = [
                'page' => $page,
                'snippet' => $snippet,
                'html' => self::highlight($snippet, $words),
                'score' => $score,
            ];
        }
        usort($results, static function (array $a, array $b): int {
            return $b['score'] <=> $a['score'] ?: strcmp($a['page']['path'], $b['page']['path']);
        });

        return array_slice($results, 0, max(1, $limit));
    }

    /**
     * El texto escapado con cada palabra buscada dentro de <mark>. Se parte primero y se escapa cada trozo: nada del texto llega
     * al HTML sin pasar por e().
     *
     * @param string[] $words
     */
    public static function highlight(string $text, array $words): string
    {
        if ($words === []) {
            return e($text);
        }
        $quoted = array_map(static function (string $w): string {
            return preg_quote($w, '/');
        }, $words);
        $parts = preg_split('/(' . implode('|', $quoted) . ')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
        $html = '';
        foreach (is_array($parts) ? $parts : [] as $part) {
            $isHit = in_array(mb_strtolower($part), $words, true);
            $html .= $isHit ? '<mark>' . e($part) . '</mark>' : e($part);
        }

        return $html;
    }

    /** El trozo de texto alrededor de la posición dada (o el principio, si la palabra solo estaba en el título). */
    private function snippet(string $text, int $at): string
    {
        $start = $at > 60 ? $at - 60 : 0;
        $piece = mb_substr($text, $start, self::SNIPPET);
        if ($start > 0) {
            $piece = '…' . ltrim($piece);
        }
        if ($start + self::SNIPPET < mb_strlen($text)) {
            $piece = rtrim($piece) . '…';
        }

        return $piece;
    }
}
