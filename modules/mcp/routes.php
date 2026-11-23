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
use Aifec\Modules\Mcp\McpController;

return static function (Router $r, App $app): void {
    // El servidor MCP. Sin ficha csrf (lo llaman programas, no formularios) y con el límite de uso de la API («api»).
    // «mcp.on» va primero: con el MCP apagado, 404 antes de gastar un uso.
    $r->post('/mcp', [McpController::class, 'post'])->middleware('mcp.on', 'api')->name('mcp.post');
    $r->get('/mcp', [McpController::class, 'info'])->middleware('mcp.on', 'api')->name('mcp.info');
    $r->get('/.well-known/mcp.json', [McpController::class, 'wellKnown'])->middleware('mcp.on', 'api')->name('mcp.wellknown');
    // La ficha del servidor («server card») en las direcciones que proponen las distintas versiones del descubrimiento de MCP
    // (SEP-1649, SEP-2127 y el catálogo de IA), para que un cliente o una IA lo encuentren sin conectarse (17/11/2026).
    $r->get('/.well-known/mcp/server-card.json', [McpController::class, 'serverCard'])->middleware('mcp.on', 'api')->name('mcp.card');
    $r->get('/.well-known/mcp-server-card', [McpController::class, 'serverCard'])->middleware('mcp.on', 'api')->name('mcp.card.alt');
    $r->get('/.well-known/mcp', [McpController::class, 'serverCard'])->middleware('mcp.on', 'api')->name('mcp.card.dir');
    $r->get('/mcp/server-card', [McpController::class, 'serverCard'])->middleware('mcp.on', 'api')->name('mcp.card.server');
    // El icono de la ficha (y del registro oficial): el de la tienda o el de serie.
    $r->get('/mcp/icon.svg', [McpController::class, 'icon'])->middleware('mcp.on')->name('mcp.icon');
    $r->get('/.well-known/ai-catalog.json', [McpController::class, 'aiCatalog'])->middleware('mcp.on', 'api')->name('mcp.catalog');
    // El mismo catálogo con el nombre actual de la especificación ARD (el anterior, ai-catalog.json, se conserva).
    $r->get('/.well-known/ard.json', [McpController::class, 'aiCatalog'])->middleware('mcp.on', 'api')->name('mcp.ard');
    // La prueba de que el dominio es de la tienda, para publicar el servidor en el registro oficial de MCP (17/11/2026).
    $r->get('/.well-known/mcp-registry-auth', [McpController::class, 'registryAuth'])->middleware('mcp.on')->name('mcp.registry.auth');
};
