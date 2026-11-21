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
use Aifec\Modules\Agentic\AcpController;
use Aifec\Modules\Agentic\AcpFeed;
use Aifec\Modules\Agentic\Ucp;
use Aifec\Modules\Agentic\ProtocolGate;

return static function (App $app): void {
    $commerce = static function () use ($app): array {
        $url = $app->url();
        $result = [];
        if (Ucp::enabled($app)) {
            $result['ucp'] = ['profile' => $url->abs('/.well-known/ucp', [], ''), 'rest' => Ucp::endpoint($app),
                'catalog_search' => $url->abs('/ucp/v1/catalog/search', [], ''),
                'checkout_sessions' => $url->abs('/ucp/v1/checkout-sessions', [], '')];
            if ($app->modules()->isLoaded('mcp')) {
                $result['ucp']['mcp'] = $url->abs('/ucp/mcp', [], '');
            }
        }
        if (AcpController::enabled($app)) {
            $result['acp'] = ['profile' => $url->abs('/.well-known/acp.json', [], ''), 'feed' => $url->abs('/acp/feed.jsonl', [], ''),
                'checkout_sessions' => $url->abs('/checkout_sessions', [], '')];
        }

        return $result;
    };
    $app->hooks()->filter('semantic.gates', static function (array $gates) use ($commerce): array {
        return $gates + $commerce();
    });
    $app->hooks()->filter('semantic.openai.policies', static function (array $policies) use ($app): array {
        static $checkout = null;
        if ($checkout === null) {
            $checkout = AcpFeed::checkoutPolicies($app);
        }
        return $policies + $checkout;
    });
    $app->hooks()->filter('llms.sections', static function (array $sections) use ($commerce): array {
        $links = [];
        foreach ($commerce() as $protocol => $endpoints) {
            foreach ($endpoints as $action => $url) {
                $links[] = ['title' => strtoupper($protocol) . ' ' . str_replace('_', ' ', $action), 'url' => $url,
                    'note' => $action === 'checkout_sessions' ? t('The buyer pays on the shop checkout.') : '', 'ref' => null];
            }
        }
        if ($links !== []) {
            $sections[] = ['id' => 'commerce-protocols', 'title' => 'UCP / ACP', 'links' => $links];
        }
        return $sections;
    });
    $app->middleware('commerce.api', [ProtocolGate::class, 'handle']);
    $app->middleware('acp.on', static function (Request $request, App $application, callable $next): Response {
        if (!AcpController::enabled($application)) {
            return Response::json(['type' => 'invalid_request', 'code' => 'not_found', 'message' => 'Not found'], 404);
        }

        return $next($request);
    });
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
