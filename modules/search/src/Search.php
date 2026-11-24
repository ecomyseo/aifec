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
use Aifec\Search\Fuzzy;
use Aifec\Search\Tokenizer;
use Aifec\Search\Vector;

/**
 * Servicio «search»: el buscador propio de la tienda (contrato 05 § 4).
 *
 *   query(q, options)  ids de lo que encaja, por relevancia; erratas corregidas, sinónimos aplicados y un «confident» que dice si
 *                      lo encontrado convence. Todo sale del índice local: NUNCA espera a ninguna IA.
 *   suggest(q, lang)   hasta 8 sugerencias por prefijo mientras se escribe.
 *   index(type, id)    reindexa una entidad.   reindex(type)   reindexa todo por tandas (en segundo plano).
 *
 * Cómo puntúa: BM25F, la misma fórmula que Aifec\Search\Bm25 (que la prueba de este módulo comprueba contra ella), pero con las
 * estadísticas del índice guardado en la base de datos en vez de un índice en memoria: la frecuencia de cada término sale de
 * search_terms, la longitud media de cada campo de una agregación en caché, y de las apariciones solo se leen las de los términos
 * de la consulta. Así una búsqueda cuesta unas pocas consultas aunque el catálogo tenga decenas de miles de productos.
 *
 * Reglas de la consulta:
 *   - Todos los términos tienen que aparecer; si así no sale nada, vale «todos menos uno».
 *   - Un término que no está en el vocabulario se sustituye por el más parecido (Aifec\Search\Fuzzy) y se dice en «corrected».
 *   - Lo agotado baja (su puntuación se reduce a la mitad); a igualdad, gana lo que más se vende.
 *   - Un código de referencia o de barras que coincide entero va el primero.
 *   - confident = la mejor puntuación, relativa a la de una coincidencia perfecta de todos los términos (una vez en el nombre),
 *     llega al umbral «search.min_score».
 */
final class Search
{
    public const TYPES = ['product', 'page', 'post'];

    private const K1 = 1.2;

    private const B = 0.75;

    /** Consultas distintas que se recuerdan durante una petición (el listado del catálogo vuelve a preguntar lo mismo). */
    private const MEMO_MAX = 40;

    /** Tamaño de los grupos en los «IN (…)». */
    private const CHUNK = 400;

    /** Cuántos resultados se reordenan con los vectores. */
    private const RERANK = 50;

    private const MAX_QUERY = 200;

    private const STOCK_FACTOR = 0.5;

    private const SYNONYM_FACTOR = 0.9;

    private const VECTOR_SHARE = 0.3;

    /** @var App */
    private $app;

    /** @var Index */
    private $index;

    /** @var Synonyms */
    private $synonyms;

    /** @var QueryLog */
    private $log;

    /** @var Suggester */
    private $suggester;

    /** @var Ask|null */
    private $ask;

    /** @var array<string,Analyzer> */
    private $analyzers = [];

    /** @var array<string,array<string,mixed>> */
    private $memo = [];

    /** @var int versión de la etiqueta «search» de la caché con la que se recordó lo anterior */
    private $memoVersion = -1;

    /** @var int ámbitos de evaluación que solo pueden usar información ya calculada */
    private $withoutAiDepth = 0;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->index = new Index($app, $this);
        $this->synonyms = new Synonyms($app);
        $this->log = new QueryLog($app);
        $this->suggester = new Suggester($app, $this);
    }

    // ------------------------------------------------------------------ piezas

    public function analyzer(string $lang): Analyzer
    {
        if (!isset($this->analyzers[$lang])) {
            $this->analyzers[$lang] = new Analyzer($lang);
        }

        return $this->analyzers[$lang];
    }

    public function indexer(): Index
    {
        return $this->index;
    }

    public function log(): QueryLog
    {
        return $this->log;
    }

    /** El idioma pedido si es uno de los de la tienda; si no, el de la petición (o el principal si no es de la tienda). */
    public function lang(?string $lang): string
    {
        if ($lang === null || $lang === '') {
            return $this->app->lang();
        }
        $lang = strtolower($lang);

        return in_array($lang, $this->app->languages(), true) ? $lang : $this->app->defaultLang();
    }

    /** Olvida lo que se recordaba de esta petición (cambió el índice). */
    public function forget(): void
    {
        $this->memo = [];
    }

    /**
     * Ejecuta lecturas sin peticiones nuevas ni trabajos de IA; conserva vectores y respuestas ya guardados.
     * Se restaura incluso si la comprobación falla, y su memoización no se mezcla con una búsqueda normal.
     *
     * @return mixed
     */
    public function withoutAiSideEffects(callable $work)
    {
        ++$this->withoutAiDepth;
        $this->forget();
        try {
            return $work();
        } finally {
            --$this->withoutAiDepth;
            $this->forget();
        }
    }

    public function allowsAiSideEffects(): bool
    {
        return $this->withoutAiDepth === 0;
    }

    // ------------------------------------------------------------------ ajustes

    public function minScore(): float
    {
        $value = $this->app->settings()->get('search.min_score', 0.35);

        return is_numeric($value) ? max(0.0, min(1.0, (float) $value)) : 0.35;
    }

    /** ¿Hay que guardar vectores y usarlos? Solo con el ajuste encendido y el servicio «ai» activo. */
    public function vectorsOn(): bool
    {
        return $this->aiOn('search.vectors');
    }

    /** ¿Hay que pedir a la IA formas de buscar cada producto al indexarlo? */
    public function expandOn(): bool
    {
        return $this->allowsAiSideEffects() && $this->aiOn('search.ai_expand');
    }

    private function aiOn(string $setting): bool
    {
        if (!$this->app->settings()->bool($setting, false) || !$this->app->has('ai')) {
            return false;
        }
        try {
            $ai = $this->app->service('ai');

            return is_callable([$ai, 'enabled']) && (bool) $ai->enabled();
        } catch (\Throwable $e) {
            return false;
        }
    }

    // ------------------------------------------------------------------ índice

    /** Reindexa una entidad en todos los idiomas. */
    public function index(string $type, int $id): void
    {
        $this->index->index($type, $id);
    }

    /**
     * Reindexa todo (o un tipo) en segundo plano, por tandas de 100 que se encolan solas. Devuelve el identificador del
     * trabajo (o el del que ya estaba en marcha). Sin el módulo de tareas lo hace ahora mismo y devuelve cuántas entidades leyó.
     */
    public function reindex(?string $type = null): int
    {
        if ($this->index->types($type) === []) {
            return 0;
        }
        if (!$this->app->has('jobs')) {
            return $this->index->reindexNow($type);
        }
        $open = (int) $this->app->db()->value("SELECT MIN(id) FROM {jobs} WHERE type = 'search.reindex' AND status IN ('pending', 'running')");
        if ($open > 0) {
            return $open;
        }

        return (int) $this->app->service('jobs')->push('search.reindex', ['types' => $this->index->types($type), 'after' => 0]);
    }

    /** Reindexa todo (o un tipo) ahora mismo. Devuelve cuántas entidades leyó. */
    public function reindexNow(?string $type = null): int
    {
        return $this->index->reindexNow($type);
    }

    /**
     * Pide reindexar una entidad: un trabajo único por entidad (varios cambios seguidos son uno solo). Sin el módulo de tareas
     * lo hace ahora.
     */
    public function queueIndex(string $type, int $id): void
    {
        if ($id <= 0 || !in_array($type, self::TYPES, true)) {
            return;
        }
        if ($this->app->has('jobs')) {
            $this->app->service('jobs')->push('search.index', ['type' => $type, 'id' => $id], ['unique' => 'search.index:' . $type . ':' . $id]);

            return;
        }
        $this->index->index($type, $id);
    }

    // ------------------------------------------------------------------ sugerencias

    /**
     * Hasta 8 sugerencias por prefijo: ['type' => product|category|query, 'label', 'url', 'image', 'price', 'group'].
     *
     * @return array<int,array<string,mixed>>
     */
    public function suggest(string $q, ?string $lang = null): array
    {
        return $this->suggester->suggest($q, $this->lang($lang));
    }

    // ------------------------------------------------------------------ entender la frase

    /** El que entiende frases («silla negra por menos de 200 €»): reglas, IA opcional y respuesta. */
    public function asker(): Ask
    {
        if ($this->ask === null) {
            $this->ask = new Ask($this->app, $this);
        }

        return $this->ask;
    }

    /**
     * Para el asistente y para máquinas: entiende la frase y contesta con lo entendido, los productos y una respuesta en texto.
     * $options: lang, limit (el del listado de la tienda; como mucho 48), ai (false = solo reglas; 'cached' = reglas y lo que la IA
     * ya tenga guardado, sin llamarla; true o sin poner = puede llamarla), log (true = apunta la búsqueda).
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> query, lang, understood (filters, text, corrected, ai), products, answer, shop, total, relaxed,
     *                             related (productos que salen por significado, aparte de «products» y de «total») y answer_kind
     *                             (exact, relaxed, related, shop o none)
     */
    public function ask(string $query, array $options = []): array
    {
        return $this->asker()->ask($query, $options);
    }

    // ------------------------------------------------------------------ consulta

    /**
     * Busca. $options: lang, limit (200), type (product, page o post), explain (true = añade 'explain': por id, la puntuación
     * y si fue coincidencia exacta; solo para pruebas y diagnóstico).
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> ['ids' => int[], 'total' => int, 'corrected' => ?string, 'confident' => bool, 'relaxed' => bool]
     */
    public function query(string $q, array $options = []): array
    {
        $lang = $this->lang(isset($options['lang']) && is_string($options['lang']) ? $options['lang'] : null);
        $type = isset($options['type']) && is_string($options['type']) && in_array($options['type'], self::TYPES, true) ? $options['type'] : 'product';
        $limit = isset($options['limit']) ? max(1, min(500, (int) $options['limit'])) : 200;
        $explain = !empty($options['explain']);
        $requireAllTerms = !empty($options['require_all_terms']);
        $q = trim(mb_substr(Analyzer::clean($q), 0, self::MAX_QUERY, 'UTF-8'));
        if ($q === '') {
            return $this->empty(null);
        }
        // Lo recordado vale mientras nada cambie en el índice, los sinónimos o los ajustes (la etiqueta «search» de la caché).
        $version = $this->app->cache()->tagVersion('search');
        if ($version !== $this->memoVersion) {
            $this->memo = [];
            $this->memoVersion = $version;
        }
        $key = $lang . '|' . $type . '|' . $limit . '|' . ($explain ? '1' : '0') . '|' . ($requireAllTerms ? '1' : '0') . '|' . $q;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }
        $natural = $type === 'product' && $lang === 'es' ? (new Tokenizer('es'))->conversationalProductTerms($q) : null;
        $result = $this->run($natural === null ? $q : implode(' ', $natural), $lang, $type, $limit, $explain, $natural !== null, $requireAllTerms);
        if (count($this->memo) >= self::MEMO_MAX) {
            $this->memo = [];
        }
        $this->memo[$key] = $result;

        return $result;
    }

    /**
     * @return array<string,mixed>
     */
    private function empty(?string $corrected): array
    {
        return ['ids' => [], 'total' => 0, 'corrected' => $corrected, 'confident' => false, 'relaxed' => false, 'terms' => 0];
    }

    /**
     * @return array<string,mixed>
     */
    private function run(string $q, string $lang, string $type, int $limit, bool $explain, bool $anchorFirst, bool $requireAllTerms): array
    {
        $analyzer = $this->analyzer($lang);
        $chunks = $analyzer->chunks($q, 12);

        // 1. La consulta en grupos: cada palabra con sus raíces; los sinónimos añaden formas de decir lo mismo.
        $groups = [];
        foreach ($chunks as $chunk) {
            $tokens = $analyzer->tokens($chunk);
            if ($tokens === []) {
                continue;
            }
            $groups[] = [
                'alts' => [$tokens], 'word' => $chunk, 'chunk' => $chunk,
                'typo' => count($tokens) === 1 && preg_match('/[0-9]/', $tokens[0]) !== 1,
            ];
        }
        if ($groups === []) {
            return $this->empty(null);
        }
        $groups = $this->synonyms->apply($groups, $analyzer, $lang);

        // 2. El vocabulario: qué raíces existen y en cuántos documentos salen.
        $info = $this->terms($lang, self::stemsOf($groups));

        // 3. Erratas: una palabra que no sale en ningún documento se cambia por la más parecida.
        $fixes = [];
        foreach ($groups as $index => $group) {
            if (!$group['typo']) {
                continue;
            }
            $stem = (string) $group['alts'][0][0];
            if (isset($info[$stem]) && $info[$stem]['df'] > 0) {
                continue;
            }
            $near = $this->closest($lang, $stem);
            // La palabra escrita se compara con la forma que se enseña Y con la raíz: «perfum» está a dos letras de «perfumes» (la
            // forma guardada) pero a una de «perfume» (14/11/2026: «perfum» no encontraba ningún perfume).
            if ($near === null || (!self::closeWord((string) $group['chunk'], (string) $near['surface']) && !self::closeWord((string) $group['chunk'], (string) $near['term']))) {
                continue;
            }
            $groups[$index]['alts'][0][0] = $near['term'];
            $fixes[$group['chunk']] = $near;
            $info[$near['term']] = ['id' => $near['id'], 'df' => $near['df'], 'surface' => $near['surface']];
        }
        $corrected = null;
        if ($fixes !== []) {
            $words = [];
            foreach ($chunks as $chunk) {
                $words[] = isset($fixes[$chunk]) ? $fixes[$chunk]['surface'] : $chunk;
            }
            $corrected = implode(' ', $words);
        }

        $stats = $this->stats($lang);
        $total = (int) $stats['n'];
        if ($total < 1) {
            return $this->empty($corrected);
        }

        // 4. Las apariciones de los términos de la consulta (y, si es un código, el código entero).
        $need = [];
        foreach ($groups as $group) {
            foreach ($group['alts'] as $stems) {
                foreach ($stems as $stem) {
                    if (isset($info[$stem]) && $info[$stem]['df'] > 0) {
                        $need[$info[$stem]['id']] = true;
                    }
                }
            }
        }
        $exactTid = 0;
        if (count($chunks) === 1 && count($groups) === 1) {
            $whole = '#' . (string) preg_replace('/\s+/', '', $analyzer->fold($chunks[0]));
            $exact = $this->terms($lang, [$whole]);
            if (isset($exact[$whole]) && $exact[$whole]['df'] > 0) {
                $exactTid = $exact[$whole]['id'];
                $need[$exactTid] = true;
            }
        }
        $post = $this->postings(array_keys($need), $lang, $type);

        // 5. Qué documentos cumplen cada grupo, y cuáles todos (o todos menos uno).
        // La puntuación de referencia de un término es la de una coincidencia perfecta: una vez en el nombre, con la longitud media.
        $weights = Fields::weights($this->app);
        $top = $weights['name'] > 0.0 ? $weights['name'] : max($weights);
        $reference = $top > 0.0 ? $top * (self::K1 + 1.0) / ($top + self::K1) : 1.0;
        $idf = static function (int $df) use ($total): float {
            return log(1 + ($total - $df + 0.5) / ($df + 0.5));
        };
        $plan = [];
        $groupDocs = [];
        $ideal = 0.0;
        foreach ($groups as $gi => $group) {
            $union = [];
            $bestIdeal = 0.0;
            foreach ($group['alts'] as $ai => $stems) {
                $set = null;
                $terms = [];
                $altIdeal = 0.0;
                foreach ($stems as $stem) {
                    $df = isset($info[$stem]) ? (int) $info[$stem]['df'] : 0;
                    $weight = $idf($df);
                    $altIdeal += $weight * $reference;
                    $tid = $df > 0 ? (int) $info[$stem]['id'] : 0;
                    $terms[] = [$tid, $weight];
                    if ($set === []) {
                        continue;
                    }
                    if ($tid === 0 || !isset($post[$tid])) {
                        $set = [];
                        continue;
                    }
                    $set = $set === null ? $post[$tid] : array_intersect_key($set, $post[$tid]);
                }
                $plan[$gi][$ai] = ['terms' => $terms, 'docs' => $set === null ? [] : $set];
                $union += $plan[$gi][$ai]['docs'];
                $bestIdeal = max($bestIdeal, $altIdeal);
            }
            $groupDocs[$gi] = $union;
            $ideal += $bestIdeal;
        }

        $matched = null;
        foreach ($groupDocs as $docs) {
            $matched = $matched === null ? $docs : array_intersect_key($matched, $docs);
        }
        $relaxed = false;
        $count = count($groups);
        if (!$requireAllTerms && ($matched === null || $matched === []) && $count >= 2) {
            $hits = [];
            foreach ($groupDocs as $docs) {
                foreach ($docs as $docId => $unused) {
                    $hits[$docId] = (isset($hits[$docId]) ? $hits[$docId] : 0) + 1;
                }
            }
            $matched = [];
            foreach ($hits as $docId => $n) {
                if ($n >= max(1, $count - 1) && (!$anchorFirst || isset($groupDocs[0][$docId]))) {
                    $matched[$docId] = true;
                }
            }
            $relaxed = true;
        }
        if ($matched === null || $matched === []) {
            return $this->empty($corrected);
        }

        // 6. Puntuación BM25F de cada documento.
        $rows = $this->docRows(array_keys($matched));
        $refIndex = (int) array_search('ref', Fields::ALL, true);
        $ranked = [];
        foreach ($matched as $docId => $unused) {
            if (!isset($rows[$docId])) {
                continue;
            }
            $row = $rows[$docId];
            $score = 0.0;
            $presence = 0.0;
            $strong = false;
            foreach ($plan as $alts) {
                $best = 0.0;
                $bestPresence = 0.0;
                foreach ($alts as $ai => $alt) {
                    if (!isset($alt['docs'][$docId])) {
                        continue;
                    }
                    $sum = 0.0;
                    $seen = 0.0;
                    foreach ($alt['terms'] as $pair) {
                        $tf = $post[$pair[0]][$docId];
                        $strong = $strong || self::inStrongField($tf, $weights);
                        $sum += self::termScore($tf, $row['len'], $stats['avg'], $weights, (float) $pair[1]);
                        $seen += min(self::presence($tf, $weights, (float) $pair[1]), (float) $pair[1] * $reference);
                    }
                    $factor = $ai === 0 ? 1.0 : self::SYNONYM_FACTOR;
                    if ($sum * $factor > $best) {
                        $best = $sum * $factor;
                        $bestPresence = $seen * $factor;
                    }
                }
                $score += $best;
                $presence += $bestPresence;
            }
            $isExact = $exactTid > 0 && isset($post[$exactTid][$docId]) && $post[$exactTid][$docId][$refIndex] > 0;
            $ranked[] = [
                'doc' => (int) $docId, 'id' => $row['entity_id'], 'raw' => $score, 'presence' => $presence,
                'adj' => $row['in_stock'] ? $score : $score * self::STOCK_FACTOR,
                'sales' => $row['sales'], 'exact' => $isExact, 'strong' => $strong || $isExact, 'in_stock' => (bool) $row['in_stock'],
            ];
        }
        // Lo que solo casa por la descripción larga no se mezcla con lo que casa de verdad (13/11/2026: «perfume» traía un
        // detergente «con perfume a lavanda»). Si nada casa por el nombre, la referencia, la marca, la categoría, las etiquetas o los
        // atributos, se queda todo: es lo único que hay.
        $strongOnes = array_values(array_filter($ranked, static function (array $item): bool {
            return $item['strong'];
        }));
        // «weak»: nada casa por un campo de peso (solo por la descripción larga). Lo usa quien pregunta para no darlo por una respuesta
        // exacta cuando la consulta traía varias palabras (verificación del 24/11/2026: «pasarela de pago» traía una ficha que dice
        // «pasarela de pago» en medio de su descripción).
        $weak = $strongOnes === [] && $ranked !== [];
        if ($strongOnes !== []) {
            $ranked = $strongOnes;
        }
        if ($ranked === []) {
            return $this->empty($corrected);
        }
        usort($ranked, static function (array $a, array $b): int {
            if ($a['exact'] !== $b['exact']) {
                return $a['exact'] ? -1 : 1;
            }
            $x = round($a['adj'], 9);
            $y = round($b['adj'], 9);
            if ($x !== $y) {
                return $x < $y ? 1 : -1;
            }
            if ($a['sales'] !== $b['sales']) {
                return $a['sales'] < $b['sales'] ? 1 : -1;
            }

            return $a['id'] <=> $b['id'];
        });

        // 7. Vectores (solo si hay uno de la consulta ya calculado): reordena los primeros sin esperar a nadie.
        $ranked = $this->rerank($ranked, $q, $lang, $ideal);

        // 8. ¿Convence? Cuánto de lo pedido encontró el mejor documento, frente a una coincidencia perfecta de todo (sin contar lo
        // largo que sea su texto: un producto con mucha descripción no es peor resultado por tenerla).
        $best = 0.0;
        $hasExact = false;
        foreach ($ranked as $item) {
            $best = max($best, $item['presence']);
            $hasExact = $hasExact || $item['exact'];
        }
        $confident = $hasExact || ($ideal > 0.0 && $best / $ideal >= $this->minScore());
        if ($confident && !$hasExact && $fixes !== [] && count($fixes) === count($groups)) {
            // Si solo se casó por palabras corregidas y todas son cortas («mes» → «mesa»), casi seguro no es lo que se buscaba.
            $short = true;
            foreach ($fixes as $fix) {
                $short = $short && mb_strlen((string) $fix['surface'], 'UTF-8') < 4;
            }
            $confident = !$short;
        }

        $ids = [];
        foreach (array_slice($ranked, 0, $limit) as $item) {
            $ids[] = (int) $item['id'];
        }
        $ids = $this->learnedOrder($ids, $q, $lang, $type, $ranked);
        // «terms»: cuántas palabras con significado traía la consulta. Con «relaxed» (se casó quitando una) dice cuánto de lo
        // pedido quedó sin encontrar: una de dos es la mitad.
        $result = ['ids' => $ids, 'total' => count($ranked), 'corrected' => $corrected, 'confident' => $confident, 'relaxed' => $relaxed, 'terms' => count($groups), 'weak' => $weak];
        if ($explain) {
            $result['explain'] = [];
            foreach ($ranked as $item) {
                $result['explain'][(int) $item['id']] = ['score' => $item['raw'], 'normalized' => $ideal > 0.0 ? $item['presence'] / $ideal : 0.0, 'exact' => $item['exact']];
            }
        }

        return $result;
    }

    /**
     * Una regla de clasificación puede cambiar el orden, nunca el conjunto, las referencias exactas ni la disponibilidad.
     *
     * @param int[]                           $ids
     * @param array<int,array<string,mixed>>   $ranked
     * @return int[]
     */
    private function learnedOrder(array $ids, string $query, string $lang, string $type, array $ranked): array
    {
        $candidate = $this->app->hooks()->apply('search.result.ids', $ids, $query, $lang, $type);
        if ($candidate === $ids) {
            return $ids;
        }
        if (!is_array($candidate) || count($candidate) !== count($ids)) {
            return $ids;
        }
        $candidate = array_values($candidate);
        foreach ($candidate as $id) {
            if (!is_int($id) || $id <= 0) {
                return $ids;
            }
        }
        $expected = $ids;
        $actual = $candidate;
        sort($expected, SORT_NUMERIC);
        sort($actual, SORT_NUMERIC);
        if ($actual !== $expected || count(array_unique($actual)) !== count($actual)) {
            return $ids;
        }
        $facts = [];
        foreach ($ranked as $item) {
            $facts[(int) $item['id']] = $item;
        }
        foreach ($ids as $position => $id) {
            if (($facts[$id]['exact'] && $candidate[$position] !== $id)
                || $facts[$id]['in_stock'] !== $facts[$candidate[$position]]['in_stock']) {
                return $ids;
            }
        }

        return $candidate;
    }

    /**
     * BM25F de un término en un documento: los pesos de cada campo ponderan la frecuencia normalizada por la longitud de ese campo,
     * y la suma se satura con k1. Es exactamente la fórmula de Aifec\Search\Bm25.
     *
     * @param int[]                $tf      apariciones en cada campo, en el orden de Fields::ALL
     * @param array<string,int>    $len     longitud del campo en este documento
     * @param array<string,float>  $avg     longitud media del campo
     * @param array<string,float>  $weights
     */
    private static function termScore(array $tf, array $len, array $avg, array $weights, float $idf): float
    {
        $wtf = 0.0;
        foreach (Fields::ALL as $i => $field) {
            $times = $tf[$i];
            if ($times <= 0) {
                continue;
            }
            $weight = $weights[$field];
            if ($weight <= 0.0) {
                continue;
            }
            $average = $avg[$field];
            $norm = $average > 0.0 ? 1.0 - self::B + self::B * $len[$field] / $average : 1.0;
            $wtf += $weight * $times / $norm;
        }

        return $wtf > 0.0 ? $idf * ($wtf * (self::K1 + 1.0)) / ($wtf + self::K1) : 0.0;
    }

    /**
     * Cuánto cuenta que un término esté en un documento sin mirar lo largo que sea cada campo: la misma curva de saturación de
     * BM25, con los pesos de los campos en que sale (una vez cada uno). Sirve para decidir si lo encontrado convence.
     *
     * @param int[]               $tf
     * @param array<string,float> $weights
     */
    private static function presence(array $tf, array $weights, float $idf): float
    {
        $wtf = 0.0;
        foreach (Fields::ALL as $i => $field) {
            if ($tf[$i] > 0 && $weights[$field] > 0.0) {
                $wtf += $weights[$field];
            }
        }

        return $wtf > 0.0 ? $idf * ($wtf * (self::K1 + 1.0)) / ($wtf + self::K1) : 0.0;
    }

    /**
     * Todas las raíces distintas de los grupos.
     *
     * @param array<int,array<string,mixed>> $groups
     *
     * @return string[]
     */
    private static function stemsOf(array $groups): array
    {
        $stems = [];
        foreach ($groups as $group) {
            foreach ($group['alts'] as $alt) {
                foreach ($alt as $stem) {
                    $stems[(string) $stem] = (string) $stem;
                }
            }
        }

        return array_values($stems);
    }

    // ------------------------------------------------------------------ lecturas del índice

    /**
     * De unas raíces: su identificador, en cuántos documentos salen y cómo se escriben.
     *
     * @param string[] $stems
     *
     * @return array<string,array{id:int,df:int,surface:string}>
     */
    private function terms(string $lang, array $stems): array
    {
        $db = $this->app->db();
        $out = [];
        foreach (array_chunk($stems, self::CHUNK) as $chunk) {
            $params = [$lang];
            $in = $db->in($chunk, $params);
            foreach ($db->select('SELECT id, term, df, surface FROM {search_terms} WHERE lang = ? AND term IN (' . $in . ')', $params) as $row) {
                $out[(string) $row['term']] = ['id' => (int) $row['id'], 'df' => (int) $row['df'], 'surface' => (string) $row['surface']];
            }
        }

        return $out;
    }

    /**
     * Las apariciones de unos términos en los documentos de un tipo y un idioma: término => documento => veces por campo.
     *
     * @param int[] $termIds
     *
     * @return array<int,array<int,int[]>>
     */
    private function postings(array $termIds, string $lang, string $type): array
    {
        $db = $this->app->db();
        $columns = 'p.term_id, p.doc_id';
        foreach (Fields::postingColumns() as $column) {
            $columns .= ', p.' . $column;
        }
        $out = [];
        foreach (array_chunk($termIds, self::CHUNK) as $chunk) {
            $params = [$lang, $type];
            $in = $db->in($chunk, $params);
            $rows = $db->select(
                'SELECT ' . $columns . ' FROM {search_postings} p JOIN {search_docs} d ON d.id = p.doc_id WHERE d.lang = ? AND d.type = ? AND p.term_id IN (' . $in . ')',
                $params
            );
            foreach ($rows as $row) {
                $counts = [];
                foreach (Fields::postingColumns() as $column) {
                    $counts[] = (int) $row[$column];
                }
                $out[(int) $row['term_id']][(int) $row['doc_id']] = $counts;
            }
        }

        return $out;
    }

    /**
     * Lo que hace falta de cada documento para puntuarlo.
     *
     * @param int[] $docIds
     *
     * @return array<int,array{entity_id:int,sales:int,in_stock:bool,len:array<string,int>}>
     */
    private function docRows(array $docIds): array
    {
        $db = $this->app->db();
        $columns = array_merge(['id', 'entity_id', 'sales', 'in_stock'], Fields::lengthColumns());
        $out = [];
        foreach (array_chunk($docIds, self::CHUNK) as $chunk) {
            $params = [];
            $in = $db->in($chunk, $params);
            foreach ($db->select('SELECT ' . implode(', ', $columns) . ' FROM {search_docs} WHERE id IN (' . $in . ')', $params) as $row) {
                $len = [];
                foreach (Fields::ALL as $field) {
                    $len[$field] = (int) $row['len_' . $field];
                }
                $out[(int) $row['id']] = ['entity_id' => (int) $row['entity_id'], 'sales' => (int) $row['sales'], 'in_stock' => (int) $row['in_stock'] === 1, 'len' => $len];
            }
        }

        return $out;
    }

    /**
     * Cuántos documentos hay en un idioma y la longitud media de cada campo. Se guarda en la caché (etiqueta «search»).
     *
     * @return array{n:int,avg:array<string,float>}
     */
    public function stats(string $lang): array
    {
        $cache = $this->app->cache();
        $key = 'search:stats:' . $lang;
        $hit = $cache->get($key);
        if (is_array($hit) && isset($hit['n'], $hit['avg'])) {
            return $hit;
        }
        $select = 'COUNT(*) AS n';
        foreach (Fields::ALL as $field) {
            $select .= ', SUM(len_' . $field . ') AS s_' . $field;
        }
        $row = $this->app->db()->row('SELECT ' . $select . ' FROM {search_docs} WHERE lang = ?', [$lang]);
        $n = $row !== null ? (int) $row['n'] : 0;
        $avg = [];
        foreach (Fields::ALL as $field) {
            $avg[$field] = $n > 0 && $row !== null ? (float) $row['s_' . $field] / $n : 0.0;
        }
        $stats = ['n' => $n, 'avg' => $avg];
        $cache->set($key, $stats, 600, ['search']);

        return $stats;
    }

    /**
     * El término del vocabulario más parecido a uno que no existe. Se prueba también con la forma en masculino de una palabra
     * que acaba en «a» (el vocabulario guarda «silla» como «sillo»: «slla» no se parece a «sillo», pero «sllo» sí).
     *
     * @return array{id:int,df:int,term:string,surface:string}|null
     */
    /**
     * ¿La palabra corregida se parece a la escrita, entera y no solo por la raíz? «colon» y «color» están a una letra, pero
     * «colonia» y «colorido» a tres (13/11/2026: buscar «colonia» enseñaba «Resultados para colorido»). Mismo tope que closest().
     */
    /**
     * ¿El término sale en un campo que dice qué es el producto (nombre, referencia, marca, categoría, etiquetas, atributos, resumen)?
     * La descripción larga y los términos de la IA solo cuentan si los ajustes les dan tanto peso como al nombre.
     *
     * @param int[]               $tf      apariciones en cada campo, en el orden de Fields::ALL
     * @param array<string,float> $weights
     */
    private static function inStrongField(array $tf, array $weights): bool
    {
        foreach (Fields::ALL as $i => $field) {
            $weak = in_array($field, ['desc', 'extra'], true) && $weights[$field] < $weights['name'];
            if (isset($tf[$i]) && $tf[$i] > 0 && !$weak) {
                return true;
            }
        }

        return false;
    }

    public static function closeWord(string $typed, string $surface): bool
    {
        $a = \Aifec\Util\Str::slug($typed, 60);
        $b = \Aifec\Util\Str::slug($surface, 60);
        if ($a === '' || $b === '' || strpos($a, '-') !== false || strpos($b, '-') !== false) {
            return true;
        }
        $max = mb_strlen($a, 'UTF-8') <= 6 ? 1 : 2;

        // Una errata casi nunca está en la primera letra: «taza» no es «raza» ni «lampara» es «cámara» (14/11/2026, agentes de compra).
        return $a[0] === $b[0] && Fuzzy::distance($a, $b) <= $max;
    }

    private function closest(string $lang, string $stem): ?array
    {
        $forms = [$stem];
        if ($lang === 'es' && strlen($stem) >= 3 && substr($stem, -1) === 'a') {
            $forms[] = substr($stem, 0, -1) . 'o';
        }
        $db = $this->app->db();
        $best = null;
        foreach ($forms as $form) {
            $length = mb_strlen($form, 'UTF-8');
            if ($length < 3) {
                continue;
            }
            $max = $length <= 6 ? 1 : 2;
            $vocabulary = [];
            $rows = [];
            $found = $db->select(
                'SELECT id, term, df, surface FROM {search_terms} WHERE lang = ? AND len BETWEEN ? AND ? AND df > 0',
                [$lang, max(1, $length - $max), $length + $max]
            );
            foreach ($found as $row) {
                $term = (string) $row['term'];
                if ($term[0] === '#' || preg_match('/[0-9]/', $term) === 1) {
                    continue;
                }
                $vocabulary[$term] = (int) $row['df'];
                $rows[$term] = $row;
            }
            if ($vocabulary === []) {
                continue;
            }
            $near = (new Fuzzy($vocabulary))->closest($form, $max);
            if ($near === null || !isset($rows[$near])) {
                continue;
            }
            $distance = Fuzzy::distance($form, $near);
            // A igual distancia gana el término que sale en más documentos: una palabra de verdad («silla») antes que un trozo
            // de una referencia («SIL» de «SIL-ERGO»), que es lo que se le va a enseñar a quien busca.
            if ($best === null || $distance < $best['distance'] || ($distance === $best['distance'] && (int) $rows[$near]['df'] > $best['df'])) {
                $row = $rows[$near];
                $best = [
                    'id' => (int) $row['id'], 'df' => (int) $row['df'], 'term' => $near,
                    'surface' => (string) $row['surface'] !== '' ? (string) $row['surface'] : $near, 'distance' => $distance,
                ];
            }
        }
        if ($best === null) {
            return null;
        }
        unset($best['distance']);

        return $best;
    }

    // ------------------------------------------------------------------ vectores

    /**
     * Reordena los primeros resultados mezclando su puntuación con la similitud de significado con la consulta. Solo si el
     * vector de la consulta ya está calculado (en la caché); si no, no se usan vectores en esta búsqueda y se encarga
     * calcularlo para la próxima vez. Nunca llama a la IA.
     *
     * @param array<int,array<string,mixed>> $ranked
     *
     * @return array<int,array<string,mixed>>
     */
    private function rerank(array $ranked, string $q, string $lang, float $ideal): array
    {
        if ($ideal <= 0.0 || count($ranked) < 2 || !$this->vectorsOn()) {
            return $ranked;
        }
        $key = $this->queryVectorKey($q, $lang);
        $vector = $this->app->cache()->get($key);
        if (!is_array($vector) || $vector === []) {
            if ($this->allowsAiSideEffects() && $this->app->has('jobs')) {
                $this->app->service('jobs')->push('search.embed', ['q' => $q, 'lang' => $lang], ['unique' => 'search.embed:' . md5($key)]);
            }

            return $ranked;
        }
        $top = array_slice($ranked, 0, self::RERANK);
        $rest = array_slice($ranked, self::RERANK);
        $db = $this->app->db();
        $docIds = [];
        foreach ($top as $item) {
            $docIds[] = (int) $item['doc'];
        }
        $params = [];
        $in = $db->in($docIds, $params);
        $cosines = [];
        foreach ($db->select('SELECT id, vector FROM {search_docs} WHERE vector IS NOT NULL AND id IN (' . $in . ')', $params) as $row) {
            $binary = base64_decode((string) $row['vector'], true);
            if ($binary === false || $binary === '') {
                continue;
            }
            try {
                $docVector = Vector::unpack($binary);
            } catch (\InvalidArgumentException $e) {
                continue;
            }
            if (count($docVector) === count($vector)) {
                $cosines[(int) $row['id']] = max(0.0, Vector::cosine($vector, $docVector));
            }
        }
        if ($cosines === []) {
            return $ranked;
        }
        $mean = array_sum($cosines) / count($cosines);
        foreach ($top as $i => $item) {
            $cos = isset($cosines[$item['doc']]) ? $cosines[$item['doc']] : $mean;
            $top[$i]['final'] = (1.0 - self::VECTOR_SHARE) * ($item['adj'] / $ideal) + self::VECTOR_SHARE * $cos;
        }
        usort($top, static function (array $a, array $b): int {
            if ($a['exact'] !== $b['exact']) {
                return $a['exact'] ? -1 : 1;
            }
            $x = round($a['final'], 9);
            $y = round($b['final'], 9);
            if ($x !== $y) {
                return $x < $y ? 1 : -1;
            }

            return $a['id'] <=> $b['id'];
        });

        return array_merge($top, $rest);
    }

    /**
     * Guarda el vector de una consulta (lo calcula el trabajo «search.embed», nunca la búsqueda).
     *
     * @param array<int,float|int> $vector
     */
    public function storeQueryVector(string $q, string $lang, array $vector): void
    {
        if ($vector === []) {
            return;
        }
        $this->app->cache()->set($this->queryVectorKey($q, $lang), array_map('floatval', Vector::normalize($vector)), 30 * 86400);
    }

    /**
     * El vector ya calculado de una consulta (el que se guardó 30 días), o [] si no lo hay. No llama a nadie.
     *
     * @return float[]
     */
    public function queryVector(string $q, string $lang): array
    {
        $vector = $this->app->cache()->get($this->queryVectorKey($q, $lang));

        return is_array($vector) ? array_map('floatval', array_values($vector)) : [];
    }

    private function queryVectorKey(string $q, string $lang): string
    {
        return 'search:qv:' . $lang . ':' . md5($this->analyzer($lang)->normalize($q));
    }
}
