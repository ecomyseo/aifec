<?php
/**
 * AIFEC — pruebas de «buscar sin esperar a la IA» (P104): la página de resultados, /buscar.json, /buscar.md y las sugerencias
 * nunca llaman a la IA dentro de la petición; usan las reglas, el índice y las respuestas de la IA ya guardadas. Si la IA aún
 * puede ayudar, la página sale con un hueco que pide después /buscar/significado.json (solo desde la tienda, con límite por
 * visitante y dentro del tope de gasto); sin JavaScript, el hueco enlaza a la búsqueda con «significado=1». Lo que no tiene
 * arreglo («zzqx14495») no se le pregunta nunca. Y el resultado final es el mismo que el de la búsqueda con la IA en la petición.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiResult;
use Aifec\Kernel\App;
use Aifec\Kernel\Csp;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Search\AiUnderstand;

require_once __DIR__ . '/MeaningKit.php';

if (!defined('SEARCH_LATER_Q')) {
    /** Una frase cuyas palabras no están en el catálogo y que la IA de mentira entiende como «sillas de oficina». */
    define('SEARCH_LATER_Q', 'algo para trabajar sentado');
    /** Lo que encuentra la IA con esa frase, ordenado con sort() (por bytes: «blanca» va antes que «básica»). */
    define('SEARCH_LATER_SORTED', ['Silla de oficina blanca', 'Silla de oficina básica', 'Silla de oficina ergonómica']);
}

if (!function_exists('searchLaterShop')) {
    /**
     * La tienda de MeaningKit con una IA de mentira que entiende SEARCH_LATER_Q como la categoría «Sillas de oficina» y propone
     * «lumbar» y «ergonómica» cuando nada encaja.
     *
     * @param array<string,mixed> $settings
     * @param string[]            $more
     *
     * @return array{0:App,1:FakeAi,2:array<string,mixed>,3:TestClient}
     */
    function searchLaterShop(array $settings = [], array $more = []): array
    {
        list($app, $fake) = meaningApp($settings, $more);
        $ids = meaningShop($app);
        $fake->replies['search.understand'] = (string) json_encode(['category_id' => $ids['cat']['oficina'], 'text' => '']);
        $fake->replies['search.meaning'] = '{"terms": ["lumbar", "ergonómica"]}';

        return [$app, $fake, $ids, new TestClient($app)];
    }

    /** Llamadas de pago hechas a la IA de mentira: preguntas y vectores. */
    function searchLaterCalls(FakeAi $fake): int
    {
        return count($fake->asked) + count($fake->embedded);
    }

    /**
     * GET /buscar/significado.json como lo pide el guion del hueco.
     *
     * @param array<string,string> $headers
     */
    function searchLaterAsk(TestClient $web, string $q, string $extra = '', array $headers = []): Response
    {
        return $web->get('/buscar/significado.json?q=' . rawurlencode($q) . $extra, $headers + ['x-requested-with' => 'fetch', 'accept' => 'application/json']);
    }

    function searchLaterPage(TestClient $web, string $q, string $extra = ''): Response
    {
        return $web->get('/buscar?q=' . rawurlencode($q) . $extra);
    }

    /** La dirección que lleva el hueco (data-af-search-more), o '' si la página no lo trae. */
    function searchLaterHole(string $html): string
    {
        if (preg_match('/data-af-search-more="([^"]*)"/', $html, $m) !== 1) {
            return '';
        }

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    /** El enlace sin JavaScript del hueco (con rel="nofollow"), o ''. */
    function searchLaterLink(string $html): string
    {
        if (preg_match('/<p class="af-muted" data-af-search-more-link><a href="([^"]*)" rel="nofollow">/', $html, $m) !== 1) {
            return '';
        }

        return html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
    }

    function searchLaterRobots(string $html): string
    {
        return preg_match('/<meta name="robots" content="([^"]*)">/', $html, $m) === 1 ? $m[1] : '';
    }

    /** @return array<string,mixed> */
    function searchLaterData(Response $r): array
    {
        $data = json_decode($r->body, true);

        return is_array($data) ? $data : [];
    }

    /** Las tres cabeceras anti-caché, exactas. */
    function searchLaterNoCache(Response $r, string $what): void
    {
        same('no-store, no-cache, must-revalidate, max-age=0', isset($r->headers['Cache-Control']) ? $r->headers['Cache-Control'] : '', $what . ': Cache-Control');
        same('no-cache', isset($r->headers['Pragma']) ? $r->headers['Pragma'] : '', $what . ': Pragma');
        same('0', isset($r->headers['Expires']) ? $r->headers['Expires'] : '', $what . ': Expires');
    }

    /**
     * Una petición desde otra dirección IP (otro visitante).
     */
    function searchLaterFrom(App $app, string $ip, string $q): Response
    {
        $request = Request::create('GET', '/buscar/significado.json?q=' . rawurlencode($q), [], [], ['x-requested-with' => 'fetch', 'sec-fetch-site' => 'same-origin']);
        $request->host = 'tienda.test';
        $request->secure = true;
        $request->ip = $ip;
        if (property_exists($request, 'remoteAddr')) {
            $request->remoteAddr = $ip;
        }

        return $app->handle($request);
    }
}

if (!class_exists('SearchLaterSlowAi')) {
    /** Una IA de mentira que tarda 3 segundos en cada llamada y las cuenta. */
    final class SearchLaterSlowAi
    {
        /** @var int */
        public $calls = 0;

        public function enabled(): bool
        {
            return true;
        }

        public function limitReached(): bool
        {
            return false;
        }

        /**
         * @param array<string,mixed> $request
         */
        public function ask(string $task, array $request, string $tier = 'economy'): AiResult
        {
            ++$this->calls;
            usleep(3000000);

            return new AiResult('{"terms": []}');
        }

        /**
         * @param string[] $texts
         *
         * @return array<int,array<int,float>>
         */
        public function embed(array $texts): array
        {
            ++$this->calls;
            usleep(3000000);

            return [];
        }
    }
}

// ------------------------------------------------------------------ 1. la página no llama nunca a la IA

test('P104 · una frase nueva que la IA puede mejorar: la página sale con el hueco y sin llamar a la IA', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $r = searchLaterPage($web, SEARCH_LATER_Q);
    same(200, $r->status);
    same(0, searchLaterCalls($fake), 'ni una llamada a la IA dentro de la petición');
    same('/buscar/significado.json?q=' . rawurlencode(SEARCH_LATER_Q), searchLaterHole($r->body), 'el hueco lleva la dirección de la ayuda');
    contains('aria-live="polite"', $r->body);
    contains('Buscando por significado…', $r->body, 'con su texto discreto, traducido');
    contains('data-af-search-nothing', $r->body, 'mientras tanto, lo que dan las reglas');
});

test('P104 · el hueco lleva, para quien no tiene JavaScript, el enlace a la búsqueda con «significado=1», con rel="nofollow"', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $r = searchLaterPage($web, SEARCH_LATER_Q);
    same('/buscar?q=' . rawurlencode(SEARCH_LATER_Q) . '&significado=1', searchLaterLink($r->body));
    contains('>Buscar por significado</a>', $r->body);
    same('noindex, follow', searchLaterRobots($r->body), 'la página normal no se indexa, como siempre');
});

test('P104 · la página con el hueco carga more.js aparte y pasa la política de contenido estricta (sin guiones ni manejadores escritos)', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $r = searchLaterPage($web, SEARCH_LATER_Q);
    check(preg_match('#<script src="[^"]*/assets/m/search/[^"]*/more\.js" defer></script>#', $r->body) === 1, 'more.js con defer');
    $csp = isset($r->headers['Content-Security-Policy']) ? (string) $r->headers['Content-Security-Policy'] : '';
    $scripts = preg_match("/script-src ([^;]*)/", $csp, $directive) === 1 ? $directive[1] : '';
    contains("'self'", $scripts);
    notContains('unsafe-inline', $scripts, 'guiones solo de la tienda');
    preg_match_all('/<script\b([^>]*)>(.*?)<\/script>/is', $r->body, $all, PREG_SET_ORDER);
    foreach ($all as $m) {
        if (stripos($m[1], 'src=') !== false || trim($m[2]) === '' || preg_match('/type="application\/(ld\+)?json"/i', $m[1]) === 1) {
            continue;
        }
        contains(Csp::hash($m[2]), $csp, 'un guion en línea lleva su huella');
    }
    notContains(' onclick="', $r->body);
    notContains(' onload="', $r->body);
    $plain = searchLaterPage($web, 'silla de oficina');
    notContains('more.js', $plain->body, 'sin hueco, sin guion: no pesa en las demás búsquedas');
});

test('P104 · con la IA tardando 3 segundos la página responde sin esperarla: la simulada no llega a llamarse', function (): void {
    $app = searchApp(['search.vectors' => true]);
    meaningShop($app);
    $slow = new SearchLaterSlowAi();
    $app->bind('ai', static function () use ($slow): SearchLaterSlowAi {
        return $slow;
    });
    $web = new TestClient($app);
    $web->get('/buscar?q=silla');
    $started = microtime(true);
    $r = searchLaterPage($web, SEARCH_LATER_Q);
    $page = microtime(true) - $started;
    same(200, $r->status);
    same(0, $slow->calls, 'la IA lenta no se ha llamado');
    check($page < 1.0, 'la página tardó ' . round($page, 3) . ' s');
    check(searchLaterHole($r->body) !== '', 'y ofrece la ayuda para después');
    $started = microtime(true);
    $web->get('/buscar.json?q=' . rawurlencode(SEARCH_LATER_Q));
    $web->get('/buscar.md?q=' . rawurlencode(SEARCH_LATER_Q));
    $web->get('/buscar/sugerir.json?q=' . rawurlencode('algo para'));
    $machines = microtime(true) - $started;
    same(0, $slow->calls, 'ni /buscar.json, ni /buscar.md, ni las sugerencias');
    check($machines < 2.0, 'las tres tardaron ' . round($machines, 3) . ' s');
});

test('P104 · /buscar.json, /buscar.md y las sugerencias no llaman a la IA, ni con una frase que la pediría ni con vectores', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop(['search.vectors' => true]);
    meaningVectors($app, $fake, $ids);
    $fake->embedded = [];
    $json = searchLaterData($web->get('/buscar.json?q=' . rawurlencode(SEARCH_LATER_Q)));
    same([], $json['products'], 'lo que dan las reglas: nada');
    same(false, $json['understood']['ai']);
    same(200, $web->get('/buscar.md?q=' . rawurlencode(MEANING_Q))->status);
    same(200, $web->get('/buscar.json?q=' . rawurlencode(MEANING_Q))->status);
    same(200, $web->get('/buscar/sugerir.json?q=' . rawurlencode('algo para la'))->status);
    same(200, $web->get('/buscar/sugerencias.json?q=sil')->status);
    same(0, searchLaterCalls($fake), 'cero llamadas y cero vectores pedidos');
});

test('P104 · /buscar.json y /buscar.md usan lo que la IA ya dejó guardado, sin volver a llamarla', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    same(true, searchLaterData(searchLaterAsk($web, SEARCH_LATER_Q))['better']);
    $calls = searchLaterCalls($fake);
    $json = searchLaterData($web->get('/buscar.json?q=' . rawurlencode(SEARCH_LATER_Q)));
    same(SEARCH_LATER_SORTED, meaningNames($json['products']));
    same(true, $json['understood']['ai'], 'con lo que entendió la IA');
    contains('Silla de oficina blanca', $web->get('/buscar.md?q=' . rawurlencode(SEARCH_LATER_Q))->body);
    same($calls, searchLaterCalls($fake), 'sin llamadas nuevas');
});

// ------------------------------------------------------------------ 2. la ayuda llega después

test('P104 · significado.json mejora: devuelve el contenido de <main> pintado con las plantillas y anuncia lo entendido', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    searchLaterPage($web, SEARCH_LATER_Q);
    $r = searchLaterAsk($web, SEARCH_LATER_Q);
    same(200, $r->status);
    $data = searchLaterData($r);
    same(true, $data['better']);
    $cards = askCardNames($data['html']);
    sort($cards);
    same(SEARCH_LATER_SORTED, $cards, 'las tarjetas de la plantilla de siempre');
    same('Hemos entendido: Categoría: Sillas de oficina', $data['said']);
    notContains('<main', $data['html'], 'solo lo de dentro de <main>');
    contains('<h1 class="af-title">Resultados para algo para trabajar sentado</h1>', $data['html']);
    contains('3 productos', $data['html'], 'con el contador');
    same(1, $fake->askedTask('search.understand'), 'una sola llamada');
});

test('P104 · la mejora trae las fichas de lo entendido, que se quitan como en la página (la de la IA, con noai=1)', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $html = searchLaterData(searchLaterAsk($web, SEARCH_LATER_Q))['html'];
    $remove = askChipUrl($html, 'category');
    contains('noai=1', $remove);
    notContains('data-af-search-more', $html, 'y ya no lleva hueco: la ayuda está dentro');
});

test('P104 · con la respuesta ya guardada la página sale mejorada, sin hueco y sin llamada', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    searchLaterPage($web, SEARCH_LATER_Q);
    searchLaterAsk($web, SEARCH_LATER_Q);
    $calls = searchLaterCalls($fake);
    $again = searchLaterPage($web, SEARCH_LATER_Q);
    same(200, $again->status);
    same('', searchLaterHole($again->body), 'sin hueco');
    notContains('more.js', $again->body);
    $cards = askCardNames($again->body);
    sort($cards);
    same(SEARCH_LATER_SORTED, $cards, 'ya mejorada');
    same($calls, searchLaterCalls($fake), 'sin llamar');
});

test('P104 · significado.json no mejora: contesta {"better":false} y la página no vuelve a ofrecer la ayuda', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $fake->replies['search.understand'] = '{"text": "trabajar"}';
    $fake->replies['search.meaning'] = '{"terms": []}';
    $r = searchLaterAsk($web, SEARCH_LATER_Q);
    same(200, $r->status);
    same('{"better":false}', $r->body);
    check(searchLaterCalls($fake) >= 1, 'se le preguntó');
    $calls = searchLaterCalls($fake);
    same('', searchLaterHole(searchLaterPage($web, SEARCH_LATER_Q)->body), 'lo que contestó ya está guardado: no hay nada más que pedir');
    same('{"better":false}', searchLaterAsk($web, SEARCH_LATER_Q)->body);
    same($calls, searchLaterCalls($fake), 'y no se vuelve a llamar');
});

test('P104 · por significado (términos): significado.json trae los relacionados y lo dice como en la página', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $fake->replies['search.understand'] = '{"text": "espalda"}';
    check(searchLaterHole(searchLaterPage($web, MEANING_Q)->body) !== '', 'la página ofrece la ayuda');
    $data = searchLaterData(searchLaterAsk($web, MEANING_Q));
    same(true, $data['better']);
    contains('data-af-search-related', $data['html']);
    $cards = askCardNames($data['html']);
    sort($cards);
    same(['Cojín lumbar', 'Silla de oficina ergonómica'], $cards);
    same('No tenemos nada con esas palabras. Esto puede servirte:', $data['said']);
});

test('P104 · con vectores: la página no pide el vector de la consulta; significado.json lo pide una vez y lo deja guardado', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop(['search.vectors' => true]);
    meaningVectors($app, $fake, $ids);
    $fake->replies['search.understand'] = '{"text": "espalda"}';
    $fake->embedded = [];
    check(searchLaterHole(searchLaterPage($web, MEANING_Q)->body) !== '', 'hueco');
    same([], $fake->embedded, 'la página no pide vectores');
    $data = searchLaterData(searchLaterAsk($web, MEANING_Q));
    same(true, $data['better']);
    same([[MEANING_Q]], $fake->embedded, 'un vector: el de la consulta');
    same(3, count(searchService($app)->queryVector(MEANING_Q, 'es')), 'guardado');
    $again = searchLaterPage($web, MEANING_Q);
    same('', searchLaterHole($again->body));
    same(['Cojín lumbar', 'Silla de oficina ergonómica'], askCardNames($again->body), 'por orden de parecido');
    same(1, count($fake->embedded));
});

test('P104 · el resultado de «frase nueva + ayuda» es idéntico al de la búsqueda con la IA dentro de la petición: mismos productos y mismo orden', function (): void {
    foreach ([[SEARCH_LATER_Q, null], [MEANING_Q, '{"text": "espalda"}']] as $case) {
        [$q, $understand] = $case;
        // Hoy: la IA dentro de la petición (Search::ask como siempre, y la página con «significado=1»).
        [$appA, $fakeA, $idsA, $webA] = searchLaterShop();
        [$appB, $fakeB, $idsB, $webB] = searchLaterShop();
        [$appC, $fakeC, $idsC, $webC] = searchLaterShop();
        if ($understand !== null) {
            $fakeA->replies['search.understand'] = $understand;
            $fakeB->replies['search.understand'] = $understand;
            $fakeC->replies['search.understand'] = $understand;
        }
        $live = askCardNames(searchLaterPage($webA, $q, '&significado=1')->body);
        $result = searchService($appC)->ask($q);
        $askNames = askNames($result) !== [] ? askNames($result) : array_map(static function (array $p): string {
            return (string) $p['name'];
        }, $result['related']);
        // Ahora: la página sin la IA, la ayuda después y la página siguiente.
        searchLaterPage($webB, $q);
        $later = askCardNames(searchLaterData(searchLaterAsk($webB, $q))['html']);
        $next = askCardNames(searchLaterPage($webB, $q)->body);
        check($live !== [], $q . ': hay productos');
        same($live, $later, $q . ': lo que trae la ayuda');
        same($live, $next, $q . ': la página siguiente');
        same($askNames, $live, $q . ': y lo mismo que da Search::ask() con la IA en la petición');
    }
});

// ------------------------------------------------------------------ 3. el punto de la IA está protegido

test('P104 · significado.json sin la cabecera del mismo sitio (un robot, un enlace suelto) contesta 403 y no llama a la IA', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $web->headers = ['user-agent' => 'RobotDePrueba/1.0'];
    $url = '/buscar/significado.json?q=' . rawurlencode(SEARCH_LATER_Q);
    $r = $web->get($url);
    same(403, $r->status);
    same(false, searchLaterData($r)['better']);
    searchLaterNoCache($r, '403');
    same(403, $web->get($url, ['sec-fetch-site' => 'cross-site', 'x-requested-with' => 'fetch'])->status, 'desde otra web, aunque diga fetch');
    same(403, $web->get($url, ['sec-fetch-site' => 'same-site'])->status, 'desde otro subdominio');
    same(403, $web->get($url, ['x-requested-with' => 'XMLHttpRequest'])->status);
    same(0, searchLaterCalls($fake), 'ni una llamada');
});

test('P104 · significado.json desde la tienda: Sec-Fetch-Site same-origin, o X-Requested-With: fetch en un navegador que no manda la otra', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $url = '/buscar/significado.json?q=' . rawurlencode(SEARCH_LATER_Q);
    same(200, $web->get($url)->status, 'el navegador de prueba manda Sec-Fetch-Site: same-origin');
    $web->headers = ['user-agent' => 'NavegadorViejo/1.0'];
    same(200, $web->get($url, ['x-requested-with' => 'fetch'])->status);
});

test('P104 · el límite por visitante: 10 por minuto (la undécima, 429 con Retry-After) y pasado el minuto vuelve a contestar', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    for ($i = 1; $i <= 10; ++$i) {
        same(200, searchLaterAsk($web, SEARCH_LATER_Q)->status, 'petición ' . $i);
    }
    $r = searchLaterAsk($web, SEARCH_LATER_Q);
    same(429, $r->status);
    same('60', isset($r->headers['Retry-After']) ? $r->headers['Retry-After'] : '');
    same(false, searchLaterData($r)['better']);
    searchLaterNoCache($r, '429');
    same(429, searchLaterAsk($web, 'otra frase cualquiera')->status, 'cuenta el visitante, no la frase');
    $app->clock()->advance(61);
    same(200, searchLaterAsk($web, SEARCH_LATER_Q)->status);
    same(1, $fake->askedTask('search.understand'), 'solo la primera llamó a la IA');
});

test('P104 · el límite por visitante: 60 por hora, aunque se respeten los 10 por minuto', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    for ($i = 1; $i <= 60; ++$i) {
        same(200, searchLaterAsk($web, 'zzqx' . $i)->status, 'petición ' . $i);
        if ($i % 10 === 0) {
            $app->clock()->advance(61);
        }
    }
    same(429, searchLaterAsk($web, SEARCH_LATER_Q)->status, 'la 61 de la hora');
    $app->clock()->advance(3600);
    same(200, searchLaterAsk($web, SEARCH_LATER_Q)->status, 'pasada la hora');
});

test('P104 · el límite es de cada visitante: otra dirección sigue pudiendo pedir la ayuda', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    for ($i = 1; $i <= 11; ++$i) {
        searchLaterFrom($app, '127.0.0.1', 'zzqx' . $i);
    }
    same(429, searchLaterFrom($app, '127.0.0.1', SEARCH_LATER_Q)->status);
    $other = searchLaterFrom($app, '127.0.0.2', SEARCH_LATER_Q);
    same(200, $other->status);
    same(true, searchLaterData($other)['better']);
});

test('P104 · con el tope de gasto alcanzado, con la IA apagada o sin el módulo: better:false, ninguna llamada y la página sin hueco', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $fake->limit = true;
    same('', searchLaterHole(searchLaterPage($web, SEARCH_LATER_Q)->body), 'tope alcanzado: sin hueco');
    $r = searchLaterAsk($web, SEARCH_LATER_Q);
    same('{"better":false}', $r->body);
    searchLaterNoCache($r, 'tope');
    $fake->limit = false;
    $fake->on = false;
    same('', searchLaterHole(searchLaterPage($web, SEARCH_LATER_Q)->body), 'apagada: sin hueco');
    same('{"better":false}', searchLaterAsk($web, SEARCH_LATER_Q)->body);
    same(0, searchLaterCalls($fake), 'ni una llamada');
    $plain = searchApp();
    meaningShop($plain);
    $bare = new TestClient($plain);
    same('', searchLaterHole(searchLaterPage($bare, SEARCH_LATER_Q)->body), 'sin el módulo de IA: sin hueco');
    same('{"better":false}', searchLaterAsk($bare, SEARCH_LATER_Q)->body);
});

test('P104 · si el tope de gasto se alcanza entre la página y la ayuda, significado.json no llama', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    check(searchLaterHole(searchLaterPage($web, SEARCH_LATER_Q)->body) !== '', 'la página ofreció la ayuda');
    $fake->limit = true;
    same('{"better":false}', searchLaterAsk($web, SEARCH_LATER_Q)->body);
    same('{"better":false}', searchLaterAsk($web, MEANING_Q)->body);
    same(0, searchLaterCalls($fake));
});

test('P104 · con «search.ai_understand» apagado no hay hueco ni ayuda', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop(['search.ai_understand' => false]);
    same('', searchLaterHole(searchLaterPage($web, SEARCH_LATER_Q)->body));
    same('{"better":false}', searchLaterAsk($web, SEARCH_LATER_Q)->body);
    same(0, searchLaterCalls($fake));
});

test('P104 · las respuestas de significado.json llevan las tres cabeceras anti-caché y no se indexan', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $better = searchLaterAsk($web, SEARCH_LATER_Q);
    searchLaterNoCache($better, 'mejora');
    same('noindex', isset($better->headers['X-Robots-Tag']) ? $better->headers['X-Robots-Tag'] : '');
    searchLaterNoCache(searchLaterAsk($web, SEARCH_LATER_Q), 'sin mejora');
    searchLaterNoCache(searchLaterAsk($web, ''), 'sin frase');
});

test('P104 · con la IA caída significado.json contesta better:false sin error; no se guarda el fallo y luego se puede volver a pedir', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $fake->fail = true;
    $r = searchLaterAsk($web, SEARCH_LATER_Q);
    same(200, $r->status);
    same('{"better":false}', $r->body);
    same('', searchLaterHole(searchLaterPage($web, SEARCH_LATER_Q)->body), 'en los 30 segundos siguientes no se ofrece');
    $app->clock()->advance(31);
    $fake->fail = false;
    check(searchLaterHole(searchLaterPage($web, SEARCH_LATER_Q)->body) !== '', 'pasado ese rato, sí: el fallo no se guardó');
    same(true, searchLaterData(searchLaterAsk($web, SEARCH_LATER_Q))['better']);
});

test('P104 · una frase vacía en significado.json: better:false, sin llamada', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    same('{"better":false}', searchLaterAsk($web, '')->body);
    same('{"better":false}', searchLaterAsk($web, '   ')->body);
    same(0, searchLaterCalls($fake));
});

// ------------------------------------------------------------------ «significado=1»: la búsqueda con la IA en la petición

test('P104 · «significado=1» hace la búsqueda con la IA dentro de la petición, sin hueco, y no se indexa ni se sigue', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $r = searchLaterPage($web, SEARCH_LATER_Q, '&significado=1');
    same(200, $r->status);
    same(1, $fake->askedTask('search.understand'), 'la IA dentro de la petición, como antes');
    same('noindex, nofollow', searchLaterRobots($r->body));
    same('', searchLaterHole($r->body));
    $cards = askCardNames($r->body);
    sort($cards);
    same(SEARCH_LATER_SORTED, $cards);
});

test('P104 · «significado=1» respeta el límite por visitante: pasado, sale como cualquier otra búsqueda y sin llamar', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    for ($i = 1; $i <= 10; ++$i) {
        searchLaterAsk($web, 'zzqx' . $i);
    }
    $r = searchLaterPage($web, SEARCH_LATER_Q, '&significado=1');
    same(200, $r->status);
    same(0, searchLaterCalls($fake), 'sin llamada');
    contains('data-af-search-nothing', $r->body);
});

// ------------------------------------------------------------------ 4. lo que no tiene arreglo

test('P104 · «zzqx14495» no tiene arreglo: ni hueco, ni llamada (página, significado.json, «significado=1», ask())', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop(['search.vectors' => true]);
    meaningVectors($app, $fake, $ids);
    $fake->embedded = [];
    $page = searchLaterPage($web, 'zzqx14495');
    same(200, $page->status);
    same('', searchLaterHole($page->body), 'sin hueco');
    notContains('Buscando por significado', $page->body);
    same('{"better":false}', searchLaterAsk($web, 'zzqx14495')->body);
    same(200, searchLaterPage($web, 'zzqx14495', '&significado=1')->status);
    searchService($app)->ask('zzqx14495');
    searchService($app)->ask('14495');
    same(0, searchLaterCalls($fake), 'ni preguntas ni vectores');
});

test('P104 · el criterio de «sin arreglo»: sin letras, menos de 3 letras o ninguna palabra con vocal', function (): void {
    foreach (['zzqx14495', '14495', '12345', '€€€', '-- ++', 'ab', 'x1', 'tv', 'zzqx', 'xkcd zzqx', 'mp3', '', '   ', 'ZZQX'] as $q) {
        check(AiUnderstand::hopeless($q), '«' . $q . '» no tiene arreglo');
    }
    foreach (['sandalia plana verano', 'nike', 'lynx', 'gym', 'tacón', 'ÁRBOL', 'mp3 usb', 'zzqx botas', 'obi', 'niño', 'обувь', '鞋'] as $q) {
        check(!AiUnderstand::hopeless($q), '«' . $q . '» se le puede preguntar');
    }
});

test('P104 · «sandalia plana verano» y «nike» sin resultados sí sacan el hueco (y la página sigue sin llamar)', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    foreach (['sandalia plana verano', 'nike'] as $q) {
        $r = searchLaterPage($web, $q);
        same('/buscar/significado.json?q=' . rawurlencode($q), searchLaterHole($r->body), $q);
    }
    same(0, searchLaterCalls($fake));
});

// ------------------------------------------------------------------ cuándo no sale el hueco

test('P104 · lo que ya encuentran las reglas no saca el hueco: «silla de oficina» y «flexo»', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    foreach (['silla de oficina', 'flexo', 'cojín lumbar'] as $q) {
        $r = searchLaterPage($web, $q);
        same('', searchLaterHole($r->body), $q);
        same('{"better":false}', searchLaterAsk($web, $q)->body, $q . ': nada que pedir');
    }
    same(0, searchLaterCalls($fake));
});

test('P104 · «noai=1» y las preguntas sobre la tienda no sacan el hueco', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop([], ['shipping']);
    meaningCarriers($app);
    same('', searchLaterHole(searchLaterPage($web, SEARCH_LATER_Q, '&noai=1')->body), 'noai=1');
    same('{"better":false}', searchLaterAsk($web, SEARCH_LATER_Q, '&noai=1')->body);
    same('', searchLaterHole(searchLaterPage($web, '¿cuánto tarda el envío?')->body), 'pregunta de la tienda');
    same(0, searchLaterCalls($fake));
});

test('P104 · el hueco conserva el orden y los filtros: significado.json y el enlace llevan los mismos parámetros', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $r = searchLaterPage($web, SEARCH_LATER_Q, '&sort=price_asc');
    same('/buscar/significado.json?q=' . rawurlencode(SEARCH_LATER_Q) . '&sort=price_asc', searchLaterHole($r->body));
    same('/buscar?q=' . rawurlencode(SEARCH_LATER_Q) . '&sort=price_asc&significado=1', searchLaterLink($r->body));
    $data = searchLaterData(searchLaterAsk($web, SEARCH_LATER_Q, '&sort=price_asc'));
    same(true, $data['better']);
    same(['Silla de oficina básica', 'Silla de oficina blanca', 'Silla de oficina ergonómica'], askCardNames($data['html']), 'por precio, de menos a más');
});

// ------------------------------------------------------------------ 5. el asistente

test('P104 · Search::ask() con «ai» => «cached» (lo que debe pedir el asistente) no llama a la IA y usa lo guardado; con «ai» => true sí la llama', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $search = searchService($app);
    $cached = $search->ask(SEARCH_LATER_Q, ['ai' => 'cached']);
    same([], $cached['products']);
    same(0, searchLaterCalls($fake), 'cached: ni una llamada');
    // Lo que pasa hoy el asistente en su segunda vuelta (ShopTools::askStages): «ai» => true, y eso es una llamada más.
    $live = $search->ask(SEARCH_LATER_Q, ['ai' => true]);
    same(1, $fake->askedTask('search.understand'), 'ai => true llama a la IA del buscador');
    same(3, count($live['products']));
    catalogNewRequest($app);
    $again = $search->ask(SEARCH_LATER_Q, ['ai' => 'cached']);
    same(meaningNames($live['products']), meaningNames($again['products']), 'cached: con la respuesta guardada, lo mismo');
    same(true, $again['understood']['ai']);
    same(1, $fake->askedTask('search.understand'), 'y sin llamar');
    $search->ask(MEANING_Q, ['ai' => 'cached']);
    same(1, searchLaterCalls($fake), 'tampoco pide términos ni vectores');
});

// ------------------------------------------------------------------ el registro

test('P104 · el registro: la página apunta la búsqueda; la ayuda corrige cómo acabó sin contarla dos veces y suma los filtros de la IA', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    searchLaterPage($web, SEARCH_LATER_Q);
    $row = $app->db()->row('SELECT hits, found, missed, outcome FROM {search_log}');
    same(1, (int) $row['hits']);
    same('none', (string) $row['outcome']);
    searchLaterAsk($web, SEARCH_LATER_Q);
    $row = $app->db()->row('SELECT hits, found, missed, outcome FROM {search_log}');
    same(1, (int) $row['hits'], 'una sola búsqueda');
    same('exact', (string) $row['outcome']);
    same(3, (int) $row['found']);
    same(0, (int) $row['missed']);
    $stats = searchService($app)->log()->filterStats(7);
    same(1, $stats['ai']);
    same(1, $stats['category']);
    searchService($app)->log()->amend('frase que nadie buscó', 'es', 5, false, 'exact');
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {search_log}'), 'corregir no da de alta lo que no estaba');
});

test('P104 · corregir el registro no guarda lo que parece un dato personal', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $log = searchService($app)->log();
    $log->amend('ana@cliente.test', 'es', 1, false, 'exact');
    $log->amend('', 'es', 1, false, 'exact');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {search_log}'));
});

test('P104 · cinco palabras o más que las reglas no entienden del todo: aunque haya resultados, la IA puede afinar y sale el hueco', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $fake->replies['search.understand'] = (string) json_encode(['price_max_eur' => 130, 'text' => '']);
    $q = 'busco una silla negra comoda para trabajar en casa';
    $page = searchLaterPage($web, $q);
    check(askCardNames($page->body) !== [], 'las reglas ya encuentran algo');
    check(searchLaterHole($page->body) !== '', 'y aun así la IA puede afinar');
    same(0, searchLaterCalls($fake));
    $data = searchLaterData(searchLaterAsk($web, $q));
    same(true, $data['better']);
    contains('Precio: menos de 130', $data['said']);
    notContains('Silla de oficina ergonómica', implode(',', askCardNames($data['html'])), 'la de 159,99 € se queda fuera');
});

test('P104 · la frase va escapada en el hueco y en el enlace: comillas, «<», «&» y tildes', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $q = 'sofá "rojo" & <b>mesa</b>';
    $r = searchLaterPage($web, $q);
    same(200, $r->status);
    notContains('<b>mesa</b>', $r->body);
    same('/buscar/significado.json?q=' . rawurlencode($q), searchLaterHole($r->body));
    same('/buscar?q=' . rawurlencode($q) . '&significado=1', searchLaterLink($r->body));
});

test('P104 · la dirección de more.js que pone la página es la de los recursos del módulo, y el fichero existe', function (): void {
    [$app, $fake, $ids, $web] = searchLaterShop();
    $r = searchLaterPage($web, SEARCH_LATER_Q);
    check(preg_match('#<script src="([^"]*more\.js)" defer></script>#', $r->body, $m) === 1, 'la página lo carga');
    same($app->url()->asset('m', 'search', 'more.js'), html_entity_decode($m[1], ENT_QUOTES, 'UTF-8'));
    check(preg_match('#/assets/m/search/[^/]+/more\.js$#', $m[1]) === 1, $m[1]);
    check(is_file(__DIR__ . '/../assets/more.js'), 'modules/search/assets/more.js');
});

// ------------------------------------------------------------------ el guion y los textos

test('P104 · more.js: pequeño, sin textos (ni letras con tilde) en el código, sin montar tarjetas y pidiendo sin caché desde la tienda', function (): void {
    $file = __DIR__ . '/../assets/more.js';
    $js = (string) file_get_contents($file);
    check(strlen($js) <= 2500, 'more.js pesa ' . strlen($js) . ' bytes (presupuesto: 2 500)');
    $code = (string) preg_replace('#/\*.*?\*/#s', '', $js);
    $code = (string) preg_replace('#^\s*//.*$#m', '', $code);
    check(preg_match('/[^\x00-\x7F]/', $code) !== 1, 'el código no lleva ningún carácter fuera de ASCII: los textos vienen de PHP');
    contains("'[data-af-search-more]'", $code);
    contains("'X-Requested-With': 'fetch'", $code);
    contains("cache: 'no-store'", $code);
    contains("getElementById('main')", $code);
    notContains('product-card', $code, 'no monta tarjetas: las pinta el servidor');
    notContains('onclick', $code);
    notContains('eval(', $code);
    check(preg_match("/textContent = '[A-Za-z]/", $code) !== 1, 'ningún texto escrito en el guion');
});

test('P104 · los textos nuevos están traducidos', function (): void {
    $es = require __DIR__ . '/../lang/es.php';
    same('Buscando por significado…', $es['Searching by meaning…']);
    same('Buscar por significado', $es['Search by meaning']);
    same('Hemos entendido: {list}', $es['We understood: {list}']);
});
