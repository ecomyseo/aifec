<?php
/**
 * AIFEC — negociación, autenticación y exclusión de operaciones de comercio.
 * @author Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license Ver LICENSE.md
 */
declare(strict_types=1);

namespace Aifec\Modules\Agentic;

use Aifec\Kernel\App;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

final class ProtocolGate
{
    public static function handle(Request $request, App $app, callable $next): Response
    {
        $body = $request->body !== '' ? json_decode($request->body, true) : [];
        $body = is_array($body) ? $body : [];
        $version = (string) $request->header('ucp-version', '');
        if ($version === '' && isset($body['ucp']['version']) && is_string($body['ucp']['version'])) {
            $version = $body['ucp']['version'];
        }
        if ($version !== '' && !in_array($version, Ucp::VERSIONS, true)) {
            return self::error(400, 'version_unsupported', 'Unsupported UCP version.');
        }
        $request->setAttr('ucp_version', $version !== '' ? $version : Ucp::VERSION);
        if (($request->header('authorization') !== null || $request->header('x-api-key') !== null) && Ucp::key($app, $request) === null) {
            return self::error(401, 'unauthorized', 'Invalid API credentials.');
        }
        if (preg_match('~/ucp/v1/(?:carts|checkout-sessions)(?:/|$)~', $request->path) === 1
            && Ucp::key($app, $request) === null && \Aifec\Kernel\BrowserCommerce::blocked($app, $request)) {
            return self::error(403, 'browser_blocked', 'This browser is not allowed to use commerce sessions.');
        }
        if ($request->method !== 'GET' && $request->method !== 'HEAD') {
            if (strlen($request->body) > 65536) {
                return self::error(413, 'invalid_request', 'The request is too large.');
            }
            if ($request->body !== '' && (substr(ltrim($request->body), 0, 1) !== '{' || json_last_error() !== JSON_ERROR_NONE)) {
                return self::error(400, 'invalid_request', 'The body must be a JSON object.');
            }
            $type = strtolower(explode(';', (string) $request->header('content-type', ''))[0]);
            if ($request->body !== '' && $type !== 'application/json') {
                return self::error(415, 'unsupported_media_type', 'Use application/json.');
            }
        }
        $lock = '';
        if (in_array($request->method, ['POST', 'PUT'], true) && preg_match('~/(?:checkout-sessions|checkout_sessions|carts)/(chk_[a-f0-9]{32})(?:/|$)~D', $request->path, $m) === 1) {
            $lock = hash('sha256', 'commerce-lock|' . $m[1]);
            // Un bloqueo abandonado caduca; nunca se libera el bloqueo de otra petición en el finally.
            $app->db()->run('DELETE FROM {ucp_requests} WHERE key_hash = ? AND created_at < ?', [$lock, $app->clock()->sql($app->clock()->now() - 900)]);
            if (!$app->db()->insertIgnore('ucp_requests', ['key_hash' => $lock, 'request_hash' => '', 'status' => 0, 'created_at' => $app->clock()->sql()])) {
                return self::error(409, 'request_in_progress', 'Another operation is processing this checkout.');
            }
        }
        try {
            $response = $next($request);
            $response->headers['Cache-Control'] = 'no-store, private, max-age=0';
            $response->headers['X-Content-Type-Options'] = 'nosniff';
            if ($request->header('request-id') !== null) {
                $response->headers['Request-Id'] = preg_replace('/[^A-Za-z0-9_.:-]/', '', substr((string) $request->header('request-id'), 0, 128));
            }
            if ($request->header('idempotency-key') !== null) {
                $response->headers['Idempotency-Key'] = preg_replace('/[\r\n]/', '', substr((string) $request->header('idempotency-key'), 0, 255));
            }

            return $request->attr('commerce_protocol') === 'acp' ? $response : UcpSignatures::sign($app, $response);
        } finally {
            if ($lock !== '') {
                $app->db()->delete('ucp_requests', ['key_hash' => $lock]);
            }
        }
    }

    private static function error(int $status, string $code, string $content): Response
    {
        $response = Response::json(['ucp' => ['version' => Ucp::VERSION, 'status' => 'error'], 'messages' => [[
            'type' => 'error', 'code' => $code, 'content' => $content, 'severity' => 'unrecoverable',
        ]]], $status);
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Content-Type-Options'] = 'nosniff';

        return $response;
    }
}
