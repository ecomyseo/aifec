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
use Aifec\Modules\Api\CartController;
use Aifec\Modules\Api\DocsController;
use Aifec\Modules\Api\Routes;

return static function (Router $r, App $app): void {
    // La API (/api/v1/…): sale de la tabla de rutas, la misma que documenta el OpenAPI.
    Routes::register($r, $app);

    // La página para quien programa y el documento OpenAPI. Con la API apagada, 404.
    $r->get('/api', [DocsController::class, 'page'])->middleware('api.on')->name('api.docs');
    $r->get('/api/openapi.json', [DocsController::class, 'openapi'])->middleware('api.on')->name('api.openapi');
    // Donde un programa lo busca primero (13/11/2026: /api/v1/openapi.json y /api/v1 daban 404): lleva a los de arriba.
    $r->get('/api/v1/openapi.json', [DocsController::class, 'toOpenapi'])->middleware('api.on')->name('api.openapi.v1');
    $r->get('/api/v1', [DocsController::class, 'toPage'])->middleware('api.on')->name('api.docs.v1');
    // Y en las dos direcciones de siempre, servido tal cual (sin redirección: hay clientes que no la siguen).
    $r->get('/openapi.json', [DocsController::class, 'openapi'])->middleware('api.on')->name('api.openapi.root');
    $r->get('/.well-known/openapi.json', [DocsController::class, 'openapi'])->middleware('api.on')->name('api.openapi.wellknown');

    // El enlace que abre en el navegador de una persona el carrito que preparó un programa.
    $r->get('/' . trim($app->settings()->str('urls.cart', 'carrito'), '/') . '/retomar/{token:[a-f0-9]{48}}', [CartController::class, 'resume'])
        ->middleware('api.on')->name('api.cart.resume');
};
