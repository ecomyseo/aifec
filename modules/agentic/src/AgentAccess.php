<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Lo que la tienda hace con un agente de IA que firma sus peticiones (el agente de ChatGPT y su monedero):
 *
 *  - lo reconoce (atributo «ai_agent» de la petición) y apunta la visita a su nombre, para que la venta salga atribuida;
 *  - la dirección del visitante pasa a ser la de la persona que manda el agente en Forwarded, así los límites por dirección
 *    se aplican a cada persona y no a todos los que compran desde el mismo navegador en la nube.
 *
 * No da permisos de nada: la compra sigue el camino de siempre (la caja de la web, y el pago con la tarjeta de la persona).
 */
final class AgentAccess
{
    /** Atributo de la petición con el agente verificado. */
    public const ATTR = 'ai_agent';

    /**
     * @param mixed $early lo que ya haya decidido otro (una respuesta corta la petición)
     *
     * @return mixed
     */
    public static function early($early, Request $request, App $app)
    {
        if ($early instanceof Response || $request->header('signature-input') === null || !$app->settings()->bool('agentic.verify_agents', true)) {
            return $early;
        }
        try {
            $agent = (new WebBotAuth($app))->verify($request);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'agentic.webbotauth');

            return $early;
        }
        if ($agent === null) {
            return $early;
        }
        $request->setAttr(self::ATTR, $agent);
        $forwarded = $agent['forwarded'];
        if (isset($forwarded['for']) && is_string($forwarded['for'])) {
            $request->ip = $forwarded['for'];
        }
        $app->cache()->set('agentic.last_agent', ['name' => (string) $agent['name'], 'at' => $app->clock()->now()], 30 * 86400);
        if ($request->method === 'GET' && self::isPage($request->path)) {
            $app->session('customer')->set('attribution', [
                'source' => (string) $agent['name'] . ' (verified agent)',
                'landing' => substr($request->rawPath, 0, 300),
                'at' => $app->clock()->sql(),
            ]);
        }

        return $early;
    }

    /** Solo las páginas que ve una persona: nada de panel, recursos, versiones en texto, API ni ficheros con extensión. */
    private static function isPage(string $path): bool
    {
        $lower = strtolower($path);
        foreach (['/admin', '/assets/', '/feeds/', '/api', '/mcp', '/ucp', '/.well-known/'] as $prefix) {
            if (strpos($lower, $prefix) === 0) {
                return false;
            }
        }
        $last = (string) substr($lower, (int) strrpos($lower, '/'));

        return strpos($last, '.') === false;
    }
}
