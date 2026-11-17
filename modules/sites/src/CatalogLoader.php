<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Sites;

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Admin\CategoryAdmin;
use Aifec\Modules\Catalog\Admin\TaxonomySlugs;
use Aifec\Util\Str;

/**
 * Carga en un sitio los atributos, las marcas, las categorías y los productos de un paquete (DemoBuilder la llama).
 *
 * Todo se casa por referencia o por código, así que repetir la carga no duplica nada: el atributo por su código, la
 * marca y la categoría por su dirección (que sale del código), el producto por su referencia («sku»). Lo que ya
 * existe no se toca. Las tablas son las del módulo de catálogo (modules/catalog/migrations/0001_catalog.php).
 */
final class CatalogLoader
{
    /** @var App */
    private $app;

    /** @var Package */
    private $pkg;

    /** @var callable(string,string):int guarda una imagen del paquete en la biblioteca y devuelve su identificador */
    private $media;

    /** @var string */
    private $lang;

    /** @var array<string,array<string,int>> tipo (attribute, brand, category, product) => código => identificador */
    private $ids = ['attribute' => [], 'brand' => [], 'category' => [], 'product' => []];

    /** @var array<string,array<string,int>> código de atributo => código de valor => identificador */
    private $valueIds = [];

    /** @var array<string,array<string,string>> código de atributo => código de valor => etiqueta */
    private $valueLabels = [];

    /** @var array<string,int> lo que se ha creado de verdad (lo que ya existía no cuenta) */
    private $created = ['attributes' => 0, 'brands' => 0, 'categories' => 0, 'products' => 0, 'variants' => 0];

    /**
     * @param callable(string,string):int $media (nombre de fichero, texto alternativo) → identificador en la biblioteca de medios
     */
    public function __construct(App $app, Package $pkg, callable $media)
    {
        $this->app = $app;
        $this->pkg = $pkg;
        $this->media = $media;
        $this->lang = $app->defaultLang();
    }

    /**
     * Identificador de una cosa ya cargada (para los menús, las páginas y las valoraciones): null si no existe.
     */
    public function id(string $kind, string $code): ?int
    {
        return isset($this->ids[$kind][$code]) ? $this->ids[$kind][$code] : null;
    }

    /**
     * @return array<string,int>
     */
    public function created(): array
    {
        return $this->created;
    }

    /**
     * Carga todo lo que trae el paquete, en este orden: atributos, marcas, categorías, productos.
     */
    public function load(): void
    {
        $this->attributes();
        $this->brands();
        $this->categories();
        $this->products();
        if ($this->pkg->has('products.json') || $this->pkg->has('categories.json')) {
            CategoryAdmin::recount($this->app);
        }
    }

    // ------------------------------------------------------------------ atributos

    private function attributes(): void
    {
        $db = $this->app->db();
        foreach (array_values($this->pkg->data('attributes.json')) as $i => $a) {
            $code = (string) $a['code'];
            $id = $db->value('SELECT id FROM {attributes} WHERE code = ?', [$code]);
            if ($id === null) {
                $id = $db->insert('attributes', [
                    'code' => $code,
                    'type' => isset($a['type']) ? (string) $a['type'] : 'select',
                    'unit' => isset($a['unit']) && is_string($a['unit']) && $a['unit'] !== '' ? $a['unit'] : null,
                    'filterable' => array_key_exists('filterable', $a) ? (int) (bool) $a['filterable'] : 1,
                    'visible' => array_key_exists('visible', $a) ? (int) (bool) $a['visible'] : 1,
                    'variant' => !empty($a['variant']) ? 1 : 0,
                    'position' => isset($a['position']) ? (int) $a['position'] : $i,
                ]);
                $db->insert('attribute_i18n', ['attribute_id' => (int) $id, 'lang' => $this->lang, 'name' => (string) $a['name']]);
                ++$this->created['attributes'];
            }
            $id = (int) $id;
            $this->ids['attribute'][$code] = $id;
            foreach (array_values(isset($a['values']) && is_array($a['values']) ? $a['values'] : []) as $n => $v) {
                $vcode = is_string($v) ? $v : (string) $v['code'];
                $label = is_array($v) && isset($v['label']) && is_string($v['label']) ? $v['label'] : ucfirst(str_replace('-', ' ', $vcode));
                $this->valueLabels[$code][$vcode] = $label;
                $valueId = $db->value('SELECT id FROM {attribute_values} WHERE attribute_id = ? AND code = ?', [$id, $vcode]);
                if ($valueId === null) {
                    $valueId = $db->insert('attribute_values', [
                        'attribute_id' => $id, 'code' => $vcode,
                        'color' => is_array($v) && isset($v['color']) ? (string) $v['color'] : null,
                        'position' => $n,
                    ]);
                    $db->insert('attribute_value_i18n', ['value_id' => (int) $valueId, 'lang' => $this->lang, 'label' => $label]);
                }
                $this->valueIds[$code][$vcode] = (int) $valueId;
            }
        }
    }

    // ------------------------------------------------------------------ marcas

    private function brands(): void
    {
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        foreach (array_values($this->pkg->data('brands.json')) as $i => $b) {
            $code = (string) $b['code'];
            $path = TaxonomySlugs::prefix($this->app, 'brand') . '/' . $code;
            $hit = $this->app->slugs()->resolve($this->lang, $path);
            if ($hit !== null && $hit['type'] === 'brand') {
                $this->ids['brand'][$code] = $hit['entity_id'];
                continue;
            }
            $id = $db->insert('brands', [
                'name' => (string) $b['name'],
                'description' => isset($b['description']) && is_string($b['description']) ? $b['description'] : null,
                'website' => isset($b['website']) && is_string($b['website']) ? $b['website'] : null,
                'media_id' => isset($b['image']) && is_string($b['image']) ? $this->image($b['image'], (string) $b['name']) : null,
                'active' => 1,
                'position' => isset($b['position']) ? (int) $b['position'] : $i,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->app->slugs()->set('brand', $id, $this->lang, $path);
            $this->ids['brand'][$code] = $id;
            ++$this->created['brands'];
        }
    }

    // ------------------------------------------------------------------ categorías

    private function categories(): void
    {
        $pending = [];
        foreach (array_values($this->pkg->data('categories.json')) as $i => $c) {
            $c['_position'] = $i;
            $pending[(string) $c['code']] = $c;
        }
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $code => $c) {
                $parent = isset($c['parent']) && $c['parent'] !== null ? (string) $c['parent'] : null;
                if ($parent !== null && !isset($this->ids['category'][$parent])) {
                    continue;
                }
                $this->category((string) $code, $c, $parent !== null ? $this->ids['category'][$parent] : null);
                unset($pending[$code]);
                $progress = true;
            }
            if (!$progress) {
                throw new \RuntimeException('The categories have a parent that is never created');
            }
        }
    }

    /**
     * @param array<string,mixed> $c
     */
    private function category(string $code, array $c, ?int $parentId): void
    {
        $db = $this->app->db();
        $path = TaxonomySlugs::categoryPath($this->app, $parentId, $this->lang, $code);
        $hit = $this->app->slugs()->resolve($this->lang, $path);
        if ($hit !== null && $hit['type'] === 'category') {
            $this->ids['category'][$code] = $hit['entity_id'];

            return;
        }
        $now = $this->app->clock()->sql();
        $parent = $parentId !== null ? $db->row('SELECT path, depth FROM {categories} WHERE id = ?', [$parentId]) : null;
        $id = $db->insert('categories', [
            'parent_id' => $parentId, 'path' => '', 'depth' => $parent !== null ? (int) $parent['depth'] + 1 : 0,
            'position' => isset($c['position']) ? (int) $c['position'] : (int) $c['_position'],
            'active' => 1, 'in_menu' => 1,
            'media_id' => isset($c['image']) && is_string($c['image']) ? $this->image($c['image'], (string) $c['name']) : null,
            'product_count' => 0, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $db->update('categories', ['path' => $parent !== null ? trim((string) $parent['path'], '/') . '/' . $id : (string) $id], ['id' => $id]);
        $db->insert('category_i18n', [
            'category_id' => $id, 'lang' => $this->lang, 'name' => (string) $c['name'],
            'description' => isset($c['description']) && is_string($c['description']) ? $c['description'] : '',
            'facts' => null,
            'meta_title' => isset($c['meta_title']) && is_string($c['meta_title']) ? $c['meta_title'] : null,
            'meta_description' => isset($c['meta_description']) && is_string($c['meta_description']) ? $c['meta_description'] : null,
        ]);
        $this->app->slugs()->set('category', $id, $this->lang, $path);
        $this->ids['category'][$code] = $id;
        ++$this->created['categories'];
    }

    // ------------------------------------------------------------------ productos

    private function products(): void
    {
        foreach (array_values($this->pkg->data('products.json')) as $i => $p) {
            $sku = (string) $p['sku'];
            $existing = $this->app->db()->value('SELECT id FROM {products} WHERE sku = ?', [$sku]);
            if ($existing !== null) {
                $this->ids['product'][$sku] = (int) $existing;
                continue;
            }
            $this->ids['product'][$sku] = $this->app->db()->transaction(function () use ($p, $i): int {
                return $this->product($p, $i);
            });
            ++$this->created['products'];
        }
    }

    /**
     * @param array<string,mixed> $p
     */
    private function product(array $p, int $position): int
    {
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        $variants = isset($p['variants']) && is_array($p['variants']) ? array_values($p['variants']) : [];
        $published = !isset($p['status']) || $p['status'] === 'published';
        $taxClass = null;
        if (isset($p['tax_class']) && is_string($p['tax_class'])) {
            $found = $db->value('SELECT id FROM {tax_classes} WHERE code = ?', [$p['tax_class']]);
            $taxClass = $found !== null ? (int) $found : null;
        }
        $brandId = isset($p['brand']) && is_string($p['brand']) && isset($this->ids['brand'][$p['brand']]) ? $this->ids['brand'][$p['brand']] : null;
        $id = $db->insert('products', [
            'kind' => isset($p['kind']) ? (string) $p['kind'] : 'physical',
            'has_variants' => $variants !== [] ? 1 : 0,
            'sku' => (string) $p['sku'],
            'gtin' => isset($p['gtin']) ? (string) $p['gtin'] : null,
            'mpn' => isset($p['mpn']) && is_string($p['mpn']) ? $p['mpn'] : null,
            'brand_id' => $brandId,
            'tax_class_id' => $taxClass,
            'price' => (int) $p['price'],
            'compare_price' => isset($p['compare_price']) ? (int) $p['compare_price'] : null,
            'sale_price' => isset($p['sale_price']) ? (int) $p['sale_price'] : null,
            'sale_from' => isset($p['sale_from']) && is_string($p['sale_from']) ? $p['sale_from'] : null,
            'sale_to' => isset($p['sale_to']) && is_string($p['sale_to']) ? $p['sale_to'] : null,
            'manage_stock' => array_key_exists('manage_stock', $p) ? (int) (bool) $p['manage_stock'] : 1,
            'stock' => isset($p['stock']) ? (int) $p['stock'] : 0,
            'backorders' => 'no',
            'weight' => isset($p['weight']) ? (int) $p['weight'] : 0,
            'length' => isset($p['length']) ? (int) $p['length'] : 0,
            'width' => isset($p['width']) ? (int) $p['width'] : 0,
            'height' => isset($p['height']) ? (int) $p['height'] : 0,
            'delivery_min_days' => isset($p['delivery_min_days']) ? (int) $p['delivery_min_days'] : null,
            'delivery_max_days' => isset($p['delivery_max_days']) ? (int) $p['delivery_max_days'] : null,
            'status' => $published ? 'published' : 'draft',
            'visibility' => 'everywhere',
            'featured' => !empty($p['featured']) ? 1 : 0,
            'position' => $position,
            'published_at' => $published ? $now : null,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $facts = [];
        foreach (isset($p['facts']) && is_array($p['facts']) ? $p['facts'] : [] as $fact) {
            $facts[] = ['label' => (string) $fact['label'], 'value' => (string) $fact['value']];
        }
        $db->insert('product_i18n', [
            'product_id' => $id, 'lang' => $this->lang, 'name' => (string) $p['name'],
            'summary' => isset($p['summary']) && is_string($p['summary']) ? $p['summary'] : '',
            'description' => isset($p['description']) && is_string($p['description']) ? $p['description'] : '',
            'facts' => $facts === [] ? null : json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'meta_title' => isset($p['meta_title']) && is_string($p['meta_title']) && $p['meta_title'] !== '' ? $p['meta_title'] : null,
            'meta_description' => isset($p['meta_description']) && is_string($p['meta_description']) && $p['meta_description'] !== '' ? $p['meta_description'] : null,
        ]);
        $segment = TaxonomySlugs::make(isset($p['slug']) && is_string($p['slug']) && $p['slug'] !== '' ? $p['slug'] : (string) $p['name'], 'product-' . $id);
        $this->app->slugs()->set('product', $id, $this->lang, TaxonomySlugs::prefix($this->app, 'product') . '/' . $segment);

        foreach (array_values(isset($p['categories']) && is_array($p['categories']) ? $p['categories'] : []) as $n => $code) {
            if (isset($this->ids['category'][(string) $code])) {
                $db->insertIgnore('product_categories', ['product_id' => $id, 'category_id' => $this->ids['category'][(string) $code], 'is_main' => $n === 0 ? 1 : 0, 'position' => $n]);
            }
        }
        $this->productImages($id, $p);
        foreach (array_values($variants) as $n => $variant) {
            $this->variant($id, $variant, $n);
        }
        foreach (isset($p['tags']) && is_array($p['tags']) ? $p['tags'] : [] as $tag) {
            if (is_string($tag) && trim($tag) !== '') {
                $db->insertIgnore('tags', ['lang' => $this->lang, 'name' => trim($tag)]);
                $tagId = (int) $db->value('SELECT id FROM {tags} WHERE lang = ? AND name = ?', [$this->lang, trim($tag)]);
                $db->insertIgnore('product_tags', ['product_id' => $id, 'tag_id' => $tagId]);
            }
        }
        foreach (array_values(isset($p['faqs']) && is_array($p['faqs']) ? $p['faqs'] : []) as $n => $faq) {
            $db->insert('product_faqs', ['product_id' => $id, 'lang' => $this->lang, 'question' => (string) $faq['question'], 'answer' => (string) $faq['answer'], 'position' => $n]);
        }
        $this->productAttributes($id, $p);

        return $id;
    }

    /**
     * @param array<string,mixed> $p
     */
    private function productImages(int $productId, array $p): void
    {
        $db = $this->app->db();
        $seen = [];
        $first = null;
        $position = 0;
        foreach (array_values(isset($p['images']) && is_array($p['images']) ? $p['images'] : []) as $image) {
            $file = is_string($image) ? $image : (string) $image['file'];
            $alt = is_array($image) && isset($image['alt']) && is_string($image['alt']) && $image['alt'] !== '' ? $image['alt'] : (string) $p['name'];
            $mediaId = $this->image($file, $alt);
            if (isset($seen[$mediaId])) {
                continue;
            }
            $seen[$mediaId] = true;
            $db->insert('product_media', ['product_id' => $productId, 'media_id' => $mediaId, 'variant_id' => null, 'position' => $position]);
            $first = $first === null ? $mediaId : $first;
            ++$position;
        }
        if ($first !== null) {
            $db->update('products', ['main_media_id' => $first], ['id' => $productId]);
        }
    }

    /**
     * @param array<string,mixed> $v
     */
    private function variant(int $productId, array $v, int $position): void
    {
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        $values = isset($v['values']) && is_array($v['values']) ? $v['values'] : [];
        $label = isset($v['label']) && is_string($v['label']) && $v['label'] !== '' ? $v['label'] : '';
        if ($label === '') {
            $parts = [];
            foreach ($values as $attribute => $value) {
                $parts[] = isset($this->valueLabels[(string) $attribute][(string) $value]) ? $this->valueLabels[(string) $attribute][(string) $value] : (string) $value;
            }
            $label = implode(' / ', $parts);
        }
        $variantId = $db->insert('variants', [
            'product_id' => $productId, 'sku' => (string) $v['sku'],
            'gtin' => isset($v['gtin']) ? (string) $v['gtin'] : null,
            'price' => isset($v['price']) ? (int) $v['price'] : null,
            'compare_price' => isset($v['compare_price']) ? (int) $v['compare_price'] : null,
            'cost' => null, 'stock' => isset($v['stock']) ? (int) $v['stock'] : 0,
            'weight' => isset($v['weight']) ? (int) $v['weight'] : null,
            'media_id' => isset($v['image']) && is_string($v['image']) ? $this->image($v['image'], $label) : null,
            'label' => $label, 'position' => $position, 'active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        foreach ($values as $attribute => $value) {
            $db->insert('variant_values', [
                'variant_id' => $variantId, 'attribute_id' => $this->ids['attribute'][(string) $attribute],
                'value_id' => $this->valueIds[(string) $attribute][(string) $value],
            ]);
        }
        ++$this->created['variants'];
    }

    /**
     * @param array<string,mixed> $p
     */
    private function productAttributes(int $productId, array $p): void
    {
        $db = $this->app->db();
        foreach (isset($p['attributes']) && is_array($p['attributes']) ? $p['attributes'] : [] as $code => $value) {
            $code = (string) $code;
            $attributeId = $this->ids['attribute'][$code];
            $type = (string) $db->value('SELECT type FROM {attributes} WHERE id = ?', [$attributeId]);
            if ($type === 'select' || $type === 'color' || $type === 'multiselect') {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    $db->insert('product_attributes', ['product_id' => $productId, 'attribute_id' => $attributeId, 'value_id' => $this->valueIds[$code][(string) $item]]);
                }
            } elseif ($type === 'number') {
                $db->insert('product_attributes', ['product_id' => $productId, 'attribute_id' => $attributeId, 'value_number' => (string) $value]);
            } elseif ($type === 'bool') {
                $db->insert('product_attributes', ['product_id' => $productId, 'attribute_id' => $attributeId, 'value_number' => $value ? 1 : 0]);
            } else {
                $db->insert('product_attributes', ['product_id' => $productId, 'attribute_id' => $attributeId, 'value_text' => Str::limit((string) $value, 500, '')]);
            }
        }
    }

    private function image(string $file, string $alt): int
    {
        $media = $this->media;

        return (int) $media($file, $alt);
    }
}
