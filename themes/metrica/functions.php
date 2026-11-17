<?php
/**
 * AIFEC — código del tema Métrica (ferretería): ayudas para sus plantillas. Pictogramas en SVG de los trabajos, limpieza de
 * menús, la línea de medidas de una tarjeta (sale de las variantes o de los atributos numéricos del producto: sin dato no
 * hay línea) y las facetas del mostrador «por medida» de la portada. No registra rutas ni ganchos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

if (!function_exists('metrica_logo_is_text')) {
    /** ¿El logotipo de la tienda es solo su nombre, sin imagen? Entonces la placa lleva delante la tuerca del tema. */
    function metrica_logo_is_text(): bool
    {
        return strpos(shop_logo(), '<' . 'img') === false;
    }
}

if (!function_exists('metrica_svg')) {
    /**
     * Un icono SVG en línea, de trazo. Las claves son fijas: todo el contenido es del propio tema, nunca un dato.
     */
    function metrica_svg(string $key, int $size = 22): string
    {
        static $paths = [
            'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
            'user' => '<circle cx="12" cy="8.5" r="3.8"/><path d="M5 20.5c.6-3.6 3.5-5.4 7-5.4s6.4 1.8 7 5.4"/>',
            'cart' => '<path d="M3 4h2.5l2 11h10.5l2-8H7"/><circle cx="9.5" cy="19" r="1.4"/><circle cx="17" cy="19" r="1.4"/>',
            'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'nut' => '<path d="M7.5 3.5h9L21 12l-4.5 8.5h-9L3 12z"/><circle cx="12" cy="12" r="3.4"/>',
            'hang' => '<path d="M12 3v3M8.5 9 12 6l3.5 3"/><path d="M5 9h14v11H5z"/><path d="m8 17 3-3 2 2 3-3"/>',
            'fix' => '<path d="M3 6h15v3H3zM18 5v5M7 9v5M12 9v9"/><path d="M5.5 14h3M10.5 18h3"/>',
            'cut' => '<path d="M3 16 9 5h12v6H9"/><path d="M9 11v3M12.5 11v3M16 11v3M19.5 11v3"/>',
            'screw' => '<path d="M14.5 3.5 20.5 9.5 17 13l-6-6z"/><path d="M12.5 8.5 4 17l-.5 3.5L7 20l8.5-8.5"/><path d="M8.5 13.5l2 2"/>',
            'measure' => '<path d="M3 8h14a4 4 0 0 1 0 8H3z"/><circle cx="17" cy="12" r="1.3"/><path d="M6 8v3M9 8v2M12 8v3"/>',
        ];
        $inner = isset($paths[$key]) ? $paths[$key] : $paths['nut'];
        $size = max(12, min(64, $size));

        return '<svg class="mt-ico" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}

if (!function_exists('metrica_job_icon')) {
    /** La clave del pictograma que le va a un trabajo por su nombre; sin pista, la tuerca. */
    function metrica_job_icon(string $name): string
    {
        $rules = [
            'hang' => '/colg|cuadro|espejo|hang/iu',
            'cut' => '/cort|serr|sierra|cut|saw/iu',
            'screw' => '/atornill|destornill|montar|mueble|screw|assembl/iu',
            'measure' => '/medi|nivel|marcar|measur|level/iu',
            'fix' => '/fij|anclar|estanter|balda|taco|fix|fasten|shelf/iu',
        ];
        foreach ($rules as $key => $pattern) {
            if (preg_match($pattern, $name) === 1) {
                return $key;
            }
        }

        return 'nut';
    }
}

if (!function_exists('metrica_clean_menu')) {
    /**
     * Un menú sin los elementos rotos (sin texto, o sin dirección y sin hijos), con los hijos ya limpios.
     *
     * @param array<int,mixed> $items
     *
     * @return array<int,array{label:string,url:string,current:bool,children:array<int,mixed>}>
     */
    function metrica_clean_menu(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = isset($item['label']) ? trim((string) $item['label']) : '';
            $href = isset($item['url']) ? (string) $item['url'] : '';
            $kids = isset($item['children']) && is_array($item['children']) ? metrica_clean_menu($item['children']) : [];
            if ($label === '' || ($href === '' && $kids === [])) {
                continue;
            }
            $out[] = ['label' => $label, 'url' => $href, 'current' => !empty($item['current']), 'children' => $kids];
        }

        return $out;
    }
}

if (!function_exists('metrica_can_add')) {
    /** ¿Se puede añadir al carrito desde una tarjeta? Con el módulo de pedidos, o en la página de muestra del tema. */
    function metrica_can_add(): bool
    {
        $app = App::current();

        return $app->view()->shared('af_demo') === true || $app->router()->has('cart.add');
    }
}

if (!function_exists('metrica_add_action')) {
    /** A dónde envía el formulario de «añadir» de una tarjeta. */
    function metrica_add_action(): string
    {
        $app = App::current();

        return $app->router()->has('cart.add') ? route('cart.add') : url('/tema/muestra');
    }
}

if (!function_exists('metrica_range')) {
    /**
     * Los valores de una opción en una línea corta. Una escala (todos llevan cifra y hay más de dos) se escribe como un
     * tramo, «M4 – M10» o «16 – 60 mm» (la unidad común se dice una vez); lo demás, sus primeros valores.
     *
     * @param string[] $labels
     */
    function metrica_range(array $labels): string
    {
        $labels = array_values(array_filter(array_map('trim', $labels), static function (string $label): bool {
            return $label !== '';
        }));
        $n = count($labels);
        if ($n === 0) {
            return '';
        }
        if ($n === 1) {
            return $labels[0];
        }
        $scale = true;
        foreach ($labels as $label) {
            if (preg_match('/\d/', $label) !== 1) {
                $scale = false;
                break;
            }
        }
        if ($scale && $n > 2) {
            $first = $labels[0];
            $last = $labels[$n - 1];
            if (preg_match('/^(.*\d)\s*(\D+)$/u', $first, $a) === 1 && preg_match('/^(.*\d)\s*(\D+)$/u', $last, $b) === 1 && trim($a[2]) === trim($b[2])) {
                $first = $a[1];
            }

            return $first . ' – ' . $last;
        }
        $shown = array_slice($labels, 0, 3);

        return implode($scale ? ' / ' : ', ', $shown) . ($n > 3 ? ' +' . ($n - 3) : '');
    }
}

if (!function_exists('metrica_measures')) {
    /**
     * La línea de medidas de una tarjeta y cuántas medidas tiene el producto. Con variantes sale de sus opciones (las trae
     * la propia tarjeta en «buy» o, si no, la ficha, que el catálogo guarda en su caché); sin variantes, de sus atributos
     * numéricos con unidad. Sin dato devuelve la línea vacía y la tarjeta no la pinta. El resultado se recuerda en la vista
     * de la petición para no pedirlo dos veces.
     *
     * @param array<string,mixed> $card
     *
     * @return array{line:string,count:int}
     */
    function metrica_measures(array $card): array
    {
        $id = isset($card['id']) ? (int) $card['id'] : 0;
        $app = App::current();
        $view = $app->view();
        $memo = $view->shared('mt_measures');
        $memo = is_array($memo) ? $memo : [];
        if ($id > 0 && isset($memo[$id]) && is_array($memo[$id])) {
            return $memo[$id];
        }
        $variants = !empty($card['has_variants']);
        $options = null;
        $attributes = [];
        $count = 0;
        $buy = isset($card['buy']) && is_array($card['buy']) ? $card['buy'] : null;
        if ($variants && $buy !== null && isset($buy['options'], $buy['variants']) && is_array($buy['options']) && is_array($buy['variants']) && $buy['options'] !== []) {
            $options = $buy['options'];
            $count = count($buy['variants']);
        }
        if ($options === null && $id > 0 && $app->has('catalog')) {
            try {
                $full = $app->service('catalog')->product($id);
            } catch (\Throwable $e) {
                $full = null;
            }
            if (is_array($full)) {
                if ($variants) {
                    $options = isset($full['options']) && is_array($full['options']) ? $full['options'] : [];
                    $count = isset($full['variants']) && is_array($full['variants']) ? count($full['variants']) : 0;
                } else {
                    $attributes = isset($full['attributes']) && is_array($full['attributes']) ? $full['attributes'] : [];
                }
            }
        }
        $parts = [];
        foreach (array_slice($options === null ? [] : $options, 0, 3) as $option) {
            $labels = [];
            foreach (is_array($option) && isset($option['values']) && is_array($option['values']) ? $option['values'] : [] as $value) {
                if (is_array($value) && isset($value['label'])) {
                    $labels[] = (string) $value['label'];
                }
            }
            $range = metrica_range($labels);
            if ($range !== '') {
                $parts[] = $range;
            }
        }
        foreach ($attributes as $attribute) {
            if (count($parts) >= 2) {
                break;
            }
            if (is_array($attribute) && isset($attribute['type'], $attribute['name'], $attribute['value']) && $attribute['type'] === 'number'
                && isset($attribute['unit']) && (string) $attribute['unit'] !== '' && (string) $attribute['value'] !== '') {
                $parts[] = (string) $attribute['name'] . ' ' . (string) $attribute['value'];
            }
        }
        $out = ['line' => implode(' · ', $parts), 'count' => $count];
        if ($id > 0) {
            $memo[$id] = $out;
            $view->share('mt_measures', $memo);
        }

        return $out;
    }
}

if (!function_exists('metrica_home_measures')) {
    /**
     * El mostrador «por medida» de la portada: la primera categoría de primer nivel cuyo listado tiene alguna de las
     * facetas pedidas (los códigos de atributo de la opción «measure_facets»), con sus valores y su recuento. Sale de la
     * caché del sitio (etiqueta «catalog»); null si no hay catálogo o ninguna categoría tiene esas facetas.
     *
     * @return array{name:string,url:string,facets:array<int,array<string,mixed>>}|null
     */
    function metrica_home_measures(string $codes): ?array
    {
        $app = App::current();
        if (!$app->has('catalog')) {
            return null;
        }
        $want = [];
        foreach (explode(',', strtolower($codes)) as $code) {
            $code = trim($code);
            if ($code !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,62}$/', $code) === 1 && !in_array($code, $want, true)) {
                $want[] = $code;
            }
        }
        if ($want === []) {
            return null;
        }
        try {
            $data = $app->cache()->remember('mt:measures:' . $app->lang() . ':' . md5(implode(',', $want)), 600, static function () use ($app, $want): array {
                $catalog = $app->service('catalog');
                $roots = $catalog->tree();
                foreach (array_slice(is_array($roots) ? array_values($roots) : [], 0, 6) as $root) {
                    if (!is_array($root) || empty($root['id']) || empty($root['url']) || !isset($root['name'])) {
                        continue;
                    }
                    $result = $catalog->listing(['category_id' => (int) $root['id'], 'per_page' => 1]);
                    $found = [];
                    foreach (is_array($result) && isset($result['facets']) && is_array($result['facets']) ? $result['facets'] : [] as $facet) {
                        if (!is_array($facet) || !isset($facet['code'], $facet['name'], $facet['values']) || !is_array($facet['values']) || !in_array((string) $facet['code'], $want, true)) {
                            continue;
                        }
                        $values = [];
                        foreach ($facet['values'] as $value) {
                            if (is_array($value) && isset($value['code'], $value['label']) && (int) (isset($value['count']) ? $value['count'] : 0) > 0) {
                                $values[] = ['code' => (string) $value['code'], 'label' => (string) $value['label'], 'count' => (int) $value['count']];
                            }
                        }
                        if ($values !== []) {
                            $found[(string) $facet['code']] = ['code' => (string) $facet['code'], 'name' => (string) $facet['name'], 'values' => $values];
                        }
                    }
                    if ($found !== []) {
                        $facets = [];
                        foreach ($want as $code) {
                            if (isset($found[$code])) {
                                $facets[] = $found[$code];
                            }
                        }

                        return ['name' => (string) $root['name'], 'url' => (string) $root['url'], 'facets' => $facets];
                    }
                }

                return [];
            }, ['catalog']);
        } catch (\Throwable $e) {
            return null;
        }

        return is_array($data) && isset($data['url'], $data['facets']) && is_array($data['facets']) && $data['facets'] !== [] ? $data : null;
    }
}
