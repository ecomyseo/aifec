<?php
/**
 * AIFEC — rutas de contenidos. En la tienda: el índice del blog, su canal de novedades y el envío de los formularios (con su marca
 * de tiempo). En el panel: vista previa, conversión y vista previa de bloques, restaurar versiones, menús, mensajes en CSV,
 * direcciones no encontradas y páginas legales. Las del recurso (altas, listados, borrados) son las genéricas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Cms\ContentController;
use Aifec\Modules\Cms\Front\BlockController;
use Aifec\Modules\Cms\Front\FormController;
use Aifec\Modules\Cms\Front\PageController;
use Aifec\Modules\Cms\MenuController;

return static function (Router $r, App $app): void {
    // La tienda: el índice del blog y su canal de novedades (con la palabra del ajuste «urls.post») y los formularios del contenido.
    $blog = '/' . trim($app->settings()->str('urls.post', 'blog'), '/');
    $r->get($blog, [PageController::class, 'blog'])->name('blog');
    $r->get($blog . '/feed.xml', [PageController::class, 'feed'])->name('blog.feed');
    $r->post('/formulario/{code:[a-z0-9][a-z0-9_-]*}', [FormController::class, 'submit'])->name('form.submit')->middleware('csrf');
    $r->get('/formulario/{code:[a-z0-9][a-z0-9_-]*}/marca', [FormController::class, 'stamp'])->name('form.stamp');
    // Un bloque suelto de una página («p12-3») o de una entrada («e5-0»), para repintarlo sin pedir la página.
    $r->get('/bloque/{id:[pe][0-9]+-[0-9]+}', [BlockController::class, 'show'])->name('cms.block');

    $r->group(['prefix' => '/admin/cms', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/preview/{type:page|post}/{id:\d+}', [ContentController::class, 'preview'])->name('admin.cms.preview');
        $r->post('/blocks/import', [ContentController::class, 'importBlocks'])->name('admin.cms.blocks.import');
        $r->post('/blocks/render', [ContentController::class, 'renderBlocks'])->name('admin.cms.blocks.render');
        $r->post('/revisions/{id:\d+}/restore', [ContentController::class, 'restore'])->name('admin.cms.restore');

        // «search» va antes que «{code}»: si no, el menú «search» se llevaría la petición.
        $r->get('/menus', [MenuController::class, 'index'])->name('admin.cms.menus');
        $r->get('/menus/search', [MenuController::class, 'search'])->name('admin.cms.menus.search');
        $r->get('/menus/{code:[a-z][a-z0-9_-]*}', [MenuController::class, 'edit']);
        $r->post('/menus/{code:[a-z][a-z0-9_-]*}', [MenuController::class, 'save']);

        $r->get('/entries/export', [ContentController::class, 'exportEntries'])->name('admin.cms.entries.export');

        $r->get('/not-found', [ContentController::class, 'notFound'])->name('admin.cms.not_found');
        $r->post('/not-found/redirect', [ContentController::class, 'createRedirect']);
        $r->post('/not-found/clear', [ContentController::class, 'clearNotFound']);

        $r->get('/legal', [ContentController::class, 'legal'])->name('admin.cms.legal');
        $r->post('/legal', [ContentController::class, 'createLegal']);
    });
};
