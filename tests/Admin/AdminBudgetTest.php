<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Kernel\App;

require_once __DIR__ . '/../../modules/orders/tests/AdminKit.php';

/*
 * Presupuesto de peso del panel. Se abre en proceso cada pantalla del menú, con una tienda con datos (20 productos y 20 pedidos),
 * y se exige por pantalla:
 *   - el HTML no pasa de 300 KB;
 *   - como mucho 10 recursos enlazados (hojas de estilo, guiones, fuentes);
 *   - ningún recurso de otro dominio;
 *   - ningún guion en línea de más de 2 KB;
 *   - como mucho 1.500 nodos.
 * Y dos reglas del menú: un solo nivel y no más de 60 entradas.
 *
 * Una pantalla que hoy no cumple se arregla; si no es de este paquete, queda en BUDGET_EXCEPTIONS con su cifra de hoy, y la
 * prueba falla si la cifra sube (la excepción es un techo, no un permiso) o si la pantalla ya cumple (hay que quitarla de la lista).
 */

/** Límites por pantalla. */
const BUDGET_LIMITS = ['bytes' => 300 * 1024, 'resources' => 10, 'inline_script' => 2048, 'nodes' => 1500];

/** Reglas del menú: no más de 60 entradas (sin excepción), con todos los módulos encendidos. */
const BUDGET_MENU_MAX = 60;

/**
 * Pantallas que hoy no cumplen y no son de este paquete: dirección => [medida => cifra de hoy]. Vacía si todas cumplen.
 *
 * @var array<string,array<string,int>>
 */
const BUDGET_EXCEPTIONS = [];

/**
 * Las medidas de una pantalla.
 *
 * @return array{bytes:int,resources:string[],foreign:string[],inline_script:int,nodes:int}
 */
function budgetMeasure(string $html, string $host): array
{
    $resources = [];
    $foreign = [];
    $take = static function (string $url) use (&$resources, &$foreign, $host): void {
        $url = html_entity_decode(trim($url), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if ($url === '' || isset($resources[$url])) {
            return;
        }
        $resources[$url] = true;
        if (preg_match('#^(?:https?:)?//([^/:?\#]+)#i', $url, $m) === 1 && strcasecmp($m[1], $host) !== 0) {
            $foreign[] = $url;
        }
    };
    if (preg_match_all('#<link\b[^>]*>#i', $html, $links) > 0) {
        foreach ($links[0] as $tag) {
            $rel = preg_match('#\brel="([^"]*)"#i', $tag, $r) === 1 ? strtolower($r[1]) : '';
            $as = preg_match('#\bas="([^"]*)"#i', $tag, $a) === 1 ? strtolower($a[1]) : '';
            $isResource = strpos($rel, 'stylesheet') !== false || (in_array($rel, ['preload', 'prefetch', 'modulepreload'], true) && in_array($as, ['font', 'style', 'script', ''], true));
            if ($isResource && preg_match('#\bhref="([^"]*)"#i', $tag, $h) === 1) {
                $take($h[1]);
            }
        }
    }
    if (preg_match_all('#<script\b[^>]*\bsrc="([^"]*)"#i', $html, $scripts) > 0) {
        foreach ($scripts[1] as $src) {
            $take($src);
        }
    }
    // Las fuentes que pide un <style> en la propia página (@font-face).
    if (preg_match_all('#<style\b[^>]*>(.*?)</style>#is', $html, $styles) > 0) {
        foreach ($styles[1] as $css) {
            if (preg_match_all('#url\(\s*[\'"]?([^\'")]+\.(?:woff2?|ttf|otf|eot)[^\'")]*)#i', $css, $fonts) > 0) {
                foreach ($fonts[1] as $font) {
                    $take($font);
                }
            }
        }
    }

    $largest = 0;
    if (preg_match_all('#<script\b([^>]*)>(.*?)</script>#is', $html, $inline, PREG_SET_ORDER) > 0) {
        foreach ($inline as $script) {
            if (preg_match('#\bsrc=#i', $script[1]) === 1) {
                continue;
            }
            // Un bloque de datos (JSON-LD, plantillas) no es un guion que se ejecute.
            if (preg_match('#\btype="([^"]*)"#i', $script[1], $t) === 1 && !in_array(strtolower($t[1]), ['text/javascript', 'application/javascript', 'module'], true)) {
                continue;
            }
            $largest = max($largest, strlen($script[2]));
        }
    }

    $clean = (string) preg_replace('#<!--.*?-->#s', '', $html);

    return [
        'bytes' => strlen($html),
        'resources' => array_keys($resources),
        'foreign' => $foreign,
        'inline_script' => $largest,
        'nodes' => (int) preg_match_all('#<[a-zA-Z][a-zA-Z0-9-]*[\s/>]#', $clean),
    ];
}

/**
 * Lo que se pasa del presupuesto en una pantalla, en frases (vacío si cumple). $allowed = las cifras de su excepción.
 *
 * @param array{bytes:int,resources:string[],foreign:string[],inline_script:int,nodes:int} $m
 * @param array<string,int> $allowed
 *
 * @return string[]
 */
function budgetProblems(array $m, array $allowed = []): array
{
    $limits = $allowed + BUDGET_LIMITS;
    $out = [];
    if ($m['bytes'] > $limits['bytes']) {
        $out[] = 'HTML de ' . $m['bytes'] . ' bytes (tope ' . $limits['bytes'] . ')';
    }
    if (count($m['resources']) > $limits['resources']) {
        $out[] = count($m['resources']) . ' recursos enlazados (tope ' . $limits['resources'] . '): ' . implode(', ', $m['resources']);
    }
    if ($m['foreign'] !== []) {
        $out[] = 'recursos de otro dominio: ' . implode(', ', $m['foreign']);
    }
    if ($m['inline_script'] > $limits['inline_script']) {
        $out[] = 'un guion en línea de ' . $m['inline_script'] . ' bytes (tope ' . $limits['inline_script'] . ')';
    }
    if ($m['nodes'] > $limits['nodes']) {
        $out[] = $m['nodes'] . ' nodos (tope ' . $limits['nodes'] . ')';
    }

    return $out;
}

/**
 * Lo que se pasa del presupuesto en el menú: un solo nivel y no más de 60 entradas. Un conjunto de pestañas ('tabs') es una
 * sola entrada, y lo que va oculto ('hidden': se llega con un botón) no cuenta.
 *
 * @param array<string,array<string,mixed>> $definitions las de $app->adminDefinitions()
 *
 * @return string[]
 */
function budgetMenuProblems(array $definitions, int $max = BUDGET_MENU_MAX): array
{
    $out = [];
    $entries = 0;
    $sets = [];
    foreach ($definitions as $module => $definition) {
        foreach (isset($definition['menu']) && is_array($definition['menu']) ? $definition['menu'] : [] as $group) {
            if (!is_array($group)) {
                continue;
            }
            if (!isset($group['items']) && isset($group['url'])) {
                $entries++;
            }
            foreach (isset($group['items']) && is_array($group['items']) ? $group['items'] : [] as $item) {
                $set = is_array($item) && isset($item['tabs']) && is_string($item['tabs']) ? $item['tabs'] : '';
                if (is_array($item) && empty($item['hidden']) && ($set === '' || !isset($sets[$set]))) {
                    $entries++;
                }
                if ($set !== '') {
                    $sets[$set] = true;
                }
                if (is_array($item) && (isset($item['items']) || isset($item['children']) || isset($item['group']))) {
                    $out[] = 'la entrada «' . (isset($item['label']) ? (string) $item['label'] : '?') . '» de ' . $module . ' tiene un segundo nivel';
                }
            }
        }
    }
    if ($entries > $max) {
        $out[] = $entries . ' entradas en el menú (tope ' . $max . ')';
    }

    return $out;
}

/**
 * Tienda con todos los módulos encendidos (también los que no vienen de serie), 20 productos y 20 pedidos.
 *
 * @return array{0:App,1:TestClient}
 */
function budgetShop(): array
{
    $slugs = [];
    foreach (glob(Autoload::root() . '/modules/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $slug = basename($dir);
        if ($slug !== 'orders') {
            $slugs[] = $slug;
        }
    }
    $app = adminOrdersApp(['modules' => $slugs, 'settings' => ['modules.active' => array_merge(['orders', 'mail', 'media', 'jobs'], $slugs)]]);
    $products = [];
    for ($i = 1; $i <= 20; $i++) {
        $products[] = adminOrdersProduct($app, ['name' => 'Producto ' . $i . ' & Cía', 'price' => 1000 + $i * 150, 'stock' => 50, 'sku' => 'REF-' . $i]);
    }
    for ($i = 0; $i < 20; $i++) {
        adminOrdersPlace($app, $i % 2 === 0 ? 'transfer' : 'gw', [[$products[$i], 0, 1 + ($i % 3)]], ['email' => 'cliente' . $i . '@cliente.test']);
    }

    return [$app, adminClient($app)];
}

/**
 * Las direcciones de las entradas del menú, tal como las enseña el panel a esta persona.
 *
 * @return array<int,string>
 */
function budgetMenuLinks(string $html): array
{
    $start = strpos($html, '<nav class="af-nav">');
    $end = $start !== false ? strpos($html, '</nav>', $start) : false;
    $nav = $start !== false && $end !== false ? substr($html, $start, $end - $start) : '';
    $links = [];
    if (preg_match_all('#<a\b[^>]*\bhref="([^"]+)"#', $nav, $m) > 0) {
        foreach ($m[1] as $href) {
            $links[] = html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
    }

    return array_values(array_unique($links));
}

test('el medidor del presupuesto salta cuando una pantalla se pasa de cada tope', function (): void {
    $page = static function (string $extra = '', string $links = ''): string {
        return '<!doctype html><html><head><link rel="stylesheet" href="/c/core/admin.css">' . $links . '</head><body><main>' . $extra . '</main>'
            . '<script src="/c/core/admin.js" defer></script></body></html>';
    };
    same([], budgetProblems(budgetMeasure($page('<p>hola</p>'), 'tienda.test')), 'una pantalla sencilla cumple');

    $m = budgetMeasure($page('<script>' . str_repeat('x', 2049) . '</script>'), 'tienda.test');
    same(2049, $m['inline_script']);
    check(count(budgetProblems($m)) === 1 && strpos(budgetProblems($m)[0], 'guion en línea') !== false, 'un guion en línea de más de 2 KB');
    same([], budgetProblems(budgetMeasure($page('<script>' . str_repeat('x', 2048) . '</script>'), 'tienda.test')), 'justo 2 KB cumple');
    same([], budgetProblems(budgetMeasure($page('<script type="application/ld+json">' . str_repeat('x', 5000) . '</script>'), 'tienda.test')), 'un bloque de datos no cuenta');

    $links = '';
    for ($i = 0; $i < 9; $i++) {
        $links .= '<link rel="stylesheet" href="/c/x' . $i . '.css">';
    }
    $m = budgetMeasure($page('', $links), 'tienda.test');
    same(11, count($m['resources']), 'la hoja y el guion de siempre más nueve');
    check(strpos(implode('|', budgetProblems($m)), 'recursos enlazados') !== false, '11 recursos pasan de 10');
    same([], budgetProblems(budgetMeasure($page('', substr($links, 0, strlen('<link rel="stylesheet" href="/c/x0.css">') * 8)), 'tienda.test')), 'diez cumple');
    $fonts = '<link rel="preload" as="font" href="/f/a.woff2" crossorigin><style>@font-face{src:url(/f/b.woff2)}</style>';
    same(4, count(budgetMeasure($page('', $fonts), 'tienda.test')['resources']), 'las fuentes cuentan como recursos');

    $m = budgetMeasure($page('', '<link rel="stylesheet" href="https://fonts.googleapis.com/css?family=X">'), 'tienda.test');
    same(['https://fonts.googleapis.com/css?family=X'], $m['foreign']);
    check(strpos(implode('|', budgetProblems($m)), 'otro dominio') !== false, 'un recurso de otro dominio');
    same([], budgetMeasure($page('', '<link rel="stylesheet" href="https://tienda.test/c/a.css"><script src="//tienda.test/j.js"></script>'), 'tienda.test')['foreign'], 'el propio dominio no es de otro');
    check(budgetMeasure($page('<script src="https://cdn.example.test/x.js"></script>'), 'tienda.test')['foreign'] !== [], 'tampoco un guion de fuera');
    check(budgetMeasure($page('<a href="https://otro.test/">enlace</a>'), 'tienda.test')['foreign'] === [], 'un enlace a otra web no es un recurso');

    $m = budgetMeasure($page(str_repeat('<i></i>', 1500)), 'tienda.test');
    check($m['nodes'] > 1500, 'con 1.500 nodos de más');
    check(strpos(implode('|', budgetProblems($m)), 'nodos') !== false);
    same([], budgetProblems(budgetMeasure($page(str_repeat('<i></i>', 1400)), 'tienda.test')));
    same([], budgetProblems(budgetMeasure($page('<!--' . str_repeat('<i></i>', 3000) . '-->'), 'tienda.test')), 'un comentario no son nodos');

    $big = budgetMeasure($page(str_repeat('a', 300 * 1024)), 'tienda.test');
    check(strpos(implode('|', budgetProblems($big)), 'bytes') !== false, 'un HTML de más de 300 KB');
    same([], budgetProblems($big, ['bytes' => 400 * 1024]), 'con su excepción, se mide contra la cifra de la excepción');
});

test('el medidor del menú salta con un segundo nivel o con más de 60 entradas', function (): void {
    $ok = ['m' => ['menu' => [['group' => 'g', 'items' => [['label' => 'Uno', 'url' => '/admin/uno'], ['label' => 'Dos', 'url' => '/admin/dos']]]]]];
    same([], budgetMenuProblems($ok));
    $second = $ok;
    $second['m']['menu'][0]['items'][1]['items'] = [['label' => 'Hija', 'url' => '/admin/hija']];
    check(count(budgetMenuProblems($second)) === 1 && strpos(budgetMenuProblems($second)[0], 'segundo nivel') !== false, 'una entrada con hijas');
    $nested = $ok;
    $nested['m']['menu'][0]['items'][0]['group'] = 'otro';
    check(budgetMenuProblems($nested) !== [], 'un grupo dentro de una entrada también');

    $many = ['m' => ['menu' => [['group' => 'g', 'items' => []]]]];
    for ($i = 1; $i <= 60; $i++) {
        $many['m']['menu'][0]['items'][] = ['label' => 'E' . $i, 'url' => '/admin/e' . $i];
    }
    same([], budgetMenuProblems($many), 'sesenta cumplen');
    $many['m']['menu'][0]['items'][] = ['label' => 'E61', 'url' => '/admin/e61'];
    same(['61 entradas en el menú (tope 60)'], budgetMenuProblems($many));
    $single = [['m' => ['menu' => [['group' => 'h', 'label' => 'Inicio', 'url' => '/admin']]]]];
    same([], budgetMenuProblems($single[0]), 'un grupo de un solo enlace es una entrada');
});

test('el medidor del menú cuenta un conjunto de pestañas como una entrada, aunque se reparta entre módulos, y no cuenta lo oculto', function (): void {
    $sixty = ['m' => ['menu' => [['group' => 'g', 'items' => []]]]];
    for ($i = 1; $i <= 60; $i++) {
        $sixty['m']['menu'][0]['items'][] = ['label' => 'E' . $i, 'url' => '/admin/e' . $i];
    }
    $tabs = $sixty;
    $tabs['m']['menu'][0]['items'][0]['tabs'] = 'set';
    $tabs['m']['menu'][0]['items'][] = ['label' => 'Hermana', 'url' => '/admin/hermana', 'tabs' => 'set'];
    $tabs['otro'] = ['menu' => [['group' => 'g', 'items' => [['label' => 'Prima', 'url' => '/admin/prima', 'tabs' => 'set']]]]];
    same([], budgetMenuProblems($tabs), 'tres pantallas del mismo conjunto, de dos módulos, son una entrada');
    $hidden = $sixty;
    $hidden['m']['menu'][0]['items'][] = ['label' => 'Botón', 'url' => '/admin/boton', 'hidden' => true];
    same([], budgetMenuProblems($hidden), 'lo oculto no ocupa sitio en el menú');
    $more = $tabs;
    $more['m']['menu'][0]['items'][] = ['label' => 'Otra', 'url' => '/admin/otra', 'tabs' => 'otro-conjunto'];
    same(['61 entradas en el menú (tope 60)'], budgetMenuProblems($more), 'un conjunto nuevo sí es una entrada más');
});

test('todas las pantallas del menú cumplen el presupuesto de peso con una tienda con datos', function (): void {
    list($app, $web) = budgetShop();
    same(20, (int) $app->db()->value('SELECT COUNT(*) FROM {orders}'), 'la tienda tiene veinte pedidos');
    same(20, (int) $app->db()->value('SELECT COUNT(*) FROM {products}'), 'y veinte productos');

    $home = $web->get('/admin');
    same(200, $home->status);
    $links = budgetMenuLinks($home->body);
    check(count($links) >= 20, 'el menú trae entradas (' . count($links) . ')');
    check(count($links) <= BUDGET_MENU_MAX, 'el menú tiene ' . count($links) . ' entradas (tope ' . BUDGET_MENU_MAX . ')');
    same([], budgetMenuProblems($app->adminDefinitions()), 'contado en las definiciones: un conjunto de pestañas es una entrada');

    // Un solo nivel, mirando lo que se pinta: ningún «details» dentro del submenú de un grupo.
    $nav = (string) substr($home->body, (int) strpos($home->body, '<nav class="af-nav">'));
    $nav = substr($nav, 0, (int) strpos($nav, '</nav>'));
    same(0, preg_match_all('#<div class="af-nav-sub">(?:(?!</div>).)*<details#s', $nav), 'ningún grupo dentro de otro');
    same([], budgetMenuProblems($app->adminDefinitions(), PHP_INT_MAX), 'y en las definiciones de los módulos');

    $report = [];
    $failed = [];
    $seen = 0;
    foreach (array_merge(['/admin'], $links) as $href) {
        $path = (string) preg_replace('#^https?://[^/]+#', '', $href);
        if ($path === '' || strpos($path, '/admin') !== 0) {
            continue;
        }
        $r = $web->get($path);
        for ($hop = 0; $hop < 3 && $r->isRedirect(); $hop++) {
            $r = $web->follow();
        }
        if ($r->status !== 200) {
            $failed[] = $path . ' → ' . $r->status;
            continue;
        }
        $seen++;
        $m = budgetMeasure($r->body, 'tienda.test');
        $allowed = isset(BUDGET_EXCEPTIONS[$path]) ? BUDGET_EXCEPTIONS[$path] : [];
        $problems = budgetProblems($m, $allowed);
        if ($problems !== []) {
            $failed[] = $path . ': ' . implode('; ', $problems);
        } elseif ($allowed !== [] && budgetProblems($m) === []) {
            $failed[] = $path . ': ya cumple, quítala de BUDGET_EXCEPTIONS';
        }
        $report[$path] = $m['bytes'] . ' B, ' . count($m['resources']) . ' recursos, ' . $m['nodes'] . ' nodos, guion ' . $m['inline_script'] . ' B';
    }
    check($seen >= 20, 'se han abierto ' . $seen . ' pantallas');
    same([], $failed, "pantallas fuera de presupuesto:\n" . implode("\n", $failed));
    if (getenv('AIFEC_BUDGET_REPORT') !== false) {
        ksort($report);
        fwrite(STDERR, "PRESUPUESTO\n" . implode("\n", array_map(static function (string $k, string $v): string {
            return $k . "\t" . $v;
        }, array_keys($report), $report)) . "\n");
    }
});
