<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Construye todas las direcciones del sitio. Ninguna plantilla ni módulo escribe una ruta a mano:
 * así un sitio montado en una subcarpeta («/demos/moda») o en otro idioma funciona sin tocar nada.
 */
final class Urls
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Ruta dentro del sitio, con el prefijo del sitio y el del idioma: to('/carrito') → «/demos/moda/carrito».
     *
     * @param array<string,string|int|float|null> $query
     * @param string|null                         $lang  null = el idioma de la petición; '' = sin prefijo de idioma
     */
    public function to(string $path = '/', array $query = [], ?string $lang = null): string
    {
        $lang = $lang !== null ? $lang : $this->app->lang();
        $prefix = $this->app->site()->basePath;
        if ($lang !== '' && $lang !== $this->app->defaultLang()) {
            $prefix .= '/' . $lang;
        }
        $path = '/' . ltrim($path, '/');
        if ($path === '/admin' || strpos($path, '/admin/') === 0) {
            $alias = $this->app->adminAlias();
            if ($alias !== '') {
                $path = '/' . $alias . substr($path, 6);
            }
        }
        $url = $prefix . ($path === '/' ? ($prefix === '' ? '/' : '/') : $path);
        $query = array_filter($query, static function ($v): bool {
            return $v !== null && $v !== '';
        });

        return $url . ($query !== [] ? '?' . http_build_query($query) : '');
    }

    /**
     * Ruta del panel o de cualquier otra zona sin idioma en la dirección.
     *
     * @param array<string,string|int|float|null> $query
     */
    public function plain(string $path, array $query = []): string
    {
        return $this->to($path, $query, '');
    }

    /**
     * Dirección completa, con protocolo y dominio.
     *
     * @param array<string,string|int|float|null> $query
     */
    public function abs(string $path = '/', array $query = [], ?string $lang = null): string
    {
        return $this->origin() . $this->to($path, $query, $lang);
    }

    /** «https://aifec.gmartos.es» (sin la subcarpeta del sitio). */
    public function origin(): string
    {
        $url = (string) $this->app->site()->get('url', '');
        if ($url !== '') {
            $parts = parse_url($url);
            if (is_array($parts) && isset($parts['host'])) {
                return (isset($parts['scheme']) ? $parts['scheme'] : 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
            }
        }
        $request = $this->app->request();

        return $request->scheme() . '://' . $request->host;
    }

    /**
     * Ruta de una ruta con nombre: route('admin.products.edit', ['id' => 5]).
     *
     * @param array<string,string|int> $params
     */
    public function route(string $name, array $params = [], ?string $lang = null): string
    {
        $path = $this->app->router()->path($name, $params);
        // Las zonas técnicas no llevan idioma en la dirección.
        if ($lang === null && preg_match('#^/(admin|api|mcp|cron|install|assets|media|\.well-known)(/|$)#', $path) === 1) {
            $lang = '';
        }

        return $this->to($path, [], $lang);
    }

    /** Dirección de una entidad con dirección amigable (producto, categoría, página, entrada…). */
    public function entity(string $type, int $id, ?string $lang = null): string
    {
        $lang = $lang !== null && $lang !== '' ? $lang : $this->app->lang();
        $path = $this->app->slugs()->path($type, $id, $lang);
        if ($path === null && $lang !== $this->app->defaultLang()) {
            // Sin dirección traducida: la del idioma principal, pero DENTRO del idioma de la visita (/en/producto/…), que la
            // tienda sirve en ese idioma; antes saltaba al castellano y el visitante inglés perdía su idioma (14/11/2026).
            $path = $this->app->slugs()->path($type, $id, $this->app->defaultLang());
        }
        if ($path === null) {
            return $this->to('/', [], $lang);
        }

        return $this->to('/' . $path . ($this->trailingSlash() && strpos(basename($path), '.') === false ? '/' : ''), [], $lang);
    }

    public function entityAbs(string $type, int $id, ?string $lang = null): string
    {
        return $this->origin() . $this->entity($type, $id, $lang);
    }

    /** Recurso estático de un tema («t»), de un módulo o plugin («m») o del núcleo («c»). */
    public function asset(string $kind, string $slug, string $path): string
    {
        return $this->app->publicBase() . '/assets/' . $kind . '/' . $slug . '/' . $this->app->assetBuild() . '/' . ltrim($path, '/');
    }

    /** Fichero de la biblioteca de medios del sitio: media('2026/11/silla-card.webp'). */
    public function media(string $relative): string
    {
        $base = (string) $this->app->site()->get('media_url', '');
        if ($base === '') {
            $base = $this->app->publicBase() . '/media/' . $this->app->site()->id;
        }

        return rtrim($base, '/') . '/' . ltrim($relative, '/');
    }

    /**
     * La dirección de la petición actual con otros parámetros (paginación, orden, filtros).
     *
     * @param array<string,string|int|float|null> $query se mezclan con los actuales; null quita el parámetro
     */
    public function current(array $query = []): string
    {
        $request = $this->app->request();
        $merged = $query + $request->query;
        foreach ($query as $key => $value) {
            if ($value === null) {
                unset($merged[$key]);
            }
        }
        $flat = [];
        foreach ($merged as $key => $value) {
            if (is_scalar($value) || is_array($value)) {
                $flat[$key] = $value;
            }
        }

        return $this->to($request->path, [], null) . ($flat !== [] ? '?' . http_build_query($flat) : '');
    }

    public function trailingSlash(): bool
    {
        return $this->app->settings()->bool('urls.trailing_slash', false);
    }
}
