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
 * Puntuación BM25F (BM25 con campos ponderados) sobre un índice en memoria.
 *
 * Cada documento se compone de campos («name», «attrs»…), cada uno con su lista de tokens
 * (los que devuelve `Tokenizer::tokens()`). La frecuencia de un término se normaliza por la
 * longitud de SU campo y se suma con el peso de ese campo; después se satura con k1.
 *
 * El índice se puede exportar a un array serializable a JSON y recuperar con `import()`.
 */
final class Bm25
{
    private float $k1;

    private float $b;

    /** @var array<string,float> peso por campo (por defecto 1) */
    private array $weights = [];

    /** @var array<string,array{f:array<string,array<string,int>>,len:array<string,int>}> */
    private array $docs = [];

    /** @var array<string,int> término => en cuántos documentos aparece */
    private array $df = [];

    /** @var array<string,array<string,bool>> término => documentos que lo contienen */
    private array $postings = [];

    /** @var array<string,int> campo => suma de longitudes de todos los documentos */
    private array $totalLen = [];

    public function __construct(float $k1 = 1.2, float $b = 0.75)
    {
        $this->k1 = max(0.0, $k1);
        $this->b = min(1.0, max(0.0, $b));
    }

    /**
     * Pesos por campo; un campo que no aparece pesa 1. Sustituye a los anteriores.
     *
     * @param array<string,float|int> $weights
     */
    public function setWeights(array $weights): void
    {
        $this->weights = [];
        foreach ($weights as $field => $weight) {
            if (is_int($weight) || is_float($weight) || (is_string($weight) && is_numeric($weight))) {
                $this->weights[(string) $field] = max(0.0, (float) $weight);
            }
        }
    }

    /**
     * Añade un documento (o lo sustituye si ya existía).
     *
     * @param array<string,string[]> $fields campo => tokens
     */
    public function add(string $docId, array $fields): void
    {
        if (isset($this->docs[$docId])) {
            $this->remove($docId);
        }

        $tfByField = [];
        $lenByField = [];
        foreach ($fields as $field => $tokens) {
            if (!is_array($tokens)) {
                continue;
            }
            $tf = [];
            $length = 0;
            foreach ($tokens as $token) {
                if (!is_scalar($token)) {
                    continue;
                }
                $token = (string) $token;
                if ($token === '') {
                    continue;
                }
                $tf[$token] = ($tf[$token] ?? 0) + 1;
                ++$length;
            }
            $tfByField[(string) $field] = $tf;
            $lenByField[(string) $field] = $length;
        }

        $this->insert($docId, $tfByField, $lenByField);
    }

    /** Quita un documento del índice. Devuelve false si no estaba. */
    public function remove(string $docId): bool
    {
        if (!isset($this->docs[$docId])) {
            return false;
        }

        $doc = $this->docs[$docId];
        $terms = [];
        foreach ($doc['f'] as $tf) {
            foreach ($tf as $term => $count) {
                $terms[(string) $term] = true;
            }
        }
        foreach ($terms as $term => $unused) {
            $term = (string) $term;
            if (isset($this->df[$term])) {
                if (--$this->df[$term] <= 0) {
                    unset($this->df[$term]);
                }
            }
            if (isset($this->postings[$term])) {
                unset($this->postings[$term][$docId]);
                if ($this->postings[$term] === []) {
                    unset($this->postings[$term]);
                }
            }
        }
        foreach ($doc['len'] as $field => $length) {
            $this->totalLen[$field] = ($this->totalLen[$field] ?? 0) - $length;
        }
        unset($this->docs[$docId]);

        return true;
    }

    public function count(): int
    {
        return count($this->docs);
    }

    /**
     * Documentos más relevantes para la consulta, de mayor a menor puntuación; a igualdad,
     * por `id` (orden estable). Solo salen los que contienen algún término de la consulta.
     *
     * @param string[] $queryTokens
     *
     * @return array<int,array{id:string,score:float}>
     */
    public function search(array $queryTokens, int $limit = 20): array
    {
        if ($limit < 1 || $this->docs === []) {
            return [];
        }

        $terms = [];
        foreach ($queryTokens as $token) {
            if (is_scalar($token) && (string) $token !== '') {
                $terms[(string) $token] = true;
            }
        }

        $total = count($this->docs);
        $idf = [];
        $candidates = [];
        foreach ($terms as $term => $unused) {
            $term = (string) $term;
            $df = $this->df[$term] ?? 0;
            if ($df < 1) {
                continue;
            }
            $idf[$term] = log(1 + ($total - $df + 0.5) / ($df + 0.5));
            foreach ($this->postings[$term] as $id => $unused2) {
                $candidates[$id] = true;
            }
        }
        if ($idf === []) {
            return [];
        }

        $avg = [];
        foreach ($this->totalLen as $field => $sum) {
            $avg[$field] = $sum / $total;
        }

        $results = [];
        foreach ($candidates as $id => $unused) {
            $doc = $this->docs[$id];
            $score = 0.0;
            foreach ($idf as $term => $weightIdf) {
                $wtf = 0.0;
                foreach ($doc['f'] as $field => $tf) {
                    if (!isset($tf[$term])) {
                        continue;
                    }
                    $fieldWeight = $this->weights[$field] ?? 1.0;
                    if ($fieldWeight <= 0.0) {
                        continue;
                    }
                    $average = $avg[$field] ?? 0.0;
                    $norm = $average > 0.0 ? 1.0 - $this->b + $this->b * ($doc['len'][$field] ?? 0) / $average : 1.0;
                    $wtf += $fieldWeight * $tf[$term] / $norm;
                }
                if ($wtf > 0.0) {
                    $score += $weightIdf * ($wtf * ($this->k1 + 1.0)) / ($wtf + $this->k1);
                }
            }
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

        return array_slice($results, 0, $limit);
    }

    /**
     * Estado completo del índice, serializable a JSON.
     *
     * @return array<string,mixed>
     */
    public function export(): array
    {
        return [
            'version' => 1,
            'k1' => $this->k1,
            'b' => $this->b,
            'weights' => $this->weights,
            'docs' => $this->docs,
            'df' => $this->df,
        ];
    }

    /**
     * Recupera un índice exportado (también tras pasar por JSON). La frecuencia documental se
     * recalcula desde los documentos, así que un `df` estropeado no puede falsear la puntuación.
     *
     * @param array<string,mixed> $data
     */
    public static function import(array $data): self
    {
        if (!isset($data['docs']) || !is_array($data['docs'])) {
            throw new \InvalidArgumentException('Invalid BM25 export: "docs" is missing');
        }

        $k1 = isset($data['k1']) && is_numeric($data['k1']) ? (float) $data['k1'] : 1.2;
        $b = isset($data['b']) && is_numeric($data['b']) ? (float) $data['b'] : 0.75;
        $index = new self($k1, $b);
        if (isset($data['weights']) && is_array($data['weights'])) {
            $index->setWeights($data['weights']);
        }

        foreach ($data['docs'] as $id => $doc) {
            if (!is_array($doc) || !isset($doc['f']) || !is_array($doc['f'])) {
                continue;
            }
            $tfByField = [];
            $lenByField = [];
            foreach ($doc['f'] as $field => $tf) {
                $clean = [];
                $sum = 0;
                foreach (is_array($tf) ? $tf : [] as $term => $count) {
                    $count = (int) $count;
                    if ($count > 0) {
                        $clean[(string) $term] = $count;
                        $sum += $count;
                    }
                }
                $tfByField[(string) $field] = $clean;
                $declared = isset($doc['len']) && is_array($doc['len']) ? ($doc['len'][$field] ?? null) : null;
                $lenByField[(string) $field] = is_numeric($declared) ? (int) $declared : $sum;
            }
            $index->insert((string) $id, $tfByField, $lenByField);
        }

        return $index;
    }

    /**
     * Todos los términos del índice, en orden alfabético.
     *
     * @return string[]
     */
    public function vocabulary(): array
    {
        $terms = array_map('strval', array_keys($this->df));
        sort($terms, SORT_STRING);

        return $terms;
    }

    /**
     * Término => en cuántos documentos aparece (sirve de frecuencias para `Fuzzy`).
     *
     * @return array<string,int>
     */
    public function documentFrequencies(): array
    {
        return $this->df;
    }

    /**
     * @param array<string,array<string,int>> $tfByField
     * @param array<string,int>               $lenByField
     */
    private function insert(string $docId, array $tfByField, array $lenByField): void
    {
        if (isset($this->docs[$docId])) {
            $this->remove($docId);
        }

        $terms = [];
        foreach ($tfByField as $field => $tf) {
            foreach ($tf as $term => $count) {
                $terms[(string) $term] = true;
            }
            $this->totalLen[$field] = ($this->totalLen[$field] ?? 0) + ($lenByField[$field] ?? 0);
        }
        foreach ($terms as $term => $unused) {
            $term = (string) $term;
            $this->df[$term] = ($this->df[$term] ?? 0) + 1;
            $this->postings[$term][$docId] = true;
        }

        $this->docs[$docId] = ['f' => $tfByField, 'len' => $lenByField];
    }
}
