<?php
/**
 * AIFEC — pago simulado: registra su forma de pago en el servicio «payments» de pedidos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\PaymentsSimulated\SimulatedPayment;

return static function (App $app): void {
    // Un módulo de pago solo se registra: la caja y el carrito preguntan al servicio y nunca lo conocen por su nombre.
    $app->service('payments')->register(new SimulatedPayment($app));
};
