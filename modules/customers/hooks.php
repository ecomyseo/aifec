<?php
/**
 * AIFEC — clientes: publica el servicio «customers».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Customers\Customers;

return static function (App $app): void {
    $app->bind('customers', static function (App $app): Customers {
        return new Customers($app);
    });
};
