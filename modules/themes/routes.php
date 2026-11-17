<?php
/**
 * AIFEC — rutas del módulo de temas: la galería, el personalizador, la portada, el logotipo, el asistente de creación y el informe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Themes\ThemesController;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin/themes', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [ThemesController::class, 'index'])->name('admin.themes');
        $r->get('/home', [ThemesController::class, 'home'])->name('admin.themes.home');
        $r->post('/home', [ThemesController::class, 'homeSave']);
        $r->get('/brand', [ThemesController::class, 'brand'])->name('admin.themes.brand');
        $r->post('/brand', [ThemesController::class, 'brandSave']);
        $r->get('/new', [ThemesController::class, 'newForm'])->name('admin.themes.new');
        $r->post('/new', [ThemesController::class, 'create']);
        $r->get('/preview/{slug:[a-z0-9-]+}\.png', [ThemesController::class, 'previewImage']);
        $r->get('/customise/{slug:[a-z0-9-]+}', [ThemesController::class, 'customise']);
        $r->post('/customise/{slug:[a-z0-9-]+}', [ThemesController::class, 'customiseSave']);
        $r->get('/{slug:[a-z0-9-]+}/report', [ThemesController::class, 'report']);
        $r->post('/{slug:[a-z0-9-]+}/activate', [ThemesController::class, 'activate']);
    });
};
