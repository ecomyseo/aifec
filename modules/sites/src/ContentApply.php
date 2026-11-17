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
use Aifec\Modules\Catalog\Admin\TaxonomySlugs;
use Aifec\Modules\Cms\Cms;
use Aifec\Modules\Cms\Legal;
use Aifec\Modules\Cms\Revisions;
use Aifec\Modules\Themes\ThemeException;
use Aifec\Text\UrlFilter;

/**
 * Aplica a un sitio que YA existe los contenidos de un paquete: sus páginas, sus menús y su tema. A diferencia de DemoBuilder,
 * no construye un sitio ni destruye nada, así que vale también en el sitio maestro.
 *
 * Solo añade o actualiza:
 *  - las páginas, por su dirección (el «slug» o, sin él, el código): la que no existe se crea; la que existe vuelve al texto del
 *    paquete (antes se guarda una versión en el historial, para poder recuperar lo que se había cambiado a mano), salvo la que el
 *    paquete marca con «keep»: true (textos de partida, como un aviso legal de ejemplo), que si ya existe no se toca; la que el
 *    paquete marca como portada pasa a ser la portada del sitio;
 *  - los menús, por su código: los elementos de ese menú se sustituyen por los del paquete; los menús de otros códigos no se tocan;
 *  - el tema del paquete y sus opciones.
 * Nunca borra páginas, productos, categorías, pedidos, clientes ni ajustes (store.* no se toca). El catálogo, las entradas, los
 * envíos, los cupones y las valoraciones que traiga el paquete no se cargan.
 *
 * Dos pasadas seguidas dejan lo mismo que una. Con $dry no se escribe nada: se dice qué se haría.
 */
final class ContentApply
{
    /** Las tres partes que se pueden aplicar (--only). */
    public const PARTS = ['pages', 'menus', 'theme'];

    /** Datos del paquete que esta operación no carga (los carga DemoBuilder al crear una demostración). */
    private const NOT_APPLIED = ['categories.json', 'brands.json', 'attributes.json', 'products.json', 'posts.json', 'carriers.json', 'coupons.json', 'reviews.json'];

    /** @var App */
    private $app;

    /** @var Package */
    private $pkg;

    /** @var string */
    private $lang;

    /** @var array<string,int> código de página => identificador (el que ya tiene en el sitio o el que se le acaba de dar) */
    private $pageIds = [];

    /** @var int contador de identificadores provisionales (solo en un ensayo: las páginas que se crearían) */
    private $pending = 0;

    /** @var bool */
    private $dry = false;

    public function __construct(App $app, Package $pkg)
    {
        $this->app = $app;
        $this->pkg = $pkg;
        $this->lang = $app->defaultLang();
    }

    /**
     * @param string[] $only qué partes aplicar (valores de PARTS); vacío = todas
     *
     * @throws \InvalidArgumentException si una parte no existe
     * @throws \RuntimeException         si algo del paquete no se puede aplicar en este sitio (no se ha escrito nada)
     *
     * @return array{dry:bool,pages:array<int,array<string,mixed>>,menus:array<int,array<string,mixed>>,theme:array<string,mixed>|null,notes:string[],counts:array<string,int>,line:string}
     */
    public function run(array $only, bool $dry): array
    {
        foreach ($only as $part) {
            if (!in_array($part, self::PARTS, true)) {
                throw new \InvalidArgumentException('Unknown part «' . $part . '» (use ' . implode(', ', self::PARTS) . ')');
            }
        }
        $parts = $only === [] ? self::PARTS : array_values($only);
        if (!$this->app->has('cms') && (in_array('pages', $parts, true) || in_array('menus', $parts, true))) {
            throw new \RuntimeException('The content module is not active in this site: there are no pages or menus to apply to');
        }
        $this->dry = $dry;
        $notes = [];

        // Primero se comprueba todo lo que puede fallar; solo después se escribe.
        $theme = in_array('theme', $parts, true) ? $this->planTheme($notes) : null;
        $pages = in_array('pages', $parts, true) ? $this->planPages() : [];
        if (!in_array('pages', $parts, true)) {
            $this->lookupPages();
        }

        if (!$dry) {
            $legalCodes = [];
            foreach ($this->pkg->data('pages.json') as $p) {
                if (is_array($p) && isset($p['code']) && $this->legalCode($p) !== '') {
                    $legalCodes[(string) $p['code']] = $this->legalCode($p);
                }
            }
            foreach ($pages as $i => $plan) {
                $pages[$i]['id'] = $this->writePage($plan);
                $this->pageIds[(string) $plan['code']] = (int) $pages[$i]['id'];
                if (isset($legalCodes[(string) $plan['code']]) && (int) $pages[$i]['id'] > 0) {
                    (new Legal($this->app))->register($legalCodes[(string) $plan['code']], (int) $pages[$i]['id']);
                }
            }
        }
        $menus = in_array('menus', $parts, true) ? $this->planMenus($notes) : [];
        if (!$dry) {
            foreach ($menus as $plan) {
                if ($plan['action'] !== 'same') {
                    $this->cms()->menus()->save((string) $plan['code'], (string) $plan['name'], $plan['items']);
                }
            }
            if ($theme !== null && $theme['action'] !== 'same' && $theme['action'] !== 'skip') {
                $this->writeTheme($theme);
            }
        }

        $extra = [];
        foreach (self::NOT_APPLIED as $file) {
            if ($this->pkg->has($file)) {
                $extra[] = $file;
            }
        }
        if ($extra !== []) {
            $notes[] = 'The package also brings ' . implode(', ', $extra) . ': that data is not loaded here (only pages, menus and the theme are applied).';
        }

        if (!$dry && ($pages !== [] || $menus !== [] || ($theme !== null && $theme['action'] !== 'skip'))) {
            $this->app->invalidate(['cms', 'home', 'pages', 'settings']);
            $this->app->cache()->flush();
        }

        return $this->result($pages, $menus, $theme, $notes);
    }

    // ------------------------------------------------------------------ tema

    /**
     * @param string[] $notes
     *
     * @return array<string,mixed>
     */
    private function planTheme(array &$notes): array
    {
        $meta = $this->pkg->meta();
        $slug = (string) $meta['theme'];
        $options = isset($meta['theme_options']) && is_array($meta['theme_options']) ? $meta['theme_options'] : [];
        if (!$this->app->has('themes')) {
            $notes[] = 'The themes module is not active in this site: the theme «' . $slug . '» was not changed.';

            return ['slug' => $slug, 'action' => 'skip', 'options' => $options];
        }
        $themes = $this->app->service('themes');
        if (!$themes->exists($slug)) {
            throw new \RuntimeException('The theme «' . $slug . '» of the package is not installed in this site');
        }
        $action = 'same';
        if ($themes->activeSlug() !== $slug) {
            $action = 'activate';
        } else {
            $current = $themes->options($slug)['values'];
            foreach ($options as $key => $value) {
                if (!array_key_exists((string) $key, $current) || (string) $current[(string) $key] !== (string) $value) {
                    $action = 'options';
                }
            }
        }

        return ['slug' => $slug, 'action' => $action, 'options' => $options];
    }

    /**
     * @param array<string,mixed> $plan
     */
    private function writeTheme(array $plan): void
    {
        $themes = $this->app->service('themes');
        try {
            if ($plan['action'] === 'activate') {
                $themes->activate((string) $plan['slug']);
            }
            if ($plan['options'] !== []) {
                foreach ($themes->saveOptions((string) $plan['slug'], $plan['options']) as $key => $error) {
                    throw new \RuntimeException('The theme option «' . $key . '» was not saved: ' . $error);
                }
            }
        } catch (ThemeException $e) {
            throw new \RuntimeException('The theme «' . $plan['slug'] . '» could not be applied: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------------ páginas

    /**
     * Lo que se haría con cada página del paquete, sin escribir nada.
     *
     * @return array<int,array<string,mixed>>
     */
    private function planPages(): array
    {
        $db = $this->app->db();
        $plans = [];
        foreach (array_values($this->pkg->data('pages.json')) as $i => $p) {
            $code = (string) $p['code'];
            $path = $this->pagePath($p);
            $hit = $this->app->slugs()->resolve($this->lang, $path);
            if ($hit !== null && $hit['type'] !== 'page') {
                throw new \RuntimeException('Page «' . $code . '»: the address «' . $path . '» is already used by another kind of content (' . $hit['type'] . ')');
            }
            if ($hit !== null && !empty($p['keep'])) {
                $this->pageIds[$code] = (int) $hit['entity_id'];
                $plans[] = ['code' => $code, 'path' => $path, 'action' => 'keep', 'id' => (int) $hit['entity_id'], 'want' => []];
                continue;
            }
            $want = [
                'title' => (string) $p['title'],
                'blocks' => $this->blocksJson($p, $code),
                'excerpt' => isset($p['excerpt']) && is_string($p['excerpt']) ? $p['excerpt'] : '',
                'meta_title' => isset($p['meta_title']) && is_string($p['meta_title']) ? $p['meta_title'] : '',
                'meta_description' => isset($p['meta_description']) && is_string($p['meta_description']) ? $p['meta_description'] : '',
                'status' => !isset($p['status']) || $p['status'] === 'published' ? 'published' : 'draft',
                'home' => !empty($p['home']),
                'position' => isset($p['position']) ? (int) $p['position'] : $i,
            ];
            $id = null;
            $action = 'create';
            if ($hit !== null) {
                $id = (int) $hit['entity_id'];
                $row = $db->row('SELECT p.status AS status, p.is_home AS is_home, x.title AS title, x.blocks AS blocks, x.excerpt AS excerpt, x.meta_title AS meta_title, x.meta_description AS meta_description FROM {pages} p LEFT JOIN {page_i18n} x ON x.page_id = p.id AND x.lang = ? WHERE p.id = ?', [$this->lang, $id]);
                $action = $row !== null && $this->samePage($row, $want) ? 'same' : 'update';
                $this->pageIds[$code] = $id;
            } else {
                $this->pageIds[$code] = $this->dry ? -(++$this->pending) : 0;
            }
            $plans[] = ['code' => $code, 'path' => $path, 'action' => $action, 'id' => $id, 'want' => $want];
        }

        return $plans;
    }

    /**
     * ¿La página del sitio ya dice lo que dice el paquete? En un ensayo no se pueden crear las imágenes, así que se compara sin sus identificadores.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $want
     */
    private function samePage(array $row, array $want): bool
    {
        if ((string) $row['status'] !== $want['status'] || ($want['home'] && (int) $row['is_home'] !== 1)) {
            return false;
        }
        foreach (['title', 'excerpt', 'meta_title', 'meta_description'] as $field) {
            if ((string) $row[$field] !== $want[$field]) {
                return false;
            }
        }

        return $this->comparable((string) $row['blocks']) === $this->comparable((string) $want['blocks']);
    }

    private function comparable(string $blocks): string
    {
        return $this->dry ? (string) preg_replace('/"media_id":\d+/', '"media_id":0', $blocks) : $blocks;
    }

    /**
     * Crea o actualiza una página. Devuelve su identificador.
     *
     * @param array<string,mixed> $plan
     */
    private function writePage(array $plan): int
    {
        if ($plan['action'] === 'keep') {
            return (int) $plan['id'];
        }
        $db = $this->app->db();
        $want = $plan['want'];
        $now = $this->app->clock()->sql();
        $texts = [
            'title' => $want['title'], 'blocks' => $want['blocks'],
            'excerpt' => $want['excerpt'] !== '' ? $want['excerpt'] : null,
            'meta_title' => $want['meta_title'] !== '' ? $want['meta_title'] : null,
            'meta_description' => $want['meta_description'] !== '' ? $want['meta_description'] : null,
        ];
        $published = $want['status'] === 'published';
        if ($plan['action'] === 'create') {
            $id = $db->insert('pages', [
                'status' => $want['status'], 'template' => 'default', 'parent_id' => null, 'position' => $want['position'],
                'noindex' => 0, 'is_home' => $want['home'] ? 1 : 0, 'published_at' => $published ? $now : null, 'author_id' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('page_i18n', ['page_id' => $id, 'lang' => $this->lang] + $texts);
            $this->app->slugs()->set('page', $id, $this->lang, (string) $plan['path']);
        } else {
            $id = (int) $plan['id'];
            if ($plan['action'] === 'same') {
                return $id;
            }
            (new Revisions($this->app))->record('page', $id);
            $main = ['status' => $want['status'], 'updated_at' => $now];
            if ($published && $db->value('SELECT published_at FROM {pages} WHERE id = ?', [$id]) === null) {
                $main['published_at'] = $now;
            }
            if ($want['home']) {
                $main['is_home'] = 1;
            }
            $db->update('pages', $main, ['id' => $id]);
            if ($db->value('SELECT id FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, $this->lang]) !== null) {
                $db->update('page_i18n', $texts, ['page_id' => $id, 'lang' => $this->lang]);
            } else {
                $db->insert('page_i18n', ['page_id' => $id, 'lang' => $this->lang] + $texts);
            }
        }
        if ($want['home']) {
            // Una sola portada: las demás páginas dejan de serlo (no se borra ninguna).
            $db->run('UPDATE {pages} SET is_home = 0 WHERE id <> ? AND is_home = 1', [$id]);
        }

        return $id;
    }

    /**
     * @param array<string,mixed> $p
     */
    private function pagePath(array $p): string
    {
        return isset($p['slug']) && is_string($p['slug']) && $p['slug'] !== '' ? $p['slug'] : (string) $p['code'];
    }

    /**
     * Los identificadores de las páginas del paquete que el sitio ya tiene (cuando solo se aplican los menús).
     */
    private function lookupPages(): void
    {
        foreach (array_values($this->pkg->data('pages.json')) as $p) {
            $hit = $this->app->slugs()->resolve($this->lang, $this->pagePath($p));
            if ($hit !== null && $hit['type'] === 'page') {
                $this->pageIds[(string) $p['code']] = (int) $hit['entity_id'];
            }
        }
    }

    /**
     * Los bloques de una página con sus referencias por código cambiadas por identificadores de este sitio.
     *
     * @param array<string,mixed> $p
     */
    private function blocksJson(array $p, string $code): string
    {
        // Una página legal («legal»: código de Legal) no lleva un texto de ejemplo: la escribe el generador de páginas legales,
        // con el bloque de datos de la empresa que lee Ajustes al pintar (lo pidió el usuario el 14/11/2026 para todas las tiendas).
        $legal = $this->legalCode($p);
        if ($legal !== '') {
            return (string) json_encode(BlockSchema::validate((new Legal($this->app))->blocksFor($legal, $this->lang)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        try {
            $resolved = BlockRefs::resolve(is_array($p['blocks']) ? $p['blocks'] : [], function (string $kind, string $ref): ?int {
                return $this->reference($kind, $ref);
            });
            $clean = BlockSchema::validate($resolved);
        } catch (\InvalidArgumentException $e) {
            throw new \RuntimeException('Page «' . $code . '»: ' . $e->getMessage());
        }

        return (string) json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * El código de página legal de una entrada de pages.json («legal»: "privacy"…), o '' si no lo es.
     *
     * @param array<string,mixed> $p
     */
    private function legalCode(array $p): string
    {
        $code = isset($p['legal']) && is_string($p['legal']) ? $p['legal'] : '';

        return $code !== '' && class_exists(Legal::class) && isset(Legal::pages()[$code]) ? $code : '';
    }

    /**
     * Identificador, en ESTE sitio, de lo que un bloque nombra por código: una imagen del paquete (se guarda en la biblioteca, una
     * sola vez por contenido), un producto por su referencia o una categoría de primer nivel por su dirección.
     */
    private function reference(string $kind, string $code): ?int
    {
        $db = $this->app->db();
        if ($kind === 'media') {
            $path = $this->pkg->mediaPath($code);
            if ($path === null) {
                return null;
            }
            if ($this->dry) {
                return 1;
            }
            if (!$this->app->has('media')) {
                throw new \RuntimeException('The package has images and the media module is not active in this site');
            }
            $alt = ucfirst(str_replace(['-', '_'], ' ', (string) preg_replace('/\.[^.]*$/', '', $code)));
            $row = $this->app->service('media')->store($path, $code, ['alt' => $alt]);

            return (int) $row['id'];
        }
        if (!$this->app->has('catalog')) {
            return null;
        }
        if ($kind === 'product') {
            $id = $db->value('SELECT id FROM {products} WHERE sku = ?', [$code]);

            return $id === null ? null : (int) $id;
        }
        $hit = $this->app->slugs()->resolve($this->lang, TaxonomySlugs::categoryPath($this->app, null, $this->lang, $code));

        return $hit !== null && $hit['type'] === 'category' ? (int) $hit['entity_id'] : null;
    }

    // ------------------------------------------------------------------ menús

    /**
     * @param string[] $notes
     *
     * @return array<int,array<string,mixed>>
     */
    private function planMenus(array &$notes): array
    {
        $db = $this->app->db();
        $menus = $this->cms()->menus();
        $plans = [];
        foreach (array_values($this->pkg->data('menus.json')) as $menu) {
            $code = (string) $menu['code'];
            $items = [];
            foreach (array_values(isset($menu['items']) && is_array($menu['items']) ? $menu['items'] : []) as $item) {
                $items[] = $this->menuItem($item, 0, $code, $notes);
                foreach (array_values(isset($item['children']) && is_array($item['children']) ? $item['children'] : []) as $child) {
                    $items[] = $this->menuItem($child, 1, $code, $notes);
                }
            }
            $items = array_values(array_filter($items));
            $name = (string) $menu['name'];
            $existing = $db->row('SELECT id, name FROM {menus} WHERE code = ?', [$code]);
            if ($existing === null) {
                $action = 'create';
            } else {
                $same = (string) $existing['name'] === $name && $this->shape($menus->editable($code)) === $this->shape($items);
                $action = $same ? 'same' : 'update';
            }
            $plans[] = ['code' => $code, 'name' => $name, 'action' => $action, 'items' => $items];
        }

        return $plans;
    }

    /**
     * Un elemento de menú como lo espera Menus::save(), o null si lo que enlaza no está en este sitio (se avisa).
     *
     * @param mixed               $item
     * @param string[]            $notes
     *
     * @return array<string,mixed>|null
     */
    private function menuItem($item, int $level, string $menu, array &$notes): ?array
    {
        if (!is_array($item)) {
            return null;
        }
        $type = (string) $item['type'];
        $ref = isset($item['ref']) && is_string($item['ref']) ? $item['ref'] : '';
        $id = 0;
        if ($type === 'page') {
            $id = isset($this->pageIds[$ref]) ? $this->pageIds[$ref] : 0;
        } elseif ($type === 'post') {
            $hit = $this->app->has('cms') ? $this->app->slugs()->resolve($this->lang, $this->cms()->prefix('post') . '/' . $ref) : null;
            $id = $hit !== null && $hit['type'] === 'post' ? (int) $hit['entity_id'] : 0;
        } elseif ($type === 'product' || $type === 'category') {
            $id = (int) $this->reference($type, $ref);
        }
        if (in_array($type, ['page', 'post', 'product', 'category'], true) && $id === 0) {
            $notes[] = 'Menu «' . $menu . '»: «' . (string) $item['label'] . '» was skipped (the ' . $type . ' «' . $ref . '» is not in this site).';

            return null;
        }

        return [
            'type' => $type, 'ref' => $id,
            'url' => isset($item['url']) && is_string($item['url']) ? $item['url'] : '',
            'blank' => !empty($item['blank']) ? 1 : 0,
            'level' => $level,
            'labels' => [$this->lang => (string) $item['label']],
        ];
    }

    /**
     * Lo que importa de una lista de elementos para saber si dos menús son el mismo.
     *
     * @param array<int,array<string,mixed>> $items
     */
    private function shape(array $items): string
    {
        $out = [];
        foreach ($items as $item) {
            $type = (string) $item['type'];
            $url = '';
            if ($type === 'url') {
                $safe = UrlFilter::safe((string) $item['url']);
                $url = $safe !== null ? $safe : '';
            }
            $labels = isset($item['labels']) && is_array($item['labels']) ? $item['labels'] : [];
            $out[] = [
                $type, $type === 'url' || $type === 'home' ? 0 : (int) $item['ref'], $url,
                !empty($item['blank']) && (string) $item['blank'] !== '0' ? 1 : 0, (int) $item['level'] === 1 ? 1 : 0,
                isset($labels[$this->lang]) ? trim(strip_tags((string) $labels[$this->lang])) : '',
            ];
        }

        return (string) json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    // ------------------------------------------------------------------ resultado

    /**
     * @param array<int,array<string,mixed>> $pages
     * @param array<int,array<string,mixed>> $menus
     * @param array<string,mixed>|null       $theme
     * @param string[]                       $notes
     *
     * @return array{dry:bool,pages:array<int,array<string,mixed>>,menus:array<int,array<string,mixed>>,theme:array<string,mixed>|null,notes:string[],counts:array<string,int>,line:string}
     */
    private function result(array $pages, array $menus, ?array $theme, array $notes): array
    {
        $created = 0;
        $updated = 0;
        $outPages = [];
        foreach ($pages as $plan) {
            $created += $plan['action'] === 'create' ? 1 : 0;
            $updated += $plan['action'] === 'update' ? 1 : 0;
            $outPages[] = ['code' => $plan['code'], 'path' => $plan['path'], 'action' => $plan['action']];
        }
        $outMenus = [];
        foreach ($menus as $plan) {
            $outMenus[] = ['code' => $plan['code'], 'action' => $plan['action'], 'items' => count($plan['items'])];
        }
        $outTheme = $theme !== null ? ['slug' => $theme['slug'], 'action' => $theme['action']] : null;
        $counts = ['pages' => count($pages), 'created' => $created, 'updated' => $updated, 'menus' => count($menus)];
        $themeName = $theme !== null && $theme['action'] !== 'skip' ? (string) $theme['slug'] : '-';

        return [
            'dry' => $this->dry, 'pages' => $outPages, 'menus' => $outMenus, 'theme' => $outTheme, 'notes' => $notes, 'counts' => $counts,
            'line' => ($this->dry ? 'DRY-RUN' : 'APPLIED') . ': ' . $counts['pages'] . ' páginas (' . $created . ' nuevas, ' . $updated . ' actualizadas), ' . $counts['menus'] . ' menús, tema ' . $themeName,
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
}
