<?php
/**
 * AIFEC — rutas de sitios: el panel maestro y la página pública de demos. Solo existen en el sitio maestro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Sites\Demos;
use Aifec\Modules\Sites\DemosController;
use Aifec\Modules\Sites\SitesController;

return static function (Router $r, App $app): void {
    if (!Demos::isMaster($app)) {
        return;
    }

    // Lo público: la lista de demostraciones y la captura de portada de cada paquete.
    $r->get('/demos', [DemosController::class, 'show'])->name('demos');
    $r->get('/demo-preview/{package:[a-z0-9-]+}', [DemosController::class, 'preview'])->name('demos.preview');

    $r->group(['prefix' => '/admin/sites', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [SitesController::class, 'index'])->name('admin.sites');
        $r->get('/open/{id:[a-z0-9-]+}', [SitesController::class, 'open']);
        $r->post('/create', [SitesController::class, 'create']);
        $r->post('/reset/{id:[a-z0-9-]+}', [SitesController::class, 'reset']);
        $r->post('/delete/{id:[a-z0-9-]+}', [SitesController::class, 'delete']);
        $r->post('/settings', [SitesController::class, 'settings']);
    });
};
