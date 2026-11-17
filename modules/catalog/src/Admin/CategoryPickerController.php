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

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las dos direcciones del selector de categorías de la ficha de producto: consultar (catalog.view) y crear una categoría (catalog.edit).
 * Las respuestas son las de Response::json: llevan las tres cabeceras que impiden guardarlas en una caché.
 */
final class CategoryPickerController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * GET /admin/catalog/categories/pick
     *   ?q=texto      hasta 10 coincidencias por nombre, con su ruta
     *   ?ids=3,7      las rutas de esas categorías
     *   ?parent=N     las ramas de un nivel (0 = la raíz)
     *   ?tree=texto   el árbol desplegado: entero (vacío) o solo las ramas con coincidencias
     *   ?all=1        todas las rutas (el desplegable de la madre al crear)
     *
     * @param array<string,string> $params
     */
    public function lookup(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('catalog.view');
        if (array_key_exists('ids', $request->query)) {
            $ids = [];
            foreach (explode(',', $request->str('ids')) as $id) {
                if (ctype_digit(trim($id))) {
                    $ids[] = (int) trim($id);
                }
            }
            $items = [];
            foreach (CategoryPicker::paths($this->app, array_slice($ids, 0, 50)) as $id => $path) {
                $items[] = ['id' => $id, 'path' => $path];
            }

            return Response::json(['items' => $items]);
        }
        if (array_key_exists('parent', $request->query)) {
            return Response::json(['nodes' => CategoryPicker::level($this->app, max(0, $request->int('parent')))]);
        }
        if (array_key_exists('tree', $request->query)) {
            return Response::json(['nodes' => CategoryPicker::tree($this->app, $request->str('tree'))]);
        }
        if ($request->str('all') === '1') {
            return Response::json(['items' => CategoryPicker::everyPath($this->app)]);
        }

        return Response::json(['items' => CategoryPicker::search($this->app, $request->str('q'))]);
    }

    /**
     * POST /admin/catalog/categories/quick: crea una categoría (nombre y madre) y devuelve su identificador y su ruta.
     *
     * @param array<string,string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('catalog.edit');
        $result = CategoryPicker::create($this->app, $request->str('name'), $request->int('parent'));

        return Response::json($result, $result['ok'] ? 200 : 422);
    }
}
