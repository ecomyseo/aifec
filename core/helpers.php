<?php
/**
 * AIFEC — funciones cortas para las plantillas y los controladores. Todas actúan sobre la aplicación en curso.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

if (!function_exists('app')) {
    /** La aplicación que atiende la petición en curso. */
    function app(): App
    {
        return App::current();
    }

    /**
     * Escapa un dato para pintarlo en HTML (texto o valor de atributo entre comillas).
     * TODO dato que llegue a una plantilla pasa por aquí.
     *
     * @param mixed $value
     */
    function e($value): string
    {
        if ($value === null || is_array($value) || (is_object($value) && !method_exists($value, '__toString'))) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Traduce un texto de la interfaz. El texto fuente va en inglés.
     *
     * @param array<string,string|int|float> $vars
     */
    function t(string $text, array $vars = []): string
    {
        return App::current()->i18n()->t($text, $vars);
    }

    /**
     * Traducción con singular y plural: tn('{n} item', '{n} items', $n).
     *
     * @param array<string,string|int|float> $vars
     */
    function tn(string $singular, string $plural, int $n, array $vars = []): string
    {
        return App::current()->i18n()->tn($singular, $plural, $n, $vars);
    }

    /**
     * Dirección dentro del sitio (con su subcarpeta y su idioma).
     *
     * @param array<string,string|int|float|null> $query
     */
    function url(string $path = '/', array $query = []): string
    {
        return App::current()->url()->to($path, $query);
    }

    /**
     * Dirección de una ruta con nombre.
     *
     * @param array<string,string|int> $params
     */
    function route(string $name, array $params = []): string
    {
        return App::current()->url()->route($name, $params);
    }

    /** Recurso estático del tema activo: asset('css/theme.css'). Busca en el tema y en sus padres. */
    function asset(string $path): string
    {
        $app = App::current();
        foreach ((array) $app->theme()['chain'] as $slug) {
            if (is_file($app->root() . '/themes/' . $slug . '/assets/' . ltrim($path, '/'))) {
                return $app->url()->asset('t', (string) $slug, $path);
            }
        }

        return $app->url()->asset('t', (string) $app->theme()['slug'], $path);
    }

    /** Importe en céntimos con el formato y la moneda de la tienda: money(4999) → «49,99 €». */
    function money(int $cents, ?string $currency = null): string
    {
        $app = App::current();

        return $app->i18n()->money($cents, $currency !== null ? $currency : $app->settings()->str('shop.currency', 'EUR'));
    }

    /**
     * Pinta un hueco de plantilla: lo que los módulos y plugins quieran poner en ese punto.
     *
     * @param mixed ...$args
     */
    function hook(string $name, ...$args): string
    {
        return App::current()->hooks()->render($name, ...$args);
    }

    /**
     * Valor de un ajuste del sitio.
     *
     * @param mixed $default
     *
     * @return mixed
     */
    function setting(string $key, $default = null)
    {
        return App::current()->settings()->get($key, $default);
    }

    /**
     * Atributos HTML a partir de un array: attrs(['class' => 'a', 'disabled' => true, 'title' => null]).
     * Los valores se escapan; true pinta el atributo solo; false y null lo omiten.
     *
     * @param array<string,mixed> $attributes
     */
    function attrs(array $attributes): string
    {
        $out = '';
        foreach ($attributes as $name => $value) {
            if ($value === false || $value === null || preg_match('/^[a-zA-Z_:][a-zA-Z0-9_:.-]*$/', (string) $name) !== 1) {
                continue;
            }
            $out .= $value === true ? ' ' . $name : ' ' . $name . '="' . e($value) . '"';
        }

        return $out;
    }

    /**
     * JSON listo para incrustarlo en la página (datos estructurados, datos para JavaScript).
     * No puede cerrar la etiqueta que lo contiene.
     *
     * @param mixed $value
     */
    function json_html($value): string
    {
        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $json === false ? 'null' : $json;
    }

    // ------------------------------------------------------------------ tienda (plantillas de los temas)

    /**
     * Datos de cabecera de la página ya completos (título, canónica, robots, datos estructurados…).
     *
     * @param array<string,mixed> $page
     *
     * @return array<string,mixed>
     */
    function page_meta(array $page): array
    {
        return \Aifec\Front\Page::prepare(App::current(), $page);
    }

    /**
     * Las etiquetas del <head> de la página. El envoltorio de todo tema la llama una vez.
     *
     * @param array<string,mixed> $page
     */
    function page_head(array $page): string
    {
        return \Aifec\Front\Page::head(App::current(), $page);
    }

    /**
     * Opción del tema activo (theme.json + personalizador).
     *
     * @param mixed $default
     *
     * @return mixed
     */
    function theme_option(string $key, $default = null)
    {
        return \Aifec\Front\ThemeVars::get(App::current(), $key, $default);
    }

    /**
     * Logotipo de la tienda para la cabecera: la imagen elegida en Ajustes → Identidad o, si no hay, el nombre (escapado).
     *
     * @param array<string,mixed> $attrs atributos extra de la imagen (class, height…)
     */
    function shop_logo(array $attrs = []): string
    {
        $app = App::current();
        $name = $app->settings()->str('store.name', 'AIFEC');
        $image = \Aifec\Front\Page::logo($app);
        if ($image === null || empty($image['url'])) {
            return e($name);
        }
        $image['alt'] = $name;
        unset($image['srcset']);

        return image_tag($image, 'full', $attrs + ['class' => 'af-logo-img', 'loading' => 'eager']);
    }

    /** Hojas de estilo del tema: la del tema base y, encima, las de sus hijos (ver ThemeVars::assetChain). Va en el <head>. */
    function theme_styles(): string
    {
        return \Aifec\Front\ThemeVars::styleTags(App::current());
    }

    /** Guiones del tema (con defer), del tema base al activo. Va antes de </body> o en el <head>. */
    function theme_scripts(): string
    {
        return \Aifec\Front\ThemeVars::scriptTags(App::current());
    }

    /**
     * Elementos de un menú de la tienda («main», «footer», «legal»): [['label', 'url', 'current' => bool, 'children' => […]]].
     * Los aportan los módulos con el filtro «menu.items»; sin ninguno, lista vacía.
     *
     * @return array<int,array<string,mixed>>
     */
    function menu(string $location): array
    {
        $app = App::current();
        $items = $app->hooks()->apply('menu.items', [], $location, $app);

        return is_array($items) ? array_values(array_filter($items, 'is_array')) : [];
    }

    /**
     * Migas de pan accesibles a partir de [['name', 'url'], …].
     *
     * @param array<int,array<string,mixed>> $crumbs
     */
    function breadcrumbs(array $crumbs): string
    {
        return \Aifec\Front\Page::breadcrumbs($crumbs);
    }

    /**
     * Etiqueta <img> de una imagen de la biblioteca (el array que dan ProductData y el servicio «media»):
     * con su tamaño, su texto alternativo, carga diferida y, si los trae, sus anchos alternativos.
     * Sin imagen devuelve un hueco neutro del mismo tamaño para que la página no salte.
     *
     * @param array<string,mixed>|null $image url, alt, width, height, thumb, card, large, srcset
     * @param string                   $size  thumb, card, large o full
     * @param array<string,mixed>      $attrs atributos extra (class, sizes, loading => 'eager' para la primera imagen…)
     */
    function image_tag(?array $image, string $size = 'card', array $attrs = []): string
    {
        if ($image === null || empty($image['url'])) {
            return '<span class="af-noimage' . (isset($attrs['class']) ? ' ' . e($attrs['class']) : '') . '" aria-hidden="true"></span>';
        }
        $src = $size !== 'full' && !empty($image[$size]) ? $image[$size] : $image['url'];
        $base = [
            'src' => $src,
            'alt' => isset($image['alt']) ? (string) $image['alt'] : '',
            'width' => isset($image['width']) ? (int) $image['width'] : null,
            'height' => isset($image['height']) ? (int) $image['height'] : null,
            'loading' => 'lazy',
            'decoding' => 'async',
        ];
        if (!empty($image['srcset'])) {
            $base['srcset'] = (string) $image['srcset'];
            $base['sizes'] = '(max-width: 600px) 50vw, 25vw';
        }

        return '<img' . attrs($attrs + $base) . '>';
    }
}
