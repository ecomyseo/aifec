<?php
/**
 * AIFEC — exportación del catálogo a CSV: una fila por producto y una por variante, por tandas y sin cargarlo todo en memoria.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Util\Csv;

/**
 * Lo que se exporta es lo guardado, tal como está (los importes, con coma decimal; el peso, en gramos), de modo que el mismo
 * fichero vuelve a entrar sin cambiar nada. Las columnas son las de CsvColumns.
 */
final class ProductExporter
{
    /** Productos que se leen de golpe. */
    private const BATCH = 100;

    /** @var App */
    private $app;

    /** @var Db */
    private $db;

    /** @var string */
    private $lang;

    /** @var array<int,array{code:string,type:string}>|null */
    private $attributes;

    /** @var array<int,string>|null id del valor => texto en el idioma principal (o su código) */
    private $valueLabels;

    /** @var array<int,array{parent:?int,name:string}>|null */
    private $categories;

    /** @var array<int,string>|null */
    private $brands;

    /** @var array<int,string>|null */
    private $taxClasses;

    public function __construct(App $app)
    {
        $this->app = $app;
        $this->db = $app->db();
        $this->lang = $app->defaultLang();
    }

    /**
     * Escribe el fichero (UTF-8 con marca BOM y «;» como separador, que es lo que abre bien una hoja de cálculo en castellano).
     * Devuelve cuántas filas ha escrito.
     */
    public function export(string $path): int
    {
        return Csv::write($path, CsvColumns::exportHeader($this->app), $this->rows(), ';', true);
    }

    /**
     * Las filas, una a una; cada una es un array con el nombre de la columna como clave.
     *
     * @return \Generator<int,array<string,string>>
     */
    public function rows(): \Generator
    {
        $last = 0;
        while (true) {
            $ids = array_map('intval', $this->db->column('SELECT id FROM {products} WHERE id > ? ORDER BY id LIMIT ?', [$last, self::BATCH]));
            if ($ids === []) {
                return;
            }
            $last = $ids[count($ids) - 1];
            $batch = $this->load($ids);
            foreach ($ids as $id) {
                if (!isset($batch['products'][$id])) {
                    continue;
                }
                yield $this->productRow($batch, $id);
                foreach (isset($batch['variants'][$id]) ? $batch['variants'][$id] : [] as $variant) {
                    yield $this->variantRow($batch, $batch['products'][$id], $variant);
                }
            }
        }
    }

    // ------------------------------------------------------------------ lectura por tandas

    /**
     * @param int[] $ids
     *
     * @return array<string,array<int|string,mixed>>
     */
    private function load(array $ids): array
    {
        $db = $this->db;
        $out = ['products' => [], 'texts' => [], 'variants' => [], 'variantValues' => [], 'attributes' => [], 'categories' => [], 'images' => []];
        $params = [];
        $in = $db->in($ids, $params);
        foreach ($db->select('SELECT * FROM {products} WHERE id IN (' . $in . ')', $params) as $row) {
            $out['products'][(int) $row['id']] = $row;
        }
        foreach ($db->select('SELECT product_id, lang, name, summary, description, meta_title, meta_description FROM {product_i18n} WHERE product_id IN (' . $in . ')', $params) as $row) {
            $out['texts'][(int) $row['product_id']][(string) $row['lang']] = $row;
        }
        $variantIds = [];
        foreach ($db->select('SELECT * FROM {variants} WHERE product_id IN (' . $in . ') ORDER BY product_id, position, id', $params) as $row) {
            $out['variants'][(int) $row['product_id']][] = $row;
            $variantIds[] = (int) $row['id'];
        }
        if ($variantIds !== []) {
            $vParams = [];
            $marks = $db->in($variantIds, $vParams);
            foreach ($db->select('SELECT variant_id, attribute_id, value_id FROM {variant_values} WHERE variant_id IN (' . $marks . ')', $vParams) as $row) {
                $out['variantValues'][(int) $row['variant_id']][(int) $row['attribute_id']] = (int) $row['value_id'];
            }
        }
        foreach ($db->select('SELECT product_id, attribute_id, value_id, lang, value_text, value_number FROM {product_attributes} WHERE product_id IN (' . $in . ') ORDER BY id', $params) as $row) {
            $out['attributes'][(int) $row['product_id']][(int) $row['attribute_id']][] = $row;
        }
        foreach ($db->select('SELECT product_id, category_id FROM {product_categories} WHERE product_id IN (' . $in . ') ORDER BY product_id, is_main DESC, position', $params) as $row) {
            $out['categories'][(int) $row['product_id']][] = (int) $row['category_id'];
        }
        if ($db->tableExists('product_media')) {
            foreach ($db->select('SELECT product_id, media_id FROM {product_media} WHERE product_id IN (' . $in . ') AND variant_id IS NULL ORDER BY product_id, position', $params) as $row) {
                $out['images'][(int) $row['product_id']][] = (int) $row['media_id'];
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ filas

    /**
     * @param array<string,array<int|string,mixed>> $batch
     *
     * @return array<string,string>
     */
    private function productRow(array $batch, int $id): array
    {
        $p = $batch['products'][$id];
        $texts = isset($batch['texts'][$id]) ? $batch['texts'][$id] : [];
        $main = isset($texts[$this->lang]) ? $texts[$this->lang] : [];
        $row = [
            'sku' => self::text($p['sku']),
            'parent_sku' => '',
            'name' => self::text(isset($main['name']) ? $main['name'] : null),
            'summary' => self::text(isset($main['summary']) ? $main['summary'] : null),
            'description' => self::text(isset($main['description']) ? $main['description'] : null),
            'price' => Amounts::format((int) $p['price']),
            'compare_price' => $p['compare_price'] !== null ? Amounts::format((int) $p['compare_price']) : '',
            'sale_price' => $p['sale_price'] !== null ? Amounts::format((int) $p['sale_price']) : '',
            'stock' => (int) $p['has_variants'] === 1 || (int) $p['manage_stock'] === 0 ? '' : (string) (int) $p['stock'],
            'status' => (string) $p['status'],
            'categories' => $this->categoryPaths(isset($batch['categories'][$id]) ? $batch['categories'][$id] : []),
            'brand' => $p['brand_id'] !== null ? $this->brandName((int) $p['brand_id']) : '',
            'tax_class' => $p['tax_class_id'] !== null ? $this->taxClassCode((int) $p['tax_class_id']) : '',
            'weight' => (string) (int) $p['weight'],
            'gtin' => self::text($p['gtin']),
            'mpn' => self::text($p['mpn']),
            'images' => $this->imageUrls(isset($batch['images'][$id]) ? $batch['images'][$id] : []),
            'meta_title' => self::text(isset($main['meta_title']) ? $main['meta_title'] : null),
            'meta_description' => self::text(isset($main['meta_description']) ? $main['meta_description'] : null),
        ];
        foreach ($this->languages() as $lang) {
            $row['name:' . $lang] = isset($texts[$lang]) ? self::text($texts[$lang]['name']) : '';
        }
        foreach (isset($batch['attributes'][$id]) ? $batch['attributes'][$id] : [] as $attributeId => $values) {
            $attr = $this->attributeInfo((int) $attributeId);
            if ($attr !== null) {
                $row['attribute:' . $attr['code']] = $this->attributeText($attr['type'], $values);
            }
        }

        return $row;
    }

    /**
     * @param array<string,array<int|string,mixed>> $batch
     * @param array<string,mixed>                   $product
     * @param array<string,mixed>                   $variant
     *
     * @return array<string,string>
     */
    private function variantRow(array $batch, array $product, array $variant): array
    {
        $row = [
            'sku' => self::text($variant['sku']),
            'parent_sku' => self::text($product['sku']),
            'name' => self::text($variant['label']),
            'price' => $variant['price'] !== null ? Amounts::format((int) $variant['price']) : '',
            'compare_price' => $variant['compare_price'] !== null ? Amounts::format((int) $variant['compare_price']) : '',
            'stock' => (int) $product['manage_stock'] === 0 ? '' : (string) (int) $variant['stock'],
            'weight' => $variant['weight'] !== null ? (string) (int) $variant['weight'] : '',
            'gtin' => self::text($variant['gtin']),
        ];
        $values = isset($batch['variantValues'][(int) $variant['id']]) ? $batch['variantValues'][(int) $variant['id']] : [];
        foreach ($values as $attributeId => $valueId) {
            $attr = $this->attributeInfo((int) $attributeId);
            if ($attr !== null) {
                $row['attribute:' . $attr['code']] = $this->valueLabel((int) $valueId);
            }
        }

        return $row;
    }

    /**
     * @param array<int,array<string,mixed>> $rows las filas de product_attributes de un atributo
     */
    private function attributeText(string $type, array $rows): string
    {
        $out = [];
        foreach ($rows as $row) {
            if ($type === 'select' || $type === 'color' || $type === 'multiselect') {
                if ($row['value_id'] !== null) {
                    $out[] = $this->valueLabel((int) $row['value_id']);
                }
            } elseif ($type === 'number') {
                $out[] = Amounts::showDecimal((string) $row['value_number']);
            } elseif ($type === 'bool') {
                $out[] = (int) $row['value_number'] === 1 ? '1' : '0';
            } elseif ($row['lang'] === null || $row['lang'] === $this->lang) {
                $out[] = (string) $row['value_text'];
            }
        }

        return implode('|', $out);
    }

    // ------------------------------------------------------------------ nombres

    /** @return string[] los idiomas que no son el principal */
    private function languages(): array
    {
        return array_values(array_filter($this->app->languages(), function (string $lang): bool {
            return $lang !== $this->lang;
        }));
    }

    /**
     * @param mixed $value
     */
    private static function text($value): string
    {
        return $value === null ? '' : (string) $value;
    }

    /** @return array{code:string,type:string}|null */
    private function attributeInfo(int $id): ?array
    {
        if ($this->attributes === null) {
            $this->attributes = [];
            foreach ($this->db->select('SELECT id, code, type FROM {attributes}') as $row) {
                $this->attributes[(int) $row['id']] = ['code' => (string) $row['code'], 'type' => (string) $row['type']];
            }
        }

        return isset($this->attributes[$id]) ? $this->attributes[$id] : null;
    }

    private function valueLabel(int $id): string
    {
        if ($this->valueLabels === null) {
            $this->valueLabels = [];
            $rows = $this->db->select(
                'SELECT v.id, v.code, i.label FROM {attribute_values} v LEFT JOIN {attribute_value_i18n} i ON i.value_id = v.id AND i.lang = ?',
                [$this->lang]
            );
            foreach ($rows as $row) {
                $this->valueLabels[(int) $row['id']] = $row['label'] !== null && $row['label'] !== '' ? (string) $row['label'] : (string) $row['code'];
            }
        }

        return isset($this->valueLabels[$id]) ? $this->valueLabels[$id] : '';
    }

    /**
     * @param int[] $ids
     */
    private function categoryPaths(array $ids): string
    {
        if ($this->categories === null) {
            $this->categories = [];
            $rows = $this->db->select(
                'SELECT c.id, c.parent_id, i.name FROM {categories} c LEFT JOIN {category_i18n} i ON i.category_id = c.id AND i.lang = ?',
                [$this->lang]
            );
            foreach ($rows as $row) {
                $this->categories[(int) $row['id']] = ['parent' => $row['parent_id'] !== null ? (int) $row['parent_id'] : null, 'name' => (string) $row['name']];
            }
        }
        $paths = [];
        foreach ($ids as $id) {
            $names = [];
            $guard = 0;
            for ($cursor = $id; $cursor !== null && isset($this->categories[$cursor]) && $guard < 20; ++$guard) {
                array_unshift($names, $this->categories[$cursor]['name']);
                $cursor = $this->categories[$cursor]['parent'];
            }
            if ($names !== []) {
                $paths[] = implode(' > ', $names);
            }
        }

        return implode('|', $paths);
    }

    private function brandName(int $id): string
    {
        if ($this->brands === null) {
            $this->brands = [];
            foreach ($this->db->select('SELECT id, name FROM {brands}') as $row) {
                $this->brands[(int) $row['id']] = (string) $row['name'];
            }
        }

        return isset($this->brands[$id]) ? $this->brands[$id] : '';
    }

    private function taxClassCode(int $id): string
    {
        if ($this->taxClasses === null) {
            $this->taxClasses = [];
            if ($this->db->tableExists('tax_classes')) {
                foreach ($this->db->select('SELECT id, code FROM {tax_classes}') as $row) {
                    $this->taxClasses[(int) $row['id']] = (string) $row['code'];
                }
            }
        }

        return isset($this->taxClasses[$id]) ? $this->taxClasses[$id] : '';
    }

    /**
     * @param int[] $mediaIds
     */
    private function imageUrls(array $mediaIds): string
    {
        if ($mediaIds === [] || !$this->app->has('media')) {
            return '';
        }
        $urls = [];
        foreach ($mediaIds as $mediaId) {
            $url = (string) $this->app->service('media')->url($mediaId);
            if ($url !== '') {
                $urls[] = $this->app->url()->origin() . $url;
            }
        }

        return implode('|', $urls);
    }
}
