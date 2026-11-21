<?php
/** Control de navegadores en las rutas que crean carritos para asistentes. */
declare(strict_types=1);

namespace Aifec\Kernel;

final class BrowserCommerce
{
    public static function blocked(App $app, Request $request, bool $resume = false): bool
    {
        if ($request->method === 'HEAD') {
            return true;
        }
        $agent = $request->userAgent();
        $known = VisitLog::bot($agent);
        $automation = preg_match('/headless|playwright|puppeteer|selenium|browser-use/i', $agent) === 1 || $request->attr('ai_agent') !== null;
        $allowed = array_filter(array_map('trim', preg_split('/\R/', $app->settings()->str('api.browser_agents_allowed', '')) ?: []));
        if (in_array($agent, $allowed, true)) {
            return false;
        }
        if (self::crawler($agent)) {
            return true;
        }
        $browser = $automation || (stripos($agent, 'Mozilla/') === 0 && $known === null);

        return $browser && (!$resume || !self::navigation($request));
    }

    public static function crawler(string $agent): bool
    {
        $known = VisitLog::bot($agent);
        return $known !== null ? $known[2] !== 'ai_user'
            : preg_match('/bot\b|spider|crawl|semrush|hreflang|ahrefs/i', $agent) === 1;
    }

    /** Solo para continuar en caja o abrir una ficha. Nunca autoriza crear un carrito. */
    public static function navigation(Request $request): bool
    {
        $agent = $request->userAgent();
        return $request->method !== 'HEAD'
            && stripos($agent, 'Mozilla/') === 0
            && VisitLog::bot($agent) === null
            && !self::crawler($agent)
            && preg_match('/headless|playwright|puppeteer|selenium|browser-use/i', $agent) !== 1
            && $request->attr('ai_agent') === null
            && $request->header('sec-fetch-dest') === 'document'
            && $request->header('sec-fetch-mode', 'navigate') === 'navigate'
            && $request->header('sec-fetch-user') === '?1';
    }
}
