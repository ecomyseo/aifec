<?php
/**
 * AIFEC — código del tema taller (bricolaje y ferretería): ayudas para sus plantillas. Iconos dibujados en SVG, datos
 * de la tarjeta (referencia y tres características), documentos descargables de la ficha, marcas y consejos de la
 * portada y enlaces seguros. No registra rutas ni ganchos: todo lo demás lo hereda del tema base.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

if (!function_exists('taller_svg')) {
    /**
     * Un icono SVG en línea (trazo, sin relleno). Las claves son fijas: todo el contenido es del propio tema, nunca un dato.
     */
    function taller_svg(string $key, int $size = 22): string
    {
        static $paths = [
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
            'user' => '<circle cx="12" cy="8.5" r="3.8"/><path d="M5 20.5c.6-3.6 3.5-5.4 7-5.4s6.4 1.8 7 5.4"/>',
            'cart' => '<path d="M3 4h2.5l2 11h10.5l2-8H7"/><circle cx="9.5" cy="19" r="1.4"/><circle cx="17" cy="19" r="1.4"/>',
            'phone' => '<path d="M5 4h4l2 5-2.5 1.5a11 11 0 0 0 5 5L15 13l5 2v4a2 2 0 0 1-2 2A16 16 0 0 1 3 6a2 2 0 0 1 2-2Z"/>',
            'truck' => '<path d="M2 6h11v10H2zM13 9h4l4 3v4h-8"/><circle cx="6.5" cy="17.5" r="1.8"/><circle cx="17.5" cy="17.5" r="1.8"/>',
            'store' => '<path d="M3 9l1.5-5h15L21 9H3zM4 9v11h16V9M9.5 20v-6h5v6"/>',
            'returns' => '<path d="M5 8h11a4 4 0 0 1 0 8H8M5 8l3-3M5 8l3 3"/>',
            'drill' => '<path d="M3 7h11a3 3 0 0 1 3 3v2H3zM7 12v7h4v-7M17 9.5h4M3 9v1"/>',
            'hammer' => '<path d="M13 4l7 3.5-2.4 4.4-3-1.6L9.5 21l-3-1.6 5-10.2-3-1.6z"/>',
            'wrench' => '<path d="M14.7 6.3a4 4 0 0 0 5 5L10 21l-3-3 9.7-9.7a4 4 0 0 0-2-2z"/>',
            'saw' => '<path d="M3 16l5-10h13v7H8M8 13v3M12 13v3M16 13v3"/>',
            'roller' => '<path d="M5 4h12v5H5zM17 6.5h3v5h-9v4M10.5 15.5h2v5h-2z"/>',
            'bolt' => '<path d="M7 3h10v5l-2.5 2v8L12 21l-2.5-3v-8L7 8z"/>',
            'bulb' => '<path d="M9 18h6M10 21h4M12 3a6 6 0 0 0-3.5 10.8c.6.5 1 1.2 1 2V16h5v-.2c0-.8.4-1.5 1-2A6 6 0 0 0 12 3z"/>',
            'tape' => '<circle cx="10" cy="12" r="7"/><circle cx="10" cy="12" r="2.5"/><path d="M17 15h4v3h-9"/>',
            'doc' => '<path d="M6 3h8l4 4v14H6zM14 3v4h4M9 12h6M9 16h6"/>',
            'list' => '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>',
            'grid' => '<path d="M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h6v6h-6z"/>',
            'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
        ];
        $inner = isset($paths[$key]) ? $paths[$key] : $paths['bolt'];
        $size = max(12, min(64, $size));

        return '<svg class="tl-ico" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}

if (!function_exists('taller_icon_key')) {
    /** La clave del icono que mejor le va a una categoría por su nombre; sin pista, uno de la serie según su posición. */
    function taller_icon_key(string $name, int $index): string
    {
        $rules = [
            'drill' => '/taladr|drill|electroportatil|power tool|atornill|motor/iu',
            'hammer' => '/martill|hammer|obra|constru|demoli|mano\b|hand tool/iu',
            'wrench' => '/llave|wrench|fontan|plumb|tuber|grifo|bomba/iu',
            'saw' => '/sierra|saw|madera|wood|corte|cutting|carpint/iu',
            'roller' => '/pintur|paint|barniz|varnish|rodillo|brocha/iu',
            'bolt' => '/tornill|screw|bolt|fijaci|fastener|clavo|tuerca|ferreter/iu',
            'bulb' => '/ilumin|light|lamp|bombill|electric|cable/iu',
            'tape' => '/medic|measure|metro|nivel|level|cinta|adhesiv/iu',
        ];
        foreach ($rules as $key => $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return $key;
            }
        }
        $series = ['drill', 'hammer', 'wrench', 'saw', 'roller', 'bolt', 'bulb', 'tape'];

        return $series[abs($index) % count($series)];
    }
}

if (!function_exists('taller_link')) {
    /** Dirección que se puede poner en un enlace: ruta de la tienda, ancla o http(s). Cualquier otra cosa (javascript:, data:…) da «». */
    function taller_link(string $value): string
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

if (!function_exists('taller_clean_menu')) {
    /**
     * Un menú sin los elementos rotos (sin texto, o sin dirección y sin hijos), con los hijos ya limpios.
     *
     * @param array<int,mixed> $items
     *
     * @return array<int,array{label:string,url:string,current:bool,children:array<int,mixed>}>
     */
    function taller_clean_menu(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = isset($item['label']) ? (string) $item['label'] : '';
            $href = isset($item['url']) ? (string) $item['url'] : '';
            $kids = isset($item['children']) && is_array($item['children']) ? taller_clean_menu($item['children']) : [];
            if ($label === '' || ($href === '' && $kids === [])) {
                continue;
            }
            $out[] = ['label' => $label, 'url' => $href, 'current' => !empty($item['current']), 'children' => $kids];
        }

        return $out;
    }
}

if (!function_exists('taller_phone')) {
    /** El teléfono de ayuda de la tienda (Ajustes → Identidad), tal como está escrito; «» si no hay. */
    function taller_phone(): string
    {
        return trim(App::current()->settings()->str('store.phone', ''));
    }
}

if (!function_exists('taller_tel')) {
    /** Dirección «tel:» de un teléfono escrito de cualquier forma. */
    function taller_tel(string $phone): string
    {
        $digits = (string) preg_replace('/[^0-9+]/', '', $phone);

        return $digits !== '' ? 'tel:' . $digits : '';
    }
}

if (!function_exists('taller_can_add')) {
    /** ¿Se puede añadir al carrito desde una tarjeta? Con el módulo de pedidos, o en la página de muestra del tema. */
    function taller_can_add(): bool
    {
        $app = App::current();

        return $app->view()->shared('af_demo') === true || $app->router()->has('cart.add');
    }
}

if (!function_exists('taller_add_action')) {
    /** A dónde envía el formulario de «añadir» de una tarjeta. */
    function taller_add_action(): string
    {
        $app = App::current();

        return $app->router()->has('cart.add') ? route('cart.add') : url('/tema/muestra');
    }
}

if (!function_exists('taller_card_info')) {
    /**
     * Lo que enseña una tarjeta además de lo suyo: la referencia y hasta tres características. Salen de la propia tarjeta
     * si las trae (sku, facts, attributes) y, si no, de la ficha del producto, que el catálogo ya guarda en su caché; el
     * resultado se recuerda en la vista de la petición para no pedirlo dos veces.
     *
     * @param array<string,mixed> $card
     *
     * @return array{ref:string,specs:array<int,array{label:string,value:string}>}
     */
    function taller_card_info(array $card, int $limit = 3): array
    {
        $id = isset($card['id']) ? (int) $card['id'] : 0;
        $app = App::current();
        $view = $app->view();
        $memo = $view->shared('tl_info');
        $memo = is_array($memo) ? $memo : [];
        if (!isset($memo[$id])) {
            $ref = isset($card['sku']) && is_string($card['sku']) ? trim($card['sku']) : '';
            $facts = isset($card['facts']) && is_array($card['facts']) ? $card['facts'] : null;
            $attrs = isset($card['attributes']) && is_array($card['attributes']) ? $card['attributes'] : [];
            if (($ref === '' || $facts === null) && $id > 0 && $app->has('catalog')) {
                try {
                    $full = $app->service('catalog')->product($id);
                } catch (\Throwable $e) {
                    $full = null;
                }
                if (is_array($full)) {
                    if ($ref === '') {
                        $ref = trim((string) (isset($full['sku']) ? $full['sku'] : ''));
                        if ($ref === '') {
                            $ref = trim((string) (isset($full['mpn']) ? $full['mpn'] : ''));
                        }
                    }
                    if ($facts === null) {
                        $facts = isset($full['facts']) && is_array($full['facts']) ? $full['facts'] : [];
                        $attrs = isset($full['attributes']) && is_array($full['attributes']) ? $full['attributes'] : [];
                    }
                }
            }
            $specs = [];
            foreach ($facts === null ? [] : $facts as $fact) {
                if (is_array($fact) && isset($fact['label'], $fact['value']) && (string) $fact['value'] !== '') {
                    $specs[] = ['label' => (string) $fact['label'], 'value' => (string) $fact['value']];
                }
            }
            foreach ($attrs as $attr) {
                if (is_array($attr) && isset($attr['name'], $attr['value']) && (string) $attr['value'] !== '') {
                    $specs[] = ['label' => (string) $attr['name'], 'value' => (string) $attr['value']];
                }
            }
            $memo[$id] = ['ref' => $ref, 'specs' => array_slice($specs, 0, 3)];
            if ($id > 0) {
                $view->share('tl_info', $memo);
            }
        }
        $info = $memo[$id];
        $info['specs'] = array_slice($info['specs'], 0, max(0, $limit));

        return $info;
    }
}

if (!function_exists('taller_doc')) {
    /**
     * Si un dato extra público de la ficha es un documento descargable (tipo file, document, pdf, url o link con una
     * dirección segura), devuelve su texto y su dirección; en otro caso null.
     *
     * @param mixed $extra
     *
     * @return array{label:string,url:string}|null
     */
    function taller_doc($extra): ?array
    {
        if (!is_array($extra) || empty($extra['public']) || !isset($extra['type'], $extra['label'], $extra['value'])) {
            return null;
        }
        if (!in_array((string) $extra['type'], ['file', 'document', 'pdf', 'url', 'link'], true) || !is_scalar($extra['value'])) {
            return null;
        }
        $href = taller_link((string) $extra['value']);

        return $href !== '' && (string) $extra['label'] !== '' ? ['label' => (string) $extra['label'], 'url' => $href] : null;
    }
}

if (!function_exists('taller_home_brands')) {
    /**
     * Las marcas activas para la franja de la portada (las que tienen imagen primero).
     *
     * @return array<int,array<string,mixed>>
     */
    function taller_home_brands(int $limit): array
    {
        $app = App::current();
        if (!$app->has('catalog')) {
            return [];
        }
        try {
            $all = $app->service('catalog')->brands();
        } catch (\Throwable $e) {
            return [];
        }
        $with = [];
        $without = [];
        foreach (is_array($all) ? $all : [] as $brand) {
            if (!is_array($brand) || empty($brand['name']) || empty($brand['url'])) {
                continue;
            }
            if (is_array($brand['image']) && !empty($brand['image']['url'])) {
                $with[] = $brand;
            } else {
                $without[] = $brand;
            }
        }

        return array_slice(array_merge($with, $without), 0, max(0, $limit));
    }
}

if (!function_exists('taller_home_tips')) {
    /**
     * Las últimas entradas del blog para «Consejos»; vacío si el blog no está.
     *
     * @return array<int,array<string,mixed>>
     */
    function taller_home_tips(int $limit): array
    {
        $app = App::current();
        if (!$app->has('cms')) {
            return [];
        }
        try {
            $result = $app->service('cms')->posts(['per_page' => max(1, $limit)]);
        } catch (\Throwable $e) {
            return [];
        }
        $items = is_array($result) && isset($result['items']) && is_array($result['items']) ? $result['items'] : [];

        return array_slice(array_values(array_filter($items, 'is_array')), 0, max(0, $limit));
    }
}
