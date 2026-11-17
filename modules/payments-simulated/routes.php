<?php
/**
 * AIFEC — pago simulado: la página donde el cliente decide si el pago sale bien o mal.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\PaymentsSimulated\SimulatedController;

return static function (Router $r, App $app): void {
    $base = '/' . trim($app->settings()->str('urls.payment', 'pago'), '/') . '/simulado/{number}/{secret}';

    $r->get($base, [SimulatedController::class, 'show'])->name('payment.simulated.pay');
    // Solo desde la propia web (intermediario csrf).
    $r->group(['middleware' => ['csrf']], static function (Router $r) use ($base): void {
        $r->post($base, [SimulatedController::class, 'decide'])->name('payment.simulated.decide');
    });
};
