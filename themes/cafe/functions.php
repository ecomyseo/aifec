<?php
/**
 * AIFEC — código del tema Café (cápsulas y café): ayudas para sus plantillas. Iconos, pictogramas y emblema dibujados en
 * SVG; lo que el tema sabe de un café (para qué cafetera vale, intensidad, tueste, tazas, notas y precio por unidad o por
 * kilo), que sale siempre de los atributos reales del producto; y la regleta de sistemas, que sale de las facetas del
 * catálogo. Sin dato, la plantilla no pinta la pieza. No registra rutas: todo lo demás lo hereda del tema base.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

if (!function_exists('cafe_svg')) {
    /**
     * Un dibujo SVG en línea (trazo, sin relleno): iconos de la cabecera y pictogramas de cápsulas y cafeteras.
     * Las claves son fijas: todo el contenido es del propio tema, nunca un dato.
     */
    function cafe_svg(string $key, int $size = 22): string
    {
        static $paths = [
            'menu' => '<path d="M4 7h16M4 12h16M4 17h16"/>',
            'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4.2-4.2"/>',
            'user' => '<circle cx="12" cy="8.5" r="3.8"/><path d="M5 20.5c.6-3.6 3.5-5.4 7-5.4s6.4 1.8 7 5.4"/>',
            'bag' => '<path d="M6 8h12l1 12H5L6 8Z"/><path d="M9 11V7a3 3 0 0 1 6 0v4"/>',
            'close' => '<path d="M6 6l12 12M18 6 6 18"/>',
            'plus' => '<path d="M12 5v14M5 12h14"/>',
            'arrow' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
            'capsule' => '<path d="M8 5h8l2 14H6L8 5Z"/><path d="M9 9h6"/>',
            'cone' => '<path d="M9 4h6l3 15H6L9 4Z"/><path d="M5 19h14"/>',
            'dome' => '<path d="M4 18c0-6 3.5-11 8-11s8 5 8 11Z"/><path d="M3 18h18"/>',
            'cupw' => '<path d="M5 6h14l-2 13H7L5 6Z"/><path d="M4 6h16"/>',
            'disc' => '<ellipse cx="12" cy="13" rx="8" ry="4"/><path d="M4 13v-2c0-2.2 3.6-4 8-4s8 1.8 8 4v2"/>',
            'pad' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4.5"/>',
            'cyl' => '<path d="M8 4h8v15a1 1 0 0 1-1 1H9a1 1 0 0 1-1-1V4Z"/><path d="M7 4h10"/>',
            'paper' => '<circle cx="12" cy="12" r="8" stroke-dasharray="2 2.4"/><circle cx="12" cy="12" r="4"/>',
            'machine' => '<path d="M5 4h14v16H5zM5 9h14M10 13h4v3h-4z"/>',
            'lever' => '<path d="M4 6h12v5H4zM7 11v3h6v-3M10 14v3M7 20h8M16 8h4"/>',
            'moka' => '<path d="M9 3h6l1 7-3 1 3 9H8l3-9-3-1 1-7ZM16 6h3"/>',
            'drip' => '<path d="M5 5h14l-5 7v3h-4v-3L5 5ZM8 20h8M12 15v2"/>',
            'press' => '<path d="M7 7h10v13H7zM12 3v8M9 11h6M17 10h3v6h-3"/>',
            'bean' => '<ellipse cx="12" cy="12" rx="6" ry="8.5" transform="rotate(28 12 12)"/><path d="M8.5 17.5c2-2.5 1-5 3.5-7s3-3.5 3.5-4"/>',
        ];
        $inner = isset($paths[$key]) ? $paths[$key] : $paths['capsule'];
        $size = max(12, min(64, $size));

        return '<svg class="cf-ico" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">' . $inner . '</svg>';
    }
}

if (!function_exists('cafe_logo_is_text')) {
    /** ¿El logotipo de la tienda es solo su nombre, sin imagen? Entonces el tema le pone su emblema delante. */
    function cafe_logo_is_text(): bool
    {
        return strpos(shop_logo(), '<' . 'img') === false;
    }
}

if (!function_exists('cafe_emblem')) {
    /** El emblema de la marca: una taza con su humo dentro de un círculo tostado. Todo el contenido es del propio tema. */
    function cafe_emblem(int $size = 44): string
    {
        $size = max(20, min(96, $size));

        return '<svg class="cf-emblem" viewBox="0 0 48 48" width="' . $size . '" height="' . $size . '" aria-hidden="true" focusable="false">'
            . '<circle class="cf-emblem-disc" cx="24" cy="24" r="24"/>'
            . '<path class="cf-emblem-line" d="M13 24h18v4a8 8 0 0 1-8 8h-2a8 8 0 0 1-8-8v-4ZM31 26h2.5a3 3 0 0 1 0 6H30M11 39h22M18 19c-1.6-2 1.6-3 0-5.5M23.5 19c-1.6-2 1.6-3 0-5.5"/>'
            . '</svg>';
    }
}

if (!function_exists('cafe_lower')) {
    /** Minúsculas que respetan las tildes cuando el servidor trae mbstring. */
    function cafe_lower(string $text): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    }
}

if (!function_exists('cafe_pict')) {
    /**
     * Qué pictograma le toca a un sistema o a una cafetera, por las palabras de su código y de su nombre (cono, cúpula,
     * disco, italiana, prensa…). Si ninguna encaja, la cápsula genérica.
     */
    function cafe_pict(string $code, string $label): string
    {
        $hay = cafe_lower($code . ' ' . $label);
        $map = [
            'dome' => '/c[uú]pula|dome/u',
            'cone' => '/cono|cone/u',
            'cupw' => '/vaso|ancho|wide/u',
            'disc' => '/disco|disc/u',
            'pad' => '/blanda|soft|\bpad/u',
            'cyl' => '/cilindr|cylind/u',
            'paper' => '/papel|paper|\bese\b|44/u',
            'machine' => '/super|autom/u',
            'moka' => '/italiana|moka/u',
            'drip' => '/goteo|filtro|drip|filter/u',
            // «Espresso» lleva «press» dentro: la de brazo se mira antes y la prensa pide la palabra entera.
            'lever' => '/brazo|espresso|lever/u',
            'press' => '/prensa|\bpress|[eé]mbolo/u',
        ];
        foreach ($map as $key => $pattern) {
            if (preg_match($pattern, $hay) === 1) {
                return $key;
            }
        }

        return 'capsule';
    }
}

if (!function_exists('cafe_can_add')) {
    /** ¿Se puede añadir al carrito desde una tarjeta? Con el módulo de pedidos, o en la página de muestra del tema. */
    function cafe_can_add(): bool
    {
        $app = App::current();

        return $app->view()->shared('af_demo') === true || $app->router()->has('cart.add');
    }
}

if (!function_exists('cafe_add_action')) {
    /** A dónde envía el formulario de «añadir» de una tarjeta. */
    function cafe_add_action(): string
    {
        $app = App::current();

        return $app->router()->has('cart.add') ? route('cart.add') : url('/tema/muestra');
    }
}

if (!function_exists('cafe_attr_kind')) {
    /**
     * Qué dice un atributo del producto para este tema: «system» (para qué cafetera vale), «intensity», «roast», «cups»,
     * «notes», «units» (cápsulas o piezas del paquete), «weight» (peso neto o contenido, en g o kg) o «» si no es ninguno.
     * Se reconoce por su código y su nombre; el del sistema, además, se puede fijar en las opciones del tema.
     *
     * @param array<string,mixed> $attr   code, name, type, unit
     * @param string              $system código del atributo de sistema elegido en las opciones («» = reconocerlo solo)
     */
    function cafe_attr_kind(array $attr, string $system = ''): string
    {
        $code = strtolower(isset($attr['code']) ? (string) $attr['code'] : '');
        $hay = cafe_lower($code . ' ' . (isset($attr['name']) ? (string) $attr['name'] : ''));
        $type = isset($attr['type']) ? (string) $attr['type'] : '';
        if ($system !== '') {
            if ($code === $system) {
                return 'system';
            }
        } elseif (preg_match('/cafetera|sistema|system|machine|brewer|compatib/u', $hay) === 1) {
            return 'system';
        }
        if (preg_match('/intensidad|intensity/u', $hay) === 1) {
            return 'intensity';
        }
        if ($type === 'number') {
            // El peso neto, no cualquier peso: el que aguanta una silla («Peso máximo») no da un precio por kilo.
            $unit = strtolower(trim(isset($attr['unit']) ? (string) $attr['unit'] : ''));
            if (($unit === 'g' || $unit === 'kg') && preg_match('/neto|\bnet\b|contenido|content/u', $hay) === 1) {
                return 'weight';
            }

            return preg_match('/unidades|units|c[aá]psulas|capsules|piezas|pieces|dosis|pods/u', $hay) === 1 ? 'units' : '';
        }
        if (preg_match('/tueste|roast/u', $hay) === 1) {
            return 'roast';
        }
        if (preg_match('/taza|cup/u', $hay) === 1) {
            return 'cups';
        }

        return preg_match('/nota|notes|cata|tasting/u', $hay) === 1 ? 'notes' : '';
    }
}

if (!function_exists('cafe_roast_step')) {
    /** El tueste en cuatro escalones, del más claro (1) al más oscuro (4); 0 si el nombre no dice cuál es. */
    function cafe_roast_step(string $label): int
    {
        $l = cafe_lower($label);
        if (preg_match('/muy\s+oscuro|very\s+dark|extra/u', $l) === 1) {
            return 4;
        }
        if (preg_match('/oscuro|dark/u', $l) === 1) {
            return 3;
        }
        if (preg_match('/medio|medium/u', $l) === 1) {
            return 2;
        }

        return preg_match('/claro|light|rubio|blonde/u', $l) === 1 ? 1 : 0;
    }
}

if (!function_exists('cafe_scale_max')) {
    /** El tope de la escala de intensidad (opción del tema, de 5 a 13). */
    function cafe_scale_max(): int
    {
        return max(5, min(13, (int) theme_option('scale_max', 13)));
    }
}

if (!function_exists('cafe_rail')) {
    /**
     * La regleta: los sistemas y cafeteras para los que hay café, sacados de la faceta del atributo de sistema. Cada uno
     * enlaza al listado de la categoría principal que más cafés tiene para él, ya filtrado. Sin catálogo, sin ese atributo
     * (o si no es filtrable) o sin productos, lista vacía. Se guarda en la caché del sitio con la etiqueta «catalog».
     *
     * @return array{name:string,code:string,items:array<int,array{code:string,label:string,count:int,url:string,pict:string}>}
     */
    function cafe_rail(): array
    {
        $empty = ['name' => '', 'code' => '', 'items' => []];
        $app = App::current();
        $view = $app->view();
        $memo = $view->shared('cf_rail');
        if (is_array($memo) && isset($memo['items'])) {
            return $memo;
        }
        if (!$app->has('catalog')) {
            return $empty;
        }
        $system = strtolower(trim((string) theme_option('system_attr', '')));
        $build = static function () use ($app, $system, $empty): array {
            $catalog = $app->service('catalog');
            $rail = $empty;
            $best = [];
            $order = [];
            foreach (array_slice(array_values(array_filter($catalog->tree(), 'is_array')), 0, 6) as $root) {
                if (!isset($root['id'], $root['url'])) {
                    continue;
                }
                $result = $catalog->listing(['category_id' => (int) $root['id'], 'per_page' => 1]);
                foreach (isset($result['facets']) && is_array($result['facets']) ? $result['facets'] : [] as $facet) {
                    if (!is_array($facet) || !isset($facet['code'], $facet['values']) || cafe_attr_kind($facet, $system) !== 'system') {
                        continue;
                    }
                    if ($rail['code'] !== '' && $rail['code'] !== (string) $facet['code']) {
                        continue;
                    }
                    $rail['code'] = (string) $facet['code'];
                    $rail['name'] = isset($facet['name']) ? (string) $facet['name'] : '';
                    foreach (is_array($facet['values']) ? $facet['values'] : [] as $value) {
                        $code = isset($value['code']) ? (string) $value['code'] : '';
                        $count = isset($value['count']) ? (int) $value['count'] : 0;
                        if ($code === '' || $count <= 0) {
                            continue;
                        }
                        if (!isset($best[$code])) {
                            $order[] = $code;
                        }
                        if (!isset($best[$code]) || $count > $best[$code]['count']) {
                            $label = isset($value['label']) ? (string) $value['label'] : $code;
                            $best[$code] = [
                                'code' => $code,
                                'label' => $label,
                                'count' => $count,
                                'url' => (string) $root['url'] . '?' . http_build_query(['f' => [$rail['code'] => [$code]]]),
                                'pict' => cafe_pict($code, $label),
                            ];
                        }
                    }
                }
            }
            foreach ($order as $code) {
                $rail['items'][] = $best[$code];
            }

            return $rail;
        };
        try {
            $rail = $app->cache()->remember('cafe:rail:' . $app->lang() . ':' . md5($system), 600, $build, ['catalog']);
        } catch (\Throwable $e) {
            $rail = $empty;
        }
        $rail = is_array($rail) && isset($rail['items']) && is_array($rail['items']) ? $rail : $empty;
        $view->share('cf_rail', $rail);

        return $rail;
    }
}

if (!function_exists('cafe_info')) {
    /**
     * Lo que el tema enseña de un café además de lo suyo, sacado de sus atributos: para qué sistemas vale (nombres y, si
     * están en la regleta, códigos y direcciones), intensidad, tueste, tazas, notas, unidades del paquete, peso neto y el
     * precio por unidad o por kilo ya escrito. Vale para una tarjeta (pide la ficha al catálogo, que la guarda en su
     * caché) y para una ficha. Lo que el producto no trae queda vacío y la plantilla no lo pinta.
     *
     * @param array<string,mixed>      $product tarjeta o ficha
     * @param array<string,mixed>|null $price   bloque de precio que se enseña (por defecto, el del producto)
     *
     * @return array{systems:array<int,array{label:string,code:string,url:string,pict:string}>,intensity:int,roast:string,roast_step:int,cups:array<int,string>,notes:string,units:int,grams:int,unit_price:string}
     */
    function cafe_info(array $product, ?array $price = null): array
    {
        $id = isset($product['id']) ? (int) $product['id'] : 0;
        $app = App::current();
        $view = $app->view();
        $memo = $view->shared('cf_info');
        $memo = is_array($memo) ? $memo : [];
        $key = $id . ($price !== null ? ':p' : ':c');
        if ($id > 0 && isset($memo[$key])) {
            return $memo[$key];
        }
        $data = $product;
        if (!isset($product['attributes']) && $id > 0 && $app->has('catalog')) {
            try {
                $full = $app->service('catalog')->product($id);
            } catch (\Throwable $e) {
                $full = null;
            }
            if (is_array($full)) {
                $data = $full + $product;
            }
        }
        $info = ['systems' => [], 'intensity' => 0, 'roast' => '', 'roast_step' => 0, 'cups' => [], 'notes' => '', 'units' => 0, 'grams' => 0, 'unit_price' => ''];
        $system = strtolower(trim((string) theme_option('system_attr', '')));
        $split = static function (string $text): array {
            return array_values(array_filter(array_map('trim', explode(',', $text)), static function (string $part): bool {
                return $part !== '';
            }));
        };
        foreach (isset($data['attributes']) && is_array($data['attributes']) ? $data['attributes'] : [] as $attr) {
            if (!is_array($attr) || !isset($attr['value']) || trim((string) $attr['value']) === '') {
                continue;
            }
            $value = trim((string) $attr['value']);
            $number = isset($attr['number']) && is_numeric($attr['number']) ? (float) $attr['number'] : null;
            $kind = cafe_attr_kind($attr, $system);
            if ($kind === 'system' && $info['systems'] === []) {
                $known = [];
                foreach (cafe_rail()['items'] as $item) {
                    $known[$item['label']] = $item;
                }
                foreach ($split($value) as $label) {
                    $info['systems'][] = [
                        'label' => $label,
                        'code' => isset($known[$label]) ? $known[$label]['code'] : '',
                        'url' => isset($known[$label]) ? $known[$label]['url'] : '',
                        'pict' => isset($known[$label]) ? $known[$label]['pict'] : cafe_pict('', $label),
                    ];
                }
            } elseif ($kind === 'intensity' && $info['intensity'] === 0) {
                $level = $number !== null ? (int) round($number) : (is_numeric($value) ? (int) $value : 0);
                $info['intensity'] = max(0, min(cafe_scale_max(), $level));
            } elseif ($kind === 'roast' && $info['roast'] === '') {
                $info['roast'] = $value;
                $info['roast_step'] = cafe_roast_step($value);
            } elseif ($kind === 'cups' && $info['cups'] === []) {
                $info['cups'] = $split($value);
            } elseif ($kind === 'notes' && $info['notes'] === '') {
                $info['notes'] = $value;
            } elseif ($kind === 'units' && $info['units'] === 0 && $number !== null && $number >= 1) {
                $info['units'] = (int) round($number);
            } elseif ($kind === 'weight' && $info['grams'] === 0 && $number !== null && $number > 0) {
                $info['grams'] = (int) round($number * (strtolower(trim((string) $attr['unit'])) === 'kg' ? 1000 : 1));
            }
        }
        // El precio de cada taza: por unidad si el paquete dice cuántas trae; si no, por kilo si dice su peso neto. Con
        // variantes de precios distintos («desde») no se da ninguno: sería el de una sola variante.
        $block = $price !== null ? $price : (isset($product['price']) && is_array($product['price']) ? $product['price'] : []);
        $amount = isset($block['amount']) ? (int) $block['amount'] : 0;
        if ($amount > 0 && empty($block['from'])) {
            $currency = isset($block['currency']) && is_string($block['currency']) && $block['currency'] !== '' ? $block['currency'] : null;
            if ($info['units'] > 0) {
                $info['unit_price'] = t('{price} / unit', ['price' => money(intdiv($amount * 2 + $info['units'], $info['units'] * 2), $currency)]);
            } elseif ($info['grams'] > 0) {
                $info['unit_price'] = t('{price} / kg', ['price' => money(intdiv($amount * 1000 + intdiv($info['grams'], 2), $info['grams']), $currency)]);
            }
        }
        if ($id > 0) {
            $memo[$key] = $info;
            $view->share('cf_info', $memo);
        }

        return $info;
    }
}

if (!function_exists('cafe_ribbon')) {
    /**
     * El texto de la cinta de una tarjeta: «For {system}» con uno, «For {a} and {b}» con dos y «For {n} coffee makers»
     * con más. Sin sistemas, «».
     *
     * @param array<int,array<string,string>> $systems
     */
    function cafe_ribbon(array $systems): string
    {
        $count = count($systems);
        if ($count === 0) {
            return '';
        }
        if ($count === 1) {
            return t('For {system}', ['system' => $systems[0]['label']]);
        }
        if ($count === 2) {
            return t('For {a} and {b}', ['a' => $systems[0]['label'], 'b' => $systems[1]['label']]);
        }

        return t('For {n} coffee makers', ['n' => $count]);
    }
}

if (!function_exists('cafe_pieces')) {
    /**
     * Los trozos de un texto de opción separados por «|» (los avisos de la franja), sin vacíos y con un tope.
     *
     * @return array<int,string>
     */
    function cafe_pieces(string $text, int $max = 4): array
    {
        $out = [];
        foreach (explode('|', $text) as $piece) {
            $piece = trim($piece);
            if ($piece !== '' && count($out) < $max) {
                $out[] = $piece;
            }
        }

        return $out;
    }
}

return static function (App $app): void {
    // Lo recordado de cada producto y la regleta valen solo para esa petición: una misma aplicación puede atender varias.
    $app->hooks()->on('request.end', static function () use ($app): void {
        $app->view()->share('cf_info', []);
        $app->view()->share('cf_rail', null);
    });
};
