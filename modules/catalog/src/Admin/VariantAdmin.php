<?php
/**
 * AIFEC — variantes de un producto en el panel: combinaciones, estado del producto y consultas que comparte su pantalla.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Db\Db;
use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Attributes;

/**
 * Una variante es lo que de verdad se compra cuando un producto las tiene: sus valores (variant_values) dicen «Rojo / M».
 * «has_variants» del producto vale 1 mientras haya alguna variante activa; con él, las existencias del producto no se usan.
 */
final class VariantAdmin
{
    /** Tope de variantes por producto. */
    public const MAX = 200;

    /** Dirección de la pantalla de variantes de un producto. */
    public static function url(App $app, int $productId): string
    {
        return $app->url()->plain('/admin/catalog/products/' . $productId . '/variants');
    }

    /**
     * Atributos que sirven para crear variantes (de lista y que se eligen de uno en uno), con sus valores.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function attributes(App $app): array
    {
        $out = [];
        foreach ((new Attributes($app))->all($app->defaultLang()) as $id => $attr) {
            if ($attr['variant'] && in_array($attr['type'], AttributeAdmin::VARIANT_TYPES, true) && $attr['values'] !== []) {
                $out[$id] = $attr;
            }
        }

        return $out;
    }

    /**
     * Las variantes de un producto, en su orden, con sus valores y la etiqueta calculada.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function rows(App $app, int $productId): array
    {
        $db = $app->db();
        $attributes = new Attributes($app);
        $all = $attributes->all($app->defaultLang());
        $rows = $db->select('SELECT * FROM {variants} WHERE product_id = ? ORDER BY position, id', [$productId]);
        $maps = [];
        if ($rows !== []) {
            $ids = array_map(static function (array $row): int {
                return (int) $row['id'];
            }, $rows);
            $params = [];
            foreach ($db->select('SELECT variant_id, attribute_id, value_id FROM {variant_values} WHERE variant_id IN (' . $db->in($ids, $params) . ')', $params) as $value) {
                $maps[(int) $value['variant_id']][(int) $value['attribute_id']] = (int) $value['value_id'];
            }
        }
        foreach ($rows as $i => $row) {
            $id = (int) $row['id'];
            $rows[$i]['values'] = isset($maps[$id]) ? $maps[$id] : [];
            $rows[$i]['display'] = $attributes->variantLabel($row, $rows[$i]['values'], $all);
            $rows[$i]['in_orders'] = self::inOrders($app, $id);
        }

        return $rows;
    }

    /**
     * ¿Aparece esta variante en algún pedido? (Si el módulo de pedidos no está, no.)
     */
    public static function inOrders(App $app, int $variantId): bool
    {
        $db = $app->db();

        return $db->tableExists('order_items') && (int) $db->value('SELECT COUNT(*) FROM {order_items} WHERE variant_id = ?', [$variantId]) > 0;
    }

    /**
     * Crea las combinaciones que faltan. Cada atributo trae los valores elegidos; se combinan todos con todos. Una combinación
     * que ya existe (mismos atributos y mismos valores) no se repite; nunca se pasa de MAX variantes.
     *
     * @param array<int,int[]> $chosen id del atributo => ids de valores elegidos (ya comprobados)
     *
     * @return array{created:int,existing:int,capped:bool}
     */
    public static function generate(App $app, int $productId, array $chosen): array
    {
        $db = $app->db();
        $combos = self::combinations($chosen);

        $have = [];
        $count = 0;
        foreach (self::rows($app, $productId) as $row) {
            $have[self::key($row['values'])] = true;
            $count++;
        }
        $created = 0;
        $existing = 0;
        $capped = false;
        $now = $app->clock()->sql();
        $db->transaction(function (Db $db) use ($productId, $combos, &$have, &$count, &$created, &$existing, &$capped, $now): void {
            foreach ($combos as $combo) {
                if (isset($have[self::key($combo)])) {
                    $existing++;
                    continue;
                }
                if ($count >= self::MAX) {
                    $capped = true;
                    continue;
                }
                $variantId = $db->insert('variants', [
                    'product_id' => $productId, 'sku' => null, 'gtin' => null, 'price' => null, 'compare_price' => null, 'cost' => null,
                    'stock' => 0, 'weight' => null, 'media_id' => null, 'label' => null, 'position' => $count, 'active' => 1,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                foreach ($combo as $attributeId => $valueId) {
                    $db->insert('variant_values', ['variant_id' => $variantId, 'attribute_id' => (int) $attributeId, 'value_id' => (int) $valueId]);
                }
                $have[self::key($combo)] = true;
                $count++;
                $created++;
            }
        });
        self::refresh($app, $productId);

        return ['created' => $created, 'existing' => $existing, 'capped' => $capped];
    }

    /**
     * Todas las combinaciones de lo elegido (cada atributo con cada valor), en orden.
     *
     * @param array<int,int[]> $chosen id del atributo => ids de valores
     *
     * @return array<int,array<int,int>> lista de combinaciones: id del atributo => id del valor
     */
    public static function combinations(array $chosen): array
    {
        $combos = [[]];
        foreach ($chosen as $attributeId => $valueIds) {
            $next = [];
            foreach ($combos as $combo) {
                foreach ($valueIds as $valueId) {
                    $next[] = $combo + [(int) $attributeId => (int) $valueId];
                }
            }
            $combos = $next;
        }

        return $combos;
    }

    /**
     * La tabla de combinaciones que saldrían, sin crear nada: cada una con su nombre y si ya existe en el producto. Pasa de
     * MAX igual que generate(): las que no caben salen marcadas.
     *
     * @param array<int,int[]> $chosen id del atributo => ids de valores (ya comprobados)
     *
     * @return array{rows:array<int,array{label:string,exists:bool,capped:bool}>,new:int,existing:int,capped:int}
     */
    public static function preview(App $app, int $productId, array $chosen): array
    {
        $attributes = new Attributes($app);
        $all = $attributes->all($app->defaultLang());
        $have = [];
        $count = 0;
        foreach (self::rows($app, $productId) as $row) {
            $have[self::key($row['values'])] = true;
            $count++;
        }
        $rows = [];
        $new = 0;
        $existing = 0;
        $capped = 0;
        foreach (self::combinations($chosen) as $combo) {
            $label = $attributes->variantLabel(['id' => 0, 'label' => null, 'sku' => null], $combo, $all);
            if (isset($have[self::key($combo)])) {
                $rows[] = ['label' => $label, 'exists' => true, 'capped' => false];
                $existing++;
            } elseif ($count >= self::MAX) {
                $rows[] = ['label' => $label, 'exists' => false, 'capped' => true];
                $capped++;
            } else {
                $rows[] = ['label' => $label, 'exists' => false, 'capped' => false];
                $count++;
                $new++;
            }
        }

        return ['rows' => $rows, 'new' => $new, 'existing' => $existing, 'capped' => $capped];
    }

    /**
     * Los atributos con los que se pueden escribir combinaciones, con todos los nombres que se les conocen (en cada idioma de la
     * tienda, el código y los sinónimos corrientes) para que «tallas» o «sizes» encuentren «Talla».
     *
     * @return array<int,array<string,mixed>> id => ['id','name','code','aliases','values' => [id => ['label','code','aliases']]]
     */
    public static function parserAttributes(App $app): array
    {
        $attributes = new Attributes($app);
        $out = [];
        foreach (self::attributes($app) as $id => $attr) {
            $aliases = [];
            $values = [];
            foreach ($attr['values'] as $valueId => $value) {
                $values[$valueId] = ['label' => (string) $value['label'], 'code' => (string) $value['code'], 'aliases' => []];
            }
            foreach ($app->languages() as $lang) {
                $other = $attributes->all($lang);
                if (!isset($other[$id])) {
                    continue;
                }
                $aliases[] = (string) $other[$id]['name'];
                foreach ($other[$id]['values'] as $valueId => $value) {
                    if (isset($values[$valueId])) {
                        $values[$valueId]['aliases'][] = (string) $value['label'];
                    }
                }
            }
            $code = strtolower((string) $attr['code']);
            if (strpos($code, 'talla') !== false || strpos($code, 'size') !== false) {
                $aliases = array_merge($aliases, ['talla', 'size', 'tamano', 'medida']);
            }
            if (strpos($code, 'color') !== false || strpos($code, 'colour') !== false) {
                $aliases = array_merge($aliases, ['color', 'colour', 'colores']);
            }
            $out[$id] = ['id' => (int) $id, 'name' => (string) $attr['name'], 'code' => (string) $attr['code'], 'aliases' => $aliases, 'values' => $values];
        }

        return $out;
    }

    /**
     * Pone «has_variants» según haya variantes activas y vacía la caché del producto.
     */
    public static function refresh(App $app, int $productId): void
    {
        $db = $app->db();
        $active = (int) $db->value('SELECT COUNT(*) FROM {variants} WHERE product_id = ? AND active = 1', [$productId]);
        $db->update('products', ['has_variants' => $active > 0 ? 1 : 0, 'updated_at' => $app->clock()->sql()], ['id' => $productId]);
        $app->invalidate(['product:' . $productId, 'catalog']);
    }

    /**
     * Identifica una combinación: «3:10,4:22» (atributo:valor, por atributo).
     *
     * @param array<int,int> $values id del atributo => id del valor
     */
    private static function key(array $values): string
    {
        ksort($values);
        $parts = [];
        foreach ($values as $attributeId => $valueId) {
            $parts[] = $attributeId . ':' . $valueId;
        }

        return implode(',', $parts);
    }
}
