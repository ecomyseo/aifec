<?php
/**
 * AIFEC — rutas del módulo de IA: la bandeja de propuestas, los lotes, el gasto y el botón «Proponer con IA» de la ficha de producto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Ai\Admin\BatchesController;
use Aifec\Modules\Ai\Admin\ProposalsController;
use Aifec\Modules\Ai\Admin\UsageController;
use Aifec\Modules\Ai\Admin\ConnectionController;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin/ai', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/proposals', [ProposalsController::class, 'index'])->name('admin.ai.proposals');
        $r->post('/proposals/bulk', [ProposalsController::class, 'bulk']);
        $r->get('/proposals/{id:\d+}', [ProposalsController::class, 'show'])->name('admin.ai.proposal');
        $r->post('/proposals/{id:\d+}/save', [ProposalsController::class, 'save']);
        $r->post('/proposals/{id:\d+}/approve', [ProposalsController::class, 'approve']);
        $r->post('/proposals/{id:\d+}/reject', [ProposalsController::class, 'reject']);
        $r->post('/propose', [ProposalsController::class, 'propose']);

        $r->get('/batches', [BatchesController::class, 'index'])->name('admin.ai.batches');
        $r->post('/batches/estimate', [BatchesController::class, 'estimate']);
        $r->post('/batches/start', [BatchesController::class, 'start']);
        $r->post('/batches/{id:\d+}/pause', [BatchesController::class, 'pause']);
        $r->post('/batches/{id:\d+}/resume', [BatchesController::class, 'resume']);
        $r->post('/batches/{id:\d+}/cancel', [BatchesController::class, 'cancel']);

        $r->get('/usage', [UsageController::class, 'index'])->name('admin.ai.usage');
        $r->get('/connection', [ConnectionController::class, 'index'])->name('admin.ai.connection');
        // «admin» ya comprueba la ficha de sesión del panel. «csrf» es la ficha distinta de la tienda.
        $r->post('/connection', [ConnectionController::class, 'test']);
    });
};
