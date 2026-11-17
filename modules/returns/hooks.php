<?php
/**
 * AIFEC — devoluciones: publica el servicio «returns», enseña el estado y el botón de devolver en la página del pedido y en la zona
 * de cliente, la tarjeta de la ficha del pedido en el panel, la herramienta del asistente y los datos personales.
 *
 * Nada de aquí recorre pedidos antiguos ni manda correos por su cuenta: solo actúa cuando alguien pide o tramita una devolución.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Returns\Admin\OrderCard;
use Aifec\Modules\Returns\Front\ReturnCards;
use Aifec\Modules\Returns\ReturnsPrivacy;
use Aifec\Modules\Returns\ReturnTools;
use Aifec\Modules\Returns\Returns;

return static function (App $app): void {
    $app->bind('returns', static function (App $app): Returns {
        return new Returns($app);
    });

    // La página del pedido (con la ficha de la dirección) y la zona de cliente: el estado de las devoluciones y el botón de pedir una.
    $app->hooks()->slot('order.thanks', static function (array $order) use ($app): string {
        return ReturnCards::forOrder($app, $order);
    });
    $app->hooks()->slot('account.dashboard', static function (array $customer) use ($app): string {
        return ReturnCards::forCustomer($app, $customer);
    });

    // La ficha del pedido en el panel: sus devoluciones.
    $app->hooks()->slot('admin.order.side', static function (array $order) use ($app): string {
        return OrderCard::render($app, $order);
    });

    // El asistente (si está cargado): la herramienta «return_options».
    $app->hooks()->filter('assistant.tools', static function ($tools) use ($app) {
        return ReturnTools::add($app, is_array($tools) ? $tools : []);
    });

    // Datos personales: lo que se borra cuando un cliente pide que se le olvide, y sus devoluciones en la copia de sus datos.
    $app->hooks()->on('customer.anonymised', static function (int $customerId, string $email) use ($app): void {
        ReturnsPrivacy::forget($app, $customerId, $email);
    });
    $app->hooks()->filter('customer.export', static function ($data, int $customerId) use ($app) {
        return is_array($data) ? ReturnsPrivacy::export($app, $data, $customerId) : $data;
    });
};
