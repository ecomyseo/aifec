<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Apunta las visitas que interesan a la capa AI-First: las de robots conocidos y las que llegan
 * desde un asistente de IA. Es una línea en un fichero por día (sin base de datos, para que también
 * funcione cuando la página sale de la caché); una tarea periódica las agrega en sus tablas.
 *
 * No guarda la IP ni nada que identifique a una persona.
 */
final class VisitLog
{
    /** Robots conocidos: fragmento del agente de usuario => [nombre, dueño, clase]. Clases: search, ai_search, ai_user, ai_training, seo, other. */
    public const BOTS = [
        'OAI-SearchBot' => ['OAI-SearchBot', 'OpenAI', 'ai_search'],
        'ChatGPT-User' => ['ChatGPT-User', 'OpenAI', 'ai_user'],
        'GPTBot' => ['GPTBot', 'OpenAI', 'ai_training'],
        'Claude-SearchBot' => ['Claude-SearchBot', 'Anthropic', 'ai_search'],
        'Claude-User' => ['Claude-User', 'Anthropic', 'ai_user'],
        'ClaudeBot' => ['ClaudeBot', 'Anthropic', 'ai_training'],
        'Perplexity-User' => ['Perplexity-User', 'Perplexity', 'ai_user'],
        // Gemini lee la página que le da una persona con estos dos (medido en el registro, 16/11/2026).
        'GoogleAgent-URLContext' => ['GoogleAgent-URLContext', 'Google', 'ai_user'],
        'Google-Read-Aloud' => ['Google-Read-Aloud', 'Google', 'ai_user'],
        'PerplexityBot' => ['PerplexityBot', 'Perplexity', 'ai_search'],
        'Google-CloudVertexBot' => ['Google-CloudVertexBot', 'Google', 'ai_training'],
        'GoogleOther' => ['GoogleOther', 'Google', 'other'],
        'Storebot-Google' => ['Storebot-Google', 'Google', 'search'],
        'Googlebot' => ['Googlebot', 'Google', 'search'],
        'bingbot' => ['Bingbot', 'Microsoft', 'search'],
        'Applebot' => ['Applebot', 'Apple', 'search'],
        'Amazonbot' => ['Amazonbot', 'Amazon', 'ai_search'],
        'meta-externalagent' => ['Meta-ExternalAgent', 'Meta', 'ai_training'],
        'facebookexternalhit' => ['FacebookExternalHit', 'Meta', 'other'],
        'DuckAssistBot' => ['DuckAssistBot', 'DuckDuckGo', 'ai_user'],
        'DuckDuckBot' => ['DuckDuckBot', 'DuckDuckGo', 'search'],
        'MistralAI-User' => ['MistralAI-User', 'Mistral', 'ai_user'],
        'cohere-ai' => ['Cohere', 'Cohere', 'ai_training'],
        'Bytespider' => ['Bytespider', 'ByteDance', 'ai_training'],
        'CCBot' => ['CCBot', 'Common Crawl', 'ai_training'],
        'YandexBot' => ['YandexBot', 'Yandex', 'search'],
        'Baiduspider' => ['Baiduspider', 'Baidu', 'search'],
        'PetalBot' => ['PetalBot', 'Huawei', 'search'],
        'SemrushBot' => ['SemrushBot', 'Semrush', 'seo'],
        'AhrefsBot' => ['AhrefsBot', 'Ahrefs', 'seo'],
        'MJ12bot' => ['MJ12bot', 'Majestic', 'seo'],
        'DotBot' => ['DotBot', 'Moz', 'seo'],
        // El lector de Gemini se presenta a veces solo como «Google» (17/11/2026: abrió la portada y una demo así y después
        // dijo «el servidor me bloquea el acceso automático»: robots.txt no le dejaba). Va el ÚLTIMO para no tapar a los demás
        // de Google, que llevan «Google» dentro; en robots.txt el nombre «Google» no es «Googlebot», que sigue fuera.
        'Google' => ['Google', 'Google', 'ai_user'],
    ];

    /** Dominios de asistentes desde los que puede llegar una visita. */
    public const AI_REFERRERS = [
        'chatgpt.com' => 'ChatGPT', 'chat.openai.com' => 'ChatGPT', 'perplexity.ai' => 'Perplexity',
        'gemini.google.com' => 'Gemini', 'copilot.microsoft.com' => 'Copilot', 'claude.ai' => 'Claude',
        'chat.mistral.ai' => 'Le Chat', 'you.com' => 'You.com', 'phind.com' => 'Phind', 'duck.ai' => 'Duck.ai',
    ];

    /**
     * Robot al que pertenece un agente de usuario, o null.
     *
     * @return array{0:string,1:string,2:string}|null [nombre, dueño, clase]
     */
    public static function bot(string $userAgent): ?array
    {
        if ($userAgent === '') {
            return null;
        }
        foreach (self::BOTS as $needle => $info) {
            if (stripos($userAgent, $needle) !== false) {
                return $info;
            }
        }

        return null;
    }

    /** Asistente del que viene la visita (por la página de origen o por utm_source), o null. */
    public static function aiSource(Request $request): ?string
    {
        $candidates = [];
        $referer = $request->header('referer');
        if ($referer !== null) {
            $host = parse_url($referer, PHP_URL_HOST);
            if (is_string($host)) {
                $candidates[] = strtolower($host);
            }
        }
        $utm = $request->get('utm_source');
        if (is_string($utm) && $utm !== '') {
            $candidates[] = strtolower($utm);
        }
        foreach ($candidates as $candidate) {
            foreach (self::AI_REFERRERS as $domain => $name) {
                if ($candidate === $domain || substr($candidate, -strlen('.' . $domain)) === '.' . $domain) {
                    return $name;
                }
            }
        }

        return null;
    }

    /** Apunta la visita si es de un robot conocido o llega de un asistente. Nunca lanza. */
    public static function record(Site $site, Request $request, int $status, bool $cached, Clock $clock): void
    {
        try {
            if ($request->method !== 'GET' || strpos($request->path, '/admin') === 0 || strpos($request->path, '/assets/') === 0) {
                return;
            }
            $bot = self::bot($request->userAgent());
            $source = $bot === null ? self::aiSource($request) : null;
            if ($bot === null && $source === null) {
                return;
            }
            $dir = $site->path('logs', true);
            $line = implode("\t", [
                (string) $clock->now(),
                $bot !== null ? 'bot' : 'ref',
                $bot !== null ? $bot[0] : (string) $source,
                (string) $status,
                $cached ? '1' : '0',
                str_replace(["\t", "\n", "\r"], ' ', substr($request->rawPath, 0, 300)),
            ]);
            @file_put_contents($dir . '/visits-' . gmdate('Y-m-d', $clock->now()) . '.tsv', $line . "\n", FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // Contar visitas nunca puede romper una página.
            return;
        }
    }
}
