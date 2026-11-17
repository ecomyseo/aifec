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

use Aifec\Cms\Blocks\BlockSchema;
use Aifec\Kernel\App;
use Aifec\Modules\Cms\Cms;

/**
 * Carga en un sitio las páginas, las entradas y los menús de un paquete (DemoBuilder la llama, después del catálogo).
 *
 * Las páginas y las entradas se casan por su dirección (que sale de su código): repetir la carga no las duplica. Un menú
 * se guarda entero cada vez (Menus::save sustituye sus elementos), así que repetirlo deja lo mismo.
 */
final class ContentLoader
{
    /** @var App */
    private $app;

    /** @var Package */
    private $pkg;

    /** @var CatalogLoader */
    private $catalog;

    /** @var callable(string,string):int */
    private $media;

    /** @var string */
    private $lang;

    /** @var array<string,array<string,int>> */
    private $ids = ['page' => [], 'post' => []];

    /** @var array<string,int> */
    private $created = ['pages' => 0, 'posts' => 0, 'menus' => 0];

    /**
     * @param callable(string,string):int $media (nombre de fichero, texto alternativo) → identificador en la biblioteca de medios
     */
    public function __construct(App $app, Package $pkg, CatalogLoader $catalog, callable $media)
    {
        $this->app = $app;
        $this->pkg = $pkg;
        $this->catalog = $catalog;
        $this->media = $media;
        $this->lang = $app->defaultLang();
    }

    /**
     * @return array<string,int>
     */
    public function created(): array
    {
        return $this->created;
    }

    public function load(): void
    {
        $this->pages();
        $this->posts();
        $this->menus();
        $this->app->invalidate(['cms', 'home', 'pages']);
    }

    // ------------------------------------------------------------------ páginas y entradas

    private function pages(): void
    {
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        foreach (array_values($this->pkg->data('pages.json')) as $i => $p) {
            $code = (string) $p['code'];
            $path = isset($p['slug']) && is_string($p['slug']) && $p['slug'] !== '' ? $p['slug'] : $code;
            $hit = $this->app->slugs()->resolve($this->lang, $path);
            if ($hit !== null && $hit['type'] === 'page') {
                $this->ids['page'][$code] = $hit['entity_id'];
                continue;
            }
            $published = !isset($p['status']) || $p['status'] === 'published';
            $id = $db->insert('pages', [
                'status' => $published ? 'published' : 'draft',
                'template' => 'default', 'parent_id' => null,
                'position' => isset($p['position']) ? (int) $p['position'] : $i,
                'noindex' => 0, 'is_home' => !empty($p['home']) ? 1 : 0,
                'published_at' => $published ? $now : null, 'author_id' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('page_i18n', [
                'page_id' => $id, 'lang' => $this->lang, 'title' => (string) $p['title'],
                'blocks' => $this->blocksJson($p),
                'excerpt' => isset($p['excerpt']) && is_string($p['excerpt']) ? $p['excerpt'] : null,
                'meta_title' => isset($p['meta_title']) && is_string($p['meta_title']) && $p['meta_title'] !== '' ? $p['meta_title'] : null,
                'meta_description' => isset($p['meta_description']) && is_string($p['meta_description']) && $p['meta_description'] !== '' ? $p['meta_description'] : null,
            ]);
            $this->app->slugs()->set('page', $id, $this->lang, $path);
            $this->ids['page'][$code] = $id;
            ++$this->created['pages'];
        }
    }

    private function posts(): void
    {
        $posts = $this->pkg->data('posts.json');
        if ($posts === []) {
            return;
        }
        $cms = $this->cms();
        $db = $this->app->db();
        $now = $this->app->clock()->sql();
        foreach (array_values($posts) as $p) {
            $code = (string) $p['code'];
            $path = $cms->prefix('post') . '/' . (isset($p['slug']) && is_string($p['slug']) && $p['slug'] !== '' ? $p['slug'] : $code);
            $hit = $this->app->slugs()->resolve($this->lang, $path);
            if ($hit !== null && $hit['type'] === 'post') {
                $this->ids['post'][$code] = $hit['entity_id'];
                continue;
            }
            $date = isset($p['date']) && is_string($p['date']) ? (strlen($p['date']) === 10 ? $p['date'] . ' 09:00:00' : $p['date']) : $now;
            $id = $db->insert('posts', [
                'status' => 'published',
                'media_id' => isset($p['image']) && is_string($p['image']) ? $this->image($p['image'], (string) $p['title']) : null,
                'author_id' => null, 'noindex' => 0, 'published_at' => $date, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('post_i18n', [
                'post_id' => $id, 'lang' => $this->lang, 'title' => (string) $p['title'],
                'blocks' => $this->blocksJson($p),
                'excerpt' => isset($p['excerpt']) && is_string($p['excerpt']) ? $p['excerpt'] : '',
                'meta_title' => isset($p['meta_title']) && is_string($p['meta_title']) && $p['meta_title'] !== '' ? $p['meta_title'] : null,
                'meta_description' => isset($p['meta_description']) && is_string($p['meta_description']) && $p['meta_description'] !== '' ? $p['meta_description'] : null,
            ]);
            $this->app->slugs()->set('post', $id, $this->lang, $path);
            $this->ids['post'][$code] = $id;
            ++$this->created['posts'];
        }
    }

    /**
     * Los bloques de una página o entrada, con sus referencias por código ya cambiadas por identificadores y saneados.
     *
     * @param array<string,mixed> $item
     */
    private function blocksJson(array $item): string
    {
        $resolved = BlockRefs::resolve(is_array($item['blocks']) ? $item['blocks'] : [], function (string $kind, string $code): ?int {
            if ($kind === 'media') {
                return $this->image($code, ucfirst(str_replace(['-', '_'], ' ', (string) preg_replace('/\.[^.]*$/', '', $code))));
            }

            return $this->catalog->id($kind, $code);
        });

        return (string) json_encode(BlockSchema::validate($resolved), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ menús

    private function menus(): void
    {
        $menus = $this->pkg->data('menus.json');
        if ($menus === []) {
            return;
        }
        $cms = $this->cms();
        foreach (array_values($menus) as $menu) {
            $items = [];
            foreach (array_values(isset($menu['items']) && is_array($menu['items']) ? $menu['items'] : []) as $item) {
                $items[] = $this->menuItem($item, 0);
                foreach (array_values(isset($item['children']) && is_array($item['children']) ? $item['children'] : []) as $child) {
                    $items[] = $this->menuItem($child, 1);
                }
            }
            $cms->menus()->save((string) $menu['code'], (string) $menu['name'], $items);
            ++$this->created['menus'];
        }
    }

    /**
     * @param array<string,mixed> $item
     *
     * @return array<string,mixed> un elemento como lo espera Menus::save()
     */
    private function menuItem(array $item, int $level): array
    {
        $type = (string) $item['type'];
        $ref = isset($item['ref']) && is_string($item['ref']) ? $item['ref'] : '';
        $id = 0;
        if ($type === 'page' || $type === 'post') {
            $id = isset($this->ids[$type][$ref]) ? $this->ids[$type][$ref] : 0;
        } elseif ($type === 'category' || $type === 'product') {
            $id = (int) $this->catalog->id($type, $ref);
        }

        return [
            'type' => $type, 'ref' => $id,
            'url' => isset($item['url']) && is_string($item['url']) ? $item['url'] : '',
            'blank' => !empty($item['blank']) ? 1 : 0,
            'level' => $level,
            'labels' => [$this->lang => (string) $item['label']],
        ];
    }

    private function cms(): Cms
    {
        $cms = $this->app->service('cms');
        if (!$cms instanceof Cms) {
            throw new \RuntimeException('The content module is not available in this site');
        }

        return $cms;
    }

    private function image(string $file, string $alt): int
    {
        $media = $this->media;

        return (int) $media($file, $alt);
    }
}
