<?php
/**
 * AIFEC — opciones de un tema: lo que declara su theme.json, lo que ha cambiado el comerciante en el personalizador
 * y su salida como variables CSS.
 *
 * theme.json:
 *   "settings": {
 *     "colors": {"label": "Colours", "fields": {
 *        "accent": {"type": "color", "label": "Accent colour", "default": "#b94a17", "var": "--accent"}}},
 *     "layout": {"label": "Layout", "fields": {
 *        "container": {"type": "number", "label": "Page width", "default": 1200, "min": 960, "max": 1600, "var": "--container", "unit": "px"},
 *        "header_style": {"type": "select", "label": "Header", "options": {"classic": "Classic", "centered": "Centred"}, "default": "classic"}}}
 *   }
 * Tipos: color, number, text, select, toggle, image (identificador de la biblioteca), font (lista «font_stacks» del tema).
 * Un campo con «var» sale como variable CSS; los demás se leen en las plantillas con theme_option('clave').
 * Lo que cambia el comerciante se guarda en el ajuste «theme.options» (por tema: ['base' => ['accent' => '#…']]).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Front;

use Aifec\Kernel\App;

final class ThemeVars
{
    /**
     * Definición plana de los campos del tema activo (con los heredados): clave => campo (+ 'group').
     *
     * @return array<string,array<string,mixed>>
     */
    public static function fields(App $app): array
    {
        $theme = $app->theme();
        $fields = [];
        foreach (isset($theme['settings']) && is_array($theme['settings']) ? $theme['settings'] : [] as $groupKey => $group) {
            foreach (is_array($group) && isset($group['fields']) && is_array($group['fields']) ? $group['fields'] : [] as $key => $field) {
                if (is_array($field) && preg_match('/^[a-z][a-z0-9_]*$/', (string) $key) === 1) {
                    $fields[(string) $key] = $field + ['type' => 'text', 'label' => (string) $key, 'default' => '', 'group' => (string) $groupKey];
                }
            }
        }

        return $fields;
    }

    /**
     * Valores vigentes: los de fábrica del tema con los cambios del comerciante encima (ya validados).
     *
     * @return array<string,mixed>
     */
    public static function values(App $app): array
    {
        $theme = $app->theme();
        $saved = $app->settings()->arr('theme.options');
        $own = isset($saved[$theme['slug']]) && is_array($saved[$theme['slug']]) ? $saved[$theme['slug']] : [];
        $values = [];
        foreach (self::fields($app) as $key => $field) {
            $value = array_key_exists($key, $own) ? $own[$key] : $field['default'];
            $clean = self::clean($field, $value);
            $values[$key] = $clean !== null ? $clean : $field['default'];
        }

        return $values;
    }

    /**
     * @param mixed $default
     *
     * @return mixed
     */
    public static function get(App $app, string $key, $default = null)
    {
        $values = self::values($app);

        return array_key_exists($key, $values) ? $values[$key] : $default;
    }

    /** «:root{--accent:#b94a17;--container:1200px}» con los campos que declaran «var». */
    public static function css(App $app): string
    {
        $values = self::values($app);
        $rules = '';
        foreach (self::fields($app) as $key => $field) {
            if (!isset($field['var']) || preg_match('/^--[a-z][a-z0-9-]*$/', (string) $field['var']) !== 1) {
                continue;
            }
            $value = $values[$key];
            if ($field['type'] === 'font') {
                $stacks = isset($app->theme()['font_stacks']) && is_array($app->theme()['font_stacks']) ? $app->theme()['font_stacks'] : [];
                $value = isset($stacks[$value]) ? (string) $stacks[$value] : '';
            } elseif ($field['type'] === 'number') {
                $value = $value . (isset($field['unit']) && preg_match('/^(px|rem|em|%|vw|vh|ms|s)$/', (string) $field['unit']) === 1 ? $field['unit'] : '');
            }
            $value = (string) $value;
            // Nada que pueda cerrar la regla o la etiqueta.
            if ($value === '' || preg_match('/[;{}<>\\\\]/', $value) === 1) {
                continue;
            }
            $rules .= $field['var'] . ':' . $value . ';';
        }

        return $rules !== '' ? ':root{' . $rules . '}' : '';
    }

    /**
     * Temas de la cadena (del más general al activo) cuyo fichero de recursos hay que cargar. Un tema hijo AÑADE el suyo
     * encima del de su padre; si declara en su theme.json "css": "replace" (o "js": "replace"), corta la herencia y
     * solo se carga desde él.
     *
     * @param string $file ruta dentro de assets/ («css/theme.css»)
     * @param string $flag clave del theme.json que puede valer «replace» («css» o «js»)
     *
     * @return string[] slugs, en el orden en que se cargan
     */
    public static function assetChain(App $app, string $file, string $flag): array
    {
        $load = [];
        foreach (array_reverse((array) $app->theme()['chain']) as $slug) {
            $base = $app->root() . '/themes/' . $slug;
            $json = is_file($base . '/theme.json') ? json_decode((string) file_get_contents($base . '/theme.json'), true) : null;
            if (is_array($json) && isset($json[$flag]) && $json[$flag] === 'replace') {
                $load = [];
            }
            if (is_file($base . '/assets/' . $file)) {
                $load[] = (string) $slug;
            }
        }

        return $load;
    }

    /** Las hojas de estilo del tema activo y de sus padres, listas para la cabecera (ver styleTag). */
    public static function styleTags(App $app): string
    {
        $html = '';
        foreach (self::assetChain($app, 'css/theme.css', 'css') as $slug) {
            $html .= self::styleTag($app, 't', (string) $slug, 'css/theme.css') . "\n";
        }

        return $html;
    }

    /** Tope de una hoja para escribirla dentro de la página; por encima se enlaza (el navegador la guarda en su caché). */
    public const INLINE_CSS_MAX = 65536;

    /**
     * Una hoja de estilo propia (de un tema «t», o de un módulo o un plugin «m») lista para la página.
     *
     * Va escrita dentro de un <style>: la página no espera a otra petición para pintarse (ni una hoja que bloquee el primer
     * pintado, ni una cadena de peticiones críticas). El atributo data-af-css lleva la dirección que tendría enlazada. Si la
     * hoja no existe, pasa del tope o trae direcciones relativas (url(…) o @import), se enlaza como siempre. Quien instala
     * puede volver a las hojas enlazadas con `'inline_css' => false` en la configuración del sitio (config.php).
     */
    public static function styleTag(App $app, string $kind, string $slug, string $file): string
    {
        $href = $app->url()->asset($kind, $slug, $file);
        // Igual que el servidor de recursos: «m» es un módulo o, si no existe con ese nombre, un plugin.
        $dirs = ['t' => ['themes'], 'm' => ['modules', 'plugins']];
        $css = null;
        if (isset($dirs[$kind]) && preg_match('#^[a-z0-9_-]+$#', $slug) === 1 && strpos($file, '..') === false && $app->site()->get('inline_css', true) !== false) {
            foreach ($dirs[$kind] as $dir) {
                $path = $app->root() . '/' . $dir . '/' . $slug . '/assets/' . ltrim($file, '/');
                if (is_file($path)) {
                    $css = self::inlineCss($path);
                    break;
                }
            }
        }

        return $css !== null
            ? '<style data-af-css="' . e($href) . '">' . $css . '</style>'
            : '<link rel="stylesheet" href="' . e($href) . '">';
    }

    /** @var array<string,?string> hojas ya preparadas en esta petición */
    private static $inlined = [];

    /**
     * El contenido de una hoja listo para ir dentro de <style>: sin comentarios ni espacios de más (las cadenas entre
     * comillas no se tocan). null si no conviene escribirla en la página.
     */
    public static function inlineCss(string $file, int $max = self::INLINE_CSS_MAX): ?string
    {
        if (array_key_exists($file, self::$inlined)) {
            return self::$inlined[$file];
        }
        $css = is_file($file) && filesize($file) <= $max ? file_get_contents($file) : false;
        if (!is_string($css) || $css === '' || stripos($css, '</style') !== false || stripos($css, '@import') !== false
            || preg_match('#url\(\s*+["\']?+(?!data:|https?://|/)#i', $css) === 1) {
            return self::$inlined[$file] = null;
        }
        $parts = preg_split('#("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\')#', $css, -1, PREG_SPLIT_DELIM_CAPTURE);
        $out = '';
        foreach (is_array($parts) ? $parts : [$css] as $i => $part) {
            if ($i % 2 === 1) {
                $out .= $part;
                continue;
            }
            $part = (string) preg_replace('#/\*.*?\*/#s', '', $part);
            $part = (string) preg_replace('#\s+#', ' ', $part);
            $out .= (string) preg_replace('#\s*([{};,])\s*#', '$1', $part);
        }

        return self::$inlined[$file] = trim(str_replace(';}', '}', $out));
    }

    /** Las etiquetas <script defer> del guion del tema activo y de sus padres. */
    public static function scriptTags(App $app): string
    {
        $html = '';
        foreach (self::assetChain($app, 'js/theme.js', 'js') as $slug) {
            $html .= '<script src="' . e($app->url()->asset('t', $slug, 'js/theme.js')) . "\" defer></script>\n";
        }

        return $html;
    }

    /**
     * Valida un valor contra su campo. Devuelve el valor limpio o null si no vale.
     *
     * @param array<string,mixed> $field
     * @param mixed               $value
     *
     * @return mixed
     */
    public static function clean(array $field, $value)
    {
        switch ((string) $field['type']) {
            case 'color':
                return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) === 1 ? strtolower($value) : null;
            case 'number':
                if (!is_numeric($value)) {
                    return null;
                }
                $number = $value + 0;
                if ((isset($field['min']) && $number < $field['min']) || (isset($field['max']) && $number > $field['max'])) {
                    return null;
                }

                return $number;
            case 'toggle':
                return !empty($value) ? 1 : 0;
            case 'select':
            case 'font':
                $options = isset($field['options']) && is_array($field['options']) ? $field['options'] : [];

                return is_scalar($value) && ($options === [] || array_key_exists((string) $value, $options)) ? (string) $value : null;
            case 'image':
                return is_numeric($value) ? (int) $value : (is_string($value) && preg_match('#^[a-zA-Z0-9/_.-]+$#', $value) === 1 ? $value : null);
        }

        return is_scalar($value) ? mb_substr(trim((string) $value), 0, 500) : null;
    }
}
