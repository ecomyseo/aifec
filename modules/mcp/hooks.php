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
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

return static function (App $app): void {
    // Con el MCP apagado en los ajustes, todas sus direcciones dan 404 (en JSON: las usan programas).
    $app->middleware('mcp.on', static function (Request $request, App $application, callable $next): Response {
        if (!$application->settings()->bool('mcp.enabled', true)) {
            return Response::json(['error' => ['status' => 404, 'message' => 'Not found']], 404);
        }

        return $next($request);
    });

    // Cada página de la tienda dice en su cabecera dónde está el servidor MCP y su ficha: lo primero que lee una IA o un
    // cliente MCP que descubre servicios desde el HTML (17/11/2026, «que todas las IA reconozcan el MCP»).
    $app->hooks()->slot('head', static function () use ($app): string {
        if (!$app->settings()->bool('mcp.enabled', true)) {
            return '';
        }
        $url = $app->url();

        return '<link rel="ard" href="' . e($url->abs('/.well-known/ard.json', [], '')) . '">'
            . '<link rel="mcp" href="' . e($url->abs('/mcp', [], '')) . '">'
            . '<link rel="alternate" type="application/json" title="MCP server card" href="' . e($url->abs('/.well-known/mcp/server-card.json', [], '')) . '">';
    });
};
