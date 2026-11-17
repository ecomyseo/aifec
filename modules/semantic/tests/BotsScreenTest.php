<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Semantic\BotsChart;
use Aifec\Modules\Semantic\BotsReport;

require_once __DIR__ . '/helpers.php';

/*
 * La pantalla «Robots y asistentes»: totales de 7 y 30 días, tablas de robots y de orígenes de IA, gráfica diaria en SVG y
 * qué leen los asistentes.
 */

/** Una fila ya sumada de bot_visits, de hace $daysAgo días. */
function botsScreenSeed(App $app, int $daysAgo, string $kind, string $name, string $family, string $class, int $visits): void
{
    $at = $app->clock()->now() - $daysAgo * 86400;
    $app->db()->insert('bot_visits', [
        'day' => gmdate('Y-m-d', $at), 'kind' => $kind, 'name' => $name, 'family' => $family, 'page_class' => $class,
        'visits' => $visits, 'last_at' => $app->clock()->sql($at),
    ]);
}

test('la pantalla la ve quien tiene el permiso, a los demás les da 403 y sin sesión lleva al acceso', function (): void {
    same(200, adminClient(semanticApp())->get('/admin/semantic/bots')->status);
    same(403, adminClient(semanticApp(), 'support')->get('/admin/semantic/bots')->status);
    same(302, (new TestClient(semanticApp()))->get('/admin/semantic/bots')->status);
});

test('sin visitas la pantalla lo dice en cada apartado y no rompe', function (): void {
    $app = semanticApp();
    $r = adminClient($app)->get('/admin/semantic/bots');

    same(200, $r->status);
    contains('Ningún robot ha visitado la tienda todavía', $r->body);
    contains('Nadie ha llegado desde un asistente todavía', $r->body);
    contains('Los asistentes todavía no han leído nada', $r->body);
    contains('data-af-tabs', $r->body, 'cada apartado en su pestaña');
});

test('los totales de 7 y de 30 días cuentan cada visita en su ventana y no más allá', function (): void {
    $app = semanticApp();
    botsScreenSeed($app, 0, 'bot', 'GPTBot', 'training', 'product', 5);
    botsScreenSeed($app, 6, 'bot', 'GPTBot', 'training', 'product', 2);
    botsScreenSeed($app, 7, 'bot', 'ClaudeBot', 'training', 'other', 3);
    botsScreenSeed($app, 29, 'bot', 'ClaudeBot', 'training', 'other', 4);
    botsScreenSeed($app, 30, 'bot', 'ClaudeBot', 'training', 'other', 100);
    botsScreenSeed($app, 1, 'ref', 'ChatGPT', 'assistant', 'product', 6);
    botsScreenSeed($app, 20, 'ref', 'Gemini', 'assistant', 'category', 1);

    $report = BotsReport::build($app);
    same(['bot7' => 7, 'bot30' => 14, 'ref7' => 6, 'ref30' => 7], $report['totals']);
    same(30, count($report['days']));
    same('2026-11-12', $report['days'][29]['day'], 'el último día es hoy');
    same(5, $report['days'][29]['bot']);
    same(0, $report['days'][28]['bot'], 'los días sin visitas salen a cero');

    $body = adminClient($app)->get('/admin/semantic/bots')->body;
    contains('Visitas de robots, 7 días', $body);
    contains('Visitas desde asistentes, 30 días', $body);
});

test('la tabla de robots da las visitas de 30 días, la última vez y qué leen, del que más visita al que menos', function (): void {
    $app = semanticApp();
    botsScreenSeed($app, 0, 'bot', 'GPTBot', 'training', 'product', 5);
    botsScreenSeed($app, 3, 'bot', 'GPTBot', 'training', 'llms', 1);
    botsScreenSeed($app, 2, 'bot', 'ChatGPT-User', 'assistant', 'product', 9);

    $report = BotsReport::build($app);
    same(['ChatGPT-User', 'GPTBot'], array_map(static function (array $bot): string {
        return $bot['name'];
    }, $report['bots']));
    $gpt = $report['bots'][1];
    same(6, $gpt['visits']);
    same('2026-11-12 12:00:00', $gpt['last']);
    same(['product' => 5, 'llms' => 1], $gpt['reads']);

    $body = adminClient($app)->get('/admin/semantic/bots')->body;
    contains('Asistentes de IA', $body, 'la familia del robot de usuario');
    contains('Entrenamiento de IA', $body);
    contains('Páginas de producto 5', $body, 'lo que lee, con su cuenta');
    contains('llms.txt 1', $body);
});

test('los orígenes de IA salen con sus visitas y las páginas más leídas por asistentes, con cuentas aparte para robots y personas', function (): void {
    $app = semanticApp();
    botsScreenSeed($app, 0, 'ref', 'ChatGPT', 'assistant', 'product', 4);
    botsScreenSeed($app, 1, 'ref', 'ChatGPT', 'assistant', 'category', 2);
    botsScreenSeed($app, 1, 'ref', 'Perplexity', 'assistant', 'product', 1);
    botsScreenSeed($app, 1, 'bot', 'ChatGPT-User', 'assistant', 'product', 10);
    botsScreenSeed($app, 1, 'bot', 'GPTBot', 'training', 'product', 50);

    $report = BotsReport::build($app);
    same([['ChatGPT', 6], ['Perplexity', 1]], array_map(static function (array $source): array {
        return [$source['name'], $source['visits']];
    }, $report['sources']));
    $pages = [];
    foreach ($report['pages'] as $page) {
        $pages[$page['class']] = [$page['bots'], $page['refs']];
    }
    same(['product' => [10, 5], 'category' => [0, 2]], $pages, 'GPTBot es de entrenamiento: no cuenta como lectura de un asistente');
    same('product', $report['pages'][0]['class'], 'la más leída, primero');
});

test('la gráfica diaria es un SVG de servidor bien formado, con una barra por día que tiene visitas y sin JavaScript', function (): void {
    $app = semanticApp();
    botsScreenSeed($app, 0, 'bot', 'GPTBot', 'training', 'product', 5);
    botsScreenSeed($app, 0, 'ref', 'ChatGPT', 'assistant', 'product', 3);
    botsScreenSeed($app, 4, 'bot', 'GPTBot', 'training', 'product', 2);

    $svg = BotsChart::svg(BotsReport::build($app)['days'], 'Visitas <diarias> & más', 'Robots', 'Desde asistentes');
    $dom = new \DOMDocument();
    check($dom->loadXML($svg, LIBXML_NOERROR | LIBXML_NOWARNING), 'el SVG es XML válido');
    same('svg', $dom->documentElement->nodeName);
    same('img', $dom->documentElement->getAttribute('role'));
    same('Visitas <diarias> & más', $dom->documentElement->getAttribute('aria-label'), 'el título va escapado');
    same(3, $dom->getElementsByTagName('rect')->length, 'hoy: robots y asistentes; hace 4 días: robots');
    notContains('<script', $svg);

    $page = adminClient($app)->get('/admin/semantic/bots')->body;
    contains('<svg ', $page);
    contains('role="img"', $page);
    contains('Visitas diarias de robots y asistentes, últimos 30 días', $page);
});

test('una gráfica sin ninguna visita sigue siendo válida', function (): void {
    $svg = BotsChart::svg([], 'Sin datos', 'A', 'B');
    $dom = new \DOMDocument();
    check($dom->loadXML($svg, LIBXML_NOERROR | LIBXML_NOWARNING), 'XML válido');
    same(0, $dom->getElementsByTagName('rect')->length);
});

test('el nombre de un robot con etiquetas sale escapado en la pantalla', function (): void {
    $app = semanticApp();
    botsScreenSeed($app, 0, 'bot', '<script>alert(1)</script> & Co', 'other', 'other', 3);
    botsScreenSeed($app, 0, 'ref', '<img src=x onerror=1>', 'assistant', 'other', 2);
    $body = adminClient($app)->get('/admin/semantic/bots')->body;

    contains('&lt;script&gt;alert(1)&lt;/script&gt; &amp; Co', $body);
    contains('&lt;img src=x onerror=1&gt;', $body);
    notContains('<script>alert(1)', $body);
    notContains('<img src=x', $body);
});

test('avisa si los robots de los asistentes están bloqueados en robots.txt y lleva a donde se cambia', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'seo']);
    $web = adminClient($app);
    notContains('bloqueados en robots.txt', $web->get('/admin/semantic/bots')->body);

    $app->settings()->set('seo.bots.assistant', 'disallow');
    $body = $web->get('/admin/semantic/bots')->body;
    contains('Los robots de los asistentes de IA están bloqueados en robots.txt', $body);
    contains('href="/admin/settings/seo"', $body);

    $sinSeo = semanticApp();
    notContains('bloqueados en robots.txt', adminClient($sinSeo)->get('/admin/semantic/bots')->body, 'sin el módulo SEO no hay nada que los bloquee');
});
