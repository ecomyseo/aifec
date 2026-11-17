<?php
/**
 * AIFEC — Redsys: el aviso del banco (de servidor a servidor) y las dos vueltas del navegador del cliente.
 *
 * El aviso NO lleva el intermediario «csrf»: lo manda el servidor del banco, sin sesión ni ficha. Lo protege la firma.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\PaymentsRedsys\RedsysController;

return static function (Router $r, App $app): void {
    $base = '/' . trim($app->settings()->str('urls.payment', 'pago'), '/') . '/redsys';

    $r->post($base . '/aviso', [RedsysController::class, 'notify'])->name('payment.redsys.notify');
    $r->get($base . '/ok/{number}/{secret}', [RedsysController::class, 'back'])->name('payment.redsys.ok');
    $r->get($base . '/ko/{number}/{secret}', [RedsysController::class, 'cancelled'])->name('payment.redsys.ko');
};
