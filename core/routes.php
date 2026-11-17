<?php
/**
 * AIFEC — rutas del núcleo: acceso al panel, inicio, ajustes y altas/bajas/cambios genéricos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Admin\AuthController;
use Aifec\Admin\DashboardController;
use Aifec\Admin\ResourceController;
use Aifec\Admin\SettingsController;
use Aifec\Kernel\App;
use Aifec\Kernel\Router;

return static function (Router $r, App $app): void {
    // La portada: la componen los módulos (ver Aifec\Front\HomeController).
    $r->get('/', [\Aifec\Front\HomeController::class, 'show'])->name('home');
    $r->get('/favicon.ico', [\Aifec\Front\HomeController::class, 'favicon']);

    // Acceso: sin sesión todavía.
    $r->get('/admin/login', [AuthController::class, 'form'])->name('admin.login');
    $r->post('/admin/login', [AuthController::class, 'login']);
    $r->get('/admin/login/code', [AuthController::class, 'codeForm']);
    $r->post('/admin/login/code', [AuthController::class, 'code']);
    $r->get('/admin/forgot', [AuthController::class, 'forgotForm']);
    $r->post('/admin/forgot', [AuthController::class, 'forgot']);
    $r->get('/admin/reset/{token}', [AuthController::class, 'resetForm']);
    $r->post('/admin/reset/{token}', [AuthController::class, 'reset']);

    $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [DashboardController::class, 'index'])->name('admin.home');
        $r->post('/logout', [AuthController::class, 'logout'])->name('admin.logout');
        $r->get('/palette.json', [DashboardController::class, 'palette']);

        // «Mi cuenta»: lo de la persona que ha entrado. No pide ningún permiso: cada uno gestiona lo suyo.
        $r->get('/profile', [\Aifec\Admin\ProfileController::class, 'show'])->name('admin.profile');
        $r->post('/profile/data', [\Aifec\Admin\ProfileController::class, 'saveData']);
        $r->post('/profile/password', [\Aifec\Admin\ProfileController::class, 'savePassword']);
        $r->post('/profile/two-step/start', [\Aifec\Admin\ProfileController::class, 'startTwoStep']);
        $r->post('/profile/two-step/confirm', [\Aifec\Admin\ProfileController::class, 'confirmTwoStep']);
        $r->post('/profile/two-step/cancel', [\Aifec\Admin\ProfileController::class, 'cancelTwoStep']);
        $r->post('/profile/two-step/disable', [\Aifec\Admin\ProfileController::class, 'disableTwoStep']);
        $r->post('/profile/two-step/codes', [\Aifec\Admin\ProfileController::class, 'newRecoveryCodes']);
        // Quitarle el segundo paso a otra persona del equipo (pide team.edit; lo comprueba el controlador).
        $r->post('/team/{id:\d+}/two-step/remove', [\Aifec\Admin\UsersController::class, 'removeTwoStep']);
        $r->post('/profile/sessions/close', [\Aifec\Admin\ProfileController::class, 'closeSessions']);

        $r->get('/settings', [SettingsController::class, 'index'])->name('admin.settings');
        $r->get('/settings/{module:[a-z0-9-]+}', [SettingsController::class, 'edit']);
        $r->post('/settings/{module:[a-z0-9-]+}', [SettingsController::class, 'save']);

        $r->get('/r/{resource:[a-z0-9_-]+}', [ResourceController::class, 'index'])->name('admin.resource');
        $r->get('/r/{resource:[a-z0-9_-]+}/new', [ResourceController::class, 'create']);
        $r->get('/r/{resource:[a-z0-9_-]+}/export\.csv', [ResourceController::class, 'export']);
        $r->get('/r/{resource:[a-z0-9_-]+}/search\.json', [ResourceController::class, 'search']);
        $r->post('/r/{resource:[a-z0-9_-]+}/save', [ResourceController::class, 'save']);
        $r->post('/r/{resource:[a-z0-9_-]+}/bulk', [ResourceController::class, 'bulk']);
        $r->get('/r/{resource:[a-z0-9_-]+}/{id:\d+}', [ResourceController::class, 'edit']);
        $r->post('/r/{resource:[a-z0-9_-]+}/{id:\d+}/save', [ResourceController::class, 'save']);
        $r->post('/r/{resource:[a-z0-9_-]+}/{id:\d+}/delete', [ResourceController::class, 'delete']);
    });
};
