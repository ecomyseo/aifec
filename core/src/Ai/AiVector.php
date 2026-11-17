<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Ai;

/** Utilidades de vectores: normalizar y un resumen determinista sin IA (para el proveedor simulado). */
final class AiVector
{
    /**
     * Lleva el vector a longitud 1. Un vector de ceros se devuelve tal cual.
     *
     * @param array<int|string,mixed> $vector
     *
     * @return array<int,float>
     */
    public static function normalize(array $vector): array
    {
        $values = [];
        $sum = 0.0;
        foreach ($vector as $v) {
            $f = is_numeric($v) ? (float) $v : 0.0;
            $values[] = $f;
            $sum += $f * $f;
        }
        if ($sum <= 0.0) {
            return $values;
        }
        $norm = sqrt($sum);

        return array_map(static fn (float $f): float => $f / $norm, $values);
    }

    /**
     * Resumen determinista de un texto: bolsa de palabras y de trigramas de caracteres llevada a
     * $dims posiciones con crc32, con signo y normalizada. Los textos parecidos quedan cerca.
     *
     * @return array<int,float>
     */
    public static function hash(string $text, int $dims = 256): array
    {
        $dims = max(1, $dims);
        $vector = array_fill(0, $dims, 0.0);
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        if (class_exists('Normalizer')) {
            $decomposed = \Normalizer::normalize($text, \Normalizer::FORM_D);
            if (is_string($decomposed)) {
                $stripped = preg_replace('/\p{Mn}+/u', '', $decomposed);
                $text = is_string($stripped) ? $stripped : $text;
            }
        }
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        foreach (is_array($words) ? $words : [] as $word) {
            self::add($vector, 'w:' . $word, $dims, 2.0);
            $padded = '^' . $word . '$';
            $chars = preg_split('//u', $padded, -1, PREG_SPLIT_NO_EMPTY);
            $chars = is_array($chars) ? $chars : [];
            for ($i = 0, $n = count($chars) - 2; $i < $n; ++$i) {
                self::add($vector, 't:' . $chars[$i] . $chars[$i + 1] . $chars[$i + 2], $dims, 1.0);
            }
        }

        return self::normalize($vector);
    }

    /**
     * @param array<int,float> $vector
     */
    private static function add(array &$vector, string $feature, int $dims, float $weight): void
    {
        $h = crc32($feature) & 0xFFFFFFFF;
        $index = $h % $dims;
        $sign = (($h >> 16) & 1) === 1 ? 1.0 : -1.0;
        $vector[$index] += $sign * $weight;
    }
}
