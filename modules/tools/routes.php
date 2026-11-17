<?php
/**
 * AIFEC — rutas del módulo de herramientas: diagnóstico, registro, caché, copias, información y CSV de productos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Tools\BackupController;
use Aifec\Modules\Tools\CsvController;
use Aifec\Modules\Tools\ToolsController;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin/tools', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/doctor', [ToolsController::class, 'doctor'])->name('admin.tools.doctor');
        $r->get('/logs', [ToolsController::class, 'logs'])->name('admin.tools.logs');
        $r->get('/logs/download', [ToolsController::class, 'logDownload']);
        $r->get('/cache', [ToolsController::class, 'cache'])->name('admin.tools.cache');
        $r->post('/cache/clear-data', [ToolsController::class, 'clearData']);
        $r->post('/cache/clear-pages', [ToolsController::class, 'clearPages']);
        $r->post('/cache/publish-assets', [ToolsController::class, 'publishAssets']);
        $r->post('/cache/maintenance', [ToolsController::class, 'toggleMaintenance']);
        $r->get('/info', [ToolsController::class, 'info'])->name('admin.tools.info');

        $r->get('/backups', [BackupController::class, 'index'])->name('admin.tools.backups');
        $r->post('/backups/create', [BackupController::class, 'create']);
        $r->get('/backups/download/{name:[A-Za-z0-9._-]+}', [BackupController::class, 'download']);
        $r->post('/backups/delete/{name:[A-Za-z0-9._-]+}', [BackupController::class, 'delete']);
        $r->post('/backups/verify/{name:[A-Za-z0-9._-]+}', [BackupController::class, 'verify']);
        $r->get('/backups/restore/{name:[A-Za-z0-9._-]+}', [BackupController::class, 'restoreForm']);
        $r->post('/backups/restore/{name:[A-Za-z0-9._-]+}', [BackupController::class, 'restore']);
        $r->get('/backups/run/{token:[a-f0-9]{12}}', [BackupController::class, 'progress']);
        $r->post('/backups/run/{token:[a-f0-9]{12}}', [BackupController::class, 'step']);
        $r->post('/backups/run/{token:[a-f0-9]{12}}/cancel', [BackupController::class, 'cancel']);

        $r->get('/csv', [CsvController::class, 'index'])->name('admin.tools.csv');
        $r->get('/csv/export', [CsvController::class, 'export']);
        $r->post('/csv/upload', [CsvController::class, 'upload']);
        $r->get('/csv/{token:[a-f0-9]{24}}', [CsvController::class, 'show']);
        $r->post('/csv/{token:[a-f0-9]{24}}/run', [CsvController::class, 'run']);
        $r->post('/csv/{token:[a-f0-9]{24}}/cancel', [CsvController::class, 'cancel']);
        $r->get('/csv/{token:[a-f0-9]{24}}/report.csv', [CsvController::class, 'report']);
    });
};
