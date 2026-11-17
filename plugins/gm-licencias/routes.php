<?php
/**
 * AIFEC — GM licences: las rutas de la API de verificación, de la zona de cliente y de las acciones del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Gm\Licencias\AccountController;
use Gm\Licencias\AdminController;
use Gm\Licencias\ApiController;

return static function (Router $r, App $app): void {
    // La API que consultan los módulos instalados en las tiendas de los clientes: sin sesión ni ficha (la clave es el secreto) y con
    // su propio límite de uso.
    $r->post('/api/gm/licencia/verificar', [ApiController::class, 'verify'])->name('gm.license.verify');
    $r->post('/api/gm/licencia/desactivar', [ApiController::class, 'deactivate'])->name('gm.license.deactivate');

    // «Mis licencias»: bajo la zona de cliente.
    $account = '/' . trim($app->settings()->str('urls.account', 'mi-cuenta'), '/');
    $r->group(['prefix' => $account, 'middleware' => ['customer', 'csrf']], static function (Router $r): void {
        $r->get('/licencias', [AccountController::class, 'index'])->name('gm.licenses.account');
    });

    // Las acciones de la ficha de una licencia en el panel.
    $r->group(['prefix' => '/admin/gm-licencias', 'middleware' => ['admin']], static function (Router $r): void {
        $r->post('/{id:\d+}/revoke', [AdminController::class, 'revoke'])->name('admin.gm_licenses.revoke');
        $r->post('/{id:\d+}/reactivate', [AdminController::class, 'reactivate'])->name('admin.gm_licenses.reactivate');
        $r->post('/{id:\d+}/extend', [AdminController::class, 'extend'])->name('admin.gm_licenses.extend');
        $r->post('/{id:\d+}/reissue', [AdminController::class, 'reissue'])->name('admin.gm_licenses.reissue');
        $r->post('/{id:\d+}/domain/{domainId:\d+}/remove', [AdminController::class, 'removeDomain'])->name('admin.gm_licenses.domain');
    });
};
