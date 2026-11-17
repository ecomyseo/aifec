<?php
/**
 * AIFEC — envíos: publica el servicio «shipping» y sella el filtro «shipping.options».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Shipping\Shipping;

return static function (App $app): void {
    // El precio del envío es dinero: solo un módulo con la capacidad «pricing» concedida puede cambiar las opciones.
    $app->hooks()->seal('shipping.options', 'pricing');

    $app->bind('shipping', static function (App $app): Shipping {
        return new Shipping($app);
    });
};
