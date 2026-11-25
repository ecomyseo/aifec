<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Lo que piden las máquinas por una dirección fija: /llms.txt, /llms-full.txt, los feeds de producto y /.well-known/aifec.json.
 */
final class SemanticController
{
    /** Segundos que se guardan en la caché de página el resumen para modelos y el documento de puertas. */
    private const TTL = 1800;

    /** Tamaño de /llms-full.txt si el ajuste no dice otra cosa (KB). */
    private const FULL_KB = 500;

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    private function semantic(): Semantic
    {
        /** @var Semantic $semantic */
        $semantic = $this->app->service('semantic');

        return $semantic;
    }

    /**
     * @param array<string,string> $params
     */
    public function llms(Request $request, array $params): Response
    {
        return Response::text(Llms::build($this->semantic()), 200, 'text/plain')->cacheable(self::TTL, ['semantic', 'catalog', 'cms', 'settings']);
    }

    /**
     * El mapa de las versiones en texto (/sitemap-md.xml).
     *
     * @param array<string,string> $params
     */
    public function mdSitemap(Request $request, array $params): Response
    {
        return Response::text(MdSitemap::xml($this->app), 200, 'application/xml')->cacheable(self::TTL, ['semantic', 'catalog', 'cms', 'settings']);
    }

    /**
     * @param array<string,string> $params
     */
    public function llmsFull(Request $request, array $params): Response
    {
        $kb = $this->app->settings()->int('semantic.llms_full_kb', self::FULL_KB);
        $max = ($kb > 0 ? $kb : self::FULL_KB) * 1024;
        // Por páginas de unos 60 KB: la primera, sin pedir nada; «?page=N», otra; «?all=1», todo de una vez (hasta el tope de los ajustes).
        $all = $request->get('all');
        if (!(is_scalar($all) && in_array((string) $all, ['1', 'true', 'yes'], true))) {
            $param = $request->get('page');
            $page = is_scalar($param) && ctype_digit((string) $param) ? (int) $param : 0;
            if ($param !== null && $page < 1) {
                throw HttpError::notFound();
            }
            $app = $this->app;
            $result = Llms::fullPage($this->semantic(), $max, Llms::FULL_PAGE_BYTES, max(1, $page), static function (int $n) use ($app): string {
                return $app->url()->abs('/llms-full.txt', $n > 1 ? ['page' => $n] : [], '');
            });
            if ($result === null) {
                throw HttpError::notFound();
            }
            $response = Response::text($result['text'], 200, 'text/plain')->cacheable(self::TTL, ['semantic', 'catalog', 'cms', 'settings']);
            if ($result['pages'] > 1) {
                $links = [];
                $url = static function (int $n) use ($app): string {
                    return $app->url()->abs('/llms-full.txt', $n > 1 ? ['page' => $n] : [], '');
                };
                if ($result['page'] < $result['pages']) {
                    $links[] = '<' . $url($result['page'] + 1) . '>; rel="next"';
                }
                if ($result['page'] > 1) {
                    $links[] = '<' . $url($result['page'] - 1) . '>; rel="prev"';
                }
                $links[] = '<' . $url(1) . '>; rel="first"';
                $links[] = '<' . $url($result['pages']) . '>; rel="last"';
                $response->header('Link', implode(', ', $links))->header('X-Total-Pages', (string) $result['pages']);
            }

            return $response;
        }

        return Response::text(Llms::buildFull($this->semantic(), $max), 200, 'text/plain')->cacheable(self::TTL, ['semantic', 'catalog', 'cms', 'settings']);
    }

    /**
     * Un feed ya generado, tal cual está en el fichero y con su ETag: quien ya lo tiene recibe un 304. Un feed apagado no existe (404).
     * Si está encendido y todavía no se ha generado, la primera petición lo genera (hasta 500 productos) o, con más, contesta 503 con
     * Retry-After y deja el trabajo encolado; con otra generación en marcha también contesta 503 (Feeds::ensure).
     *
     * @param array<string,string> $params file
     */
    public function feed(Request $request, array $params): Response
    {
        $file = isset($params['file']) ? (string) $params['file'] : '';
        $key = (string) array_search($file, Feeds::FILES, true);
        if ($key === '' || !Feeds::enabled($this->app, $key)) {
            throw HttpError::notFound();
        }
        $path = Feeds::path($this->app, $key);
        if (!is_file($path)) {
            // Tienda recién instalada: la tarea periódica aún no ha corrido. La primera petición lo genera si la tienda es pequeña.
            try {
                $state = Feeds::ensure($this->semantic(), $key);
            } catch (\Throwable $e) {
                $this->app->log()->exception($e, 'feeds');
                $state = 'busy';
            }
            if ($state === 'busy' || $state === 'queued') {
                return Response::text('The feed is being built. Try again in a moment.', 503, 'text/plain')
                    ->header('Retry-After', $state === 'queued' ? '300' : '60')
                    ->header('Cache-Control', 'no-store');
            }
            if (!is_file($path)) {
                throw HttpError::notFound();
            }
        }
        // Un feed JSON grande se reparte en páginas (FeedParts): sin pedir nada, la primera; «?page=N», la que sea; «?all=1», el feed entero.
        $paged = $this->pagedFeed($request, $key, $path);
        if ($paged !== null) {
            return $paged;
        }
        $size = (int) filesize($path);
        $time = (int) filemtime($path);
        $etag = '"' . md5($key . '|' . $size . '|' . $time) . '"';
        $headers = [
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $time) . ' GMT',
            'Cache-Control' => 'public, max-age=300',
        ];
        $sent = $request->header('if-none-match');
        if ($sent !== null && in_array($etag, array_map('trim', explode(',', $sent)), true)) {
            return new Response('', 304, $headers);
        }
        $mime = $key === 'google' ? 'application/xml; charset=UTF-8' : 'application/json; charset=UTF-8';
        $response = Response::file($path, $file, $mime, true);
        foreach ($headers as $name => $value) {
            $response->headers[$name] = $value;
        }

        return $response;
    }

    /**
     * La página de un feed troceado que toca servir, o null si se sirve el fichero entero (feed pequeño, «?all=1» o sin trocear todavía).
     */
    private function pagedFeed(Request $request, string $key, string $path): ?Response
    {
        if (!isset(Feeds::PAGED[$key])) {
            return null;
        }
        $all = $request->get('all');
        if (is_scalar($all) && in_array((string) $all, ['1', 'true', 'yes'], true)) {
            return null;
        }
        $param = $request->get('page');
        $page = is_scalar($param) && ctype_digit((string) $param) ? (int) $param : 0;
        if ($param !== null && $page < 1) {
            throw HttpError::notFound();
        }
        if ($page === 0 && (int) filesize($path) <= Feeds::SINGLE_MAX_BYTES) {
            return null;
        }
        $page = max(1, $page);
        $partPath = Feeds::partPath($this->app, $key, $page);
        if (!is_file($partPath)) {
            if ($page > 1) {
                throw HttpError::notFound();
            }
            // Un feed hecho por una versión anterior, sin páginas: se sirve entero y se rehace en segundo plano para la próxima.
            if ($this->app->has('jobs')) {
                $this->app->service('jobs')->push('semantic.feeds', [], ['unique' => 'semantic.feeds']);
            }

            return null;
        }
        $size = (int) filesize($partPath);
        $time = (int) filemtime($partPath);
        $etag = '"' . md5($key . '|p' . $page . '|' . $size . '|' . $time) . '"';
        $headers = ['ETag' => $etag, 'Last-Modified' => gmdate('D, d M Y H:i:s', $time) . ' GMT', 'Cache-Control' => 'public, max-age=300'];
        $sent = $request->header('if-none-match');
        if ($sent !== null && in_array($etag, array_map('trim', explode(',', $sent)), true)) {
            return new Response('', 304, $headers);
        }
        $links = [];
        $meta = json_decode((string) file_get_contents($partPath), true);
        $pagination = is_array($meta) && isset($meta['pagination']) && is_array($meta['pagination']) ? $meta['pagination'] : [];
        foreach (['next', 'prev', 'first', 'last', 'index'] as $rel) {
            if (isset($pagination[$rel]) && is_string($pagination[$rel])) {
                $links[] = '<' . $pagination[$rel] . '>; rel="' . ($rel === 'index' ? 'index' : $rel) . '"';
            }
        }
        if ($links !== []) {
            $headers['Link'] = implode(', ', $links);
        }
        if (isset($pagination['pages'])) {
            $headers['X-Total-Pages'] = (string) (int) $pagination['pages'];
        }
        $response = Response::file($partPath, Feeds::FILES[$key], 'application/json; charset=UTF-8', true);
        foreach ($headers as $name => $value) {
            $response->headers[$name] = $value;
        }

        return $response;
    }

    /**
     * El índice de los feeds: cada uno con sus bytes, sus productos y, los JSON, las páginas en que se reparten.
     *
     * @param array<string,string> $params
     */
    public function feedsIndex(Request $request, array $params): Response
    {
        $path = Feeds::indexPath($this->app);
        if (!is_file($path)) {
            // Feeds de una versión anterior (sin índice) o sin generar: se rehacen y mientras tanto se pide esperar.
            if ($this->app->has('jobs')) {
                $this->app->service('jobs')->push('semantic.feeds', [], ['unique' => 'semantic.feeds']);
            }

            return Response::text('The feeds index is being built. Try again in a moment.', 503, 'text/plain')->header('Retry-After', '300')->header('Cache-Control', 'no-store');
        }
        $response = Response::file($path, 'index.json', 'application/json; charset=UTF-8', true);
        $response->headers['Cache-Control'] = 'public, max-age=300';
        $response->headers['Access-Control-Allow-Origin'] = '*';

        return $response;
    }

    /**
     * Cuántos productos tiene la tienda y cuántos están en los feeds (los demás, con su motivo, en «Sistema → Diagnóstico»).
     *
     * @return array{products:int,in_feeds:int,excluded:array<int,array{id:int,reason:string}>,reasons:array<string,int>}
     */
    private function catalogCounts(): array
    {
        $report = FeedEligibility::report($this->app);
        // Los que no entran en los feeds, por identificador y con su motivo (el mismo dato que «php aifec feeds:excluded»), sin nombres.
        $excluded = [];
        $reasons = [];
        foreach ($report['excluded'] as $row) {
            $excluded[] = ['id' => $row['id'], 'reason' => $row['reason']];
            $reasons[$row['reason']] = (isset($reasons[$row['reason']]) ? $reasons[$row['reason']] : 0) + 1;
        }
        ksort($reasons);

        return ['products' => $report['total'], 'in_feeds' => $report['eligible'], 'excluded' => $excluded, 'reasons' => $reasons];
    }

    /**
     * El documento que dice qué ofrece la tienda a las máquinas: versión, nombre, idiomas, moneda y las puertas activas.
     *
     * @param array<string,string> $params
     */
    public function wellKnown(Request $request, array $params): Response
    {
        $s = $this->semantic();
        $document = [
            'version' => '1.0',
            // El origen del sistema: una marca que permite saber qué lo genera y quién es su autor.
            'generator' => ['name' => 'AIFEC', 'version' => App::VERSION, 'url' => 'https://aifec.dev', 'author' => 'Ecom Experts', 'license' => 'AGPL-3.0-or-later with additional terms (LICENSE.md)'],
            'name' => $s->shop(),
            'url' => $s->siteUrl(),
            'description' => $s->summary(),
            'languages' => $this->app->languages(),
            'default_language' => $this->app->defaultLang(),
            'currency' => $s->currency(),
            'catalog' => $this->catalogCounts(),
            'gates' => $this->app->hooks()->apply('semantic.gates', $s->gates()),
        ];
        // El pago: qué formas tiene encendidas la caja y que lo hace la persona en ella (sin ningún dato de las pasarelas).
        if (class_exists('Aifec\\Modules\\Orders\\PaymentSummary')) {
            $document['payment'] = \Aifec\Modules\Orders\PaymentSummary::declaration($this->app);
        }
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);

        return Response::text($json === false ? '{}' : $json, 200, 'application/json')
            ->header('Cache-Control', 'public, max-age=300')
            ->cacheable(self::TTL, ['semantic', 'settings', 'catalog']);
    }
}
