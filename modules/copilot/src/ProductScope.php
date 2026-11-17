<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Copilot;

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Categories;

/**
 * El conjunto de productos que nombra una orden: un producto (por referencia o nombre), una categoría con sus subcategorías,
 * una marca o una búsqueda por palabras. Si se dan varios criterios, valen los productos que cumplen todos.
 *
 * resolve() no elige entre dos lecturas que valen: si un nombre encaja con varias categorías, marcas o productos distintos,
 * devuelve las opciones y ningún producto.
 */
final class ProductScope
{
    /** Tope de lo que se recorre cuando hay que cruzar varios criterios. */
    private const CROSS_MAX = 5000;

    /**
     * @param array<string,mixed> $args product, category, brand, query
     * @param int                 $limit hasta cuántos identificadores devuelve (uno más que el tope para saber que se pasa)
     *
     * @return array{ids:int[],label:string,errors:string[],choices:array<int,array<string,string>>,given:bool}
     */
    public static function resolve(App $app, array $args, int $limit): array
    {
        $errors = [];
        $choices = [];
        $labels = [];
        $sets = [];
        $given = false;
        $fetch = count(array_filter([isset($args['product']), isset($args['category']), isset($args['brand']), isset($args['query'])])) > 1 ? self::CROSS_MAX : $limit;

        if (isset($args['product'])) {
            $given = true;
            $candidates = Finder::products($app, (string) $args['product'], 8);
            $ids = [];
            foreach ($candidates as $candidate) {
                $ids[$candidate['id']] = $candidate;
            }
            if ($ids === []) {
                $errors[] = t('No product matches «{text}».', ['text' => (string) $args['product']]);
            } elseif (count($ids) > 1) {
                $errors[] = t('Several products match «{text}». Choose one.', ['text' => (string) $args['product']]);
                foreach ($ids as $candidate) {
                    $choices[] = ['param' => 'product', 'value' => '#' . $candidate['id'], 'label' => $candidate['name'] . ($candidate['sku'] !== '' ? ' (' . $candidate['sku'] . ')' : '')];
                }
            } else {
                $one = reset($ids);
                $sets[] = [(int) $one['id']];
                $labels[] = $one['name'];
            }
        }

        if (isset($args['category'])) {
            $given = true;
            $rows = Finder::categories($app, (string) $args['category']);
            if ($rows === []) {
                $errors[] = t('No category matches «{text}».', ['text' => (string) $args['category']]);
            } elseif (count($rows) > 1) {
                $errors[] = t('Several categories match «{text}». Choose one.', ['text' => (string) $args['category']]);
                foreach ($rows as $row) {
                    $choices[] = ['param' => 'category', 'value' => '#' . $row['id'], 'label' => $row['name']];
                }
            } else {
                $sets[] = self::inCategory($app, $rows[0], $fetch);
                $labels[] = t('category «{name}»', ['name' => $rows[0]['name']]);
            }
        }

        if (isset($args['brand'])) {
            $given = true;
            $rows = Finder::brands($app, (string) $args['brand']);
            if ($rows === []) {
                $errors[] = t('No brand matches «{text}».', ['text' => (string) $args['brand']]);
            } elseif (count($rows) > 1) {
                $errors[] = t('Several brands match «{text}». Choose one.', ['text' => (string) $args['brand']]);
                foreach ($rows as $row) {
                    $choices[] = ['param' => 'brand', 'value' => '#' . $row['id'], 'label' => $row['name']];
                }
            } else {
                $ids = [];
                foreach ($app->db()->select('SELECT id FROM {products} WHERE brand_id = ? ORDER BY id LIMIT ?', [(int) $rows[0]['id'], $fetch]) as $row) {
                    $ids[] = (int) $row['id'];
                }
                $sets[] = $ids;
                $labels[] = t('brand «{name}»', ['name' => $rows[0]['name']]);
            }
        }

        if (isset($args['query'])) {
            $given = true;
            $words = Finder::words((string) $args['query']);
            $ids = Finder::idsByName($app, $words, $fetch);
            if ($ids === []) {
                $ids = Finder::idsByScan($app, $words, $fetch);
            }
            $sets[] = $ids;
            $labels[] = t('search «{text}»', ['text' => (string) $args['query']]);
        }

        $ids = [];
        if ($errors === [] && $sets !== []) {
            $ids = $sets[0];
            foreach (array_slice($sets, 1) as $set) {
                $ids = array_values(array_intersect($ids, $set));
            }
            sort($ids);
            $ids = array_slice(array_values(array_unique($ids)), 0, $limit);
        }

        return ['ids' => $ids, 'label' => implode(', ', $labels), 'errors' => $errors, 'choices' => $choices, 'given' => $given];
    }

    /**
     * Los productos de una categoría y de todas las que cuelgan de ella.
     *
     * @param array<string,mixed> $category id, path
     *
     * @return int[]
     */
    private static function inCategory(App $app, array $category, int $limit): array
    {
        $db = $app->db();
        $path = trim((string) $category['path'], '/');
        $path = $path === '' ? (string) $category['id'] : Categories::pathOf(['id' => $category['id'], 'path' => $path]);
        $categoryIds = [(int) $category['id']];
        foreach ($db->select('SELECT id FROM {categories} WHERE path LIKE ?' . Like::ESC . ' ORDER BY id', [Like::escape($path) . '/%']) as $row) {
            $categoryIds[] = (int) $row['id'];
        }
        $params = [];
        $in = $db->in($categoryIds, $params);
        $params[] = $limit;
        $ids = [];
        foreach ($db->select('SELECT DISTINCT product_id FROM {product_categories} WHERE category_id IN (' . $in . ') ORDER BY product_id LIMIT ?', $params) as $row) {
            $ids[] = (int) $row['product_id'];
        }

        return $ids;
    }
}
