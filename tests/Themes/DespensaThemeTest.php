<?php
/**
 * AIFEC — pruebas del tema despensa (gourmet y alimentación): que sus cinco superficies responden con su hoja y su
 * estructura propias, que conservan los datos y los huecos de las plantillas que sustituyen, el tinte por categoría y el
 * precio por kilo de las tarjetas, el contrato de móvil, las opciones del tema y las comprobaciones estáticas del
 * presupuesto de peso y del contrato de estilos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Kernel\App;

require_once dirname(__DIR__, 2) . '/modules/catalog/tests/helpers.php';

/** Carpeta del tema despensa. */
function despensaDir(): string
{
    return rtrim(str_replace('\\', '/', Autoload::root()), '/') . '/themes/despensa';
}

/**
 * Todos los ficheros de una carpeta del tema (recursivo).
 *
 * @return string[]
 */
function despensaFiles(string $sub = ''): array
{
    $files = [];
    $dir = despensaDir() . ($sub !== '' ? '/' . $sub : '');
    if (!is_dir($dir)) {
        return $files;
    }
    $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if ($file->isFile()) {
            $files[] = str_replace('\\', '/', $file->getPathname());
        }
    }
    sort($files);

    return $files;
}

/** El CSS sin los bloques :root (donde sí se declaran los colores de fábrica). */
function despensaCssWithoutRoot(string $css): string
{
    return (string) preg_replace('/:where\(:root\)\s*\{[^}]*\}|:root\s*\{[^}]*\}/', '', $css);
}

/** La hoja sin comentarios ni espacios, para comparar reglas sin depender del formato. */
function despensaCssPacked(): string
{
    $css = (string) file_get_contents(despensaDir() . '/assets/css/theme.css');
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

    return (string) preg_replace('/\s+/', '', $css);
}

/**
 * Aplicación con el tema activo, el catálogo y los medios, arrancada.
 *
 * @param array<string,mixed> $options opciones del tema que cambia el comerciante
 * @param string[]            $modules
 * @param string              $theme   despensa, base, atelier o taller (para comparar estructuras)
 */
function despensaApp(array $options = [], array $modules = ['catalog', 'media'], string $theme = 'despensa'): App
{
    $settings = ['theme.active' => $theme];
    if ($options !== []) {
        $settings['theme.options'] = [$theme => $options];
    }
    $app = testApp(['modules' => $modules, 'settings' => $settings]);
    $app->boot();

    return $app;
}

/** Rutas de las que dependen la cabecera y la barra del móvil, registradas como lo haría un módulo de comercio. */
function despensaShopRoutes(App $app): void
{
    $noop = static function (): string {
        return '';
    };
    foreach (['search' => '/buscar', 'cart' => '/carrito', 'account' => '/mi-cuenta'] as $name => $path) {
        // Con el módulo de pedidos la ruta del carrito ya existe: solo se añaden las que faltan.
        if (!$app->router()->has($name)) {
            $app->router()->get($path, $noop)->name($name);
        }
    }
}

/** Un menú principal de tres niveles, con un elemento roto y un grupo vacío que no se tienen que pintar. */
function despensaMenu(App $app): void
{
    $app->hooks()->filter('menu.items', static function (array $items, string $location): array {
        if ($location !== 'main') {
            return $items;
        }

        return [
            ['label' => 'Aceites', 'url' => '/aceites', 'current' => true, 'children' => [
                ['label' => 'Oliva', 'url' => '/aceites/oliva', 'children' => [['label' => 'Virgen extra', 'url' => '/aceites/oliva/virgen'], ['label' => 'Ecológico', 'url' => '/aceites/oliva/eco']]],
                ['label' => 'Vinagres', 'url' => '/aceites/vinagres', 'children' => []],
            ]],
            ['label' => 'Quesos', 'url' => '/quesos', 'children' => []],
            ['label' => 'Roto', 'url' => ''],
            ['label' => 'Grupo vacío', 'url' => '', 'children' => []],
        ];
    });
}

/**
 * La tienda de las pruebas: dos categorías, una marca, un atributo de formato, un aceite con variantes (tres imágenes, datos
 * clave, preguntas y relacionados), una miel con peso y otros productos para llenar el listado. Con los mismos datos en
 * cualquier tema.
 *
 * @return array<string,int>
 */
function despensaSeed(App $app): array
{
    $app->boot();
    $ids = [];
    $ids['cat'] = aifecCategory($app, 'Aceites');
    $ids['cat2'] = aifecCategory($app, 'Quesos');
    $ids['brand'] = aifecBrand($app, 'Olivar Norte');
    aifecAttribute($app, 'formato', 'Formato', ['lata' => 'Lata 500 ml', 'botella' => 'Botella 1 l']);
    $ids['miel'] = aifecProduct($app, [
        'name' => 'Miel de azahar', 'slug' => 'miel-azahar', 'price' => 750, 'weight' => 250, 'images' => 2,
        'categories' => [$ids['cat2']], 'facts' => [['label' => 'Origen', 'value' => 'Valencia']],
    ]);
    $ids['queso'] = aifecProduct($app, ['name' => 'Queso curado', 'slug' => 'queso-curado', 'price' => 1800, 'images' => 1, 'categories' => [$ids['cat2']], 'stock' => 0]);
    foreach ([['Aceite suave', 'aceite-suave', 890], ['Aceite de girasol', 'aceite-girasol', 560], ['Vinagre de Jerez', 'vinagre-jerez', 640], ['Aceite ecológico', 'aceite-eco', 1490]] as $row) {
        aifecProduct($app, ['name' => $row[0], 'slug' => $row[1], 'price' => $row[2], 'categories' => [$ids['cat']]]);
    }
    $ids['oil'] = aifecProduct($app, [
        'name' => 'Aceite <b>&', 'slug' => 'aceite-oliva', 'price' => 1290, 'compare_price' => 1590, 'images' => 3,
        'categories' => [$ids['cat']], 'brand_id' => $ids['brand'], 'sku' => 'ACE-1', 'gtin' => '8412345678905',
        'summary' => '<p>Resumen corto</p>', 'description' => '<p>Texto largo del aceite</p>',
        'facts' => [['label' => 'Origen', 'value' => 'Jaén'], ['label' => 'Notas', 'value' => 'Frutado verde'], ['label' => 'Maridaje', 'value' => 'Pan y tomate']],
        'attributes' => ['variedad' => 'picual'],
        'faqs' => [['question' => '¿Se conserva en frío?', 'answer' => '<p>No hace falta.</p>']],
        'related' => [$ids['miel'], $ids['queso']],
        'variants' => [
            ['sku' => 'ACE-L', 'stock' => 3, 'values' => ['formato' => 'lata']],
            ['sku' => 'ACE-B', 'stock' => 5, 'values' => ['formato' => 'botella']],
        ],
    ]);

    return $ids;
}

/**
 * La secuencia «etiqueta.clase» de las etiquetas de abrir de un trozo de HTML: lo que sirve para comparar composiciones.
 * Los dibujos en línea no cuentan.
 */
function despensaSequence(string $html, bool $withClass): string
{
    // La compra desde la tarjeta (form[data-af-co]) es una pieza del catálogo, la misma en todos los temas: no es composición del tema.
    $html = (string) preg_replace('#<form\b[^>]*\bdata-af-co\b[^>]*>.*?</form>#s', '', $html);
    $found = [];
    preg_match_all('/<([a-z][a-z0-9]*)\b([^>]*)>/i', $html, $found, PREG_SET_ORDER);
    $out = [];
    foreach ($found as $tag) {
        $name = strtolower($tag[1]);
        if (in_array($name, ['script', 'path', 'circle', 'svg', 'ellipse', 'rect', 'line'], true)) {
            continue;
        }
        $class = '';
        if ($withClass && preg_match('/\bclass="([^"]*)"/', $tag[2], $m) === 1) {
            $parts = preg_split('/\s+/', trim($m[1]));
            $class = '.' . (is_array($parts) ? (string) $parts[0] : '');
        }
        $out[] = $name . $class;
    }

    return implode(' ', $out);
}

/** Parecido (0 a 1) entre dos secuencias de etiquetas. */
function despensaSimilarity(string $a, string $b): float
{
    $percent = 0.0;
    similar_text($a, $b, $percent);

    return $percent / 100;
}

/** La parte de una página que va entre dos marcas. */
function despensaSlice(string $html, string $from, string $to): string
{
    $start = strpos($html, $from);
    check($start !== false, 'no está «' . $from . '»');
    $end = strpos($html, $to, (int) $start);
    check($end !== false, 'no está «' . $to . '»');

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/**
 * Cada superficie de una tienda con los mismos datos, para compararla entre temas.
 *
 * @return array<string,string>
 */
function despensaSurfaces(string $theme): array
{
    $app = despensaApp([], ['catalog', 'media', 'orders'], $theme);
    despensaSeed($app);
    despensaMenu($app);
    despensaShopRoutes($app);
    $web = new TestClient($app);
    $list = $web->get('/categoria/aceites')->body;
    $product = $web->get('/producto/aceite-oliva')->body;
    check(strpos($list, '<article') !== false, 'el listado de ' . $theme . ' trae tarjetas');

    return [
        'header' => despensaSlice($list, '<header class="', '</header>'),
        'card' => despensaSlice($list, '<article', '</article>'),
        'listing' => despensaSlice($list, '<main id="main"', '</main>'),
        'product' => despensaSlice($product, '<main id="main"', '</main>'),
    ];
}

test('portada, listado, ficha, carrito y muestra responden 200 con la hoja de despensa y no con la del tema base', function (): void {
    $app = despensaApp([], ['catalog', 'media', 'orders']);
    despensaSeed($app);
    $web = new TestClient($app);
    foreach (['/', '/categoria/aceites', '/producto/aceite-oliva', '/carrito', '/tema/muestra'] as $uri) {
        $r = $web->get($uri);
        same(200, $r->status, $uri);
        contains('/assets/t/despensa/', $r->body, $uri . ': la hoja de despensa');
        check(preg_match('#<link rel="stylesheet" href="[^"]*/assets/t/despensa/[^"]*/css/theme\.css">#', $r->body) === 1, $uri . ': enlaza theme.css de despensa');
        check(preg_match('#/assets/t/base/[^"]*/css/theme\.css#', $r->body) === 0, $uri . ': no enlaza la hoja del tema base');
        check(preg_match('#/assets/t/base/[^"]*/js/theme\.js" defer#', $r->body) === 1, $uri . ': el guion del tema base sigue cargándose');
        check(preg_match('#/assets/t/despensa/[^"]*/js/theme\.js" defer#', $r->body) === 1, $uri . ': y el pequeño de despensa va con defer');
        contains('ds-header af-header', $r->body, $uri . ': la cabecera es la de despensa');
        notContains('href=""', $r->body, $uri);
    }
});

test('la ficha: galería antes que la compra, un solo h1, formulario, JSON de variantes válido y todos los datos de la original', function (): void {
    $app = despensaApp([], ['catalog', 'media', 'orders']);
    despensaSeed($app);
    $body = (new TestClient($app))->get('/producto/aceite-oliva')->body;
    $gallery = strpos($body, 'af-product-gallery');
    $buy = strpos($body, 'af-product-buy');
    check($gallery !== false && $buy !== false && $gallery < $buy, 'la galería va antes que la compra en el HTML (izquierda en escritorio)');
    same(1, substr_count($body, '<h1'), 'un solo h1');
    contains('<h1 class="ds-pdp-title af-title">Aceite &lt;b&gt;&amp;</h1>', $body);
    contains('data-af-cart-add', $body);
    contains('name="product_id"', $body);
    contains('data-af-variant-id', $body);
    contains('data-af-variants', $body);
    check(preg_match('#<script type="application/json" data-af-variants-data>(.*?)</script>#s', $body, $m) === 1, 'hay JSON de variantes');
    $data = json_decode($m[1], true);
    check(is_array($data) && count($data['variants']) === 2, 'el JSON es válido y lleva las dos variantes: ' . json_last_error_msg());
    notContains('<script>alert', $body);
    contains('af-price-old', $body, 'precio anterior tachado');
    contains('af-price-off', $body, 'insignia del descuento');
    contains('data-af-availability', $body, 'disponibilidad');
    contains('data-af-qty', $body, 'cantidad');
    contains('data-af-add', $body, 'botón de compra');
    contains('data-af-sku', $body, 'referencia');
    contains('Olivar Norte', $body, 'marca');
    contains('Resumen corto', $body, 'resumen');
    contains('Texto largo del aceite', $body, 'descripción');
    contains('id="af-specs"', $body, 'características');
    check(stripos(despensaSlice($body, 'id="af-specs"', '</section>'), 'picual') !== false, 'el atributo visible sale en las características');
    contains('class="ds-spec"', $body);
    contains('8412345678905', $body, 'GTIN');
    contains('id="af-faq"', $body, 'preguntas');
    contains('¿Se conserva en frío?', $body);
    contains('id="af-related"', $body, 'relacionados');
    same(2, substr_count($body, '<article class="ds-card af-product-card'), 'dos productos relacionados');
    // Lo propio de despensa.
    contains('class="ds-from"', $body, 'de dónde viene');
    contains('<strong>Jaén</strong>', $body);
    contains('id="af-notes"', $body, 'notas de cata');
    same(3, substr_count($body, 'class="ds-note"'), 'una ficha por dato clave');
    contains('Frutado verde', $body);
    contains('Maridaje', $body, 'el maridaje son los relacionados');
    contains('class="ds-producer', $body, 'bloque del productor');
    notContains('class="ds-unit"', despensaSlice($body, 'class="ds-pdp-buy', 'class="ds-more'), 'con variantes el precio cambia con el formato: sin precio por kilo');
});

test('el formato se elige con tarjetas: un radio nativo por valor dentro de una tarjeta, y el resto de datos de la ficha sin variantes', function (): void {
    $app = despensaApp([], ['catalog', 'media', 'orders']);
    despensaSeed($app);
    $web = new TestClient($app);
    $body = $web->get('/producto/aceite-oliva')->body;
    $formats = despensaSlice($body, '<fieldset class="ds-formats af-variants"', '</fieldset>');
    contains('data-af-option="formato"', $formats);
    same(2, substr_count($formats, '<label class="ds-format af-variant'), 'una tarjeta por formato');
    same(2, substr_count($formats, 'type="radio"'), 'con su radio nativo');
    same(1, substr_count($formats, ' checked'), 'uno elegido de serie');
    contains('Lata 500 ml', $formats);
    contains('Botella 1 l', $formats);
    contains('name="opt[formato]"', $formats);

    $simple = $web->get('/producto/miel-azahar')->body;
    notContains('data-af-variants', $simple);
    contains('class="ds-unit"', $simple, 'sin variantes: precio por kilo');
    contains('30,00', $simple);
    contains('/ kg', $simple);
    contains('Valencia', $simple);
});

test('la galería: imagen grande con las miniaturas debajo, la principal con su marca y sin miniaturas si se apagan', function (): void {
    $app = despensaApp();
    despensaSeed($app);
    $body = (new TestClient($app))->get('/producto/aceite-oliva')->body;
    $gal = despensaSlice($body, '<div class="ds-gal af-gallery"', 'class="ds-pdp-buy af-product-buy');
    contains('data-af-gallery', $gal);
    check(strpos($gal, 'ds-gal-main') < strpos($gal, 'ds-gal-thumbs'), 'las miniaturas van después de la imagen principal');
    same(1, substr_count($gal, 'data-af-gallery-main'), 'una sola imagen principal');
    same(3, substr_count($gal, 'data-image-id='), 'cada miniatura sabe qué imagen es (lo usa theme.js al elegir variante)');
    same(3, substr_count($gal, 'data-src='), 'y trae la dirección de la grande');
    check(preg_match('#<img[^>]*data-af-gallery-main[^>]*>#', $gal, $main) === 1, 'hay imagen principal');
    contains('loading="eager"', $main[0]);
    contains('fetchpriority="high"', $main[0]);
    notContains('ds-gal-off', $gal);

    $off = despensaApp(['gallery_thumbs' => 'hidden']);
    despensaSeed($off);
    contains('ds-gal-thumbs af-gallery-thumbs ds-gal-off', (new TestClient($off))->get('/producto/aceite-oliva')->body);

    $none = despensaApp();
    aifecProduct($none, ['name' => 'Sin foto', 'slug' => 'sin-foto']);
    $r = (new TestClient($none))->get('/producto/sin-foto');
    same(200, $r->status);
    contains('af-noimage', $r->body, 'sin imágenes: hueco y sin errores');
});

test('la ficha conserva todos los huecos de la plantilla original del catálogo y la portada y el envoltorio los suyos', function (): void {
    $original = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/catalog/views/front/product.php');
    $names = [];
    preg_match_all("/hook\('(product\.[a-z.]+)'/", $original, $names);
    $names = array_values(array_unique($names[1]));
    check(count($names) >= 7, 'la plantilla original declara sus huecos: ' . implode(', ', $names));
    $app = despensaApp();
    despensaSeed($app);
    foreach ($names as $name) {
        $app->hooks()->on($name, static function () use ($name): string {
            return '<i data-hole="' . $name . '"></i>';
        });
    }
    $body = (new TestClient($app))->get('/producto/aceite-oliva')->body;
    foreach ($names as $name) {
        same(1, substr_count($body, '<i data-hole="' . $name . '"></i>'), 'el hueco «' . $name . '» sale una vez');
    }
    $shell = despensaApp();
    $holes = ['body.start', 'header.before', 'header.after', 'footer.before', 'footer.after', 'body.end', 'header.actions', 'home.top', 'home.bottom'];
    foreach ($holes as $name) {
        $shell->hooks()->on($name, static function () use ($name): string {
            return '<i data-hole="' . $name . '"></i>';
        });
    }
    despensaShopRoutes($shell);
    $home = (new TestClient($shell))->get('/')->body;
    foreach ($holes as $name) {
        same(1, substr_count($home, '<i data-hole="' . $name . '"></i>'), 'el hueco «' . $name . '» sale una vez en la portada');
    }
});

test('la cabecera: franja de envío, logotipo con emblema, menú centrado con desplegables, buscador desplegable y carrito redondeado con el número', function (): void {
    $app = despensaApp();
    despensaMenu($app);
    despensaShopRoutes($app);
    $body = (new TestClient($app))->get('/')->body;
    contains('<div class="ds-strip"><p class="ds-strip-text">Empaquetado a mano y enviado con mimo</p></div>', $body);
    $header = despensaSlice($body, '<header class="ds-header af-header', '</header>');
    check(strpos($header, 'ds-brand') < strpos($header, 'ds-menu') && strpos($header, 'ds-menu') < strpos($header, 'ds-tools'), 'logotipo, menú y herramientas por ese orden');
    contains('class="ds-emblem"', $header, 'emblema dibujado');
    contains('ds-emblem-svg', $header);
    contains('<a class="ds-menu-link" href="/aceites" aria-current="page">Aceites</a>', $header);
    contains('class="ds-sub"', $header);
    contains('class="ds-sub-link" href="/aceites/oliva"', $header);
    contains('class="ds-sub-leaf" href="/aceites/oliva/virgen"', $header);
    contains('href="/quesos"', $header);
    notContains('Roto', $body);
    notContains('Grupo vacío', $body);
    contains('<details class="ds-search af-hide-sm">', $header, 'el buscador se despliega');
    contains('role="search"', $header);
    contains('href="/mi-cuenta"', $header);
    contains('class="ds-cart af-hide-sm"', $header);
    contains('data-af-cart-count', $header, 'el carrito lleva su contador');
    contains('data-af-drawer-open="af-cart"', $header);
    notContains('href=""', $body);
});

test('la portada: historia con su h1, lotes, estantería de temporada y productor en su orden, y un solo h1 aunque una sección traiga el suyo', function (): void {
    $app = despensaApp();
    $app->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'catalog.new', 'order' => 40, 'html' => '<section><h2>Novedades</h2></section>'];
        $sections[] = ['id' => 'x.otro', 'order' => 60, 'html' => '<section><h2>Otro</h2></section>'];
        $sections[] = ['id' => 'catalog.categories', 'order' => 30, 'html' => '<section><h2>Lotes</h2></section>'];
        $sections[] = ['id' => 'cms.home', 'order' => 10, 'html' => '<p>Contenido propio</p>'];

        return $sections;
    });
    $body = (new TestClient($app))->get('/')->body;
    contains('<section class="ds-story ds-story-right"', $body);
    contains('<h1 class="ds-story-title" id="ds-story-title">Buena comida, con nombre y con lugar de origen</h1>', $body);
    same(1, substr_count($body, '<h1'));
    contains('/img/story.svg', $body, 'sin imagen elegida, el dibujo propio');
    $order = ['class="ds-story ', 'data-section="cms.home"', 'data-section="catalog.categories"', 'data-section="catalog.new"', 'data-section="x.otro"', 'data-section="ds.producer"'];
    $last = -1;
    foreach ($order as $mark) {
        $pos = strpos($body, $mark);
        check($pos !== false && $pos > $last, 'orden de la portada: «' . $mark . '»');
        $last = (int) $pos;
    }
    contains('/img/map.svg', $body, 'el mapa dibujado del productor');

    $withH1 = despensaApp();
    $withH1->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'cms.home', 'order' => 10, 'html' => '<h1>Mi portada</h1>'];

        return $sections;
    });
    $other = (new TestClient($withH1))->get('/')->body;
    same(1, substr_count($other, '<h1'), 'si una sección trae su h1, el de la historia pasa a ser un párrafo');
    contains('<p class="ds-story-title"', $other);

    $empty = (new TestClient(despensaApp(['producer_show' => 0])))->get('/')->body;
    notContains('ds-producer', $empty, 'el bloque del productor se puede apagar');
    contains('af-empty', $empty, 'sin secciones, el aviso amable');
});

test('«lotes y regalos» son tres tarjetas grandes con tinte y el resto pastillas; la temporada es una fila que se desliza con anclaje', function (): void {
    $app = despensaApp();
    $cats = [];
    foreach (['Cestas', 'Aceites', 'Dulces', 'Vinos', 'Quesos'] as $name) {
        $cats[] = ['name' => $name . ' <i>', 'url' => '/categoria/' . strtolower($name), 'image' => null];
    }
    $html = $app->view()->render('catalog::front/home/categories', ['title' => 'Categorías', 'categories' => $cats]);
    contains('Lotes y regalos', $html);
    same(3, substr_count($html, '<a class="ds-gift"'), 'tres tarjetas grandes');
    contains('data-tone="1"', $html);
    contains('data-tone="3"', $html);
    contains('Cestas &lt;i&gt;', $html);
    notContains('Cestas <i>', $html);
    same(2, substr_count($html, 'class="ds-pill"'), 'el resto, como pastillas');
    contains('ds-gift-art', $html, 'sin imagen, un dibujo');
    $few = $app->view()->render('catalog::front/home/categories', ['title' => 'x', 'categories' => array_slice($cats, 0, 2)]);
    same(2, substr_count($few, '<a class="ds-gift"'));
    notContains('ds-gifts-more', $few);
    $custom = despensaApp(['gifts_title' => 'Cestas & lotes']);
    contains('Cestas &amp; lotes', $custom->view()->render('catalog::front/home/categories', ['title' => 'x', 'categories' => $cats]));

    $items = [];
    for ($i = 1; $i <= 6; ++$i) {
        $items[] = ['id' => 100 + $i, 'name' => 'Producto ' . $i, 'url' => '/p/' . $i, 'image' => null, 'price' => ['amount' => 1000 * $i, 'currency' => 'EUR'], 'badges' => []];
    }
    $row = $app->view()->render('catalog::front/home/products', ['id' => 'catalog.new', 'title' => 'Novedades', 'items' => $items]);
    contains('class="ds-rail-track" role="region" aria-label="Novedades" tabindex="0"', $row);
    same(6, substr_count($row, '<div class="ds-rail-item">'), 'una celda por tarjeta');
    same(6, substr_count($row, '<article class="ds-card af-product-card'));
    contains('id="af-catalog-new-title"', $row);
    $css = despensaCssPacked();
    contains('scroll-snap-type:xmandatory', $css, 'la fila se ancla al deslizar');
    contains('scroll-snap-align:start', $css);
    notContains('<script', $row, 'sin guion para deslizar');

    $grid = despensaApp(['season_row' => 'grid']);
    $asGrid = $grid->view()->render('catalog::front/home/products', ['id' => 'catalog.new', 'title' => 'x', 'items' => $items]);
    contains('ds-grid af-grid ds-cols-4', $asGrid);
    notContains('ds-rail', $asGrid);
});

test('la tarjeta: tinte por categoría, etiqueta de origen, formato, precio por kilo, botón «+» fuera del enlace y todo escapado', function (): void {
    $app = despensaApp([], ['catalog', 'media', 'orders']);
    $image = static function (string $name): array {
        return ['id' => 1, 'url' => '/m/' . $name . '.jpg', 'card' => '/m/' . $name . '-card.jpg', 'width' => 600, 'height' => 600, 'alt' => ''];
    };
    $card = [
        'id' => 11, 'name' => 'Aceite <b>&', 'url' => '/producto/aceite?a=1&b=2', 'image' => $image('uno'), 'brand' => 'Casa <i>',
        'price' => ['amount' => 1290, 'with_tax' => true, 'compare' => 1590, 'discount_percent' => 19, 'currency' => 'EUR', 'from' => false],
        'rating' => ['average' => 4.5, 'count' => 3], 'badges' => ['sale', 'new'], 'has_variants' => false,
        'weight' => 500, 'main_category' => ['id' => 3, 'name' => 'Aceites'],
        'facts' => [['label' => 'Origen', 'value' => 'Jaén <i>'], ['label' => 'Formato', 'value' => 'Lata 500 g']],
    ];
    $html = $app->view()->render('partials/product-card', ['product' => $card]);
    contains('Aceite &lt;b&gt;&amp;', $html);
    notContains('Aceite <b>', $html);
    contains('href="/producto/aceite?a=1&amp;b=2"', $html);
    contains('data-tone="3"', $html, 'el tinte sale de la categoría principal');
    contains('class="ds-origin"', $html);
    contains('Jaén &lt;i&gt;', $html);
    notContains('Jaén <i>', $html);
    contains('<p class="ds-card-format">Lata 500 g</p>', $html, 'el dato clave de formato manda sobre el peso');
    contains('class="ds-unit"', $html);
    contains('25,80', $html, 'precio por kilo: 12,90 € por 500 g');
    contains('/ kg', $html);
    contains('Casa &lt;i&gt;', $html, 'la marca sale de serie');
    contains('af-badge-sale', $html);
    contains("\u{2212}19\u{a0}%", $html);
    contains('af-price-old', $html, 'el precio anterior sigue a la vista');
    contains('af-stars', $html);
    same(1, substr_count($html, 'class="ds-card-link af-product-card-link"'), 'todo el bloque enlaza una sola vez');
    check(strpos($html, '</a>') < strpos($html, '<form class="ds-add-form"'), 'el botón de añadir va fuera del enlace');
    contains('data-af-cart-add', $html);
    contains('name="product_id" value="11"', $html);
    contains('aria-label="Añadir al carrito"', $html);

    // Con variantes el «+» lleva a elegir formato; agotado, ninguno; y se puede apagar.
    $variants = $app->view()->render('partials/product-card', ['product' => ['id' => 12, 'name' => 'Con formatos', 'url' => '/producto/x', 'image' => null, 'has_variants' => true, 'badges' => [], 'price' => ['amount' => 500, 'currency' => 'EUR', 'from' => true], 'weight' => 500]]);
    contains('class="ds-add ds-add-options" href="/producto/x"', $variants);
    notContains('<form', $variants);
    notContains('ds-unit', $variants, 'precio «desde»: sin precio por kilo');
    $out = $app->view()->render('partials/product-card', ['product' => ['id' => 13, 'name' => 'Agotado', 'url' => '/producto/y', 'image' => null, 'badges' => ['out'], 'price' => ['amount' => 500, 'currency' => 'EUR']]]);
    notContains('ds-add', $out);
    contains('af-badge-out', $out);
    contains('is-out', $out);
    $off = despensaApp(['card_add' => 0, 'unit_price_show' => 0, 'show_brand_in_card' => 0, 'card_ratio' => 'portrait'], ['catalog', 'media', 'orders']);
    $plain = $off->view()->render('partials/product-card', ['product' => $card]);
    notContains('ds-add', $plain);
    notContains('ds-unit', $plain);
    notContains('Casa', $plain);
    contains('ds-ratio-portrait', $plain);
    $bare = $app->view()->render('partials/product-card', ['product' => ['name' => 'Sal', 'url' => '', 'image' => null, 'price' => ['amount' => 100, 'currency' => 'EUR'], 'badges' => []]]);
    notContains('<a ', $bare);
    notContains('<form', $bare);
    contains('af-noimage', $bare);
    contains('data-tone="1"', $bare, 'sin categoría ni identificador, el primer tinte');
});

test('el precio por kilo o litro, el formato y el origen: unidades, redondeo y casos raros', function (): void {
    $app = despensaApp();
    check($app instanceof App, 'hay aplicación');
    same(['kind' => 'mass', 'base' => 500], despensa_measure(['weight' => 500]));
    same(['kind' => 'volume', 'base' => 750], despensa_measure(['attributes' => [['number' => 0.75, 'unit' => 'l']], 'weight' => 300]), 'un atributo con unidad manda sobre el peso');
    same(['kind' => 'mass', 'base' => 2000], despensa_measure(['attributes' => [['number' => 2, 'unit' => 'kg']]]));
    same(['kind' => 'volume', 'base' => 330], despensa_measure(['attributes' => [['number' => 33, 'unit' => 'cl']]]));
    same(['kind' => 'mass', 'base' => 250], despensa_measure(['attributes' => [['number' => 5, 'unit' => 'oz']], 'weight' => 250]), 'una unidad desconocida se ignora');
    same(null, despensa_measure(['weight' => 0]));
    same(null, despensa_measure([]));
    same(null, despensa_measure(['attributes' => ['no es un array', ['number' => 'x', 'unit' => 'g']]]));

    same("500\u{a0}g", despensa_format_measure('mass', 500));
    same("1,5\u{a0}kg", despensa_format_measure('mass', 1500));
    same("1,25\u{a0}kg", despensa_format_measure('mass', 1250));
    same("2\u{a0}kg", despensa_format_measure('mass', 2000));
    same("250\u{a0}ml", despensa_format_measure('volume', 250));
    same("1\u{a0}l", despensa_format_measure('volume', 1000));

    $price = despensa_unit_price(1290, ['kind' => 'mass', 'base' => 500]);
    contains('25,80', $price);
    contains('/ kg', $price);
    contains('12,00', despensa_unit_price(900, ['kind' => 'volume', 'base' => 750]));
    contains('/ l', despensa_unit_price(900, ['kind' => 'volume', 'base' => 750]));
    contains('3,33', despensa_unit_price(1, ['kind' => 'mass', 'base' => 3]), 'redondea al céntimo más cercano');
    same('', despensa_unit_price(0, ['kind' => 'mass', 'base' => 500]));
    same('', despensa_unit_price(1290, null));
    same('', despensa_unit_price(1290, ['kind' => 'mass', 'base' => 0]));

    $facts = [['label' => 'Origen', 'value' => ' Jaén '], ['label' => 'Formato', 'value' => ''], ['label' => 'Region', 'value' => 'Sur'], 'basura', ['label' => 'sin valor']];
    same('Jaén', despensa_fact($facts, '/origen|origin/iu'));
    same('Sur', despensa_fact($facts, '/regi[oó]n/iu'));
    same('', despensa_fact($facts, '/formato/iu'), 'un dato sin valor no cuenta');
    same('', despensa_fact([], '/origen/iu'));

    $info = despensa_card_info(['id' => 0, 'weight' => 1000, 'price' => ['amount' => 2000, 'currency' => 'EUR'], 'facts' => [['label' => 'Procedencia', 'value' => 'Soria']]]);
    same('Soria', $info['origin']);
    same("1\u{a0}kg", $info['format']);
    contains('20,00', $info['unit']);
    same(1, $info['tone']);
    $tones = [];
    foreach ([1, 2, 6, 7, 13] as $category) {
        $tones[] = despensa_card_info(['id' => 0, 'main_category' => ['id' => $category]])['tone'];
    }
    same([1, 2, 6, 1, 1], $tones, 'seis tintes que se repiten en serie');
});

test('el listado: sin barra lateral, categorías como pastillas, orden a la derecha, filtros en un panel desde un botón y rejilla de tarjetas grandes', function (): void {
    $app = despensaApp();
    despensaSeed($app);
    despensaMenu($app);
    $web = new TestClient($app);
    $r = $web->get('/categoria/aceites');
    same(200, $r->status);
    $body = $r->body;
    same(1, substr_count($body, '<h1'));
    contains('<h1 class="ds-list-title af-title">Aceites</h1>', $body);
    contains('ds-listing af-listing', $body);
    contains('id="af-filters"', $body, 'el panel de filtros existe');
    contains('class="ds-filter-btn af-hide-sm" data-af-drawer-open="af-filters" aria-controls="af-filters"', $body, 'y se abre desde un botón');
    contains('id="af-filters-form"', $body);
    $panel = despensaSlice($body, '<aside class="ds-filters af-listing-filters af-drawer"', '</aside>');
    check(substr_count($panel, '<details class="ds-fgroup af-filter"') >= 2, 'un grupo plegable por faceta y el precio');
    contains('class="ds-chip af-check"', $panel, 'los valores son pastillas');
    contains('name="min"', $panel);
    contains('name="max"', $panel);
    contains('name="stock"', $panel);
    contains('name="sale"', $panel);
    contains('type="submit"', $panel);
    contains('name="sort" form="af-filters-form" data-af-autosubmit', $body, 'el orden se envía con el formulario de filtros');
    contains('class="ds-grid af-grid ds-cols-3"', $body);
    same(5, substr_count($body, '<article class="ds-card af-product-card'), 'cinco productos en la rejilla');
    contains('class="ds-pills"', $body, 'sin subcategorías, las pastillas salen del menú principal');
    contains('<a class="ds-pill" href="/quesos">Quesos</a>', $body);
    contains('aria-current="page"', despensaSlice($body, '<nav class="ds-pills"', '</nav>'));
    notContains('Roto', $body);

    // Con subcategorías, las pastillas son ellas, con su cuenta.
    $parent = aifecCategory($app, 'Con hijas');
    $child = aifecCategory($app, 'Hija', $parent);
    aifecProduct($app, ['name' => 'En la hija', 'slug' => 'en-la-hija', 'categories' => [$child]]);
    $withKids = $web->get('/categoria/con-hijas')->body;
    contains('>Hija', despensaSlice($withKids, '<nav class="ds-pills"', '</nav>'));
    notContains('href="/quesos"', despensaSlice($withKids, '<nav class="ds-pills"', '</nav>'), 'con subcategorías no salen las del menú');

    $css = despensaCssPacked();
    contains('.ds-listing,.af-listing{display:block}', $css, 'en escritorio no hay columna lateral');
    notContains('grid-template-columns:16rem', $css);
    check(preg_match('/\.ds-filters\.af-drawer\{[^}]*transform:translateX\(100%\)/', $css) === 1, 'el panel de filtros está plegado hasta que se abre');
    check(preg_match('/\.ds-pills\{[^}]*overflow-x:auto/', $css) === 1, 'las pastillas van en una fila desplazable');
});

test('el listado, con cuatro columnas si se elige, vacío y sin filtros marcados sigue funcionando, y la página de error va con el envoltorio de despensa', function (): void {
    $app = despensaApp(['listing_cols' => '4']);
    despensaSeed($app);
    contains('ds-grid af-grid ds-cols-4', (new TestClient($app))->get('/categoria/aceites')->body);

    $empty = despensaApp();
    aifecCategory($empty, 'Vacía');
    $web = new TestClient($empty);
    $r = $web->get('/categoria/vacia');
    same(200, $r->status);
    contains('ds-empty af-card af-empty', $r->body);
    contains('No se ha encontrado ningún producto', $r->body);
    notContains('class="ds-grid', $r->body);
    $missing = $web->get('/esto-no-existe');
    same(404, $missing->status);
    contains('ds-header af-header', $missing->body);
    contains('class="af-empty af-error"', $missing->body);
    same(1, substr_count($missing->body, '<h1'));
});

test('contrato de móvil: cabecera pegajosa y barra inferior con buscar, carrito con contador y cuenta; con filtros solo en el listado', function (): void {
    $app = despensaApp();
    despensaSeed($app);
    despensaShopRoutes($app);
    $web = new TestClient($app);
    $home = $web->get('/')->body;
    contains('<nav class="ds-bar af-mobile-bar af-show-sm"', $home);
    contains('data-af-drawer-open="af-search"', $home);
    contains('data-af-drawer-open="af-cart"', $home);
    contains('ds-bar-item ds-bar-cart af-mobile-bar-item', $home, 'el carrito es el botón de color');
    contains('data-af-cart-count', $home);
    contains('af-mobile-bar-label">Cuenta', $home);
    contains('af-has-bar', $home);
    notContains('data-af-drawer-open="af-filters"', $home, 'sin filtros funcionales no hay botón de filtros');
    contains('id="af-cart"', $home);
    contains('data-af-cart-lines', $home);
    contains('id="af-search"', $home, 'el panel de búsqueda del móvil existe');
    $list = $web->get('/categoria/aceites')->body;
    contains('data-af-drawer-open="af-filters" aria-controls="af-filters" aria-expanded="false">', despensaSlice($list, '<nav class="ds-bar', '</nav>'));

    $css = despensaCssPacked();
    check(preg_match('/\.af-header\{[^}]*position:sticky/', $css) === 1, 'la cabecera es pegajosa');
    check(preg_match('/\.af-mobile-bar\{[^}]*position:fixed[^}]*bottom:0/', $css) === 1, 'la barra inferior es fija abajo');
    check(preg_match('/\.af-has-bar\{padding-bottom:calc\(var\(--bar-h\)\+env\(safe-area-inset-bottom\)\)/', $css) === 1, 'el contenido reserva el alto de la barra');
    check(preg_match('/\.af-mobile-bar-item\{[^}]*min-width:44px[^}]*min-height:calc\(var\(--bar-h\)/', $css) === 1, 'áreas táctiles de 44 px');
    check(preg_match('/--bar-h:([0-9.]+)rem/', $css, $bar) === 1 && (float) $bar[1] * 16 >= 52, 'la barra mide lo que mide el botón más su margen');
    contains('.af-show-sm{display:none!important}', $css);
    contains('.af-hide-sm{display:none!important}', $css);
});

test('las opciones de estructura del tema llegan al HTML: franja, cabecera, buscador, fila de temporada, historia, columnas y productor', function (): void {
    $off = (new TestClient(despensaApp(['strip_show' => 0])))->get('/')->body;
    notContains('ds-strip', $off);
    $custom = (new TestClient(despensaApp(['strip_text' => 'Envíos gratis & rápidos'])))->get('/')->body;
    contains('Envíos gratis &amp; rápidos', $custom);

    $classic = (new TestClient(despensaApp(['header_style' => 'classic'])))->get('/')->body;
    contains('ds-header af-header ds-header-classic', $classic);
    contains('ds-header af-header ds-header-centered', (new TestClient(despensaApp()))->get('/')->body);

    $bar = despensaApp(['search_style' => 'bar']);
    despensaShopRoutes($bar);
    $barBody = (new TestClient($bar))->get('/')->body;
    contains('class="ds-search-bar af-search af-hide-sm"', $barBody);
    notContains('<details class="ds-search', $barBody);

    $left = (new TestClient(despensaApp(['story_side' => 'left'])))->get('/')->body;
    contains('ds-story ds-story-left', $left);

    $story = despensaApp(['story_kicker' => 'Desde 1985', 'story_title' => 'Aceite & pan', 'story_text' => 'Texto <b>', 'story_cta' => 'Ver todo', 'story_link' => '/novedades']);
    $home = (new TestClient($story))->get('/')->body;
    contains('Desde 1985', $home);
    contains('Aceite &amp; pan', $home);
    contains('Texto &lt;b&gt;', $home);
    contains('href="/novedades"', $home);
    contains('href="#ds-shop"', (new TestClient(despensaApp()))->get('/')->body, 'sin enlace elegido, el botón baja a la tienda');
    $bad = (new TestClient(despensaApp(['story_link' => 'javascript:alert(1)'])))->get('/')->body;
    notContains('javascript:', $bad);

    $producer = despensaApp(['producer_title' => 'Familia & finca', 'producer_place' => 'Sierra de Cazorla', 'producer_text' => 'Tres generaciones']);
    $html = $producer->view()->render('partials/producer');
    contains('Familia &amp; finca', $html);
    contains('Origen: Sierra de Cazorla', $html);
    contains('Tres generaciones', $html);
    same('', trim(despensaApp(['producer_show' => 0])->view()->render('partials/producer')), 'apagado, no pinta nada');

    // El acento y el radio llegan como variable del <head>.
    $accent = (new TestClient(despensaApp(['accent' => '#112233'])))->get('/')->body;
    contains('--accent:#112233', $accent);
    contains('--radius:16px', $accent);
});

test('las recetas del blog salen como tarjetas escapadas y sin entradas no pintan nada', function (): void {
    $app = despensaApp();
    $posts = [
        ['title' => 'Salmorejo <b>&', 'url' => '/blog/salmorejo', 'excerpt' => 'Con pan & tomate', 'image' => ['id' => 1, 'url' => '/m/s.jpg', 'card' => '/m/s-card.jpg', 'width' => 600, 'height' => 400, 'alt' => '']],
        ['title' => 'Sin imagen', 'url' => '/blog/otra', 'excerpt' => ''],
    ];
    $html = $app->view()->render('partials/recipes', ['posts' => $posts]);
    contains('Recetas de la despensa', $html);
    same(2, substr_count($html, '<article class="ds-recipe">'));
    contains('Salmorejo &lt;b&gt;&amp;', $html);
    notContains('Salmorejo <b>', $html);
    contains('Con pan &amp; tomate', $html);
    same(1, substr_count($html, 'class="ds-recipe-media"'), 'solo la que tiene imagen lleva figura');
    same('', trim($app->view()->render('partials/recipes', ['posts' => []])));
    same([], despensa_home_posts(3), 'sin módulo de blog, ninguna receta');
});

test('las ayudas del tema: enlaces seguros, menús sin elementos rotos y una imagen que no existe', function (): void {
    $app = despensaApp();
    check(function_exists('despensa_link') && function_exists('despensa_image') && function_exists('despensa_menu'), 'functions.php define las ayudas');
    same('', despensa_link('javascript:alert(1)'));
    same('', despensa_link('//otro.test/x'));
    same('', despensa_link('data:text/html,x'));
    same('', despensa_link(''));
    same('#tienda', despensa_link('#tienda'));
    same('https://tienda.test/x', despensa_link('https://tienda.test/x'));
    same('/novedades', despensa_link('/novedades'));
    same(null, despensa_image('story_image'), 'sin elección no hay imagen');
    $custom = despensaApp(['story_image' => 99999]);
    same(null, despensa_image('story_image'), 'un identificador que no existe tampoco');
    same(200, (new TestClient($custom))->get('/')->status);

    $menu = despensa_menu([
        ['label' => 'A', 'url' => '/a', 'children' => [['label' => 'B', 'url' => '', 'children' => []], ['label' => 'C', 'url' => '/c']]],
        ['label' => '', 'url' => '/sin-texto'],
        ['label' => 'Roto', 'url' => ''],
        'basura',
        ['label' => 'Grupo', 'url' => '', 'children' => [['label' => 'D', 'url' => '/d']]],
    ]);
    same(['A', 'Grupo'], array_map(static function (array $item): string {
        return $item['label'];
    }, $menu));
    same(['C'], array_map(static function (array $item): string {
        return $item['label'];
    }, $menu[0]['children']), 'el hijo sin dirección ni hijos no se pinta');
    same([], despensa_menu([['label' => 'X', 'url' => '/x', 'children' => [['label' => 'Y', 'url' => '/y']]]], 0)[0]['children'], 'sin niveles por debajo, no hay hijos');
    $app->boot();
});

test('el resumen de las tarjetas se olvida al acabar la petición', function (): void {
    $app = despensaApp();
    despensaSeed($app);
    despensa_card_info(['id' => 777, 'weight' => 100]);
    check(is_array($app->view()->shared('ds_info')) && isset($app->view()->shared('ds_info')[777]), 'queda en la vista durante la petición');
    (new TestClient($app))->get('/');
    same([], $app->view()->shared('ds_info'), 'request.end lo vacía');
});

test('estructura distinta de la del tema base y de los otros temas de sector: cabecera, tarjeta, listado y ficha se parecen menos de un 82 %', function (): void {
    $mine = despensaSurfaces('despensa');
    $others = ['base'];
    foreach (['atelier', 'taller'] as $slug) {
        if (is_dir(dirname(despensaDir()) . '/' . $slug)) {
            $others[] = $slug;
        }
    }
    foreach ($others as $slug) {
        $other = despensaSurfaces($slug);
        foreach (['header', 'card', 'listing', 'product'] as $surface) {
            $byClass = despensaSimilarity(despensaSequence($other[$surface], true), despensaSequence($mine[$surface], true));
            $byTag = despensaSimilarity(despensaSequence($other[$surface], false), despensaSequence($mine[$surface], false));
            check($byClass < 0.82, $slug . ' / ' . $surface . ': la secuencia etiqueta.clase se parece un ' . round($byClass * 100) . ' %');
            check($byTag < 0.82, $slug . ' / ' . $surface . ': la secuencia de etiquetas se parece un ' . round($byTag * 100) . ' %');
            check(strlen($mine[$surface]) > 200, $surface . ' tiene contenido para comparar');
        }
    }
    check(count($others) >= 1, 'se ha comparado con el tema base');
});

test('un nombre de producto con <b>&: escapado en tarjeta, ficha, listado y muestra', function (): void {
    $app = despensaApp();
    despensaSeed($app);
    $web = new TestClient($app);
    $product = $web->get('/producto/aceite-oliva')->body;
    contains('Aceite &lt;b&gt;&amp;', $product);
    notContains('Aceite <b>', $product);
    $list = $web->get('/categoria/aceites')->body;
    contains('Aceite &lt;b&gt;&amp;', $list);
    notContains('Aceite <b>', $list);
    $sample = $web->get('/tema/muestra')->body;
    same(1, substr_count($sample, '<h1'));
});

test('el carrito vacío y la muestra del tema se ven con la hoja de despensa y sin errores', function (): void {
    $app = despensaApp([], ['catalog', 'media', 'orders']);
    $web = new TestClient($app);
    $cart = $web->get('/carrito');
    same(200, $cart->status);
    contains('af-empty', $cart->body);
    same(1, substr_count($cart->body, '<h1'));
    $sample = $web->get('/tema/muestra?filtros=1');
    same(200, $sample->status);
    contains('data-af-drawer-open="af-filters"', $sample->body);
    contains('af-product-card', $sample->body);
    contains('data-af-gallery', $sample->body);
    $imgs = [];
    preg_match_all('/<img\b[^>]*>/', $sample->body, $imgs);
    foreach ($imgs[0] as $img) {
        check(strpos($img, ' width="') !== false && strpos($img, ' height="') !== false, 'toda imagen lleva ancho y alto: ' . substr($img, 0, 80));
    }
});

test('peso: theme.css pesa como mucho 45 000 bytes y el guion propio como mucho 8 000', function (): void {
    $css = despensaDir() . '/assets/css/theme.css';
    $js = despensaDir() . '/assets/js/theme.js';
    check(is_file($css) && is_file($js), 'existen la hoja y el guion');
    check(filesize($css) <= 45000, 'theme.css pesa ' . filesize($css));
    check(filesize($js) <= 8000, 'theme.js pesa ' . filesize($js));
});

test('ninguna dirección externa en las plantillas ni en los recursos (salvo el espacio de nombres de los SVG) y ningún @import', function (): void {
    $files = array_merge(despensaFiles('templates'), despensaFiles('assets'));
    check(count($files) >= 10, 'se han encontrado las plantillas y los recursos');
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        same(0, preg_match('#https?://(?!www\.w3\.org)#i', $text), basename($file) . ' lleva una dirección externa');
        notContains('@import', $text, basename($file));
        notContains('//cdn', $text, basename($file));
    }
    foreach (['story.svg', 'map.svg'] as $svg) {
        $xml = @simplexml_load_string((string) file_get_contents(despensaDir() . '/assets/img/' . $svg));
        check($xml !== false, $svg . ' es un SVG bien formado');
    }
});

test('theme.css: ningún color fuera de :root, sin fuentes ni iconos de fuera y con los medios de accesibilidad', function (): void {
    $css = (string) file_get_contents(despensaDir() . '/assets/css/theme.css');
    // La condición del @supports nombra color-mix(): se quita antes de mirar, lo que importa son las reglas.
    $rest = despensaCssWithoutRoot((string) preg_replace('/@supports[^{]*\{/', '@supports{', $css));
    $found = [];
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $rest, $found);
    same([], $found[0], 'colores fijos fuera de :root');
    check(preg_match('/\b(?:rgb|rgba|hsl|hsla)\(/', $rest) === 0, 'colores funcionales fuera de :root');
    check(preg_match('/color-mix\(/', $rest) === 0, 'las mezclas de color también viven en :root');
    notContains('@font-face', $css);
    notContains('url(', $css);
    foreach (['prefers-reduced-motion', ':focus-visible', 'env(safe-area-inset-bottom)', 'min-height:44px', 'min-width:44px', 'flex-wrap:wrap', 'overflow-x:auto', 'line-through', 'object-fit:contain', 'position:sticky', 'scroll-snap-type'] as $needle) {
        contains($needle, $css, 'theme.css no contiene «' . $needle . '»');
    }
    notContains('fonts.googleapis', $css);
});

test('theme.css da estilo a todas las clases del contrato de estilos y a las que pintan las plantillas propias', function (): void {
    $css = (string) file_get_contents(despensaDir() . '/assets/css/theme.css');
    $classes = [
        'af-container', 'af-section', 'af-grid', 'af-cols-2', 'af-cols-3', 'af-cols-4', 'af-cols-5', 'af-cols-6', 'af-stack', 'af-row', 'af-split', 'af-sr',
        'af-hide-sm', 'af-show-sm', 'af-title', 'af-subtitle', 'af-muted', 'af-small', 'af-prose', 'af-btn', 'af-btn-primary', 'af-btn-ghost', 'af-btn-block',
        'af-btn-sm', 'af-btn-lg', 'af-input', 'af-select', 'af-textarea', 'af-field', 'af-hint', 'af-error', 'af-check', 'af-radio-card', 'af-qty', 'af-alert',
        'af-alert-ok', 'af-alert-bad', 'af-alert-info', 'af-alert-warn', 'af-badge', 'af-badge-sale', 'af-badge-new', 'af-badge-out', 'af-stars',
        'af-product-card', 'af-price', 'af-price-now', 'af-price-old', 'af-price-off', 'af-price-note', 'af-listing', 'af-listing-filters', 'af-listing-results',
        'af-filter', 'af-product', 'af-product-gallery', 'af-product-buy', 'af-gallery', 'af-gallery-main', 'af-gallery-thumbs', 'af-variants', 'af-variant',
        'af-availability', 'is-ok', 'is-low', 'is-out', 'af-facts', 'af-tabs', 'af-cart', 'af-cart-line', 'af-totals', 'af-totals-row', 'af-totals-total',
        'af-steps', 'af-summary', 'af-table', 'af-card', 'af-empty', 'af-mobile-bar', 'af-drawer', 'af-drawer-backdrop',
    ];
    foreach ($classes as $class) {
        check(preg_match('/\.' . preg_quote($class, '/') . '(?![A-Za-z0-9_-])/', $css) === 1, 'theme.css no da estilo a .' . $class);
    }
    // Las clases «ds-» de las plantillas: las que no llevan regla propia son marcas de estructura (tienen que estar en esta lista).
    $markers = [
        'ds-card-link', 'ds-card-body', 'ds-card-name', 'ds-card-brand', 'ds-card-media', 'ds-card-rating', 'ds-badges', 'ds-list', 'ds-results',
        'ds-pdp-wrap', 'ds-pdp-gallery', 'ds-gal', 'ds-more', 'ds-notes', 'ds-desc', 'ds-specs', 'ds-faq', 'ds-pairs', 'ds-stock', 'ds-delivery', 'ds-buy',
        'ds-buy-row', 'ds-formats', 'ds-formats-title', 'ds-formats-list', 'ds-home', 'ds-filters-title', 'ds-filters-body', 'ds-bar', 'ds-bar-item', 'ds-bar-icon',
        'ds-bar-label', 'ds-search-input', 'ds-cart-label', 'ds-header', 'ds-menu', 'ds-pdp', 'ds-pdp-buy', 'ds-gal-main', 'ds-gal-thumbs', 'ds-gifts', 'ds-recipes',
        'ds-story-cta', 'ds-season', 'ds-list-intro', 'ds-add-options', 'ds-pdp-head', 'ds-thumb',
    ];
    $own = [];
    foreach (despensaFiles('templates') as $file) {
        $text = (string) file_get_contents($file);
        $m = [];
        preg_match_all('/class="([^"]*)"/', $text, $m);
        foreach ($m[1] as $attr) {
            $attr = (string) preg_replace('/<\?=.*?\?>/s', ' ', $attr);
            foreach (preg_split('/\s+/', trim($attr)) ?: [] as $name) {
                if (strpos($name, 'ds-') === 0 && substr($name, -1) !== '-') {
                    $own[$name] = true;
                }
            }
        }
    }
    check(count($own) > 60, 'se han encontrado las clases propias: ' . count($own));
    $unstyled = [];
    foreach (array_keys($own) as $name) {
        if (preg_match('/\.' . preg_quote($name, '/') . '(?![A-Za-z0-9_-])/', $css) !== 1 && !in_array($name, $markers, true)) {
            $unstyled[] = $name;
        }
    }
    same([], $unstyled, 'clases propias sin regla en theme.css');
    // Y lo que está en la lista de marcas existe de verdad en alguna plantilla (la lista no se pudre).
    foreach ($markers as $name) {
        check(isset($own[$name]), 'la marca «' . $name . '» ya no está en ninguna plantilla');
    }
});

test('theme.json es JSON válido, hijo de base con su hoja propia, y cada opción con var existe en :root', function (): void {
    $json = json_decode((string) file_get_contents(despensaDir() . '/theme.json'), true);
    check(is_array($json), 'JSON válido');
    same('Despensa', $json['name']);
    same('base', $json['parent']);
    same('replace', $json['css'], 'la hoja sustituye a la del padre');
    check(!isset($json['js']) || $json['js'] !== 'replace', 'el guion del padre se sigue cargando');
    $css = (string) file_get_contents(despensaDir() . '/assets/css/theme.css');
    preg_match_all('/(?::where\(:root\)|:root)\s*\{[^}]*\}/', $css, $blocks);
    $root = implode("\n", $blocks[0]);
    $vars = [];
    $plain = [];
    foreach ($json['settings'] as $group) {
        foreach ($group['fields'] as $key => $field) {
            if (isset($field['var'])) {
                $vars[$key] = $field['var'];
                contains($field['var'] . ':', $root, 'la opción «' . $key . '» no está en :root');
            } else {
                $plain[] = $key;
            }
        }
    }
    foreach (['--bg', '--surface', '--ink', '--muted', '--line', '--accent', '--accent-ink', '--sale', '--ok', '--warn', '--bad', '--font', '--font-head', '--radius', '--container', '--olive', '--tone-1', '--tone-6'] as $var) {
        check(in_array($var, $vars, true), 'falta la opción para ' . $var);
    }
    check(count(array_diff($plain, ['header_style', 'card_ratio', 'show_brand_in_card'])) >= 3, 'al menos tres opciones de estructura propias: ' . implode(', ', $plain));
    foreach (['strip_show', 'search_style', 'season_row', 'listing_cols', 'card_add', 'gallery_thumbs', 'story_side', 'producer_show'] as $key) {
        check(in_array($key, $plain, true), 'falta la opción de estructura ' . $key);
    }
    foreach ($json['settings']['typography']['fields']['font_head']['options'] as $stack => $label) {
        check(isset($json['font_stacks'][$stack]) || in_array($stack, ['system', 'serif', 'rounded', 'mono'], true), 'la tipografía «' . $stack . '» no tiene pila');
    }
    check(preg_match('/https?:/', json_encode($json['font_stacks']) ?: '') === 0, 'las pilas son del sistema');
    same(16.0, (float) $json['settings']['layout']['fields']['radius']['default'], 'esquinas grandes');
    foreach (['colors', 'tones'] as $group) {
        foreach ($json['settings'][$group]['fields'] as $key => $field) {
            check(preg_match('/^#[0-9a-f]{6}$/', $field['default']) === 1, 'el color «' . $key . '» tiene un valor de fábrica válido');
        }
    }
});

test('el contraste de los colores de fábrica cumple AA en texto, precio anterior, insignias y texto sobre el acento', function (): void {
    $json = json_decode((string) file_get_contents(despensaDir() . '/theme.json'), true);
    $c = [];
    foreach (['colors', 'tones'] as $group) {
        foreach ($json['settings'][$group]['fields'] as $key => $field) {
            $c[$key] = $field['default'];
        }
    }
    $lum = static function (string $hex): float {
        $out = 0.0;
        foreach ([1, 3, 5] as $i => $pos) {
            $v = hexdec(substr($hex, $pos, 2)) / 255;
            $v = $v <= 0.03928 ? $v / 12.92 : (($v + 0.055) / 1.055) ** 2.4;
            $out += [0.2126, 0.7152, 0.0722][$i] * $v;
        }

        return $out;
    };
    $ratio = static function (string $a, string $b) use ($lum): float {
        $x = $lum($a);
        $y = $lum($b);

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    };
    $white = '#ffffff';
    $pairs = [
        'texto sobre fondo' => [$c['ink'], $c['bg']], 'texto sobre tarjeta' => [$c['ink'], $c['surface']], 'texto secundario sobre fondo' => [$c['muted'], $c['bg']],
        'texto secundario sobre tarjeta' => [$c['muted'], $c['surface']], 'precio anterior sobre tarjeta' => [$c['muted'], $c['surface']],
        'texto sobre el acento' => [$c['accent_ink'], $c['accent']], 'acento sobre tarjeta' => [$c['accent'], $c['surface']],
        'insignia de oferta' => [$white, $c['sale']], 'insignia de agotado' => [$white, $c['muted']], 'franja y pie sobre verde oliva' => [$white, $c['olive']],
        'verde oliva sobre tarjeta' => [$c['olive'], $c['surface']],
    ];
    foreach (['tone_1', 'tone_2', 'tone_3', 'tone_4', 'tone_5', 'tone_6'] as $tone) {
        $pairs['texto sobre ' . $tone] = [$c['ink'], $c[$tone]];
        $pairs['texto secundario sobre ' . $tone] = [$c['muted'], $c[$tone]];
    }
    $pairs['verde oliva sobre tinte 2'] = [$c['olive'], $c['tone_2']];
    $pairs['acento sobre tinte 1'] = [$c['accent'], $c['tone_1']];
    foreach ($pairs as $name => $pair) {
        check($ratio($pair[0], $pair[1]) >= 4.5, $name . ': contraste ' . round($ratio($pair[0], $pair[1]), 2));
    }
});

test('todas las plantillas y ficheros PHP del tema empiezan por la cabecera del proyecto, en UTF-8 sin BOM, con LF y sin etiqueta de cierre', function (): void {
    $names = ['home', 'partials/header', 'partials/mobile-bar', 'partials/product-card', 'partials/producer', 'partials/recipes', 'modules/catalog/front/listing',
        'modules/catalog/front/product', 'modules/catalog/front/partials/filters', 'modules/catalog/front/partials/options', 'modules/catalog/front/home/categories',
        'modules/catalog/front/home/products'];
    foreach ($names as $name) {
        check(is_file(despensaDir() . '/templates/' . $name . '.php'), 'falta templates/' . $name . '.php');
    }
    foreach (['functions.php', 'lang/es.php', 'assets/css/theme.css', 'assets/js/theme.js', 'assets/img/story.svg', 'assets/img/map.svg', 'theme.json', 'preview.png'] as $extra) {
        check(is_file(despensaDir() . '/' . $extra), 'falta ' . $extra);
    }
    $size = getimagesize(despensaDir() . '/preview.png');
    check(is_array($size) && $size[0] === 1200 && $size[1] === 900, 'preview.png mide 1200 × 900');
    $php = array_filter(despensaFiles(), static function (string $file): bool {
        return substr($file, -4) === '.php';
    });
    check(count($php) >= 14, 'se han encontrado los ficheros PHP: ' . count($php));
    foreach ($php as $file) {
        $text = (string) file_get_contents($file);
        $head = substr($text, 0, 900);
        check(strpos($head, "<?php\n/**\n * AIFEC") === 0, basename($file) . ' no empieza por la cabecera del proyecto');
        contains('@author    Ecom Experts <ecomyseo@gmail.com>', $head);
        contains('@copyright 2026 Ecom Experts', $head);
        notContains("\r", $text, basename($file) . ' lleva retornos de carro');
        notContains("\xEF\xBB\xBF", $text, basename($file) . ' lleva BOM');
        if (strpos($file, '/templates/') === false) {
            check(substr(rtrim($text), -2) !== '?>', basename($file) . ' no lleva etiqueta de cierre');
        }
        check(preg_match('/\b(?:readonly|match\s*\(|enum\s+\w+)\b/', $text) === 0 || basename($file) === 'es.php', basename($file) . ': sintaxis posterior a PHP 7.4');
        check(preg_match('/\?->/', $text) === 0, basename($file) . ' usa ?->');
        check(preg_match('/\b(?:echo|print)\s/', $text) === 0 || strpos($file, '/templates/') !== false, basename($file) . ' pinta fuera de una plantilla');
    }
});

test('theme.js propio: solo comportamiento mínimo, sin ventanas del navegador ni peticiones y sin cadenas visibles', function (): void {
    $js = (string) file_get_contents(despensaDir() . '/assets/js/theme.js');
    foreach (['alert(', 'confirm(', 'prompt(', 'eval(', 'document.write', 'innerHTML', 'fetch(', 'XMLHttpRequest', 'http://', 'https://'] as $banned) {
        notContains($banned, $js, 'theme.js no puede usar ' . $banned);
    }
    foreach (['details.ds-search', 'ds-rail-track', 'prefers-reduced-motion', 'Escape'] as $needle) {
        contains($needle, $js, 'theme.js no contiene «' . $needle . '»');
    }
    notContains('"use strict"', $js);
    contains("'use strict'", $js);
});

test('el catálogo castellano del tema tiene todas las cadenas, ninguna vacía y con tildes y eñes', function (): void {
    $lang = require despensaDir() . '/lang/es.php';
    check(is_array($lang) && count($lang) > 100, 'el catálogo está cargado');
    foreach ($lang as $key => $value) {
        check(is_string($value) && trim($value) !== '', 'la cadena «' . $key . '» no tiene traducción');
    }
    same('Añadir al carrito', $lang['Add to cart']);
    same('Notas de cata', $lang['Tasting notes']);
    same('Lotes y regalos', $lang['Gift sets and hampers']);
    same('Maridaje', $lang['Pairs well with']);
    same('{price} / kg', $lang['{price} / kg']);
    $app = despensaApp([], ['catalog', 'media', 'orders']);
    despensaSeed($app);
    $body = (new TestClient($app))->get('/producto/aceite-oliva')->body;
    contains('Añadir al carrito', $body);
    contains('Notas de cata', $body);
    notContains('Add to cart', $body);
    notContains('Tasting notes', $body);
});
