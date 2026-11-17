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
 * Lee de la base de datos todo lo que hace falta para armar productos, de varios a la vez.
 *
 * Cada pieza (textos, variantes, imágenes, marcas, categorías…) es UNA consulta con «IN (…)» sin importar cuántos
 * productos se pidan: por eso un listado de 5 y uno de 40 cuestan las mismas consultas. Para una tarjeta (full = false)
 * se leen solo las piezas que una tarjeta enseña.
 *
 * Un «paquete» es el array de un producto: row (fila de products), text (name, summary, description, facts,
 * meta_title, meta_description ya en el idioma que toca), variants (activas), images (arrays de imagen en orden),
 * brand (id y nombre, o null) y, solo en el modo completo: values, categories, tags, faqs, related y attrRows.
 */
final class ProductLoader
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>> id => paquete; solo los que existen, en el orden pedido
     */
    public function load(array $ids, string $lang, bool $full, bool $options = false): array
    {
        $want = [];
        $seen = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0 && !isset($seen[$id])) {
                $seen[$id] = true;
                $want[] = $id;
            }
        }
        if ($want === []) {
            return [];
        }
        $rows = [];
        foreach ($this->in('SELECT * FROM {products} WHERE id', $want) as $row) {
            $rows[(int) $row['id']] = $row;
        }
        $ids = [];
        foreach ($want as $id) {
            if (isset($rows[$id])) {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            return [];
        }
        $default = $this->app->defaultLang();

        // Textos: todos los idiomas, y se elige en PHP (el pedido, el principal o el que haya).
        $i18n = [];
        foreach ($this->in('SELECT * FROM {product_i18n} WHERE product_id', $ids) as $row) {
            $i18n[(int) $row['product_id']][(string) $row['lang']] = $row;
        }

        $variants = [];
        foreach ($this->in('SELECT * FROM {variants} WHERE active = 1 AND product_id', $ids, ' ORDER BY product_id, position, id') as $row) {
            $variants[(int) $row['product_id']][] = $row;
        }

        $mediaOrder = [];
        foreach ($this->in('SELECT product_id, media_id FROM {product_media} WHERE product_id', $ids, ' ORDER BY product_id, position, media_id') as $row) {
            $mediaOrder[(int) $row['product_id']][] = (int) $row['media_id'];
        }

        $brandIds = [];
        foreach ($rows as $row) {
            if (isset($row['brand_id']) && $row['brand_id'] !== null && (int) $row['brand_id'] > 0) {
                $brandIds[(int) $row['brand_id']] = true;
            }
        }
        $brands = [];
        if ($brandIds !== []) {
            foreach ($this->in('SELECT id, name FROM {brands} WHERE active = 1 AND id', array_keys($brandIds)) as $row) {
                $brands[(int) $row['id']] = ['id' => (int) $row['id'], 'name' => (string) $row['name']];
            }
        }

        // Imágenes: la principal primero y después las del producto por orden.
        $imageIds = [];
        $perProduct = [];
        foreach ($ids as $id) {
            $list = [];
            $main = isset($rows[$id]['main_media_id']) && $rows[$id]['main_media_id'] !== null ? (int) $rows[$id]['main_media_id'] : 0;
            if ($main > 0) {
                $list[] = $main;
            }
            foreach (isset($mediaOrder[$id]) ? $mediaOrder[$id] : [] as $mediaId) {
                if (!in_array($mediaId, $list, true)) {
                    $list[] = $mediaId;
                }
            }
            $perProduct[$id] = $list;
            foreach ($list as $mediaId) {
                $imageIds[$mediaId] = true;
            }
        }
        // La foto de cada variante: la necesita la ficha y, si la tarjeta deja elegir combinación ($options), también ella.
        // Van en la misma consulta de imágenes: no cuesta una más.
        if ($full || $options) {
            foreach ($variants as $list) {
                foreach ($list as $variant) {
                    if (isset($variant['media_id']) && $variant['media_id'] !== null && (int) $variant['media_id'] > 0) {
                        $imageIds[(int) $variant['media_id']] = true;
                    }
                }
            }
        }
        $found = Images::many($this->app, array_keys($imageIds));

        $bundles = [];
        foreach ($ids as $id) {
            $row = $rows[$id];
            $picked = Texts::pick(isset($i18n[$id]) ? $i18n[$id] : [], $lang, $default);
            $picked = $picked !== null ? $picked : [];
            $name = isset($picked['name']) ? trim((string) $picked['name']) : '';
            if ($name === '' && isset($i18n[$id][$default]['name'])) {
                $name = trim((string) $i18n[$id][$default]['name']);
            }
            $images = [];
            foreach ($perProduct[$id] as $mediaId) {
                if (!isset($found[$mediaId])) {
                    continue;
                }
                $image = $found[$mediaId];
                $images[] = $image['alt'] !== '' ? $image : array_merge($image, ['alt' => $name]);
            }
            $brandId = isset($row['brand_id']) && $row['brand_id'] !== null ? (int) $row['brand_id'] : 0;
            $bundles[$id] = [
                'row' => $row,
                'text' => [
                    'name' => $name,
                    'summary' => isset($picked['summary']) ? (string) $picked['summary'] : '',
                    'description' => isset($picked['description']) ? (string) $picked['description'] : '',
                    'facts' => isset($picked['facts']) ? (string) $picked['facts'] : '',
                    'meta_title' => isset($picked['meta_title']) ? (string) $picked['meta_title'] : '',
                    'meta_description' => isset($picked['meta_description']) ? (string) $picked['meta_description'] : '',
                ],
                'variants' => isset($variants[$id]) ? $variants[$id] : [],
                'images' => $images,
                'brand' => isset($brands[$brandId]) ? $brands[$brandId] : null,
                'variant_images' => $options ? self::variantImages(isset($variants[$id]) ? $variants[$id] : [], $found, $name) : [],
                'values' => [],
                'categories' => [],
                'tags' => [],
                'faqs' => [],
                'related' => [],
                'attrRows' => [],
            ];
        }
        if ($full) {
            $this->loadFull($bundles, $ids, $lang, $default);
        } elseif ($options) {
            // La tarjeta que deja elegir combinación necesita saber qué valores tiene cada variante: una consulta para todo el listado.
            $this->loadVariantValues($bundles);
        }

        // Las direcciones de todo lo que se va a enlazar, de una vez (y las del idioma principal, por si falta la traducción).
        $slugs = $this->app->slugs();
        $langs = array_values(array_unique([$lang, $default]));
        $categoryIds = [];
        foreach ($bundles as $bundle) {
            foreach ($bundle['categories'] as $category) {
                $categoryIds[(int) $category['id']] = true;
            }
        }
        foreach ($langs as $l) {
            $slugs->preload('product', $ids, $l);
            if ($brands !== []) {
                $slugs->preload('brand', array_keys($brands), $l);
            }
            if ($categoryIds !== []) {
                $slugs->preload('category', array_keys($categoryIds), $l);
            }
        }

        return $bundles;
    }

    /**
     * Qué valor de cada atributo tiene cada variante («values»: variante => atributo => valor), para todos los productos
     * de una vez. Sin variantes no pregunta nada.
     *
     * @param array<int,array<string,mixed>> $bundles
     */
    private function loadVariantValues(array &$bundles): void
    {
        $variantIds = [];
        foreach ($bundles as $bundle) {
            foreach ($bundle['variants'] as $variant) {
                $variantIds[(int) $variant['id']] = (int) $variant['product_id'];
            }
        }
        if ($variantIds === []) {
            return;
        }
        foreach ($this->in('SELECT variant_id, attribute_id, value_id FROM {variant_values} WHERE variant_id', array_keys($variantIds)) as $row) {
            $productId = $variantIds[(int) $row['variant_id']];
            $bundles[$productId]['values'][(int) $row['variant_id']][(int) $row['attribute_id']] = (int) $row['value_id'];
        }
    }

    /**
     * La foto propia de cada variante que la tiene (variante => imagen), con el nombre del producto como texto alternativo
     * si la foto no trae el suyo.
     *
     * @param array<int,array<string,mixed>> $variants
     * @param array<int,array<string,mixed>> $found    las imágenes cargadas, por identificador
     *
     * @return array<int,array<string,mixed>>
     */
    private static function variantImages(array $variants, array $found, string $name): array
    {
        $out = [];
        foreach ($variants as $variant) {
            $mediaId = isset($variant['media_id']) && $variant['media_id'] !== null ? (int) $variant['media_id'] : 0;
            if ($mediaId > 0 && isset($found[$mediaId])) {
                $image = $found[$mediaId];
                $out[(int) $variant['id']] = $image['alt'] !== '' ? $image : array_merge($image, ['alt' => $name]);
            }
        }

        return $out;
    }

    /**
     * Lo que solo necesita la ficha completa: valores de variantes, categorías, etiquetas, preguntas, relacionados y atributos.
     *
     * @param array<int,array<string,mixed>> $bundles
     * @param int[]                          $ids
     */
    private function loadFull(array &$bundles, array $ids, string $lang, string $default): void
    {
        $db = $this->app->db();

        $this->loadVariantValues($bundles);

        // Categorías (solo las activas; la principal primero) y sus nombres.
        $categories = [];
        $categoryIds = [];
        foreach ($this->in(
            'SELECT pc.product_id, pc.category_id, pc.is_main FROM {product_categories} pc'
            . ' JOIN {categories} c ON c.id = pc.category_id AND c.active = 1 WHERE pc.product_id',
            $ids,
            ' ORDER BY pc.product_id, pc.is_main DESC, pc.position, pc.category_id'
        ) as $row) {
            $categories[(int) $row['product_id']][] = ['id' => (int) $row['category_id'], 'is_main' => (int) $row['is_main'] === 1];
            $categoryIds[(int) $row['category_id']] = true;
        }
        $names = [];
        if ($categoryIds !== []) {
            foreach ($this->in('SELECT category_id, lang, name FROM {category_i18n} WHERE category_id', array_keys($categoryIds)) as $row) {
                $names[(int) $row['category_id']][(string) $row['lang']] = ['name' => (string) $row['name']];
            }
        }
        foreach ($categories as $productId => $list) {
            foreach ($list as $category) {
                $picked = Texts::pick(isset($names[$category['id']]) ? $names[$category['id']] : [], $lang, $default);
                $bundles[$productId]['categories'][] = [
                    'id' => $category['id'],
                    'name' => $picked !== null ? (string) $picked['name'] : '',
                    'is_main' => $category['is_main'],
                ];
            }
        }

        // Etiquetas: las del idioma pedido o, si el producto no tiene en él, las del principal.
        $tags = [];
        foreach ($this->in(
            'SELECT pt.product_id, t.name, t.lang FROM {product_tags} pt JOIN {tags} t ON t.id = pt.tag_id WHERE pt.product_id',
            $ids,
            ' ORDER BY pt.product_id, t.name'
        ) as $row) {
            $tags[(int) $row['product_id']][(string) $row['lang']][] = (string) $row['name'];
        }
        foreach ($tags as $productId => $byLang) {
            $list = isset($byLang[$lang]) ? $byLang[$lang] : (isset($byLang[$default]) ? $byLang[$default] : []);
            $bundles[$productId]['tags'] = $list;
        }

        // Preguntas frecuentes: por idioma, con el mismo respaldo.
        $faqs = [];
        foreach ($this->in('SELECT * FROM {product_faqs} WHERE product_id', $ids, ' ORDER BY product_id, position, id') as $row) {
            $faqs[(int) $row['product_id']][(string) $row['lang']][] = ['question' => (string) $row['question'], 'answer' => (string) $row['answer']];
        }
        foreach ($faqs as $productId => $byLang) {
            $bundles[$productId]['faqs'] = isset($byLang[$lang]) ? $byLang[$lang] : (isset($byLang[$default]) ? $byLang[$default] : []);
        }

        // Relacionados: solo los que se pueden ver.
        $params = [$this->app->clock()->sql()];
        $marks = $db->in($ids, $params);
        $sql = 'SELECT pr.product_id, pr.related_id FROM {product_relations} pr JOIN {products} r ON r.id = pr.related_id'
            . " WHERE pr.kind = 'related' AND r.status = 'published' AND (r.published_at IS NULL OR r.published_at <= ?)"
            . ' AND pr.product_id IN (' . $marks . ') ORDER BY pr.product_id, pr.position, pr.related_id';
        foreach ($db->select($sql, $params) as $row) {
            $bundles[(int) $row['product_id']]['related'][] = (int) $row['related_id'];
        }

        foreach ($this->in('SELECT * FROM {product_attributes} WHERE product_id', $ids, ' ORDER BY product_id, id') as $row) {
            $bundles[(int) $row['product_id']]['attrRows'][] = $row;
        }
    }

    /**
     * Ejecuta «<cabecera> IN (valores) <cola>» con los valores como parámetros.
     *
     * @param array<int,int> $values
     *
     * @return array<int,array<string,mixed>>
     */
    private function in(string $head, array $values, string $tail = ''): array
    {
        $db = $this->app->db();
        $params = [];
        $marks = $db->in($values, $params);

        return $db->select($head . ' IN (' . $marks . ')' . $tail, $params);
    }
}
