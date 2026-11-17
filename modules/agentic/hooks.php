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
use Aifec\Modules\Agentic\AgentAccess;
use Aifec\Modules\Agentic\Ucp;

return static function (App $app): void {
    // Con UCP apagado en los ajustes, sus direcciones dan 404 (en JSON: las usan programas).
    $app->middleware('ucp.on', static function (Request $request, App $application, callable $next): Response {
        if (!Ucp::enabled($application)) {
            return Response::json(['error' => ['status' => 404, 'message' => 'Not found']], 404);
        }

        return $next($request);
    });

    // Un agente de IA que firma sus peticiones (Web Bot Auth): se comprueba la firma al empezar la petición.
    $app->hooks()->filter('request.start', [AgentAccess::class, 'early'], 0);
};
