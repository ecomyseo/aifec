<?php
/**
 * AIFEC — código del tema aifec: las ayudas de sus plantillas (el botón de la cabecera, los accesos de la barra inferior del móvil,
 * las seis líneas de principios y la entrada «Tienda» del menú con las categorías del sitio) y un único gancho, el de la portada,
 * que reparte la página «Inicio» alrededor del catálogo y pone la rejilla de categorías.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

if (!function_exists('aifec_theme_roots')) {
    /**
     * Las categorías de primer nivel activas del sitio (doce como mucho): id, name, url. Sin catálogo, vacío. Nunca se nombra una:
     * salen del sitio en el que corre el tema.
     *
     * @return array<int,array{id:int,name:string,url:string}>
     */
    function aifec_theme_roots(): array
    {
        $app = App::current();
        if (!$app->has('catalog')) {
            return [];
        }
        $out = [];
        foreach ($app->service('catalog')->tree() as $root) {
            if (!is_array($root)) {
                continue;
            }
            $name = isset($root['name']) ? trim((string) $root['name']) : '';
            $url = isset($root['url']) ? (string) $root['url'] : '';
            if ($name !== '' && $url !== '') {
                $out[] = ['id' => (int) $root['id'], 'name' => $name, 'url' => $url];
            }
            if (count($out) >= 12) {
                break;
            }
        }

        return $out;
    }
}

if (!function_exists('aifec_theme_has_shop')) {
    /** ¿Este sitio vende algo? Con el catálogo encendido y alguna categoría o algún producto. Se calcula una vez por petición. */
    function aifec_theme_has_shop(): bool
    {
        $app = App::current();
        $memo = $app->view()->shared('af_theme_has_shop');
        if (is_bool($memo)) {
            return $memo;
        }
        $has = false;
        if ($app->has('catalog')) {
            $has = aifec_theme_roots() !== [];
            if (!$has) {
                $listing = $app->service('catalog')->listing(['per_page' => 1]);
                $has = is_array($listing) && !empty($listing['total']);
            }
        }
        $app->view()->share('af_theme_has_shop', $has);

        return $has;
    }
}

if (!function_exists('aifec_theme_shop')) {
    /**
     * ¿Hay que enseñar el acceso de tienda «search», «cart» o «account»? Solo si el sitio vende algo (o el comerciante lo ha encendido en el
     * tema: un sitio de producto sin catálogo no lo quiere, aunque el módulo de pedidos esté encendido) Y la ruta existe: nunca un
     * enlace a una página que no hay.
     */
    function aifec_theme_shop(string $name): bool
    {
        return App::current()->router()->has($name) && ((int) theme_option('shop_links', 0) === 1 || aifec_theme_has_shop());
    }
}

if (!function_exists('aifec_theme_shop_item')) {
    /**
     * El elemento «Tienda» del menú: lleva al buscador (o, sin él, a la primera categoría) y despliega las categorías de primer nivel.
     * null si el sitio no vende nada.
     *
     * @return array<string,mixed>|null
     */
    function aifec_theme_shop_item(): ?array
    {
        if (!aifec_theme_has_shop()) {
            return null;
        }
        $roots = aifec_theme_roots();
        $app = App::current();
        $href = $app->router()->has('search') ? route('search') : ($roots !== [] ? $roots[0]['url'] : url('/') . '#tienda');
        $children = [];
        foreach ($roots as $root) {
            $children[] = ['label' => $root['name'], 'url' => $root['url'], 'current' => false, 'children' => []];
        }

        return ['label' => t('Shop'), 'url' => $href, 'current' => false, 'children' => $children, 'shop' => true];
    }
}

if (!function_exists('aifec_theme_main_menu')) {
    /**
     * El menú principal tal como lo pintan la cabecera y el cajón del móvil: el del comerciante, con el elemento de dirección «#tienda»
     * (el marcador que pone el paquete) cambiado por «Tienda» con las categorías del sitio, o quitado si el sitio no vende nada.
     *
     * @return array<int,array<string,mixed>>
     */
    function aifec_theme_main_menu(): array
    {
        $out = [];
        $shop = null;
        foreach (menu('main') as $item) {
            if (isset($item['url']) && $item['url'] === '#tienda') {
                $shop = $shop !== null ? $shop : aifec_theme_shop_item();
                if ($shop !== null) {
                    $label = isset($item['label']) ? trim((string) $item['label']) : '';
                    $out[] = $label !== '' ? ['label' => $label] + $shop : $shop;
                }
                continue;
            }
            $out[] = $item;
        }

        return $out;
    }
}

if (!function_exists('aifec_theme_cta')) {
    /**
     * El botón de la cabecera («Ver demos» por defecto): texto y dirección, o null si no hay botón. La dirección que escribe el
     * comerciante solo vale si es de este sitio (`/algo`) o una dirección web completa; cualquier otra cosa se ignora. Sin dirección propia, solo
     * sale si existe la ruta de las demos (nunca un enlace roto).
     *
     * @return array{text:string,url:string}|null
     */
    function aifec_theme_cta(): ?array
    {
        if ((int) theme_option('header_cta', 1) !== 1) {
            return null;
        }
        $text = trim((string) theme_option('header_cta_text', ''));
        $link = trim((string) theme_option('header_cta_link', ''));
        $url = '';
        if ($link !== '' && preg_match('#^(/(?!/)|https?:/{2})[^\s<>"\']*$#i', $link) === 1) {
            $url = strpos($link, '/') === 0 ? url($link) : $link;
        } elseif (App::current()->router()->has('demos')) {
            $url = route('demos');
        }
        if ($url === '') {
            return null;
        }

        return ['text' => $text !== '' ? $text : t('See demos'), 'url' => $url];
    }
}

if (!function_exists('aifec_theme_bar_items')) {
    /**
     * Los accesos de la barra inferior del móvil, solo los que existen de verdad: el menú (siempre), las demos, la documentación,
     * el buscador (el de la tienda si se enseña, y si no el de la documentación) y el carrito (los dos de tienda solo si el sitio vende algo
     * o el tema los muestra). Cada uno: key, label, url, drawer (el panel que abre, o vacío si es un enlace), icon (trazados SVG).
     *
     * @return array<int,array{key:string,label:string,url:string,drawer:string,icon:string}>
     */
    function aifec_theme_bar_items(): array
    {
        $router = App::current()->router();
        $items = [
            ['key' => 'menu', 'label' => t('Menu'), 'url' => '', 'drawer' => 'af-menu', 'icon' => '<path d="M4 6h16M4 12h16M4 18h16"/>'],
        ];
        if ($router->has('demos')) {
            $items[] = ['key' => 'demos', 'label' => t('Demos'), 'url' => route('demos'), 'drawer' => '', 'icon' => '<rect x="4" y="5" width="16" height="14" rx="2"/><path d="m10 9 5 3-5 3V9Z"/>'];
        }
        if ($router->has('docs')) {
            $items[] = ['key' => 'docs', 'label' => t('Docs'), 'url' => route('docs'), 'drawer' => '', 'icon' => '<path d="M5 4h10a3 3 0 0 1 3 3v13H8a3 3 0 0 1-3-3V4Z"/><path d="M8 17h10"/>'];
        }
        $searchIcon = '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>';
        if (aifec_theme_shop('search')) {
            $items[] = ['key' => 'search', 'label' => t('Search'), 'url' => route('search'), 'drawer' => 'af-search', 'icon' => $searchIcon];
        } elseif ($router->has('docs.search')) {
            $items[] = ['key' => 'search', 'label' => t('Search'), 'url' => route('docs.search'), 'drawer' => '', 'icon' => $searchIcon];
        }
        if (aifec_theme_shop('cart')) {
            $items[] = ['key' => 'cart', 'label' => t('Cart'), 'url' => route('cart'), 'drawer' => 'af-cart', 'icon' => '<path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/>'];
        }

        return $items;
    }
}

if (!function_exists('aifec_theme_principles')) {
    /**
     * Los seis principios AIFEC en una palabra o dos cada uno, para la línea del pie.
     *
     * @return string[]
     */
    function aifec_theme_principles(): array
    {
        return [
            t('One single truth'),
            t('Data before text'),
            t('Doors for machines'),
            t('Light pages'),
            t('AI inside, a person in charge'),
            t('Sovereignty'),
        ];
    }
}

if (!function_exists('aifec_theme_needs')) {
    /**
     * Lo que necesita una dirección de la portada para tener sentido: «#tienda» pide que el sitio venda algo, «#asistente» que el
     * módulo del asistente esté encendido y «/buy-with-links» que un agente pueda comprar aquí con enlaces. Cualquier otra, nada.
     */
    function aifec_theme_needs(string $href): string
    {
        $map = ['#tienda' => 'shop', '#asistente' => 'assistant', '/buy-with-links' => 'agents'];

        return isset($map[$href]) ? $map[$href] : '';
    }
}

if (!function_exists('aifec_theme_met')) {
    /** ¿Se cumple esa necesidad en este sitio? Vacía siempre se cumple. */
    function aifec_theme_met(string $need, App $app): bool
    {
        if ($need === 'shop') {
            return aifec_theme_has_shop();
        }
        if ($need === 'assistant') {
            return $app->has('assistant');
        }
        if ($need === 'agents') {
            // La compra con enlaces es del módulo de la API: hace falta que esté, encendida, y que el sitio venda algo.
            return aifec_theme_has_shop() && $app->modules()->isLoaded('api')
                && $app->settings()->bool('api.enabled', true) && $app->settings()->bool('api.links', true);
        }

        return true;
    }
}

if (!function_exists('aifec_theme_prune')) {
    /**
     * Quita de los bloques de la portada lo que no tendría sentido en este sitio: los botones que llevan a «#tienda» si no hay tienda
     * o a «#asistente» si no hay asistente (y el botón sin botones que queda vacío) y las columnas cuyo titular tiene el ancla
     * «paso-agente», «paso-comprar» o «paso-asistente» (los caminos de «Pruébalo» que dependen de eso).
     *
     * @param array<int,array<string,mixed>> $blocks
     *
     * @return array<int,array<string,mixed>>
     */
    function aifec_theme_prune(array $blocks, App $app): array
    {
        $steps = ['paso-agente' => 'agents', 'paso-comprar' => 'shop', 'paso-asistente' => 'assistant'];
        $out = [];
        foreach ($blocks as $block) {
            $type = isset($block['type']) ? (string) $block['type'] : '';
            $children = isset($block['children']) && is_array($block['children']) ? array_values(array_filter($block['children'], 'is_array')) : [];
            if ($type === 'column') {
                $dropped = false;
                foreach ($children as $child) {
                    $anchor = isset($child['type'], $child['attrs']['anchor']) && $child['type'] === 'heading' ? (string) $child['attrs']['anchor'] : '';
                    if ($anchor !== '' && isset($steps[$anchor]) && !aifec_theme_met($steps[$anchor], $app)) {
                        $dropped = true;
                    }
                }
                if ($dropped) {
                    continue;
                }
            }
            if ($type === 'buttons' && isset($block['attrs']['items']) && is_array($block['attrs']['items'])) {
                $items = [];
                foreach ($block['attrs']['items'] as $item) {
                    $href = is_array($item) && isset($item['href']) ? (string) $item['href'] : '';
                    if (aifec_theme_met(aifec_theme_needs($href), $app)) {
                        $items[] = $item;
                    }
                }
                if ($items === []) {
                    continue;
                }
                $block['attrs']['items'] = $items;
            }
            if ($children !== []) {
                $block['children'] = aifec_theme_prune($children, $app);
            }
            $out[] = $block;
        }

        return $out;
    }
}

if (!function_exists('aifec_theme_part')) {
    /**
     * Los bloques de una parte de la portada, ya pintados y dentro de su caja de texto: sin estilos en línea (el ancho de las bandas lo
     * da la hoja del tema) y con el atributo que abre el asistente en el botón que lo pide.
     *
     * @param array<int,array<string,mixed>> $blocks
     */
    function aifec_theme_part(object $cms, array $blocks, App $app): string
    {
        $html = (string) $cms->render(aifec_theme_prune($blocks, $app));
        $html = str_replace('href="#asistente"', 'href="#asistente" role="button" aria-haspopup="dialog" data-af-assistant-open', $html);
        $form = $app->has('cms') && strpos($html, '<form') !== false && class_exists('Aifec\\Modules\\Cms\\Front\\Support')
            ? \Aifec\Modules\Cms\Front\Support::formScript($app, $html) : '';

        return '<div class="af-prose">' . $html . '</div>' . $form;
    }
}

if (!function_exists('aifec_theme_home_sections')) {
    /**
     * El filtro «home.sections» del tema, el último en pasar. La página de portada se parte en el primer separador que lleva entre sus
     * bloques: lo de antes va arriba (cms.home), después la rejilla de categorías y las secciones del catálogo, y lo de después
     * (cms.home.more) debajo de todo. La rejilla del tema sustituye a la del catálogo, que solo enseñaba las categorías con imagen.
     *
     * @param mixed $sections lo que ya hay: [['id', 'order', 'html']]
     * @param mixed $app
     *
     * @return array<int,array<string,mixed>>
     */
    function aifec_theme_home_sections($sections, $app): array
    {
        $sections = is_array($sections) ? array_values($sections) : [];
        if (!($app instanceof App)) {
            return $sections;
        }
        $roots = aifec_theme_roots();
        $out = [];
        foreach ($sections as $section) {
            $id = is_array($section) && isset($section['id']) ? (string) $section['id'] : '';
            if ($id === 'cms.home') {
                foreach (aifec_theme_split_home($app, $section) as $part) {
                    $out[] = $part;
                }
                continue;
            }
            if ($id === 'catalog.categories' && $roots !== []) {
                continue;
            }
            $out[] = $section;
        }
        if ($roots !== []) {
            $categories = [];
            $catalog = $app->service('catalog');
            foreach ($roots as $root) {
                $category = $catalog->category($root['id']);
                $image = is_array($category) && isset($category['image']) && is_array($category['image']) && !empty($category['image']['url']) ? $category['image'] : null;
                $categories[] = $root + ['image' => $image];
            }
            $out[] = ['id' => 'aifec.categories', 'order' => 15, 'html' => $app->view()->render('partials/home-categories', ['categories' => $categories])];
        }
        // Las demos, a la vista justo debajo del titular (antes que la tienda).
        $demos = aifec_theme_demos($app);
        if ($demos !== []) {
            $out[] = ['id' => 'aifec.demos', 'order' => 12, 'html' => $app->view()->render('partials/home-demos', ['demos' => $demos])];
        }

        return $out;
    }
}

if (!function_exists('aifec_theme_demos')) {
    /**
     * Las tiendas de demostración que este sitio tiene montadas, para enseñarlas en la portada: nombre, sector, dirección y
     * captura. Vacío si el sitio no lleva el módulo de sitios o no hay ninguna terminada.
     *
     * @return array<int,array{name:string,sector:string,url:string,preview:string}>
     */
    function aifec_theme_demos(App $app): array
    {
        if (!$app->modules()->isLoaded('sites') || !class_exists('\Aifec\Modules\Sites\Demos')) {
            return [];
        }
        $out = [];
        try {
            $demos = new \Aifec\Modules\Sites\Demos($app);
            $packages = $demos->packages();
            foreach ($demos->registry() as $entry) {
                if (!is_array($entry) || !isset($entry['state']) || $entry['state'] !== 'done' || empty($entry['url'])) {
                    continue;
                }
                $package = isset($entry['package']) ? (string) $entry['package'] : '';
                $meta = isset($packages[$package]) && is_array($packages[$package]) ? $packages[$package] : [];
                $from = !empty($entry['adopted']) ? $entry : $meta;
                $out[] = [
                    'name' => isset($entry['name']) ? (string) $entry['name'] : '',
                    'sector' => isset($from['sector']) && is_string($from['sector']) ? $from['sector'] : '',
                    'url' => rtrim((string) $entry['url'], '/') . '/',
                    'preview' => empty($entry['adopted']) && !empty($meta['preview']) ? $app->url()->to('/demo-preview/' . $package) : '',
                ];
            }
        } catch (\Throwable $e) {
            return [];
        }
        usort($out, static function (array $a, array $b): int {
            return strcmp($a['name'], $b['name']);
        });

        return array_slice($out, 0, 12);
    }
}

if (!function_exists('aifec_theme_split_home')) {
    /**
     * La sección «cms.home» hecha de nuevo desde los bloques de la página de portada y partida por el primer separador.
     *
     * @param mixed $section
     *
     * @return array<int,array<string,mixed>>
     */
    function aifec_theme_split_home(App $app, $section): array
    {
        $cms = $app->has('cms') ? $app->service('cms') : null;
        $id = $app->db()->value('SELECT id FROM {pages} WHERE is_home = 1 ORDER BY id LIMIT 1');
        $page = is_object($cms) && $id !== null ? $cms->page((int) $id) : null;
        if (!is_array($page) || !isset($page['blocks']) || !is_array($page['blocks'])) {
            return [$section];
        }
        $top = [];
        $bottom = [];
        $after = false;
        foreach ($page['blocks'] as $block) {
            if (!$after && is_array($block) && isset($block['type']) && $block['type'] === 'separator') {
                $after = true;
                continue;
            }
            if (is_array($block)) {
                if ($after) {
                    $bottom[] = $block;
                } else {
                    $top[] = $block;
                }
            }
        }
        $order = isset($section['order']) ? (int) $section['order'] : 10;
        $parts = [['id' => 'cms.home', 'order' => $order, 'html' => aifec_theme_part($cms, $top, $app)]];
        if ($bottom !== []) {
            $parts[] = ['id' => 'cms.home.more', 'order' => 60, 'html' => aifec_theme_part($cms, $bottom, $app)];
        }

        return $parts;
    }
}

return static function (App $app): void {
    $app->hooks()->filter('home.sections', 'aifec_theme_home_sections', 90);
};
