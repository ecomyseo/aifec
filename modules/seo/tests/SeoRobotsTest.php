<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * Pruebas de /robots.txt: lo que se cierra siempre, el mapa, las familias de robots, el sitio sin indexar y el texto libre.
 */

declare(strict_types=1);

use Aifec\Kernel\VisitLog;
use Aifec\Modules\Seo\Robots;

require_once __DIR__ . '/helpers.php';

test('/robots.txt siempre cierra el panel, el carrito, la caja, la cuenta, el buscador y los filtros, y trae el mapa', function (): void {
    $app = seoApp(['seo']);
    $r = (new TestClient($app))->get('/robots.txt');
    same(200, $r->status);
    contains('text/plain', $r->contentType());
    $body = $r->body;
    foreach (['/admin', '/carrito', '/finalizar-compra', '/mi-cuenta', '/buscar', '/*?sort=', '/*&sort=', '/*?q=', '/*?f[', '/*?f%5B', '/*?min=', '/*?max='] as $path) {
        contains('Disallow: ' . $path . "\n", $body, $path);
    }
    contains("Sitemap: https://tienda.test/sitemap.xml\n", $body, 'el mapa con la dirección completa');
    contains("User-agent: *\n", $body);
    same(200, (new TestClient($app))->get('/robots.txt')->status);
});

test('las palabras de las direcciones del carrito y la cuenta salen de los ajustes y en cada idioma', function (): void {
    $app = seoApp(['seo'], ['urls.cart' => 'cesta', 'urls.account' => 'mi-zona'], true);
    $body = (new TestClient($app))->get('/robots.txt')->body;
    contains("Disallow: /cesta\n", $body);
    contains("Disallow: /en/cesta\n", $body, 'también con el prefijo del otro idioma');
    contains("Disallow: /mi-zona\n", $body);
    notContains("Disallow: /carrito\n", $body);
});

test('por defecto las tres familias de robots entran y llevan las mismas reglas que el resto', function (): void {
    $body = (new TestClient(seoApp(['seo'])))->get('/robots.txt')->body;
    foreach (['Googlebot', 'bingbot', 'ChatGPT-User', 'Claude-User', 'PerplexityBot', 'GPTBot', 'ClaudeBot', 'CCBot'] as $agent) {
        $rules = seoGroupOf($body, $agent);
        check($rules !== [], $agent . ' tiene su grupo');
        check(in_array('Allow: /', $rules, true), $agent . ' puede entrar');
        check(!in_array('Disallow: /', $rules, true), $agent . ' no está bloqueado');
        check(in_array('Disallow: /admin', $rules, true), $agent . ' tampoco entra en el panel');
    }
});

test('los agentes de cada familia son los de VisitLog::BOTS', function (): void {
    $agents = Robots::agents();
    $all = [];
    foreach ($agents as $family => $list) {
        $all = array_merge($all, $list);
    }
    foreach (VisitLog::BOTS as $needle => $info) {
        $listed = in_array($needle, $all, true);
        $expected = in_array($info[2], ['search', 'ai_search', 'ai_user', 'ai_training'], true);
        same($expected, $listed, $needle . ' (' . $info[2] . ')');
    }
    check(in_array('Googlebot', $agents['search'], true) && !in_array('GPTBot', $agents['search'], true), 'GPTBot no es un buscador');
    check(in_array('ChatGPT-User', $agents['assistant'], true) && in_array('OAI-SearchBot', $agents['assistant'], true), 'los asistentes');
    check(in_array('GPTBot', $agents['training'], true) && in_array('ClaudeBot', $agents['training'], true), 'los de entrenamiento');
});

test('bloquear la familia de entrenamiento añade sus agentes con «Disallow: /» y no toca a los buscadores', function (): void {
    $app = seoApp(['seo'], ['seo.bots' => ['training' => 'disallow']]);
    $body = (new TestClient($app))->get('/robots.txt')->body;
    foreach (Robots::agents()['training'] as $agent) {
        same(['Disallow: /'], seoGroupOf($body, $agent), $agent . ' fuera de todo');
    }
    $google = seoGroupOf($body, 'Googlebot');
    check(in_array('Allow: /', $google, true) && !in_array('Disallow: /', $google, true), 'Googlebot sigue entrando');
    $assistant = seoGroupOf($body, 'ChatGPT-User');
    check(in_array('Allow: /', $assistant, true) && !in_array('Disallow: /', $assistant, true), 'y los asistentes también');
});

test('el ajuste de cada familia (el del panel) también bloquea, y el bloqueo gana al permiso', function (): void {
    $app = seoApp(['seo'], ['seo.bots.assistant' => 'disallow']);
    $body = (new TestClient($app))->get('/robots.txt')->body;
    same(['Disallow: /'], seoGroupOf($body, 'ChatGPT-User'));
    same(['Disallow: /'], seoGroupOf($body, 'PerplexityBot'));
    check(in_array('Allow: /', seoGroupOf($body, 'ClaudeBot'), true), 'entrenamiento sigue permitido');
    $mixed = seoApp(['seo'], ['seo.bots' => ['search' => 'disallow'], 'seo.bots.search' => 'allow']);
    same(['Disallow: /'], seoGroupOf((new TestClient($mixed))->get('/robots.txt')->body, 'Googlebot'), 'si uno de los dos ajustes bloquea, se bloquea');
});

test('un valor raro en «seo.bots» no bloquea nada ni rompe el fichero', function (): void {
    $app = seoApp(['seo'], ['seo.bots' => ['training' => ['disallow'], 'search' => 5, 'otra' => 'disallow']]);
    $body = (new TestClient($app))->get('/robots.txt')->body;
    check(in_array('Allow: /', seoGroupOf($body, 'GPTBot'), true), 'un valor que no es texto no bloquea');
    check(in_array('Allow: /', seoGroupOf($body, 'Googlebot'), true));
    notContains('otra', $body, 'una familia que no existe no sale');
});

test('con el sitio sin indexar el robots.txt dice «Disallow: /» para todos, y solo deja leer a quien abre una página para una persona', function (): void {
    $app = seoApp(['seo'], ['seo.noindex_site' => true, 'seo.robots_extra' => "Allow: /algo\nSitemap: https://tienda.test/otro.xml"]);
    $body = (new TestClient($app))->get('/robots.txt')->body;
    check(strpos($body, "User-agent: *\nDisallow: /\n") === 0, 'la regla general cierra todo: ' . $body);
    // Los lectores «para una persona» (ChatGPT-User, Claude-User…) tienen su grupo: leen, no indexan (las páginas van con noindex).
    contains("User-agent: Claude-User\n", $body);
    contains("User-agent: ChatGPT-User\n", $body);
    // Gemini lee con estos dos (16/11/2026: desde la tienda cerrada a todo, Gemini decía que no podía entrar).
    contains("User-agent: GoogleAgent-URLContext\n", $body);
    contains("User-agent: Google-Read-Aloud\n", $body);
    // Y con el nombre «Google» a secas, que también usa (17/11/2026); Googlebot sigue fuera (se comprueba más abajo).
    contains("User-agent: Google\n", $body);
    notContains('OAI-SearchBot', $body, 'el buscador de ChatGPT no entra si no se dice');
    // Con «seo.noindex_allow» = OAI-SearchBot, el buscador de ChatGPT lee la tienda oculta; Google sigue fuera (16/11/2026).
    $allow = seoApp(['seo'], ['seo.noindex_site' => true, 'seo.noindex_allow' => 'OAI-SearchBot, <mal>']);
    $open = (new TestClient($allow))->get('/robots.txt')->body;
    contains("User-agent: OAI-SearchBot\n", $open, 'el buscador de ChatGPT, en el grupo que puede leer');
    notContains('<mal>', $open, 'lo que no es un nombre de robot no se escribe');
    notContains('Googlebot', $open, 'Google sigue fuera');
    check(strpos($open, "User-agent: *\nDisallow: /\n") === 0, 'y la regla general sigue cerrando todo');
    contains("\nAllow: /\nDisallow: /admin\n", $body, 'pueden leer la tienda menos lo privado');
    // Ni buscadores, ni arañas de IA, ni entrenamiento, ni el texto libre, ni el mapa del sitio.
    foreach (['Googlebot', 'Bingbot', 'GPTBot', 'ClaudeBot', 'OAI-SearchBot', 'PerplexityBot', 'Allow: /algo', 'Sitemap:'] as $piece) {
        notContains($piece, $body);
    }

    // Con los asistentes bloqueados en los ajustes, «Disallow: /» para todos y nada más.
    $shut = seoApp(['seo'], ['seo.noindex_site' => true, 'seo.bots.assistant' => 'disallow']);
    same("User-agent: *\nDisallow: /\n", (new TestClient($shut))->get('/robots.txt')->body);
});

test('el texto libre solo conserva las líneas que un robots.txt admite', function (): void {
    $extra = implode("\n", [
        '# una nota',
        'User-agent: MiRobot',
        'Disallow: /privado/',
        'Crawl-delay: 10',
        'Crawl-delay: muchisimo',
        '<script>alert(1)</script>',
        'Disallow: <b>',
        'Allow: /publico',
        'Disallow: sin-barra',
        'Host: tienda.test',
        'Sitemap: javascript:alert(1)',
        'Sitemap: https://tienda.test/otro.xml',
        'User-agent: <malo>',
        '   ',
        "Disallow: /con\x00nulo",
    ]);
    $clean = Robots::sanitizeExtra($extra);
    same([
        '# una nota', 'User-agent: MiRobot', 'Disallow: /privado/', 'Crawl-delay: 10', 'Allow: /publico', 'Sitemap: https://tienda.test/otro.xml',
    ], explode("\n", $clean));
    $app = seoApp(['seo'], ['seo.robots_extra' => $extra]);
    $body = (new TestClient($app))->get('/robots.txt')->body;
    contains("User-agent: MiRobot\nDisallow: /privado/\n", $body, 'el texto libre va en el fichero');
    notContains('<script>', $body);
    notContains('Host:', $body);
    notContains('javascript:', $body);
    notContains("\x00", $body);
});

test('unas reglas sueltas sin «User-agent» se atan a «*»', function (): void {
    same("User-agent: *\nDisallow: /x\nDisallow: /y", Robots::sanitizeExtra("Disallow: /x\nDisallow: /y"));
    same('', Robots::sanitizeExtra(''), 'vacío');
    same('', Robots::sanitizeExtra("<<<>>>\nfoo"), 'solo basura');
});

test('el robots.txt se guarda en la caché de página y cambiar los ajustes la vacía', function (): void {
    $app = seoApp(['seo']);
    $web = new TestClient($app);
    $r = $web->get('/robots.txt');
    same(3600, $r->cacheTtl);
    check(seoServe($app, '/robots.txt') !== null, 'está en la caché');
    $app->invalidate(['settings']);
    check(seoServe($app, '/robots.txt') === null, 'guardar ajustes (etiqueta «settings») la vacía');
});
