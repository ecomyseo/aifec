<?php
/**
 * AIFEC — pantalla del árbol de categorías del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * El listado de categorías es un árbol (cada una sangrada bajo su madre, por posición), y eso un listado de tabla no lo da:
 * esta ruta se adelanta a la genérica del núcleo (/admin/r/categories). Alta, ficha, guardado y borrado siguen siendo los del recurso.
 */
final class CategoryController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function index(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('catalog.view');
        $canEdit = $this->app->auth()->can('catalog.edit');
        $q = $request->str('q');
        $matches = $q !== '' ? $this->matching($q) : null;

        $rows = [];
        foreach (CategoryAdmin::tree($this->app) as $node) {
            if ($matches !== null && !isset($matches[$node['id']])) {
                continue;
            }
            $rows[] = $node + [
                'edit_url' => Admin::resourceUrl($this->app, 'categories', $node['id']),
                'delete_url' => Admin::resourceUrl($this->app, 'categories', $node['id'] . '/delete'),
            ];
        }
        $actions = $canEdit
            ? '<a class="af-btn af-btn-primary" href="' . e(Admin::resourceUrl($this->app, 'categories', 'new')) . '">' . Admin::icon('plus') . e(t('New')) . '</a>'
            : '';

        return Admin::page($this->app, 'catalog::admin/category_tree', [
            'rows' => $rows,
            'q' => $q,
            'canEdit' => $canEdit,
            'listUrl' => Admin::resourceUrl($this->app, 'categories'),
            'newUrl' => Admin::resourceUrl($this->app, 'categories', 'new'),
        ], [
            'title' => t('Categories'),
            'actions' => $actions,
            'help' => Admin::helpText("The categories are shown as a tree, in the order they have in the shop.\nA category with subcategories or with products cannot be deleted.\nThe address of a category includes the one of its parents; moving it changes them all and the old ones keep redirecting."),
        ]);
    }

    /**
     * Identificadores de las categorías cuyo nombre (en cualquier idioma) contiene el texto buscado.
     *
     * @return array<int,bool>
     */
    private function matching(string $q): array
    {
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $found = [];
        foreach ($this->app->db()->column("SELECT DISTINCT category_id FROM {category_i18n} WHERE name LIKE ? ESCAPE '!'", [$like]) as $id) {
            $found[(int) $id] = true;
        }

        return $found;
    }
}
