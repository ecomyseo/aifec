<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Writers;

use Aifec\Http\HttpRequest;
use Aifec\Modules\Catalog\Admin\CategoryAdmin;
use Aifec\Modules\Catalog\Admin\TaxonomySlugs;
use Aifec\Modules\Catalog\Admin\VariantAdmin;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;

/**
 * Los productos del origen: textos por idioma, precios, impuestos, marca, categorías, existencias, imágenes, variantes, datos, etiquetas,
 * ficheros, relacionados y direcciones antiguas. Se escribe como lo guarda el panel (ProductSaver) y se invalidan las mismas etiquetas de
 * caché («product:<id>» y «catalog»).
 *
 * Los precios se pasan a como se escriben aquí (WriterBase::price). Nada de existencias ni precios negativos: se dejan en 0 y se apunta.
 * Las existencias se apuntan además como movimientos («import»). Una variante que ya no viene se desactiva, no se borra. Los relacionados
 * se ponen en la segunda pasada (link()), cuando ya están todos los productos.
 */
final class ProductsWriter extends WriterBase
{
    /** Tamaño máximo de un fichero de producto digital que se baja de una dirección. */
    private const MAX_FILE_BYTES = 52428800;

    private const VISIBILITY = ['visible' => 'everywhere', 'catalog' => 'catalog', 'search' => 'search', 'hidden' => 'hidden'];

    private const RELATIONS = ['related_ids' => 'related', 'upsell_ids' => 'upsell', 'cross_sell_ids' => 'cross'];

    public function type(): string
    {
        return 'products';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $found = $this->resolve('products', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('products', ['id' => $localId]);
        }, function () use ($record, $id): ?array {
            return $this->bySku($record, $id);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($action === self::CONFLICT) {
            return $this->conflict((int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        $names = $this->texts(isset($record['name']) && is_array($record['name']) ? $record['name'] : [], $ctx);
        if ($names === []) {
            return $this->result(self::SKIPPED, null, 'The product has no name');
        }
        $now = $this->now();
        $classId = $this->taxClass($record, $ctx);
        $includes = $record['price_includes_tax'] === true;
        $variants = $this->resolveVariants($record, $ctx);
        $hasVariants = $variants !== [];
        $manage = isset($record['manage_stock']) ? $record['manage_stock'] === true : true;
        $stock = $this->nonNegative(isset($record['stock']) && is_int($record['stock']) ? $record['stock'] : 0, 'stock', $ctx);
        $status = (string) $record['status'];
        if ($status === 'private') {
            $ctx->unmapped('feature', 'Private products', 1, 'Brought as drafts');
        }
        $this->noteStock($record, $ctx);
        $data = [
            'kind' => (string) $record['kind'], 'sku' => $this->nullable($record, 'sku', 64), 'gtin' => $this->nullable($record, 'gtin', 32), 'mpn' => $this->nullable($record, 'mpn', 64),
            'brand_id' => $this->brand($record, $ctx), 'tax_class_id' => $classId,
            'price' => $this->cents($record['price'], $includes, $classId, $ctx, $this->micros($record, 'price_micros')),
            'compare_price' => $this->cents(isset($record['compare_price']) ? $record['compare_price'] : null, $includes, $classId, $ctx, $this->micros($record, 'compare_price_micros')),
            'sale_price' => $this->cents(isset($record['sale_price']) ? $record['sale_price'] : null, $includes, $classId, $ctx, $this->micros($record, 'sale_price_micros')),
            'sale_from' => $this->sqlDate(isset($record['sale_from']) ? $record['sale_from'] : null), 'sale_to' => $this->sqlDate(isset($record['sale_to']) ? $record['sale_to'] : null),
            'cost' => $this->cents(isset($record['cost']) ? $record['cost'] : null, $includes, $classId, $ctx),
            'manage_stock' => $manage ? 1 : 0, 'stock' => $stock,
            'backorders' => isset($record['backorders']) && in_array($record['backorders'], ['no', 'notify', 'yes'], true) ? (string) $record['backorders'] : 'no',
            'weight' => $this->whole($record, 'weight'), 'length' => $this->whole($record, 'length'), 'width' => $this->whole($record, 'width'), 'height' => $this->whole($record, 'height'),
            'min_qty' => max(1, isset($record['min_qty']) && is_int($record['min_qty']) ? $record['min_qty'] : 1),
            'max_qty' => isset($record['max_qty']) && is_int($record['max_qty']) ? max(0, $record['max_qty']) : 0,
            'status' => $status === 'published' ? 'published' : 'draft',
            'visibility' => isset($record['visibility'], self::VISIBILITY[$record['visibility']]) ? self::VISIBILITY[$record['visibility']] : 'everywhere',
            'featured' => isset($record['featured']) && $record['featured'] === true ? 1 : 0,
            'extra' => $this->extra($record, $row),
            'updated_at' => $now,
        ];
        if ($data['sale_price'] === null && isset($record['sale_reduction']) && is_array($record['sale_reduction'])) {
            $data['sale_price'] = $this->reducedPrice($data['price'], $record['sale_reduction'], $classId, $ctx);
        }
        $published = $this->sqlDate(isset($record['published_at']) ? $record['published_at'] : null);
        if ($data['status'] === 'published') {
            $data['published_at'] = $published !== null ? $published : ($row !== null && $row['published_at'] !== null ? (string) $row['published_at'] : $now);
        } elseif ($published !== null) {
            $data['published_at'] = $published;
        }
        if ($row === null) {
            $created = $this->sqlDate(isset($record['created_at']) ? $record['created_at'] : null);
            $localId = $db->insert('products', $data + ['has_variants' => 0, 'created_at' => $created !== null ? $created : $now]);
        } else {
            $localId = (int) $row['id'];
            $db->update('products', $data, ['id' => $localId]);
        }
        $this->saveTexts($localId, $record, $names, $ctx);
        $this->saveCategories($localId, $record, $ctx);
        $this->saveTags($localId, $record, $ctx);
        $byIndex = $this->saveImages($localId, $record, $ctx, $id);
        $this->saveAttributes($localId, $record, $ctx);
        $this->saveVariants($localId, $variants, $record, $byIndex, $includes, $classId, $manage, $ctx);
        if (!$hasVariants && $manage) {
            $this->stockMove($localId, 0, $row !== null ? (int) $row['stock'] : 0, $stock);
        }
        $this->saveFiles($localId, $record, $ctx);
        $this->saveAddresses($localId, $record, $names, $ctx);
        $ctx->remember('products', $id, $localId, Neutral::hash($record));
        VariantAdmin::refresh($this->app, $localId);
        CategoryAdmin::recount($this->app);
        $this->app->invalidate(['product:' . $localId, 'catalog']);

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * Segunda pasada: los productos relacionados, de venta cruzada y de mejora, cuando ya existen todos.
     */
    public function link(array $record, WriteContext $ctx): void
    {
        $localId = $ctx->localId('products', (string) $record['id']);
        if ($localId === null || $ctx->dryRun()) {
            return;
        }
        $db = $this->db();
        $missing = 0;
        $db->transaction(function () use ($db, $localId, $record, $ctx, &$missing): void {
            foreach (self::RELATIONS as $field => $kind) {
                if (!isset($record[$field]) || !is_array($record[$field])) {
                    continue;
                }
                $ids = [];
                foreach ($record[$field] as $sourceId) {
                    $other = is_scalar($sourceId) ? $ctx->localId('products', (string) $sourceId) : null;
                    if ($other === null || !$this->exists('products', ['id' => $other])) {
                        ++$missing;
                        continue;
                    }
                    if ($other !== $localId) {
                        $ids[$other] = $other;
                    }
                }
                $db->delete('product_relations', ['product_id' => $localId, 'kind' => $kind]);
                foreach (array_slice(array_values($ids), 0, 50) as $position => $other) {
                    $db->insert('product_relations', ['product_id' => $localId, 'related_id' => $other, 'kind' => $kind, 'position' => $position]);
                }
            }
        });
        if ($missing > 0) {
            $ctx->note('products', (string) $record['id'], 'note', 'Some related products were not brought and were left out');
        }
        $this->app->invalidate(['product:' . $localId]);
    }

    public function checksums(WriteContext $ctx): array
    {
        $db = $this->db();
        $stock = (int) $db->value(
            "SELECT COALESCE(SUM(CASE WHEN p.has_variants = 1 THEN (SELECT COALESCE(SUM(v.stock), 0) FROM {variants} v WHERE v.product_id = p.id AND v.active = 1) ELSE p.stock END), 0)"
            . " FROM {import_map} m JOIN {products} p ON p.id = m.local_id WHERE m.type = 'products' AND p.manage_stock = 1"
        );
        // Existencias compartidas por las variaciones en el origen: aquí cada variante tiene la cifra entera, pero el origen las contaba UNA vez.
        foreach ($db->select(
            "SELECT p.extra FROM {import_map} m JOIN {products} p ON p.id = m.local_id WHERE m.type = 'products' AND p.manage_stock = 1 AND p.has_variants = 1 AND p.extra LIKE ?",
            ['%shared_stock%']
        ) as $row) {
            $decoded = json_decode((string) $row['extra'], true);
            $shared = is_array($decoded) && isset($decoded['import']['shared_stock']) && is_array($decoded['import']['shared_stock']) ? $decoded['import']['shared_stock'] : [];
            if (isset($shared['quantity'], $shared['variants'])) {
                $stock += (int) $shared['quantity'] * (1 - (int) $shared['variants']);
            }
        }

        return [
            'products_count' => $this->mappedCount('products', 'products'),
            'variants_count' => $this->mappedCount('variants', 'variants'),
            'stock_total' => $stock,
        ];
    }

    // ------------------------------------------------------------------ casar y datos sueltos

    /**
     * El producto de aquí con el mismo sku, si es único y no es ya pareja de otro.
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>|null
     */
    private function bySku(array $record, string $id): ?array
    {
        $sku = isset($record['sku']) && is_string($record['sku']) ? trim($record['sku']) : '';
        if ($sku === '') {
            return null;
        }
        $rows = $this->db()->select('SELECT * FROM {products} WHERE sku = ? ORDER BY id', [$sku]);

        return count($rows) === 1 && !$this->claimed('products', (int) $rows[0]['id'], $id) ? $rows[0] : null;
    }

    /**
     * La clase de impuesto del producto: por la pareja guardada o por la equivalencia que eligió el comerciante.
     *
     * @param array<string,mixed> $record
     */
    private function taxClass(array $record, WriteContext $ctx): ?int
    {
        if (!isset($record['tax_class_id']) || !is_string($record['tax_class_id']) || $record['tax_class_id'] === '') {
            return null;
        }
        $local = $ctx->localId('tax_classes', $record['tax_class_id']);
        if ($local === null) {
            $code = $ctx->equivalent('tax_class', $record['tax_class_id']);
            $found = $code !== null ? $this->db()->value('SELECT id FROM {tax_classes} WHERE code = ?', [$code]) : null;
            $local = $found !== null ? (int) $found : null;
        }
        if ($local === null) {
            $this->noteOnce($ctx, 'tax-class', 'The tax class of the product was not brought: it uses the default one');
        }

        return $local;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function brand(array $record, WriteContext $ctx): ?int
    {
        if (!isset($record['brand_id']) || !is_string($record['brand_id']) || $record['brand_id'] === '') {
            return null;
        }
        $local = $ctx->localId('brands', $record['brand_id']);
        if ($local === null || !$this->exists('brands', ['id' => $local])) {
            $this->noteOnce($ctx, 'brand', 'The brand of the product was not brought');

            return null;
        }

        return $local;
    }

    /**
     * Lo que el control de existencias del origen no puede ser igual aquí: se apunta (una vez por producto).
     *
     * @param array<string,mixed> $record
     */
    private function noteStock(array $record, WriteContext $ctx): void
    {
        $extra = isset($record['extra']) && is_array($record['extra']) ? $record['extra'] : [];
        if (isset($extra['shared_stock'])) {
            $this->noteOnce($ctx, 'shared-stock', 'The stock was shared by all the variations in the source: here every variation has its own, so each one got the whole quantity');
        }
        if (isset($extra['variants_without_stock_control'])) {
            $this->noteOnce($ctx, 'variants-no-stock', 'Some variations had no stock control in the source and have no stock here: here the stock is controlled for the whole product');
        }
    }

    /**
     * Un importe del origen a como se escriben los precios aquí; null se queda en null; uno negativo, en 0.
     *
     * @param mixed $value
     */
    private function cents($value, bool $includes, ?int $classId, WriteContext $ctx, ?int $micros = null): ?int
    {
        if (!is_int($value)) {
            return null;
        }
        $cents = $this->nonNegative($value, 'price', $ctx);

        return $this->price($cents, $includes, $classId, $ctx, $this->sureMicros($micros, $cents));
    }

    /**
     * Las millonésimas que acompañan a un importe del registro (el campo hermano «<campo>_micros»), o null si no vienen.
     *
     * @param array<string,mixed> $record
     */
    private function micros(array $record, string $key): ?int
    {
        return isset($record[$key]) && is_int($record[$key]) ? $record[$key] : null;
    }

    /**
     * El precio rebajado de una rebaja por importe (sale_reduction): el precio del producto como se escribe aquí menos la rebaja como se
     * escribe aquí (la misma conversión de impuestos que un precio). Si no deja un precio por debajo del del producto y por encima de 0,
     * no hay rebaja y se apunta.
     *
     * @param array<string,mixed> $reduction
     */
    private function reducedPrice(?int $price, array $reduction, ?int $classId, WriteContext $ctx): ?int
    {
        if ($price === null || !isset($reduction['amount']) || !is_int($reduction['amount']) || $reduction['amount'] <= 0) {
            return null;
        }
        $amount = $this->price(
            $reduction['amount'],
            isset($reduction['includes_tax']) && $reduction['includes_tax'] === true,
            $classId,
            $ctx,
            $this->sureMicros(isset($reduction['amount_micros']) ? $reduction['amount_micros'] : null, $reduction['amount'])
        );
        $sale = $price - $amount;
        if ($sale <= 0 || $sale >= $price) {
            $this->noteOnce($ctx, 'sale-reduction', 'The reduction does not leave a price below the product price: no sale was brought');

            return null;
        }

        return $sale;
    }

    private function nonNegative(int $value, string $what, WriteContext $ctx): int
    {
        if ($value < 0) {
            $this->noteOnce($ctx, 'negative-' . $what, 'A negative ' . $what . ' was left at 0');

            return 0;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function nullable(array $record, string $field, int $max): ?string
    {
        // La anchura de la columna la pone FittedDb al escribir ($max es la de siempre, solo orienta a quien lee).
        $text = isset($record[$field]) && is_string($record[$field]) ? trim($record[$field]) : '';

        return $text !== '' ? $text : null;
    }

    /**
     * @param array<string,mixed> $record
     */
    private function whole(array $record, string $field): int
    {
        return isset($record[$field]) && is_int($record[$field]) ? max(0, $record[$field]) : 0;
    }

    /**
     * Lo que no tiene columna se guarda en products.extra bajo la clave «import» (sin secretos), junto a lo que ya hubiera.
     *
     * @param array<string,mixed>      $record
     * @param array<string,mixed>|null $row
     */
    private function extra(array $record, ?array $row): ?string
    {
        $all = [];
        if ($row !== null && is_string($row['extra']) && $row['extra'] !== '') {
            $decoded = json_decode($row['extra'], true);
            $all = is_array($decoded) ? $decoded : [];
        }
        $clean = Neutral::redact('products', $record);
        $import = isset($clean['extra']) && is_array($clean['extra']) ? $clean['extra'] : [];
        if (isset($record['reviews_allowed']) && is_bool($record['reviews_allowed'])) {
            $import['reviews_allowed'] = $record['reviews_allowed'];
        }
        if ($import !== []) {
            $all['import'] = $import;
        }

        return $all !== [] ? (string) json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    }

    // ------------------------------------------------------------------ textos, direcciones, categorías y etiquetas

    /**
     * @param array<string,mixed>  $record
     * @param array<string,string> $names
     */
    private function saveTexts(int $productId, array $record, array $names, WriteContext $ctx): void
    {
        $db = $this->db();
        $summary = $this->texts(isset($record['summary']) && is_array($record['summary']) ? $record['summary'] : [], $ctx);
        $description = $this->texts(isset($record['description']) && is_array($record['description']) ? $record['description'] : [], $ctx);
        $titles = $this->texts(isset($record['meta_title']) && is_array($record['meta_title']) ? $record['meta_title'] : [], $ctx);
        $metas = $this->texts(isset($record['meta_description']) && is_array($record['meta_description']) ? $record['meta_description'] : [], $ctx);
        foreach ($names as $lang => $name) {
            $text = [
                'name' => trim($name),
                'summary' => isset($summary[$lang]) ? $this->html($summary[$lang]) : null,
                'description' => isset($description[$lang]) ? $this->html($description[$lang]) : null,
                'meta_title' => isset($titles[$lang]) ? trim($titles[$lang]) : null,
                'meta_description' => isset($metas[$lang]) ? trim($metas[$lang]) : null,
            ];
            if ($db->find('product_i18n', ['product_id' => $productId, 'lang' => $lang]) === null) {
                $db->insert('product_i18n', $text + ['product_id' => $productId, 'lang' => $lang]);
            } else {
                $db->update('product_i18n', $text, ['product_id' => $productId, 'lang' => $lang]);
            }
        }
    }

    /**
     * La dirección amigable de cada idioma y las antiguas (con keep_urls, siguen valiendo; si no, redirigen).
     *
     * @param array<string,mixed>  $record
     * @param array<string,string> $names
     */
    private function saveAddresses(int $productId, array $record, array $names, WriteContext $ctx): void
    {
        $prefix = TaxonomySlugs::prefix($this->app, 'product');
        $slugs = $this->texts(isset($record['slug']) && is_array($record['slug']) ? $record['slug'] : [], $ctx);
        foreach ($names as $lang => $name) {
            $segment = TaxonomySlugs::make(isset($slugs[$lang]) && $slugs[$lang] !== '' ? $slugs[$lang] : $name, 'product-' . $productId);
            $this->app->slugs()->set('product', $productId, $lang, $prefix . '/' . $segment);
        }
        $paths = [];
        foreach (isset($record['old_paths']) && is_array($record['old_paths']) ? $record['old_paths'] : [] as $path) {
            if (is_string($path)) {
                $paths[] = $path;
            }
        }
        $main = $this->app->defaultLang();
        $ctx->oldPaths('product', $productId, $paths, isset($names[$main]) ? $main : '');
    }

    /**
     * @param array<string,mixed> $record
     */
    private function saveCategories(int $productId, array $record, WriteContext $ctx): void
    {
        $db = $this->db();
        $ids = [];
        $missing = false;
        foreach (isset($record['category_ids']) && is_array($record['category_ids']) ? $record['category_ids'] : [] as $sourceId) {
            $local = is_scalar($sourceId) ? $ctx->localId('categories', (string) $sourceId) : null;
            if ($local === null || !$this->exists('categories', ['id' => $local])) {
                $missing = true;
                continue;
            }
            $ids[$local] = $local;
        }
        $mainSource = isset($record['main_category_id']) && is_string($record['main_category_id']) ? $record['main_category_id'] : '';
        $main = $mainSource !== '' ? $ctx->localId('categories', $mainSource) : null;
        if ($missing || ($mainSource !== '' && ($main === null || !isset($ids[$main])))) {
            $this->noteOnce($ctx, 'categories', 'Some categories of the product were not brought and were left out');
        }
        $ids = array_values($ids);
        if ($main === null || !in_array($main, $ids, true)) {
            $main = $ids !== [] ? $ids[0] : 0;
        }
        $db->delete('product_categories', ['product_id' => $productId]);
        foreach ($ids as $position => $categoryId) {
            $db->insert('product_categories', ['product_id' => $productId, 'category_id' => $categoryId, 'is_main' => $categoryId === $main ? 1 : 0, 'position' => $position]);
        }
    }

    /**
     * @param array<string,mixed> $record
     */
    private function saveTags(int $productId, array $record, WriteContext $ctx): void
    {
        $db = $this->db();
        $db->delete('product_tags', ['product_id' => $productId]);
        foreach (isset($record['tags']) && is_array($record['tags']) ? $record['tags'] : [] as $lang => $list) {
            $short = $this->siteLanguage((string) $lang, $ctx);
            if ($short === null) {
                continue;
            }
            foreach (is_array($list) ? $list : [] as $tag) {
                $name = is_string($tag) ? $db->fitText('tags', 'name', trim($tag)) : '';
                if ($name === '') {
                    continue;
                }
                $tagId = $db->value('SELECT id FROM {tags} WHERE lang = ? AND name = ?', [$short, $name]);
                if ($tagId === null) {
                    $tagId = $db->insert('tags', ['lang' => $short, 'name' => $name]);
                }
                $db->insertIgnore('product_tags', ['product_id' => $productId, 'tag_id' => (int) $tagId]);
            }
        }
    }

    // ------------------------------------------------------------------ imágenes

    /**
     * Las imágenes en su orden, con la de portada primero. Devuelve el identificador de medios de cada una por su posición en la lista
     * del registro (las variantes se refieren a ella por ese número).
     *
     * @param array<string,mixed> $record
     *
     * @return array<int,int>
     */
    private function saveImages(int $productId, array $record, WriteContext $ctx, string $sourceId): array
    {
        $db = $this->db();
        $list = isset($record['images']) && is_array($record['images']) ? array_values($record['images']) : [];
        $order = [];
        foreach ($list as $i => $image) {
            if (is_array($image)) {
                $order[] = ['i' => $i, 'rank' => [isset($image['cover']) && $image['cover'] === true ? 0 : 1, isset($image['position']) && is_int($image['position']) ? $image['position'] : $i, $i]];
            }
        }
        usort($order, static function (array $a, array $b): int {
            return $a['rank'] <=> $b['rank'];
        });
        $byIndex = [];
        $ids = [];
        $failed = false;
        foreach ($order as $item) {
            $mediaId = $ctx->image($list[$item['i']], 'products', $sourceId);
            if ($mediaId === null) {
                $failed = true;
                continue;
            }
            $byIndex[$item['i']] = $mediaId;
            if (!in_array($mediaId, $ids, true)) {
                $ids[] = $mediaId;
            }
        }
        if ($list === []) {
            $db->run('DELETE FROM {product_media} WHERE product_id = ? AND variant_id IS NULL', [$productId]);
            $db->update('products', ['main_media_id' => null], ['id' => $productId]);

            return [];
        }
        if ($ids === []) {
            if ((bool) $ctx->option('import_images', true)) {
                $this->noteOnce($ctx, 'images', 'The images of the product could not be brought');
            }

            return [];
        }
        if ($failed) {
            $this->noteOnce($ctx, 'images', 'Some images of the product could not be brought');
        }
        $db->run('DELETE FROM {product_media} WHERE product_id = ? AND variant_id IS NULL', [$productId]);
        foreach ($ids as $position => $mediaId) {
            $db->insertIgnore('product_media', ['product_id' => $productId, 'media_id' => $mediaId, 'variant_id' => null, 'position' => $position]);
        }
        $db->update('products', ['main_media_id' => $ids[0]], ['id' => $productId]);

        return $byIndex;
    }

    // ------------------------------------------------------------------ datos

    /**
     * Los datos del producto (material, medidas…): valores de una lista, texto por idioma o número.
     *
     * @param array<string,mixed> $record
     */
    private function saveAttributes(int $productId, array $record, WriteContext $ctx): void
    {
        $db = $this->db();
        $db->delete('product_attributes', ['product_id' => $productId]);
        foreach (isset($record['attributes']) && is_array($record['attributes']) ? $record['attributes'] : [] as $attr) {
            if (!is_array($attr) || !isset($attr['attribute_id']) || !is_string($attr['attribute_id'])) {
                continue;
            }
            $local = $ctx->localId('attributes', $attr['attribute_id']);
            $def = $local !== null ? $db->find('attributes', ['id' => $local]) : null;
            if ($def === null) {
                $this->noteOnce($ctx, 'attribute', 'Some data of the product point to an attribute that was not brought and were left out');
                continue;
            }
            $base = ['product_id' => $productId, 'attribute_id' => (int) $def['id']];
            $type = (string) $def['type'];
            if (in_array($type, ['select', 'color', 'multiselect'], true)) {
                foreach (isset($attr['value_ids']) && is_array($attr['value_ids']) ? $attr['value_ids'] : [] as $valueSource) {
                    $valueId = is_scalar($valueSource) ? $ctx->localId('attribute_values', self::valueKey($attr['attribute_id'], (string) $valueSource)) : null;
                    if ($valueId === null) {
                        $this->noteOnce($ctx, 'value', 'Some values of the data of the product were not brought and were left out');
                        continue;
                    }
                    $db->insert('product_attributes', $base + ['value_id' => $valueId]);
                }
            } elseif ($type === 'number' || $type === 'bool') {
                if (isset($attr['number']) && (is_int($attr['number']) || is_float($attr['number']))) {
                    $db->insert('product_attributes', $base + ['value_number' => $type === 'bool' ? ($attr['number'] != 0 ? 1 : 0) : (string) $attr['number']]);
                }
            } else {
                foreach ($this->texts(isset($attr['text']) && is_array($attr['text']) ? $attr['text'] : [], $ctx) as $lang => $text) {
                    $db->insert('product_attributes', $base + ['lang' => $lang, 'value_text' => $this->cut($text, 1000)]);
                }
            }
        }
    }

    // ------------------------------------------------------------------ variantes

    /**
     * Las variantes de las que se conocen todos los valores de atributo (las demás se dejan fuera y se apunta).
     *
     * @param array<string,mixed> $record
     *
     * @return array<int,array{variant:array<string,mixed>,values:array<int,array{0:int,1:int}>}>
     */
    private function resolveVariants(array $record, WriteContext $ctx): array
    {
        $out = [];
        $db = $this->db();
        foreach (isset($record['variants']) && is_array($record['variants']) ? $record['variants'] : [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $values = [];
            $ok = isset($variant['values']) && is_array($variant['values']) && $variant['values'] !== [];
            foreach ($ok ? $variant['values'] : [] as $pair) {
                $attrSource = is_array($pair) && isset($pair['attribute_id']) && is_scalar($pair['attribute_id']) ? (string) $pair['attribute_id'] : '';
                $valueSource = is_array($pair) && isset($pair['value_id']) && is_scalar($pair['value_id']) ? (string) $pair['value_id'] : '';
                $attrId = $attrSource !== '' ? $ctx->localId('attributes', $attrSource) : null;
                $valueId = $attrId !== null && $valueSource !== '' ? $ctx->localId('attribute_values', self::valueKey($attrSource, $valueSource)) : null;
                if ($attrId === null || $valueId === null || !$this->exists('attribute_values', ['id' => $valueId, 'attribute_id' => $attrId])) {
                    $ok = false;
                    break;
                }
                $values[$attrId] = [$attrId, $valueId];
            }
            if ($ok) {
                $out[] = ['variant' => $variant, 'values' => array_values($values)];
            }
        }
        $total = isset($record['variants']) && is_array($record['variants']) ? count($record['variants']) : 0;
        if ($total > count($out)) {
            $this->noteOnce($ctx, 'variants', $out === [] ? 'The variants have attributes that were not brought: the product was brought as a simple one' : 'Some variants have attributes that were not brought and were left out');
        }

        return $out;
    }

    /**
     * Escribe las variantes (casadas por su identificador de origen y, la primera vez, por el sku) y desactiva las que ya no vienen.
     *
     * @param array<int,array{variant:array<string,mixed>,values:array<int,array{0:int,1:int}>}> $variants
     * @param array<string,mixed>                                                                $record
     * @param array<int,int>                                                                     $byIndex
     */
    private function saveVariants(int $productId, array $variants, array $record, array $byIndex, bool $includes, ?int $classId, bool $manage, WriteContext $ctx): void
    {
        $db = $this->db();
        $now = $this->now();
        $inProduct = array_flip(array_map('intval', $db->column('SELECT media_id FROM {product_media} WHERE product_id = ?', [$productId])));
        $present = [];
        foreach (isset($record['variants']) && is_array($record['variants']) ? $record['variants'] : [] as $variant) {
            if (is_array($variant) && isset($variant['id'])) {
                $present[(string) $variant['id']] = true;
            }
        }
        foreach ($variants as $item) {
            $variant = $item['variant'];
            $source = (string) $variant['id'];
            $mapped = $ctx->localId('variants', $source);
            $row = $mapped !== null ? $db->row('SELECT * FROM {variants} WHERE id = ? AND product_id = ?', [$mapped, $productId]) : null;
            $sku = $this->nullable($variant, 'sku', 64);
            if ($row === null && $sku !== null) {
                foreach ($db->select('SELECT * FROM {variants} WHERE product_id = ? AND sku = ? ORDER BY id', [$productId, $sku]) as $candidate) {
                    if (!$this->claimed('variants', (int) $candidate['id'], $source)) {
                        $row = $candidate;
                        break;
                    }
                }
            }
            $stock = $this->nonNegative(isset($variant['stock']) && is_int($variant['stock']) ? $variant['stock'] : 0, 'stock', $ctx);
            $media = isset($variant['image_index']) && is_int($variant['image_index']) && isset($byIndex[$variant['image_index']]) && isset($inProduct[$byIndex[$variant['image_index']]])
                ? $byIndex[$variant['image_index']] : null;
            $weight = isset($variant['weight']) && is_int($variant['weight']) && $variant['weight'] > 0 ? $variant['weight'] : null;
            $data = [
                'sku' => $sku, 'gtin' => $this->nullable($variant, 'gtin', 32), 'price' => $this->cents($variant['price'], $includes, $classId, $ctx, $this->micros($variant, 'price_micros')),
                'compare_price' => $this->cents(isset($variant['compare_price']) ? $variant['compare_price'] : null, $includes, $classId, $ctx, $this->micros($variant, 'compare_price_micros')),
                'cost' => $this->cents(isset($variant['cost']) ? $variant['cost'] : null, $includes, $classId, $ctx),
                'stock' => $stock, 'weight' => $weight, 'media_id' => $media, 'active' => isset($variant['active']) && $variant['active'] === false ? 0 : 1,
                'position' => isset($variant['position']) ? (int) $variant['position'] : 0, 'updated_at' => $now,
            ];
            if ($row === null) {
                $variantId = $db->insert('variants', $data + ['product_id' => $productId, 'label' => null, 'created_at' => $now]);
                $before = 0;
            } else {
                $variantId = (int) $row['id'];
                $before = (int) $row['stock'];
                $db->update('variants', $data, ['id' => $variantId]);
            }
            $db->delete('variant_values', ['variant_id' => $variantId]);
            foreach ($item['values'] as $pair) {
                $db->insert('variant_values', ['variant_id' => $variantId, 'attribute_id' => $pair[0], 'value_id' => $pair[1]]);
            }
            if ($manage) {
                $this->stockMove($productId, $variantId, $before, $stock);
            }
            $ctx->remember('variants', $source, $variantId, Neutral::hash($variant));
        }
        $this->deactivateGone($productId, $present);
    }

    /**
     * Las variantes que antes vinieron del origen y ya no vienen se desactivan (no se borran: pueden estar en pedidos).
     *
     * @param array<string,bool> $present
     */
    private function deactivateGone(int $productId, array $present): void
    {
        $db = $this->db();
        $rows = $db->select(
            "SELECT v.id, m.source_id FROM {variants} v JOIN {import_map} m ON m.type = 'variants' AND m.local_id = v.id WHERE v.product_id = ? AND v.active = 1",
            [$productId]
        );
        foreach ($rows as $row) {
            if (!isset($present[(string) $row['source_id']])) {
                $db->update('variants', ['active' => 0, 'updated_at' => $this->now()], ['id' => (int) $row['id']]);
            }
        }
    }

    /**
     * Apunta el cambio de existencias (sin disparar ningún aviso: lo importado es histórico).
     */
    private function stockMove(int $productId, int $variantId, int $before, int $after): void
    {
        if ($before === $after) {
            return;
        }
        $this->db()->insert('stock_moves', [
            'product_id' => $productId, 'variant_id' => $variantId, 'delta' => $after - $before, 'stock_after' => $after, 'reason' => 'import',
            'reference' => null, 'created_at' => $this->now(),
        ]);
    }

    // ------------------------------------------------------------------ ficheros de un producto digital

    /**
     * Los ficheros que se entregan al comprar un producto digital. Solo se bajan los que tienen dirección; el que no se pueda bajar se apunta
     * y el producto entra igual.
     *
     * @param array<string,mixed> $record
     */
    private function saveFiles(int $productId, array $record, WriteContext $ctx): void
    {
        $files = isset($record['files']) && is_array($record['files']) ? $record['files'] : [];
        if ($files === []) {
            return;
        }
        $db = $this->db();
        $position = (int) $db->value('SELECT COALESCE(MAX(position), -1) + 1 FROM {product_files} WHERE product_id = ?', [$productId]);
        foreach ($files as $file) {
            if (!is_array($file)) {
                continue;
            }
            $name = $this->cut(isset($file['name']) && is_string($file['name']) ? $file['name'] : 'file', 191);
            if ($this->exists('product_files', ['product_id' => $productId, 'name' => $name])) {
                continue;
            }
            $stored = $this->download(isset($file['url']) && is_string($file['url']) ? $file['url'] : '', $name);
            if ($stored === null) {
                $ctx->note('products', (string) $record['id'], 'note', 'The file «' . $name . '» of the product could not be brought');
                continue;
            }
            $version = isset($file['version']) && is_string($file['version']) ? $this->cut($file['version'], 40) : '';
            $db->insert('product_files', [
                'product_id' => $productId, 'variant_id' => null, 'name' => $name, 'path' => (string) $stored['path'], 'bytes' => (int) $stored['bytes'],
                'version' => $version !== '' ? $version : null, 'position' => $position++, 'created_at' => $this->now(),
            ]);
        }
    }

    /**
     * Baja un fichero de una dirección http(s) con el cliente HTTP de la aplicación y lo guarda en la carpeta privada.
     *
     * @return array<string,mixed>|null null si no se pudo
     */
    private function download(string $url, string $name): ?array
    {
        $parts = parse_url(trim($url));
        $scheme = is_array($parts) && isset($parts['scheme']) ? strtolower((string) $parts['scheme']) : '';
        $host = is_array($parts) && isset($parts['host']) ? strtolower(trim((string) $parts['host'], '[]')) : '';
        if (($scheme !== 'http' && $scheme !== 'https') || $host === '' || $this->internalHost($host) || !$this->app->has('files')) {
            return null;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'aifec');
        if ($tmp === false) {
            return null;
        }

        try {
            $response = $this->app->http()->send(HttpRequest::get(trim($url), [], 60.0));
            if (!$response->ok() || $response->body === '' || strlen($response->body) > self::MAX_FILE_BYTES || file_put_contents($tmp, $response->body) === false) {
                return null;
            }

            return $this->app->service('files')->store($tmp, $name, 'products');
        } catch (\Throwable $e) {
            return null;
        } finally {
            if (is_file($tmp)) {
                unlink($tmp);
            }
        }
    }

    /** ¿Es una dirección de la propia máquina o de una red privada? (No se baja nada de ahí.) */
    private function internalHost(string $host): bool
    {
        if ($host === 'localhost' || substr($host, -6) === '.local' || substr($host, -9) === '.internal') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
