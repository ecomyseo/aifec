<?php
/**
 * AIFEC — valoraciones: publica el servicio «reviews», pinta la sección en la ficha, anota la petición de opinión al completarse
 * un pedido y se suma al borrado y a la copia de datos de un cliente.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Reviews\Front\ProductSection;
use Aifec\Modules\Reviews\Reviews;
use Aifec\Modules\Reviews\ReviewsPrivacy;

return static function (App $app): void {
    $app->bind('reviews', static function (App $app): Reviews {
        return new Reviews($app);
    });

    // Las valoraciones van al final de la ficha, dentro de la página que se guarda en la caché de página.
    $app->hooks()->slot('product.bottom', static function (array $product) use ($app): string {
        return ProductSection::render($app, $product);
    });

    // Un pedido se completa: se anota (solo se anota, nunca se recorre nada) la petición de opinión de cada producto.
    $app->hooks()->on('order.status', static function (array $order, string $from, string $to) use ($app): void {
        if ($to === 'completed') {
            $app->service('reviews')->requests()->schedule($order);
        }
    });

    // Datos personales: lo que se deja anónimo cuando un cliente pide que se le olvide, y sus opiniones en la copia de sus datos.
    $app->hooks()->on('customer.anonymised', static function (int $customerId, string $email) use ($app): void {
        ReviewsPrivacy::forget($app, $customerId, $email);
    });
    $app->hooks()->filter('customer.export', static function ($data, int $customerId) use ($app) {
        return is_array($data) ? ReviewsPrivacy::export($app, $data, $customerId) : $data;
    });
};
