<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms;

use Aifec\Kernel\App;
use Aifec\Text\UrlFilter;

/**
 * Menús del comerciante: una lista de elementos (dos niveles como mucho) por cada ubicación que declara el tema.
 *
 * Un elemento apunta a una página, una entrada, una categoría del blog (`term`), una categoría o un producto del catálogo
 * (si existe), la portada o una dirección libre, y lleva su etiqueta por idioma. Un elemento cuya entidad ya no existe, o
 * no se ve (despublicada), no sale, y los que cuelgan de él tampoco.
 */
final class Menus
{
    public const TYPES = ['home', 'page', 'post', 'term', 'category', 'product', 'url'];

    /** @var App */
    private $app;

    /** @var Cms */
    private $cms;

    public function __construct(App $app, Cms $cms)
    {
        $this->app = $app;
        $this->cms = $cms;
    }

    /**
     * Las ubicaciones del tema activo: código => nombre.
     *
     * @return array<string,string>
     */
    public function locations(): array
    {
        $theme = $this->app->theme();
        $out = [];
        foreach (isset($theme['menus']) && is_array($theme['menus']) ? $theme['menus'] : [] as $code => $name) {
            if (is_string($code) && preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $code) === 1) {
                // El tema escribe el nombre en inglés en su theme.json y lo traduce en su lang/: aquí se pide ya traducido
                // (sin esto el panel enseñaba «Main menu» y «Legal links» en una tienda en castellano).
                $out[$code] = is_string($name) && $name !== '' ? t($name) : $code;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ lectura de la tienda

    /**
     * @return array<int,array<string,mixed>> [['label','url','current','children' => […]]]
     */
    public function items(string $location, string $lang): array
    {
        $cache = $this->app->cache();
        $key = 'cms:menu:' . $location . ':' . $lang;
        $tree = $cache->get($key);
        if (!is_array($tree)) {
            $tree = $this->build($location, $lang);
            // Cinco minutos como mucho: un enlace a una página programada tiene que salir cuando llega su hora.
            $cache->set($key, $tree, 300, ['cms', 'catalog']);
        }

        return $this->markCurrent($tree);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function build(string $location, string $lang): array
    {
        $db = $this->app->db();
        $menuId = $db->value('SELECT id FROM {menus} WHERE code = ?', [$location]);
        if ($menuId === null) {
            return [];
        }
        $rows = $db->select('SELECT * FROM {menu_items} WHERE menu_id = ? ORDER BY position, id', [(int) $menuId]);
        $children = [];
        foreach ($rows as $row) {
            $parent = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
            $children[$parent][] = $row;
        }
        $tree = [];
        foreach (isset($children[0]) ? $children[0] : [] as $row) {
            $item = $this->resolve($row, $lang);
            if ($item === null) {
                continue;
            }
            $item['children'] = [];
            foreach (isset($children[(int) $row['id']]) ? $children[(int) $row['id']] : [] as $child) {
                $sub = $this->resolve($child, $lang);
                if ($sub !== null) {
                    $sub['children'] = [];
                    $item['children'][] = $sub;
                }
            }
            $tree[] = $item;
        }

        return $tree;
    }

    /**
     * Etiqueta y dirección de un elemento, o null si lo que enlaza ya no se ve.
     *
     * @param array<string,mixed> $row
     *
     * @return array{label:string,url:string,current:bool,blank:bool}|null
     */
    private function resolve(array $row, string $lang): ?array
    {
        $type = (string) $row['type'];
        $ref = isset($row['ref']) && $row['ref'] !== null ? (int) $row['ref'] : 0;
        $labels = json_decode(isset($row['labels']) && $row['labels'] !== null ? (string) $row['labels'] : '{}', true);
        $labels = is_array($labels) ? $labels : [];
        $default = $this->app->defaultLang();
        $label = '';
        foreach ([$lang, $default] as $try) {
            if ($label === '' && isset($labels[$try]) && is_string($labels[$try])) {
                $label = trim($labels[$try]);
            }
        }
        $url = '';
        $name = '';
        $urls = $this->app->url();

        switch ($type) {
            case 'home':
                $url = $urls->to('/', [], $lang);
                $name = $this->app->i18n()->t('Home', [], $lang);
                break;
            case 'url':
                $safe = isset($row['url']) && is_string($row['url']) ? UrlFilter::safe($row['url']) : null;
                if ($safe === null) {
                    return null;
                }
                $url = preg_match('#^/(?!/)#', $safe) === 1 ? $urls->to($safe, [], $lang) : $safe;
                $name = $safe;
                break;
            case 'page':
            case 'post':
                $content = $type === 'page' ? $this->cms->page($ref, $lang) : $this->cms->post($ref, $lang);
                if ($content === null) {
                    return null;
                }
                $url = (string) $content['url'];
                $name = (string) $content['title'];
                break;
            case 'term':
                $term = $this->cms->term($ref, $lang);
                if ($term === null) {
                    return null;
                }
                $url = (string) $term['url'];
                $name = (string) $term['name'];
                break;
            case 'category':
            case 'product':
                if (!$this->app->has('catalog')) {
                    return null;
                }
                $catalog = $this->app->service('catalog');
                $entity = $type === 'category' ? $catalog->category($ref, $lang) : $catalog->card($ref, $lang);
                if ($entity === null) {
                    return null;
                }
                $name = isset($entity['name']) ? (string) $entity['name'] : '';
                $url = isset($entity['url']) && is_string($entity['url']) && $entity['url'] !== '' ? $entity['url'] : $urls->entity($type, $ref, $lang);
                break;
            default:
                return null;
        }

        return ['label' => $label !== '' ? $label : $name, 'url' => $url, 'current' => false, 'blank' => !empty($row['blank'])];
    }

    /**
     * Marca el elemento de la dirección que se está viendo (y, si es un hijo, también a su padre).
     *
     * @param array<int,array<string,mixed>> $tree
     *
     * @return array<int,array<string,mixed>>
     */
    private function markCurrent(array $tree): array
    {
        $here = $this->app->url()->to($this->app->request()->path, [], null);
        $trim = static function (string $url): string {
            $path = parse_url($url, PHP_URL_PATH);

            return rtrim(is_string($path) ? $path : $url, '/');
        };
        $current = $trim($here);
        // Un enlace con ancla («/#servicios») lleva a un trozo de la página, no es la página en la que se está.
        $isHere = static function (string $url) use ($trim, $current): bool {
            return strpos($url, '#') === false && $trim($url) === $current;
        };
        foreach ($tree as $i => $item) {
            $tree[$i]['current'] = $isHere((string) $item['url']);
            foreach ($item['children'] as $j => $child) {
                $hit = $isHere((string) $child['url']);
                $tree[$i]['children'][$j]['current'] = $hit;
                if ($hit) {
                    $tree[$i]['current'] = true;
                }
            }
        }

        return $tree;
    }

    // ------------------------------------------------------------------ lectura del panel y guardado

    /**
     * Los elementos tal como se editan: en orden, cada uno con su nivel (0 o 1).
     *
     * @return array<int,array<string,mixed>> [['type','ref','url','blank','level','labels' => [idioma => texto]]]
     */
    public function editable(string $code): array
    {
        $db = $this->app->db();
        $menuId = $db->value('SELECT id FROM {menus} WHERE code = ?', [$code]);
        if ($menuId === null) {
            return [];
        }
        $rows = $db->select('SELECT * FROM {menu_items} WHERE menu_id = ? ORDER BY position, id', [(int) $menuId]);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $out = [];
        foreach ($rows as $row) {
            $labels = json_decode(isset($row['labels']) && $row['labels'] !== null ? (string) $row['labels'] : '{}', true);
            $out[] = [
                'type' => (string) $row['type'],
                'ref' => isset($row['ref']) && $row['ref'] !== null ? (int) $row['ref'] : 0,
                'url' => isset($row['url']) && $row['url'] !== null ? (string) $row['url'] : '',
                'blank' => !empty($row['blank']) ? 1 : 0,
                'level' => isset($row['parent_id']) && $row['parent_id'] !== null && isset($byId[(int) $row['parent_id']]) ? 1 : 0,
                'labels' => is_array($labels) ? $labels : [],
            ];
        }

        return $out;
    }

    /**
     * Guarda el menú entero de una vez: sustituye todos sus elementos. Cada elemento de nivel 1 cuelga del último de nivel 0
     * (un nivel 1 sin padre pasa a nivel 0). Lo que no es válido (tipo desconocido, entidad que no existe, dirección
     * insegura) se descarta. Devuelve cuántos elementos quedan.
     *
     * @param array<int|string,mixed> $items
     */
    public function save(string $code, string $name, array $items): int
    {
        $db = $this->app->db();
        $languages = $this->app->languages();
        $clean = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $type = isset($item['type']) && is_string($item['type']) ? $item['type'] : '';
            if (!in_array($type, self::TYPES, true)) {
                continue;
            }
            $ref = isset($item['ref']) && is_scalar($item['ref']) ? (int) $item['ref'] : 0;
            $url = null;
            if ($type === 'url') {
                $url = isset($item['url']) && is_string($item['url']) ? UrlFilter::safe($item['url']) : null;
                if ($url === null) {
                    continue;
                }
            } elseif ($type !== 'home' && !$this->exists($type, $ref)) {
                continue;
            }
            $labels = [];
            foreach ($languages as $lang) {
                $text = isset($item['labels'][$lang]) && is_string($item['labels'][$lang]) ? trim(strip_tags($item['labels'][$lang])) : '';
                if ($text !== '') {
                    $labels[$lang] = mb_substr($text, 0, 120);
                }
            }
            $clean[] = [
                'type' => $type,
                'ref' => $type === 'url' || $type === 'home' ? null : $ref,
                'url' => $url,
                'blank' => !empty($item['blank']) && (string) $item['blank'] !== '0' ? 1 : 0,
                'level' => isset($item['level']) && (int) $item['level'] === 1 ? 1 : 0,
                'labels' => $labels,
            ];
        }

        $db->transaction(function () use ($db, $code, $name, $clean): void {
            $menuId = $db->value('SELECT id FROM {menus} WHERE code = ?', [$code]);
            if ($menuId === null) {
                $menuId = $db->insert('menus', ['code' => $code, 'name' => $name]);
            } else {
                $db->update('menus', ['name' => $name], ['id' => (int) $menuId]);
            }
            $db->delete('menu_items', ['menu_id' => (int) $menuId]);
            $parent = null;
            foreach ($clean as $position => $item) {
                $row = [
                    'menu_id' => (int) $menuId,
                    'position' => $position,
                    'type' => $item['type'],
                    'blank' => $item['blank'],
                    'labels' => json_encode($item['labels'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ];
                if ($item['ref'] !== null) {
                    $row['ref'] = $item['ref'];
                }
                if ($item['url'] !== null) {
                    $row['url'] = $item['url'];
                }
                if ($item['level'] === 1 && $parent !== null) {
                    $row['parent_id'] = $parent;
                }
                $id = $db->insert('menu_items', $row);
                if ($item['level'] === 0 || $parent === null) {
                    $parent = $id;
                }
            }
        });
        $this->app->invalidate(['cms']);

        return count($clean);
    }

    /**
     * ¿Existe lo que enlaza? (aunque ahora no se vea: una página en borrador se puede poner en el menú y sale cuando se publique).
     */
    private function exists(string $type, int $ref): bool
    {
        if ($ref <= 0) {
            return false;
        }
        $db = $this->app->db();
        switch ($type) {
            case 'page':
                return $db->value('SELECT id FROM {pages} WHERE id = ?', [$ref]) !== null;
            case 'post':
                return $db->value('SELECT id FROM {posts} WHERE id = ?', [$ref]) !== null;
            case 'term':
                return $db->value('SELECT id FROM {terms} WHERE id = ?', [$ref]) !== null;
            case 'category':
                return $this->app->has('catalog') && $db->tableExists('categories') && $db->value('SELECT id FROM {categories} WHERE id = ?', [$ref]) !== null;
            case 'product':
                return $this->app->has('catalog') && $db->tableExists('products') && $db->value('SELECT id FROM {products} WHERE id = ?', [$ref]) !== null;
        }

        return false;
    }

    // ------------------------------------------------------------------ buscador del editor

    /**
     * Candidatos para añadir a un menú: [['id','label']]. Busca en el idioma principal.
     *
     * @return array<int,array{id:int,label:string}>
     */
    public function search(string $type, string $q): array
    {
        $db = $this->app->db();
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($q)) . '%';
        $default = $this->app->defaultLang();
        $rows = [];
        switch ($type) {
            case 'page':
                $rows = $db->select("SELECT p.id AS id, x.title AS label FROM {pages} p JOIN {page_i18n} x ON x.page_id = p.id AND x.lang = ? WHERE x.title LIKE ? ESCAPE '!' ORDER BY x.title LIMIT 20", [$default, $like]);
                break;
            case 'post':
                $rows = $db->select("SELECT p.id AS id, x.title AS label FROM {posts} p JOIN {post_i18n} x ON x.post_id = p.id AND x.lang = ? WHERE x.title LIKE ? ESCAPE '!' ORDER BY p.id DESC LIMIT 20", [$default, $like]);
                break;
            case 'term':
                $rows = $db->select("SELECT t.id AS id, x.name AS label FROM {terms} t JOIN {term_i18n} x ON x.term_id = t.id AND x.lang = ? WHERE x.name LIKE ? ESCAPE '!' ORDER BY x.name LIMIT 20", [$default, $like]);
                break;
            case 'category':
                if ($this->app->has('catalog') && $db->tableExists('category_i18n')) {
                    $rows = $db->select("SELECT category_id AS id, name AS label FROM {category_i18n} WHERE lang = ? AND name LIKE ? ESCAPE '!' ORDER BY name LIMIT 20", [$default, $like]);
                }
                break;
            case 'product':
                if ($this->app->has('catalog') && $db->tableExists('product_i18n')) {
                    $rows = $db->select("SELECT product_id AS id, name AS label FROM {product_i18n} WHERE lang = ? AND name LIKE ? ESCAPE '!' ORDER BY name LIMIT 20", [$default, $like]);
                }
                break;
        }
        $out = [];
        foreach ($rows as $row) {
            $out[] = ['id' => (int) $row['id'], 'label' => (string) $row['label']];
        }

        return $out;
    }

    /**
     * El texto de un elemento para enseñarlo en el editor (el nombre de lo que enlaza).
     *
     * @param array<string,mixed> $item
     */
    public function describe(array $item): string
    {
        $type = (string) $item['type'];
        $ref = (int) $item['ref'];
        $db = $this->app->db();
        $default = $this->app->defaultLang();
        $name = '';
        switch ($type) {
            case 'home':
                return t('Home page');
            case 'url':
                return (string) $item['url'];
            case 'page':
                $name = $db->value('SELECT title FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$ref, $default]);
                break;
            case 'post':
                $name = $db->value('SELECT title FROM {post_i18n} WHERE post_id = ? AND lang = ?', [$ref, $default]);
                break;
            case 'term':
                $name = $db->value('SELECT name FROM {term_i18n} WHERE term_id = ? AND lang = ?', [$ref, $default]);
                break;
            case 'category':
                $name = $db->tableExists('category_i18n') ? $db->value('SELECT name FROM {category_i18n} WHERE category_id = ? AND lang = ?', [$ref, $default]) : null;
                break;
            case 'product':
                $name = $db->tableExists('product_i18n') ? $db->value('SELECT name FROM {product_i18n} WHERE product_id = ? AND lang = ?', [$ref, $default]) : null;
                break;
        }

        return $name !== null && $name !== '' ? (string) $name : '#' . $ref;
    }
}
