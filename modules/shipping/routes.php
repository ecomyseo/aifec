<?php
/**
 * AIFEC — envíos: las pestañas de «Envío» del panel y el cambio de precio de una tarifa en su fila.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Shipping\ShippingController;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/shipping', [ShippingController::class, 'index'])->name('admin.shipping');
        $r->get('/shipping/carriers', [ShippingController::class, 'carriers'])->name('admin.shipping.carriers');
        $r->get('/shipping/zones', [ShippingController::class, 'zones'])->name('admin.shipping.zones');
        $r->get('/shipping/settings', [ShippingController::class, 'settings'])->name('admin.shipping.settings');
        $r->get('/shipping/test', [ShippingController::class, 'test'])->name('admin.shipping.test');
        $r->post('/shipping/rates/{id:\d+}', [ShippingController::class, 'saveRate'])->name('admin.shipping.rate');

        // Las direcciones de antes redirigen a su pestaña. Una ruta sin parámetros se mira antes que la genérica del panel.
        $r->get('/r/carriers', [ShippingController::class, 'oldCarriers']);
        $r->get('/r/shipping_zones', [ShippingController::class, 'oldZones']);
        $r->get('/settings/shipping', [ShippingController::class, 'oldSettings']);
    });
};
