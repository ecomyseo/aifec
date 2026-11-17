<?php
/**
 * AIFEC — escritura de las filas del CSV de productos en el catálogo: altas y cambios por referencia, sin borrar nunca.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Media\MediaException;
use Aifec\Util\Str;

/**
 * Cada fila se escribe en su propia transacción: si falla, no queda nada de ella y las demás siguen. Las existencias nunca se
 * escriben a pelo: se pasan por el servicio «catalog» para que quede apuntado el movimiento. Lo que se crea por el camino
 * (categorías, marcas, atributos y sus valores) se busca antes por su nombre, sin distinguir mayúsculas.
 */
final class ProductWriter
{
    /** @var App */
    private $app;

    /** @var Db */
    private $db;

    /** @var string idioma principal */
    private $lang;

    /** @var array<string,int>|null «idMadre|nombre en minúsculas» => id */
    private $categoryIds;

    /** @var array<string,int>|null nombre en minúsculas => id */
    private $brandIds;

    /** @var array<string,int>|null código o nombre en minúsculas => id */
    private $taxIds;

    /** @var array<string,array{id:int,type:string}>|null código en minúsculas => atributo */
    private $attributes;

    /** @var array<int,array{code:array<string,int>,label:array<string,int>}> id del atributo => sus valores */
    private $values = [];

    /** @var array<string,int> dirección de imagen => id de medios, de lo ya bajado en esta pasada */
    private $downloaded = [];

    /** @var string[] avisos de la fila en curso (no impiden guardarla) */
    private $warnings = [];

    /** @var bool se ha tocado el catálogo desde el último finish() */
    private $touched = false;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->db = $app->db();
        $this->lang = $app->defaultLang();
    }

    // ------------------------------------------------------------------ vista previa

    /**
     * Qué pasaría con esta fila, sin escribir nada: «create» o «update». Lanza RowError si no se podría.
     *
     * @param array<string,mixed> $row
     * @param array<string,bool>  $planned lo que las filas anteriores del mismo fichero darían de alta
     *
     * @throws RowError
     */
    public function plan(array $row, array &$planned): string
    {
        $this->check($row);
        if ($row['parent_sku'] !== '') {
            $parent = $this->productRow((string) $row['parent_sku']);
            if ($parent === null && !isset($planned['p:' . $row['parent_sku']])) {
                throw new RowError('The parent product does not exist: ' . $row['parent_sku']);
            }
            $variantKey = 'v:' . $row['parent_sku'] . '|' . $row['sku'];
            $exists = $parent !== null && $this->db->row('SELECT id FROM {variants} WHERE product_id = ? AND sku = ?', [(int) $parent['id'], (string) $row['sku']]) !== null;
            if ($exists || isset($planned[$variantKey])) {
                return 'update';
            }
            $planned[$variantKey] = true;

            return 'create';
        }
        if ($this->productRow((string) $row['sku']) !== null || isset($planned['p:' . $row['sku']])) {
            return 'update';
        }
        if ($row['name'] === null) {
            throw new RowError('A new product needs a name');
        }
        $planned['p:' . $row['sku']] = true;

        return 'create';
    }

    // ------------------------------------------------------------------ escritura

    /**
     * Escribe una fila: alta si no existe su referencia, cambio si existe. Lanza RowError y no deja nada a medias.
     *
     * @param array<string,mixed> $row
     *
     * @return array{result:string,warnings:string[]} result: created o updated
     *
     * @throws RowError
     */
    public function apply(array $row): array
    {
        $this->warnings = [];
        try {
            $this->check($row);
            $result = $this->db->transaction(function () use ($row): string {
                return $row['parent_sku'] !== '' ? $this->applyVariant($row) : $this->applyProduct($row);
            });
        } catch (\Throwable $e) {
            // Lo que la fila creó (una categoría, un atributo) se deshizo con ella: lo recordado ya no existe.
            $this->categoryIds = $this->brandIds = $this->attributes = null;
            $this->values = [];
            $this->downloaded = [];
            throw $e;
        }

        return ['result' => $result, 'warnings' => $this->warnings];
    }

    /**
     * Al acabar una tanda: cuenta otra vez los productos de cada categoría y vacía la caché del catálogo.
     */
    public function finish(): void
    {
        if (!$this->touched) {
            return;
        }
        $this->touched = false;
        $class = 'Aifec\\Modules\\Catalog\\Admin\\CategoryAdmin';
        if (class_exists($class) && is_callable([$class, 'recount'])) {
            $class::recount($this->app);
        }
        $this->app->invalidate(['catalog']);
    }

    // ------------------------------------------------------------------ producto

    /**
     * @param array<string,mixed> $row
     */
    private function applyProduct(array $row): string
    {
        $existing = $this->productRow((string) $row['sku']);
        if ($existing === null) {
            $id = $this->createProduct($row);
            $result = 'created';
        } else {
            $id = (int) $existing['id'];
            $this->updateProduct($id, $row);
            $result = 'updated';
        }
        $this->texts($id, $row);
        $this->slugs($id);
        $this->categories($id, $row);
        $this->images($id, $row);
        $this->productAttributes($id, $row);
        $this->productStock($id, $row);
        $this->app->invalidate(['product:' . $id]);
        $this->touched = true;

        return $result;
    }

    /**
     * @param array<string,mixed> $row
     */
    private function createProduct(array $row): int
    {
        if ($row['name'] === null) {
            throw new RowError('A new product needs a name');
        }
        $now = $this->app->clock()->sql();
        $status = $row['status'] !== null ? (string) $row['status'] : 'draft';

        return $this->db->insert('products', [
            'kind' => 'physical',
            'has_variants' => 0,
            'sku' => (string) $row['sku'],
            'gtin' => $row['gtin'],
            'mpn' => $row['mpn'],
            'brand_id' => $row['brand'] !== null ? $this->brandId((string) $row['brand']) : null,
            'tax_class_id' => $row['tax_class'] !== null ? $this->taxClassId((string) $row['tax_class']) : null,
            'price' => $row['price'] !== null ? (int) $row['price'] : 0,
            'compare_price' => $row['compare_price'],
            'sale_price' => $row['sale_price'],
            'manage_stock' => 1,
            'stock' => 0,
            'weight' => $row['weight'] !== null ? (int) $row['weight'] : 0,
            'status' => $status,
            'visibility' => 'everywhere',
            'published_at' => $status === 'published' ? $now : null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function updateProduct(int $id, array $row): void
    {
        $set = [];
        foreach (['gtin', 'mpn', 'price', 'compare_price', 'sale_price', 'weight', 'status'] as $field) {
            if ($row[$field] !== null) {
                $set[$field] = $row[$field];
            }
        }
        if ($row['brand'] !== null) {
            $set['brand_id'] = $this->brandId((string) $row['brand']);
        }
        if ($row['tax_class'] !== null) {
            $set['tax_class_id'] = $this->taxClassId((string) $row['tax_class']);
        }
        if ($set !== []) {
            $set['updated_at'] = $this->app->clock()->sql();
            $this->db->update('products', $set, ['id' => $id]);
        }
        if (isset($set['status']) && $set['status'] === 'published') {
            $this->db->run('UPDATE {products} SET published_at = ? WHERE id = ? AND published_at IS NULL', [$this->app->clock()->sql(), $id]);
        }
    }

    /**
     * Los textos del idioma principal y los nombres de los demás idiomas.
     *
     * @param array<string,mixed> $row
     */
    private function texts(int $id, array $row): void
    {
        $base = [];
        foreach (['name', 'summary', 'description', 'meta_title', 'meta_description'] as $field) {
            if ($row[$field] !== null) {
                $base[$field] = (string) $row[$field];
            }
        }
        $this->writeTexts($id, $this->lang, $base);
        foreach ($row['names'] as $lang => $name) {
            $this->writeTexts($id, (string) $lang, ['name' => (string) $name]);
        }
    }

    /**
     * @param array<string,string> $fields los textos que vienen (el nombre es obligatorio si el idioma aún no tiene fila)
     */
    private function writeTexts(int $id, string $lang, array $fields): void
    {
        if ($fields === []) {
            return;
        }
        $exists = $this->db->row('SELECT id FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$id, $lang]) !== null;
        if ($exists) {
            $this->db->update('product_i18n', $fields, ['product_id' => $id, 'lang' => $lang]);

            return;
        }
        if (!isset($fields['name'])) {
            throw new RowError('The name is missing for the language ' . $lang);
        }
        $this->db->insert('product_i18n', [
            'product_id' => $id, 'lang' => $lang, 'name' => $fields['name'],
            'summary' => isset($fields['summary']) ? $fields['summary'] : '',
            'description' => isset($fields['description']) ? $fields['description'] : '',
            'facts' => null,
            'meta_title' => isset($fields['meta_title']) ? $fields['meta_title'] : null,
            'meta_description' => isset($fields['meta_description']) ? $fields['meta_description'] : null,
        ]);
    }

    /** Dirección amigable de cada idioma que aún no tenga (una dirección que ya existe no se toca: puede estar en buscadores). */
    private function slugs(int $id): void
    {
        $prefix = trim($this->app->settings()->str('urls.product', 'producto'), '/');
        $prefix = $prefix !== '' ? $prefix : 'producto';
        foreach ($this->db->select('SELECT lang, name FROM {product_i18n} WHERE product_id = ?', [$id]) as $text) {
            $lang = (string) $text['lang'];
            if ($this->app->slugs()->path('product', $id, $lang) === null) {
                $segment = Str::slug((string) $text['name'], 90);
                $this->app->slugs()->set('product', $id, $lang, $prefix . '/' . ($segment !== '' ? $segment : 'product-' . $id));
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function categories(int $id, array $row): void
    {
        if ($row['categories'] === null || $row['categories'] === []) {
            return;
        }
        $ids = [];
        foreach ($row['categories'] as $parts) {
            $categoryId = $this->categoryId($parts);
            if (!in_array($categoryId, $ids, true)) {
                $ids[] = $categoryId;
            }
        }
        $this->db->delete('product_categories', ['product_id' => $id]);
        foreach ($ids as $position => $categoryId) {
            $this->db->insert('product_categories', ['product_id' => $id, 'category_id' => $categoryId, 'is_main' => $position === 0 ? 1 : 0, 'position' => $position]);
        }
    }

    /**
     * @param array<string,mixed> $row
     */
    private function productStock(int $id, array $row): void
    {
        if ($row['stock'] === null) {
            return;
        }
        $product = $this->db->row('SELECT stock, has_variants FROM {products} WHERE id = ?', [$id]);
        if ($product === null || (int) $product['has_variants'] === 1) {
            return; // con variantes, las existencias son las de cada variante
        }
        $this->changeStock($id, 0, (int) $row['stock'] - (int) $product['stock']);
    }

    private function changeStock(int $productId, int $variantId, int $delta): void
    {
        if ($delta !== 0 && !$this->app->service('catalog')->adjustStock($productId, $variantId, $delta, 'import')) {
            $this->warnings[] = 'The stock could not be changed';
        }
    }

    // ------------------------------------------------------------------ imágenes

    /**
     * Las imágenes del producto pasan a ser las de la fila, en su orden. Las que ya tiene el producto (por su dirección) no se
     * vuelven a bajar. Si alguna no se puede bajar, no se cambia ninguna: no se deja un producto con la mitad de las fotos.
     *
     * @param array<string,mixed> $row
     */
    private function images(int $id, array $row): void
    {
        if ($row['images'] === null || $row['images'] === []) {
            return;
        }
        if (!$this->app->has('media')) {
            $this->warnings[] = 'The media library is not available: the images were skipped';

            return;
        }
        $media = $this->app->service('media');
        $current = [];
        $currentIds = [];
        foreach ($this->db->column('SELECT media_id FROM {product_media} WHERE product_id = ? AND variant_id IS NULL ORDER BY position', [$id]) as $mediaId) {
            $currentIds[] = (int) $mediaId;
            $url = (string) $media->url((int) $mediaId);
            if ($url !== '') {
                $current[$url] = (int) $mediaId;
                $current[$this->app->url()->origin() . $url] = (int) $mediaId;
            }
        }
        $wanted = [];
        $failed = 0;
        foreach ($row['images'] as $url) {
            $url = (string) preg_replace('/#.*$/', '', (string) $url);
            if (isset($current[$url])) {
                $wanted[] = $current[$url];
                continue;
            }
            if (isset($this->downloaded[$url])) {
                $wanted[] = $this->downloaded[$url];
                continue;
            }
            try {
                $stored = $media->importUrl($url, ['folder' => 'products']);
                $this->downloaded[$url] = (int) $stored['id'];
                $wanted[] = (int) $stored['id'];
            } catch (MediaException $e) {
                ++$failed;
                $this->warnings[] = 'The image could not be downloaded: ' . mb_substr($url, 0, 120, 'UTF-8');
            }
        }
        if ($failed > 0 || $wanted === $currentIds) {
            return;
        }
        $this->db->run('DELETE FROM {product_media} WHERE product_id = ? AND variant_id IS NULL', [$id]);
        $position = 0;
        foreach ($wanted as $mediaId) {
            if ($this->db->insertIgnore('product_media', ['product_id' => $id, 'media_id' => $mediaId, 'variant_id' => null, 'position' => $position])) {
                ++$position;
            }
        }
        $this->db->update('products', ['main_media_id' => $wanted[0]], ['id' => $id]);
    }

    // ------------------------------------------------------------------ atributos

    /**
     * Los atributos de la ficha del producto (los de la fila sin «parent_sku»).
     *
     * @param array<string,mixed> $row
     */
    private function productAttributes(int $id, array $row): void
    {
        foreach ($row['attributes'] as $code => $text) {
            $attr = $this->attribute((string) $code, false);
            $this->db->delete('product_attributes', ['product_id' => $id, 'attribute_id' => $attr['id']]);
            $base = ['product_id' => $id, 'attribute_id' => $attr['id']];
            switch ($attr['type']) {
                case 'select':
                case 'color':
                    $this->db->insert('product_attributes', $base + ['value_id' => $this->valueId($attr['id'], (string) $text)]);
                    break;
                case 'multiselect':
                    foreach (explode('|', (string) $text) as $label) {
                        if (trim($label) !== '') {
                            $this->db->insert('product_attributes', $base + ['value_id' => $this->valueId($attr['id'], trim($label))]);
                        }
                    }
                    break;
                case 'number':
                    $this->db->insert('product_attributes', $base + ['value_number' => $this->number((string) $code, (string) $text)]);
                    break;
                case 'bool':
                    $this->db->insert('product_attributes', $base + ['value_number' => $this->flag((string) $code, (string) $text)]);
                    break;
                default:
                    $this->db->insert('product_attributes', $base + ['lang' => $this->lang, 'value_text' => (string) $text]);
                    break;
            }
        }
    }

    private function number(string $code, string $text): string
    {
        try {
            return Amounts::decimal($text);
        } catch (\InvalidArgumentException $e) {
            throw new RowError('The attribute ' . $code . ' must be a number: ' . $text);
        }
    }

    private function flag(string $code, string $text): int
    {
        // mb_strtolower: strtolower de PHP 7 depende del idioma del sistema y puede estropear una «í» en UTF-8.
        $value = mb_strtolower($text, 'UTF-8');
        if (in_array($value, ['1', 'yes', 'true', 'si', 'sí'], true)) {
            return 1;
        }
        if (in_array($value, ['0', 'no', 'false'], true)) {
            return 0;
        }

        throw new RowError('The attribute ' . $code . ' must be yes or no: ' . $text);
    }

    /**
     * Un atributo por su código; si no existe se crea como lista de valores.
     *
     * @return array{id:int,type:string}
     */
    private function attribute(string $code, bool $forVariants): array
    {
        if ($this->attributes === null) {
            $this->attributes = [];
            foreach ($this->db->select('SELECT id, code, type FROM {attributes}') as $row) {
                $this->attributes[strtolower((string) $row['code'])] = ['id' => (int) $row['id'], 'type' => (string) $row['type']];
            }
        }
        $key = strtolower($code);
        if (!isset($this->attributes[$key])) {
            if (preg_match(CsvColumns::ATTRIBUTE_CODE, $key) !== 1) {
                throw new RowError('The attribute code is not valid: ' . $code);
            }
            $position = (int) $this->db->value('SELECT COUNT(*) FROM {attributes}');
            $id = $this->db->insert('attributes', [
                'code' => $key, 'type' => 'select', 'unit' => null, 'filterable' => 1, 'visible' => 1, 'variant' => $forVariants ? 1 : 0, 'position' => $position,
            ]);
            $this->db->insert('attribute_i18n', ['attribute_id' => $id, 'lang' => $this->lang, 'name' => ucfirst(str_replace(['-', '_'], ' ', $key))]);
            $this->attributes[$key] = ['id' => $id, 'type' => 'select'];
        }

        return $this->attributes[$key];
    }

    /**
     * El valor de un atributo de lista por su texto (o su código); si no existe se crea.
     */
    private function valueId(int $attributeId, string $label): int
    {
        if (!isset($this->values[$attributeId])) {
            $this->values[$attributeId] = ['code' => [], 'label' => []];
            $rows = $this->db->select(
                'SELECT v.id, v.code, i.label FROM {attribute_values} v LEFT JOIN {attribute_value_i18n} i ON i.value_id = v.id AND i.lang = ? WHERE v.attribute_id = ?',
                [$this->lang, $attributeId]
            );
            foreach ($rows as $row) {
                $this->values[$attributeId]['code'][mb_strtolower((string) $row['code'], 'UTF-8')] = (int) $row['id'];
                if ($row['label'] !== null) {
                    $this->values[$attributeId]['label'][mb_strtolower((string) $row['label'], 'UTF-8')] = (int) $row['id'];
                }
            }
        }
        $key = mb_strtolower(trim($label), 'UTF-8');
        if (isset($this->values[$attributeId]['label'][$key])) {
            return $this->values[$attributeId]['label'][$key];
        }
        if (isset($this->values[$attributeId]['code'][$key])) {
            return $this->values[$attributeId]['code'][$key];
        }
        $count = count($this->values[$attributeId]['code']);
        $slug = Str::slug($label, 60);
        $code = $slug !== '' ? $slug : 'value-' . ($count + 1);
        for ($n = 2; isset($this->values[$attributeId]['code'][$code]); ++$n) {
            $code = ($slug !== '' ? $slug : 'value') . '-' . $n;
        }
        $valueId = $this->db->insert('attribute_values', ['attribute_id' => $attributeId, 'code' => $code, 'color' => null, 'media_id' => null, 'position' => $count]);
        $this->db->insert('attribute_value_i18n', ['value_id' => $valueId, 'lang' => $this->lang, 'label' => trim($label)]);
        $this->values[$attributeId]['code'][$code] = $valueId;
        $this->values[$attributeId]['label'][$key] = $valueId;

        return $valueId;
    }

    // ------------------------------------------------------------------ variante

    /**
     * @param array<string,mixed> $row
     */
    private function applyVariant(array $row): string
    {
        $parent = $this->productRow((string) $row['parent_sku']);
        if ($parent === null) {
            throw new RowError('The parent product does not exist: ' . $row['parent_sku']);
        }
        $pid = (int) $parent['id'];
        $now = $this->app->clock()->sql();
        $variant = $this->db->row('SELECT id FROM {variants} WHERE product_id = ? AND sku = ?', [$pid, (string) $row['sku']]);
        $fields = [];
        foreach (['gtin', 'price', 'compare_price', 'weight'] as $field) {
            if ($row[$field] !== null) {
                $fields[$field] = $row[$field];
            }
        }
        if ($row['name'] !== null) {
            $fields['label'] = (string) $row['name'];
        }
        if ($variant === null) {
            $vid = $this->db->insert('variants', $fields + [
                'product_id' => $pid, 'sku' => (string) $row['sku'], 'gtin' => null, 'price' => null, 'compare_price' => null, 'cost' => null, 'stock' => 0,
                'weight' => null, 'media_id' => null, 'label' => null,
                'position' => (int) $this->db->value('SELECT COUNT(*) FROM {variants} WHERE product_id = ?', [$pid]),
                'active' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $result = 'created';
        } else {
            $vid = (int) $variant['id'];
            if ($fields !== []) {
                $this->db->update('variants', $fields + ['updated_at' => $now], ['id' => $vid]);
            }
            $result = 'updated';
        }
        if ((int) $parent['has_variants'] !== 1) {
            $this->db->update('products', ['has_variants' => 1, 'updated_at' => $now], ['id' => $pid]);
        }
        foreach ($row['attributes'] as $code => $text) {
            $attr = $this->attribute((string) $code, true);
            if ($attr['type'] !== 'select' && $attr['type'] !== 'color') {
                throw new RowError('The options of a variant must be a list attribute: ' . $code);
            }
            $this->db->delete('variant_values', ['variant_id' => $vid, 'attribute_id' => $attr['id']]);
            $this->db->insert('variant_values', ['variant_id' => $vid, 'attribute_id' => $attr['id'], 'value_id' => $this->valueId($attr['id'], (string) $text)]);
        }
        if ($row['stock'] !== null) {
            $this->changeStock($pid, $vid, (int) $row['stock'] - (int) $this->db->value('SELECT stock FROM {variants} WHERE id = ?', [$vid]));
        }
        $this->app->invalidate(['product:' . $pid]);
        $this->touched = true;

        return $result;
    }

    // ------------------------------------------------------------------ lo que se nombra

    /**
     * Lo que se nombra y no se crea tiene que existir: la clase de impuesto.
     *
     * @param array<string,mixed> $row
     *
     * @throws RowError
     */
    private function check(array $row): void
    {
        if ($row['tax_class'] !== null && $row['parent_sku'] === '') {
            $this->taxClassId((string) $row['tax_class']);
        }
    }

    /**
     * @return array<string,mixed>|null id y has_variants del producto con esa referencia
     */
    private function productRow(string $sku): ?array
    {
        return $this->db->row('SELECT id, has_variants FROM {products} WHERE sku = ? ORDER BY id', [$sku]);
    }

    private function taxClassId(string $code): int
    {
        if ($this->taxIds === null) {
            $this->taxIds = [];
            if ($this->db->tableExists('tax_classes')) {
                foreach ($this->db->select('SELECT id, code, name FROM {tax_classes}') as $row) {
                    $this->taxIds[mb_strtolower((string) $row['code'], 'UTF-8')] = (int) $row['id'];
                    $this->taxIds[mb_strtolower((string) $row['name'], 'UTF-8')] = (int) $row['id'];
                }
            }
        }
        $key = mb_strtolower(trim($code), 'UTF-8');
        if (!isset($this->taxIds[$key])) {
            throw new RowError('The tax class does not exist: ' . $code);
        }

        return $this->taxIds[$key];
    }

    private function brandId(string $name): int
    {
        if ($this->brandIds === null) {
            $this->brandIds = [];
            foreach ($this->db->select('SELECT id, name FROM {brands}') as $row) {
                $this->brandIds[mb_strtolower((string) $row['name'], 'UTF-8')] = (int) $row['id'];
            }
        }
        $key = mb_strtolower(trim($name), 'UTF-8');
        if (isset($this->brandIds[$key])) {
            return $this->brandIds[$key];
        }
        $now = $this->app->clock()->sql();
        $id = $this->db->insert('brands', [
            'name' => trim($name), 'description' => null, 'website' => null, 'media_id' => null, 'active' => 1, 'position' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $prefix = trim($this->app->settings()->str('urls.brand', 'marca'), '/');
        $segment = Str::slug($name, 90);
        $this->app->slugs()->set('brand', $id, $this->lang, ($prefix !== '' ? $prefix : 'marca') . '/' . ($segment !== '' ? $segment : 'brand-' . $id));
        $this->brandIds[$key] = $id;

        return $id;
    }

    /**
     * La categoría de una ruta de nombres («Muebles», «Sillas»); las que falten se crean.
     *
     * @param string[] $parts
     */
    private function categoryId(array $parts): int
    {
        if ($this->categoryIds === null) {
            $this->categoryIds = [];
            $rows = $this->db->select(
                'SELECT c.id, c.parent_id, i.name FROM {categories} c LEFT JOIN {category_i18n} i ON i.category_id = c.id AND i.lang = ?',
                [$this->lang]
            );
            foreach ($rows as $row) {
                if ($row['name'] !== null) {
                    $this->categoryIds[(int) $row['parent_id'] . '|' . mb_strtolower((string) $row['name'], 'UTF-8')] = (int) $row['id'];
                }
            }
        }
        $parent = 0;
        foreach ($parts as $name) {
            $key = $parent . '|' . mb_strtolower($name, 'UTF-8');
            if (!isset($this->categoryIds[$key])) {
                $this->categoryIds[$key] = $this->createCategory($parent > 0 ? $parent : null, $name);
            }
            $parent = $this->categoryIds[$key];
        }

        return $parent;
    }

    private function createCategory(?int $parentId, string $name): int
    {
        $now = $this->app->clock()->sql();
        $parent = $parentId !== null ? $this->db->row('SELECT id, path, depth FROM {categories} WHERE id = ?', [$parentId]) : null;
        $position = $parentId !== null
            ? (int) $this->db->value('SELECT COUNT(*) FROM {categories} WHERE parent_id = ?', [$parentId])
            : (int) $this->db->value('SELECT COUNT(*) FROM {categories} WHERE parent_id IS NULL');
        $id = $this->db->insert('categories', [
            'parent_id' => $parentId, 'path' => '', 'depth' => $parent !== null ? (int) $parent['depth'] + 1 : 0, 'position' => $position,
            'active' => 1, 'in_menu' => 1, 'media_id' => null, 'product_count' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $this->db->update('categories', ['path' => $parent !== null ? trim((string) $parent['path'], '/') . '/' . $id : (string) $id], ['id' => $id]);
        $this->db->insert('category_i18n', [
            'category_id' => $id, 'lang' => $this->lang, 'name' => $name, 'description' => '', 'facts' => null, 'meta_title' => null, 'meta_description' => null,
        ]);
        $segment = Str::slug($name, 90);
        $segment = $segment !== '' ? $segment : 'category-' . $id;
        $base = $parentId !== null ? $this->app->slugs()->path('category', $parentId, $this->lang) : null;
        if ($base === null) {
            $prefix = trim($this->app->settings()->str('urls.category', 'categoria'), '/');
            $base = $prefix !== '' ? $prefix : 'categoria';
        }
        $this->app->slugs()->set('category', $id, $this->lang, $base . '/' . $segment);

        return $id;
    }
}
