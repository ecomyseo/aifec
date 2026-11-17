<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Front;

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Catalog;

/**
 * Lo que el catálogo aporta a la portada, por el filtro «home.sections»: Destacados (orden 20), Categorías (30), Novedades (40) y
 * Ofertas (50). Cada sección solo sale si tiene algo que enseñar; la portada entera va a la caché de página y se renueva con
 * la etiqueta «catalog».
 */
final class HomeSections
{
    /** Productos por sección. */
    private const ITEMS = 8;

    /** Categorías de primer nivel que se enseñan como mucho. */
    private const CATEGORIES = 12;

    /**
     * El filtro «home.sections».
     *
     * @param mixed $sections lo que ya hay: [['id', 'order', 'html']]
     * @param mixed $app
     *
     * @return array<int,array<string,mixed>>
     */
    public static function add($sections, $app): array
    {
        $sections = is_array($sections) ? array_values($sections) : [];
        if (!($app instanceof App) || !$app->has('catalog')) {
            return $sections;
        }
        /** @var Catalog $catalog */
        $catalog = $app->service('catalog');

        $products = [
            ['id' => 'catalog.featured', 'order' => 20, 'title' => t('Featured products'), 'query' => ['featured' => true]],
            ['id' => 'catalog.new', 'order' => 40, 'title' => t('New arrivals'), 'query' => ['sort' => 'newest']],
            ['id' => 'catalog.sale', 'order' => 50, 'title' => t('On sale'), 'query' => ['on_sale' => true]],
        ];
        // El mosaico, de la caché del sitio (etiqueta «catalog»): se pide en cada portada y casi nunca cambia.
        $categories = $app->cache()->remember('home:categories:' . $app->lang(), 600, static function () use ($app, $catalog): array {
            return self::categories($app, $catalog);
        }, ['catalog']);
        $categories = is_array($categories) ? $categories : [];
        if ($categories !== []) {
            $sections[] = [
                'id' => 'catalog.categories',
                'order' => 30,
                'html' => $app->view()->render('catalog::front/home/categories', ['title' => t('Shop by category'), 'categories' => $categories]),
            ];
        }
        foreach ($products as $section) {
            // Qué productos van en cada sección, de la caché del sitio (etiqueta «catalog»): un listado entero cuesta cinco
            // consultas (recuento, ids y facetas que la portada no enseña). Las tarjetas salen de la caché de tarjetas.
            $query = $section['query'];
            $ids = $app->cache()->remember('home:section:' . $section['id'] . ':' . $app->lang(), 600, static function () use ($catalog, $query): array {
                $result = $catalog->listing($query + ['per_page' => self::ITEMS]);
                $out = [];
                foreach (array_slice(is_array($result['items']) ? $result['items'] : [], 0, self::ITEMS) as $card) {
                    if (is_array($card) && isset($card['id'])) {
                        $out[] = (int) $card['id'];
                    }
                }

                return $out;
            }, ['catalog']);
            $cards = is_array($ids) && $ids !== [] ? $catalog->cards(array_map('intval', $ids)) : [];
            $items = [];
            foreach (is_array($ids) ? $ids : [] as $id) {
                if (isset($cards[(int) $id])) {
                    $items[] = $cards[(int) $id];
                }
            }
            if ($items === []) {
                continue;
            }
            $sections[] = [
                'id' => $section['id'],
                'order' => $section['order'],
                'html' => $app->view()->render('catalog::front/home/products', ['id' => $section['id'], 'title' => $section['title'], 'items' => $items]),
            ];
        }

        return $sections;
    }

    /**
     * Las categorías de primer nivel (o las hijas, si la tienda tiene UNA sola principal) con imagen: la suya o, si no
     * tiene, la del primer producto de ella que se puede comprar (P100: el mosaico no se queda vacío por falta de fotos de
     * categoría). Una categoría sin ninguna de las dos no sale.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function categories(App $app, Catalog $catalog): array
    {
        $nodes = $catalog->tree();
        if (count($nodes) === 1 && isset($nodes[0]['children']) && is_array($nodes[0]['children']) && $nodes[0]['children'] !== []) {
            $nodes = $nodes[0]['children'];
        }
        $nodes = array_slice(array_values(array_filter($nodes, 'is_array')), 0, self::CATEGORIES);
        $chosen = [];
        $missing = [];
        foreach ($nodes as $root) {
            $category = $catalog->category((int) $root['id']);
            if ($category === null) {
                continue;
            }
            $chosen[(int) $root['id']] = $category;
            if (!is_array($category['image']) || empty($category['image']['url'])) {
                $missing[(int) $root['id']] = $root;
            }
        }
        $photos = $missing !== [] ? self::productImages($app, $catalog, $missing) : [];
        $out = [];
        foreach ($chosen as $id => $category) {
            $image = is_array($category['image']) && !empty($category['image']['url']) ? $category['image'] : (isset($photos[$id]) ? $photos[$id] : null);
            if ($image !== null) {
                $out[] = ['name' => (string) $category['name'], 'url' => (string) $category['url'], 'image' => $image];
            }
        }

        return $out;
    }

    /**
     * La foto del primer producto que se puede comprar de cada una de estas categorías (o de sus hijas), con UNA consulta
     * agrupada para todas: los candidatos publicados y con foto, primero los que tienen existencias; después se miran sus
     * tarjetas (de la caché de tarjetas) y gana el primero que se puede comprar. Sin ninguno comprable, el primero con foto.
     *
     * @param array<int,array<string,mixed>> $nodes id de la categoría => nodo del árbol (con sus hijas)
     *
     * @return array<int,array<string,mixed>> id de la categoría => imagen
     */
    private static function productImages(App $app, Catalog $catalog, array $nodes): array
    {
        // Cada categoría del subárbol apunta a la de primer nivel que la contiene.
        $owner = [];
        $walk = static function (array $list, int $top) use (&$walk, &$owner): void {
            foreach ($list as $node) {
                if (is_array($node) && isset($node['id'])) {
                    $owner[(int) $node['id']] = $top;
                    $walk(isset($node['children']) && is_array($node['children']) ? $node['children'] : [], $top);
                }
            }
        };
        foreach ($nodes as $id => $node) {
            $owner[(int) $id] = (int) $id;
            $walk(isset($node['children']) && is_array($node['children']) ? $node['children'] : [], (int) $id);
        }
        $ids = array_keys($owner);
        $marks = implode(', ', array_fill(0, count($ids), '?'));
        $rows = $app->db()->select(
            'SELECT pc.category_id AS category_id, pc.product_id AS product_id FROM {product_categories} pc'
            . ' JOIN {products} p ON p.id = pc.product_id'
            . ' WHERE pc.category_id IN (' . $marks . ') AND p.status = ? AND p.visibility IN (?, ?)'
            . ' AND (p.published_at IS NULL OR p.published_at <= ?)'
            . ' AND EXISTS (SELECT 1 FROM {product_media} m WHERE m.product_id = p.id)'
            . ' ORDER BY CASE WHEN p.has_variants = 1 OR p.manage_stock = 0 OR p.stock > 0 THEN 0 ELSE 1 END, p.position, p.id LIMIT 200',
            array_merge($ids, ['published', 'everywhere', 'catalog', $app->clock()->sql()])
        );
        // Tres candidatos por categoría como mucho: lo justo para saltar los que un plugin deja sin comprar.
        $candidates = [];
        $all = [];
        foreach ($rows as $row) {
            $top = isset($owner[(int) $row['category_id']]) ? $owner[(int) $row['category_id']] : 0;
            $product = (int) $row['product_id'];
            if ($top > 0 && (!isset($candidates[$top]) || count($candidates[$top]) < 3) && !in_array($product, isset($candidates[$top]) ? $candidates[$top] : [], true)) {
                $candidates[$top][] = $product;
                $all[$product] = true;
            }
        }
        $cards = $all !== [] ? $catalog->cards(array_keys($all)) : [];
        $out = [];
        foreach ($candidates as $top => $products) {
            $fallback = null;
            foreach ($products as $product) {
                $card = isset($cards[$product]) && is_array($cards[$product]) ? $cards[$product] : null;
                if ($card === null || !isset($card['image']) || !is_array($card['image']) || empty($card['image']['url'])) {
                    continue;
                }
                $image = $card['image'];
                $image['alt'] = '';
                $availability = isset($card['availability']) && is_array($card['availability']) ? $card['availability'] : [];
                $buyable = (!array_key_exists('purchasable', $availability) || !empty($availability['purchasable']))
                    && (!isset($availability['status']) || (string) $availability['status'] !== 'out_of_stock');
                if ($buyable) {
                    $out[$top] = $image;
                    break;
                }
                if ($fallback === null) {
                    $fallback = $image;
                }
            }
            if (!isset($out[$top]) && $fallback !== null) {
                $out[$top] = $fallback;
            }
        }

        return $out;
    }
}
