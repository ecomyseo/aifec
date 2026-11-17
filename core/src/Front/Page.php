<?php
/**
 * AIFEC — los datos de cabecera de una página de la tienda y su pintado en el <head>.
 *
 * Cada controlador de la tienda pasa a su plantilla un array «page»:
 *   title, description, canonical (dirección completa), robots, type (home, product, category, brand, page, post,
 *   blog, search, cart, checkout, account, order, error), entity (['type' => 'product', 'id' => 12]),
 *   breadcrumbs ([['name' => …, 'url' => …]]), alternates ([idioma => dirección completa]), image (dirección
 *   completa para compartir), jsonld (lista de objetos de datos estructurados), markdown (dirección de la versión
 *   en texto limpio), body_class, title_full (true = no se añade el nombre de la tienda).
 *
 * Page::prepare() completa lo que falte y pasa el array por el filtro «page.meta», que es donde los módulos de SEO
 * y de datos estructurados añaden lo suyo. Page::head() lo pinta. El envoltorio de CUALQUIER tema llama a
 * page_head($page): así ninguna tienda sale sin canónica, sin datos estructurados o sin su versión en texto por
 * culpa de un tema.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Front;

use Aifec\Kernel\App;

final class Page
{
    /** Tipos de página que nunca se indexan. */
    private const PRIVATE_TYPES = ['cart', 'checkout', 'account', 'order', 'search', 'error'];

    /** Parámetros de seguimiento que no forman parte de la dirección canónica. */
    private const TRACKING = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id', 'gclid', 'fbclid', 'msclkid', 'ttclid', 'mc_cid', 'mc_eid', 'srsltid'];

    /**
     * @param array<string,mixed> $page
     *
     * @return array<string,mixed>
     */
    public static function prepare(App $app, array $page): array
    {
        if (!empty($page['_prepared'])) {
            return $page;
        }
        $request = $app->request();
        $page += [
            'title' => '', 'description' => '', 'canonical' => '', 'robots' => '', 'type' => 'page', 'entity' => null, 'breadcrumbs' => [],
            'alternates' => [], 'image' => '', 'jsonld' => [], 'markdown' => '', 'body_class' => '', 'title_full' => false,
        ];
        $shop = $app->settings()->str('store.name', 'AIFEC');
        if ($page['title'] === '') {
            $page['title'] = $shop;
            $page['title_full'] = true;
        }
        if ($page['canonical'] === '') {
            $query = array_diff_key($request->query, array_flip(self::TRACKING));
            ksort($query);
            $page['canonical'] = $app->url()->origin() . $app->url()->to($request->path, [], null) . ($query !== [] ? '?' . http_build_query($query) : '');
        }
        if ($page['robots'] === '') {
            $private = in_array($page['type'], self::PRIVATE_TYPES, true);
            // Un sitio de demostración, o uno que todavía no se ha abierto, no se indexa entero.
            $closed = $app->site()->isDemo() || $app->settings()->bool('seo.noindex_site', false);
            // Con «seo.index_products», las fichas de producto sí se indexan aunque la tienda esté oculta (nunca en una demo).
            if ($closed && $page['type'] === 'product' && !$app->site()->isDemo() && $app->settings()->bool('seo.index_products', false)) {
                $closed = false;
            }
            $page['robots'] = $private || $closed ? 'noindex, follow' : 'index, follow, max-image-preview:large';
        }
        $page['lang'] = $app->lang();
        $page['shop'] = $shop;
        $page['full_title'] = !empty($page['title_full']) ? (string) $page['title'] : $page['title'] . ' · ' . $shop;
        $page['_prepared'] = true;

        $filtered = $app->hooks()->apply('page.meta', $page, $app);

        return is_array($filtered) && !empty($filtered['_prepared']) ? $filtered : $page;
    }

    /**
     * Las etiquetas del <head> de una página: título, descripción, canónica, robots, idiomas, datos para compartir,
     * versión en texto, datos estructurados, variables del tema y el hueco «head» de los módulos.
     *
     * @param array<string,mixed> $page ya pasado por prepare() (si no, se prepara aquí)
     */
    public static function head(App $app, array $page): string
    {
        $page = self::prepare($app, $page);
        $html = '<title>' . e($page['full_title']) . "</title>\n";
        if ($page['description'] !== '') {
            $html .= '<meta name="description" content="' . e($page['description']) . "\">\n";
        }
        $html .= '<meta name="robots" content="' . e($page['robots']) . "\">\n";
        // Una página de error no tiene dirección canónica: decir «la buena es esta» de una dirección que no existe confunde al buscador.
        $isError = $page['type'] === 'error';
        if (!$isError) {
            $html .= '<link rel="canonical" href="' . e($page['canonical']) . "\">\n";
        }
        $html .= '<link rel="icon" href="' . e(self::iconUrl($app)) . "\">\n";
        foreach ((array) $page['alternates'] as $lang => $url) {
            $html .= '<link rel="alternate" hreflang="' . e((string) $lang) . '" href="' . e((string) $url) . "\">\n";
        }
        if ($page['markdown'] !== '') {
            $html .= '<link rel="alternate" type="text/markdown" href="' . e($page['markdown']) . "\">\n";
        }
        $html .= '<meta property="og:title" content="' . e($page['title']) . "\">\n"
            . '<meta property="og:type" content="' . ($page['type'] === 'product' ? 'product' : ($page['type'] === 'post' ? 'article' : 'website')) . "\">\n"
            . ($isError ? '' : '<meta property="og:url" content="' . e($page['canonical']) . "\">\n")
            . '<meta property="og:site_name" content="' . e($page['shop']) . "\">\n";
        if ($page['description'] !== '') {
            $html .= '<meta property="og:description" content="' . e($page['description']) . "\">\n";
        }
        if ($page['image'] !== '') {
            $html .= '<meta property="og:image" content="' . e($page['image']) . "\">\n<meta name=\"twitter:card\" content=\"summary_large_image\">\n";
        }
        foreach ((array) $page['jsonld'] as $data) {
            if (is_array($data) && $data !== []) {
                $html .= '<script type="application/ld+json">' . json_html($data) . "</script>\n";
            }
        }
        $css = ThemeVars::css($app);
        if ($css !== '') {
            $html .= '<style id="af-theme-vars">' . $css . "</style>\n";
        }

        return $html . $app->hooks()->render('head', $page);
    }

    /**
     * El logotipo de la tienda (ajuste «store.logo») como imagen de la biblioteca, o null si no hay o falta el módulo de medios.
     *
     * @return array<string,mixed>|null
     */
    public static function logo(App $app): ?array
    {
        return self::media($app, 'store.logo');
    }

    /** Dirección del icono del sitio: el elegido en los ajustes («store.icon») o, si no hay, uno dibujado con la inicial de la tienda. */
    public static function iconUrl(App $app): string
    {
        $image = self::media($app, 'store.icon');
        if ($image !== null && !empty($image['url'])) {
            return (string) (!empty($image['thumb']) ? $image['thumb'] : $image['url']);
        }

        return 'data:image/svg+xml,' . rawurlencode(self::defaultIcon($app));
    }

    /** El icono de serie: un cuadrado con la inicial de la tienda. Es SVG y no depende de ningún fichero. */
    public static function defaultIcon(App $app): string
    {
        $name = trim($app->settings()->str('store.name', 'AIFEC'));
        $letter = $name !== '' ? mb_strtoupper(mb_substr($name, 0, 1)) : 'A';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64"><rect width="64" height="64" rx="14" fill="#1c3a2f"/>'
            . '<text x="32" y="45" font-family="Georgia,serif" font-size="38" font-weight="700" text-anchor="middle" fill="#ece5d8">' . e($letter) . '</text></svg>';
    }

    /**
     * @return array<string,mixed>|null
     */
    private static function media(App $app, string $key): ?array
    {
        $id = $app->settings()->int($key);
        if ($id <= 0 || !$app->has('media')) {
            return null;
        }
        try {
            $image = $app->service('media')->image($id);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($image) ? $image : null;
    }

    /**
     * Migas de pan como HTML accesible (el tema puede pintar las suyas con $page['breadcrumbs']).
     *
     * @param array<int,array{name:string,url:string}> $crumbs
     */
    public static function breadcrumbs(array $crumbs): string
    {
        if ($crumbs === []) {
            return '';
        }
        $html = '<nav class="af-breadcrumbs" aria-label="' . e(t('You are here')) . '"><ol>';
        $last = count($crumbs) - 1;
        foreach (array_values($crumbs) as $i => $crumb) {
            $name = e(isset($crumb['name']) ? (string) $crumb['name'] : '');
            $html .= $i === $last || empty($crumb['url']) ? '<li aria-current="page">' . $name . '</li>' : '<li><a href="' . e((string) $crumb['url']) . '">' . $name . '</a></li>';
        }

        return $html . '</ol></nav>';
    }
}
