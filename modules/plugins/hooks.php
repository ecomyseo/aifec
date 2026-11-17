<?php
/**
 * AIFEC — módulo de plugins: publica el servicio «plugins» (gestor, validador del contrato y generador).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Plugins\Plugins;

return static function (App $app): void {
    $app->bind('plugins', static function (App $app): Plugins {
        return new Plugins($app);
    });
};
