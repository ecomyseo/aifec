<?php
/**
 * AIFEC — cupones: publica el servicio «coupons» y devuelve los usos de un pedido cancelado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Coupons\Coupons;

return static function (App $app): void {
    $app->bind('coupons', static function (App $app): Coupons {
        return new Coupons($app);
    });

    // Un pedido cancelado devuelve sus usos. release() es idempotente: si el módulo de pedidos también lo llama, no resta dos veces.
    $app->hooks()->on('order.cancelled', static function (array $order) use ($app): void {
        if (isset($order['id']) && (int) $order['id'] > 0) {
            $app->service('coupons')->release((int) $order['id']);
        }
    });
};
