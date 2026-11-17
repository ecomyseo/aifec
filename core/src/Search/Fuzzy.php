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
 * Tolerancia a erratas contra un vocabulario: el término conocido más cercano por distancia de
 * edición con trasposición (Damerau-Levenshtein restringida), sobre caracteres multibyte.
 *
 * El vocabulario y los términos que se consultan deben estar en la misma forma (por ejemplo,
 * las raíces que devuelve `Tokenizer::tokens()`).
 */
final class Fuzzy
{
    /** @var array<string,float> término => frecuencia */
    private array $freq = [];

    /** @var array<int,array<int,string>> longitud en caracteres => términos */
    private array $byLength = [];

    /** @var array<string,array<string,int>>|null trigrama => [término => veces], se construye al primer uso */
    private ?array $grams = null;

    /**
     * @param array<int|string,mixed> $vocabulary lista de términos `['silla', ...]` o mapa de
     *                                            frecuencias `['silla' => 12, ...]`
     */
    public function __construct(array $vocabulary)
    {
        foreach ($vocabulary as $key => $value) {
            if (is_string($value)) {
                $term = $value;
                $weight = 1.0;
            } elseif (is_int($value) || is_float($value)) {
                $term = (string) $key;
                $weight = (float) $value;
            } else {
                continue;
            }
            if ($term === '') {
                continue;
            }
            if (isset($this->freq[$term])) {
                $this->freq[$term] += $weight;
                continue;
            }
            $this->freq[$term] = $weight;
            $this->byLength[mb_strlen($term, 'UTF-8')][] = $term;
        }
    }

    /**
     * El término del vocabulario más cercano, o null si no hay ninguno suficientemente parecido.
     *
     * Distancia máxima: 1 para palabras de hasta 4 letras y `$maxDistance` (2 por defecto) para las
     * demás. Las de menos de 3 letras no se corrigen nunca. A igualdad de distancia gana el más
     * frecuente y después el primero por orden alfabético. Un término que ya está en el vocabulario
     * se devuelve tal cual.
     */
    public function closest(string $term, int $maxDistance = 2): ?string
    {
        if ($term === '') {
            return null;
        }
        if (isset($this->freq[$term])) {
            return $term;
        }

        $chars = mb_str_split($term, 1, 'UTF-8');
        $length = count($chars);
        if ($length < 3) {
            return null;
        }
        $max = $length <= 4 ? min($maxDistance, 1) : $maxDistance;
        if ($max < 1) {
            return null;
        }

        // Preselección: con palabras largas, una edición toca como mucho 4 trigramas, así que un
        // candidato a distancia ≤ max comparte al menos length - 2 - 4·max. Con las cortas, por longitud.
        $need = $length - 2 - 4 * $max;
        $candidates = $need > 0 ? $this->byTrigrams($chars, $need, $max) : $this->byLength($length, $max);

        $best = null;
        $bestDistance = $max + 1;
        $bestFreq = 0.0;
        foreach ($candidates as $candidate) {
            $distance = self::charDistance($chars, mb_str_split($candidate, 1, 'UTF-8'), $max);
            if ($distance > $max) {
                continue;
            }
            $freq = $this->freq[$candidate];
            if ($best === null
                || $distance < $bestDistance
                || ($distance === $bestDistance && ($freq > $bestFreq
                    || ($freq === $bestFreq && strcmp($candidate, $best) < 0)))
            ) {
                $best = $candidate;
                $bestDistance = $distance;
                $bestFreq = $freq;
            }
        }

        return $best;
    }

    /**
     * Sustituye los tokens desconocidos por su más cercano. Los que llevan cifras (referencias,
     * medidas) no se tocan nunca.
     *
     * @param string[] $queryTokens
     *
     * @return array{tokens:string[],corrections:array<string,string>}
     */
    public function expand(array $queryTokens): array
    {
        $tokens = [];
        $corrections = [];
        foreach ($queryTokens as $token) {
            $token = is_scalar($token) ? (string) $token : '';
            if ($token === '' || isset($this->freq[$token]) || preg_match('/[0-9]/', $token) === 1) {
                $tokens[] = $token;
                continue;
            }
            $near = $this->closest($token);
            if ($near !== null && $near !== $token) {
                $tokens[] = $near;
                $corrections[$token] = $near;
            } else {
                $tokens[] = $token;
            }
        }

        return ['tokens' => $tokens, 'corrections' => $corrections];
    }

    /**
     * Distancia de edición con trasposición entre dos cadenas. Si supera `$max` devuelve `$max + 1`.
     */
    public static function distance(string $a, string $b, int $max = 255): int
    {
        return self::charDistance(mb_str_split($a, 1, 'UTF-8'), mb_str_split($b, 1, 'UTF-8'), $max);
    }

    /**
     * @param string[] $a
     * @param string[] $b
     */
    private static function charDistance(array $a, array $b, int $max): int
    {
        $la = count($a);
        $lb = count($b);
        if (abs($la - $lb) > $max) {
            return $max + 1;
        }

        $prev2 = [];
        $prev = range(0, $lb);
        $prevMin = 0;
        for ($i = 1; $i <= $la; ++$i) {
            $cur = [$i];
            $rowMin = $i;
            for ($j = 1; $j <= $lb; ++$j) {
                $cost = $a[$i - 1] === $b[$j - 1] ? 0 : 1;
                $v = min($prev[$j] + 1, $cur[$j - 1] + 1, $prev[$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $v = min($v, $prev2[$j - 2] + 1);
                }
                $cur[$j] = $v;
                if ($v < $rowMin) {
                    $rowMin = $v;
                }
            }
            // Todo camino pasa por una de cada dos filas seguidas y el coste no baja: si las dos
            // pasan del máximo, ya no se puede llegar.
            if ($rowMin > $max && $prevMin > $max) {
                return $max + 1;
            }
            $prev2 = $prev;
            $prev = $cur;
            $prevMin = $rowMin;
        }

        return $prev[$lb] > $max ? $max + 1 : $prev[$lb];
    }

    /** @return string[] */
    private function byLength(int $length, int $max): array
    {
        $out = [];
        for ($l = max(1, $length - $max); $l <= $length + $max; ++$l) {
            foreach ($this->byLength[$l] ?? [] as $term) {
                $out[] = $term;
            }
        }

        return $out;
    }

    /**
     * @param string[] $chars
     *
     * @return string[]
     */
    private function byTrigrams(array $chars, int $need, int $max): array
    {
        $index = $this->gramIndex();
        $length = count($chars);

        $query = [];
        for ($i = 0; $i + 2 < $length; ++$i) {
            $gram = $chars[$i] . $chars[$i + 1] . $chars[$i + 2];
            $query[$gram] = ($query[$gram] ?? 0) + 1;
        }

        $shared = [];
        foreach ($query as $gram => $times) {
            foreach ($index[$gram] ?? [] as $term => $count) {
                $shared[$term] = ($shared[$term] ?? 0) + min($times, $count);
            }
        }

        $out = [];
        foreach ($shared as $term => $count) {
            $term = (string) $term;
            if ($count >= $need && abs(mb_strlen($term, 'UTF-8') - $length) <= $max) {
                $out[] = $term;
            }
        }

        return $out;
    }

    /** @return array<string,array<string,int>> */
    private function gramIndex(): array
    {
        if ($this->grams === null) {
            $this->grams = [];
            foreach ($this->byLength as $length => $terms) {
                if ($length < 3) {
                    continue;
                }
                foreach ($terms as $term) {
                    $chars = mb_str_split($term, 1, 'UTF-8');
                    $counts = [];
                    for ($i = 0; $i + 2 < $length; ++$i) {
                        $gram = $chars[$i] . $chars[$i + 1] . $chars[$i + 2];
                        $counts[$gram] = ($counts[$gram] ?? 0) + 1;
                    }
                    foreach ($counts as $gram => $times) {
                        $this->grams[$gram][$term] = $times;
                    }
                }
            }
        }

        return $this->grams;
    }
}
