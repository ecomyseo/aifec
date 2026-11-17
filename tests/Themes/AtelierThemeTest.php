<?php
/**
 * AIFEC — pruebas del tema atelier (moda): que sus cinco superficies responden con su hoja y su estructura propias, que
 * conservan los datos y los huecos de las plantillas que sustituyen, el contrato de móvil, las opciones del tema y las
 * comprobaciones estáticas del presupuesto de peso y del contrato de estilos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Kernel\App;

require_once dirname(__DIR__, 2) . '/modules/catalog/tests/helpers.php';

/** Carpeta del tema atelier. */
function atelierDir(): string
{
    return rtrim(str_replace('\\', '/', Autoload::root()), '/') . '/themes/atelier';
}

/**
 * Todos los ficheros de una carpeta del tema (recursivo).
 *
 * @return string[]
 */
function atelierFiles(string $sub = ''): array
{
    $files = [];
    $dir = atelierDir() . ($sub !== '' ? '/' . $sub : '');
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
function atelierCssWithoutRoot(string $css): string
{
    return (string) preg_replace('/:where\(:root\)\s*\{[^}]*\}|:root\s*\{[^}]*\}/', '', $css);
}

/** La hoja sin comentarios ni espacios, para comparar reglas sin depender del formato. */
function atelierCssPacked(): string
{
    $css = (string) file_get_contents(atelierDir() . '/assets/css/theme.css');
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

    return (string) preg_replace('/\s+/', '', $css);
}

/**
 * Aplicación con el tema activo, el catálogo y los medios, arrancada.
 *
 * @param array<string,mixed> $options  opciones del tema que cambia el comerciante
 * @param string[]            $modules
 * @param string              $theme    atelier o base (para comparar estructuras)
 */
function atelierApp(array $options = [], array $modules = ['catalog', 'media'], string $theme = 'atelier'): App
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
function atelierShopRoutes(App $app): void
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
function atelierMenu(App $app): void
{
    $app->hooks()->filter('menu.items', static function (array $items, string $location): array {
        if ($location !== 'main') {
            return $items;
        }

        return [
            ['label' => 'Mujer', 'url' => '/mujer', 'current' => true, 'children' => [
                ['label' => 'Vestidos', 'url' => '/mujer/vestidos', 'children' => [['label' => 'De lino', 'url' => '/mujer/vestidos/lino'], ['label' => 'De fiesta', 'url' => '/mujer/vestidos/fiesta']]],
                ['label' => 'Abrigos', 'url' => '/mujer/abrigos', 'children' => []],
            ]],
            ['label' => 'Hombre', 'url' => '/hombre', 'children' => []],
            ['label' => 'Roto', 'url' => ''],
            ['label' => 'Grupo vacío', 'url' => '', 'children' => []],
        ];
    });
}

/**
 * La tienda de las pruebas: una categoría, una marca, tallas y colores, un vestido con variantes, tres imágenes, preguntas y
 * relacionados, y otros cuatro productos para llenar el listado. Con los mismos datos en cualquier tema.
 *
 * @return array<string,int>
 */
function atelierSeed(App $app): array
{
    $app->boot();
    $ids = [];
    $ids['cat'] = aifecCategory($app, 'Vestidos');
    $ids['brand'] = aifecBrand($app, 'Maison Norte');
    aifecAttribute($app, 'talla', 'Talla', ['s' => 'S', 'm' => 'M', 'l' => 'L']);
    aifecAttribute($app, 'color', 'Color', ['negro' => 'Negro', 'crudo' => 'Crudo'], ['colors' => ['negro' => '#111111', 'crudo' => '#e8dfd0']]);
    $ids['falda'] = aifecProduct($app, ['name' => 'Falda midi', 'slug' => 'falda-midi', 'price' => 5900, 'images' => 2, 'categories' => [$ids['cat']], 'brand_id' => $ids['brand']]);
    $ids['blusa'] = aifecProduct($app, ['name' => 'Blusa de seda', 'slug' => 'blusa-seda', 'price' => 7900, 'images' => 1, 'categories' => [$ids['cat']], 'stock' => 0]);
    aifecProduct($app, ['name' => 'Abrigo largo', 'slug' => 'abrigo-largo', 'price' => 19900, 'images' => 2, 'categories' => [$ids['cat']]]);
    aifecProduct($app, ['name' => 'Pantalón recto', 'slug' => 'pantalon-recto', 'price' => 6900, 'categories' => [$ids['cat']]]);
    $ids['dress'] = aifecProduct($app, [
        'name' => 'Vestido <b>&', 'slug' => 'vestido-lino', 'price' => 8900, 'compare_price' => 11900, 'images' => 3,
        'categories' => [$ids['cat']], 'brand_id' => $ids['brand'], 'sku' => 'VES-1', 'gtin' => '8412345678905',
        'summary' => '<p>Resumen corto</p>', 'description' => '<p>Texto largo del vestido</p>',
        'facts' => [['label' => 'Tejido', 'value' => 'Lino']], 'attributes' => ['material' => 'lino'],
        'faqs' => [['question' => '¿Encoge al lavarlo?', 'answer' => '<p>Poco.</p>']],
        'related' => [$ids['falda'], $ids['blusa']],
        'variants' => [
            ['sku' => 'VES-S', 'stock' => 3, 'values' => ['talla' => 's']],
            ['sku' => 'VES-M', 'stock' => 5, 'values' => ['talla' => 'm']],
        ],
    ]);

    return $ids;
}

/**
 * La secuencia «etiqueta.clase» de las etiquetas de abrir de un trozo de HTML: lo que sirve para comparar composiciones.
 */
function atelierSequence(string $html, bool $withClass): string
{
    // La compra desde la tarjeta (form[data-af-co]) es una pieza del catálogo, la misma en todos los temas: no es composición del tema.
    $html = (string) preg_replace('#<form\b[^>]*\bdata-af-co\b[^>]*>.*?</form>#s', '', $html);
    $found = [];
    preg_match_all('/<([a-z][a-z0-9]*)\b([^>]*)>/i', $html, $found, PREG_SET_ORDER);
    $out = [];
    foreach ($found as $tag) {
        $name = strtolower($tag[1]);
        if ($name === 'script' || $name === 'path' || $name === 'circle' || $name === 'svg') {
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
function atelierSimilarity(string $a, string $b): float
{
    $percent = 0.0;
    similar_text($a, $b, $percent);

    return $percent / 100;
}

/** La parte de una página que va entre dos marcas. */
function atelierSlice(string $html, string $from, string $to): string
{
    $start = strpos($html, $from);
    check($start !== false, 'no está «' . $from . '»');
    $end = strpos($html, $to, (int) $start);
    check($end !== false, 'no está «' . $to . '»');

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/** Cada superficie de una tienda con los mismos datos, para compararla entre temas. */
function atelierSurfaces(string $theme): array
{
    $app = atelierApp([], ['catalog', 'media', 'orders'], $theme);
    atelierSeed($app);
    atelierMenu($app);
    atelierShopRoutes($app);
    $web = new TestClient($app);
    $list = $web->get('/categoria/vestidos')->body;
    $product = $web->get('/producto/vestido-lino')->body;
    check(strpos($list, '<article') !== false, 'el listado de ' . $theme . ' trae tarjetas');
    $card = atelierSlice($list, '<article', '</article>');

    return [
        'header' => atelierSlice($list, '<header class="', '</header>'),
        'card' => $card,
        'listing' => atelierSlice($list, '<main id="main"', '</main>'),
        'product' => atelierSlice($product, '<main id="main"', '</main>'),
    ];
}

test('portada, listado, ficha, carrito y muestra responden 200 con la hoja de atelier y no con la del tema base', function (): void {
    $app = atelierApp([], ['catalog', 'media', 'orders']);
    atelierSeed($app);
    $web = new TestClient($app);
    foreach (['/', '/categoria/vestidos', '/producto/vestido-lino', '/carrito', '/tema/muestra'] as $uri) {
        $r = $web->get($uri);
        same(200, $r->status, $uri);
        contains('/assets/t/atelier/', $r->body, $uri . ': la hoja de atelier');
        check(preg_match('#<link rel="stylesheet" href="[^"]*/assets/t/atelier/[^"]*/css/theme\.css">#', $r->body) === 1, $uri . ': enlaza theme.css de atelier');
        check(preg_match('#/assets/t/base/[^"]*/css/theme\.css#', $r->body) === 0, $uri . ': no enlaza la hoja del tema base');
        check(preg_match('#/assets/t/base/[^"]*/js/theme\.js" defer#', $r->body) === 1, $uri . ': el guion del tema base sigue cargándose');
        check(preg_match('#/assets/t/atelier/[^"]*/js/theme\.js" defer#', $r->body) === 1, $uri . ': y el pequeño de atelier va con defer');
        contains('at-header af-header', $r->body, $uri . ': la cabecera es la de atelier');
        notContains('href=""', $r->body, $uri);
    }
});

test('la ficha: galería antes que la compra, un solo h1, formulario de compra, JSON de variantes válido y datos completos', function (): void {
    $app = atelierApp([], ['catalog', 'media', 'orders']);
    atelierSeed($app);
    $body = (new TestClient($app))->get('/producto/vestido-lino')->body;
    $gallery = strpos($body, 'af-product-gallery');
    $buy = strpos($body, 'af-product-buy');
    check($gallery !== false && $buy !== false && $gallery < $buy, 'la galería va antes que la compra en el HTML (izquierda en escritorio)');
    same(1, substr_count($body, '<h1'), 'un solo h1');
    contains('<h1 class="at-pdp-title af-title">Vestido &lt;b&gt;&amp;</h1>', $body);
    contains('data-af-cart-add', $body);
    contains('name="product_id"', $body);
    contains('data-af-variant-id', $body);
    contains('data-af-variants', $body);
    check(preg_match('#<script type="application/json" data-af-variants-data>(.*?)</script>#s', $body, $m) === 1, 'hay JSON de variantes');
    $data = json_decode($m[1], true);
    check(is_array($data) && count($data['variants']) === 2, 'el JSON es válido y lleva las dos variantes: ' . json_last_error_msg());
    notContains('<script>alert', $body);
    // Los datos de la plantilla original.
    contains('af-price-old', $body, 'precio anterior tachado');
    contains('af-price-off', $body, 'insignia del descuento');
    contains('data-af-availability', $body, 'disponibilidad');
    contains('data-af-qty', $body, 'cantidad');
    contains('data-af-add', $body, 'botón de compra');
    contains('data-af-sku', $body, 'referencia');
    contains('Maison Norte', $body, 'marca');
    contains('af-facts', $body, 'datos clave');
    contains('Lino', $body);
    contains('Resumen corto', $body, 'resumen');
    contains('Texto largo del vestido', $body, 'descripción');
    contains('id="af-specs"', $body, 'características');
    contains('8412345678905', $body, 'GTIN');
    contains('id="af-faq"', $body, 'preguntas');
    contains('¿Encoge al lavarlo?', $body);
    contains('id="af-related"', $body, 'relacionados');
    same(2, substr_count($body, '<article class="at-card af-product-card'), 'dos productos relacionados');
    contains('af-variant', $body, 'tallas como botones');
    contains('data-af-option="talla"', $body);
    contains('class="at-sizeguide"', $body, 'la guía de tallas es un desplegable');
});

test('la galería de la ficha: una foto principal y una miniatura por imagen que la cambia (botón: vale el teclado)', function (): void {
    $app = atelierApp();
    atelierSeed($app);
    $body = (new TestClient($app))->get('/producto/vestido-lino')->body;
    $grid = atelierSlice($body, '<div class="at-pgal af-gallery"', 'class="at-buy af-product-buy');
    contains('data-af-gallery', $grid);
    same(1, substr_count($grid, '<figure class="at-pg-main af-gallery-main"'), 'una sola foto principal, en su contenedor');
    same(1, substr_count($grid, 'data-af-gallery-main'), 'una sola imagen principal');
    same(3, substr_count($grid, '<button type="button" class="at-pg-thumb"'), 'una miniatura por imagen, como botón');
    same(3, substr_count($grid, 'data-src='), 'cada miniatura cambia la imagen principal (theme.js del tema base)');
    same(3, substr_count($grid, 'data-image-id='), 'cada miniatura sabe qué imagen es (lo usa theme.js al elegir variante)');
    same(1, substr_count($grid, 'aria-current="true"'), 'la primera miniatura es la vigente');
    check(preg_match('#<img[^>]*data-af-gallery-main[^>]*>#', $grid, $main) === 1, 'hay imagen principal');
    contains('loading="eager"', $main[0]);
    contains('fetchpriority="high"', $main[0]);
    same(3, substr_count($grid, 'loading="lazy"'), 'las miniaturas, diferidas');

    $none = atelierApp();
    aifecProduct($none, ['name' => 'Sin foto', 'slug' => 'sin-foto']);
    $r = (new TestClient($none))->get('/producto/sin-foto');
    same(200, $r->status);
    contains('af-noimage', $r->body, 'sin imágenes: hueco y sin errores');
});

test('la ficha conserva todos los huecos de la plantilla original del catálogo', function (): void {
    $original = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/catalog/views/front/product.php');
    $names = [];
    preg_match_all("/hook\('(product\.[a-z.]+)'/", $original, $names);
    $names = array_values(array_unique($names[1]));
    check(count($names) >= 7, 'la plantilla original declara sus huecos: ' . implode(', ', $names));
    $app = atelierApp();
    atelierSeed($app);
    foreach ($names as $name) {
        $app->hooks()->on($name, static function () use ($name): string {
            return '<i data-hole="' . $name . '"></i>';
        });
    }
    $body = (new TestClient($app))->get('/producto/vestido-lino')->body;
    foreach ($names as $name) {
        same(1, substr_count($body, '<i data-hole="' . $name . '"></i>'), 'el hueco «' . $name . '» sale una vez');
    }
    // Y los de la portada, el envoltorio y el listado que atelier también sustituye.
    $shell = atelierApp();
    $holes = ['body.start', 'header.before', 'header.after', 'footer.before', 'footer.after', 'body.end', 'header.actions', 'home.top', 'home.bottom'];
    foreach ($holes as $name) {
        $shell->hooks()->on($name, static function () use ($name): string {
            return '<i data-hole="' . $name . '"></i>';
        });
    }
    atelierShopRoutes($shell);
    $home = (new TestClient($shell))->get('/')->body;
    foreach ($holes as $name) {
        same(1, substr_count($home, '<i data-hole="' . $name . '"></i>'), 'el hueco «' . $name . '» sale una vez en la portada');
    }
});

test('la cabecera: franja de aviso, logotipo centrado, menú con desplegable ancho por columnas y buscador como capa', function (): void {
    $app = atelierApp();
    atelierMenu($app);
    atelierShopRoutes($app);
    $body = (new TestClient($app))->get('/')->body;
    contains('<div class="at-strip">', $body);
    contains('Nueva temporada', $body, 'el texto de la franja sale traducido');
    $header = atelierSlice($body, '<header class="at-header af-header', '</header>');
    contains('class="at-logo af-logo"', $header);
    check(strpos($header, 'at-header-start') < strpos($header, 'at-logo') && strpos($header, 'at-logo') < strpos($header, 'at-header-end'), 'menú, logotipo y acciones por ese orden');
    contains('<a class="at-menu-link" href="/mujer" aria-current="page">Mujer</a>', $header);
    contains('class="at-mega"', $header);
    same(2, substr_count($header, 'class="at-mega-col"'), 'una columna por hijo');
    contains('class="at-mega-head" href="/mujer/vestidos"', $header);
    contains('href="/mujer/vestidos/lino"', $header);
    contains('href="/hombre"', $header);
    notContains('Roto', $body);
    notContains('Grupo vacío', $body);
    contains('data-af-drawer-open="af-search"', $header, 'el icono de buscar abre la capa');
    contains('id="af-search"', $body, 'la capa de búsqueda a pantalla completa existe');
    contains('href="/mi-cuenta"', $header);
    contains('data-af-cart-count', $header, 'el carrito lleva su contador');
    notContains('role="search"', $header, 'el buscador no es un campo en la cabecera');
    notContains('href=""', $body);
});

test('la portada: campaña con su h1, bloque editorial detrás de las novedades y un solo h1 aunque una sección traiga el suyo', function (): void {
    $app = atelierApp();
    $app->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'catalog.new', 'order' => 40, 'html' => '<section><h2>Novedades</h2></section>'];
        $sections[] = ['id' => 'catalog.sale', 'order' => 50, 'html' => '<section><h2>Ofertas</h2></section>'];

        return $sections;
    });
    $body = (new TestClient($app))->get('/')->body;
    contains('<section class="at-hero at-hero-tall"', $body);
    contains('<h1 class="at-hero-title" id="at-hero-title">La nueva temporada</h1>', $body);
    same(1, substr_count($body, '<h1'));
    // P100: sin imagen elegida ni productos con foto, la campaña va sin imagen (nunca un dibujo de otro sector).
    notContains('/img/campaign.svg', $body, 'sin imagen elegida, ningún dibujo ajeno a la tienda');
    contains('<span class="at-hero-img" aria-hidden="true"></span>', $body);
    check(strpos($body, 'data-section="catalog.new"') < strpos($body, 'class="at-editorial"') && strpos($body, 'class="at-editorial"') < strpos($body, 'data-section="catalog.sale"'), 'el bloque editorial va detrás de las novedades');
    contains('/img/editorial.svg', $body);

    $withH1 = atelierApp();
    $withH1->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'cms.home', 'order' => 10, 'html' => '<h1>Mi portada</h1>'];

        return $sections;
    });
    $other = (new TestClient($withH1))->get('/')->body;
    same(1, substr_count($other, '<h1'), 'si una sección trae su h1, el de la campaña pasa a ser un párrafo');
    contains('<p class="at-hero-title"', $other);

    $empty = (new TestClient(atelierApp(['editorial_show' => 0])))->get('/')->body;
    notContains('class="at-editorial"', $empty, 'el bloque editorial se puede apagar');
    contains('af-empty', $empty, 'sin secciones, el aviso amable');
});

test('el mosaico de categorías es asimétrico (una grande y dos pequeñas) y las novedades van en una fila de cuatro', function (): void {
    $app = atelierApp();
    $cats = [];
    foreach (['Vestidos', 'Abrigos', 'Zapatos', 'Bolsos', 'Joyas'] as $name) {
        $cats[] = ['name' => $name . ' <i>', 'url' => '/categoria/' . strtolower($name), 'image' => null];
    }
    $html = $app->view()->render('catalog::front/home/categories', ['title' => 'Compra por categoría', 'categories' => $cats]);
    contains('class="at-mosaic at-mosaic-n3"', $html);
    same(3, substr_count($html, '<a class="at-tile'), 'tres teselas');
    same(1, substr_count($html, 'at-tile-big'), 'una grande');
    contains('Vestidos &lt;i&gt;', $html);
    same(2, substr_count($html, '<li><a href="/categoria/'), 'el resto, como enlaces de texto');
    $few = $app->view()->render('catalog::front/home/categories', ['title' => 'x', 'categories' => array_slice($cats, 0, 2)]);
    contains('at-mosaic-n2', $few);
    notContains('at-more', $few);

    $flip = atelierApp(['mosaic_side' => 'right']);
    contains('at-mosaic-flip', $flip->view()->render('catalog::front/home/categories', ['title' => 'x', 'categories' => $cats]));

    $items = [];
    for ($i = 1; $i <= 8; ++$i) {
        $items[] = ['id' => $i, 'name' => 'Prenda ' . $i, 'url' => '/p/' . $i, 'image' => null, 'price' => ['amount' => 1000 * $i, 'currency' => 'EUR'], 'badges' => []];
    }
    $row = $app->view()->render('catalog::front/home/products', ['id' => 'catalog.new', 'title' => 'Novedades', 'items' => $items]);
    same(4, substr_count($row, '<article class="at-card af-product-card'), 'una fila de cuatro');
    contains('at-grid at-row-4 af-grid', $row);
    contains('id="af-catalog-new-title"', $row);
});

test('la tarjeta: vertical, sin botón de compra, segunda imagen, muestras de color validadas, marca opcional y todo escapado', function (): void {
    $app = atelierApp();
    $image = static function (string $name): array {
        return ['id' => 1, 'url' => '/m/' . $name . '.jpg', 'card' => '/m/' . $name . '-card.jpg', 'width' => 600, 'height' => 800, 'alt' => ''];
    };
    $card = [
        'id' => 1, 'name' => 'Mesa <b>&', 'url' => '/producto/mesa?a=1&b=2', 'image' => $image('uno'), 'images' => [$image('uno'), $image('dos')], 'brand' => 'Casa <i>',
        'price' => ['amount' => 1999, 'with_tax' => true, 'compare' => 2499, 'discount_percent' => 20, 'currency' => 'EUR', 'from' => false],
        'rating' => ['average' => 4.5, 'count' => 3], 'badges' => ['sale', 'new', 'out'],
        'swatches' => [['label' => 'Negro', 'color' => '#111111'], ['label' => 'Raro', 'color' => 'url(javascript:1)'], ['label' => '', 'color' => '#ffffff']],
    ];
    $html = $app->view()->render('partials/product-card', ['product' => $card]);
    contains('Mesa &lt;b&gt;&amp;', $html);
    notContains('Mesa <b>', $html);
    notContains('Casa', $html, 'la marca no sale de serie');
    contains('href="/producto/mesa?a=1&amp;b=2"', $html);
    same(1, substr_count($html, 'class="at-card-link af-product-card-link"'), 'todo el bloque enlaza una sola vez');
    notContains('<button', $html);
    notContains('data-af-cart-add', $html);
    contains('<figure class="at-card-fig">', $html);
    contains('at-card-alt', $html);
    contains('/m/dos-card.jpg', $html, 'la segunda imagen es la que sale al pasar el ratón');
    contains('style="--sw:#111111"', $html);
    same(2, substr_count($html, 'class="at-swatch"'), 'la muestra con un color raro se pinta sin color y la que no tiene nombre, no');
    notContains('javascript', $html);
    notContains('--sw:url', $html);
    contains('af-badge-sale', $html);
    contains("\u{2212}20\u{a0}%", $html);
    contains('af-price-old', $html, 'el precio anterior sigue a la vista');
    notContains('af-stars', $html, 'sin valoración en la tarjeta: nombre y precio en una línea');

    $brand = atelierApp(['show_brand_in_card' => 1, 'card_ratio' => 'tall']);
    $withBrand = $brand->view()->render('partials/product-card', ['product' => $card]);
    contains('Casa &lt;i&gt;', $withBrand);
    contains('at-ratio-tall', $withBrand);

    $bare = $app->view()->render('partials/product-card', ['product' => ['name' => 'Silla', 'url' => '', 'image' => null, 'price' => ['amount' => 500, 'currency' => 'EUR'], 'badges' => []]]);
    notContains('<a ', $bare);
    notContains('href=""', $bare);
    contains('af-noimage', $bare);
});

test('el listado: sin barra lateral, filtros en una fila de desplegables, orden junto al recuento y rejilla propia', function (): void {
    $app = atelierApp();
    atelierSeed($app);
    $web = new TestClient($app);
    $r = $web->get('/categoria/vestidos');
    same(200, $r->status);
    $body = $r->body;
    same(1, substr_count($body, '<h1'));
    contains('<h1 class="at-list-title af-title">Vestidos</h1>', $body);
    contains('at-listing af-listing', $body);
    contains('id="af-filters"', $body, 'el panel de filtros del móvil existe');
    contains('data-af-drawer-open="af-filters"', $body, 'y la barra inferior tiene el botón');
    contains('id="af-filters-form"', $body);
    $filters = atelierSlice($body, '<aside class="at-filters af-listing-filters af-drawer"', '</aside>');
    $details = substr_count($filters, '<details class="at-dd af-filter">');
    check($details >= 3, 'un desplegable por faceta más precio y existencias: ' . $details);
    contains('name="min"', $filters);
    contains('name="max"', $filters);
    contains('name="stock"', $filters);
    contains('name="sale"', $filters);
    contains('type="submit"', $filters);
    contains('name="sort" form="af-filters-form" data-af-autosubmit', $body, 'el orden se envía con el formulario de filtros');
    contains('<div class="at-grid af-grid at-cols-4">', $body);
    same(5, substr_count($body, '<article class="at-card af-product-card'), 'cinco productos en la rejilla');
    contains('class="at-results-bar"', $body);

    $css = atelierCssPacked();
    contains('.at-listing{display:block}', $css, 'en escritorio no hay columna lateral');
    notContains('grid-template-columns:16rem', $css);
    check(preg_match('/\.at-dd-panel\{position:absolute/', $css) === 1, 'los desplegables son paneles flotantes bajo cada botón');
});

test('un listado vacío o sin filtros marcados sigue funcionando y la página de error va con el envoltorio de atelier', function (): void {
    $app = atelierApp();
    aifecCategory($app, 'Vacía');
    $web = new TestClient($app);
    $r = $web->get('/categoria/vacia');
    same(200, $r->status);
    contains('at-empty af-empty', $r->body);
    contains('No se ha encontrado ningún producto', $r->body);
    $missing = $web->get('/esto-no-existe');
    same(404, $missing->status);
    contains('at-header af-header', $missing->body);
    contains('class="af-empty af-error"', $missing->body);
    same(1, substr_count($missing->body, '<h1'));
});

test('contrato de móvil: barra inferior con buscar, carrito con contador y cuenta; con filtros solo en el listado; cabecera pegajosa', function (): void {
    $app = atelierApp();
    atelierSeed($app);
    atelierShopRoutes($app);
    $web = new TestClient($app);
    $home = $web->get('/')->body;
    contains('<nav class="af-mobile-bar af-show-sm"', $home);
    contains('data-af-drawer-open="af-search"', $home);
    contains('data-af-drawer-open="af-cart"', $home);
    contains('data-af-cart-count', $home);
    contains('af-mobile-bar-label">Cuenta', $home);
    contains('af-has-bar', $home);
    notContains('data-af-drawer-open="af-filters"', $home, 'sin filtros funcionales no hay botón de filtros');
    contains('id="af-cart"', $home);
    contains('data-af-cart-lines', $home);
    $list = $web->get('/categoria/vestidos')->body;
    contains('data-af-drawer-open="af-filters" aria-controls="af-filters"', $list);

    $css = atelierCssPacked();
    check(preg_match('/\.af-header\{[^}]*position:sticky/', $css) === 1, 'la cabecera es pegajosa');
    check(preg_match('/\.af-mobile-bar\{[^}]*position:fixed[^}]*bottom:0/', $css) === 1, 'la barra inferior es fija abajo');
    check(preg_match('/\.af-has-bar\{[^}]*padding-bottom:calc\(var\(--bar-h\)\+env\(safe-area-inset-bottom\)\)/', $css) === 1, 'el contenido reserva el alto de la barra');
    check(preg_match('/\.af-mobile-bar-item\{[^}]*min-width:44px[^}]*min-height:var\(--bar-h\)/', $css) === 1, 'áreas táctiles de 44 px');
    check(preg_match('/--bar-h:3\.[0-9]+rem/', $css) === 1, 'la barra mide al menos 44 px');
    contains('.af-show-sm{display:none!important}', $css);
    contains('.af-hide-sm{display:none!important}', $css);
});

test('las opciones de estructura del tema llegan al HTML: franja, cabecera, menú, tarjeta, columnas y galería', function (): void {
    $off = (new TestClient(atelierApp(['strip_show' => 0])))->get('/')->body;
    notContains('at-strip', $off);
    $custom = (new TestClient(atelierApp(['strip_text' => 'Envíos gratis & rápidos'])))->get('/')->body;
    contains('Envíos gratis &amp; rápidos', $custom);

    $classic = (new TestClient(atelierApp(['header_style' => 'classic'])))->get('/')->body;
    contains('at-header af-header at-header-classic', $classic);
    notContains('at-header-classic', (new TestClient(atelierApp()))->get('/')->body);

    $app = atelierApp(['mega_menu' => 0]);
    atelierMenu($app);
    contains('at-nav af-hide-sm at-nav-simple', (new TestClient($app))->get('/')->body);

    $app = atelierApp(['gallery_style' => 'column']);
    atelierSeed($app);
    contains('at-pdp af-product at-pdp-column', (new TestClient($app))->get('/producto/vestido-lino')->body);

    $app = atelierApp(['listing_cols' => '3']);
    atelierSeed($app);
    contains('at-grid af-grid at-cols-3', (new TestClient($app))->get('/categoria/vestidos')->body);

    $app = atelierApp(['hero_height' => 'medium', 'hero_kicker' => 'Otoño', 'hero_title' => 'Lino & lana', 'hero_link' => '/novedades', 'hero_cta' => 'Ver todo']);
    $home = (new TestClient($app))->get('/')->body;
    notContains('at-hero-tall', $home);
    contains('Otoño', $home);
    contains('Lino &amp; lana', $home);
    contains('href="/novedades"', $home);

    // El acento llega como variable del <head>.
    $accent = (new TestClient(atelierApp(['accent' => '#112233'])))->get('/')->body;
    contains('--accent:#112233', $accent);
    contains('--radius:0px', $accent);
});

test('las ayudas del tema: enlaces seguros, colores de muestra y una imagen que no existe', function (): void {
    $app = atelierApp();
    check(function_exists('atelier_link') && function_exists('atelier_hex') && function_exists('atelier_image'), 'functions.php define las tres ayudas');
    same('', atelier_link('javascript:alert(1)'));
    same('', atelier_link('//otro.test/x'));
    same('', atelier_link('data:text/html,x'));
    same('', atelier_link(''));
    same('#novedades', atelier_link('#novedades'));
    same('https://tienda.test/x', atelier_link('https://tienda.test/x'));
    same('/novedades', atelier_link('/novedades'));
    same('#aabbcc', atelier_hex('#AABBCC'));
    same('', atelier_hex('red'));
    same('', atelier_hex(null));
    same('', atelier_hex('#abc'));
    same(null, atelier_image('hero_image'), 'sin elección no hay imagen');
    $custom = atelierApp(['hero_image' => 99999]);
    same(null, atelier_image('hero_image'), 'un identificador que no existe tampoco');
    check($custom instanceof App, 'la tienda sigue arrancando');
    same(200, (new TestClient($custom))->get('/')->status);
    $app->boot();
});

test('estructura distinta de la del tema base: cabecera, tarjeta, listado y ficha se parecen menos de un 82 %', function (): void {
    $base = atelierSurfaces('base');
    $atelier = atelierSurfaces('atelier');
    $worst = [];
    foreach (['header', 'card', 'listing', 'product'] as $surface) {
        $byClass = atelierSimilarity(atelierSequence($base[$surface], true), atelierSequence($atelier[$surface], true));
        $byTag = atelierSimilarity(atelierSequence($base[$surface], false), atelierSequence($atelier[$surface], false));
        $worst[$surface] = round($byClass, 3) . ' / ' . round($byTag, 3);
        check($byClass < 0.82, $surface . ': la secuencia etiqueta.clase se parece un ' . round($byClass * 100) . ' % al tema base');
        check($byTag < 0.82, $surface . ': la secuencia de etiquetas se parece un ' . round($byTag * 100) . ' % al tema base');
        check(strlen($atelier[$surface]) > 200, $surface . ' tiene contenido para comparar');
    }
    check(count($worst) === 4, 'las cuatro superficies medidas: ' . json_encode($worst));
});

test('un nombre de producto con <b>&: escapado en tarjeta, ficha, migas y datos para compartir', function (): void {
    $app = atelierApp();
    atelierSeed($app);
    $web = new TestClient($app);
    $product = $web->get('/producto/vestido-lino')->body;
    contains('Vestido &lt;b&gt;&amp;', $product);
    notContains('Vestido <b>', $product);
    $list = $web->get('/categoria/vestidos')->body;
    contains('Vestido &lt;b&gt;&amp;', $list);
    notContains('Vestido <b>', $list);
    $sample = $web->get('/tema/muestra')->body;
    same(1, substr_count($sample, '<h1'));
});

test('el carrito vacío y la muestra del tema se ven con la hoja de atelier y sin errores', function (): void {
    $app = atelierApp([], ['catalog', 'media', 'orders']);
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
    $css = atelierDir() . '/assets/css/theme.css';
    $js = atelierDir() . '/assets/js/theme.js';
    check(is_file($css) && is_file($js), 'existen la hoja y el guion');
    check(filesize($css) <= 45000, 'theme.css pesa ' . filesize($css));
    check(filesize($js) <= 8000, 'theme.js pesa ' . filesize($js));
});

test('ninguna dirección externa en las plantillas ni en los recursos (salvo el espacio de nombres de los SVG) y ningún @import', function (): void {
    $files = array_merge(atelierFiles('templates'), atelierFiles('assets'));
    check(count($files) >= 10, 'se han encontrado las plantillas y los recursos');
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        same(0, preg_match('#https?://(?!www\.w3\.org)#i', $text), basename($file) . ' lleva una dirección externa');
        notContains('@import', $text, basename($file));
        notContains('//cdn', $text, basename($file));
    }
    foreach (['campaign.svg', 'editorial.svg'] as $svg) {
        $xml = @simplexml_load_string((string) file_get_contents(atelierDir() . '/assets/img/' . $svg));
        check($xml !== false, $svg . ' es un SVG bien formado');
    }
});

test('theme.css: ningún color fuera de :root, sin fuentes ni iconos de fuera y con los medios de accesibilidad', function (): void {
    $css = (string) file_get_contents(atelierDir() . '/assets/css/theme.css');
    $rest = atelierCssWithoutRoot($css);
    $found = [];
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $rest, $found);
    same([], $found[0], 'colores fijos fuera de :root');
    check(preg_match('/\b(?:rgb|rgba|hsl|hsla)\(/', $rest) === 0, 'colores funcionales fuera de :root');
    notContains('@font-face', $css);
    notContains('url(', $css);
    foreach (['prefers-reduced-motion', ':focus-visible', 'env(safe-area-inset-bottom)', 'min-height:44px', 'min-width:44px', 'flex-wrap:wrap', 'overflow-x:auto', 'line-through', 'object-fit:contain', 'position:sticky'] as $needle) {
        contains($needle, $css, 'theme.css no contiene «' . $needle . '»');
    }
    // Tipografías de la pila del sistema: ni una sola petición a otro dominio.
    notContains('fonts.googleapis', $css);
});

test('theme.css da estilo a todas las clases del contrato de estilos', function (): void {
    $css = (string) file_get_contents(atelierDir() . '/assets/css/theme.css');
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
    // Y a las clases que pintan las plantillas propias de atelier.
    $own = [];
    foreach (atelierFiles('templates') as $file) {
        $text = (string) file_get_contents($file);
        $m = [];
        preg_match_all('/class="([^"]*)"/', $text, $m);
        foreach ($m[1] as $attr) {
            $attr = (string) preg_replace('/<\?=.*?\?>/s', ' ', $attr);
            foreach (preg_split('/\s+/', trim($attr)) ?: [] as $name) {
                if (strpos($name, 'at-') === 0) {
                    $own[$name] = true;
                }
            }
        }
    }
    check(count($own) > 40, 'se han encontrado las clases propias: ' . count($own));
    foreach (array_keys($own) as $name) {
        // Solo los prefijos que la plantilla completa con un número («at-cols-3», «at-mosaic-n2») se miran por la clase entera.
        if (in_array($name, ['at-cols-', 'at-mosaic-n'], true)) {
            continue;
        }
        check(preg_match('/\.' . preg_quote($name, '/') . '(?![A-Za-z0-9_-])/', $css) === 1, 'theme.css no da estilo a .' . $name);
    }
});

test('theme.json es JSON válido, hijo de base con su hoja propia, y cada opción con var existe en :root', function (): void {
    $json = json_decode((string) file_get_contents(atelierDir() . '/theme.json'), true);
    check(is_array($json), 'JSON válido');
    same('Atelier', $json['name']);
    same('base', $json['parent']);
    same('replace', $json['css'], 'la hoja sustituye a la del padre');
    check(!isset($json['js']) || $json['js'] !== 'replace', 'el guion del padre se sigue cargando');
    $css = (string) file_get_contents(atelierDir() . '/assets/css/theme.css');
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
    foreach (['--bg', '--surface', '--ink', '--muted', '--line', '--accent', '--accent-ink', '--sale', '--ok', '--warn', '--bad', '--font', '--font-head', '--radius', '--container'] as $var) {
        check(in_array($var, $vars, true), 'falta la opción para ' . $var);
    }
    check(count(array_diff($plain, ['header_style', 'card_ratio', 'show_brand_in_card'])) >= 3, 'al menos tres opciones de estructura propias: ' . implode(', ', $plain));
    foreach (['strip_show', 'mega_menu', 'mosaic_side', 'gallery_style', 'listing_cols', 'hero_height'] as $key) {
        check(in_array($key, $plain, true), 'falta la opción de estructura ' . $key);
    }
    foreach ($json['settings']['typography']['fields']['font_head']['options'] as $stack => $label) {
        check(isset($json['font_stacks'][$stack]) || in_array($stack, ['system', 'serif', 'rounded', 'mono'], true), 'la tipografía «' . $stack . '» no tiene pila');
    }
    check(preg_match('/https?:/', json_encode($json['font_stacks']) ?: '') === 0, 'las pilas son del sistema');
    same(0.0, (float) $json['settings']['layout']['fields']['radius']['default'], 'bordes rectos');
    same(1440, $json['settings']['layout']['fields']['container']['default']);
});

test('todas las plantillas y ficheros PHP del tema empiezan por la cabecera del proyecto, en UTF-8 sin BOM, con LF y sin etiqueta de cierre', function (): void {
    $names = ['home', 'partials/header', 'partials/product-card', 'partials/editorial', 'modules/catalog/front/listing', 'modules/catalog/front/product',
        'modules/catalog/front/partials/filters', 'modules/catalog/front/home/categories', 'modules/catalog/front/home/products'];
    foreach ($names as $name) {
        check(is_file(atelierDir() . '/templates/' . $name . '.php'), 'falta templates/' . $name . '.php');
    }
    foreach (['functions.php', 'lang/es.php', 'assets/css/theme.css', 'assets/js/theme.js', 'assets/img/campaign.svg', 'assets/img/editorial.svg', 'theme.json', 'preview.png'] as $extra) {
        check(is_file(atelierDir() . '/' . $extra), 'falta ' . $extra);
    }
    $php = array_filter(atelierFiles(), static function (string $file): bool {
        return substr($file, -4) === '.php';
    });
    check(count($php) >= 11, 'se han encontrado los ficheros PHP: ' . count($php));
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
    $js = (string) file_get_contents(atelierDir() . '/assets/js/theme.js');
    foreach (['alert(', 'confirm(', 'prompt(', 'eval(', 'document.write', 'innerHTML', 'fetch(', 'XMLHttpRequest', 'http://', 'https://'] as $banned) {
        notContains($banned, $js, 'theme.js no puede usar ' . $banned);
    }
    // La galería (foto principal y miniaturas) ya la mueve el guion del tema base: aquí solo quedan los filtros.
    foreach (['details.at-dd', 'Escape', 'toggle'] as $needle) {
        contains($needle, $js, 'theme.js no contiene «' . $needle . '»');
    }
    notContains('"use strict"', $js);
    contains("'use strict'", $js);
});

test('el catálogo castellano del tema tiene todas las cadenas, ninguna vacía y con tildes y eñes', function (): void {
    $lang = require atelierDir() . '/lang/es.php';
    check(is_array($lang) && count($lang) > 90, 'el catálogo está cargado');
    foreach ($lang as $key => $value) {
        check(is_string($value) && trim($value) !== '', 'la cadena «' . $key . '» no tiene traducción');
    }
    same('Añadir al carrito', $lang['Add to cart']);
    same('Composición y cuidados', $lang['Composition and care']);
    same('Guía de tallas', $lang['Size guide']);
    same('Conoce nuestra historia', $lang['Read our story']);
    // Con el idioma castellano, la ficha y el listado salen en castellano.
    $app = atelierApp([], ['catalog', 'media', 'orders']);
    atelierSeed($app);
    $body = (new TestClient($app))->get('/producto/vestido-lino')->body;
    contains('Añadir al carrito', $body);
    contains('Guía de tallas', $body);
    contains('También te puede interesar', $body);
    notContains('Add to cart', $body);
});
