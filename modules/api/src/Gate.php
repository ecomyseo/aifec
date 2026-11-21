<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Los intermediarios de la API.
 *
 *   api.gate  todo lo que pasa entre una petición a /api/v1 y su controlador:
 *             1. la API tiene que estar encendida → 404
 *             2. en las rutas del carrito, el origen (Origin) tiene que ser el de la propia tienda o uno de api.cors_origins → 403
 *             3. si trae clave (Authorization: Bearer …), tiene que valer → 401
 *             4. límite de uso: por clave (api.rate_per_minute_key) o, sin clave, el general por dirección (api.rate_per_minute) → 429
 *             5. los errores salen siempre como {"error":{"status","message"}}; los imprevistos, como un 500 sin detalles
 *             6. cabeceras CORS (en las rutas de lectura, «*») y de caché (un minuto, en lo que es catálogo)
 *   api.on    solo comprueba que la API esté encendida (la página, el documento y el enlace para retomar un carrito).
 *
 * Las peticiones OPTIONS no tienen ruta: las contesta early(), que engancha el filtro «request.start».
 */
final class Gate
{
    /** Cabeceras que aceptan las rutas del carrito. */
    private const CART_HEADERS = 'Authorization, Content-Type, X-Cart-Token';

    public static function enabled(App $app): bool
    {
        return $app->settings()->bool('api.enabled', true);
    }

    public static function on(Request $request, App $app, callable $next): Response
    {
        if (!self::enabled($app)) {
            throw HttpError::notFound();
        }

        return $next($request);
    }

    public static function handle(Request $request, App $app, callable $next): Response
    {
        $spec = null;
        $cors = null;
        try {
            if (!self::enabled($app)) {
                throw new HttpError(404, 'Not found');
            }
            $spec = Routes::find($app, (string) $request->attr('route', ''));
            if ($spec === null || ($spec['needs'] !== '' && !$app->has((string) $spec['needs']))) {
                throw new HttpError(404, 'Not found');
            }
            $cors = self::origin($app, $request, $spec);
            $key = self::authenticate($app, $request);
            // CORS permite leer respuestas desde un origen; el permiso de crear carritos se comprueba aparte.
            if ($key === null && $spec['kind'] !== 'read' && !in_array($request->method, ['GET', 'HEAD'], true)
                && \Aifec\Kernel\BrowserCommerce::blocked($app, $request)) {
                throw new HttpError(403, t('This browser cannot modify carts through assistant links.'));
            }
            $blocked = self::limit($app, $request, $key);
            if ($blocked !== null) {
                $response = $blocked;
            } else {
                if ($key !== null) {
                    Keys::touch($app, $key);
                }
                $request->setAttr('api.key', $key);
                $response = $next($request);
            }
        } catch (HttpError $e) {
            $response = self::error($e->status(), $e->getMessage());
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'api ' . $request->method . ' ' . $request->path);
            $response = self::error(500, 'The server could not process the request');
        }

        return self::finish($response, $spec, $cors);
    }

    /**
     * El filtro «request.start»: contesta las peticiones OPTIONS (la comprobación previa de un navegador) de /api/v1.
     *
     * @param mixed $early
     *
     * @return mixed
     */
    public static function early($early, Request $request, App $app)
    {
        if ($early !== null || $request->method !== 'OPTIONS' || strpos($request->path, Routes::PREFIX . '/') !== 0) {
            return $early;
        }
        if (!self::enabled($app)) {
            return self::error(404, 'Not found');
        }
        $spec = Routes::match($app, substr($request->path, strlen(Routes::PREFIX)));
        if ($spec === null) {
            return self::error(404, 'Not found');
        }
        try {
            $cors = self::origin($app, $request, $spec);
        } catch (HttpError $e) {
            return self::error($e->status(), $e->getMessage());
        }
        $response = new Response('', 204);
        $response->headers['Access-Control-Allow-Methods'] = $spec['kind'] === 'read' ? 'GET, HEAD, OPTIONS' : (string) $spec['method'] . ', OPTIONS';
        $response->headers['Access-Control-Allow-Headers'] = $spec['kind'] === 'read' ? 'Authorization, Content-Type' : self::CART_HEADERS;
        $response->headers['Access-Control-Max-Age'] = '86400';
        $response->headers['Cache-Control'] = 'public, max-age=86400';

        return self::cors($response, $cors);
    }

    // ------------------------------------------------------------------ piezas

    /**
     * El valor de Access-Control-Allow-Origin que corresponde a la petición: «*» en lo que es de lectura; en las rutas del
     * carrito, el origen de la petición si es de los permitidos, y null si no trae origen (un programa).
     *
     * @param array<string,mixed> $spec
     *
     * @throws HttpError 403 si el origen no está permitido
     */
    private static function origin(App $app, Request $request, array $spec): ?string
    {
        if ($spec['kind'] === 'read') {
            return '*';
        }
        $origin = strtolower(rtrim(trim((string) $request->header('origin', '')), '/'));
        if ($origin === '') {
            return null;
        }
        $mine = ($request->secure ? 'https://' : 'http://') . strtolower($request->host);
        if ($origin === $mine || $origin === strtolower($app->url()->origin())) {
            return $origin;
        }
        foreach (preg_split('/[\s,]+/', (string) $app->settings()->str('api.cors_origins', ''), -1, PREG_SPLIT_NO_EMPTY) as $allowed) {
            if ($origin === strtolower(rtrim($allowed, '/'))) {
                return $origin;
            }
        }

        throw new HttpError(403, 'This origin is not allowed to use the cart');
    }

    /**
     * La clave de la petición, o null si no manda ninguna.
     *
     * @return array<string,mixed>|null
     *
     * @throws HttpError 401 si la clave no vale; 429 si se prueban muchas claves malas
     */
    private static function authenticate(App $app, Request $request): ?array
    {
        $auth = trim((string) $request->header('authorization', ''));
        if ($auth === '') {
            return null;
        }
        if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) !== 1) {
            throw new HttpError(401, 'Send the key as Authorization: Bearer <key>');
        }
        $key = Keys::authenticate($app, $m[1]);
        if ($key === null) {
            // Quien prueba claves a ciegas se corta pronto: 30 fallos por minuto y por dirección.
            if (!(new RateLimiter($app->db(), $app->clock()))->hit('api-auth:' . $request->ip, 30, 60)) {
                throw new HttpError(429, 'Too many wrong keys: wait a minute');
            }
            throw new HttpError(401, 'The key is wrong, switched off or revoked');
        }

        return $key;
    }

    /**
     * El límite de uso. Devuelve la respuesta 429 si se ha pasado, o null.
     *
     * @param array<string,mixed>|null $key
     */
    private static function limit(App $app, Request $request, ?array $key): ?Response
    {
        $settings = $app->settings();
        if ($key !== null) {
            $max = $settings->int('api.rate_per_minute_key', 600);
            $bucket = 'apik:' . (int) $key['id'];
        } else {
            $max = $settings->int('api.rate_per_minute', 120);
            $bucket = 'api:' . $request->ip;
        }
        if ($max <= 0 || (new RateLimiter($app->db(), $app->clock()))->hit($bucket, $max, 60)) {
            return null;
        }
        $start = $app->db()->value('SELECT window_start FROM {rate_limits} WHERE k = ?', [strlen($bucket) <= 100 ? $bucket : substr($bucket, 0, 60) . ':' . md5($bucket)]);
        $wait = $start !== null ? max(1, min(60, (int) $start + 60 - $app->clock()->now())) : 60;
        $response = self::error(429, 'Too many requests: wait ' . $wait . ' seconds');
        $response->headers['Retry-After'] = (string) $wait;

        return $response;
    }

    /**
     * Las cabeceras de la respuesta: CORS, caché y, si falta la clave, cómo se manda.
     *
     * @param array<string,mixed>|null $spec
     */
    private static function finish(Response $response, ?array $spec, ?string $cors): Response
    {
        $response = self::cors($response, $cors);
        if ($response->status === 401) {
            $response->headers['WWW-Authenticate'] = 'Bearer realm="api"';
        }
        if ($spec !== null && !empty($spec['cache']) && $response->status === 200) {
            // Lo que es catálogo se puede guardar un minuto en cualquier intermediario; lo demás, nunca.
            $response->headers['Cache-Control'] = 'public, max-age=60';
            unset($response->headers['Pragma'], $response->headers['Expires']);
        } elseif (!isset($response->headers['Cache-Control'])) {
            $response->headers['Cache-Control'] = 'no-store';
        }

        return $response;
    }

    private static function cors(Response $response, ?string $cors): Response
    {
        if ($cors === null) {
            return $response;
        }
        $response->headers['Access-Control-Allow-Origin'] = $cors;
        $response->headers['Access-Control-Expose-Headers'] = 'X-Cart-Token, Retry-After';
        if ($cors !== '*') {
            $response->headers['Vary'] = 'Origin';
        }

        return $response;
    }

    private static function error(int $status, string $message): Response
    {
        return Response::json(['error' => ['status' => $status, 'message' => $message]], $status);
    }
}
