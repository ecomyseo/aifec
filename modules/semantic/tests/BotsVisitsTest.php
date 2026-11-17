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
use Aifec\Kernel\Request;
use Aifec\Kernel\VisitLog;
use Aifec\Modules\Semantic\Bots;
use Aifec\Modules\Semantic\BotsJobs;

require_once __DIR__ . '/helpers.php';

/*
 * La tarea «semantic.visits»: lee los registros de ayer y de hoy que deja el núcleo y los suma en bot_visits, sin contar nada
 * dos veces y sin guardar direcciones IP ni agentes completos.
 */

/** Apunta una visita como lo hace el núcleo (VisitLog::record), a la hora que se diga. */
function botsVisit(App $app, string $uri, string $agent, ?int $at = null, array $headers = []): void
{
    $request = Request::create('GET', $uri, [], [], ['user-agent' => $agent] + $headers);
    VisitLog::record($app->site(), $request, 200, false, new Clock($at !== null ? $at : $app->clock()->now()));
}

/** @return array<string,int> «nombre|clase» => visitas, de todo bot_visits */
function botsSums(App $app, string $kind = ''): array
{
    $out = [];
    foreach ($app->db()->select('SELECT name, page_class, SUM(visits) AS v FROM {bot_visits}' . ($kind !== '' ? " WHERE kind = '" . $kind . "'" : '') . ' GROUP BY name, page_class ORDER BY name, page_class') as $row) {
        $out[$row['name'] . '|' . $row['page_class']] = (int) $row['v'];
    }

    return $out;
}

/** El registro de un día, con su ruta. */
function botsLogPath(App $app, int $at): string
{
    return $app->site()->path('logs', true) . '/visits-' . gmdate('Y-m-d', $at) . '.tsv';
}

function botsShop(array $settings = []): App
{
    $app = semanticApp(['semantic', 'catalog', 'media'], $settings);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla']);
    aifecCategory($app, 'Muebles');

    return $app;
}

test('suma por robot y por clase de página con líneas del formato real del registro', function (): void {
    $app = botsShop();
    $agent = 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)';
    botsVisit($app, '/producto/silla', $agent);
    botsVisit($app, '/producto/silla', $agent);
    botsVisit($app, '/producto/silla.md', $agent);
    botsVisit($app, '/llms.txt', 'ChatGPT-User/1.0');
    botsVisit($app, '/feeds/google.xml', 'Googlebot/2.1');
    botsVisit($app, '/api/v1/products', 'Googlebot/2.1');
    botsVisit($app, '/contacto', 'bingbot/2.0');
    botsVisit($app, '/producto/silla', 'Mozilla/5.0 Firefox/130', null, ['referer' => 'https://chatgpt.com/']);
    botsVisit($app, '/producto/silla', 'Mozilla/5.0 Firefox/130');

    $done = Bots::ingest($app);
    same(['files' => 1, 'lines' => 8, 'added' => 8], $done, 'la visita de una persona normal ni se apuntó');

    same([
        'Bingbot|other' => 1,
        'ChatGPT|product' => 1,
        'ChatGPT-User|llms' => 1,
        'GPTBot|product' => 2,
        'GPTBot|text' => 1,
        'Googlebot|api' => 1,
        'Googlebot|feed' => 1,
    ], botsSums($app));
    same(['ChatGPT|product' => 1], botsSums($app, 'ref'));
});

test('cada robot cae en su familia y las visitas desde un asistente cuentan como asistente', function (): void {
    $app = botsShop();
    botsVisit($app, '/', 'GPTBot/1.1');
    botsVisit($app, '/', 'ChatGPT-User/1.0');
    botsVisit($app, '/', 'OAI-SearchBot/1.0');
    botsVisit($app, '/', 'Googlebot/2.1');
    botsVisit($app, '/', 'SemrushBot/7');
    botsVisit($app, '/', 'GoogleOther');
    botsVisit($app, '/', 'Mozilla/5.0', null, ['referer' => 'https://www.perplexity.ai/search']);
    Bots::ingest($app);

    $families = [];
    foreach ($app->db()->select('SELECT name, family, kind FROM {bot_visits} ORDER BY name') as $row) {
        $families[$row['name']] = $row['family'] . '/' . $row['kind'];
    }
    same([
        'ChatGPT-User' => 'assistant/bot', 'GPTBot' => 'training/bot', 'GoogleOther' => 'other/bot', 'Googlebot' => 'search/bot',
        'OAI-SearchBot' => 'assistant/bot', 'Perplexity' => 'assistant/ref', 'SemrushBot' => 'seo/bot',
    ], $families);
});

test('ejecutarla dos veces no cuenta doble y lo que llega después se suma una sola vez', function (): void {
    $app = botsShop();
    botsVisit($app, '/producto/silla', 'GPTBot/1.1');
    botsVisit($app, '/producto/silla', 'GPTBot/1.1');

    same(2, Bots::ingest($app)['added']);
    same(['files' => 0, 'lines' => 0, 'added' => 0], Bots::ingest($app), 'nada nuevo');
    same(['GPTBot|product' => 2], botsSums($app));

    botsVisit($app, '/producto/silla', 'GPTBot/1.1');
    same(1, Bots::ingest($app)['added']);
    same(['GPTBot|product' => 3], botsSums($app));
    same(filesize(botsLogPath($app, $app->clock()->now())), (int) $app->db()->value("SELECT pos FROM {bot_offsets} WHERE file = 'visits-2026-11-12.tsv'"), 'el desplazamiento llega al final del fichero');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {bot_visits}'), 'sigue siendo una sola fila del día');
});

test('lee el registro de ayer y el de hoy, y el de hace tres días no se abre', function (): void {
    $app = botsShop();
    $now = $app->clock()->now();
    botsVisit($app, '/producto/silla', 'GPTBot/1.1', $now - 86400);
    botsVisit($app, '/producto/silla', 'GPTBot/1.1', $now);
    botsVisit($app, '/producto/silla', 'ClaudeBot/1.0', $now - 3 * 86400);
    botsVisit($app, '/producto/silla', 'PerplexityBot/1.0', $now - 2 * 86400);

    $done = Bots::ingest($app);
    same(2, $done['files']);
    same(['GPTBot|product' => 2], botsSums($app), 'ni el de hace tres días ni el de anteayer');
    $days = array_map('strval', $app->db()->column('SELECT day FROM {bot_visits} ORDER BY day'));
    same(['2026-11-11', '2026-11-12'], $days, 'cada visita en su día');
});

test('una línea mal formada se ignora y una a medio escribir espera a la pasada siguiente', function (): void {
    $app = botsShop();
    $now = $app->clock()->now();
    $path = botsLogPath($app, $now);
    @mkdir(dirname($path), 0777, true);
    $good = $now . "\tbot\tGPTBot\t200\t0\t/producto/silla";
    file_put_contents($path, implode("\n", [
        'basura sin tabuladores',
        "abc\tbot\tGPTBot\t200\t0\t/",
        $now . "\totro\tGPTBot\t200\t0\t/",
        $now . "\tbot\t\t200\t0\t/",
        $now . "\tbot\tGPTBot\t200",
        $good,
    ]) . "\n" . $good);

    $done = Bots::ingest($app);
    same(1, $done['lines'], 'solo la línea buena y completa');
    same(['GPTBot|product' => 1], botsSums($app));

    file_put_contents($path, "\n", FILE_APPEND);
    Bots::ingest($app);
    same(['GPTBot|product' => 2], botsSums($app), 'la que estaba a medias se cuenta cuando ya está entera');
});

test('un fichero de registro que se rehace más corto se lee otra vez desde el principio', function (): void {
    $app = botsShop();
    botsVisit($app, '/producto/silla', 'GPTBot/1.1');
    botsVisit($app, '/producto/silla', 'GPTBot/1.1');
    Bots::ingest($app);

    $path = botsLogPath($app, $app->clock()->now());
    file_put_contents($path, $app->clock()->now() . "\tbot\tClaudeBot\t200\t0\t/\n");
    Bots::ingest($app);
    same(['ClaudeBot|other' => 1, 'GPTBot|product' => 2], botsSums($app));
});

test('la clase de página sale de la ruta: producto, categoría, texto, llms, feed, API u otra, también en otro idioma', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['i18n.languages' => ['es', 'en']]);
    aifecProduct($app, ['name' => 'Silla', 'slug' => 'silla', 'i18n' => ['en' => ['name' => 'Oak chair']]]);
    $category = aifecCategory($app, 'Muebles');
    $categoryPath = $app->url()->entity('category', $category, 'es');

    same('product', Bots::pageClass($app, '/producto/silla'));
    same('product', Bots::pageClass($app, '/en/producto/oak-chair'), 'con el prefijo del otro idioma');
    same('category', Bots::pageClass($app, $categoryPath));
    same('text', Bots::pageClass($app, '/producto/silla.md'));
    same('text', Bots::pageClass($app, '/index.md'));
    same('llms', Bots::pageClass($app, '/llms.txt'));
    same('llms', Bots::pageClass($app, '/llms-full.txt'));
    same('feed', Bots::pageClass($app, '/feeds/products.json'));
    same('api', Bots::pageClass($app, '/api/v1/products'));
    same('api', Bots::pageClass($app, '/mcp'));
    same('api', Bots::pageClass($app, '/.well-known/aifec.json'));
    same('other', Bots::pageClass($app, '/'));
    same('other', Bots::pageClass($app, '/contacto'));
    same('other', Bots::pageClass($app, '/producto/no-existe'));
});

test('el nombre se limpia y se corta, y no se guarda ni un agente completo ni una dirección IP', function (): void {
    $app = botsShop();
    $now = $app->clock()->now();
    $path = botsLogPath($app, $now);
    @mkdir(dirname($path), 0777, true);
    file_put_contents($path, $now . "\tbot\t" . str_repeat('x', 80) . "\t200\t0\t/\n" . $now . "\tbot\t<b>Robot</b> & Co\x07\t200\t0\t/\n");
    botsVisit($app, '/producto/silla', 'Mozilla/5.0 (compatible; GPTBot/1.1; +https://openai.com/gptbot)');
    Bots::ingest($app);

    $names = array_map('strval', $app->db()->column('SELECT name FROM {bot_visits} ORDER BY name'));
    same(['<b>Robot</b> & Co', 'GPTBot', str_repeat('x', 60)], $names, 'sin el carácter de control y con 60 caracteres como mucho');

    $columns = $app->db()->columns('bot_visits');
    foreach (['ip', 'user_agent', 'agent', 'referer'] as $forbidden) {
        check(!in_array($forbidden, $columns, true), 'la tabla no tiene la columna «' . $forbidden . '»');
    }
    $all = json_encode($app->db()->select('SELECT * FROM {bot_visits}'));
    notContains('Mozilla', (string) $all);
    notContains('openai.com', (string) $all);
});

test('las sumas de más de 400 días se olvidan y los desplazamientos de otros días también', function (): void {
    $app = botsShop();
    $now = $app->clock()->now();
    $old = gmdate('Y-m-d', $now - 401 * 86400);
    $app->db()->insert('bot_visits', ['day' => $old, 'kind' => 'bot', 'name' => 'GPTBot', 'family' => 'training', 'page_class' => 'other', 'visits' => 5, 'last_at' => $old . ' 10:00:00']);
    $app->db()->insert('bot_offsets', ['file' => 'visits-2026-01-01.tsv', 'pos' => 99, 'updated_at' => $app->clock()->sql()]);
    botsVisit($app, '/', 'GPTBot/1.1');

    Bots::ingest($app);
    same(['GPTBot|other' => 1], botsSums($app), 'solo queda lo reciente');
    same(['visits-2026-11-12.tsv'], array_map('strval', $app->db()->column('SELECT file FROM {bot_offsets}')));
});

test('la tarea periódica cuenta lo que ha leído y no falla sin registros', function (): void {
    $app = botsShop();
    same('Visits read: 0 lines from 0 files, 0 visits added', BotsJobs::visitsTask($app));

    botsVisit($app, '/', 'GPTBot/1.1');
    botsVisit($app, '/', 'ClaudeBot/1.0');
    same('Visits read: 2 lines from 1 files, 2 visits added', BotsJobs::visitsTask($app));
});
