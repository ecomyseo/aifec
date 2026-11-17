<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Cms\Front;

use Aifec\Kernel\App;
use Aifec\Kernel\Clock;

/**
 * Piezas pequeñas que comparten los controladores y las plantillas de la tienda: migas, fechas, direcciones completas, el guion
 * de los formularios y las etiquetas de caché de lo que una página pinta de otros módulos.
 */
final class Support
{
    /**
     * Una dirección de la propia tienda como dirección completa (las imágenes y los enlaces del contenido vienen relativos).
     */
    public static function absolute(App $app, string $url): string
    {
        $url = trim($url);
        if ($url === '' || preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) === 1) {
            return $url;
        }
        if (strpos($url, '//') === 0) {
            return 'https:' . $url;
        }

        return $app->url()->origin() . '/' . ltrim($url, '/');
    }

    /**
     * Migas de una página de la tienda: la portada y lo que se le pase detrás (el último elemento va sin enlace).
     *
     * @param array<int,array{name:string,url:string}> $tail
     *
     * @return array<int,array{name:string,url:string}>
     */
    public static function crumbs(App $app, array $tail): array
    {
        return array_merge([['name' => t('Home'), 'url' => $app->url()->to('/')]], $tail);
    }

    /**
     * Una fecha de la base como ['iso' => «2026-11-12», 'label' => «12 nov 2026»] en la zona horaria del sitio; vacío si no hay.
     *
     * @return array{iso:string,label:string}
     */
    public static function date(App $app, ?string $sql): array
    {
        if ($sql === null || trim($sql) === '') {
            return ['iso' => '', 'label' => ''];
        }
        $time = Clock::parse($sql);

        return [
            'iso' => gmdate('Y-m-d', $time),
            'label' => $app->i18n()->date($time, 'medium', $app->site()->timezone()),
        ];
    }

    /**
     * Etiquetas de la caché de página de lo que unos bloques pintan de otros módulos: el catálogo si llevan productos y cada
     * producto fijo. Así la página se renueva cuando cambia un precio y no solo cuando se edita ella.
     *
     * @param array<int|string,mixed> $blocks
     *
     * @return string[]
     */
    public static function dependencyTags(array $blocks, int $depth = 0): array
    {
        $tags = [];
        if ($depth > 20) {
            return $tags;
        }
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = isset($block['type']) ? (string) $block['type'] : '';
            $attrs = isset($block['attrs']) && is_array($block['attrs']) ? $block['attrs'] : [];
            if ($type === 'products' || $type === 'product') {
                $tags[] = 'catalog';
                $ids = $type === 'product' ? [isset($attrs['id']) ? $attrs['id'] : 0] : (isset($attrs['ids']) && is_array($attrs['ids']) ? $attrs['ids'] : []);
                foreach ($ids as $id) {
                    if ((int) $id > 0) {
                        $tags[] = 'product:' . (int) $id;
                    }
                }
            }
            if (isset($block['children']) && is_array($block['children'])) {
                $tags = array_merge($tags, self::dependencyTags($block['children'], $depth + 1));
            }
        }

        return array_values(array_unique($tags));
    }

    /**
     * La primera imagen del contenido, que no se difiere: es la que se ve al llegar. Solo toca la primera etiqueta <img>.
     */
    public static function eagerFirstImage(string $html): string
    {
        $done = false;

        return (string) preg_replace_callback('/<img\b[^>]*>/i', static function (array $m) use (&$done): string {
            if ($done) {
                return $m[0];
            }
            $done = true;
            $tag = (string) preg_replace('/\sloading="lazy"/i', ' loading="eager"', $m[0], 1);
            if (stripos($tag, 'fetchpriority=') === false) {
                $tag = (string) preg_replace('/^<img\b/i', '<img fetchpriority="high"', $tag, 1);
            }

            return $tag;
        }, $html);
    }

    /**
     * El guion de los formularios del contenido, si el HTML lleva alguno: pide la marca de tiempo al enfocar el primer campo y
     * envía por JavaScript (sin él, el formulario funciona igual). Vacío si no hace falta.
     */
    public static function formScript(App $app, string $html): string
    {
        if (strpos($html, 'data-af-form=') === false) {
            return '';
        }

        return $app->view()->render('cms::front/form-script');
    }

    /**
     * El texto de ese guion, letra por letra (lo que va entre <script> y </script>): de aquí sale su huella para la política de
     * contenido. Es el mismo en todas las páginas.
     */
    public static function formScriptJs(App $app): string
    {
        $html = $app->view()->render('cms::front/form-script');
        $from = strpos($html, '<script>');
        $to = strrpos($html, '</script>');

        return $from === false || $to === false || $to < $from ? '' : substr($html, $from + 8, $to - $from - 8);
    }
}
