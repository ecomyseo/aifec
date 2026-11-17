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
use Aifec\Modules\Subscriptions\SubscriptionsController;

return static function (Router $r, App $app): void {
    $r->get('/suscripciones/planes', [SubscriptionsController::class, 'plans'])->name('subscriptions.plans');
    $r->post('/suscripciones/paypal/{environment:sandbox|live}/aviso', [SubscriptionsController::class, 'notify'])->name('subscriptions.notify');
    $base = '/' . trim($app->settings()->str('urls.account', 'mi-cuenta'), '/');
    $r->group(['prefix' => $base . '/suscripciones', 'middleware' => ['customer', 'csrf']], static function (Router $r): void {
        $r->get('', [SubscriptionsController::class, 'account'])->name('subscriptions.account');
        $r->get('/plan/{id:\d+}', [SubscriptionsController::class, 'checkout'])->name('subscriptions.checkout');
        $r->post('/plan/{id:\d+}', [SubscriptionsController::class, 'start'])->name('subscriptions.start');
        $r->get('/vuelta/{id}', [SubscriptionsController::class, 'back'])->name('subscriptions.back');
        $r->post('/{id:\d+}/cancelar', [SubscriptionsController::class, 'cancel'])->name('subscriptions.cancel');
    });
    $r->group(['prefix' => '/admin/subscriptions', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [SubscriptionsController::class, 'admin'])->name('admin.subscriptions');
        $r->get('/{id:\d+}', [SubscriptionsController::class, 'show'])->name('admin.subscriptions.show');
        $r->post('/{id:\d+}/{action:cancel|suspend|activate|paid|failed}', [SubscriptionsController::class, 'action'])->name('admin.subscriptions.action');
    });
};
