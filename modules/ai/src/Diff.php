<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai;

/**
 * Comparación por palabras de dos textos para la bandeja de propuestas: lo que se quita sale marcado en el texto actual y lo que
 * se pone, en el propuesto. Solo se escapa con e(); las marcas son <del> e <ins>.
 */
final class Diff
{
    /** Tope de celdas de la tabla de comparación (palabras × palabras): por encima se enseña el bloque entero cambiado. */
    private const MAX_CELLS = 600000;

    /**
     * @return array{old:string,new:string,same:bool} HTML ya escapado de cada lado y si los dos textos son iguales
     */
    public static function render(string $old, string $new): array
    {
        if ($old === $new) {
            return ['old' => nl2br(e($old)), 'new' => nl2br(e($new)), 'same' => true];
        }
        $a = self::tokens($old);
        $b = self::tokens($new);

        // Lo que se repite al principio y al final no hace falta compararlo.
        $start = 0;
        $na = count($a);
        $nb = count($b);
        while ($start < $na && $start < $nb && $a[$start] === $b[$start]) {
            ++$start;
        }
        $endA = $na;
        $endB = $nb;
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) {
            --$endA;
            --$endB;
        }
        $midA = array_slice($a, $start, $endA - $start);
        $midB = array_slice($b, $start, $endB - $start);

        $oldHtml = self::join(array_slice($a, 0, $start));
        $newHtml = $oldHtml;
        if (count($midA) * count($midB) > self::MAX_CELLS) {
            $oldHtml .= self::mark('del', $midA);
            $newHtml .= self::mark('ins', $midB);
        } else {
            list($left, $right) = self::lcs($midA, $midB);
            $oldHtml .= $left;
            $newHtml .= $right;
        }
        $tail = self::join(array_slice($a, $endA));
        $tailNew = self::join(array_slice($b, $endB));

        return ['old' => nl2br($oldHtml . $tail), 'new' => nl2br($newHtml . $tailNew), 'same' => false];
    }

    /**
     * @return string[] palabras y espacios, en orden
     */
    private static function tokens(string $text): array
    {
        $parts = preg_split('/(\s+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);

        return is_array($parts) ? $parts : [];
    }

    /**
     * @param string[] $tokens
     */
    private static function join(array $tokens): string
    {
        $html = '';
        foreach ($tokens as $token) {
            $html .= e($token);
        }

        return $html;
    }

    /**
     * @param string[] $tokens
     */
    private static function mark(string $tag, array $tokens): string
    {
        $text = self::join($tokens);

        return trim($text) === '' ? $text : '<' . $tag . '>' . $text . '</' . $tag . '>';
    }

    /**
     * Subsecuencia común más larga entre las palabras de en medio. Los espacios no cuentan como coincidencia (si no, todo casaría).
     *
     * @param string[] $a
     * @param string[] $b
     *
     * @return array{0:string,1:string} HTML del lado viejo y del nuevo
     */
    private static function lcs(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        $table = [];
        for ($i = $n; $i >= 0; --$i) {
            $table[$i] = array_fill(0, $m + 1, 0);
        }
        for ($i = $n - 1; $i >= 0; --$i) {
            for ($j = $m - 1; $j >= 0; --$j) {
                if ($a[$i] === $b[$j] && trim($a[$i]) !== '') {
                    $table[$i][$j] = $table[$i + 1][$j + 1] + 1;
                } else {
                    $table[$i][$j] = max($table[$i + 1][$j], $table[$i][$j + 1]);
                }
            }
        }
        $left = '';
        $right = '';
        $i = 0;
        $j = 0;
        $delete = [];
        $insert = [];
        $flush = static function () use (&$left, &$right, &$delete, &$insert): void {
            $left .= self::mark('del', $delete);
            $right .= self::mark('ins', $insert);
            $delete = [];
            $insert = [];
        };
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $a[$i] === $b[$j] && trim($a[$i]) !== '' && $table[$i][$j] === $table[$i + 1][$j + 1] + 1) {
                $flush();
                $left .= e($a[$i]);
                $right .= e($b[$j]);
                ++$i;
                ++$j;
            } elseif ($i < $n && ($j >= $m || $table[$i + 1][$j] >= $table[$i][$j + 1])) {
                $delete[] = $a[$i];
                ++$i;
            } else {
                $insert[] = $b[$j];
                ++$j;
            }
        }
        $flush();

        return [$left, $right];
    }
}
