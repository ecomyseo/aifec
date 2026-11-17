<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * El intermediario «erp.api»: todo lo que pasa entre la petición de un ERP y su manejador.
 *
 *   1. la clave (Authorization: Bearer … o X-Api-Key) tiene que ser de una conexión activa → 401
 *   2. la clave tiene que tener el ámbito de la ruta → 403
 *   3. límite de uso por clave (ajuste erp.rate_per_minute) con cabeceras RateLimit-* → 429
 *   4. el cuerpo se lee como JSON → 400 si está roto
 *   5. una escritura con Idempotency-Key no se hace dos veces
 *   6. cualquier fallo sale como {"error":{"status","code","message","field"}}; los imprevistos, como un 500 sin detalles
 */
final class ApiGate
{
    public static function handle(Request $request, App $app, callable $next): Response
    {
        $limitInfo = null;
        $connection = null;
        $reserved = '';
        try {
            $spec = Routes::find($app, (string) $request->attr('route', ''));
            if ($spec === null || ($spec['needs'] !== '' && !$app->has((string) $spec['needs']))) {
                throw new ApiException(404, 'not_found', 'Not found');
            }
            $connection = self::authenticate($request, $app);
            if (!in_array((string) $spec['scope'], $connection['scopes'], true)) {
                throw new ApiException(403, 'insufficient_scope', 'This key does not have the scope ' . (string) $spec['scope']);
            }
            $limitInfo = self::limit($app, $connection);
            if (isset($limitInfo['blocked'])) {
                return self::headers($limitInfo['blocked'], $limitInfo);
            }
            $erp = $app->service('erp');
            $erp->connections()->touch($connection);
            $request->setAttr('erp.connection', $connection);
            $request->setAttr('erp.body', self::body($request));

            $key = trim((string) $request->header('idempotency-key', ''));
            if ($request->method !== 'GET' && $request->method !== 'HEAD' && $key !== '') {
                if (strlen($key) > 120) {
                    throw new ApiException(400, 'invalid_idempotency_key', 'The Idempotency-Key is too long (120 characters at most)', 'Idempotency-Key');
                }
                $fingerprint = hash('sha256', $request->method . '|' . $request->path . '|' . $request->body . '|' . http_build_query($request->post));
                $replay = $erp->idempotency()->begin((int) $connection['id'], $key, $fingerprint);
                if ($replay !== null) {
                    return self::headers($replay, $limitInfo);
                }
                $reserved = $key;
            }

            $response = $next($request);
            if ($reserved !== '') {
                if ($response->status >= 500) {
                    $erp->idempotency()->abandon((int) $connection['id'], $reserved);
                } else {
                    $erp->idempotency()->finish((int) $connection['id'], $reserved, $response);
                }
            }
        } catch (ApiException $e) {
            $response = self::error($e->status(), $e->errorCode(), $e->getMessage(), $e->field());
            if ($e->status() === 401) {
                $response->headers['WWW-Authenticate'] = 'Bearer realm="erp"';
            }
            if ($reserved !== '' && $connection !== null && $e->errorCode() !== 'idempotency_in_progress') {
                // Un error de validación también se guarda: repetir la misma petición da el mismo error.
                $app->service('erp')->idempotency()->finish((int) $connection['id'], $reserved, $response);
            }
        } catch (HttpError $e) {
            $response = self::error($e->status(), $e->status() === 404 ? 'not_found' : 'http_error', $e->getMessage(), null);
        } catch (\Throwable $e) {
            $app->log()->exception($e, 'erp.api ' . $request->method . ' ' . $request->path);
            if ($reserved !== '' && $connection !== null) {
                $app->service('erp')->idempotency()->abandon((int) $connection['id'], $reserved);
            }
            $response = self::error(500, 'server_error', 'The server could not process the request', null);
        }

        return self::headers($response, $limitInfo);
    }

    /**
     * @return array<string,mixed>
     */
    private static function authenticate(Request $request, App $app): array
    {
        $key = '';
        $auth = (string) $request->header('authorization', '');
        if (preg_match('/^Bearer\s+(\S+)\s*$/i', $auth, $m) === 1) {
            $key = $m[1];
        } elseif ((string) $request->header('x-api-key', '') !== '') {
            $key = trim((string) $request->header('x-api-key', ''));
        }
        if ($key === '') {
            throw new ApiException(401, 'missing_key', 'Send the key of a connection as Authorization: Bearer <key>');
        }
        $connection = $app->service('erp')->connections()->authenticate($key);
        if ($connection === null) {
            // Quien prueba claves a ciegas se corta pronto: 30 fallos por minuto y por dirección.
            if (!(new RateLimiter($app->db(), $app->clock()))->hit('erp-auth:' . $request->ip, 30, 60)) {
                throw new ApiException(429, 'too_many_failures', 'Too many wrong keys: wait a minute');
            }
            throw new ApiException(401, 'invalid_key', 'The key is wrong, switched off or revoked');
        }

        return $connection;
    }

    /**
     * @param array<string,mixed> $connection
     *
     * @return array<string,mixed> limit, remaining, reset y, si se ha pasado del tope, blocked (la respuesta 429)
     */
    private static function limit(App $app, array $connection): array
    {
        $max = max(1, $app->settings()->int('erp.rate_per_minute', 300));
        $key = 'erp:' . (int) $connection['id'];
        $limiter = new RateLimiter($app->db(), $app->clock());
        $ok = $limiter->hit($key, $max, 60);
        $start = (int) $app->db()->value('SELECT window_start FROM {rate_limits} WHERE k = ?', [$key]);
        $reset = max(1, $start + 60 - $app->clock()->now());
        $info = ['limit' => $max, 'remaining' => $limiter->remaining($key, $max, 60), 'reset' => $reset];
        if (!$ok) {
            $response = self::error(429, 'rate_limited', 'Too many requests: wait ' . $reset . ' seconds', null);
            $response->headers['Retry-After'] = (string) $reset;
            $info['blocked'] = $response;
        }

        return $info;
    }

    /**
     * @return array<string,mixed>
     */
    private static function body(Request $request): array
    {
        if ($request->method === 'GET' || $request->method === 'HEAD') {
            return [];
        }
        $text = trim($request->body);
        if ($text === '') {
            return $request->post;
        }
        $data = json_decode($text, true);
        if (!is_array($data)) {
            throw new ApiException(400, 'invalid_json', 'The body is not valid JSON');
        }

        return $data;
    }

    /**
     * @param array<string,mixed>|null $info
     */
    private static function headers(Response $response, ?array $info): Response
    {
        if ($info !== null) {
            $response->headers['RateLimit-Limit'] = (string) $info['limit'];
            $response->headers['RateLimit-Remaining'] = (string) $info['remaining'];
            $response->headers['RateLimit-Reset'] = (string) $info['reset'];
        }
        if (!isset($response->headers['Cache-Control'])) {
            $response->headers['Cache-Control'] = 'no-store';
        }

        return $response;
    }

    private static function error(int $status, string $code, string $message, ?string $field): Response
    {
        return Response::json(['error' => ['status' => $status, 'code' => $code, 'message' => $message, 'field' => $field]], $status);
    }
}
