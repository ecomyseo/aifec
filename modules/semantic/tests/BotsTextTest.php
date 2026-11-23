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
use Aifec\Kernel\Clock;
use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;
use Aifec\Kernel\VisitLog;
use Aifec\Modules\Semantic\Bots;
use Aifec\Modules\Semantic\BotsReport;

require_once __DIR__ . '/helpers.php';

/*
 * «Todos los robots leen los .md»: la cabecera Link en cada página con versión en texto, la versión en texto en la misma dirección
 * para las familias de robots que los ajustes dicen (nunca para los de vista previa de enlaces), el descubrimiento (robots.txt,
 * llms.txt, sitemap-md.xml) y el panel de robots.
 */

/** Los 14 robots del informe de gmartos.es con su agente de usuario real => ¿recibe el texto con los ajustes de serie? */
const BOTSTEXT_AGENTS = [
    'GPTBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)', true],
    'ClaudeBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)', true],
    'MJ12bot' => ['Mozilla/5.0 (compatible; MJ12bot/v1.4.8; http://mj12bot.com/)', true],
    'Google' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36 Google', true],
    'Claude-User' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0; +Claude-User@anthropic.com)', true],
    'Googlebot' => ['Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', false],
    'Amazonbot' => ['Mozilla/5.0 AppleWebKit/600.2.5 (KHTML, like Gecko) Version/8.0.2 Safari/600.2.5 (Amazonbot/0.1; +https://developer.amazon.com/support/amazonbot)', true],
    'Meta-ExternalAgent' => ['meta-externalagent/1.1 (+https://developers.facebook.com/docs/sharing/webmasters/crawler)', true],
    'Bingbot' => ['Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)', false],
    'OAI-SearchBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot', true],
    'GoogleOther' => ['Mozilla/5.0 (compatible; GoogleOther)', true],
    'Bytespider' => ['Mozilla/5.0 (Linux; Android 5.0) AppleWebKit/537.36 (KHTML, like Gecko) Mobile Safari/537.36 (compatible; Bytespider; spider-feedback@bytedance.com)', true],
    'Claude-SearchBot' => ['Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-SearchBot/1.0; +Claude-SearchBot@anthropic.com)', true],
    'FacebookExternalHit' => ['facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)', false],
];

/** Los robots de vista previa de enlaces: siempre HTML. */
const BOTSTEXT_PREVIEW = [
    'Twitterbot' => 'Twitterbot/1.0',
    'LinkedInBot' => 'LinkedInBot/1.0 (compatible; Mozilla/5.0; Apache-HttpClient +http://www.linkedin.com)',
    'Slackbot' => 'Slackbot-LinkExpanding 1.0 (+https://api.slack.com/robots)',
    'WhatsApp' => 'WhatsApp/2.23.20.0 A',
    'TelegramBot' => 'TelegramBot (like TwitterBot)',
    'Discordbot' => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://discordapp.com)',
];

function botsTextShop(array $settings = [], array $modules = ['semantic', 'catalog', 'media', 'cms']): App
{
    $app = semanticApp($modules, $settings);
    semanticProduct($app, ['description' => '<p>Roble macizo con acabado natural y envío rápido.</p>']);

    return $app;
}

test('con los ajustes de serie, cada uno de los 14 robots recibe el texto o el HTML que le toca en la misma dirección', function (): void {
    $app = botsTextShop();
    $web = new TestClient($app);
    foreach (BOTSTEXT_AGENTS as $name => [$agent, $gets]) {
        $r = $web->get('/producto/silla', ['user-agent' => $agent]);
        same(200, $r->status, $name);
        if ($gets) {
            same('text/plain; charset=UTF-8', $r->contentType(), $name . ': el texto va como text/plain (el lector de Gemini no abre text/markdown)');
            contains('# Silla de roble', $r->body, $name . ': es el texto de la ficha');
            contains('User-Agent', (string) $r->headers['Vary'], $name . ': Vary: User-Agent');
            check(isset($r->headers['Link']) && strpos((string) $r->headers['Link'], 'rel="canonical"') !== false, $name . ': Link canonical a la página');
            check(!isset($r->headers['X-Robots-Tag']), $name . ': en la misma dirección no hay «noindex» (quitaría la página del buscador)');
            check(VisitLog::isTextVersion($r), $name . ': el registro de visitas la cuenta como versión en texto');
        } else {
            contains('text/html', $r->contentType(), $name . ': recibe la página');
            contains('<html', $r->body, $name . ': HTML de verdad');
            check(!VisitLog::isTextVersion($r), $name . ': no es versión en texto');
        }
    }
    // Googlebot con los ajustes de serie sigue recibiendo HTML, con la cabecera que anuncia la versión en texto.
    $google = $web->get('/producto/silla', ['user-agent' => BOTSTEXT_AGENTS['Googlebot'][0]]);
    contains('text/html', $google->contentType());
    contains('<script type="application/ld+json"', $google->body, 'y con sus datos estructurados');
    contains('rel="alternate"; type="text/markdown"', (string) $google->headers['Link']);
});

test('los robots de vista previa de enlaces reciben siempre el HTML, aunque se encienda todo', function (): void {
    $app = botsTextShop(['semantic.text_for.search' => true, 'semantic.text_for.other' => true, 'semantic.text_for.assistant' => true, 'semantic.text_for.training' => true, 'semantic.text_for.seo' => true]);
    $web = new TestClient($app);
    $previews = BOTSTEXT_PREVIEW + ['FacebookExternalHit' => BOTSTEXT_AGENTS['FacebookExternalHit'][0]];
    foreach ($previews as $name => $agent) {
        $r = $web->get('/producto/silla', ['user-agent' => $agent]);
        contains('text/html', $r->contentType(), $name . ': HTML con las etiquetas og:');
        contains('og:title', $r->body, $name);
        check(!VisitLog::isTextVersion($r), $name);
        same('', VisitLog::textClass($agent), $name . ': sin clase de texto: su copia en la caché es la de una persona');
    }
    // Y los buscadores, ahora sí, reciben el texto porque el interruptor está encendido.
    $r = $web->get('/producto/silla', ['user-agent' => BOTSTEXT_AGENTS['Googlebot'][0]]);
    contains('text/plain', $r->contentType());
    check(!isset($r->headers['X-Robots-Tag']), 'sin «noindex»: es la misma dirección que la página');
});

test('apagar la familia de un robot le devuelve el HTML y el interruptor de buscadores está apagado de serie', function (): void {
    $fields = [];
    foreach ((array) (require dirname(__DIR__) . '/settings.php')['tabs'] as $tab) {
        $fields += $tab['fields'];
    }
    foreach (['training', 'assistant', 'seo', 'other'] as $family) {
        same(true, $fields['semantic.text_for.' . $family]['default'], $family . ': encendido de serie');
    }
    same(false, $fields['semantic.text_for.search']['default'], 'buscadores: apagado de serie');
    contains('rich results', $fields['semantic.text_for.search']['hint'], 'con la advertencia a la vista');
    check(isset($fields['semantic.text_for.search']['attrs']['data-af-confirm-on']), 'y pide confirmación al encenderlo');
    $app = botsTextShop(['semantic.text_for.training' => false, 'semantic.text_for.seo' => false]);
    $web = new TestClient($app);
    contains('text/html', $web->get('/producto/silla', ['user-agent' => BOTSTEXT_AGENTS['GPTBot'][0]])->contentType(), 'GPTBot: entrenamiento apagado');
    contains('text/html', $web->get('/producto/silla', ['user-agent' => BOTSTEXT_AGENTS['MJ12bot'][0]])->contentType(), 'MJ12bot: SEO apagado');
    contains('text/plain', $web->get('/producto/silla', ['user-agent' => BOTSTEXT_AGENTS['Claude-User'][0]])->contentType(), 'Claude-User sigue recibiendo el texto');
});

test('una persona con un navegador recibe siempre el HTML, y «Accept: text/markdown» y «.md» siguen funcionando', function (): void {
    $app = botsTextShop();
    $web = new TestClient($app);
    $browser = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';
    contains('text/html', $web->get('/producto/silla', ['user-agent' => $browser])->contentType());
    $accept = $web->get('/producto/silla', ['user-agent' => $browser, 'accept' => 'text/markdown']);
    same('text/markdown; charset=UTF-8', $accept->contentType());
    contains('Accept', (string) $accept->headers['Vary']);
    check(!isset($accept->headers['X-Robots-Tag']), 'por Accept tampoco hay noindex: es la misma dirección');
    $md = $web->get('/producto/silla.md', ['user-agent' => $browser]);
    same('text/plain; charset=UTF-8', $md->contentType());
    same('noindex', $md->headers['X-Robots-Tag'], 'la dirección .md sí lleva noindex y su canónica');
    contains('rel="canonical"', (string) $md->headers['Link']);
    same($md->body, $accept->body, 'el mismo texto por .md que por Accept');
});

test('el texto de la ficha lleva nombre, precio, descripción y enlace de compra, y nada que la página no diga', function (): void {
    $app = botsTextShop();
    $web = new TestClient($app);
    $html = $web->get('/producto/silla', ['user-agent' => 'Mozilla/5.0 Chrome/131.0'])->body;
    $md = $web->get('/producto/silla', ['user-agent' => BOTSTEXT_AGENTS['GPTBot'][0]])->body;
    contains('# Silla de roble', $md, 'nombre');
    contains($app->i18n()->money(1999, 'EUR'), $md, 'precio');
    contains('Roble macizo con acabado natural y envío rápido.', $md, 'descripción');
    contains('https://tienda.test/producto/silla', $md, 'enlace de la ficha, desde donde se compra');
    // Sin texto de más: cada palabra de cuatro letras o más del texto sale también en la página (texto, atributos o datos estructurados).
    $page = mb_strtolower(html_entity_decode(strip_tags((string) preg_replace('~<script\b[^>]*>~i', ' ', $html)) . ' ' . $html, ENT_QUOTES, 'UTF-8'));
    $extra = [];
    $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower(preg_replace('~https?://\S+~', ' ', $md))) ?: [];
    foreach ($words as $word) {
        if (mb_strlen($word) >= 4 && strpos($page, $word) === false) {
            $extra[$word] = true;
        }
    }
    // Los únicos que no salen son las etiquetas de la plantilla del texto («Precio», «Disponibilidad», «Enlace»), no contenido.
    same([], array_values(array_diff(array_keys($extra), ['precio', 'disponibilidad', 'enlace'])), 'palabras del texto que la página no tiene');
});

test('cada página con versión en texto anuncia «Link: <….md>; rel="alternate"; type="text/markdown"», y el carrito y la cuenta no', function (): void {
    $app = botsTextShop([], ['semantic', 'catalog', 'media', 'cms', 'orders', 'customers']);
    aifecCategory($app, 'Muebles');
    $web = new TestClient($app);
    $browser = ['user-agent' => 'Mozilla/5.0 Chrome/131.0'];
    foreach (['/' => 'https://tienda.test/index.md', '/producto/silla' => 'https://tienda.test/producto/silla.md'] as $path => $md) {
        $r = $web->get($path, $browser);
        same(200, $r->status, $path);
        contains('<' . $md . '>; rel="alternate"; type="text/markdown"', (string) $r->headers['Link'], $path);
        contains('Accept', (string) $r->headers['Vary'], $path);
        contains('<link rel="alternate" type="text/markdown" href="' . $md . '">', $r->body, $path . ': y en el HTML, como siempre');
    }
    $category = $web->get('/categoria/muebles', $browser);
    if ($category->status === 200) {
        contains('rel="alternate"; type="text/markdown"', (string) $category->headers['Link'], 'categoría');
    }
    foreach (['/carrito', '/mi-cuenta/entrar'] as $path) {
        $r = $web->get($path, $browser);
        check(!isset($r->headers['Link']) || strpos((string) $r->headers['Link'], 'text/markdown') === false, $path . ': no anuncia versión en texto');
    }
    // El HTML que sale de la caché de página lleva la cabecera (se guarda con la página).
    $cached = $web->get('/producto/silla', $browser);
    check(isset($cached->headers['Link']), 'la cabecera forma parte de la página guardada');
});

test('la copia de la caché de página va separada por clase de robot: ni el texto llega a una persona ni el HTML a un robot de texto', function (): void {
    $app = botsTextShop();
    $key = static function (string $agent) use ($app): string {
        return (string) PageCache::key($app->site(), Request::create('GET', '/producto/silla', [], [], ['user-agent' => $agent]));
    };
    $person = $key('Mozilla/5.0 Chrome/131.0');
    $keys = [
        'GPTBot' => $key(BOTSTEXT_AGENTS['GPTBot'][0]),
        'Googlebot' => $key(BOTSTEXT_AGENTS['Googlebot'][0]),
        'Claude-User' => $key(BOTSTEXT_AGENTS['Claude-User'][0]),
        'MJ12bot' => $key(BOTSTEXT_AGENTS['MJ12bot'][0]),
    ];
    foreach ($keys as $name => $value) {
        check($value !== $person, $name . ': no comparte copia con una persona');
    }
    same(4, count(array_unique($keys)), 'y cada clase de robot tiene la suya');
    same($person, $key(BOTSTEXT_AGENTS['FacebookExternalHit'][0]), 'un robot de vista previa comparte la copia de una persona (HTML)');
    same($person, $key(BOTSTEXT_PREVIEW['Twitterbot']), 'Twitterbot también');
    same($key(BOTSTEXT_AGENTS['GPTBot'][0]), $key(BOTSTEXT_AGENTS['ClaudeBot'][0]), 'dos robots de la misma clase comparten copia');
});

test('el robots.txt anuncia las versiones en texto y su mapa, y el mapa y el llms.txt las listan sin el carrito ni la cuenta', function (): void {
    $app = botsTextShop([], ['semantic', 'catalog', 'media', 'cms', 'seo', 'orders']);
    aifecCategory($app, 'Muebles');
    $web = new TestClient($app);
    $robots = $web->get('/robots.txt')->body;
    contains('Sitemap: https://tienda.test/sitemap.xml', $robots);
    contains('Sitemap: https://tienda.test/sitemap-md.xml', $robots, 'el mapa de versiones en texto');
    contains('https://tienda.test/llms.txt', $robots, 'la guía para asistentes en un comentario');

    $map = $web->get('/sitemap-md.xml');
    same(200, $map->status);
    contains('application/xml', $map->contentType());
    contains('<loc>https://tienda.test/index.md</loc>', $map->body);
    contains('<loc>https://tienda.test/producto/silla.md</loc>', $map->body);
    contains('.md</loc>', $map->body);
    check(preg_match_all('~<loc>([^<]+)</loc>~', $map->body, $m) > 3, 'varias direcciones');
    foreach ($m[1] as $url) {
        check(substr($url, -3) === '.md', $url . ' acaba en .md');
        notContains('carrito', $url);
        notContains('mi-cuenta', $url);
        notContains('finalizar', $url);
    }
    $llms = $web->get('/llms.txt')->body;
    contains('(https://tienda.test/index.md)', $llms, 'la portada en texto');
    contains('(https://tienda.test/sitemap-md.xml)', $llms, 'y el mapa de todas');
    contains('(https://tienda.test/producto/silla.md)', $llms);
    check(preg_match('~\(https://tienda\.test/categoria/[^)]*\.md\)~', $llms) === 1, 'las categorías en texto');
});

test('el panel de robots cuenta igual lo recibido por «.md», por «Accept» o por servicio directo, con su porcentaje y «Lee .md»', function (): void {
    $app = botsTextShop();
    $log = static function (string $path, string $agent, bool $text) use ($app): void {
        VisitLog::record($app->site(), Request::create('GET', $path, [], [], ['user-agent' => $agent]), 200, false, new Clock($app->clock()->now()), $text);
    };
    $gpt = BOTSTEXT_AGENTS['GPTBot'][0];
    $log('/producto/silla.md', $gpt, true);        // por la dirección .md
    $log('/producto/silla', $gpt, true);           // por Accept o por servicio directo (misma dirección)
    $log('/producto/silla', $gpt, true);
    $log('/producto/silla', $gpt, false);          // HTML
    $log('/producto/silla', BOTSTEXT_AGENTS['Googlebot'][0], false);
    $log('/producto/silla', BOTSTEXT_AGENTS['Googlebot'][0], false);
    $log('/robots.txt', $gpt, false);
    Bots::ingest($app);
    $report = BotsReport::build($app);
    $by = [];
    foreach ($report['bots'] as $bot) {
        $by[$bot['name']] = $bot;
    }
    same(5, $by['GPTBot']['visits']);
    same(3, $by['GPTBot']['md'], 'las tres visitas de texto, vengan como vengan');
    same(60.0, (float) $by['GPTBot']['md_pct']);
    same(true, $by['GPTBot']['reads_md']);
    same(0, $by['Googlebot']['md']);
    same(false, $by['Googlebot']['reads_md']);
    // La pantalla.
    $page = adminClient($app)->get('/admin/semantic/bots');
    same(200, $page->status);
    contains('Lee .md', $page->body);
    contains('60 %', $page->body);
    // El registro antiguo (seis columnas) sigue leyéndose.
    $old = Bots::parseLine("1700000000\tbot\tGPTBot\t200\t0\t/producto/silla");
    same(false, $old['text']);
    same(true, Bots::parseLine("1700000000\tbot\tGPTBot\t200\t0\t/producto/silla\t1")['text']);
});
