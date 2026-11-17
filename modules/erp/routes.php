<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Erp\ErpController;
use Aifec\Modules\Erp\Routes;

return static function (Router $r, App $app): void {
    // La API para ERP (/api/v1/erp/…): sale de la tabla de rutas, la misma que documenta el OpenAPI.
    Routes::register($r, $app);

    $r->group(['prefix' => '/admin/erp', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/', [ErpController::class, 'connections'])->name('admin.erp');
        $r->get('/connections/new', [ErpController::class, 'newConnection']);
        $r->post('/connections/new', [ErpController::class, 'createConnection']);
        $r->get('/connections/{id:\d+}', [ErpController::class, 'connection']);
        $r->post('/connections/{id:\d+}/webhook', [ErpController::class, 'saveWebhook']);
        $r->post('/connections/{id:\d+}/toggle', [ErpController::class, 'toggleConnection']);
        $r->post('/connections/{id:\d+}/revoke', [ErpController::class, 'revokeConnection']);
        $r->post('/connections/{id:\d+}/secret', [ErpController::class, 'rotateSecret']);
        $r->post('/webhooks/{id:\d+}/test', [ErpController::class, 'testWebhook']);
        $r->post('/webhooks/{id:\d+}/reactivate', [ErpController::class, 'reactivateWebhook']);
        $r->get('/deliveries', [ErpController::class, 'deliveries']);
        $r->post('/deliveries/{id:\d+}/resend', [ErpController::class, 'resendDelivery']);

        $r->get('/runs', [ErpController::class, 'runs']);
        $r->get('/runs/{id:\d+}', [ErpController::class, 'run']);
        $r->get('/runs/{id:\d+}/download', [ErpController::class, 'runDownload']);

        $r->get('/{dir:exports|imports}', [ErpController::class, 'profiles']);
        $r->get('/{dir:exports|imports}/new', [ErpController::class, 'profileForm']);
        $r->get('/{dir:exports|imports}/{id:\d+}', [ErpController::class, 'profileForm']);
        $r->post('/{dir:exports|imports}/save', [ErpController::class, 'profileSave']);
        $r->post('/{dir:exports|imports}/{id:\d+}/delete', [ErpController::class, 'profileDelete']);
        $r->post('/{dir:exports|imports}/{id:\d+}/run', [ErpController::class, 'profileRun']);
        $r->post('/exports/{id:\d+}/interval', [ErpController::class, 'profileInterval']);
    });
};
