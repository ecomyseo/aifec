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
            // 404 en JSON y diciendo por qué: quien llega aquí es un programa, y «not found» a secas parece una ruta que no existe.
            $off = Response::json(['type' => 'invalid_request', 'code' => 'acp_disabled', 'message' => 'ACP is turned off on this shop. The owner can turn it on in Settings, UCP and agents.'], 404);
            $off->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';

            return $off;
        }

        return $next($request);
    });
    // Con UCP apagado en los ajustes, sus direcciones dan 404 (en JSON: las usan programas).
    $app->middleware('ucp.on', static function (Request $request, App $application, callable $next): Response {
        if (!Ucp::enabled($application)) {
            $off = Response::json(['error' => ['status' => 404, 'code' => 'ucp_disabled', 'message' => 'UCP is turned off on this shop. The owner can turn it on in Settings, UCP and agents.']], 404);
            $off->headers['Cache-Control'] = 'no-store, no-cache, must-revalidate, max-age=0';

            return $off;
        }

        return $next($request);
    });

    // «Sistema → Diagnóstico»: el módulo está activo pero UCP o ACP siguen apagados (de serie lo están), y los asistentes no encuentran
    // sus perfiles. Solo avisa: encenderlos es una decisión de la persona.
    $app->hooks()->filter('doctor.checks', static function ($checks) {
        $checks = is_array($checks) ? $checks : [];
        $checks[] = ['id' => 'agentic.protocols', 'group' => 'shop', 'label' => t('UCP and ACP are turned on'), 'run' => static function (App $application): array {
            $off = [];
            if (!Ucp::enabled($application)) {
                $off[] = 'UCP';
            }
            if (!AcpController::enabled($application)) {
                $off[] = 'ACP';
            }
            if ($off === []) {
                return ['status' => 'ok', 'detail' => t('UCP and ACP answer on their well-known addresses.')];
            }

            return ['status' => 'warn', 'detail' => t('{list} is off: assistants that look for it get a «not found».', ['list' => implode(' + ', $off)]),
                'fix_url' => $application->url()->plain('/admin/settings/agentic')];
        }];

        return $checks;
    });

    // Un agente de IA que firma sus peticiones (Web Bot Auth): se comprueba la firma al empezar la petición.
    $app->hooks()->filter('request.start', [AgentAccess::class, 'early'], 0);
};
