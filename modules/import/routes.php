<?php
/**
 * AIFEC — rutas del módulo de migración: el asistente del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Import\ImportController;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin/import', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [ImportController::class, 'index'])->name('admin.import');
        $r->get('/new', [ImportController::class, 'source'])->name('admin.import.new');
        $r->post('/new', [ImportController::class, 'create']);
        $r->get('/{id:\d+}/connection', [ImportController::class, 'connection']);
        $r->post('/{id:\d+}/connection', [ImportController::class, 'saveConnection']);
        $r->get('/{id:\d+}/types', [ImportController::class, 'types']);
        $r->post('/{id:\d+}/types', [ImportController::class, 'saveTypes']);
        $r->get('/{id:\d+}/equivalences', [ImportController::class, 'equivalences']);
        $r->post('/{id:\d+}/equivalences', [ImportController::class, 'saveEquivalences']);
        $r->get('/{id:\d+}/simulation', [ImportController::class, 'simulation']);
        $r->post('/{id:\d+}/simulate', [ImportController::class, 'simulate']);
        $r->get('/{id:\d+}/confirm', [ImportController::class, 'confirm']);
        $r->post('/{id:\d+}/confirm', [ImportController::class, 'start']);
        $r->get('/{id:\d+}/progress', [ImportController::class, 'progress']);
        $r->post('/{id:\d+}/poll', [ImportController::class, 'poll']);
        $r->post('/{id:\d+}/pause', [ImportController::class, 'pause']);
        $r->post('/{id:\d+}/resume', [ImportController::class, 'resume']);
        $r->post('/{id:\d+}/cancel', [ImportController::class, 'cancel']);
        $r->get('/{id:\d+}/report', [ImportController::class, 'report']);
        $r->get('/{id:\d+}/report.csv', [ImportController::class, 'reportCsv']);
        $r->get('/{id:\d+}/print', [ImportController::class, 'reportPrint']);
    });
};
