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
use Aifec\Modules\Copilot\Admin\CopilotController;

return static function (Router $r, App $app): void {
    // Todo en el panel: el intermediario «admin» exige sesión y, en los envíos, origen propio y ficha de formulario válida.
    $r->group(['prefix' => '/admin/copilot', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [CopilotController::class, 'index'])->name('admin.copilot');
        $r->post('/ask', [CopilotController::class, 'ask'])->name('admin.copilot.ask');
        $r->get('/{id:\d+}', [CopilotController::class, 'show'])->name('admin.copilot.show');
        $r->post('/{id:\d+}/complete', [CopilotController::class, 'complete'])->name('admin.copilot.complete');
        $r->post('/{id:\d+}/choose', [CopilotController::class, 'choose'])->name('admin.copilot.choose');
        $r->post('/{id:\d+}/apply', [CopilotController::class, 'apply'])->name('admin.copilot.apply');
        $r->post('/{id:\d+}/discard', [CopilotController::class, 'discard'])->name('admin.copilot.discard');
        $r->post('/{id:\d+}/undo', [CopilotController::class, 'undo'])->name('admin.copilot.undo');
    });
};
