<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Kernel\VisitLog;

/**
 * De dónde viene el pedido: si la visita llega desde un asistente de IA (la página de origen o «utm_source», ver
 * VisitLog::aiSource), se apunta en la sesión de la persona —solo en ese caso se crea sesión— y el pedido que haga después la
 * copia en orders.attribution. Es el filtro «request.start»: nunca devuelve una respuesta, solo deja la marca y sigue.
 *
 * La marca es ['source' => 'ChatGPT', 'landing' => '/producto/silla', 'at' => '2026-11-12 12:00:00'].
 */
final class BotsAttribution
{
    /**
     * @param mixed $early
     *
     * @return mixed
     */
    public static function early($early, Request $request, App $app)
    {
        if ($early instanceof Response || $request->method !== 'GET' || !self::isPage($request->path)) {
            return $early;
        }
        // Un robot no compra: sus visitas se cuentan en el registro, no en la sesión.
        if (VisitLog::bot($request->userAgent()) !== null) {
            return $early;
        }
        $source = VisitLog::aiSource($request);
        if ($source === null) {
            return $early;
        }
        $app->session('customer')->set('attribution', [
            'source' => $source,
            'landing' => substr($request->rawPath, 0, 300),
            'at' => $app->clock()->sql(),
        ]);

        return $early;
    }

    /** Solo las páginas que ve una persona: nada de panel, recursos, versiones en texto, feeds, API ni ficheros con extensión. */
    private static function isPage(string $path): bool
    {
        $lower = strtolower($path);
        foreach (['/admin', '/assets/', '/feeds/', '/api', '/mcp', '/.well-known/'] as $prefix) {
            if (strpos($lower, $prefix) === 0) {
                return false;
            }
        }
        $last = (string) substr($lower, (int) strrpos($lower, '/'));

        return strpos($last, '.') === false;
    }
}
