<?php
/**
 * AIFEC — código del tema gmartos (la tienda actual de gmartos.es): ayudas para sus plantillas. Iconos dibujados en SVG,
 * textos de la portada con valor de fábrica y opción propia, enlaces seguros, logotipo con respaldo, extracto de la
 * tarjeta, menú limpio y atajos de búsqueda. No registra rutas ni ganchos: todo lo demás lo hereda del tema base.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

if (!function_exists('gmartos_svg')) {
    /**
     * Un icono SVG en línea (trazo, sin relleno). Las claves son fijas: todo el contenido es del propio tema, nunca un dato.
     */
    function gmartos_svg(string $key, int $size = 24): string
    {
        static $paths = [
            'arrow' => '<path d="M5 19 19 5M5 5h14v14"/>',
            'bag' => '<path d="M5 8h14v13H5zM9 8V5a3 3 0 0 1 6 0v3"/>',
            'search' => '<circle cx="10" cy="10" r="6"/><path d="m15 15 6 6"/>',
            'menu' => '<path d="M3 5h18M3 12h18M3 19h18"/>',
            'store' => '<path d="M4 10v11h16V10M2 9l3-7h14l3 7H2ZM9 21v-7h6v7M8 2v7m8-7v7"/>',
            'modules' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-6 8-6s8 2 8 6"/>',
            'sliders' => '<path d="M4 6h8M18 6h2M4 12h2M12 12h8M4 18h10M20 18h0"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/>',
            'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        ];
        $inner = isset($paths[$key]) ? $paths[$key] : $paths['arrow'];
        $size = max(12, min(64, $size));

        return '<svg class="gm-ico" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}

if (!function_exists('gmartos_link')) {
    /** Dirección que se puede poner en un enlace: ruta de la tienda, ancla o http(s). Cualquier otra cosa (javascript:, data:…) da «». */
    function gmartos_link(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        if (preg_match('#^/(?!/)#', $value) === 1) {
            return url($value);
        }
        if (preg_match('/^#[A-Za-z0-9_-]+$/', $value) === 1 || preg_match('#^https?://[^\s"<>]+$#i', $value) === 1) {
            return $value;
        }

        return '';
    }
}

if (!function_exists('gmartos_text')) {
    /** Un texto de la portada: el que escribe el comerciante en el personalizador o, si lo deja vacío, el de fábrica ya traducido con t(). */
    function gmartos_text(string $option, string $translated): string
    {
        $value = trim((string) theme_option($option, ''));

        return $value !== '' ? $value : $translated;
    }
}

if (!function_exists('gmartos_lines')) {
    /** Un texto con «|» como salto de línea, ya escapado y listo para pintar. */
    function gmartos_lines(string $text): string
    {
        $parts = array_map('trim', explode('|', $text));

        return implode('<br>', array_map('e', array_filter($parts, static function (string $part): bool {
            return $part !== '';
        })));
    }
}

if (!function_exists('gmartos_logo')) {
    /**
     * El logotipo: el que el comerciante subió en Ajustes → Identidad o, si no hay, el del propio tema (de fondo claro o,
     * con $inverse, el de fondo oscuro del pie).
     */
    function gmartos_logo(bool $inverse = false): string
    {
        $app = App::current();
        $image = \Aifec\Front\Page::logo($app);
        if ($image !== null && !empty($image['url'])) {
            return shop_logo(['class' => 'af-logo-img gm-logo-img']);
        }
        $name = $app->settings()->str('store.name', 'AIFEC');

        return '<img class="gm-logo-img gm-logo-theme" src="' . e(asset('img/logo' . ($inverse ? '-inverse' : '') . '.svg')) . '" alt="' . e($name) . '" width="248" height="50" decoding="async">';
    }
}

if (!function_exists('gmartos_can_add')) {
    /** ¿Se puede añadir al carrito desde una tarjeta? Con el módulo de pedidos, o en la página de muestra del tema. */
    function gmartos_can_add(): bool
    {
        $app = App::current();

        return $app->view()->shared('af_demo') === true || $app->router()->has('cart.add');
    }
}

if (!function_exists('gmartos_add_action')) {
    /** A dónde envía el formulario de «añadir» de una tarjeta. */
    function gmartos_add_action(): string
    {
        $app = App::current();

        return $app->router()->has('cart.add') ? route('cart.add') : url('/tema/muestra');
    }
}

if (!function_exists('gmartos_shop_url')) {
    /** Dirección de «toda la tienda» si el comerciante la ha puesto; «» si no (nunca un enlace roto). */
    function gmartos_shop_url(): string
    {
        return gmartos_link((string) theme_option('shop_link', ''));
    }
}

if (!function_exists('gmartos_excerpt')) {
    /**
     * El extracto de una tarjeta: las primeras palabras del resumen del producto (o de su descripción) en texto plano. Sale de
     * la ficha, que el catálogo ya guarda en su caché; se recuerda en la vista de la petición para no pedirlo dos veces.
     *
     * @param array<string,mixed> $card
     */
    function gmartos_excerpt(array $card, int $words = 18): string
    {
        $id = isset($card['id']) ? (int) $card['id'] : 0;
        $app = App::current();
        $view = $app->view();
        $memo = $view->shared('gm_excerpt');
        $memo = is_array($memo) ? $memo : [];
        if (!isset($memo[$id])) {
            $html = '';
            if ($id > 0 && $app->has('catalog')) {
                try {
                    $full = $app->service('catalog')->product($id);
                } catch (\Throwable $e) {
                    $full = null;
                }
                if (is_array($full)) {
                    $html = isset($full['summary']) && trim((string) $full['summary']) !== '' ? (string) $full['summary'] : (isset($full['description']) ? (string) $full['description'] : '');
                }
            }
            $plain = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8')));
            $memo[$id] = $plain;
            if ($id > 0) {
                $view->share('gm_excerpt', $memo);
            }
        }
        $plain = $memo[$id];
        $list = $plain === '' ? [] : preg_split('/\s+/u', $plain);
        $list = is_array($list) ? $list : [];
        if (count($list) > $words) {
            return implode(' ', array_slice($list, 0, max(1, $words))) . '…';
        }

        return $plain;
    }
}

if (!function_exists('gmartos_clean_menu')) {
    /**
     * Un menú sin los elementos rotos (sin texto, o sin dirección y sin hijos), con los hijos ya limpios.
     *
     * @param array<int,mixed> $items
     *
     * @return array<int,array{label:string,url:string,current:bool,children:array<int,mixed>}>
     */
    function gmartos_clean_menu(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = isset($item['label']) ? (string) $item['label'] : '';
            $href = isset($item['url']) ? (string) $item['url'] : '';
            $kids = isset($item['children']) && is_array($item['children']) ? gmartos_clean_menu($item['children']) : [];
            if ($label === '' || ($href === '' && $kids === [])) {
                continue;
            }
            $out[] = ['label' => $label, 'url' => $href, 'current' => !empty($item['current']), 'children' => $kids];
        }

        return $out;
    }
}

if (!function_exists('gmartos_chips')) {
    /**
     * Los atajos de búsqueda de la portada: «Etiqueta=búsqueda» separados por comas (sin «=», la etiqueta es la búsqueda).
     * Solo salen si existe la ruta de búsqueda; cada uno es [etiqueta, dirección].
     *
     * @return array<int,array{0:string,1:string}>
     */
    function gmartos_chips(): array
    {
        if (!base_theme_can('search')) {
            return [];
        }
        $out = [];
        foreach (explode(',', gmartos_text('home_chips', t('Integrations=connector, B2B sales=B2B, Automation=automation, Security=security'))) as $pair) {
            $parts = explode('=', $pair, 2);
            $label = trim($parts[0]);
            $query = trim(isset($parts[1]) ? $parts[1] : $parts[0]);
            if ($label === '' || $query === '') {
                continue;
            }
            $out[] = [$label, base_theme_link('search') . '?q=' . rawurlencode($query)];
        }

        return array_slice($out, 0, 6);
    }
}

if (!function_exists('gmartos_footer_columns')) {
    /**
     * Las columnas de enlaces del pie a partir del menú «footer»: cada elemento con hijos es una columna con su título; los
     * elementos sueltos van juntos en la primera, «Explore».
     *
     * @return array<int,array{title:string,links:array<int,array{label:string,url:string}>}>
     */
    function gmartos_footer_columns(): array
    {
        $cols = [];
        $loose = [];
        foreach (gmartos_clean_menu(menu('footer')) as $item) {
            if ($item['children'] !== []) {
                $links = [];
                foreach ($item['children'] as $kid) {
                    if ($kid['url'] !== '') {
                        $links[] = ['label' => $kid['label'], 'url' => $kid['url']];
                    }
                }
                if ($links !== []) {
                    $cols[] = ['title' => $item['label'], 'links' => $links];
                }
            } elseif ($item['url'] !== '') {
                $loose[] = ['label' => $item['label'], 'url' => $item['url']];
            }
        }
        if ($loose !== []) {
            array_unshift($cols, ['title' => t('Explore'), 'links' => $loose]);
        }

        return array_slice($cols, 0, 3);
    }
}
