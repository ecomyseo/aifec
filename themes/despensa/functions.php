<?php
/**
 * AIFEC — código del tema despensa (gourmet y alimentación): ayudas para sus plantillas. Iconos y emblema dibujados en
 * SVG, enlaces seguros, imagen de una opción del tema, lo que enseña la tarjeta (tinte por categoría, origen, formato y
 * precio por kilo o por litro) y las entradas del blog de la portada. No registra rutas: todo lo demás lo hereda del tema base.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

if (!function_exists('despensa_svg')) {
    /**
     * Un icono SVG en línea (trazo, sin relleno). Las claves son fijas: todo el contenido es del propio tema, nunca un dato.
     */
    function despensa_svg(string $key, int $size = 22): string
    {
        static $paths = [
            'menu' => '<path d="M4 7h16M4 12h16M4 17h10"/>',
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
            'user' => '<circle cx="12" cy="8.5" r="3.8"/><path d="M5 20.5c.6-3.6 3.5-5.4 7-5.4s6.4 1.8 7 5.4"/>',
            'basket' => '<path d="M4 9h16l-1.6 9.5a2 2 0 0 1-2 1.5H7.6a2 2 0 0 1-2-1.5L4 9Z"/><path d="M8 9l3-5M16 9l-3-5"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'filter' => '<path d="M4 7h9M17 7h3M4 17h3M11 17h9"/><circle cx="15" cy="7" r="2"/><circle cx="9" cy="17" r="2"/>',
            'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'pin' => '<path d="M12 21s6.5-5.6 6.5-11a6.5 6.5 0 0 0-13 0c0 5.4 6.5 11 6.5 11Z"/><circle cx="12" cy="10" r="2.3"/>',
            'gift' => '<path d="M4 10h16v10H4zM3 7h18v3H3zM12 7v13M12 7c-1-3-5-3.5-5-1.2C7 7.2 9 7 12 7Zm0 0c1-3 5-3.5 5-1.2C17 7.2 15 7 12 7Z"/>',
            'jar' => '<path d="M8 3h8v3H8zM7 6h10l1 4v9a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2v-9l1-4Z"/><path d="M6 12h12"/>',
            'leaf' => '<path d="M5 19c0-8 5-14 14-14 0 9-6 14-14 14Zm0 0 7-7"/>',
        ];
        $inner = isset($paths[$key]) ? $paths[$key] : $paths['leaf'];
        $size = max(12, min(64, $size));

        return '<svg class="ds-ico" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}

if (!function_exists('despensa_emblem')) {
    /** El emblema de la cabecera: una ramita de olivo dibujada en trazo. Todo el contenido es del propio tema. */
    function despensa_emblem(int $size = 40): string
    {
        $size = max(16, min(96, $size));

        return '<svg class="ds-emblem-svg" viewBox="0 0 40 40" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">'
            . '<path d="M9 31c6-1 11-6 14-15M23 16c1-3 1-6 0-9"/>'
            . '<path d="M13 26c-3-1-5-3-5.5-6 3 0 5 2 5.5 6ZM17 21c-3-1-5-3-5.5-6 3 0 5 2 5.5 6ZM21 16c-2.5-1-4-3-4.5-5.5 2.5 0 4 2 4.5 5.5ZM16 27c3 0 5-2 6-5-3 0-5 2-6 5ZM21 22c3 0 5-2 6-5-3 0-5 2-6 5ZM25 17c2.5 0 4.5-1.5 5.5-4.5-2.5 0-4.5 1.5-5.5 4.5Z"/>'
            . '<circle cx="29" cy="26" r="2.2"/><circle cx="25.5" cy="29.5" r="2.2"/>'
            . '</svg>';
    }
}

if (!function_exists('despensa_link')) {
    /**
     * Dirección que se puede poner en un enlace a partir de un texto libre de una opción: una ruta de la propia tienda
     * («/novedades»), un ancla («#novedades») o una dirección http(s). Cualquier otra cosa (javascript:, data:…) da «».
     */
    function despensa_link(string $value): string
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

if (!function_exists('despensa_image')) {
    /**
     * La imagen de la biblioteca que el comerciante ha elegido en una opción del tema (la de la historia de la portada).
     * Sin elección, sin módulo de medios o con un identificador que ya no existe, devuelve null y la plantilla usa su dibujo.
     *
     * @return array<string,mixed>|null
     */
    function despensa_image(string $key): ?array
    {
        $id = theme_option($key, '');
        if (!is_numeric($id) || (int) $id <= 0) {
            return null;
        }
        $app = App::current();
        if (!$app->has('media')) {
            return null;
        }
        try {
            $image = $app->service('media')->image((int) $id);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($image) && !empty($image['url']) ? $image : null;
    }
}

if (!function_exists('despensa_can_add')) {
    /** ¿Se puede añadir al carrito desde una tarjeta? Con el módulo de pedidos, o en la página de muestra del tema. */
    function despensa_can_add(): bool
    {
        $app = App::current();

        return $app->view()->shared('af_demo') === true || $app->router()->has('cart.add');
    }
}

if (!function_exists('despensa_add_action')) {
    /** A dónde envía el formulario de «añadir» de una tarjeta. */
    function despensa_add_action(): string
    {
        $app = App::current();

        return $app->router()->has('cart.add') ? route('cart.add') : url('/tema/muestra');
    }
}

if (!function_exists('despensa_amount')) {
    /** Un número con los decimales justos (500, 1,5, 1,25) y el separador del idioma de la tienda. */
    function despensa_amount(float $value): string
    {
        $rounded = round($value, 2);
        $decimals = abs($rounded - round($rounded)) < 0.005 ? 0 : (abs($rounded * 10 - round($rounded * 10)) < 0.005 ? 1 : 2);

        return App::current()->i18n()->number($rounded, $decimals);
    }
}

if (!function_exists('despensa_measure')) {
    /**
     * El peso o el volumen de un producto, en la unidad base (gramos o mililitros), y de qué magnitud es.
     * Sale del peso de la ficha (gramos) o de un atributo numérico con unidad g, kg, ml, cl o l.
     *
     * @param array<string,mixed> $product ficha o tarjeta: weight (g) y attributes
     *
     * @return array{kind:string,base:int}|null kind es «mass» o «volume»
     */
    function despensa_measure(array $product): ?array
    {
        $attrs = isset($product['attributes']) && is_array($product['attributes']) ? $product['attributes'] : [];
        $factors = ['g' => ['mass', 1.0], 'kg' => ['mass', 1000.0], 'ml' => ['volume', 1.0], 'cl' => ['volume', 10.0], 'l' => ['volume', 1000.0]];
        foreach ($attrs as $attr) {
            if (!is_array($attr) || !isset($attr['number'], $attr['unit']) || !is_numeric($attr['number'])) {
                continue;
            }
            $unit = strtolower(trim((string) $attr['unit']));
            if (isset($factors[$unit]) && (float) $attr['number'] > 0) {
                return ['kind' => $factors[$unit][0], 'base' => (int) round((float) $attr['number'] * $factors[$unit][1])];
            }
        }
        $grams = isset($product['weight']) ? (int) $product['weight'] : 0;

        return $grams > 0 ? ['kind' => 'mass', 'base' => $grams] : null;
    }
}

if (!function_exists('despensa_format_measure')) {
    /** «500 g», «1,5 kg», «250 ml», «1,5 l». */
    function despensa_format_measure(string $kind, int $base): string
    {
        if ($base < 1000) {
            return despensa_amount((float) $base) . "\u{a0}" . ($kind === 'mass' ? 'g' : 'ml');
        }

        return despensa_amount($base / 1000) . "\u{a0}" . ($kind === 'mass' ? 'kg' : 'l');
    }
}

if (!function_exists('despensa_unit_price')) {
    /**
     * El precio por kilo o por litro, ya formateado («12,50 € / kg»), o «» si no hay medida, no hay precio o el producto
     * se vende por variantes con precios distintos.
     *
     * @param array{kind:string,base:int}|null $measure
     */
    function despensa_unit_price(int $amount, ?array $measure, ?string $currency = null): string
    {
        if ($measure === null || $amount <= 0 || $measure['base'] <= 0) {
            return '';
        }
        $cents = intdiv($amount * 1000 + intdiv($measure['base'], 2), $measure['base']);

        if ($measure['kind'] === 'mass') {
            return t('{price} / kg', ['price' => money($cents, $currency)]);
        }

        return t('{price} / l', ['price' => money($cents, $currency)]);
    }
}

if (!function_exists('despensa_fact')) {
    /**
     * El valor del primer dato clave cuya etiqueta encaja con la expresión («Origen: Jaén»); «» si no hay ninguno.
     *
     * @param array<int,mixed> $facts
     */
    function despensa_fact(array $facts, string $pattern): string
    {
        foreach ($facts as $fact) {
            if (is_array($fact) && isset($fact['label'], $fact['value']) && trim((string) $fact['value']) !== '' && preg_match($pattern, (string) $fact['label']) === 1) {
                return trim((string) $fact['value']);
            }
        }

        return '';
    }
}

if (!function_exists('despensa_card_info')) {
    /**
     * Lo que enseña una tarjeta además de lo suyo: el tinte (según la categoría principal), la etiqueta de origen, el
     * formato y el precio por kilo o litro. Salen de la propia tarjeta si los trae (weight, attributes, facts,
     * main_category) y, si no, de la ficha del producto, que el catálogo ya guarda en su caché; el resultado se recuerda
     * en la vista de la petición.
     *
     * @param array<string,mixed> $card
     *
     * @return array{tone:int,origin:string,format:string,unit:string}
     */
    function despensa_card_info(array $card): array
    {
        $id = isset($card['id']) ? (int) $card['id'] : 0;
        $app = App::current();
        $view = $app->view();
        $memo = $view->shared('ds_info');
        $memo = is_array($memo) ? $memo : [];
        if ($id > 0 && isset($memo[$id])) {
            return $memo[$id];
        }
        $own = isset($card['weight']) || isset($card['facts']) || isset($card['attributes']) || isset($card['main_category']);
        $data = $card;
        if (!$own && $id > 0 && $app->has('catalog')) {
            try {
                $full = $app->service('catalog')->product($id);
            } catch (\Throwable $e) {
                $full = null;
            }
            if (is_array($full)) {
                $data = $full + $card;
            }
        }
        $facts = isset($data['facts']) && is_array($data['facts']) ? $data['facts'] : [];
        $category = isset($data['main_category']) && is_array($data['main_category']) ? $data['main_category'] : [];
        $categoryId = isset($category['id']) ? (int) $category['id'] : 0;
        $tone = $categoryId > 0 ? 1 + (($categoryId - 1) % 6) : ($id > 0 ? 1 + (($id - 1) % 6) : 1);
        $measure = despensa_measure($data);
        $format = despensa_fact($facts, '/formato|format|peso|weight|contenido|content|capacidad|capacity|tama[ñn]o|size/iu');
        if ($format === '' && $measure !== null) {
            $format = despensa_format_measure($measure['kind'], $measure['base']);
        }
        $price = isset($card['price']) && is_array($card['price']) ? $card['price'] : [];
        $unit = '';
        if (empty($price['from']) && !empty($price['amount'])) {
            $currency = isset($price['currency']) && is_string($price['currency']) && $price['currency'] !== '' ? $price['currency'] : null;
            $unit = despensa_unit_price((int) $price['amount'], $measure, $currency);
        }
        $info = [
            'tone' => $tone,
            'origin' => despensa_fact($facts, '/origen|origin|procedencia|provenance|regi[oó]n|region|denominaci/iu'),
            'format' => $format,
            'unit' => $unit,
        ];
        if ($id > 0) {
            $memo[$id] = $info;
            $view->share('ds_info', $memo);
        }

        return $info;
    }
}

if (!function_exists('despensa_menu')) {
    /**
     * Los elementos de un menú que de verdad se pueden pintar: con texto y con dirección o con hijos pintables. Así no sale
     * nunca un enlace roto ni un grupo vacío.
     *
     * @param array<int,array<string,mixed>> $items
     * @param int                            $depth niveles que se admiten por debajo de este
     *
     * @return array<int,array{label:string,url:string,current:bool,children:array<int,mixed>}>
     */
    function despensa_menu(array $items, int $depth = 2): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = isset($item['label']) ? (string) $item['label'] : '';
            $href = isset($item['url']) ? (string) $item['url'] : '';
            $kids = $depth > 0 && isset($item['children']) && is_array($item['children']) ? despensa_menu($item['children'], $depth - 1) : [];
            if ($label === '' || ($href === '' && $kids === [])) {
                continue;
            }
            $out[] = ['label' => $label, 'url' => $href, 'current' => !empty($item['current']), 'children' => $kids];
        }

        return $out;
    }
}

if (!function_exists('despensa_home_posts')) {
    /**
     * Las últimas entradas del blog para «Recetas»; vacío si el blog no está.
     *
     * @return array<int,array<string,mixed>>
     */
    function despensa_home_posts(int $limit): array
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

return static function (App $app): void {
    // El resumen de cada tarjeta vale solo para esa petición: una misma aplicación puede atender varias seguidas.
    $app->hooks()->on('request.end', static function () use ($app): void {
        $app->view()->share('ds_info', []);
    });
};
