<?php
/**
 * AIFEC — rutas del módulo de plugins: la pantalla del panel, el informe del contrato, subir un ZIP y el asistente de creación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Plugins\PluginsController;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin/plugins', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [PluginsController::class, 'index'])->name('admin.plugins');
        $r->get('/new', [PluginsController::class, 'newForm'])->name('admin.plugins.new');
        $r->post('/new', [PluginsController::class, 'create']);
        $r->post('/upload', [PluginsController::class, 'upload']);
        $r->get('/{id:[a-z0-9-]+}/report', [PluginsController::class, 'report']);
        $r->post('/{id:[a-z0-9-]+}/enable', [PluginsController::class, 'enable']);
        $r->post('/{id:[a-z0-9-]+}/disable', [PluginsController::class, 'disable']);
        $r->post('/{id:[a-z0-9-]+}/validate', [PluginsController::class, 'validate']);
        $r->post('/{id:[a-z0-9-]+}/uninstall', [PluginsController::class, 'uninstall']);
        $r->post('/{id:[a-z0-9-]+}/grant', [PluginsController::class, 'grant']);
    });
};
