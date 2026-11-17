<?php
/**
 * AIFEC — traducciones: las pantallas del panel. Las de la IA (traducir lo que falta, pausar, reanudar, parar y el glosario)
 * contestan 404 sin el módulo de IA.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Translations\TranslationsController;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin/translations', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [TranslationsController::class, 'index'])->name('admin.translations');
        $r->post('/glossary', [TranslationsController::class, 'glossary'])->name('admin.translations.glossary');
        $r->post('/jobs/{id:\d+}/{action:pause|resume|cancel}', [TranslationsController::class, 'job'])->name('admin.translations.job');
        $r->get('/{lang:[a-z]{2}}/export.csv', [TranslationsController::class, 'export'])->name('admin.translations.export');
        $r->post('/{lang:[a-z]{2}}/import', [TranslationsController::class, 'import'])->name('admin.translations.import');
        $r->get('/{lang:[a-z]{2}}/{scope:[a-z][a-z0-9_]*}', [TranslationsController::class, 'show'])->name('admin.translations.show');
        $r->post('/{lang:[a-z]{2}}/{scope:[a-z][a-z0-9_]*}', [TranslationsController::class, 'save'])->name('admin.translations.save');
        $r->post('/{lang:[a-z]{2}}/{scope:[a-z][a-z0-9_]*}/approve', [TranslationsController::class, 'approve'])->name('admin.translations.approve');
        $r->post('/{lang:[a-z]{2}}/{scope:[a-z][a-z0-9_]*}/fill', [TranslationsController::class, 'fill'])->name('admin.translations.fill');
    });
};
