<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

/**
 * El selector de categorías de la ficha de producto: etiquetas con la principal marcada, un buscador que añade y un árbol en
 * un cuadro. Aquí está lo que decide el servidor: las coincidencias del buscador, las ramas del árbol, crear una categoría sin
 * salir y el HTML del campo (el guion es assets/category-picker.js).
 *
 * Todo se calcula sobre la lista que ya da CategoryAdmin::tree() (una consulta y un recorrido, también con miles de categorías).
 * El HTML lleva las etiquetas ya pintadas, pero NO los campos «categories[]»: los crea el guion a partir de ellas. Sin JavaScript
 * se usa un respaldo dentro de <noscript> con las casillas de siempre, y así el envío nunca lleva la misma categoría dos veces.
 */
final class CategoryPicker
{
    public const MAX_RESULTS = 10;
    public const MAX_LEVEL = 500;
    public const MAX_TREE = 5000;
    public const MAX_FILTER = 200;
    /** Hasta este número de categorías el respaldo sin JavaScript lleva todas las casillas; con más, solo las del producto. */
    public const FALLBACK_ALL = 200;
    public const SEPARATOR = ' › ';

    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o', 'õ' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    /** Minúsculas y sin tildes, para comparar nombres. */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower($text, 'UTF-8'), self::ACCENTS);
    }

    // ------------------------------------------------------------------ lo que sabe el servidor

    /**
     * Todas las categorías en orden de árbol, con su ruta («Hogar › Cocina»).
     *
     * @return array<int,array{id:int,parent:int,depth:int,name:string,path:string,fold:string,leaf:bool}>
     */
    public static function index(App $app): array
    {
        $tree = CategoryAdmin::tree($app);
        $known = [];
        foreach ($tree as $node) {
            $known[$node['id']] = true;
        }
        $parents = [];
        $out = [];
        $stack = [];
        foreach ($tree as $node) {
            $parent = $node['parent_id'] !== null && isset($known[$node['parent_id']]) ? (int) $node['parent_id'] : 0;
            $parents[$parent] = true;
            $name = $node['name'] !== '' ? $node['name'] : '#' . $node['id'];
            $stack[$node['depth']] = $name;
            $out[] = [
                'id' => $node['id'], 'parent' => $parent, 'depth' => $node['depth'], 'name' => $name,
                'path' => implode(self::SEPARATOR, array_slice($stack, 0, $node['depth'] + 1)), 'fold' => self::fold($name), 'leaf' => true,
            ];
        }
        foreach ($out as $i => $node) {
            $out[$i]['leaf'] = !isset($parents[$node['id']]);
        }

        return $out;
    }

    /**
     * Rutas de unas categorías.
     *
     * @param int[] $ids
     *
     * @return array<int,string> id => ruta (las que no existen no salen)
     */
    public static function paths(App $app, array $ids): array
    {
        $want = array_flip(array_map('intval', $ids));
        $out = [];
        foreach (self::index($app) as $node) {
            if (isset($want[$node['id']])) {
                $out[$node['id']] = $node['path'];
            }
        }

        return $out;
    }

    /**
     * Las mejores coincidencias por nombre, sin tildes ni mayúsculas: primero las que empiezan por lo escrito.
     *
     * @return array<int,array{id:int,name:string,path:string}>
     */
    public static function search(App $app, string $q, int $limit = self::MAX_RESULTS): array
    {
        $q = self::fold(trim($q));
        if (mb_strlen($q, 'UTF-8') < 2) {
            return [];
        }
        $starts = [];
        $inside = [];
        foreach (self::index($app) as $node) {
            $at = strpos($node['fold'], $q);
            if ($at === 0) {
                $starts[] = $node;
            } elseif ($at !== false) {
                $inside[] = $node;
            }
        }
        $out = [];
        foreach (array_slice(array_merge($starts, $inside), 0, max(1, $limit)) as $node) {
            $out[] = ['id' => $node['id'], 'name' => $node['name'], 'path' => $node['path']];
        }

        return $out;
    }

    /**
     * Las ramas de un nivel (0 = la raíz), cada una con si tiene hijas.
     *
     * @return array<int,array{id:int,p:int,n:string,c:int}>
     */
    public static function level(App $app, int $parent): array
    {
        $out = [];
        foreach (self::index($app) as $node) {
            if ($node['parent'] === $parent) {
                $out[] = self::node($node);
                if (count($out) >= self::MAX_LEVEL) {
                    break;
                }
            }
        }

        return $out;
    }

    /**
     * El árbol ya desplegado: entero (sin filtro, con tope) o solo las ramas con coincidencias (las coincidencias y sus madres).
     *
     * @return array<int,array{id:int,p:int,n:string,c:int}>
     */
    public static function tree(App $app, string $q): array
    {
        $index = self::index($app);
        $q = self::fold(trim($q));
        if ($q === '') {
            return array_map([self::class, 'node'], array_slice($index, 0, self::MAX_TREE));
        }
        $byId = [];
        foreach ($index as $node) {
            $byId[$node['id']] = $node;
        }
        $keep = [];
        $found = 0;
        foreach ($index as $node) {
            if (strpos($node['fold'], $q) === false) {
                continue;
            }
            if (++$found > self::MAX_FILTER) {
                break;
            }
            for ($id = $node['id']; $id > 0 && !isset($keep[$id]) && isset($byId[$id]); $id = $byId[$id]['parent']) {
                $keep[$id] = true;
            }
        }
        $out = [];
        foreach ($index as $node) {
            if (isset($keep[$node['id']])) {
                $out[] = self::node($node);
            }
        }

        return $out;
    }

    /**
     * Todas las rutas, para el desplegable de la madre al crear una categoría.
     *
     * @return array<int,array{id:int,path:string}>
     */
    public static function everyPath(App $app): array
    {
        $out = [];
        foreach (array_slice(self::index($app), 0, self::MAX_TREE) as $node) {
            $out[] = ['id' => $node['id'], 'path' => $node['path']];
        }

        return $out;
    }

    /**
     * @param array{id:int,parent:int,name:string,leaf:bool} $node
     *
     * @return array{id:int,p:int,n:string,c:int}
     */
    private static function node(array $node): array
    {
        return ['id' => $node['id'], 'p' => $node['parent'], 'n' => $node['name'], 'c' => $node['leaf'] ? 0 : 1];
    }

    // ------------------------------------------------------------------ crear sin salir de la ficha

    /**
     * Crea una categoría con la misma validación y la misma dirección amigable que el formulario de categorías.
     *
     * @return array{ok:bool,message:string,id?:int,name?:string,path?:string}
     */
    public static function create(App $app, string $name, int $parent): array
    {
        $db = $app->db();
        $name = trim(mb_substr(trim($name), 0, 191, 'UTF-8'));
        if ($name === '') {
            return ['ok' => false, 'message' => t('Write a name for the category.')];
        }
        $checked = CategoryAdmin::beforeSave(['parent_id' => $parent > 0 ? $parent : null], null, $app);
        if (isset($checked['_errors'])) {
            return ['ok' => false, 'message' => implode(' ', array_map('strval', (array) $checked['_errors']))];
        }
        $parentId = $checked['parent_id'] !== null ? (int) $checked['parent_id'] : 0;
        $folded = self::fold($name);
        foreach (self::index($app) as $node) {
            if ($node['parent'] === $parentId && $node['fold'] === $folded) {
                return ['ok' => false, 'message' => t('There is already a category with that name there.')];
            }
        }

        $lang = $app->defaultLang();
        $now = $app->clock()->sql();
        $id = (int) $db->transaction(static function () use ($db, $parentId, $name, $lang, $now): int {
            $position = (int) $db->value($parentId > 0 ? 'SELECT COALESCE(MAX(position), 0) + 1 FROM {categories} WHERE parent_id = ?' : 'SELECT COALESCE(MAX(position), 0) + 1 FROM {categories} WHERE parent_id IS NULL', $parentId > 0 ? [$parentId] : []);
            $id = $db->insert('categories', [
                'parent_id' => $parentId > 0 ? $parentId : null, 'path' => '', 'depth' => 0, 'position' => $position,
                'active' => 1, 'in_menu' => 1, 'product_count' => 0, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $db->insert('category_i18n', ['category_id' => $id, 'lang' => $lang, 'name' => $name]);

            return $id;
        });
        CategoryAdmin::afterSave($id, ['name' => [$lang => $name], 'slug' => [$lang => '']], null, $app);
        $app->invalidate(['catalog']);
        Admin::audit($app, 'create', 'categories', (string) $id, $name);
        $paths = self::paths($app, [$id]);

        return ['ok' => true, 'message' => t('Category created.'), 'id' => $id, 'name' => $name, 'path' => isset($paths[$id]) ? $paths[$id] : $name];
    }

    // ------------------------------------------------------------------ el campo de la ficha

    /**
     * Las categorías que se aceptan al guardar: todas, para que un identificador que no existe se rechace.
     *
     * @return array<string,string> id => ruta
     */
    public static function allOptions(App $app): array
    {
        $out = [];
        foreach (self::index($app) as $node) {
            $out[(string) $node['id']] = $node['path'];
        }

        return $out;
    }

    /**
     * Las categorías del producto, para el desplegable de la principal: las que lleva el envío si lo hay, y si no las guardadas.
     *
     * @param string[] $saved
     *
     * @return array<string,string> id => ruta
     */
    public static function chosenOptions(App $app, array $saved): array
    {
        $posted = ProductForm::posted($app);
        $ids = isset($posted['categories']) && is_array($posted['categories']) ? $posted['categories'] : $saved;

        return self::labelled($app, $ids);
    }

    /**
     * @param mixed[] $ids
     *
     * @return array<string,string> id => ruta (un identificador que no existe sale como «#id»)
     */
    private static function labelled(App $app, array $ids): array
    {
        $clean = [];
        foreach ($ids as $id) {
            if (is_scalar($id) && is_numeric($id) && (int) $id > 0) {
                $clean[(int) $id] = (int) $id;
            }
        }
        $paths = self::paths($app, array_values($clean));
        $out = [];
        foreach ($clean as $id) {
            $out[(string) $id] = isset($paths[$id]) ? $paths[$id] : '#' . $id;
        }

        return $out;
    }

    /**
     * El control: etiquetas ya pintadas, buscador y cuadro del árbol (escondidos hasta que el guion los activa) y, dentro de
     * <noscript>, el respaldo con casillas. Carga el guion y la hoja una sola vez por página.
     *
     * @param mixed               $value ids elegidos
     * @param array<string,mixed> $all   valores del formulario
     */
    public static function render(App $app, string $name, string $id, $value, array $all, string $savedMain): string
    {
        $chosen = self::labelled($app, is_array($value) ? $value : []);
        $main = isset($all['main_category']) && is_scalar($all['main_category']) ? (string) $all['main_category'] : $savedMain;
        if (!isset($chosen[$main])) {
            $main = $chosen !== [] ? (string) array_keys($chosen)[0] : '';
        }
        $view = $app->view();
        $view->push('admin.head', '<link rel="stylesheet" href="' . e($app->url()->asset('m', 'catalog', 'category-picker.css')) . '">');
        $view->push('admin.foot', '<script src="' . e($app->url()->asset('m', 'catalog', 'category-picker.js')) . '" defer></script>');

        $texts = [
            'remove' => t('Remove {name}'), 'none' => t('This product has no category yet.'), 'empty' => t('No category matches.'),
            'create' => t('Create “{name}” in'), 'go' => t('Create'), 'top' => t('None (top level)'), 'failed' => t('The categories could not be loaded.'),
        ];
        $attrs = ' data-url="' . e($app->url()->plain('/admin/catalog/categories/pick')) . '"'
            . ($app->auth()->can('catalog.edit') ? ' data-new="' . e($app->url()->plain('/admin/catalog/categories/quick')) . '"' : '');
        foreach ($texts as $key => $text) {
            $attrs .= ' data-t-' . $key . '="' . e($text) . '"';
        }
        $uid = e($id);

        $html = '<div class="af-cp" data-cp hidden data-name="' . e($name) . '"' . $attrs . '>';
        $html .= '<ul class="af-cp-chips" data-cp-chips aria-label="' . e(t('Categories')) . '">';
        foreach ($chosen as $categoryId => $path) {
            $html .= self::chip((string) $categoryId, $path, (string) $categoryId === $main, t('Remove {name}', ['name' => $path]));
        }
        $html .= '</ul><p class="af-cp-none af-muted" data-cp-note' . ($chosen !== [] ? ' hidden' : '') . '>' . e($texts['none']) . '</p>';
        $html .= '<template data-cp-tpl>' . self::chip('', '', false, '') . '</template>';
        $html .= '<template data-cp-node><li><div class="af-cp-row"><button type="button" class="af-cp-t" data-cp-t aria-expanded="false"></button><label><input type="checkbox"> <span></span></label></div></li></template>';
        $html .= '<div class="af-cp-find"><input type="text" class="af-input" id="' . $uid . '-q" data-cp-q role="combobox" aria-expanded="false" aria-controls="' . $uid . '-list" aria-autocomplete="list" autocomplete="off" placeholder="' . e(t('Search a category')) . '" aria-label="' . e(t('Search a category')) . '">'
            . '<button type="button" class="af-btn" data-cp-open>' . e(t('See all')) . '</button>'
            . '<ul class="af-cp-list" role="listbox" id="' . $uid . '-list" data-cp-list hidden></ul></div>';
        $html .= '<dialog class="af-modal af-modal-lg" data-cp-dlg aria-label="' . e(t('Categories')) . '"><div class="af-modal-head"><h2>' . e(t('Categories')) . '</h2>'
            . '<button type="button" class="af-btn af-btn-sm" data-cp-close>' . e(t('Cancel')) . '</button></div>'
            . '<div class="af-modal-body"><div class="af-toolbar"><input type="text" class="af-input" data-cp-filter form="af-cp-none" placeholder="' . e(t('Filter by name')) . '" aria-label="' . e(t('Filter by name')) . '" autocomplete="off">'
            . '<button type="button" class="af-btn af-btn-sm" data-cp-all="1">' . e(t('Expand all')) . '</button>'
            . '<button type="button" class="af-btn af-btn-sm" data-cp-all="0">' . e(t('Collapse all')) . '</button></div>'
            . '<ul class="af-cp-tree" data-cp-tree role="tree"></ul></div>'
            . '<div class="af-modal-foot"><button type="button" class="af-btn af-btn-primary" data-cp-apply>' . e(t('Apply')) . '</button></div></dialog>';
        $html .= '</div>';

        return $html . '<noscript>' . self::fallback($app, $name, array_keys($chosen)) . '</noscript>';
    }

    /**
     * Una etiqueta: la ruta, el botón de opción de «Principal» (sin nombre: no viaja) y el botón de quitar.
     */
    private static function chip(string $id, string $path, bool $main, string $remove): string
    {
        return '<li class="af-cp-chip" data-cp-id="' . e($id) . '"><label class="af-cp-main"><input type="radio" data-cp-main' . ($main ? ' checked' : '') . '> ' . e(t('Main')) . '</label>'
            . '<span class="af-cp-name" data-cp-name>' . e($path) . '</span>'
            . '<button type="button" class="af-cp-x" data-cp-x aria-label="' . e($remove) . '">×</button></li>';
    }

    /**
     * Respaldo sin JavaScript: las casillas de siempre. Con muchas categorías, solo las del producto (para poder quitarlas).
     *
     * @param array<int|string,mixed> $chosen
     */
    private static function fallback(App $app, string $name, array $chosen): string
    {
        $mark = array_flip(array_map('strval', $chosen));
        $options = self::allOptions($app);
        $all = count($options) <= self::FALLBACK_ALL;
        $html = '<div class="af-checks">';
        foreach ($options as $key => $path) {
            if (!$all && !isset($mark[(string) $key])) {
                continue;
            }
            $html .= '<label class="af-check"><input type="checkbox" name="' . e($name) . '[]" value="' . e($key) . '"' . (isset($mark[(string) $key]) ? ' checked' : '') . '><span>' . e($path) . '</span></label>';
        }

        return $html . '</div>' . ($all ? '' : '<div class="af-hint">' . e(t('To add more categories, turn on JavaScript.')) . '</div>');
    }
}
