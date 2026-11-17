<?php
/**
 * AIFEC — valoraciones: las rutas de la tienda (enviar una opinión, valorar desde el correo) y las del panel.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Reviews\Admin\ReviewsController;
use Aifec\Modules\Reviews\Front\ReviewController;

return static function (Router $r, App $app): void {
    // La tienda: solo desde la propia web (intermediario csrf). La página del enlace del correo es un GET sin caché.
    $r->post('/valoracion/{productId:\d+}', [ReviewController::class, 'submit'])->middleware('csrf')->name('reviews.submit');
    $r->get('/valorar/{token}', [ReviewController::class, 'page'])->name('reviews.request');
    $r->post('/valorar/{token}', [ReviewController::class, 'submitRequest'])->middleware('csrf');

    // El panel: la bandeja por estado, la ficha de una opinión y sus tres acciones.
    $r->group(['prefix' => '/admin/reviews', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [ReviewsController::class, 'index'])->name('admin.reviews');
        $r->get('/{id:\d+}', [ReviewsController::class, 'show'])->name('admin.reviews.show');
        $r->post('/{id:\d+}/publish', [ReviewsController::class, 'publish']);
        $r->post('/{id:\d+}/reject', [ReviewsController::class, 'reject']);
        $r->post('/{id:\d+}/reply', [ReviewsController::class, 'reply']);
    });
};
