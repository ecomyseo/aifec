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
            $off = Response::json(['error' => ['status' => 404, 'code' => 'mcp_disabled', 'message' => 'The MCP server is turned off on this shop. The owner can turn it on in Settings, MCP.']], 404);
            // Un 404 que dice «apagado» no se guarda: cuando se encienda, tiene que verse enseguida.
            $off->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';

            return $off;
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
