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

        return Response::text(Llms::buildFull($this->semantic(), ($kb > 0 ? $kb : self::FULL_KB) * 1024), 200, 'text/plain')->cacheable(self::TTL, ['semantic', 'catalog', 'cms', 'settings']);
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
            'generator' => ['name' => 'AIFEC', 'version' => App::VERSION, 'url' => 'https://aifec.gmartos.es', 'author' => 'Ecom Experts', 'license' => 'AGPL-3.0-or-later with additional terms (LICENSE.md)'],
            'name' => $s->shop(),
            'url' => $s->siteUrl(),
            'description' => $s->summary(),
            'languages' => $this->app->languages(),
            'default_language' => $this->app->defaultLang(),
            'currency' => $s->currency(),
            'gates' => $this->app->hooks()->apply('semantic.gates', $s->gates()),
        ];
        $json = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR | JSON_PRETTY_PRINT);

        return Response::text($json === false ? '{}' : $json, 200, 'application/json')
            ->header('Cache-Control', 'public, max-age=300')
            ->cacheable(self::TTL, ['semantic', 'settings', 'catalog']);
    }
}
