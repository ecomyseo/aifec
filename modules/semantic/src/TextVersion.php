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

/**
 * La versión en texto de cada página pública. El filtro «request.start» la sirve en dos casos:
 *
 *   GET /producto/silla.md                       la dirección con «.md» (si no existe o no se ve, 404);
 *   GET /producto/silla  con «Accept: text/markdown»   la misma dirección, negociada (si no es una página con versión en texto,
 *                                                       la petición sigue su camino de siempre).
 *
 * Lo que no es público (carrito, cuenta, panel) no tiene versión en texto: no sale de ningún servicio de aquí.
 */
final class TextVersion
{
    /** Zonas técnicas: con «Accept: text/markdown» no se tocan. */
    private const SKIP = '#^/(admin|api|mcp|cron|install|assets|media|feeds|\.well-known)(/|$)#';

    /**
     * @param mixed $early lo que ya haya contestado otro oyente
     *
     * @return mixed
     */
    public static function early($early, Request $request, App $app)
    {
        if ($early instanceof Response || !$app->has('semantic') || ($request->method !== 'GET' && $request->method !== 'HEAD')) {
            return $early;
        }
        $path = $request->path;
        $explicit = strlen($path) > 3 && strtolower(substr($path, -3)) === '.md';
        if (!$explicit && (!$request->wantsMarkdown() || preg_match(self::SKIP, $path) === 1)) {
            return $early;
        }
        $hit = Addresses::resolve($app, $explicit ? substr($path, 0, -3) : $path, $explicit);
        if ($hit === null) {
            return $explicit ? $app->errorResponse(404) : $early;
        }
        /** @var Semantic $semantic */
        $semantic = $app->service('semantic');

        // Una dirección antigua de la entidad: la versión en texto de la dirección buena, para siempre.
        if ($explicit && !$hit['canonical'] && $hit['type'] !== 'home' && $hit['type'] !== 'blog') {
            $target = Addresses::entityPath($app, $hit['type'], $hit['id']);
            if ($target !== '') {
                $query = $request->query !== [] ? '?' . http_build_query($request->query) : '';

                return Response::redirect($target . $query, 301);
            }
        }

        $page = TextPages::entity($semantic, $hit['type'], $hit['id']);
        if ($page === null) {
            return $explicit ? $app->errorResponse(404) : $early;
        }
        // La dirección con «.md» se sirve como texto plano: el lector de páginas de Gemini da error con «text/markdown» aunque
        // la respuesta sea un 200, y un navegador la descarga en vez de enseñarla. Como «text/markdown» solo va a quien lo ha
        // pedido en su «Accept» (la dirección normal, negociada), que por eso lleva «Vary».
        $response = Response::text($page['markdown'], 200, $explicit ? 'text/plain' : 'text/markdown');
        $response->headers['Link'] = '<' . $page['canonical'] . '>; rel="canonical"';
        $response->headers['X-Robots-Tag'] = 'noindex';
        if (!$explicit) {
            $response->headers['Vary'] = 'Accept';
        }

        return $response->cacheable($page['ttl'], $page['tags']);
    }
}
