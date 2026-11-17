<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog;

use Aifec\Kernel\App;

/**
 * El listado de productos con filtros, orden, paginación y facetas (catalog->listing() del contrato).
 *
 * Cuesta un número FIJO de consultas por página: una para contar, una para los identificadores de la página y las de
 * cargar las tarjetas (las mismas con 5 que con 40 productos), más las de las facetas. Los precios y las
 * ofertas se comparan «tal como se escriben», sobre el precio vigente. Los filtros por atributo valen para los
 * atributos de lista (select, multiselect, color), tanto los del producto como los de sus variantes activas.
 */
final class Listing
{
    /** @var App */
    private $app;

    /** @var Catalog */
    private $catalog;

    /** @var Attributes */
    private $attributes;

    public function __construct(App $app, Catalog $catalog, Attributes $attributes)
    {
        $this->app = $app;
        $this->catalog = $catalog;
        $this->attributes = $attributes;
    }

    /**
     * @param array<string,mixed> $query ver el contrato de comercio § 4
     *
     * @return array<string,mixed>
     */
    public function run(array $query): array
    {
        $filtered = $this->app->hooks()->apply('listing.query', $query);
        $n = $this->normalize(is_array($filtered) ? $filtered : $query);
        $lang = $n['lang'];
        $db = $this->app->db();

        $sorts = $this->sorts($lang);
        $sort = isset($sorts[$n['sort']]) ? $n['sort'] : 'relevance';

        $ctx = [
            'n' => $n,
            'now' => $this->app->clock()->sql(),
            'search_ids' => $this->searchIds($n['q'], $lang),
            'category_path' => null,
        ];
        if ($n['category_id'] > 0) {
            $row = $db->row('SELECT id, path FROM {categories} WHERE id = ? AND active = 1', [$n['category_id']]);
            $ctx['category_path'] = $row !== null ? Categories::pathOf($row) : false;
        }

        $params = [];
        $where = $this->where($ctx, [], $params);
        $total = (int) $db->value('SELECT COUNT(*) FROM {products} p WHERE ' . $where, $params);
        $perPage = $n['per_page'];
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($n['page'], $pages);
        $offset = ($page - 1) * $perPage;

        if ($ctx['search_ids'] !== null && $sort === 'relevance') {
            // El buscador manda: se leen los que casan (como mucho 200) y se ponen en el orden que él dio.
            $found = array_map('intval', $db->column('SELECT p.id FROM {products} p WHERE ' . $where, $params));
            $position = array_flip($ctx['search_ids']);
            usort($found, static function (int $a, int $b) use ($position): int {
                return $position[$a] <=> $position[$b];
            });
            $pageIds = array_slice($found, $offset, $perPage);
        } else {
            $pageParams = $params;
            $order = $this->order($sort, $ctx, $pageParams);
            $pageParams[] = $perPage;
            $pageParams[] = $offset;
            $pageIds = array_map('intval', $db->column('SELECT p.id FROM {products} p WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ? OFFSET ?', $pageParams));
        }

        $cards = $this->catalog->cards($pageIds, $lang);
        $items = [];
        foreach ($pageIds as $id) {
            if (isset($cards[$id])) {
                $items[] = $cards[$id];
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'pages' => $pages,
            'per_page' => $perPage,
            'sort' => $sort,
            'sorts' => $sorts,
            'facets' => $this->facets($ctx, $lang),
            'price_range' => $this->priceRange($ctx),
        ];
    }

    /**
     * Los órdenes que se pueden pedir, con su texto. El filtro listing.sorts (array $sorts) puede añadir más.
     *
     * @return array<string,string>
     */
    public function sorts(string $lang): array
    {
        $i18n = $this->app->i18n();
        $base = [
            'relevance' => $i18n->t('Relevance', [], $lang),
            'newest' => $i18n->t('Newest', [], $lang),
            'price_asc' => $i18n->t('Price: low to high', [], $lang),
            'price_desc' => $i18n->t('Price: high to low', [], $lang),
            'name' => $i18n->t('Name', [], $lang),
            'best' => $i18n->t('Best sellers', [], $lang),
        ];
        $filtered = $this->app->hooks()->apply('listing.sorts', $base);

        return is_array($filtered) && $filtered !== [] ? $filtered : $base;
    }

    /**
     * Lo que se pidió, limpio y con sus valores por defecto.
     *
     * @param array<string,mixed> $q
     *
     * @return array<string,mixed>
     */
    private function normalize(array $q): array
    {
        $perPage = isset($q['per_page']) && (int) $q['per_page'] > 0 ? (int) $q['per_page'] : $this->app->settings()->int('catalog.per_page', 24);
        $filters = [];
        foreach (isset($q['filters']) && is_array($q['filters']) ? $q['filters'] : [] as $code => $values) {
            $clean = [];
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $clean[(string) $value] = (string) $value;
                }
            }
            if ($clean !== []) {
                $filters[(string) $code] = array_values($clean);
            }
        }
        $ids = null;
        if (isset($q['ids']) && is_array($q['ids'])) {
            $ids = [];
            foreach ($q['ids'] as $id) {
                if ((int) $id > 0) {
                    $ids[] = (int) $id;
                }
            }
        }

        return [
            'lang' => $this->catalog->lang(isset($q['lang']) && is_string($q['lang']) ? $q['lang'] : null),
            'category_id' => isset($q['category_id']) ? (int) $q['category_id'] : 0,
            'brand_id' => isset($q['brand_id']) ? (int) $q['brand_id'] : 0,
            'tag' => isset($q['tag']) && is_scalar($q['tag']) ? trim((string) $q['tag']) : '',
            'ids' => $ids,
            'q' => isset($q['q']) && is_scalar($q['q']) ? trim((string) $q['q']) : '',
            'filters' => $filters,
            'price_min' => $this->cents($q, 'price_min'),
            'price_max' => $this->cents($q, 'price_max'),
            'on_sale' => !empty($q['on_sale']),
            'in_stock' => !empty($q['in_stock']),
            'featured' => !empty($q['featured']),
            'sort' => isset($q['sort']) && is_scalar($q['sort']) ? (string) $q['sort'] : 'relevance',
            'page' => max(1, isset($q['page']) ? (int) $q['page'] : 1),
            'per_page' => max(1, min(96, $perPage)),
        ];
    }

    /**
     * @param array<string,mixed> $q
     */
    private function cents(array $q, string $key): ?int
    {
        return isset($q[$key]) && $q[$key] !== '' && is_numeric($q[$key]) ? max(0, (int) $q[$key]) : null;
    }

    /**
     * Los identificadores que da el servicio «search» (ya ordenados), o null si no hay buscador, no se buscó nada o falló.
     *
     * @return int[]|null
     */
    private function searchIds(string $q, string $lang): ?array
    {
        if ($q === '' || !$this->app->has('search')) {
            return null;
        }
        try {
            $search = $this->app->service('search');
            $result = is_callable([$search, 'query']) ? $search->query($q, ['lang' => $lang, 'limit' => 200]) : null;
        } catch (\Throwable $e) {
            return null;
        }
        if (!is_array($result) || !isset($result['ids']) || !is_array($result['ids'])) {
            return null;
        }
        $ids = [];
        foreach ($result['ids'] as $id) {
            $ids[] = (int) $id;
        }

        return array_values(array_unique($ids));
    }

    // ------------------------------------------------------------------ SQL
    // Los parámetros se añaden a $params en el mismo orden en que sus «?» quedan en el texto de la consulta.

    /**
     * El WHERE completo del listado. $skip nombra los filtros que no se aplican (para contar las facetas):
     * «brand», «price» o «attr:<código>».
     *
     * @param array<string,mixed>     $c
     * @param string[]                $skip
     * @param array<int|string,mixed> $params
     */
    private function where(array $c, array $skip, array &$params): string
    {
        $n = $c['n'];
        $parts = ["p.status = 'published'"];
        $parts[] = '(p.published_at IS NULL OR p.published_at <= ?)';
        $params[] = $c['now'];
        $parts[] = $n['q'] !== '' ? "p.visibility IN ('everywhere', 'search')" : "p.visibility IN ('everywhere', 'catalog')";

        if ($n['in_stock'] || !$this->app->settings()->bool('catalog.show_out_of_stock', true)) {
            $parts[] = $this->stockSql();
        }
        if ($n['featured']) {
            $parts[] = 'p.featured = 1';
        }
        if ($n['ids'] !== null) {
            $parts[] = $this->idsSql('p.id', $n['ids'], $params);
        }
        if ($n['q'] !== '') {
            $parts[] = $c['search_ids'] !== null ? $this->idsSql('p.id', $c['search_ids'], $params) : $this->likeSql($n['q'], $n['lang'], $params);
        }
        if ($c['category_path'] === false) {
            $parts[] = '1 = 0';
        } elseif ($c['category_path'] !== null) {
            $parts[] = 'EXISTS (SELECT 1 FROM {product_categories} pc WHERE pc.product_id = p.id AND pc.category_id IN ('
                . $this->subtree($n['category_id'], (string) $c['category_path'], $params) . '))';
        }
        if ($n['brand_id'] > 0 && !in_array('brand', $skip, true)) {
            $parts[] = 'p.brand_id = ?';
            $params[] = $n['brand_id'];
        }
        if ($n['tag'] !== '') {
            $parts[] = 'EXISTS (SELECT 1 FROM {product_tags} pt JOIN {tags} t ON t.id = pt.tag_id WHERE pt.product_id = p.id AND t.name = ?)';
            $params[] = $n['tag'];
        }
        foreach ($n['filters'] as $code => $values) {
            if (!in_array('attr:' . $code, $skip, true)) {
                $parts[] = $this->attributeSql($code, $values, $params);
            }
        }
        if (($n['price_min'] !== null || $n['price_max'] !== null) && !in_array('price', $skip, true)) {
            $parts[] = $this->priceSql($n['price_min'], $n['price_max'], $c['now'], $params);
        }
        if ($n['on_sale']) {
            $parts[] = $this->onSaleSql($c['now'], $params);
        }

        return implode(' AND ', $parts);
    }

    /** Hay existencias para vender: sin control, con pedidos sin existencias o con unidades (en el producto o en una variante activa). */
    private function stockSql(): string
    {
        return "((p.has_variants = 0 AND (p.manage_stock = 0 OR p.backorders = 'yes' OR p.stock > 0))"
            . " OR (p.has_variants = 1 AND EXISTS (SELECT 1 FROM {variants} v WHERE v.product_id = p.id AND v.active = 1"
            . " AND (p.manage_stock = 0 OR p.backorders = 'yes' OR v.stock > 0))))";
    }

    /**
     * @param int[]                   $ids
     * @param array<int|string,mixed> $params
     */
    private function idsSql(string $column, array $ids, array &$params): string
    {
        if ($ids === []) {
            return '1 = 0';
        }
        foreach ($ids as $id) {
            $params[] = (int) $id;
        }

        return $column . ' IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')';
    }

    /**
     * Sin buscador propio: cada palabra tiene que estar en el nombre (en el idioma pedido o el principal), la referencia
     * o el código de barras.
     *
     * @param array<int|string,mixed> $params
     */
    private function likeSql(string $q, string $lang, array &$params): string
    {
        $words = preg_split('/\s+/u', $q, -1, PREG_SPLIT_NO_EMPTY);
        $words = is_array($words) ? array_slice($words, 0, 6) : [];
        $parts = [];
        $default = $this->app->defaultLang();
        foreach ($words as $word) {
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word) . '%';
            $parts[] = "(EXISTS (SELECT 1 FROM {product_i18n} i WHERE i.product_id = p.id AND i.lang IN (?, ?) AND i.name LIKE ? ESCAPE '!')"
                . " OR p.sku LIKE ? ESCAPE '!' OR p.gtin LIKE ? ESCAPE '!')";
            array_push($params, $lang, $default, $like, $like, $like);
        }

        return $parts === [] ? '1 = 1' : implode(' AND ', $parts);
    }

    /**
     * Las categorías de la rama: la propia y todas las que cuelgan de ella.
     *
     * @param array<int|string,mixed> $params
     */
    private function subtree(int $id, string $path, array &$params): string
    {
        array_push($params, $id, $path, $path . '/%');

        return 'SELECT c.id FROM {categories} c WHERE c.active = 1 AND (c.id = ? OR c.path = ? OR c.path LIKE ?)';
    }

    /**
     * Los productos que tienen alguno de esos valores del atributo, en el producto o en una variante activa.
     *
     * @param string[]                $values
     * @param array<int|string,mixed> $params
     */
    private function attributeSql(string $code, array $values, array &$params): string
    {
        $sql = '(EXISTS (SELECT 1 FROM {product_attributes} pa JOIN {attributes} a ON a.id = pa.attribute_id'
            . ' JOIN {attribute_values} av ON av.id = pa.value_id WHERE pa.product_id = p.id AND a.code = ? AND av.code IN (';
        $params[] = $code;
        $sql .= $this->marks($values, $params) . ')) OR EXISTS (SELECT 1 FROM {variants} v JOIN {variant_values} vv ON vv.variant_id = v.id'
            . ' JOIN {attributes} a ON a.id = vv.attribute_id JOIN {attribute_values} av ON av.id = vv.value_id'
            . ' WHERE v.product_id = p.id AND v.active = 1 AND a.code = ? AND av.code IN (';
        $params[] = $code;
        $sql .= $this->marks($values, $params) . ')))';

        return $sql;
    }

    /**
     * @param array<int,mixed>        $values
     * @param array<int|string,mixed> $params
     */
    private function marks(array $values, array &$params): string
    {
        foreach ($values as $value) {
            $params[] = $value;
        }

        return implode(', ', array_fill(0, count($values), '?'));
    }

    /**
     * El precio vigente del producto sin variantes: el de oferta si está en marcha, si no el normal.
     *
     * @param array<int|string,mixed> $params
     */
    private function effProduct(string $now, array &$params): string
    {
        $params[] = $now;
        $params[] = $now;

        return '(CASE WHEN p.sale_price IS NOT NULL AND p.sale_price < p.price AND (p.sale_from IS NULL OR p.sale_from <= ?)'
            . ' AND (p.sale_to IS NULL OR p.sale_to >= ?) THEN p.sale_price ELSE p.price END)';
    }

    /**
     * El precio vigente de una variante (alias «v»): el suyo o, si no tiene, el del producto.
     *
     * @param array<int|string,mixed> $params
     */
    private function effVariant(string $now, array &$params): string
    {
        return '(CASE WHEN v.price IS NULL THEN ' . $this->effProduct($now, $params) . ' ELSE v.price END)';
    }

    /**
     * El precio más bajo (MIN) o más alto (MAX) de un producto: el de sus variantes activas si las tiene, si no el suyo.
     *
     * @param array<int|string,mixed> $params
     */
    private function edgeSql(string $function, string $now, array &$params): string
    {
        $sql = 'CASE WHEN p.has_variants = 1 AND EXISTS (SELECT 1 FROM {variants} v WHERE v.product_id = p.id AND v.active = 1)'
            . ' THEN (SELECT ' . $function . '(';
        $sql .= $this->effVariant($now, $params);
        $sql .= ') FROM {variants} v WHERE v.product_id = p.id AND v.active = 1) ELSE ';
        $sql .= $this->effProduct($now, $params) . ' END';

        return $sql;
    }

    /**
     * Algún precio vigente (el del producto, o el de alguna variante activa) dentro del rango.
     *
     * @param array<int|string,mixed> $params
     */
    private function priceSql(?int $min, ?int $max, string $now, array &$params): string
    {
        $range = function (bool $variant) use ($min, $max, $now, &$params): string {
            $parts = [];
            if ($min !== null) {
                $expr = $variant ? $this->effVariant($now, $params) : $this->effProduct($now, $params);
                $params[] = $min;
                $parts[] = $expr . ' >= ?';
            }
            if ($max !== null) {
                $expr = $variant ? $this->effVariant($now, $params) : $this->effProduct($now, $params);
                $params[] = $max;
                $parts[] = $expr . ' <= ?';
            }

            return '(' . implode(' AND ', $parts) . ')';
        };
        $sql = '((p.has_variants = 0 AND ' . $range(false) . ')';
        $sql .= ' OR (p.has_variants = 1 AND EXISTS (SELECT 1 FROM {variants} v WHERE v.product_id = p.id AND v.active = 1 AND ' . $range(true) . ')))';

        return $sql;
    }

    /**
     * En oferta: tiene un precio de comparación mayor que el vigente (oferta en marcha o compare_price), en el producto
     * o en alguna variante activa.
     *
     * @param array<int|string,mixed> $params
     */
    private function onSaleSql(string $now, array &$params): string
    {
        $product = function () use ($now, &$params): string {
            $params[] = $now;
            $params[] = $now;

            return '((p.sale_price IS NOT NULL AND p.sale_price < p.price AND (p.sale_from IS NULL OR p.sale_from <= ?)'
                . ' AND (p.sale_to IS NULL OR p.sale_to >= ?)) OR (p.compare_price IS NOT NULL AND p.compare_price > p.price))';
        };
        $sql = '((p.has_variants = 0 AND ' . $product() . ')';
        $sql .= ' OR (p.has_variants = 1 AND EXISTS (SELECT 1 FROM {variants} v WHERE v.product_id = p.id AND v.active = 1 AND ((v.price IS NULL AND ';
        $sql .= $product() . ') OR (v.price IS NOT NULL AND v.compare_price IS NOT NULL AND v.compare_price > v.price)))))';

        return $sql;
    }

    /**
     * El ORDER BY de cada orden, con desempate por identificador para que sea estable.
     *
     * @param array<string,mixed>     $c
     * @param array<int|string,mixed> $params los de la consulta; se le añaden los del orden
     */
    private function order(string $sort, array $c, array &$params): string
    {
        $now = $c['now'];
        if ($sort === 'newest') {
            return 'COALESCE(p.published_at, p.created_at) DESC, p.id DESC';
        }
        if ($sort === 'best') {
            return 'p.sales_count DESC, p.id ASC';
        }
        if ($sort === 'price_asc' || $sort === 'price_desc') {
            $sql = $this->edgeSql('MIN', $now, $params);

            return $sql . ($sort === 'price_asc' ? ' ASC' : ' DESC') . ', p.id ASC';
        }
        if ($sort === 'name') {
            array_push($params, $c['n']['lang'], $this->app->defaultLang());

            return 'LOWER(COALESCE((SELECT i.name FROM {product_i18n} i WHERE i.product_id = p.id AND i.lang = ?),'
                . ' (SELECT i.name FROM {product_i18n} i WHERE i.product_id = p.id AND i.lang = ?))) ASC, p.id ASC';
        }
        // Relevancia: la posición en la categoría (la menor de las de la rama), después la del producto.
        if (is_string($c['category_path'])) {
            $sub = $this->subtree($c['n']['category_id'], $c['category_path'], $params);

            return '(SELECT MIN(pc.position) FROM {product_categories} pc WHERE pc.product_id = p.id AND pc.category_id IN (' . $sub . ')) ASC,'
                . ' p.position ASC, p.id ASC';
        }

        return 'p.position ASC, p.id ASC';
    }

    // ------------------------------------------------------------------ facetas

    /**
     * Marcas y atributos filtrables con el número de productos de cada valor, contando con todos los demás filtros
     * aplicados (el filtro de la propia faceta no se aplica, para poder ver las alternativas) y marcando los elegidos.
     *
     * @param array<string,mixed> $c
     *
     * @return array<int,array<string,mixed>>
     */
    private function facets(array $c, string $lang): array
    {
        $db = $this->app->db();
        $n = $c['n'];
        $facets = [];

        // Marcas.
        $params = [];
        $where = $this->where($c, ['brand'], $params);
        $counts = [];
        foreach ($db->select('SELECT p.brand_id AS id, COUNT(*) AS n FROM {products} p WHERE ' . $where . ' AND p.brand_id IS NOT NULL GROUP BY p.brand_id', $params) as $row) {
            $counts[(int) $row['id']] = (int) $row['n'];
        }
        $wanted = array_keys($counts);
        if ($n['brand_id'] > 0 && !isset($counts[$n['brand_id']])) {
            $wanted[] = $n['brand_id'];
        }
        if ($wanted !== []) {
            $brandParams = [];
            $marks = $db->in($wanted, $brandParams);
            $values = [];
            foreach ($db->select('SELECT id, name FROM {brands} WHERE active = 1 AND id IN (' . $marks . ') ORDER BY position, name, id', $brandParams) as $row) {
                $id = (int) $row['id'];
                $values[] = [
                    'code' => (string) $id,
                    'label' => (string) $row['name'],
                    'count' => isset($counts[$id]) ? $counts[$id] : 0,
                    'selected' => $id === $n['brand_id'],
                ];
            }
            if ($values !== []) {
                $facets[] = ['code' => 'brand', 'name' => $this->app->i18n()->t('Brand', [], $lang), 'type' => 'brand', 'values' => $values];
            }
        }

        // Atributos filtrables de lista.
        $attrs = $this->attributes->all($lang);
        $candidates = [];
        $byCode = [];
        foreach ($attrs as $id => $attr) {
            if ($attr['filterable'] && in_array($attr['type'], ['select', 'multiselect', 'color'], true) && $attr['values'] !== []) {
                $candidates[] = $id;
                $byCode[$attr['code']] = $id;
            }
        }
        if ($candidates !== []) {
            $counts = $this->attributeCounts($c, [], null);
            foreach ($n['filters'] as $code => $selectedCodes) {
                if (isset($byCode[$code])) {
                    $own = $this->attributeCounts($c, ['attr:' . $code], $byCode[$code]);
                    $counts[$byCode[$code]] = isset($own[$byCode[$code]]) ? $own[$byCode[$code]] : [];
                }
            }
            foreach ($candidates as $attributeId) {
                $attr = $attrs[$attributeId];
                $selected = isset($n['filters'][$attr['code']]) ? $n['filters'][$attr['code']] : [];
                $values = [];
                foreach ($attr['values'] as $valueId => $value) {
                    $count = isset($counts[$attributeId][$valueId]) ? $counts[$attributeId][$valueId] : 0;
                    $isSelected = in_array($value['code'], $selected, true);
                    if ($count === 0 && !$isSelected) {
                        continue;
                    }
                    $item = ['code' => $value['code'], 'label' => $value['label'], 'count' => $count, 'selected' => $isSelected];
                    if ($attr['type'] === 'color') {
                        $item['color'] = $value['color'];
                    }
                    $values[] = $item;
                }
                if ($values !== []) {
                    $facets[] = ['code' => $attr['code'], 'name' => $attr['name'], 'type' => $attr['type'], 'values' => $values];
                }
            }
        }

        return $facets;
    }

    /**
     * Cuántos productos tiene cada valor de cada atributo (o solo de uno), contando los del producto y los de sus
     * variantes activas, con el WHERE del listado menos lo que se salta.
     *
     * @param array<string,mixed> $c
     * @param string[]            $skip
     *
     * @return array<int,array<int,int>> atributo => valor => productos
     */
    private function attributeCounts(array $c, array $skip, ?int $only): array
    {
        $params = [];
        $inner = 'SELECT p.id FROM {products} p WHERE ' . $this->where($c, $skip, $params);
        $sql = 'SELECT x.attribute_id AS attribute_id, x.value_id AS value_id, COUNT(DISTINCT x.product_id) AS n FROM ('
            . 'SELECT pa.attribute_id AS attribute_id, pa.value_id AS value_id, pa.product_id AS product_id FROM {product_attributes} pa'
            . ' WHERE pa.value_id IS NOT NULL AND pa.product_id IN (' . $inner . ')'
            . ' UNION ALL '
            . 'SELECT vv.attribute_id, vv.value_id, v.product_id FROM {variant_values} vv JOIN {variants} v ON v.id = vv.variant_id AND v.active = 1'
            . ' WHERE v.product_id IN (' . $inner . ')) x';
        $all = array_merge($params, $params);
        if ($only !== null) {
            $sql .= ' WHERE x.attribute_id = ?';
            $all[] = $only;
        }
        $sql .= ' GROUP BY x.attribute_id, x.value_id';
        $out = [];
        foreach ($this->app->db()->select($sql, $all) as $row) {
            $out[(int) $row['attribute_id']][(int) $row['value_id']] = (int) $row['n'];
        }

        return $out;
    }

    /**
     * El precio vigente más bajo y más alto del conjunto (sin aplicar el propio filtro de precio, para que el control
     * pueda ensancharse). [0, 0] si no hay productos.
     *
     * @param array<string,mixed> $c
     *
     * @return array{0:int,1:int}
     */
    private function priceRange(array $c): array
    {
        $params = [];
        $low = $this->edgeSql('MIN', $c['now'], $params);
        $high = $this->edgeSql('MAX', $c['now'], $params);
        $sql = 'SELECT MIN(t.lo) AS lo, MAX(t.hi) AS hi FROM (SELECT ' . $low . ' AS lo, ' . $high . ' AS hi FROM {products} p WHERE '
            . $this->where($c, ['price'], $params) . ') t';
        $row = $this->app->db()->row($sql, $params);
        if ($row === null || $row['lo'] === null) {
            return [0, 0];
        }

        return [(int) $row['lo'], (int) $row['hi']];
    }
}
