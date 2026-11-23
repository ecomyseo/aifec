<?php
/**
 * AIFEC — intermediarios de serie: quién pasa al panel, a la zona de cliente y a la API, y qué envíos se aceptan.
 *
 * admin    exige sesión del panel; en los envíos exige además que vengan de la propia web y con ficha válida.
 * customer exige cliente identificado.
 * csrf     envíos de la tienda: tienen que venir de la propia web (y con ficha válida si la traen).
 * api      respuestas siempre en JSON, sin caché y con límite de uso.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Admin;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Security\Csrf;

final class Gate
{
    public static function admin(Request $request, App $app, callable $next): Response
    {
        $admin = $app->auth()->admin();
        if ($admin === null) {
            if ($request->wantsJson()) {
                return Response::json(['error' => ['status' => 401, 'message' => t('Sign in to continue')]], 401);
            }
            $query = [];
            if ($request->method === 'GET' && rtrim($request->path, '/') !== '/admin') {
                $query['next'] = $app->url()->plain($request->path) . ($request->query !== [] ? '?' . http_build_query($request->query) : '');
            }

            return Response::redirect($app->url()->plain('/admin/login', $query));
        }
        if (!empty($admin['locale'])) {
            $app->i18n()->setLocale((string) $admin['locale']);
        }
        if (!in_array($request->method, ['GET', 'HEAD'], true) && (!$request->isSameOrigin() || !Admin::checkToken($app, $request))) {
            if ($request->wantsJson()) {
                return Response::json(['error' => ['status' => 419, 'message' => t('The form expired. Reload the page and try again.')]], 419);
            }
            Admin::flash($app, 'bad', t('The form expired. Try again.'));
            $base = $app->site()->basePath . '/' . ($app->adminAlias() !== '' ? $app->adminAlias() : 'admin');
            $from = parse_url((string) $request->header('referer', ''), PHP_URL_PATH);

            return Response::redirect(is_string($from) && strpos($from, $base) === 0 ? $from : $app->url()->plain('/admin'));
        }
        $response = $next($request);
        $response->headers['Cache-Control'] = 'no-store, private';
        $response->headers['X-Robots-Tag'] = 'noindex, nofollow';

        return $response;
    }

    public static function customer(Request $request, App $app, callable $next): Response
    {
        if ($app->auth()->customerId() === null) {
            if ($request->wantsJson()) {
                return Response::json(['error' => ['status' => 401, 'message' => t('Sign in to continue')]], 401);
            }
            $next = $request->method === 'GET' ? $app->url()->to($request->path) : '';
            $login = $app->router()->has('account.login') ? $app->url()->route('account.login') : $app->url()->to('/');

            return Response::redirect($login . ($next !== '' ? '?next=' . rawurlencode($next) : ''));
        }
        $response = $next($request);
        $response->headers['Cache-Control'] = 'no-store, private';

        return $response;
    }

    public static function csrf(Request $request, App $app, callable $next): Response
    {
        if (!in_array($request->method, ['GET', 'HEAD'], true)) {
            if (!$request->isSameOrigin()) {
                throw HttpError::forbidden('Cross-site request refused');
            }
            $token = $request->input('_token');
            if (is_string($token) && $token !== '' && !Csrf::check($app->session('customer')->csrfSecret(), 'front', $token, $app->clock()->now(), 86400)) {
                throw HttpError::forbidden('The form expired. Reload the page and try again.');
            }
        }

        return $next($request);
    }

    public static function api(Request $request, App $app, callable $next): Response
    {
        $limit = $app->settings()->int('api.rate_per_minute', 120);
        if ($limit > 0 && !(new RateLimiter($app->db(), $app->clock()))->hit('api:' . $request->ip, $limit, 60)) {
            $response = Response::json(['error' => ['status' => 429, 'message' => 'Too many requests']], 429);
            $response->headers['Retry-After'] = '60';

            return $response;
        }
        try {
            $response = $next($request);
        } catch (HttpError $e) {
            $response = Response::json(['error' => ['status' => $e->status(), 'message' => $e->getMessage()]], $e->status());
        }
        if (!isset($response->headers['Cache-Control'])) {
            $response->headers['Cache-Control'] = 'no-store';
        }

        return $response;
    }
}
