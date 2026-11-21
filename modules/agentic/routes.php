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
use Aifec\Modules\Agentic\UcpCatalog;
use Aifec\Modules\Agentic\UcpCarts;
use Aifec\Modules\Agentic\UcpMcp;
use Aifec\Modules\Agentic\AcpController;

return static function (Router $r, App $app): void {
    // UCP: el perfil de la tienda y la API de compra. Sin ficha csrf (la llaman plataformas, no formularios), con el límite de
    // uso de la API («api») y, con UCP apagado, 404 antes de gastar un uso («ucp.on»).
    $r->get('/.well-known/ucp', [UcpController::class, 'profile'])->middleware('ucp.on', 'api')->name('ucp.profile');
    $r->get('/.well-known/ucp/{version:[0-9-]{10}}', [UcpController::class, 'profile'])->middleware('ucp.on', 'api');
    $r->get('/.well-known/acp.json', [AcpController::class, 'discovery'])->middleware('acp.on', 'api');
    $r->get('/.well-known/acp', [AcpController::class, 'discovery'])->middleware('acp.on', 'api');
    $r->get('/ucp/v1', [UcpController::class, 'profile'])->middleware('ucp.on', 'api');
    $r->post('/ucp/mcp', [UcpMcp::class, 'post'])->middleware('ucp.on', 'api')->name('ucp.mcp');
    $r->post('/ucp/v1/checkout-sessions', [UcpController::class, 'create'])->middleware('ucp.on', 'commerce.api', 'api')->name('ucp.create');
    $r->get('/ucp/v1/checkout-sessions/{id:chk_[a-f0-9]{32}}', [UcpController::class, 'show'])->middleware('ucp.on', 'commerce.api', 'api')->name('ucp.show');
    $r->add(['PUT'], '/ucp/v1/checkout-sessions/{id:chk_[a-f0-9]{32}}',[UcpController::class, 'update'])->middleware('ucp.on', 'commerce.api', 'api')->name('ucp.update');
    $r->post('/ucp/v1/checkout-sessions/{id:chk_[a-f0-9]{32}}/complete', [UcpController::class, 'complete'])->middleware('ucp.on', 'commerce.api', 'api')->name('ucp.complete');
    $r->post('/ucp/v1/checkout-sessions/{id:chk_[a-f0-9]{32}}/cancel', [UcpController::class, 'cancel'])->middleware('ucp.on', 'commerce.api', 'api')->name('ucp.cancel');
    $r->post('/ucp/v1/carts', [UcpCarts::class, 'create'])->middleware('ucp.on', 'commerce.api', 'api');
    $r->get('/ucp/v1/carts/{id:chk_[a-f0-9]{32}}', [UcpCarts::class, 'show'])->middleware('ucp.on', 'commerce.api', 'api');
    $r->add(['PUT'], '/ucp/v1/carts/{id:chk_[a-f0-9]{32}}', [UcpCarts::class, 'update'])->middleware('ucp.on', 'commerce.api', 'api');
    $r->post('/ucp/v1/carts/{id:chk_[a-f0-9]{32}}/cancel', [UcpCarts::class, 'cancel'])->middleware('ucp.on', 'commerce.api', 'api');
    $r->post('/ucp/v1/catalog/search', [UcpCatalog::class, 'search'])->middleware('ucp.on', 'commerce.api', 'api');
    $r->post('/ucp/v1/catalog/lookup', [UcpCatalog::class, 'lookup'])->middleware('ucp.on', 'commerce.api', 'api');
    $r->post('/ucp/v1/catalog/product', [UcpCatalog::class, 'lookup'])->middleware('ucp.on', 'commerce.api', 'api');
    $r->get('/acp/feed.jsonl', [Aifec\Modules\Agentic\AcpFeed::class, 'serve'])->middleware('acp.on', 'api');
    foreach (['', '/acp/v1'] as $prefix) {
        $r->post($prefix . '/checkout_sessions', [AcpController::class, 'create'])->middleware('acp.on', 'api');
        $r->get($prefix . '/checkout_sessions/{id:chk_[a-f0-9]{32}}', [AcpController::class, 'show'])->middleware('acp.on', 'api');
        $r->post($prefix . '/checkout_sessions/{id:chk_[a-f0-9]{32}}', [AcpController::class, 'update'])->middleware('acp.on', 'api');
        $r->post($prefix . '/checkout_sessions/{id:chk_[a-f0-9]{32}}/complete', [AcpController::class, 'complete'])->middleware('acp.on', 'api');
        $r->post($prefix . '/checkout_sessions/{id:chk_[a-f0-9]{32}}/cancel', [AcpController::class, 'cancel'])->middleware('acp.on', 'api');
    }
    // continue_url: abre la compra en el navegador de la persona, en la caja de la tienda.
    $r->get('/ucp/continue/{token:[a-f0-9]{48}}', [UcpController::class, 'resume'])->name('ucp.continue');
};
