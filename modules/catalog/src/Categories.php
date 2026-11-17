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
use Aifec\Util\Str;

/**
 * Categorías: la ficha de una (con sus migas y sus hijas) y el árbol entero de las activas.
 *
 * La columna path guarda los identificadores desde la raíz hasta la propia categoría («3/12/40»); si algún escritor
 * no incluye el identificador propio, aquí se añade. Una categoría desactivada no sale en ningún sitio.
 * Todo se guarda en la caché con la etiqueta «catalog» (y «category:<id>» la ficha).
 */
final class Categories
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * La ficha de una categoría activa; null si no existe o está desactivada.
     *
     * @return array<string,mixed>|null
     */
    public function category(int $id, string $lang): ?array
    {
        $cache = $this->app->cache();
        $key = 'category:' . $id . ':' . $lang;
        $hit = $cache->get($key);
        if (is_array($hit)) {
            return $hit;
        }
        $data = $this->build($id, $lang);
        if ($data !== null) {
            $cache->set($key, $data, 3600, ['category:' . $id, 'catalog']);
        }

        return $data;
    }

    /**
     * Las categorías activas anidadas: [['id','name','url','children' => […]]]. Una categoría cuya madre está desactivada no sale.
     *
     * @return array<int,array<string,mixed>>
     */
    public function tree(string $lang): array
    {
        $cache = $this->app->cache();
        $key = 'tree:' . $lang;
        $hit = $cache->get($key);
        if (is_array($hit)) {
            return $hit;
        }
        $db = $this->app->db();
        $rows = $db->select('SELECT id, parent_id FROM {categories} WHERE active = 1 ORDER BY position, id');
        $names = $this->names(array_map(static function (array $r): int {
            return (int) $r['id'];
        }, $rows), $lang);
        $ids = [];
        $children = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $ids[] = $id;
            $parent = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
            $children[$parent][] = $id;
        }
        $this->preload($ids, $lang);
        $url = $this->app->url();
        $build = function (int $parent) use (&$build, $children, $names, $url, $lang): array {
            $out = [];
            foreach (isset($children[$parent]) ? $children[$parent] : [] as $id) {
                $out[] = [
                    'id' => $id,
                    'name' => isset($names[$id]) ? $names[$id]['name'] : '',
                    'url' => $url->entity('category', $id, $lang),
                    'children' => $build($id),
                ];
            }

            return $out;
        };
        $tree = $build(0);
        $cache->set($key, $tree, 3600, ['catalog']);

        return $tree;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function build(int $id, string $lang): ?array
    {
        $db = $this->app->db();
        $row = $db->row('SELECT * FROM {categories} WHERE id = ? AND active = 1', [$id]);
        if ($row === null) {
            return null;
        }
        $path = self::pathOf($row);
        $ancestorIds = array_map('intval', explode('/', $path));

        $children = $db->select('SELECT * FROM {categories} WHERE parent_id = ? AND active = 1 ORDER BY position, id', [$id]);
        $childIds = array_map(static function (array $r): int {
            return (int) $r['id'];
        }, $children);

        $names = $this->names(array_values(array_unique(array_merge($ancestorIds, $childIds, [$id]))), $lang);
        $this->preload(array_values(array_unique(array_merge($ancestorIds, $childIds, [$id]))), $lang);
        $url = $this->app->url();

        $crumbs = [];
        foreach ($ancestorIds as $ancestorId) {
            if (isset($names[$ancestorId])) {
                $crumbs[] = ['name' => $names[$ancestorId]['name'], 'url' => $url->entity('category', $ancestorId, $lang)];
            }
        }

        $mediaIds = [];
        if (isset($row['media_id']) && $row['media_id'] !== null && (int) $row['media_id'] > 0) {
            $mediaIds[(int) $row['media_id']] = true;
        }
        foreach ($children as $child) {
            if (isset($child['media_id']) && $child['media_id'] !== null && (int) $child['media_id'] > 0) {
                $mediaIds[(int) $child['media_id']] = true;
            }
        }
        $images = Images::many($this->app, array_keys($mediaIds));

        $childList = [];
        foreach ($children as $child) {
            $childId = (int) $child['id'];
            $mediaId = isset($child['media_id']) && $child['media_id'] !== null ? (int) $child['media_id'] : 0;
            $childList[] = [
                'id' => $childId,
                'name' => isset($names[$childId]) ? $names[$childId]['name'] : '',
                'url' => $url->entity('category', $childId, $lang),
                'image' => isset($images[$mediaId]) ? $images[$mediaId] : null,
                'product_count' => (int) $child['product_count'],
            ];
        }

        $text = isset($names[$id]) ? $names[$id] : ['name' => '', 'description' => '', 'facts' => '', 'meta_title' => '', 'meta_description' => ''];
        $mediaId = isset($row['media_id']) && $row['media_id'] !== null ? (int) $row['media_id'] : 0;
        $description = $text['description'];
        $plain = Texts::plain($description);

        return [
            'id' => $id,
            'name' => $text['name'],
            'description' => $description,
            'url' => $url->entity('category', $id, $lang),
            'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
            'crumbs' => $crumbs,
            'children' => $childList,
            'image' => isset($images[$mediaId]) ? $images[$mediaId] : null,
            'facts' => Texts::facts($text['facts']),
            'seo' => [
                'title' => $text['meta_title'] !== '' ? $text['meta_title'] : $text['name'],
                'description' => $text['meta_description'] !== '' ? $text['meta_description'] : Str::limit($plain, 154),
            ],
        ];
    }

    /**
     * Los textos de varias categorías en el idioma pedido (o el principal, o el que haya): id => name, description,
     * facts, meta_title, meta_description. Una consulta.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,string>>
     */
    private function names(array $ids, string $lang): array
    {
        if ($ids === []) {
            return [];
        }
        $db = $this->app->db();
        $params = [];
        $marks = $db->in($ids, $params);
        $byId = [];
        foreach ($db->select('SELECT * FROM {category_i18n} WHERE category_id IN (' . $marks . ')', $params) as $row) {
            $byId[(int) $row['category_id']][(string) $row['lang']] = $row;
        }
        $out = [];
        $default = $this->app->defaultLang();
        foreach ($byId as $id => $byLang) {
            $picked = Texts::pick($byLang, $lang, $default);
            if ($picked === null) {
                continue;
            }
            $out[$id] = [
                'name' => (string) $picked['name'],
                'description' => isset($picked['description']) ? (string) $picked['description'] : '',
                'facts' => isset($picked['facts']) ? (string) $picked['facts'] : '',
                'meta_title' => isset($picked['meta_title']) ? (string) $picked['meta_title'] : '',
                'meta_description' => isset($picked['meta_description']) ? (string) $picked['meta_description'] : '',
            ];
        }

        return $out;
    }

    /**
     * @param int[] $ids
     */
    private function preload(array $ids, string $lang): void
    {
        $slugs = $this->app->slugs();
        foreach (array_values(array_unique([$lang, $this->app->defaultLang()])) as $l) {
            $slugs->preload('category', $ids, $l);
        }
    }

    /**
     * La ruta de una categoría con su propio identificador al final («3/12/40»).
     *
     * @param array<string,mixed> $row fila de categories
     */
    public static function pathOf(array $row): string
    {
        $id = (int) $row['id'];
        $path = trim(isset($row['path']) ? (string) $row['path'] : '', '/');
        if ($path === '') {
            return (string) $id;
        }
        $parts = explode('/', $path);

        return (int) end($parts) === $id ? $path : $path . '/' . $id;
    }
}
