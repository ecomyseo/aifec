<?php
/**
 * AIFEC — PayPal: el aviso firmado de PayPal (de servidor a servidor), las dos vueltas del navegador del cliente y la página
 * de pago de las formas que necesitan el guion de PayPal, con sus dos llamadas (crear la orden y capturarla), más el fichero
 * que acredita el dominio para Apple Pay.
 *
 * El aviso NO lleva el intermediario «csrf»: lo manda el servidor de PayPal, sin sesión ni ficha. Lo protege la
 * verificación de su firma contra la API de PayPal. Las dos llamadas de la página de pago sí lo llevan (misma web) y además
 * exigen la ficha del formulario de esa página y que la sesión la haya abierto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\PaymentsPaypal\PayPalController;
use Aifec\Modules\PaymentsPaypal\PayPalSdkController;

return static function (Router $r, App $app): void {
    $base = '/' . trim($app->settings()->str('urls.payment', 'pago'), '/') . '/paypal';

    $r->post($base . '/aviso', [PayPalController::class, 'notify'])->name('payment.paypal.notify');
    $r->get($base . '/vuelta/{number}/{secret}', [PayPalController::class, 'back'])->name('payment.paypal.back');
    $r->get($base . '/cancelado/{number}/{secret}', [PayPalController::class, 'cancelled'])->name('payment.paypal.cancelled');

    $r->get($base . '/pagar/{number}/{secret}', [PayPalSdkController::class, 'page'])->name('payment.paypal.pay');
    $r->post($base . '/orden/{number}/{secret}', [PayPalSdkController::class, 'create'])->middleware('csrf')->name('payment.paypal.create');
    $r->post($base . '/capturar/{number}/{secret}', [PayPalSdkController::class, 'capture'])->middleware('csrf')->name('payment.paypal.capture');
    $r->get('/.well-known/apple-developer-merchantid-domain-association', [PayPalSdkController::class, 'applePayDomain'])->name('payment.paypal.applepay_domain');
};
