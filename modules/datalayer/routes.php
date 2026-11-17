<?php
/**
 * AIFEC — capa de datos: la pantalla del panel con los últimos eventos del servidor.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Datalayer\DatalayerAdmin;

return static function (Router $r, App $app): void {
    $r->group(['prefix' => '/admin/datalayer', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('/events', [DatalayerAdmin::class, 'events'])->name('admin.datalayer.events');
    });
};
