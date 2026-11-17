<?php
/**
 * AIFEC — categorías en el panel: formulario, árbol, rutas, direcciones, recuento de productos y borrado seguro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Categories;
use Aifec\Modules\Catalog\Texts;

/**
 * Sus piezas las usa el recurso «categories» de admin.php (formulario, before_save, after_save, before_delete, after_delete)
 * y la pantalla del árbol (CategoryController).
 *
 * La columna path guarda los identificadores desde la raíz con el propio al final («3/12/40») y depth el nivel (0 = raíz).
 * Los textos viven en category_i18n; los «datos clave» de cada idioma, como JSON en category_i18n.facts.
 * La dirección de una categoría lleva la de sus madres, y al mover una se recalculan las de todas sus descendientes.
 */
final class CategoryAdmin
{
    // ------------------------------------------------------------------ árbol

    /**
     * Todas las categorías en orden de árbol (cada una detrás de su madre, hermanas por posición).
     *
     * @return array<int,array{id:int,parent_id:?int,depth:int,name:string,active:bool,in_menu:bool,product_count:int,position:int}>
     */
    public static function tree(App $app): array
    {
        $rows = $app->db()->select('SELECT id, parent_id, position, active, in_menu, product_count FROM {categories} ORDER BY position, id');
        $names = self::names($app);
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $children = [];
        foreach ($rows as $row) {
            $parent = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
            $children[isset($byId[$parent]) ? $parent : 0][] = (int) $row['id'];
        }
        $out = [];
        $seen = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, &$seen, $children, $byId, $names): void {
            foreach (isset($children[$parent]) ? $children[$parent] : [] as $id) {
                if (isset($seen[$id])) {
                    continue;
                }
                $seen[$id] = true;
                $row = $byId[$id];
                $out[] = [
                    'id' => $id,
                    'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null,
                    'depth' => $depth,
                    'name' => isset($names[$id]) ? $names[$id] : '',
                    'active' => (int) $row['active'] === 1,
                    'in_menu' => (int) $row['in_menu'] === 1,
                    'product_count' => (int) $row['product_count'],
                    'position' => (int) $row['position'],
                ];
                $walk($id, $depth + 1);
            }
        };
        $walk(0, 0);

        return $out;
    }

    /**
     * Nombre de cada categoría en el idioma principal (o el primero que tenga).
     *
     * @return array<int,string>
     */
    public static function names(App $app): array
    {
        $default = $app->defaultLang();
        $byId = [];
        foreach ($app->db()->select('SELECT category_id, lang, name FROM {category_i18n}') as $row) {
            $byId[(int) $row['category_id']][(string) $row['lang']] = (string) $row['name'];
        }
        $out = [];
        foreach ($byId as $id => $langs) {
            $name = isset($langs[$default]) ? $langs[$default] : '';
            if ($name === '') {
                foreach ($langs as $candidate) {
                    if ($candidate !== '') {
                        $name = $candidate;
                        break;
                    }
                }
            }
            $out[$id] = $name;
        }

        return $out;
    }

    /**
     * Identificadores de una categoría y de todas sus descendientes.
     *
     * @return array<int,bool> id => true
     */
    public static function subtreeIds(App $app, int $id): array
    {
        $db = $app->db();
        $set = [$id => true];
        $queue = [$id];
        while ($queue !== []) {
            $current = (int) array_shift($queue);
            foreach ($db->column('SELECT id FROM {categories} WHERE parent_id = ?', [$current]) as $child) {
                $child = (int) $child;
                if (!isset($set[$child])) {
                    $set[$child] = true;
                    $queue[] = $child;
                }
            }
        }

        return $set;
    }

    /**
     * Lista de categorías madre posibles, sangrada por nivel. Al editar una, sin ella misma ni sus descendientes.
     *
     * @return array<string,string> id => texto
     */
    public static function parentOptions(App $app, ?int $excludeId): array
    {
        $excluded = $excludeId !== null ? self::subtreeIds($app, $excludeId) : [];
        $options = [];
        foreach (self::tree($app) as $node) {
            if (isset($excluded[$node['id']])) {
                continue;
            }
            $options[(string) $node['id']] = str_repeat('— ', $node['depth']) . ($node['name'] !== '' ? $node['name'] : '#' . $node['id']);
        }

        return $options;
    }

    // ------------------------------------------------------------------ definiciones del recurso

    /**
     * Listado genérico (el que usa la exportación CSV; la pantalla del árbol la pinta CategoryController).
     *
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        return [
            'columns' => [
                'name' => ['label' => 'Name', 'link' => true, 'sort' => true],
                'product_count' => ['label' => 'Products', 'type' => 'int', 'hide_sm' => true],
                'in_menu' => ['label' => 'Show in the menu', 'type' => 'bool', 'hide_sm' => true],
                'active' => ['label' => 'Active', 'type' => 'bool'],
                'position' => ['label' => 'Order', 'type' => 'int', 'sort' => true, 'hide_sm' => true],
            ],
            'search' => ['name'],
            'sort' => ['position', 'asc'],
            'per_page' => 50,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id): array
    {
        $segments = [];
        $facts = [];
        if ($id !== null) {
            $segments = TaxonomySlugs::segments($app, 'category', $id);
            foreach ($app->db()->select('SELECT lang, facts FROM {category_i18n} WHERE category_id = ?', [$id]) as $row) {
                $facts[(string) $row['lang']] = Texts::facts(isset($row['facts']) && $row['facts'] !== null ? (string) $row['facts'] : null);
            }
        }
        $fields = [
            'name' => ['label' => 'Name', 'i18n' => true, 'rules' => 'required|max:191', 'tab' => 'ctmain'],
            'parent_id' => ['type' => 'select', 'label' => 'Parent category', 'options' => self::parentOptions($app, $id), 'empty' => 'None (top level)',
                'translate' => false, 'free' => true, 'rules' => 'nullable|int', 'tab' => 'ctmain'],
            'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1, 'tab' => 'ctmain',
                'hint' => 'A category that is off disappears from the shop, with its subcategories.'],
            'in_menu' => ['type' => 'toggle', 'label' => 'Show in the menu', 'default' => 1, 'tab' => 'ctmain'],
            'media_id' => ['type' => 'image', 'label' => 'Image', 'tab' => 'ctmain'],
            'position' => ['type' => 'number', 'label' => 'Order', 'default' => 0, 'rules' => 'required|min:0', 'width' => 'sm', 'tab' => 'ctmain'],
            'slug' => ['type' => 'slug', 'label' => 'Address', 'i18n' => true, 'store' => false, 'rules' => 'max:90', 'default' => $segments, 'tab' => 'ctseo',
                'hint' => 'Leave it empty to take it from the name. The address of the parent category goes in front.'],
            'description' => ['type' => 'richtext', 'label' => 'Description', 'i18n' => true, 'tab' => 'ctseo'],
        ];
        $langs = $app->languages();
        foreach ($langs as $lang) {
            $fields['facts_' . $lang] = [
                'type' => 'repeater', 'label' => count($langs) > 1 ? t('Key facts') . ' (' . strtoupper($lang) . ')' : 'Key facts', 'store' => false, 'tab' => 'ctseo',
                'add' => 'Add a fact', 'hint' => 'Short facts that can be checked, for example a size or a material.',
                'default' => isset($facts[$lang]) ? $facts[$lang] : [],
                'fields' => [
                    'label' => ['label' => 'Label', 'rules' => 'max:120'],
                    'value' => ['label' => 'Value', 'rules' => 'max:255'],
                ],
            ];
        }
        $fields['meta_title'] = ['label' => 'Meta title', 'i18n' => true, 'rules' => 'max:191', 'tab' => 'ctseo', 'hint' => 'Around 60 characters.'];
        $fields['meta_description'] = ['type' => 'textarea', 'label' => 'Meta description', 'i18n' => true, 'rows' => 3, 'rules' => 'max:320', 'tab' => 'ctseo',
            'hint' => 'Around 155 characters.'];

        return ['tabs' => ['ctmain' => 'Category', 'ctseo' => 'Content and SEO'], 'fields' => $fields];
    }

    /**
     * Botones de la cabecera de la ficha.
     *
     * @param array<string,mixed>|null $row
     */
    public static function actions(?array $row, App $app): string
    {
        if ($row === null) {
            return '';
        }

        return '<a class="af-btn" target="_blank" rel="noopener" href="' . e($app->url()->entity('category', (int) $row['id'])) . '">' . Admin::icon('external') . e(t('View')) . '</a>';
    }

    // ------------------------------------------------------------------ guardar y borrar

    /**
     * Comprueba la categoría madre: tiene que existir y no puede ser ella misma ni una descendiente.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        $parent = isset($values['parent_id']) && $values['parent_id'] !== null && $values['parent_id'] !== '' ? (int) $values['parent_id'] : 0;
        if ($parent > 0) {
            if ($app->db()->find('categories', ['id' => $parent]) === null) {
                return ['_errors' => ['parent_id' => t('Choose one of the options')]];
            }
            if ($current !== null) {
                $inside = self::subtreeIds($app, (int) $current['id']);
                if (isset($inside[$parent])) {
                    return ['_errors' => ['parent_id' => t('A category cannot be moved inside itself or one of its subcategories.')]];
                }
            }
        }
        $values['parent_id'] = $parent > 0 ? $parent : null;

        return $values;
    }

    /**
     * Rutas y niveles, textos sobrantes, datos clave, direcciones y recuento de productos.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        self::refreshPaths($app, $id);
        self::dropEmptyTexts($app, $id, $values);
        self::saveFacts($app, $id, $values);
        self::saveSlugs($app, $id, $values);
        self::recount($app);
    }

    /**
     * @return string|null el motivo por el que no se puede borrar, o null
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        $db = $app->db();
        $id = (int) $row['id'];
        $children = (int) $db->value('SELECT COUNT(*) FROM {categories} WHERE parent_id = ?', [$id]);
        if ($children > 0) {
            return tn('This category has {n} subcategory. Move or delete it first.', 'This category has {n} subcategories. Move or delete them first.', $children);
        }
        $products = (int) $db->value('SELECT COUNT(DISTINCT product_id) FROM {product_categories} WHERE category_id = ?', [$id]);
        if ($products > 0) {
            return tn('This category has {n} product. Move it to another category first.', 'This category has {n} products. Move them to another category first.', $products);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        $app->slugs()->remove('category', (int) $row['id']);
    }

    /**
     * Pone en product_count los productos publicados de cada categoría, contando los de sus descendientes.
     * Pública para que el panel de productos la llame al guardar uno.
     */
    public static function recount(App $app): void
    {
        $db = $app->db();
        $cats = $db->select('SELECT id, path, product_count FROM {categories}');
        $paths = [];
        foreach ($cats as $cat) {
            $paths[(int) $cat['id']] = array_map('intval', explode('/', Categories::pathOf($cat)));
        }
        $members = [];
        $sql = "SELECT pc.category_id AS cid, pc.product_id AS pid FROM {product_categories} pc JOIN {products} pr ON pr.id = pc.product_id WHERE pr.status = 'published'";
        foreach ($db->select($sql) as $pair) {
            $cid = (int) $pair['cid'];
            if (!isset($paths[$cid])) {
                continue;
            }
            foreach ($paths[$cid] as $ancestor) {
                $members[$ancestor][(int) $pair['pid']] = true;
            }
        }
        $changed = false;
        foreach ($cats as $cat) {
            $id = (int) $cat['id'];
            $count = isset($members[$id]) ? count($members[$id]) : 0;
            if ($count !== (int) $cat['product_count']) {
                $db->update('categories', ['product_count' => $count], ['id' => $id]);
                $changed = true;
            }
        }
        if ($changed) {
            $app->invalidate(['catalog']);
        }
    }

    // ------------------------------------------------------------------ piezas

    /**
     * Calcula path y depth de la categoría y de todas sus descendientes, de arriba abajo.
     */
    private static function refreshPaths(App $app, int $id): void
    {
        $db = $app->db();
        $row = $db->find('categories', ['id' => $id]);
        if ($row === null) {
            return;
        }
        $parentId = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : 0;
        $parent = $parentId > 0 ? $db->find('categories', ['id' => $parentId]) : null;
        $path = $parent !== null ? Categories::pathOf($parent) . '/' . $id : (string) $id;
        $depth = $parent !== null ? (int) $parent['depth'] + 1 : 0;
        $db->update('categories', ['path' => $path, 'depth' => $depth], ['id' => $id]);

        $seen = [$id => true];
        $queue = [[$id, $path, $depth]];
        while ($queue !== []) {
            $item = array_shift($queue);
            foreach ($db->column('SELECT id FROM {categories} WHERE parent_id = ? ORDER BY position, id', [$item[0]]) as $child) {
                $child = (int) $child;
                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $childPath = $item[1] . '/' . $child;
                $db->update('categories', ['path' => $childPath, 'depth' => $item[2] + 1], ['id' => $child]);
                $queue[] = [$child, $childPath, $item[2] + 1];
            }
        }
    }

    /**
     * Una traducción sin nombre no es una traducción: se quita, y la tienda enseña el idioma principal.
     *
     * @param array<string,mixed> $values
     */
    private static function dropEmptyTexts(App $app, int $id, array $values): void
    {
        $default = $app->defaultLang();
        foreach ($app->languages() as $lang) {
            $name = isset($values['name'][$lang]) && is_string($values['name'][$lang]) ? trim($values['name'][$lang]) : '';
            if ($lang !== $default && $name === '') {
                $app->db()->delete('category_i18n', ['category_id' => $id, 'lang' => $lang]);
            }
        }
    }

    /**
     * @param array<string,mixed> $values
     */
    private static function saveFacts(App $app, int $id, array $values): void
    {
        foreach ($app->languages() as $lang) {
            $key = 'facts_' . $lang;
            if (!isset($values[$key]) || !is_array($values[$key])) {
                continue;
            }
            $rows = [];
            foreach ($values[$key] as $fact) {
                $label = is_array($fact) && isset($fact['label']) ? trim((string) $fact['label']) : '';
                $value = is_array($fact) && isset($fact['value']) ? trim((string) $fact['value']) : '';
                if ($label !== '' && $value !== '') {
                    $rows[] = ['label' => $label, 'value' => $value];
                }
            }
            $app->db()->update('category_i18n', ['facts' => $rows !== [] ? json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null], ['category_id' => $id, 'lang' => $lang]);
        }
    }

    /**
     * Direcciones de la categoría (con la de sus madres delante) y, si cambia la de ella, las de todas sus descendientes.
     *
     * @param array<string,mixed> $values
     */
    private static function saveSlugs(App $app, int $id, array $values): void
    {
        $db = $app->db();
        $slugs = $app->slugs();
        $default = $app->defaultLang();
        $row = $db->find('categories', ['id' => $id]);
        if ($row === null) {
            return;
        }
        $parentId = isset($row['parent_id']) && $row['parent_id'] !== null ? (int) $row['parent_id'] : null;
        $texts = [];
        foreach ($db->select('SELECT lang, name FROM {category_i18n} WHERE category_id = ?', [$id]) as $text) {
            $texts[(string) $text['lang']] = trim((string) $text['name']);
        }
        $fallback = 'category-' . $id;
        foreach ($app->languages() as $lang) {
            if ($lang !== $default && (!isset($texts[$lang]) || $texts[$lang] === '')) {
                continue;
            }
            $typed = isset($values['slug'][$lang]) && is_string($values['slug'][$lang]) ? trim($values['slug'][$lang]) : '';
            if ($typed === '') {
                $source = isset($texts[$lang]) && $texts[$lang] !== '' ? $texts[$lang] : (isset($texts[$default]) ? $texts[$default] : '');
                $segment = TaxonomySlugs::make($source, $fallback);
            } else {
                $segment = TaxonomySlugs::make($typed, $fallback);
            }
            $slugs->set('category', $id, $lang, TaxonomySlugs::categoryPath($app, $parentId, $lang, $segment));
        }

        // Las descendientes conservan su trozo propio y toman la dirección nueva de su madre, de arriba abajo.
        $seen = [$id => true];
        $queue = [$id];
        while ($queue !== []) {
            $current = (int) array_shift($queue);
            foreach ($db->column('SELECT id FROM {categories} WHERE parent_id = ? ORDER BY position, id', [$current]) as $child) {
                $child = (int) $child;
                if (isset($seen[$child])) {
                    continue;
                }
                $seen[$child] = true;
                $queue[] = $child;
                foreach ($app->languages() as $lang) {
                    $path = $slugs->path('category', $child, $lang);
                    if ($path === null) {
                        continue;
                    }
                    $segment = TaxonomySlugs::segment($path);
                    $new = TaxonomySlugs::categoryPath($app, $current, $lang, $segment);
                    if ($new !== $path) {
                        $slugs->set('category', $child, $lang, $new);
                    }
                }
            }
        }
    }
}
