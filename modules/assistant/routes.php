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
use Aifec\Modules\Assistant\AssistantController;
use Aifec\Modules\Assistant\Admin\AssistantAdminController;

return static function (Router $r, App $app): void {
    // La tienda: solo desde la propia web (intermediario csrf). Con el asistente apagado, el controlador contesta 404.
    $r->post('/asistente/mensaje', [AssistantController::class, 'message'])->middleware('csrf')->name('assistant.message');
    // El botón «Confirmar pedido» de la tarjeta del resumen: solo con la cookie de la conversación en la que se revisó el pedido.
    $r->post('/asistente/confirmar', [AssistantController::class, 'confirm'])->middleware('csrf')->name('assistant.confirm');

    // El panel: conversaciones, preguntas sin respuesta y la ficha de una conversación. Las fijas van antes que la de la ficha.
    $r->group(['prefix' => '/admin/assistant', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [AssistantAdminController::class, 'index'])->name('admin.assistant');
        $r->get('/unanswered', [AssistantAdminController::class, 'unanswered'])->name('admin.assistant.unanswered');
        $r->get('/{id:\d+}', [AssistantAdminController::class, 'show'])->name('admin.assistant.show');
    });
};
