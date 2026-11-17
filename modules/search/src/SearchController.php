<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Search;

use Aifec\Kernel\App;
use Aifec\Kernel\RateLimiter;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Catalog\Front\ListingQuery;
use Aifec\Modules\Catalog\Front\Support;

/**
 * La página de resultados (GET /buscar?q=…), las dos versiones para máquinas (GET /buscar.json?q=… y /buscar.md?q=…, con lo mismo
 * que la página), las sugerencias (GET /buscar/sugerir.json?q=…) y la ayuda de la IA que llega después
 * (GET /buscar/significado.json?q=…).
 *
 * Esta ruta se llama «search» y sustituye a la del catálogo: el listado, los filtros, el orden y la paginación siguen
 * siendo los del catálogo (plantilla «catalog::front/listing» y catalog->listing()); aquí se entiende la frase («silla negra por
 * menos de 200 €»), se enseña lo entendido en fichas que se quitan con un clic, se avisa de lo corregido y, si lo encontrado no
 * convence, se dice «no hemos encontrado nada» en vez de enseñar una rejilla de ruido.
 *
 * Ninguna de las cuatro primeras espera a la IA: usan las reglas, los sinónimos, el índice y las respuestas de la IA que ya estén
 * guardadas. Si la IA aún puede ayudar con esa frase, la página sale con un hueco (data-af-search-more) y un guion pide después
 * /buscar/significado.json, que sí la llama (solo desde la propia tienda, con límite por visitante y dentro del tope de gasto).
 * Sin JavaScript, el hueco lleva a la misma búsqueda con «significado=1», que llama a la IA dentro de la petición.
 * Ninguna se indexa ni se guarda en la caché de página (las sugerencias, 60 s en el servidor).
 */
final class SearchController
{
    /** Largo máximo de lo que se busca. */
    private const MAX_SEARCH = 80;

    /** Ayudas de la IA (significado.json o la página con «significado=1») por visitante: por minuto y por hora. */
    public const MEANING_PER_MINUTE = 10;

    public const MEANING_PER_HOUR = 60;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function search(): Search
    {
        /** @var Search $search */
        $search = $this->app->service('search');

        return $search;
    }

    private function phrase(Request $request): string
    {
        return mb_substr(trim($request->str('q')), 0, self::MAX_SEARCH, 'UTF-8');
    }

    /**
     * @param array<string,string> $params
     */
    public function results(Request $request, array $params): Response
    {
        $app = $this->app;
        $q = $this->phrase($request);
        $base = $app->url()->route('search');
        $crumbs = Support::crumbs($app, [['name' => t('Search'), 'url' => '']]);
        if ($q === '') {
            return $app->render('catalog::front/search', [
                'page' => [
                    'type' => 'search', 'title' => t('Search'), 'robots' => 'noindex, follow',
                    'canonical' => $app->url()->origin() . $base, 'breadcrumbs' => $crumbs,
                ],
                'crumbs' => $crumbs,
                'action' => $base,
                'max' => self::MAX_SEARCH,
            ]);
        }

        // «significado=1» es el enlace del hueco para quien no tiene JavaScript: esa búsqueda sí llama a la IA dentro de la
        // petición, con el mismo límite por visitante que significado.json (pasado el límite, sale como cualquier otra).
        $asked = $request->str('significado') === '1';
        $live = $asked && !AiUnderstand::hopeless($q) && $this->search()->asker()->ai()->available() && $this->meaningAllowed($request);
        $state = $this->state($request, $q, $live);
        // Solo la primera página sin filtros cuenta como una búsqueda: pasar de página o filtrar no es buscar otra vez.
        if ($this->counts($state)) {
            $sum = $this->summary($state);
            $log = $this->search()->log();
            $log->record($q, $state['lang'], $sum['total'], $sum['missed'], $sum['outcome']);
            $log->recordFilters($state['lang'], $this->search()->asker()->kinds($state['run']['plan']));
        }
        $view = $this->view($state, $asked);

        return $app->render($view['template'], $view['data']);
    }

    /**
     * GET /buscar/significado.json?q=… (con los mismos filtros, orden y página que la página): la ayuda de la IA que la página de
     * resultados no espera. Si mejora lo que se enseñó, devuelve {"better":true,"html":…,"said":…} con el contenido de <main>
     * pintado con las plantillas del tema; si no, {"better":false}. Solo desde la propia tienda (403), con límite por visitante
     * (429) y sin llamar a la IA si está apagada, sin presupuesto, si la frase no tiene arreglo o si su respuesta ya está guardada.
     *
     * @param array<string,string> $params
     */
    public function meaning(Request $request, array $params): Response
    {
        if (!self::sameSite($request)) {
            return self::meaningJson(['better' => false, 'error' => 'forbidden'], 403);
        }
        if (!$this->meaningAllowed($request)) {
            return self::meaningJson(['better' => false, 'error' => 'too_many'], 429)->header('Retry-After', '60');
        }
        $q = $this->phrase($request);
        $ask = $this->search()->asker();
        if ($q === '' || $request->str('noai') === '1' || AiUnderstand::hopeless($q) || !$ask->ai()->available()) {
            return self::meaningJson(['better' => false]);
        }
        $before = $this->state($request, $q, false);
        if (!$before['pending']) {
            // Lo que la IA podía decir ya está guardado y la página ya lo enseñó: no hay nada que pedir.
            return self::meaningJson(['better' => false]);
        }
        $after = $this->state($request, $q, true);
        if (self::fingerprint($before) === self::fingerprint($after)) {
            return self::meaningJson(['better' => false]);
        }
        // Antes de pintar: view() deja en la consulta el orden y la página que usó el listado.
        $counts = $this->counts($after);
        $view = $this->view($after, false);
        $html = self::mainOf($this->app->view()->render($view['template'], $view['data']));
        if ($html === '') {
            return self::meaningJson(['better' => false]);
        }
        if ($counts) {
            // La página ya la apuntó sin la IA: se corrige cómo acabó, sin contarla dos veces, y se suman los filtros nuevos.
            $sum = $this->summary($after);
            $log = $this->search()->log();
            $log->amend($q, $after['lang'], $sum['total'], $sum['missed'], $sum['outcome']);
            $log->recordFilters($after['lang'], array_values(array_diff($ask->kinds($after['run']['plan']), $ask->kinds($before['run']['plan']))));
        }

        return self::meaningJson(['better' => true, 'html' => $html, 'said' => $this->said($after)]);
    }

    /**
     * La búsqueda entera: lo entendido, lo encontrado y si la IA aún podría ayudar («pending»: se habría llamado y, como solo
     * valía lo guardado, no se llamó).
     *
     * @return array<string,mixed> q, lang, query (ListingQuery), allow_ai, shop, run, pending
     */
    private function state(Request $request, string $q, bool $live): array
    {
        $lang = $this->app->lang();
        $ask = $this->search()->asker();
        // «noai=1» lo pone el botón de quitar de una ficha que sacó la IA: esa frase se entiende solo con reglas.
        $allowAi = $request->str('noai') !== '1';
        // Una pregunta sobre la tienda se contesta con sus datos: no se gasta IA en ella.
        $shop = $ask->answers()->shop($q, $lang);
        $useAi = $allowAi && $shop === null;
        $before = $ask->ai()->skipped();
        $plan = $ask->plan($q, $lang, $useAi, $live);
        $query = ListingQuery::fromRequest($request);
        $query->q = $q;
        $run = $ask->run($plan, $query->toCatalog(), $useAi, $live);

        return [
            'q' => $q, 'lang' => $lang, 'query' => $query, 'allow_ai' => $allowAi, 'shop' => $shop, 'run' => $run,
            'pending' => $useAi && $ask->ai()->skipped() > $before,
        ];
    }

    /**
     * La plantilla y sus datos para una búsqueda ya hecha.
     *
     * @param array<string,mixed> $state lo de state()
     * @param bool                $asked la página se pidió con «significado=1» (no se indexa ni se sigue)
     *
     * @return array{template:string,data:array<string,mixed>}
     */
    private function view(array $state, bool $asked): array
    {
        $app = $this->app;
        $ask = $this->search()->asker();
        $q = (string) $state['q'];
        $lang = (string) $state['lang'];
        /** @var ListingQuery $query */
        $query = $state['query'];
        $run = $state['run'];
        $plan = $run['plan'];
        $found = $run['found'];
        $listing = $run['listing'];
        $shop = $state['shop'];
        $base = $app->url()->route('search');
        $crumbs = Support::crumbs($app, [['name' => t('Search'), 'url' => '']]);

        $corrected = $run['text'] !== null && $run['text']['corrected'] !== null ? ['shown' => (string) $run['text']['corrected'], 'asked' => $q] : null;
        $fixes = [];
        if ($corrected !== null) {
            $typedWords = preg_split('/\s+/u', trim($q)) ?: [];
            $shownWords = preg_split('/\s+/u', trim($corrected['shown'])) ?: [];
            if (count($typedWords) === count($shownWords)) {
                foreach ($typedWords as $i => $word) {
                    if (mb_strtolower($word, 'UTF-8') !== mb_strtolower($shownWords[$i], 'UTF-8')) {
                        $fixes[$word] = $shownWords[$i];
                    }
                }
            }
        }
        $understood = [
            'q' => $q,
            'answer' => $found && $shop === null ? $ask->answers()->count($plan, (int) $listing['total'], $lang, $fixes) : '',
            'shop' => $shop,
            'corrected' => $found ? $corrected : null,
            'chips' => $this->chips($plan, $lang),
            'assistant' => $app->has('assistant'),
            // El hueco va con lo entendido, que los listados solo pintan en la primera página.
            'more' => $state['pending'] && $query->page === 1 ? [
                'url' => $query->url($app->url()->route('search.meaning'), $query->page),
                'link' => $query->url($base, $query->page) . '&significado=1',
                'script' => $app->url()->asset('m', 'search', 'more.js'),
            ] : null,
        ];

        $page = [
            'type' => 'search',
            'title' => t('Results for {q}', ['q' => $q]),
            'description' => '',
            'canonical' => $app->url()->origin() . $query->url($base, $query->page, $query->isFiltered()),
            'robots' => $asked ? 'noindex, nofollow' : 'noindex, follow',
            'breadcrumbs' => $crumbs,
            'image' => '',
        ];

        if (!$found) {
            $relaxed = $run['relaxed'];
            if ($relaxed !== null) {
                $relaxed['url'] = $this->searchUrl((string) $relaxed['remove_query'], false);
            }

            return ['template' => 'search::front/nothing', 'data' => [
                'page' => $page,
                'crumbs' => $crumbs,
                'q' => $q,
                'action' => $base,
                'max' => self::MAX_SEARCH,
                'categories' => $this->topCategories($lang),
                'popular' => $this->popular($lang),
                'understood' => $understood,
                'relaxed' => $relaxed,
                'related' => $run['related'] !== null ? $run['related']['items'] : [],
                'related_title' => $run['related'] !== null ? $ask->answers()->related($lang) : '',
            ]];
        }

        $shown = $run['text'] !== null && $run['text']['corrected'] !== null ? (string) $run['text']['corrected'] : $q;
        $page['title'] = Support::pageTitle(t('Results for {q}', ['q' => $shown]), $query->page);
        $query->sort = (string) $listing['sort'];
        $query->page = (int) $listing['page'];
        $clean = $query->url($base, 1, true);
        $suffix = $state['allow_ai'] ? '' : '&noai=1';
        $url = static function (int $n) use ($query, $base, $suffix): string {
            return $query->url($base, $n) . $suffix;
        };

        return ['template' => 'catalog::front/listing', 'data' => [
            'page' => $page,
            'crumbs' => $crumbs,
            'kind' => 'search',
            'heading' => t('Results for {q}', ['q' => $shown]),
            'description' => $app->view()->render('search::front/understood', $understood),
            'children' => [],
            'result' => $listing,
            'facets' => $listing['facets'],
            'query' => $query,
            'base' => $base,
            'clear_url' => $clean,
            'url' => $url,
        ]];
    }

    /**
     * ¿Cuenta como una búsqueda para el registro? Solo la primera página sin filtros ni otro orden.
     *
     * @param array<string,mixed> $state
     */
    private function counts(array $state): bool
    {
        /** @var ListingQuery $query */
        $query = $state['query'];

        return !$query->isFiltered() && $query->page === 1;
    }

    /**
     * Lo que se apunta en el registro: cuántos se encontraron, si no se encontró nada que encajara y cómo acabó.
     *
     * @param array<string,mixed> $state
     *
     * @return array{total:int,missed:bool,outcome:string}
     */
    private function summary(array $state): array
    {
        $run = $state['run'];
        $total = $run['found'] ? (int) $run['listing']['total'] : 0;
        if ($run['text'] !== null && $run['plan']['filters'] === []) {
            $total = (int) $run['text']['total'];
        }

        return ['total' => $total, 'missed' => !$run['found'], 'outcome' => $this->search()->asker()->outcome($run, $state['shop'])];
    }

    /**
     * Lo que distingue un resultado de otro tal como lo ve el visitante: qué productos salen y en qué orden, cuántos, lo
     * relacionado, la propuesta de quitar un filtro y las fichas de lo entendido.
     *
     * @param array<string,mixed> $state
     */
    private static function fingerprint(array $state): string
    {
        $run = $state['run'];
        $ids = static function (array $items): array {
            return array_map(static function (array $item): int {
                return (int) $item['id'];
            }, $items);
        };
        $keys = [];
        foreach ($run['plan']['filters'] as $filter) {
            $keys[] = (string) $filter['key'];
        }

        return (string) json_encode([
            'found' => $run['found'],
            'total' => $run['found'] ? (int) $run['listing']['total'] : 0,
            'items' => $run['found'] ? $ids($run['listing']['items']) : [],
            'related' => $run['related'] !== null ? $ids($run['related']['items']) : [],
            'relaxed' => $run['relaxed'] !== null ? [(string) $run['relaxed']['key'], (int) $run['relaxed']['total']] : null,
            'filters' => $keys,
        ]);
    }

    /**
     * Lo que se anuncia cuando la IA mejora la búsqueda: «Hemos entendido: …» con las fichas, como en la página; sin fichas, la
     * respuesta directa o lo que se dice delante de lo relacionado.
     *
     * @param array<string,mixed> $state
     */
    private function said(array $state): string
    {
        $run = $state['run'];
        $answers = $this->search()->asker()->answers();
        $labels = [];
        foreach ($this->chips($run['plan'], (string) $state['lang']) as $chip) {
            $labels[] = $chip['label'];
        }
        if ($labels !== []) {
            return t('We understood: {list}', ['list' => implode(', ', $labels)]);
        }
        if ($run['found']) {
            return $answers->count($run['plan'], (int) $run['listing']['total'], (string) $state['lang']);
        }

        return $run['related'] !== null ? $answers->related((string) $state['lang']) : '';
    }

    /** Lo que hay dentro de <main id="main"> (todos los envoltorios de tema lo tienen), o '' si no está. */
    private static function mainOf(string $html): string
    {
        if (preg_match('/<main\b[^>]*\bid="main"[^>]*>/i', $html, $open, PREG_OFFSET_CAPTURE) !== 1) {
            return '';
        }
        $start = (int) $open[0][1] + strlen($open[0][0]);
        $end = strrpos($html, '</main>');
        if ($end === false || $end < $start) {
            return '';
        }

        return trim(substr($html, $start, $end - $start));
    }

    /**
     * ¿Viene de la propia tienda? Un navegador manda Sec-Fetch-Site (y entonces tiene que ser «same-origin»); el guion de la
     * página, además, X-Requested-With: fetch. Sin ninguna de las dos (un robot, un enlace suelto) no se contesta.
     */
    private static function sameSite(Request $request): bool
    {
        $site = $request->header('sec-fetch-site');
        if ($site !== null) {
            return $site === 'same-origin';
        }

        return $request->header('x-requested-with') === 'fetch';
    }

    /** Apunta una ayuda de la IA de este visitante; false si ya ha pedido demasiadas (10 por minuto, 60 por hora). */
    private function meaningAllowed(Request $request): bool
    {
        $limiter = new RateLimiter($this->app->db(), $this->app->clock());
        $key = 'search-meaning:' . $request->ip;

        return $limiter->hit($key . ':m', self::MEANING_PER_MINUTE, 60) && $limiter->hit($key . ':h', self::MEANING_PER_HOUR, 3600);
    }

    /**
     * Las respuestas de significado.json: JSON con las tres cabeceras anti-caché y sin indexar.
     *
     * @param array<string,mixed> $data
     */
    private static function meaningJson(array $data, int $status = 200): Response
    {
        return Response::json($data, $status)->header('X-Robots-Tag', 'noindex');
    }

    /**
     * GET /buscar.json?q=… : lo mismo que enseña la página, para el asistente y para máquinas.
     *
     * @param array<string,string> $params
     */
    public function machineJson(Request $request, array $params): Response
    {
        $result = $this->machine($request);

        return $this->noIndex(Response::json($result));
    }

    /**
     * GET /buscar.md?q=… : lo mismo en texto, para quien lee en vez de ejecutar.
     *
     * @param array<string,string> $params
     */
    public function machineMarkdown(Request $request, array $params): Response
    {
        // Como texto plano: hay lectores de páginas de asistentes (el de Gemini) que no abren «text/markdown».
        return $this->noIndex(Response::text($this->markdown($this->machine($request)), 200, 'text/plain'));
    }

    /**
     * Lo que dan /buscar.json y /buscar.md: como la página, con las respuestas de la IA ya guardadas y sin llamarla nunca.
     *
     * @return array<string,mixed>
     */
    private function machine(Request $request): array
    {
        $q = $this->phrase($request);
        $options = ['log' => true, 'ai' => Ask::AI_CACHED];
        $lang = $request->str('lang');
        if ($lang !== '') {
            $options['lang'] = $lang;
        }
        if ($request->int('limit', 0) > 0) {
            $options['limit'] = $request->int('limit', 0);
        }

        return $this->search()->ask($q, $options);
    }

    private function noIndex(Response $response): Response
    {
        return $response->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }

    /**
     * El resultado de ask() como texto: la respuesta, lo entendido y los productos con su dirección completa.
     *
     * @param array<string,mixed> $result
     */
    private function markdown(array $result): string
    {
        $url = $this->app->url();
        $lang = (string) $result['lang'];
        $i18n = $this->app->i18n();
        $lines = ['# ' . $i18n->t('Results for {q}', ['q' => (string) $result['query']], $lang), ''];
        if ((string) $result['answer'] !== '') {
            $lines[] = (string) $result['answer'];
            $lines[] = '';
        }
        if (is_array($result['shop']) && (string) $result['shop']['url'] !== '') {
            $lines[] = '[' . $i18n->t('Read more', [], $lang) . '](' . $url->origin() . (string) $result['shop']['url'] . ')';
            $lines[] = '';
        }
        $filters = $result['understood']['filters'];
        if ($filters !== []) {
            $lines[] = '## ' . $i18n->t('What we understood', [], $lang);
            foreach ($filters as $filter) {
                $lines[] = '- ' . (string) $filter['label'];
            }
            $lines[] = '';
        }
        $lines[] = '## ' . $i18n->t('Products', [], $lang);
        if ($result['products'] === []) {
            $lines[] = $i18n->t('No products found', [], $lang);
        }
        $lines = array_merge($lines, $this->markdownProducts($result['products']));
        if (!empty($result['related'])) {
            // Lo que sale por significado va aparte y dicho: nunca entre los productos que encajan.
            $lines[] = '';
            $lines[] = '## ' . $i18n->t('Related products', [], $lang);
            $lines[] = '';
            $lines = array_merge($lines, $this->markdownProducts($result['related']));
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<int,array<string,mixed>> $products
     *
     * @return string[]
     */
    private function markdownProducts(array $products): array
    {
        $lines = [];
        foreach ($products as $i => $product) {
            $lines[] = ($i + 1) . '. [' . (string) $product['name'] . '](' . (string) $product['url'] . ') — ' . (string) $product['price_formatted']
                . ' — ' . (string) $product['availability']['label'];
            foreach ($product['matches'] as $match) {
                $lines[] = '   - ' . (string) $match['name'] . ': ' . (string) $match['value'];
            }
        }

        return $lines;
    }

    /**
     * Las fichas de lo entendido, cada una con la dirección de la búsqueda sin ella.
     *
     * @param array<string,mixed> $plan
     *
     * @return array<int,array{key:string,label:string,url:string}>
     */
    private function chips(array $plan, string $lang): array
    {
        $chips = [];
        foreach ($plan['filters'] as $filter) {
            $chips[] = ['key' => (string) $filter['key'], 'label' => (string) $filter['label'], 'url' => $this->searchUrl((string) $filter['remove_query'], !empty($filter['ai']))];
        }

        return $chips;
    }

    private function searchUrl(string $q, bool $noAi): string
    {
        $params = $q !== '' ? ['q' => $q] : [];
        if ($noAi) {
            $params['noai'] = '1';
        }

        return $this->app->url()->route('search', $params);
    }

    /**
     * @param array<string,string> $params
     */
    public function suggestions(Request $request, array $params): Response
    {
        $q = mb_substr(trim($request->str('q')), 0, 60, 'UTF-8');
        $items = $this->search()->suggest($q, $this->app->lang());

        return Response::json(['q' => $q, 'items' => $items])->cacheable(60, ['search', 'catalog']);
    }

    /**
     * Las categorías de primer nivel, para dar una salida cuando no se encontró nada.
     *
     * @return array<int,array{name:string,url:string}>
     */
    private function topCategories(string $lang): array
    {
        $out = [];
        $tree = $this->app->service('catalog')->tree($lang);
        foreach (is_array($tree) ? $tree : [] as $node) {
            if (isset($node['name'], $node['url']) && (string) $node['name'] !== '') {
                $out[] = ['name' => (string) $node['name'], 'url' => (string) $node['url']];
            }
            if (count($out) >= 8) {
                break;
            }
        }

        return $out;
    }

    /**
     * Lo más buscado (con resultado) del último mes.
     *
     * @return array<int,array{phrase:string,url:string}>
     */
    private function popular(string $lang): array
    {
        $out = [];
        foreach ($this->search()->log()->popular($lang, '', 6) as $phrase) {
            $out[] = ['phrase' => $phrase, 'url' => $this->app->url()->route('search', ['q' => $phrase], $lang)];
        }

        return $out;
    }
}
