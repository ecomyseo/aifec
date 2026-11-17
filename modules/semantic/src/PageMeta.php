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
use Aifec\Modules\Catalog\Front\ListingQuery;

/**
 * El filtro «page.meta»: a cada página de la tienda le añade sus datos estructurados (page['jsonld']) y la dirección de su versión
 * en texto (page['markdown']). Lo que ya trajera la página se respeta: los objetos se suman a los que hubiera.
 */
final class PageMeta
{
    /**
     * @param mixed $page
     *
     * @return mixed
     */
    public static function apply($page, App $app)
    {
        if (!is_array($page) || !$app->has('semantic')) {
            return $page;
        }
        /** @var Semantic $semantic */
        $semantic = $app->service('semantic');
        $type = isset($page['type']) ? (string) $page['type'] : '';
        $entity = isset($page['entity']) && is_array($page['entity']) ? $page['entity'] : [];
        $id = isset($entity['id']) ? (int) $entity['id'] : 0;
        $entityType = isset($entity['type']) ? (string) $entity['type'] : '';
        $objects = [];

        if ($type === 'home') {
            $objects = $semantic->jsonld('home', []);
        } elseif ($type === 'product' && $id > 0 && $semantic->catalog() !== null) {
            $product = $semantic->catalog()->product($id);
            if (is_array($product)) {
                $objects = $semantic->jsonld('product', $product);
            }
        } elseif (($type === 'category' || $type === 'brand') && $id > 0 && $semantic->catalog() !== null) {
            $objects = self::catalogList($app, $semantic, $type, $id);
        } elseif ($type === 'post' && $id > 0 && $semantic->cms() !== null) {
            $post = $semantic->cms()->post($id);
            if (is_array($post)) {
                $objects = $semantic->jsonld('post', $post);
            }
        } elseif ($type === 'page' && $id > 0 && $semantic->cms() !== null) {
            $content = $semantic->cms()->page($id);
            if (is_array($content)) {
                $objects = $semantic->jsonld('page', $content);
            }
        } elseif ($type === 'blog' && $semantic->cms() !== null) {
            $objects = self::blogList($app, $semantic, $entityType === 'term' ? $id : 0);
        }

        $crumbs = isset($page['breadcrumbs']) && is_array($page['breadcrumbs']) ? $page['breadcrumbs'] : [];
        if ($crumbs !== []) {
            $objects = array_merge($objects, $semantic->jsonld('breadcrumbs', [
                'breadcrumbs' => $crumbs,
                'canonical' => isset($page['canonical']) ? (string) $page['canonical'] : '',
            ]));
        }
        $existing = isset($page['jsonld']) && is_array($page['jsonld']) ? $page['jsonld'] : [];
        $page['jsonld'] = array_merge($existing, $objects);

        if (!isset($page['markdown']) || (string) $page['markdown'] === '') {
            $page['markdown'] = Addresses::forPage($app, $page);
        }

        return $page;
    }

    /**
     * La lista de productos de la página de una categoría o de una marca (la misma página y el mismo orden que ve la persona).
     *
     * @return array<int,array<string,mixed>>
     */
    private static function catalogList(App $app, Semantic $semantic, string $type, int $id): array
    {
        $request = $app->request();
        $query = class_exists(ListingQuery::class) ? ListingQuery::fromRequest($request)->toCatalog() : ['page' => max(1, $request->int('page', 1))];
        $query[$type === 'brand' ? 'brand_id' : 'category_id'] = $id;
        $result = $semantic->catalog()->listing($query);
        $items = [];
        foreach (isset($result['items']) && is_array($result['items']) ? $result['items'] : [] as $card) {
            if (is_array($card) && isset($card['url'])) {
                $items[] = ['name' => isset($card['name']) ? (string) $card['name'] : '', 'url' => (string) $card['url']];
            }
        }
        $perPage = isset($result['per_page']) ? (int) $result['per_page'] : count($items);
        $page = isset($result['page']) ? (int) $result['page'] : 1;

        return $semantic->jsonld('list', ['items' => $items, 'offset' => max(0, ($page - 1) * $perPage), 'total' => isset($result['total']) ? (int) $result['total'] : count($items)]);
    }

    /**
     * Las entradas del índice del blog o de una categoría o etiqueta.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function blogList(App $app, Semantic $semantic, int $termId): array
    {
        $query = ['page' => max(1, $app->request()->int('page', 1))];
        if ($termId > 0) {
            $query['term_id'] = $termId;
        }
        $result = $semantic->cms()->posts($query);
        $items = [];
        foreach (isset($result['items']) && is_array($result['items']) ? $result['items'] : [] as $post) {
            if (is_array($post) && isset($post['url_abs'])) {
                $items[] = ['name' => isset($post['title']) ? (string) $post['title'] : '', 'url' => (string) $post['url_abs']];
            }
        }
        $perPage = isset($result['per_page']) ? (int) $result['per_page'] : count($items);
        $page = isset($result['page']) ? (int) $result['page'] : 1;

        return $semantic->jsonld('list', ['items' => $items, 'offset' => max(0, ($page - 1) * $perPage), 'total' => isset($result['total']) ? (int) $result['total'] : count($items)]);
    }
}
