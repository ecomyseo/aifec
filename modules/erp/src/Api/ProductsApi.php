<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp\Api;

use Aifec\Commerce\Money;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Erp\ApiException;
use Aifec\Modules\Erp\CatalogWrites;
use Aifec\Modules\Erp\Present;
use Aifec\Modules\Tools\ProductWriter;
use Aifec\Modules\Tools\RowError;

/**
 * Productos, existencias y precios para el ERP. Todo se casa por SKU y nada se borra nunca.
 */
final class ProductsApi extends ApiBase
{
    private const MAX_BATCH = 500;

    private const MAX_CENTS = 2000000000;

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $limit = $this->limit($request);
        $after = $this->cursor($request);
        $ids = array_map('intval', $this->app->db()->column('SELECT id FROM {products} WHERE id > ? ORDER BY id ASC LIMIT ?', [$after, $limit + 1]));
        $present = $this->load(array_slice($ids, 0, $limit));

        return $this->page($request, $ids, $limit, $after, static function (int $id) use ($present): ?array {
            return isset($present[$id]) ? $present[$id] : null;
        });
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $sku = trim((string) $params['sku']);
        $db = $this->app->db();
        $row = $db->row('SELECT id FROM {products} WHERE sku = ? ORDER BY id', [$sku]);
        $variant = null;
        if ($row === null) {
            $variant = $db->row('SELECT product_id FROM {variants} WHERE sku = ? ORDER BY id', [$sku]);
            $row = $variant !== null ? ['id' => $variant['product_id']] : null;
        }
        if ($row === null) {
            throw ApiException::notFound('product_not_found', 'There is no product or variant with that SKU');
        }
        $present = $this->load([(int) $row['id']]);
        $product = $present[(int) $row['id']];
        if ($variant !== null) {
            $product['matched_variant_sku'] = $sku;
        }

        return $this->ok($product);
    }

    /** @param array<string,string> $params */
    public function put(Request $request, array $params): Response
    {
        $sku = trim((string) $params['sku']);
        if ($sku === '' || strlen($sku) > 64) {
            throw ApiException::invalid('The SKU must have between 1 and 64 characters', 'sku');
        }
        $body = $this->body($request);
        $db = $this->app->db();
        $parent = isset($body['parent_sku']) && is_string($body['parent_sku']) ? trim($body['parent_sku']) : '';
        $existing = $db->row('SELECT id, tax_class_id FROM {products} WHERE sku = ? ORDER BY id', [$sku]);
        if ($parent === '' && $existing === null && $db->row('SELECT id FROM {variants} WHERE sku = ?', [$sku]) !== null) {
            throw new ApiException(422, 'sku_is_variant', 'That SKU belongs to a variant: send parent_sku with the SKU of its product', 'sku');
        }
        $row = [
            'sku' => $sku, 'parent_sku' => $parent, 'name' => null, 'summary' => null, 'description' => null, 'meta_title' => null, 'meta_description' => null,
            'price' => null, 'compare_price' => null, 'sale_price' => null, 'stock' => null, 'weight' => null, 'status' => null, 'gtin' => null, 'mpn' => null,
            'brand' => null, 'tax_class' => null, 'categories' => null, 'images' => null, 'names' => [], 'attributes' => [],
        ];
        if (array_key_exists('name', $body)) {
            $row['name'] = $this->requireString($body['name'], 'name', 191);
        }
        $classId = $existing !== null && $existing['tax_class_id'] !== null ? (int) $existing['tax_class_id'] : null;
        if (array_key_exists('tax_class', $body)) {
            $code = $this->requireString($body['tax_class'], 'tax_class', 60);
            $found = $this->app->db()->tableExists('tax_classes') ? $db->row('SELECT id FROM {tax_classes} WHERE code = ?', [$code]) : null;
            if ($found === null) {
                throw ApiException::invalid('There is no tax class with the code ' . $code, 'tax_class');
            }
            $row['tax_class'] = $code;
            $classId = (int) $found['id'];
        }
        $includes = $this->writes()->includesTax($body);
        foreach (['price', 'compare_price', 'sale_price'] as $field) {
            $cents = $this->writes()->money($body, $field);
            $row[$field] = $cents === null ? null : $this->writes()->toStore($cents, $includes, $classId);
        }
        if (array_key_exists('weight', $body)) {
            $weight = $body['weight'];
            if (!is_int($weight) && !(is_string($weight) && preg_match('/^\d{1,9}$/', $weight) === 1) || (int) $weight < 0 || (int) $weight > 999999999) {
                throw ApiException::invalid('The weight must be a whole number of grams', 'weight');
            }
            $row['weight'] = (int) $weight;
        }
        if (array_key_exists('status', $body)) {
            $status = is_string($body['status']) ? strtolower(trim($body['status'])) : '';
            if (!in_array($status, ['draft', 'published', 'archived'], true)) {
                throw ApiException::invalid('The status must be draft, published or archived', 'status');
            }
            $row['status'] = $status;
        }
        foreach (['gtin' => 32, 'mpn' => 64] as $field => $max) {
            $value = array_key_exists($field, $body) ? $body[$field] : ($field === 'gtin' && array_key_exists('barcode', $body) ? $body['barcode'] : null);
            if ($value !== null) {
                $row[$field] = $this->requireString($value, $field, $max);
            }
        }
        $writer = new ProductWriter($this->app);
        try {
            $result = $writer->apply($row);
            $writer->finish();
        } catch (RowError $e) {
            throw ApiException::invalid($e->getMessage());
        }
        $owner = $db->row('SELECT id FROM {products} WHERE sku = ? ORDER BY id', [$parent !== '' ? $parent : $sku]);
        $this->activity($request, 'erp.product.' . $result['result'], 'product', $owner !== null ? (string) $owner['id'] : '', $sku);
        $present = $owner !== null ? $this->load([(int) $owner['id']]) : [];

        return $this->ok($owner !== null ? $present[(int) $owner['id']] : ['sku' => $sku, 'result' => $result['result']], $result['result'] === 'created' ? 201 : 200);
    }

    /** @param array<string,string> $params */
    public function stockIndex(Request $request, array $params): Response
    {
        $limit = $this->limit($request);
        $after = $this->cursor($request);
        $db = $this->app->db();
        $ids = array_map('intval', $db->column('SELECT id FROM {products} WHERE id > ? ORDER BY id ASC LIMIT ?', [$after, $limit + 1]));
        $more = count($ids) > $limit;
        $ids = array_slice($ids, 0, $limit);
        $data = [];
        foreach ($ids as $id) {
            $product = $db->row('SELECT id, sku, has_variants, manage_stock, backorders, stock FROM {products} WHERE id = ?', [$id]);
            if ($product === null) {
                continue;
            }
            if ((int) $product['has_variants'] === 1) {
                foreach ($db->select('SELECT id, sku, stock FROM {variants} WHERE product_id = ? ORDER BY position, id', [$id]) as $variant) {
                    $data[] = ['sku' => $variant['sku'] !== null ? (string) $variant['sku'] : null, 'product_id' => $id, 'variant_id' => (int) $variant['id'],
                        'stock' => (int) $variant['stock'], 'manage_stock' => (int) $product['manage_stock'] === 1, 'backorders' => (string) $product['backorders']];
                }
            } else {
                $data[] = ['sku' => $product['sku'] !== null ? (string) $product['sku'] : null, 'product_id' => $id, 'variant_id' => null,
                    'stock' => (int) $product['stock'], 'manage_stock' => (int) $product['manage_stock'] === 1, 'backorders' => (string) $product['backorders']];
            }
        }
        $last = $ids === [] ? $after : $ids[count($ids) - 1];

        return Response::json(['data' => $data, 'next_cursor' => $last > 0 ? self::encodeCursor($last) : null, 'has_more' => $more]);
    }

    /** @param array<string,string> $params */
    public function stock(Request $request, array $params): Response
    {
        $items = $this->items($request);
        $connection = $this->connection($request);
        $results = [];
        foreach ($items as $index => $line) {
            $results[] = ['index' => $index] + $this->writes()->stockLine($line, 'erp:' . (int) $connection['id']);
        }
        $done = count(array_filter($results, static function (array $r): bool {
            return $r['status'] === 'done';
        }));
        $this->activity($request, 'erp.stock', 'stock', '', $done . ' of ' . count($results) . ' lines applied');

        return $this->ok(['results' => $results, 'summary' => ['done' => $done, 'failed' => count($results) - $done]]);
    }

    /** @param array<string,string> $params */
    public function prices(Request $request, array $params): Response
    {
        $items = $this->items($request);
        $body = $this->body($request);
        $includes = $this->writes()->includesTax($body);
        $results = [];
        foreach ($items as $index => $line) {
            $results[] = ['index' => $index] + $this->writes()->priceLine($line, $includes);
        }
        $done = count(array_filter($results, static function (array $r): bool {
            return $r['status'] === 'done';
        }));
        $this->activity($request, 'erp.prices', 'product', '', $done . ' of ' . count($results) . ' lines applied');

        return $this->ok(['results' => $results, 'summary' => ['done' => $done, 'failed' => count($results) - $done]]);
    }

    // ------------------------------------------------------------------ lotes

    /**
     * @return array<int,mixed>
     */
    private function writes(): CatalogWrites
    {
        return new CatalogWrites($this->app);
    }

    private function items(Request $request): array
    {
        $body = $this->body($request);
        $items = array_is_list($body) ? $body : (isset($body['items']) ? $body['items'] : null);
        if (!is_array($items) || !array_is_list($items) || $items === []) {
            throw ApiException::invalid('Send a list of items (at least one)', 'items');
        }
        if (count($items) > self::MAX_BATCH) {
            throw new ApiException(422, 'batch_too_large', 'A batch has up to ' . self::MAX_BATCH . ' items', 'items');
        }

        return $items;
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>> id => producto presentado
     */
    private function load(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $db = $this->app->db();
        $args = [$this->app->defaultLang()];
        $in = $db->in($ids, $args);
        $products = $db->select('SELECT p.*, i.name AS i18n_name FROM {products} p LEFT JOIN {product_i18n} i ON i.product_id = p.id AND i.lang = ? WHERE p.id IN (' . $in . ')', $args);
        $variantArgs = [];
        $variants = [];
        foreach ($db->select('SELECT * FROM {variants} WHERE product_id IN (' . $db->in($ids, $variantArgs) . ') ORDER BY product_id, position, id', $variantArgs) as $variant) {
            $variants[(int) $variant['product_id']][] = $variant;
        }
        $codes = [];
        if ($db->tableExists('tax_classes')) {
            foreach ($db->select('SELECT id, code FROM {tax_classes}') as $class) {
                $codes[(int) $class['id']] = (string) $class['code'];
            }
        }
        $includes = $this->writes()->shopIncludesTax();
        $out = [];
        foreach ($products as $product) {
            $id = (int) $product['id'];
            $classId = $product['tax_class_id'] !== null ? (int) $product['tax_class_id'] : null;
            $rate = $this->app->has('tax') ? (int) $this->app->service('tax')->rate($classId, [])['rate'] : null;
            $list = [];
            foreach (isset($variants[$id]) ? $variants[$id] : [] as $v) {
                $list[] = [
                    'id' => (int) $v['id'], 'sku' => $v['sku'] !== null ? (string) $v['sku'] : null, 'gtin' => $v['gtin'] !== null ? (string) $v['gtin'] : null,
                    'label' => $v['label'] !== null ? (string) $v['label'] : null, 'stock' => (int) $v['stock'], 'weight' => $v['weight'] !== null ? (int) $v['weight'] : null,
                    'active' => (int) $v['active'] === 1,
                ] + Present::amounts($v, ['price', 'compare_price']);
            }
            $out[$id] = [
                'id' => $id, 'sku' => $product['sku'] !== null ? (string) $product['sku'] : null, 'kind' => (string) $product['kind'],
                'name' => $product['i18n_name'] !== null ? (string) $product['i18n_name'] : null, 'status' => (string) $product['status'],
                'gtin' => $product['gtin'] !== null ? (string) $product['gtin'] : null, 'mpn' => $product['mpn'] !== null ? (string) $product['mpn'] : null,
                'has_variants' => (int) $product['has_variants'] === 1, 'tax_class' => $classId !== null && isset($codes[$classId]) ? $codes[$classId] : null,
                'tax_rate' => $rate, 'tax_rate_percent' => $rate !== null ? Money::decimal($rate) : null, 'prices_include_tax' => $includes,
                'sale_from' => Present::iso($product['sale_from'] !== null ? (string) $product['sale_from'] : null),
                'sale_to' => Present::iso($product['sale_to'] !== null ? (string) $product['sale_to'] : null),
                'manage_stock' => (int) $product['manage_stock'] === 1, 'stock' => (int) $product['stock'], 'backorders' => (string) $product['backorders'],
                'weight' => (int) $product['weight'], 'updated_at' => Present::iso((string) $product['updated_at']), 'variants' => $list,
            ] + Present::amounts($product, ['price', 'compare_price', 'sale_price']);
        }

        return $out;
    }
}
