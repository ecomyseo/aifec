<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Webmcp;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Las dos direcciones de WebMCP:
 *
 *   GET  /webmcp/tools.json        la lista de herramientas con su esquema; la misma para todos y cacheable.
 *   POST /webmcp/call/{nombre}     ejecuta una herramienta sobre el carrito de quien llama (intermediario «csrf»: solo
 *                                  desde la propia web). Cuerpo JSON con la entrada; respuesta JSON sin caché.
 *
 * Con el ajuste «webmcp.enabled» apagado las dos contestan 404. «checkout_place» no se ejecuta por aquí.
 */
final class WebmcpController
{
    /** Llamadas por dirección IP y ventana. */
    public const PER_IP = 300;

    /** Ventana del límite, en segundos. */
    public const WINDOW = 600;

    /** Lo más grande que se acepta en una petición, en bytes. */
    private const MAX_BODY = 65536;

    /** Cuánto se guarda la lista de herramientas, en segundos. */
    private const TTL = 300;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * @param array<string,string> $params
     */
    public function tools(Request $request, array $params): Response
    {
        $webmcp = $this->webmcp();
        $response = Response::json($webmcp->manifest());
        $response->headers['Cache-Control'] = 'public, max-age=' . self::TTL;
        unset($response->headers['Pragma'], $response->headers['Expires']);

        return $response->cacheable(self::TTL, ['pages', 'settings']);
    }

    /**
     * @param array<string,string> $params name
     */
    public function call(Request $request, array $params): Response
    {
        $webmcp = $this->webmcp();
        $name = isset($params['name']) ? (string) $params['name'] : '';
        if (in_array($name, Webmcp::EXCLUDED, true)) {
            return Response::json(['error' => t('The order is confirmed by the person on the page, not through this tool.')], 403);
        }
        $tools = $webmcp->tools();
        if (!isset($tools[$name])) {
            return Response::json(['error' => t('Unknown tool: {name}', ['name' => mb_substr($name, 0, 60, 'UTF-8')])], 404);
        }
        if (strlen($request->body) > self::MAX_BODY) {
            return Response::json(['error' => t('The input is not valid')], 413);
        }
        $limiter = new RateLimiter($this->app->db(), $this->app->clock());
        if (!$limiter->hit('webmcp:ip:' . $request->ip, self::PER_IP, self::WINDOW)) {
            $response = Response::json(['error' => t('Too many requests. Wait a moment and try again.')], 429);
            $response->headers['Retry-After'] = (string) self::WINDOW;

            return $response;
        }

        $input = $request->json();
        if ($input === [] && is_array($request->post)) {
            $input = $request->post;
        }
        $result = $this->app->service('tools')->call($name, $input);

        return Response::json($result, isset($result['error']) ? 422 : 200);
    }

    private function webmcp(): Webmcp
    {
        /** @var Webmcp $webmcp */
        $webmcp = $this->app->service('webmcp');
        if (!$webmcp->enabled()) {
            throw HttpError::notFound();
        }

        return $webmcp;
    }
}
