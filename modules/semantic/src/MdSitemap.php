<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Kernel\App;

/**
 * El mapa de las versiones en texto (/sitemap-md.xml): una dirección «.md» por cada página pública que tiene versión en texto (la
 * portada, el índice del blog, categorías, marcas, productos, páginas y entradas), en cada idioma en que existe. Las páginas sin
 * versión en texto (carrito, cuenta, pedido) no salen.
 */
final class MdSitemap
{
    /** Lo más que cabe en un mapa (el límite del protocolo es 50.000). */
    public const MAX = 45000;

    /**
     * Todas las direcciones «.md», sin repetir y en orden.
     *
     * @return string[]
     */
    public static function urls(App $app): array
    {
        $out = [];
        $add = static function (string $url) use (&$out): void {
            if ($url !== '' && count($out) < self::MAX) {
                $out[$url] = true;
            }
        };
        foreach ($app->languages() as $lang) {
            $add($app->url()->origin() . $app->url()->to('/index.md', [], $lang));
        }
        if ($app->router()->has('blog')) {
            $add($app->url()->origin() . $app->url()->route('blog') . '.md');
        }
        foreach (self::ids($app) as $type => $ids) {
            foreach (array_chunk($ids, 400) as $chunk) {
                foreach ($app->languages() as $lang) {
                    $app->slugs()->preload($type, $chunk, $lang);
                }
                foreach ($chunk as $id) {
                    foreach ($app->languages() as $lang) {
                        if ($app->slugs()->path($type, $id, $lang) !== null) {
                            $add(Addresses::entity($app, $type, $id, $lang));
                        }
                    }
                }
            }
        }

        return array_keys($out);
    }

    /** El documento XML. */
    public static function xml(App $app): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach (self::urls($app) as $url) {
            $xml .= '  <url><loc>' . htmlspecialchars($url, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc></url>\n";
        }

        return $xml . "</urlset>\n";
    }

    /**
     * Los identificadores de lo que tiene versión en texto: tipo de entidad => ids.
     *
     * @return array<string,int[]>
     */
    private static function ids(App $app): array
    {
        $db = $app->db();
        $out = [];
        $limit = self::MAX;
        if ($app->has('catalog')) {
            $out['category'] = self::column($db->select('SELECT id FROM {categories} WHERE active = 1 ORDER BY id LIMIT ?', [$limit]));
            $out['brand'] = self::column($db->select('SELECT id FROM {brands} WHERE active = 1 ORDER BY id LIMIT ?', [$limit]));
            $out['product'] = self::column($db->select("SELECT id FROM {products} WHERE status = 'published' AND visibility <> 'hidden' ORDER BY id LIMIT ?", [$limit]));
        }
        if ($app->has('cms')) {
            list($visible, $params) = $app->service('cms')->visibleSql('p');
            $params[] = $limit;
            $out['page'] = self::column($db->select('SELECT p.id AS id FROM {pages} p WHERE ' . $visible . ' AND p.noindex = 0 AND p.is_home = 0 ORDER BY p.id LIMIT ?', $params));
            list($visible, $params) = $app->service('cms')->visibleSql('p');
            $params[] = $limit;
            $out['post'] = self::column($db->select('SELECT p.id AS id FROM {posts} p WHERE ' . $visible . ' AND p.noindex = 0 ORDER BY p.id LIMIT ?', $params));
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return int[]
     */
    private static function column(array $rows): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }
}
