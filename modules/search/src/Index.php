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
use Aifec\Modules\Catalog\Texts;
use Aifec\Search\Vector;

/**
 * El índice: pone un documento en las tablas del buscador, lo quita y reindexa por tandas.
 *
 * Un documento es una entidad en un idioma (un producto, una página o una entrada). Se guarda su longitud por campo, y por
 * cada término, en qué campos sale y cuántas veces. Las cuentas de search_terms (en cuántos documentos sale cada término) se
 * mantienen al vuelo dentro de la misma transacción: indexar dos veces lo mismo las deja igual.
 *
 * Qué se indexa de un producto: el publicado y con visibilidad «en todas partes» o «solo buscador» (aunque todavía no haya
 * llegado su fecha de publicación: el catálogo ya filtra por ella al enseñar). Lo demás se saca del índice.
 */
final class Index
{
    /** Cuántas entidades procesa una tanda de reindexado. */
    public const BATCH = 100;

    /** Tamaño de los grupos en los «IN (…)» y de las inserciones múltiples. */
    private const CHUNK = 400;

    /** Una raíz más larga que esto es basura (una dirección, un código): no se indexa. */
    private const TOKEN_MAX = 60;

    /** Caracteres de la descripción que se analizan. */
    private const DESC_CHARS = 6000;

    /** Variables máximas de una inserción (SQLite antiguo admite 999). */
    private const MAX_PARAMS = 900;

    /** Tablas de las entidades que se indexan. */
    private const TABLES = ['product' => 'products', 'page' => 'pages', 'post' => 'posts'];

    /** @var App */
    private $app;

    /** @var Search */
    private $search;

    public function __construct(App $app, Search $search)
    {
        $this->app = $app;
        $this->search = $search;
    }

    /**
     * Los tipos que se pueden indexar en este sitio (las páginas y las entradas solo con el módulo «cms»).
     *
     * @return string[]
     */
    public function types(?string $only = null): array
    {
        $types = ['product'];
        if ($this->app->has('cms')) {
            $types[] = 'page';
            $types[] = 'post';
        }
        if ($only !== null) {
            return in_array($only, $types, true) ? [$only] : [];
        }

        return $types;
    }

    /** Reindexa una entidad en todos los idiomas; si ya no se puede ver, la saca del índice. */
    public function index(string $type, int $id): void
    {
        $this->indexMany($type, [$id]);
    }

    /**
     * Reindexa varias entidades del mismo tipo. Devuelve cuántos documentos quedaron en el índice.
     *
     * @param int[] $ids
     */
    public function indexMany(string $type, array $ids): int
    {
        if (!isset(self::TABLES[$type])) {
            return 0;
        }
        $clean = [];
        foreach ($ids as $id) {
            if ((int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }
        $ids = array_values($clean);
        if ($ids === []) {
            return 0;
        }

        // Primero lo que puede tardar (leer las fichas y pedir los vectores); después, todo dentro de una transacción.
        $plan = [];
        foreach ($this->app->languages() as $lang) {
            $sources = $this->sources($type, $ids, $lang);
            $vectors = $this->vectors($sources);
            $plan[$lang] = [$sources, $vectors];
        }

        $stored = 0;
        $expand = [];
        $this->app->db()->transaction(function () use ($type, $ids, $plan, &$stored, &$expand): void {
            foreach ($plan as $lang => $pair) {
                list($sources, $vectors) = $pair;
                foreach ($ids as $id) {
                    $src = isset($sources[$id]) ? $sources[$id] : null;
                    if ($src === null) {
                        $this->removeDoc($type, $id, (string) $lang);
                        continue;
                    }
                    if (isset($vectors[$id])) {
                        $src['vector'] = $vectors[$id];
                    }
                    if ($this->store($type, $id, (string) $lang, $src) && $type === 'product') {
                        $expand[] = [$type, $id, (string) $lang];
                    }
                    ++$stored;
                }
            }
        });

        $this->app->invalidate(['search']);
        $this->search->forget();
        if ($expand !== [] && $this->search->expandOn() && $this->app->has('jobs')) {
            foreach ($expand as $item) {
                $this->app->service('jobs')->push('search.expand', ['type' => $item[0], 'id' => $item[1], 'lang' => $item[2]], ['unique' => 'search.expand:' . $item[0] . ':' . $item[1] . ':' . $item[2]]);
            }
        }

        return $stored;
    }

    /**
     * Guarda las formas de buscar un documento que propuso la IA y lo reindexa con ellas.
     *
     * @param string[] $terms
     */
    public function setExtraTerms(string $type, int $id, string $lang, array $terms): void
    {
        $db = $this->app->db();
        $docId = (int) $db->value('SELECT id FROM {search_docs} WHERE type = ? AND entity_id = ? AND lang = ?', [$type, $id, $lang]);
        if ($docId <= 0) {
            return;
        }
        $db->update('search_docs', ['extra_terms' => implode("\n", $terms)], ['id' => $docId]);
        $this->index($type, $id);
    }

    /**
     * El texto de una entidad que se le da a la IA para que proponga formas de buscarla (nombre, marca, categorías y resumen), o
     * null si no se puede ver.
     */
    public function textFor(string $type, int $id, string $lang): ?string
    {
        if (!isset(self::TABLES[$type]) || $id <= 0) {
            return null;
        }
        $sources = $this->sources($type, [$id], $lang);
        $src = isset($sources[$id]) ? $sources[$id] : null;
        if ($src === null) {
            return null;
        }
        $f = $src['fields'];
        $parts = [];
        foreach (['name', 'brand', 'cats', 'summary'] as $key) {
            if (trim((string) $f[$key]) !== '') {
                $parts[] = trim((string) $f[$key]);
            }
        }

        return mb_substr(implode("
", $parts), 0, 1500, 'UTF-8');
    }

    /**
     * Una tanda de reindexado: lee hasta BATCH entidades del primer tipo pendiente a partir de «after» y las indexa.
     * El cursor avanza por lo LEÍDO (el mayor identificador de la tanda), y un tipo se da por acabado cuando la tabla se
     * agota, no cuando salen menos documentos de los esperados.
     *
     * @param string[] $types tipos pendientes, el primero es el que se está recorriendo
     *
     * @return array{types:string[],after:int,read:int}
     */
    public function reindexBatch(array $types, int $after, int $limit = self::BATCH): array
    {
        $types = array_values($types);
        $type = isset($types[0]) ? (string) $types[0] : '';
        $db = $this->app->db();
        if ($type === '' || !isset(self::TABLES[$type]) || !$db->tableExists(self::TABLES[$type])) {
            return ['types' => array_slice($types, 1), 'after' => 0, 'read' => 0];
        }
        $limit = max(1, $limit);
        $ids = array_map('intval', $db->column('SELECT id FROM {' . self::TABLES[$type] . '} WHERE id > ? ORDER BY id LIMIT ?', [max(0, $after), $limit]));
        $read = count($ids);
        if ($read > 0) {
            $this->indexMany($type, $ids);
            $after = max($ids);
        }
        if ($read < $limit) {
            $this->cleanup($type);
            array_shift($types);
            $after = 0;
        }

        return ['types' => $types, 'after' => $after, 'read' => $read];
    }

    /**
     * Reindexa todo (o un tipo) ahora mismo, tanda a tanda. Devuelve cuántas entidades leyó.
     */
    public function reindexNow(?string $type = null): int
    {
        $types = $this->types($type);
        $after = 0;
        $total = 0;
        while ($types !== []) {
            $step = $this->reindexBatch($types, $after);
            $total += $step['read'];
            $types = $step['types'];
            $after = $step['after'];
        }

        return $total;
    }

    /**
     * Quita del índice lo que ya no existe: documentos de entidades borradas y de idiomas que ya no están activos.
     */
    public function cleanup(string $type): void
    {
        if (!isset(self::TABLES[$type])) {
            return;
        }
        $db = $this->app->db();
        $orphans = $db->column(
            'SELECT d.id FROM {search_docs} d WHERE d.type = ? AND NOT EXISTS (SELECT 1 FROM {' . self::TABLES[$type] . '} t WHERE t.id = d.entity_id)',
            [$type]
        );
        $params = [$type];
        $in = $db->in($this->app->languages(), $params);
        $stale = $db->column('SELECT id FROM {search_docs} WHERE type = ? AND lang NOT IN (' . $in . ')', $params);
        $db->transaction(function () use ($orphans, $stale): void {
            foreach (array_merge($orphans, $stale) as $docId) {
                $this->deleteDoc((int) $docId);
            }
        });
        $this->app->invalidate(['search']);
        $this->search->forget();
    }

    // ------------------------------------------------------------------ lo que se indexa de cada cosa

    /**
     * Lo que se indexa de cada entidad en un idioma, o null si no se puede ver (y entonces sale del índice).
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>|null> id => ['label','fields','codes','sales','in_stock','hash','vector']
     */
    private function sources(string $type, array $ids, string $lang): array
    {
        if ($type === 'product') {
            return $this->productSources($ids, $lang);
        }

        return $this->cmsSources($type, $ids, $lang);
    }

    /**
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>|null>
     */
    private function productSources(array $ids, string $lang): array
    {
        $out = array_fill_keys($ids, null);
        if (!$this->app->has('catalog')) {
            return $out;
        }
        $catalog = $this->app->service('catalog');
        $db = $this->app->db();

        $rows = [];
        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $params = [];
            $in = $db->in($chunk, $params);
            foreach ($db->select('SELECT id, status, visibility, sales_count FROM {products} WHERE id IN (' . $in . ')', $params) as $row) {
                $rows[(int) $row['id']] = $row;
            }
        }
        $wanted = [];
        foreach ($rows as $id => $row) {
            if ((string) $row['status'] === 'published' && in_array((string) $row['visibility'], ['everywhere', 'search'], true)) {
                $wanted[] = $id;
            }
        }
        if ($wanted === []) {
            return $out;
        }

        // Los que se ven ahora, de una vez; los programados para más tarde, uno a uno.
        $data = $catalog->products($wanted, $lang);
        foreach ($wanted as $id) {
            if (!isset($data[$id])) {
                $one = $catalog->product($id, $lang, true);
                if (is_array($one)) {
                    $data[$id] = $one;
                }
            }
        }

        foreach ($wanted as $id) {
            if (!isset($data[$id])) {
                continue;
            }
            $out[$id] = $this->productSource($data[$id], (int) $rows[$id]['sales_count']);
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $d ProductData
     *
     * @return array<string,mixed>
     */
    private function productSource(array $d, int $sales): array
    {
        $codes = [];
        foreach (['sku', 'gtin', 'mpn'] as $key) {
            if (isset($d[$key]) && trim((string) $d[$key]) !== '') {
                $codes[] = trim((string) $d[$key]);
            }
        }
        foreach (isset($d['variants']) && is_array($d['variants']) ? $d['variants'] : [] as $variant) {
            foreach (['sku', 'gtin'] as $key) {
                if (is_array($variant) && isset($variant[$key]) && trim((string) $variant[$key]) !== '') {
                    $codes[] = trim((string) $variant[$key]);
                }
            }
        }

        $attrs = [];
        foreach (isset($d['attributes']) && is_array($d['attributes']) ? $d['attributes'] : [] as $attribute) {
            if (is_array($attribute) && isset($attribute['value']) && is_scalar($attribute['value'])) {
                $attrs[] = (string) $attribute['value'];
            }
        }
        foreach (isset($d['options']) && is_array($d['options']) ? $d['options'] : [] as $option) {
            foreach (is_array($option) && isset($option['values']) && is_array($option['values']) ? $option['values'] : [] as $value) {
                if (is_array($value) && isset($value['label']) && is_scalar($value['label'])) {
                    $attrs[] = (string) $value['label'];
                }
            }
        }
        foreach (isset($d['facts']) && is_array($d['facts']) ? $d['facts'] : [] as $fact) {
            if (is_array($fact) && isset($fact['label'], $fact['value'])) {
                $attrs[] = (string) $fact['label'] . ' ' . (string) $fact['value'];
            }
        }
        foreach (isset($d['extra']) && is_array($d['extra']) ? $d['extra'] : [] as $extra) {
            if (is_array($extra) && !empty($extra['public']) && isset($extra['value']) && is_scalar($extra['value'])) {
                $attrs[] = (string) $extra['value'];
            }
        }

        $cats = [];
        foreach (isset($d['categories']) && is_array($d['categories']) ? $d['categories'] : [] as $category) {
            if (is_array($category) && isset($category['name'])) {
                $cats[] = (string) $category['name'];
            }
        }
        $tags = [];
        foreach (isset($d['tags']) && is_array($d['tags']) ? $d['tags'] : [] as $tag) {
            if (is_scalar($tag)) {
                $tags[] = (string) $tag;
            }
        }

        $name = isset($d['name']) ? (string) $d['name'] : '';
        $summary = Texts::plain(isset($d['summary']) ? (string) $d['summary'] : '');
        $desc = mb_substr(Texts::plain(isset($d['description']) ? (string) $d['description'] : ''), 0, self::DESC_CHARS, 'UTF-8');
        $brand = isset($d['brand']) && is_array($d['brand']) && isset($d['brand']['name']) ? (string) $d['brand']['name'] : '';
        $status = isset($d['availability']['status']) ? (string) $d['availability']['status'] : 'in_stock';

        return [
            'label' => $name,
            'fields' => [
                'name' => $name, 'brand' => $brand, 'cats' => implode(' ', $cats), 'tags' => implode(' ', $tags),
                'attrs' => implode(' ', $attrs), 'summary' => $summary, 'desc' => $desc,
            ],
            'codes' => $codes,
            'sales' => max(0, $sales),
            'in_stock' => $status !== 'out_of_stock',
            'hash' => md5($name . '|' . $summary),
            'vector' => null,
        ];
    }

    /**
     * Páginas y entradas: solo las que se ven ahora.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>|null>
     */
    private function cmsSources(string $type, array $ids, string $lang): array
    {
        $out = array_fill_keys($ids, null);
        if (!$this->app->has('cms') || !in_array($type, ['page', 'post'], true)) {
            return $out;
        }
        $cms = $this->app->service('cms');
        foreach ($ids as $id) {
            $d = $type === 'page' ? $cms->page($id, $lang) : $cms->post($id, $lang);
            if (!is_array($d)) {
                continue;
            }
            $cats = [];
            foreach (isset($d['categories']) && is_array($d['categories']) ? $d['categories'] : [] as $term) {
                if (is_array($term) && isset($term['name'])) {
                    $cats[] = (string) $term['name'];
                }
            }
            $tags = [];
            foreach (isset($d['tags']) && is_array($d['tags']) ? $d['tags'] : [] as $term) {
                if (is_array($term) && isset($term['name'])) {
                    $tags[] = (string) $term['name'];
                }
            }
            $title = isset($d['title']) ? (string) $d['title'] : '';
            $summary = isset($d['excerpt']) ? (string) $d['excerpt'] : '';
            $out[$id] = [
                'label' => $title,
                'fields' => [
                    'name' => $title, 'brand' => '', 'cats' => implode(' ', $cats), 'tags' => implode(' ', $tags), 'attrs' => '',
                    'summary' => $summary, 'desc' => mb_substr(isset($d['text']) ? (string) $d['text'] : '', 0, self::DESC_CHARS, 'UTF-8'),
                ],
                'codes' => [],
                'sales' => 0,
                'in_stock' => true,
                'hash' => md5($title . '|' . $summary),
                'vector' => null,
            ];
        }

        return $out;
    }

    /**
     * Los vectores de significado de los documentos (solo con el ajuste encendido y el servicio «ai» activo): id => base64.
     * Si la IA falla, no hay vectores y el indexado sigue sin ellos.
     *
     * @param array<int,array<string,mixed>|null> $sources
     *
     * @return array<int,string>
     */
    private function vectors(array $sources): array
    {
        if (!$this->search->allowsAiSideEffects() || !$this->search->vectorsOn()) {
            return [];
        }
        $texts = [];
        foreach ($sources as $id => $src) {
            if ($src === null) {
                continue;
            }
            $f = $src['fields'];
            $texts[(int) $id] = mb_substr(trim($f['name'] . '. ' . $f['brand'] . '. ' . $f['cats'] . '. ' . $f['summary'] . ' ' . mb_substr($f['desc'], 0, 400, 'UTF-8')), 0, 1500, 'UTF-8');
        }
        $out = [];
        foreach (array_chunk($texts, 32, true) as $chunk) {
            try {
                $vectors = $this->app->service('ai')->embed(array_values($chunk));
            } catch (\Throwable $e) {
                $this->app->log()->warning('Search: could not get the vectors', ['error' => $e->getMessage()]);

                return $out;
            }
            $ids = array_keys($chunk);
            foreach (is_array($vectors) ? array_values($vectors) : [] as $i => $vector) {
                if (isset($ids[$i]) && is_array($vector) && $vector !== []) {
                    $out[$ids[$i]] = base64_encode(Vector::pack(Vector::normalize($vector)));
                }
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ escritura

    /**
     * Pone un documento en el índice (lo sustituye si ya estaba). Devuelve true si todavía no tiene términos de la IA
     * y hay que pedirlos.
     *
     * @param array<string,mixed> $src
     */
    private function store(string $type, int $id, string $lang, array $src): bool
    {
        $db = $this->app->db();
        $analyzer = $this->search->analyzer($lang);
        $existing = $db->row('SELECT id, extra_terms, src_hash FROM {search_docs} WHERE type = ? AND entity_id = ? AND lang = ?', [$type, $id, $lang]);

        // Los términos de la IA valen mientras no cambie el texto del que salieron.
        $extra = null;
        if ($existing !== null && $existing['extra_terms'] !== null && (string) $existing['src_hash'] === (string) $src['hash']) {
            $extra = (string) $existing['extra_terms'];
        }

        list($tf, $len, $surfaces) = $this->analyze($analyzer, $src, $extra);

        $row = [
            'label' => mb_substr((string) $src['label'], 0, 191, 'UTF-8'),
            'name_norm' => mb_substr($analyzer->normalize((string) $src['label']), 0, 191, 'UTF-8'),
            'sales' => (int) $src['sales'],
            'in_stock' => $src['in_stock'] ? 1 : 0,
            'extra_terms' => $extra,
            'src_hash' => (string) $src['hash'],
            'indexed_at' => $this->app->clock()->sql(),
        ];
        foreach (Fields::ALL as $field) {
            $row['len_' . $field] = (int) $len[$field];
        }
        if (isset($src['vector']) && is_string($src['vector']) && $src['vector'] !== '') {
            $row['vector'] = $src['vector'];
        }

        $old = [];
        if ($existing === null) {
            $docId = $db->insert('search_docs', $row + ['type' => $type, 'entity_id' => $id, 'lang' => $lang]);
        } else {
            $docId = (int) $existing['id'];
            $old = array_map('intval', $db->column('SELECT term_id FROM {search_postings} WHERE doc_id = ?', [$docId]));
            $db->run('DELETE FROM {search_postings} WHERE doc_id = ?', [$docId]);
            $this->bumpDf($old, -1);
            $db->update('search_docs', $row, ['id' => $docId]);
        }

        $terms = array_map('strval', array_keys($tf));
        $ids = $this->termIds($lang, $terms, $surfaces);
        $this->bumpDf(array_values($ids), 1);
        $this->insertPostings($docId, $tf, $ids);
        if ($old !== []) {
            $this->dropEmptyTerms($old);
        }

        return $extra === null;
    }

    /**
     * Las raíces de cada campo, su longitud y la forma escrita de cada raíz.
     *
     * @param array<string,mixed> $src
     *
     * @return array{0:array<string,int[]>,1:array<string,int>,2:array<string,string>} apariciones por término (en el orden de Fields::ALL), longitudes y formas escritas
     */
    private function analyze(Analyzer $analyzer, array $src, ?string $extra): array
    {
        $tf = [];
        $len = [];
        $surfaces = [];
        $width = count(Fields::ALL);
        foreach (Fields::ALL as $i => $field) {
            $len[$field] = 0;
            $tokens = [];
            if ($field === 'ref') {
                foreach ($src['codes'] as $code) {
                    $tokens = array_merge($tokens, $analyzer->tokens((string) $code));
                    // El código entero, con marca, para reconocer la coincidencia exacta sin confundirlo con sus trozos.
                    $whole = preg_replace('/\s+/', '', $analyzer->fold((string) $code));
                    if (is_string($whole) && $whole !== '') {
                        $tokens[] = '#' . $whole;
                    }
                }
            } else {
                $text = $field === 'extra' ? (string) $extra : (isset($src['fields'][$field]) ? (string) $src['fields'][$field] : '');
                if ($text === '') {
                    continue;
                }
                $tokens = $analyzer->tokens($text);
                foreach ($analyzer->surfaces($text) as $stem => $surface) {
                    if (!isset($surfaces[$stem]) || mb_strlen($surface, 'UTF-8') < mb_strlen($surfaces[$stem], 'UTF-8')) {
                        $surfaces[$stem] = $surface;
                    }
                }
            }
            foreach ($tokens as $token) {
                $token = (string) $token;
                if ($token === '' || strlen($token) > self::TOKEN_MAX) {
                    continue;
                }
                if (!isset($tf[$token])) {
                    $tf[$token] = array_fill(0, $width, 0);
                }
                ++$tf[$token][$i];
                ++$len[$field];
            }
        }

        return [$tf, $len, $surfaces];
    }

    /**
     * Los identificadores de unos términos de un idioma; los que no existen se dan de alta.
     *
     * @param string[]             $terms
     * @param array<string,string> $surfaces
     *
     * @return array<string,int> término => id
     */
    private function termIds(string $lang, array $terms, array $surfaces): array
    {
        $db = $this->app->db();
        $map = [];
        foreach (array_chunk($terms, self::CHUNK) as $chunk) {
            $params = [$lang];
            $in = $db->in($chunk, $params);
            foreach ($db->select('SELECT id, term FROM {search_terms} WHERE lang = ? AND term IN (' . $in . ')', $params) as $row) {
                $map[(string) $row['term']] = (int) $row['id'];
            }
        }
        $missing = [];
        foreach ($terms as $term) {
            if (!isset($map[$term])) {
                $missing[] = $term;
            }
        }
        if ($missing === []) {
            return $map;
        }
        foreach ($missing as $term) {
            $surface = isset($surfaces[$term]) ? $surfaces[$term] : $term;
            $db->insertIgnore('search_terms', [
                'lang' => $lang, 'term' => $term, 'surface' => mb_substr($surface, 0, 100, 'UTF-8'),
                'len' => mb_strlen($term, 'UTF-8'), 'df' => 0,
            ]);
        }
        foreach (array_chunk($missing, self::CHUNK) as $chunk) {
            $params = [$lang];
            $in = $db->in($chunk, $params);
            foreach ($db->select('SELECT id, term FROM {search_terms} WHERE lang = ? AND term IN (' . $in . ')', $params) as $row) {
                $map[(string) $row['term']] = (int) $row['id'];
            }
        }

        return $map;
    }

    /**
     * Suma o resta uno a «en cuántos documentos sale» de unos términos.
     *
     * @param int[] $termIds
     */
    private function bumpDf(array $termIds, int $delta): void
    {
        $db = $this->app->db();
        foreach (array_chunk($termIds, self::CHUNK) as $chunk) {
            $params = [];
            $in = $db->in($chunk, $params);
            $db->run('UPDATE {search_terms} SET df = df ' . ($delta < 0 ? '-' : '+') . ' 1 WHERE id IN (' . $in . ')', $params);
        }
    }

    /**
     * Quita del vocabulario los términos que ya no salen en ningún documento.
     *
     * @param int[] $termIds
     */
    private function dropEmptyTerms(array $termIds): void
    {
        $db = $this->app->db();
        foreach (array_chunk($termIds, self::CHUNK) as $chunk) {
            $params = [];
            $in = $db->in($chunk, $params);
            $db->run('DELETE FROM {search_terms} WHERE df <= 0 AND id IN (' . $in . ')', $params);
        }
    }

    /**
     * @param array<string,int[]> $tf
     * @param array<string,int>   $ids término => id
     */
    private function insertPostings(int $docId, array $tf, array $ids): void
    {
        $columns = array_merge(['term_id', 'doc_id'], Fields::postingColumns());
        $width = count($columns);
        $rows = [];
        foreach ($tf as $term => $counts) {
            $term = (string) $term;
            if (!isset($ids[$term])) {
                continue;
            }
            $rows[] = array_merge([$ids[$term], $docId], $counts);
        }
        $db = $this->app->db();
        $placeholder = '(' . implode(', ', array_fill(0, $width, '?')) . ')';
        foreach (array_chunk($rows, max(1, intdiv(self::MAX_PARAMS, $width))) as $chunk) {
            $params = [];
            foreach ($chunk as $row) {
                foreach ($row as $value) {
                    $params[] = $value;
                }
            }
            $db->run('INSERT INTO {search_postings} (' . implode(', ', $columns) . ') VALUES ' . implode(', ', array_fill(0, count($chunk), $placeholder)), $params);
        }
    }

    /** Quita el documento de una entidad en un idioma, si estaba. */
    private function removeDoc(string $type, int $id, string $lang): void
    {
        $docId = (int) $this->app->db()->value('SELECT id FROM {search_docs} WHERE type = ? AND entity_id = ? AND lang = ?', [$type, $id, $lang]);
        if ($docId > 0) {
            $this->deleteDoc($docId);
        }
    }

    /** Quita un documento, sus apariciones y las cuentas de sus términos. */
    private function deleteDoc(int $docId): void
    {
        $db = $this->app->db();
        $terms = array_map('intval', $db->column('SELECT term_id FROM {search_postings} WHERE doc_id = ?', [$docId]));
        $db->run('DELETE FROM {search_postings} WHERE doc_id = ?', [$docId]);
        $this->bumpDf($terms, -1);
        $this->dropEmptyTerms($terms);
        $db->run('DELETE FROM {search_docs} WHERE id = ?', [$docId]);
    }
}
