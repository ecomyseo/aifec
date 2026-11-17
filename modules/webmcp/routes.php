<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\Router;
use Aifec\Modules\Webmcp\Admin\WebmcpAdminController;
use Aifec\Modules\Webmcp\WebmcpController;

return static function (Router $r, App $app): void {
    // La lista de herramientas, igual para todo el mundo (se guarda en la caché de página). Con WebMCP apagado, 404.
    $r->get('/webmcp/tools.json', [WebmcpController::class, 'tools'])->name('webmcp.tools');
    // Ejecutar una herramienta sobre el carrito de quien llama: solo desde la propia web (intermediario csrf).
    $r->post('/webmcp/call/{name:[a-z][a-z0-9_]{1,63}}', [WebmcpController::class, 'call'])->middleware('csrf')->name('webmcp.call');

    // El panel: herramientas publicadas y formularios marcados.
    $r->group(['prefix' => '/admin/webmcp', 'middleware' => ['admin']], static function (Router $r): void {
        $r->get('', [WebmcpAdminController::class, 'index'])->name('admin.webmcp');
    });
};
