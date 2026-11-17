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
use Aifec\Modules\Agentic\UcpController;

return static function (Router $r, App $app): void {
    // UCP: el perfil de la tienda y la API de compra. Sin ficha csrf (la llaman plataformas, no formularios), con el límite de
    // uso de la API («api») y, con UCP apagado, 404 antes de gastar un uso («ucp.on»).
    $r->get('/.well-known/ucp', [UcpController::class, 'profile'])->middleware('ucp.on', 'api')->name('ucp.profile');
    $r->post('/ucp/v1/checkout-sessions', [UcpController::class, 'create'])->middleware('ucp.on', 'api')->name('ucp.create');
    $r->get('/ucp/v1/checkout-sessions/{id:chk_[a-f0-9]{32}}', [UcpController::class, 'show'])->middleware('ucp.on', 'api')->name('ucp.show');
    $r->add(['PUT'], '/ucp/v1/checkout-sessions/{id:chk_[a-f0-9]{32}}',[UcpController::class, 'update'])->middleware('ucp.on', 'api')->name('ucp.update');
    $r->post('/ucp/v1/checkout-sessions/{id:chk_[a-f0-9]{32}}/complete', [UcpController::class, 'complete'])->middleware('ucp.on', 'api')->name('ucp.complete');
    $r->post('/ucp/v1/checkout-sessions/{id:chk_[a-f0-9]{32}}/cancel', [UcpController::class, 'cancel'])->middleware('ucp.on', 'api')->name('ucp.cancel');
    // continue_url: abre la compra en el navegador de la persona, en la caja de la tienda.
    $r->get('/ucp/continue/{token:[a-f0-9]{48}}', [UcpController::class, 'resume'])->middleware('ucp.on')->name('ucp.continue');
};
