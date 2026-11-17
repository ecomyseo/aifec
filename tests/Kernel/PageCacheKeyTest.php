<?php
/**
 * AIFEC — pruebas de la clave de la caché de página: qué peticiones pueden salir de una copia guardada y cuáles no.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;

/**
 * @param array<string,string> $headers
 * @param array<string,string> $cookies
 */
function pageCacheKey(string $uri, array $headers = [], array $cookies = [], string $method = 'GET'): ?string
{
    $app = testApp();
    $request = Request::create($method, $uri, [], $cookies, $headers + ['user-agent' => 'Mozilla/5.0 (Windows NT 10.0)']);
    $request->host = 'tienda.test';
    $request->secure = true;

    return PageCache::key($app->site(), $request);
}

test('los parámetros de seguimiento no multiplican las copias y los demás sí cuentan', function (): void {
    $plain = pageCacheKey('/producto/silla');
    check(is_string($plain) && $plain !== '', 'una página normal tiene clave');
    same($plain, pageCacheKey('/producto/silla?utm_source=boletin&utm_medium=email&gclid=abc&fbclid=x'));
    check($plain !== pageCacheKey('/producto/silla?talla=m'), 'un parámetro de verdad da otra copia');
    check($plain !== pageCacheKey('/producto/mesa'), 'otra dirección, otra copia');
    same(null, pageCacheKey('/producto/silla', [], [], 'POST'), 'un envío no sale de la caché');
    same(null, pageCacheKey('/producto/silla?a=1&b=2&c=3&d=4&e=5'), 'demasiados parámetros');
});

test('quien llega desde un asistente de IA no sale de la caché, por utm_source o por la página de origen', function (): void {
    $plain = pageCacheKey('/producto/silla');
    foreach (['chatgpt.com', 'perplexity.ai', 'gemini.google.com', 'copilot.microsoft.com', 'claude.ai'] as $source) {
        same(null, pageCacheKey('/producto/silla?utm_source=' . $source), 'utm_source=' . $source);
        same(null, pageCacheKey('/producto/silla', ['referer' => 'https://' . $source . '/c/123']), 'viene de ' . $source);
    }
    same(null, pageCacheKey('/producto/silla', ['referer' => 'https://www.perplexity.ai/search?q=silla']), 'un subdominio del asistente');
    // Lo que no es un asistente sigue saliendo de la copia de todos.
    same($plain, pageCacheKey('/producto/silla?utm_source=google'));
    same($plain, pageCacheKey('/producto/silla', ['referer' => 'https://www.google.com/search?q=silla']));
    same($plain, pageCacheKey('/producto/silla', ['referer' => 'https://no-es-chatgpt.com.test/']), 'un dominio que solo se parece');
});

test('un robot sale de la caché aunque traiga el origen de un asistente: no compra, y su visita la apunta el registro', function (): void {
    $plain = pageCacheKey('/producto/silla');
    same($plain, pageCacheKey('/producto/silla?utm_source=chatgpt.com', ['user-agent' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.1; +https://openai.com/gptbot)']));
    same($plain, pageCacheKey('/producto/silla', ['user-agent' => 'PerplexityBot/1.0', 'referer' => 'https://www.perplexity.ai/']));
});
