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

use Aifec\Kernel\App;
use Aifec\Search\Vector;

/**
 * La búsqueda por significado: lo que se ofrece cuando las palabras escritas no coinciden con las del catálogo («algo para la
 * espalda» → el cojín lumbar). Solo se llama cuando lo literal ya ha fallado, así que el camino normal no cambia ni de coste ni de
 * velocidad. Dos caminos, y el primero que dé algo gana:
 *
 *   vectores   con «search.vectors» encendido y vectores guardados: se calcula el vector de la consulta en el momento (una sola vez:
 *              se guarda 30 días, como el de siempre), se compara con el de cada producto y entran los que pasan del umbral
 *              «search.meaning_threshold». Con más de MAX_DOCS documentos con vector, este camino se salta.
 *   términos   la IA propone hasta 6 nombres del vocabulario de la tienda (AiUnderstand::terms()); con cada uno se busca de la forma
 *              normal y se juntan los resultados por relevancia.
 *
 * Nunca da error: sin IA, sin presupuesto, caída o lenta, devuelve vacío y la búsqueda se queda como estaba. Lo que sale de aquí se
 * enseña aparte y dicho («No tenemos nada con esas palabras…»): jamás se mezcla con los resultados exactos ni cuenta en su total.
 */
final class Meaning
{
    /** Documentos con vector como mucho para comparar uno a uno; por encima, el camino de los vectores se salta. */
    public const MAX_DOCS = 5000;

    /** Productos que se ofrecen como mucho. */
    public const MAX_RELATED = 12;

    /**
     * Parecido (coseno) a partir del cual un producto entra por significado. Con los modelos de incrustación de texto corrientes
     * (gemini-embedding-001), una frase corta y un producto del mismo asunto suelen estar entre 0,6 y 0,8, y los que no tienen nada
     * que ver, entre 0,3 y 0,5: 0,6 deja fuera lo segundo y entra en lo primero. Se puede cambiar en los ajustes.
     */
    public const DEFAULT_THRESHOLD = 0.6;

    /** Segundos que se espera, como mucho, al vector de la consulta: si tarda más, no se vuelve a pedir en un minuto. */
    public const EMBED_BUDGET = 1.5;

    /** Documentos que se leen de cada vez al comparar vectores (la memoria no crece con el catálogo). */
    private const CHUNK = 500;

    /** @var App */
    private $app;

    /** @var Search */
    private $search;

    /** @var AiUnderstand */
    private $ai;

    /** @var Vocabulary */
    private $vocabulary;

    /** @var float */
    private $budget = self::EMBED_BUDGET;

    /** @var int hasta cuándo (reloj de la tienda) no se pide otro vector tras un fallo o una tardanza */
    private $downUntil = 0;

    public function __construct(App $app, Search $search, AiUnderstand $ai, Vocabulary $vocabulary)
    {
        $this->app = $app;
        $this->search = $search;
        $this->ai = $ai;
        $this->vocabulary = $vocabulary;
    }

    /** El umbral de parecido de los ajustes (de 0 a 1). */
    public function threshold(): float
    {
        $value = $this->app->settings()->get('search.meaning_threshold', self::DEFAULT_THRESHOLD);

        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : self::DEFAULT_THRESHOLD;
    }

    /** Cambia el tiempo máximo de espera al vector de la consulta (para las pruebas). */
    public function setBudget(float $seconds): void
    {
        $this->budget = max(0.0, $seconds);
    }

    /**
     * Lo que encaja por significado con la frase: identificadores por orden de parecido (como mucho MAX_RELATED) y el camino que los
     * dio («vectors», «terms» o '' si no hay nada). Con $live = false solo vale lo guardado (el vector de la consulta y los términos
     * de otra vez): lo que habría que pedir no se pide y se apunta en AiUnderstand::skipped().
     *
     * @return array{ids:int[],via:string}
     */
    public function find(string $query, string $lang, bool $live = true): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['ids' => [], 'via' => ''];
        }
        $ids = $this->byVectors($query, $lang, $live);
        if ($ids !== []) {
            return ['ids' => $ids, 'via' => 'vectors'];
        }
        $ids = $this->byTerms($query, $lang, $live);

        return ['ids' => $ids, 'via' => $ids !== [] ? 'terms' : ''];
    }

    // ------------------------------------------------------------------ vectores

    /**
     * @return int[]
     */
    public function byVectors(string $query, string $lang, bool $live = true): array
    {
        if (!$this->search->vectorsOn()) {
            return [];
        }
        $db = $this->app->db();
        $docs = (int) $db->value("SELECT COUNT(*) FROM {search_docs} WHERE type = 'product' AND lang = ? AND vector IS NOT NULL", [$lang]);
        if ($docs < 1 || $docs > self::MAX_DOCS) {
            return [];
        }
        $vector = $this->queryVector($query, $lang, $live);
        if ($vector === []) {
            return [];
        }
        $threshold = $this->threshold();
        $near = [];
        $after = 0;
        do {
            $rows = $db->select(
                "SELECT id, entity_id, vector FROM {search_docs} WHERE type = 'product' AND lang = ? AND vector IS NOT NULL AND id > ? ORDER BY id LIMIT " . self::CHUNK,
                [$lang, $after]
            );
            foreach ($rows as $row) {
                $after = (int) $row['id'];
                $binary = base64_decode((string) $row['vector'], true);
                if ($binary === false || $binary === '') {
                    continue;
                }
                try {
                    $docVector = Vector::unpack($binary);
                } catch (\InvalidArgumentException $e) {
                    continue;
                }
                if (count($docVector) !== count($vector)) {
                    continue;
                }
                $cosine = Vector::cosine($vector, $docVector);
                if ($cosine >= $threshold) {
                    $near[(int) $row['entity_id']] = $cosine;
                }
            }
        } while (count($rows) === self::CHUNK);

        return $this->top($near);
    }

    /**
     * El vector de la consulta: el guardado, o uno que se pide ahora a la IA (una sola vez, dentro del tiempo y del tope de gasto) y
     * se guarda 30 días. [] si no se puede, si la frase no tiene arreglo o si solo vale lo guardado ($live = false).
     *
     * @return float[]
     */
    private function queryVector(string $query, string $lang, bool $live): array
    {
        if (AiUnderstand::hopeless($query)) {
            return [];
        }
        $stored = $this->search->queryVector($query, $lang);
        if ($stored !== []) {
            return $stored;
        }
        if ($this->app->clock()->now() < $this->downUntil || !$this->ai->available()) {
            return [];
        }
        if (!$live) {
            $this->ai->skip();

            return [];
        }
        $started = microtime(true);
        try {
            $vectors = $this->app->service('ai')->embed([$query]);
        } catch (\Throwable $e) {
            $this->downUntil = $this->app->clock()->now() + 30;

            return [];
        }
        if (!is_array($vectors) || !isset($vectors[0]) || !is_array($vectors[0]) || $vectors[0] === []) {
            return [];
        }
        if (microtime(true) - $started > $this->budget) {
            // La IA ha tardado más de lo que se está dispuesto a esperar: se aprovecha lo que ha dado, pero un minuto sin insistir.
            $this->downUntil = $this->app->clock()->now() + 60;
        }
        $vector = array_map('floatval', Vector::normalize($vectors[0]));
        $this->search->storeQueryVector($query, $lang, $vector);

        return $vector;
    }

    // ------------------------------------------------------------------ términos que propone la IA

    /**
     * @return int[]
     */
    public function byTerms(string $query, string $lang, bool $live = true): array
    {
        $terms = $this->ai->terms($query, $lang, $this->vocabulary->get($lang), $live);
        if ($terms === null || $terms === []) {
            return [];
        }
        $score = [];
        foreach ($terms as $term) {
            $found = $this->search->query($term, ['lang' => $lang, 'limit' => 60]);
            if (!$found['confident'] || (int) $found['total'] < 1) {
                continue;
            }
            foreach (array_values($found['ids']) as $rank => $id) {
                $score[(int) $id] = (isset($score[(int) $id]) ? $score[(int) $id] : 0.0) + 1.0 / (1 + $rank);
            }
        }

        return $this->top($score);
    }

    /**
     * Los MAX_RELATED de más puntuación (a igualdad, el de menor identificador).
     *
     * @param array<int,float> $scores identificador => puntuación
     *
     * @return int[]
     */
    private function top(array $scores): array
    {
        $ids = array_keys($scores);
        usort($ids, static function (int $a, int $b) use ($scores): int {
            $x = round($scores[$a], 9);
            $y = round($scores[$b], 9);
            if ($x !== $y) {
                return $x < $y ? 1 : -1;
            }

            return $a <=> $b;
        });

        return array_slice($ids, 0, self::MAX_RELATED);
    }
}
