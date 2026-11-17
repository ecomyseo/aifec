<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Search;

/**
 * Utilidades para vectores de significado (embeddings): coseno, normalización, empaquetado
 * binario, los K más cercanos y un vector de respaldo que no necesita ninguna IA.
 */
final class Vector
{
    /**
     * Coseno entre dos vectores. Devuelve 0 si alguno es nulo o si no tienen la misma dimensión
     * (no se pueden comparar, pero eso no debe tumbar una búsqueda).
     *
     * @param array<int,float|int> $a
     * @param array<int,float|int> $b
     */
    public static function cosine(array $a, array $b): float
    {
        $n = count($a);
        if ($n === 0 || $n !== count($b)) {
            return 0.0;
        }
        $a = array_values($a);
        $b = array_values($b);

        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; ++$i) {
            $x = (float) $a[$i];
            $y = (float) $b[$i];
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }

        return max(-1.0, min(1.0, $dot / sqrt($na * $nb)));
    }

    /**
     * Vector de longitud 1. El vector nulo se devuelve sin cambios (todo ceros).
     *
     * @param array<int,float|int> $v
     *
     * @return float[]
     */
    public static function normalize(array $v): array
    {
        $out = [];
        $sum = 0.0;
        foreach ($v as $x) {
            $x = (float) $x;
            $out[] = $x;
            $sum += $x * $x;
        }
        if ($sum <= 0.0) {
            return $out;
        }
        $norm = sqrt($sum);
        foreach ($out as $i => $x) {
            $out[$i] = $x / $norm;
        }

        return $out;
    }

    /**
     * Empaqueta como floats de 32 bits (little endian). Pierde precisión por debajo de ~1e-7.
     *
     * @param array<int,float|int> $v
     */
    public static function pack(array $v): string
    {
        $floats = [];
        foreach ($v as $x) {
            $floats[] = (float) $x;
        }
        if ($floats === []) {
            return '';
        }

        return pack('g*', ...$floats);
    }

    /**
     * @return float[]
     */
    public static function unpack(string $bin): array
    {
        if ($bin === '') {
            return [];
        }
        if (strlen($bin) % 4 !== 0) {
            throw new \InvalidArgumentException('Packed vector length must be a multiple of 4 bytes');
        }
        $values = unpack('g*', $bin);

        return is_array($values) ? array_values($values) : [];
    }

    /**
     * Los K candidatos más parecidos a la consulta, de mayor a menor coseno (a igualdad, por id).
     * Los candidatos de otra dimensión se saltan.
     *
     * @param array<int,float|int>                    $query
     * @param array<int|string,array<int,float|int>> $candidates id => vector
     *
     * @return array<int,array{id:string,score:float}>
     */
    public static function topK(array $query, array $candidates, int $k): array
    {
        $n = count($query);
        if ($k < 1 || $n === 0) {
            return [];
        }
        $q = array_values($query);
        $qNorm = 0.0;
        foreach ($q as $x) {
            $qNorm += (float) $x * (float) $x;
        }
        if ($qNorm <= 0.0) {
            return [];
        }

        $results = [];
        foreach ($candidates as $id => $vector) {
            if (!is_array($vector) || count($vector) !== $n) {
                continue;
            }
            $vector = array_values($vector);
            $dot = 0.0;
            $cNorm = 0.0;
            for ($i = 0; $i < $n; ++$i) {
                $y = (float) $vector[$i];
                $dot += (float) $q[$i] * $y;
                $cNorm += $y * $y;
            }
            $score = $cNorm > 0.0 ? max(-1.0, min(1.0, $dot / sqrt($qNorm * $cNorm))) : 0.0;
            $results[] = ['id' => (string) $id, 'score' => $score];
        }

        usort($results, static function (array $a, array $b): int {
            $sa = round($a['score'], 12);
            $sb = round($b['score'], 12);
            if ($sa !== $sb) {
                return $sa < $sb ? 1 : -1;
            }

            return strcmp($a['id'], $b['id']);
        });

        return array_slice($results, 0, $k);
    }

    /**
     * Vector determinista sin IA: bolsa de raíces y de trigramas de caracteres, llevada a `$dims`
     * posiciones con crc32 (con signo) y normalizada. Sirve de respaldo y para las pruebas.
     * Un texto sin palabras da el vector nulo.
     *
     * @return float[]
     */
    public static function hashEmbedding(string $text, int $dims = 256, string $lang = 'es'): array
    {
        if ($dims < 1) {
            throw new \InvalidArgumentException('Embedding dimensions must be at least 1');
        }

        $vector = array_fill(0, $dims, 0.0);
        $tokenizer = new Tokenizer($lang);
        foreach ($tokenizer->tokens($text) as $token) {
            self::bump($vector, 'w:' . $token, 1.0, $dims);

            $chars = mb_str_split('^' . $token . '$', 1, 'UTF-8');
            $count = count($chars);
            for ($i = 0; $i + 2 < $count; ++$i) {
                self::bump($vector, 't:' . $chars[$i] . $chars[$i + 1] . $chars[$i + 2], 0.35, $dims);
            }
        }

        return self::normalize($vector);
    }

    /**
     * @param float[] $vector
     */
    private static function bump(array &$vector, string $feature, float $weight, int $dims): void
    {
        $hash = crc32($feature) & 0xFFFFFFFF;
        $sign = (($hash >> 17) & 1) === 1 ? 1.0 : -1.0;
        $vector[$hash % $dims] += $sign * $weight;
    }
}
