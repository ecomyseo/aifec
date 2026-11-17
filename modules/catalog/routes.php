<?php
/**
 * AIFEC — catálogo: la búsqueda y el paso que elige la variante en la tienda, y las pantallas propias del panel (árbol de
 * categorías, valores de un atributo, variantes y existencias).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Catalog\Admin\AttributeValuesController;
use Aifec\Modules\Catalog\Admin\CategoryController;
use Aifec\Modules\Catalog\Admin\CategoryPickerController;
use Aifec\Modules\Catalog\Admin\ProductController;
use Aifec\Modules\Catalog\Admin\ProductStockController;
use Aifec\Modules\Catalog\Admin\VariantController;
use Aifec\Modules\Catalog\Front\ListingController;
use Aifec\Modules\Catalog\Front\ProductController as FrontProductController;

return static function (Router $r, App $app): void {
    // La búsqueda de la tienda (si otro módulo ya registró la suya, se respeta).
    if (!$r->has('search')) {
        $r->get('/' . trim($app->settings()->str('urls.search', 'buscar'), '/'), [ListingController::class, 'search'])->name('search');
    }
    // Comprar una ficha con variantes sin JavaScript: llegan las opciones elegidas y aquí se resuelve la variante.
    $r->post('/catalogo/elegir-variante', [FrontProductController::class, 'choose'])->name('catalog.choose')->middleware('csrf');

    $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
        // El listado de categorías es un árbol: se adelanta a la ruta genérica de los recursos (las rutas sin parámetros se miran primero).
        $r->get('/r/categories', [CategoryController::class, 'index'])->name('admin.catalog.categories');
        // El selector de categorías de la ficha de producto: buscar, ver el árbol por ramas y crear una categoría sin salir.
        $r->get('/catalog/categories/pick', [CategoryPickerController::class, 'lookup'])->name('admin.catalog.category_pick');
        $r->post('/catalog/categories/quick', [CategoryPickerController::class, 'create'])->name('admin.catalog.category_quick');
        $r->get('/catalog/attributes/{id:\d+}/values', [AttributeValuesController::class, 'show'])->name('admin.catalog.attribute_values');
        $r->post('/catalog/attributes/{id:\d+}/values', [AttributeValuesController::class, 'save']);
        // Productos: variantes, copia y edición rápida de precio y existencias.
        $r->get('/catalog/products/{id:\d+}/variants', [VariantController::class, 'show'])->name('admin.catalog.variants');
        $r->post('/catalog/products/{id:\d+}/variants', [VariantController::class, 'save']);
        $r->post('/catalog/products/{id:\d+}/duplicate', [ProductController::class, 'duplicate']);
        $r->post('/catalog/products/quickfill', [ProductController::class, 'quickfill'])->name('admin.catalog.quickfill');
        $r->get('/catalog/stock', [ProductStockController::class, 'index'])->name('admin.catalog.stock');
        $r->post('/catalog/stock', [ProductStockController::class, 'save']);
    });
};
