<?php
/**
 * AIFEC — devoluciones: las páginas de la tienda (por la ficha del pedido, por la cuenta o por el número y el correo) y las pantallas
 * del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Returns\Admin\ReturnsController;
use Aifec\Modules\Returns\Front\ReturnController;

return static function (Router $r, App $app): void {
    // ------------------------------------------------------------------ tienda

    // Por la ficha de la página del pedido: vale para un invitado. Los envíos solo se aceptan desde la propia web (csrf).
    $r->get('/devolucion/{number}/{secret}', [ReturnController::class, 'show'])->name('returns.order');
    $r->post('/devolucion/{number}/{secret}', [ReturnController::class, 'submit'])->middleware('csrf')->name('returns.submit');
    $r->post('/devolucion/{number}/{secret}/cancelar', [ReturnController::class, 'cancel'])->middleware('csrf')->name('returns.cancel');

    // El formulario legal de desistimiento: número de pedido y correo, y de ahí al mismo flujo.
    $r->get('/desistimiento', [ReturnController::class, 'withdrawal'])->name('returns.withdrawal');
    $r->post('/desistimiento', [ReturnController::class, 'lookup'])->middleware('csrf')->name('returns.lookup');
    $r->post('/desistimiento/enviar', [ReturnController::class, 'lookupSubmit'])->middleware('csrf')->name('returns.lookup.submit');

    // Desde «Mi cuenta»: pide haber entrado.
    $account = trim($app->settings()->str('urls.account', 'mi-cuenta'), '/');
    $account = '/' . ($account !== '' ? $account : 'mi-cuenta');
    $r->group(['prefix' => $account, 'middleware' => ['customer', 'csrf']], static function (Router $r): void {
        $r->get('/devoluciones/{number}', [ReturnController::class, 'accountShow'])->name('returns.account');
        $r->post('/devoluciones/{number}', [ReturnController::class, 'accountSubmit'])->name('returns.account.submit');
        $r->post('/devoluciones/{number}/cancelar', [ReturnController::class, 'accountCancel'])->name('returns.account.cancel');
    });

    // ------------------------------------------------------------------ panel

    // Listado, ficha con sus acciones y las fotos. Las rutas fijas van antes que la de la ficha.
    $r->group(['prefix' => '/admin/returns', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [ReturnsController::class, 'index'])->name('admin.returns');
        $r->get('/{id:\d+}', [ReturnsController::class, 'show'])->name('admin.returns.show');
        $r->get('/{id:\d+}/photo/{n:\d+}', [ReturnsController::class, 'photo'])->name('admin.returns.photo');
        $r->post('/{id:\d+}/approve', [ReturnsController::class, 'approve']);
        $r->post('/{id:\d+}/reject', [ReturnsController::class, 'reject']);
        $r->post('/{id:\d+}/receive', [ReturnsController::class, 'receive']);
        $r->post('/{id:\d+}/refund', [ReturnsController::class, 'refund']);
        $r->post('/{id:\d+}/cancel', [ReturnsController::class, 'cancel']);
        $r->post('/{id:\d+}/note', [ReturnsController::class, 'note']);
    });
};
