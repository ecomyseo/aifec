<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Docs;

use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * La documentación en el sitio: el índice (GET /docs), el buscador (GET /docs/buscar?q=) y cada página
 * (GET /docs/{sección}/{página}, y con «.md» detrás su texto tal cual, como text/markdown).
 *
 * Todo sale en la caché de página con la etiqueta «docs»: la orden `docs:refresh` la invalida.
 */
final class DocsController
{
    /** Cuántas coincidencias enseña el buscador como mucho. */
    private const RESULTS = 20;

    /** @var App */
    private $app;

    /** @var Docs */
    private $docs;

    public function __construct(App $app)
    {
        $this->app = $app;
        /** @var Docs $docs */
        $docs = $app->service('docs');
        $this->docs = $docs;
    }

    /**
     * El filtro «request.start»: sirve la versión en texto de una página (`/docs/…/pagina.md`) antes de que el módulo de datos
     * semánticos, que también contesta a las direcciones con «.md», la dé por inexistente.
     *
     * @param mixed $early lo que ya haya contestado otro oyente
     *
     * @return mixed
     */
    public static function early($early, Request $request, App $app)
    {
        if ($early instanceof Response || ($request->method !== 'GET' && $request->method !== 'HEAD') || !$app->has('docs')) {
            return $early;
        }
        if (preg_match('#^/docs/.+\.md$#i', $request->path) !== 1) {
            return $early;
        }

        try {
            return (new self($app))->show($request, ['ruta' => (string) substr($request->path, 6)]);
        } catch (HttpError $e) {
            // Un oyente que lanza se apunta y la petición sigue: la página que no existe se contesta aquí mismo.
            return $app->errorResponse($e->status());
        }
    }

    /**
     * @param array<string,string> $params
     */
    public function index(Request $request, array $params): Response
    {
        $library = $this->docs->library();

        return $this->app->render('docs::front/index', [
            'page' => [
                'title' => t('Documentation'),
                'description' => t('Guides and references of the project, published from its Markdown files.'),
                'type' => 'page',
                'breadcrumbs' => $this->crumbs([['name' => t('Documentation'), 'url' => '']]),
            ],
            'tree' => $library->tree(),
            'empty' => $library->isEmpty(),
            'count' => count($library->pages()),
            'search_url' => $this->app->url()->to('/docs/buscar'),
            'query' => '',
        ])->cacheable(600, [Docs::TAG]);
    }

    /**
     * @param array<string,string> $params
     */
    public function search(Request $request, array $params): Response
    {
        $query = mb_substr(trim($request->str('q')), 0, 120);
        $results = [];
        if ($query !== '') {
            foreach ((new Search($this->docs))->run($query, self::RESULTS) as $hit) {
                $results[] = [
                    'title' => $hit['page']['title'],
                    'url' => $this->app->url()->to('/docs/' . $hit['page']['path']),
                    'snippet_html' => $hit['html'],
                    'section' => $this->sectionTitle($hit['page']['section']),
                ];
            }
        }

        // El buscador no se guarda en la caché de página: cada consulta es distinta y no merece una copia.
        return $this->app->render('docs::front/search', [
            'page' => [
                'title' => $query !== '' ? t('Search: {q}', ['q' => $query]) : t('Search the documentation'),
                'description' => t('Search the documentation by words.'),
                'type' => 'search',
                'breadcrumbs' => $this->crumbs([
                    ['name' => t('Documentation'), 'url' => $this->app->url()->to('/docs')],
                    ['name' => t('Search'), 'url' => ''],
                ]),
            ],
            'tree' => $this->docs->library()->tree(),
            'query' => $query,
            'results' => $results,
            'search_url' => $this->app->url()->to('/docs/buscar'),
        ]);
    }

    /**
     * @param array<string,string> $params ruta: `guia/instalar` o `guia/instalar.md`
     */
    public function show(Request $request, array $params): Response
    {
        $path = isset($params['ruta']) ? trim((string) $params['ruta'], '/') : '';
        $text = strtolower((string) substr($path, -3)) === '.md';
        if ($text) {
            $path = (string) substr($path, 0, -3);
        }
        // Solo se acepta lo que parece una dirección de página; el resto ni se busca.
        if ($path === '' || preg_match('#^[a-z0-9][a-z0-9._-]*(?:/[a-z0-9][a-z0-9._-]*)*$#', $path) !== 1) {
            throw HttpError::notFound();
        }
        $library = $this->docs->library();
        $entry = $library->find($path);
        if ($entry === null) {
            throw HttpError::notFound();
        }
        $markdown = $library->read($entry);
        if ($markdown === '') {
            throw HttpError::notFound();
        }
        $url = $this->app->url();
        $textUrl = $url->to('/docs/' . $entry['path'] . '.md');
        if ($text) {
            // Como texto plano: hay lectores de páginas de asistentes (el de Gemini) que no abren «text/markdown».
            $response = Response::text($this->docs->textVersion($markdown, $entry), 200, 'text/plain');
            $response->headers['Link'] = '<' . $url->origin() . $url->to('/docs/' . $entry['path']) . '>; rel="canonical"';
            $response->headers['X-Robots-Tag'] = 'noindex';

            return $response->cacheable(600, [Docs::TAG]);
        }
        $html = $this->docs->html($markdown, $entry, $this->panelOpen($request));
        $around = $library->around($entry);
        $section = $this->sectionTitle($entry['section']);
        $tail = [['name' => t('Documentation'), 'url' => $url->to('/docs')]];
        if ($section !== '') {
            $tail[] = ['name' => $section, 'url' => $url->to('/docs') . '#s-' . $entry['section']];
        }
        $tail[] = ['name' => $entry['title'], 'url' => ''];

        return $this->app->render('docs::front/page', [
            'page' => [
                'title' => $entry['title'],
                'description' => $this->docs->description($html),
                'type' => 'page',
                'breadcrumbs' => $this->crumbs($tail),
                'markdown' => $url->origin() . $textUrl,
            ],
            'tree' => $library->tree(),
            'current' => $entry['path'],
            'entry' => $entry,
            'html' => $html,
            'headings' => $this->docs->headings($html),
            'prev' => $around['prev'],
            'next' => $around['next'],
            'text_url' => $textUrl,
            'search_url' => $url->to('/docs/buscar'),
            'query' => '',
        ])->cacheable(600, [Docs::TAG]);
    }

    /**
     * Una imagen de la documentación (GET /docs/_media/{fichero}): solo las de la carpeta `_media`, y solo si lo que hay en el
     * fichero es de verdad esa imagen. Con fecha de última modificación y ETag, para que el navegador no la pida otra vez.
     *
     * @param array<string,string> $params fichero: el nombre de la imagen
     */
    public function media(Request $request, array $params): Response
    {
        $name = isset($params['fichero']) ? (string) $params['fichero'] : '';
        $info = $this->docs->library()->media($name);
        if ($info === null && ($fallback = $this->docs->fallbackLibrary()) !== null) {
            // La documentación en inglés usa las mismas capturas que la de siempre.
            $info = $fallback->media($name);
        }
        if ($info === null) {
            throw HttpError::notFound();
        }
        $etag = '"' . md5($name . '|' . $info['size'] . '|' . $info['time']) . '"';
        $headers = [
            'ETag' => $etag,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $info['time']) . ' GMT',
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
        ];
        $sent = $request->header('if-none-match');
        $since = $request->header('if-modified-since');
        $same = $sent !== null && in_array($etag, array_map('trim', explode(',', $sent)), true);
        if (!$same && $sent === null && $since !== null) {
            $time = strtotime($since);
            $same = $time !== false && $time >= $info['time'];
        }
        if ($same) {
            return new Response('', 304, $headers);
        }
        $response = Response::file($info['file'], $name, $info['mime'], true);
        foreach ($headers as $header => $value) {
            $response->headers[$header] = $value;
        }

        return $response;
    }

    /** ¿Quien pide la página tiene abierto el panel? Sin la cookie del panel ni se mira la sesión: la página puede salir de la caché. */
    private function panelOpen(Request $request): bool
    {
        if (!isset($request->cookies['af_a_' . $this->app->site()->cookieSuffix()])) {
            return false;
        }

        return $this->app->auth()->admin() !== null;
    }

    /**
     * @param array<int,array{name:string,url:string}> $tail
     *
     * @return array<int,array{name:string,url:string}>
     */
    private function crumbs(array $tail): array
    {
        return array_merge([['name' => t('Home'), 'url' => $this->app->url()->to('/')]], $tail);
    }

    private function sectionTitle(string $slug): string
    {
        foreach ($this->docs->library()->tree() as $section) {
            if ($section['slug'] === $slug) {
                return $section['title'];
            }
        }

        return '';
    }
}
