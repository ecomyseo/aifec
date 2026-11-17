<?php
/**
 * AIFEC — pruebas del tema gmartos (la tienda actual de gmartos.es): que sus superficies responden con su hoja y su
 * estructura propias, que conservan los datos y los huecos de las plantillas que sustituyen, el contrato de móvil, las
 * opciones del tema, el blog y las comprobaciones estáticas del presupuesto de peso y del contrato de estilos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Kernel\App;

require_once dirname(__DIR__, 2) . '/modules/cms/tests/helpers.php';

/** Carpeta del tema gmartos. */
function gmDir(): string
{
    return rtrim(str_replace('\\', '/', Autoload::root()), '/') . '/themes/gmartos';
}

/**
 * Todos los ficheros de una carpeta del tema (recursivo).
 *
 * @return string[]
 */
function gmFiles(string $sub = ''): array
{
    $files = [];
    $dir = gmDir() . ($sub !== '' ? '/' . $sub : '');
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
function gmCssWithoutRoot(string $css): string
{
    return (string) preg_replace('/:where\(:root\)\s*\{[^}]*\}|:root\s*\{[^}]*\}/', '', $css);
}

/** La hoja sin comentarios ni espacios, para comparar reglas sin depender del formato. */
function gmCssPacked(): string
{
    $css = (string) file_get_contents(gmDir() . '/assets/css/theme.css');
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

    return (string) preg_replace('/\s+/', '', $css);
}

/**
 * Aplicación con el tema activo, el catálogo y los medios, arrancada.
 *
 * @param array<string,mixed> $options opciones del tema que cambia el comerciante
 * @param string[]            $modules
 * @param string              $theme   gmartos, base, taller… (para comparar estructuras)
 * @param array<string,mixed> $extra   ajustes de la tienda (store.email…)
 */
function gmApp(array $options = [], array $modules = ['catalog', 'media'], string $theme = 'gmartos', array $extra = []): App
{
    $settings = ['theme.active' => $theme] + $extra;
    if ($options !== []) {
        $settings['theme.options'] = [$theme => $options];
    }
    $app = testApp(['modules' => $modules, 'settings' => $settings]);
    $app->boot();

    return $app;
}

/** Rutas de las que dependen la cabecera y la barra del móvil, registradas como lo haría un módulo de comercio. */
function gmShopRoutes(App $app): void
{
    $noop = static function (): string {
        return '';
    };
    foreach (['search' => '/buscar', 'cart' => '/carrito', 'account' => '/mi-cuenta'] as $name => $path) {
        if (!$app->router()->has($name)) {
            $app->router()->get($path, $noop)->name($name);
        }
    }
}

/** Un menú principal con un elemento roto y un grupo vacío que no se tienen que pintar, y otro de pie con una columna. */
function gmMenu(App $app): void
{
    $app->hooks()->filter('menu.items', static function (array $items, string $location): array {
        if ($location === 'main') {
            return [
                ['label' => 'Módulos', 'url' => '/modulos', 'current' => true, 'children' => []],
                ['label' => 'Servicios', 'url' => '/servicios', 'children' => []],
                ['label' => 'Roto', 'url' => ''],
                ['label' => 'Grupo vacío', 'url' => '', 'children' => []],
            ];
        }
        if ($location === 'footer') {
            return [
                ['label' => 'Explora', 'url' => '', 'children' => [['label' => 'Guías', 'url' => '/blog'], ['label' => 'Soporte', 'url' => '/soporte']]],
                ['label' => 'Suelto', 'url' => '/suelto'],
            ];
        }
        if ($location === 'legal') {
            return [['label' => 'Privacidad', 'url' => '/privacidad']];
        }

        return $items;
    });
}

/**
 * La tienda de las pruebas: una categoría con una subcategoría, una marca, atributos, un taladro con variantes, tres
 * imágenes, características clave, un documento, preguntas y relacionados, y otros cuatro productos para llenar el listado.
 *
 * @return array<string,int>
 */
function gmSeed(App $app): array
{
    $app->boot();
    $ids = [];
    $ids['cat'] = aifecCategory($app, 'Taladros', null, ['media_id' => aifecMedia($app, 'Taladros')]);
    $ids['sub'] = aifecCategory($app, 'Percutores', $ids['cat'], ['media_id' => aifecMedia($app, 'Percutores'), 'product_count' => 3]);
    $ids['brand'] = aifecBrand($app, 'Norte Pro');
    aifecAttribute($app, 'potencia', 'Potencia', ['500' => '500 W', '750' => '750 W', '1000' => '1000 W']);
    $ids['broca'] = aifecProduct($app, [
        'name' => 'Broca de widia', 'slug' => 'broca-widia', 'price' => 590, 'images' => 2, 'categories' => [$ids['cat']], 'brand_id' => $ids['brand'],
        'sku' => 'BRO-8', 'summary' => '<p>Broca para hormigón y piedra de ocho milímetros con vástago SDS y punta de widia reforzada para taladrar sin que se desafile en muchas horas de trabajo seguido.</p>',
    ]);
    $ids['maletin'] = aifecProduct($app, ['name' => 'Maletín de brocas', 'slug' => 'maletin-brocas', 'price' => 2490, 'images' => 1, 'categories' => [$ids['cat']], 'stock' => 0, 'sku' => 'MAL-1']);
    aifecProduct($app, ['name' => 'Atornillador 12 V', 'slug' => 'atornillador-12v', 'price' => 5990, 'images' => 2, 'categories' => [$ids['cat']], 'compare_price' => 7990, 'sku' => 'ATO-12', 'featured' => 1]);
    aifecProduct($app, ['name' => 'Nivel láser', 'slug' => 'nivel-laser', 'price' => 3900, 'categories' => [$ids['cat']], 'featured' => 1]);
    $ids['drill'] = aifecProduct($app, [
        'name' => 'Taladro <b>&', 'slug' => 'taladro-percutor', 'price' => 8900, 'compare_price' => 11900, 'images' => 3,
        'categories' => [$ids['cat']], 'brand_id' => $ids['brand'], 'sku' => 'TAL-750', 'gtin' => '8412345678905',
        'summary' => '<p>Resumen corto</p>', 'description' => '<p>Texto largo del taladro</p>',
        'facts' => [['label' => 'Potencia', 'value' => '750 W'], ['label' => 'Mandril', 'value' => '13 mm'], ['label' => 'Peso', 'value' => '2,1 kg']],
        'attributes' => ['potencia' => '750'],
        'faqs' => [['question' => '¿Trae maletín?', 'answer' => '<p>Sí.</p>']],
        'related' => [$ids['broca'], $ids['maletin']],
        'extra' => ['garantia' => ['label' => 'Garantía', 'value' => '3 años', 'type' => 'text', 'public' => true]],
        'variants' => [
            ['sku' => 'TAL-500', 'stock' => 3, 'values' => ['potencia' => '500']],
            ['sku' => 'TAL-1000', 'stock' => 5, 'values' => ['potencia' => '1000']],
        ],
    ]);

    return $ids;
}

/** La secuencia «etiqueta.clase» de las etiquetas de abrir de un trozo de HTML: lo que sirve para comparar composiciones. */
function gmSequence(string $html, bool $withClass): string
{
    // La compra desde la tarjeta (form[data-af-co]) es una pieza del catálogo, la misma en todos los temas: no es composición del tema.
    $html = (string) preg_replace('#<form\b[^>]*\bdata-af-co\b[^>]*>.*?</form>#s', '', $html);
    $found = [];
    preg_match_all('/<([a-z][a-z0-9]*)\b([^>]*)>/i', $html, $found, PREG_SET_ORDER);
    $out = [];
    foreach ($found as $tag) {
        $name = strtolower($tag[1]);
        if ($name === 'script' || $name === 'path' || $name === 'circle' || $name === 'svg' || $name === 'rect') {
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
function gmSimilarity(string $a, string $b): float
{
    $percent = 0.0;
    similar_text($a, $b, $percent);

    return $percent / 100;
}

/** La parte de una página que va entre dos marcas. */
function gmSlice(string $html, string $from, string $to): string
{
    $start = strpos($html, $from);
    check($start !== false, 'no está «' . $from . '»');
    $end = strpos($html, $to, (int) $start);
    check($end !== false, 'no está «' . $to . '»');

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/** Cada superficie de una tienda con los mismos datos, para compararla entre temas. */
function gmSurfaces(string $theme): array
{
    $app = gmApp([], ['catalog', 'media', 'orders'], $theme);
    gmSeed($app);
    gmMenu($app);
    gmShopRoutes($app);
    $web = new TestClient($app);
    $list = $web->get('/categoria/taladros')->body;
    $product = $web->get('/producto/taladro-percutor')->body;
    check(strpos($list, '<article') !== false, 'el listado de ' . $theme . ' trae tarjetas');
    $card = gmSlice($list, '<article', '</article>');

    return [
        'header' => gmSlice($list, '<header class="', '</header>'),
        'card' => $card,
        'listing' => gmSlice($list, '<main id="main"', '</main>'),
        'product' => gmSlice($product, '<main id="main"', '</main>'),
    ];
}

test('portada, listado, ficha, carrito y muestra responden 200 con la hoja de gmartos y no con la del tema base', function (): void {
    $app = gmApp([], ['catalog', 'media', 'orders']);
    gmSeed($app);
    $web = new TestClient($app);
    foreach (['/', '/categoria/taladros', '/producto/taladro-percutor', '/carrito', '/tema/muestra'] as $uri) {
        $r = $web->get($uri);
        same(200, $r->status, $uri);
        contains('/assets/t/gmartos/', $r->body, $uri);
        check(preg_match('#<link rel="stylesheet" href="[^"]*/assets/t/gmartos/[^"]*/css/theme\.css">#', $r->body) === 1, $uri . ': enlaza theme.css de gmartos');
        check(preg_match('#/assets/t/base/[^"]*/css/theme\.css#', $r->body) === 0, $uri . ': no enlaza la hoja del tema base');
        check(preg_match('#/assets/t/base/[^"]*/js/theme\.js" defer#', $r->body) === 1, $uri . ': el guion del tema base sigue cargándose');
        contains('af-header gm-header', $r->body, $uri . ': la cabecera es la de gmartos');
        notContains('href=""', $r->body, $uri);
    }
});

test('la ficha: galería antes que la compra, un solo h1, formulario de compra, JSON de variantes válido y datos completos', function (): void {
    $app = gmApp([], ['catalog', 'media', 'orders']);
    gmSeed($app);
    $body = (new TestClient($app))->get('/producto/taladro-percutor')->body;
    $gallery = strpos($body, 'af-product-gallery');
    $buy = strpos($body, 'af-product-buy');
    check($gallery !== false && $buy !== false && $gallery < $buy, 'la galería va antes que la compra en el HTML (izquierda en escritorio)');
    same(1, substr_count($body, '<h1'), 'un solo h1');
    contains('<h1 class="gm-pdp-title af-title">Taladro &lt;b&gt;&amp;</h1>', $body);
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
    contains('data-af-price-now', $body, 'el precio se puede actualizar al cambiar de variante');
    contains('data-af-availability', $body, 'disponibilidad');
    contains('data-af-qty', $body, 'cantidad');
    contains('data-af-add', $body, 'botón de compra');
    contains('data-af-sku', $body, 'referencia');
    contains('Norte Pro', $body, 'marca');
    contains('af-facts', $body, 'datos clave');
    contains('750 W', $body);
    contains('Resumen corto', $body, 'resumen');
    contains('Texto largo del taladro', $body, 'descripción');
    contains('id="af-specs"', $body, 'características');
    contains('8412345678905', $body, 'GTIN');
    contains('Garantía', $body, 'dato público de un plugin en la tabla');
    contains('id="af-faq"', $body, 'preguntas');
    contains('¿Trae maletín?', $body);
    contains('id="af-related"', $body, 'relacionados');
    same(2, substr_count($body, '<article class="gm-card af-product-card'), 'dos accesorios relacionados');
    contains('data-af-option="potencia"', $body);
    $summary = strpos($body, 'Resumen corto');
    $form = strpos($body, 'data-af-cart-add');
    check($summary < $form, 'el resumen va antes del formulario, como en la tienda de origen');
});

test('la ficha: miniaturas en una tira de cinco, pestañas accesibles, tira de razones, llamada a la acción y enlaces solo si se configuran', function (): void {
    $app = gmApp([], ['catalog', 'media', 'orders']);
    gmSeed($app);
    $body = (new TestClient($app))->get('/producto/taladro-percutor')->body;
    $gal = gmSlice($body, '<div class="af-gallery"', 'class="af-product-buy"');
    contains('data-af-gallery', $gal);
    check(strpos($gal, 'af-gallery-main') < strpos($gal, 'af-gallery-thumbs'), 'la foto grande y detrás la tira de miniaturas');
    same(3, substr_count($gal, 'data-src='), 'una miniatura por imagen');
    same(3, substr_count($gal, 'data-image-id='), 'cada miniatura sabe qué imagen es (lo usa theme.js al elegir variante)');
    same(1, substr_count($gal, 'data-af-gallery-main'), 'una sola imagen principal');
    check(preg_match('#<img[^>]*data-af-gallery-main[^>]*>#', $gal, $main) === 1, 'hay imagen principal');
    contains('loading="eager"', $main[0]);
    contains('fetchpriority="high"', $main[0]);
    contains('grid-template-columns:repeat(5,minmax(0,1fr))', gmCssPacked(), 'cinco miniaturas por fila');

    contains('class="af-tabs" data-af-tabs', $body);
    same(3, substr_count($body, 'role="tab"'), 'tres pestañas: descripción, características y preguntas');
    same(3, substr_count($body, 'role="tabpanel"'), 'y sus tres paneles');
    contains('aria-controls="af-description" aria-selected="true"', $body);
    contains('aria-controls="af-specs" aria-selected="false" tabindex="-1"', $body);
    $desc = strpos($body, 'id="af-description"');
    $specs = strpos($body, 'id="af-specs"');
    check($desc !== false && $specs !== false && $desc < $specs, 'la descripción va antes que las características');
    contains('<th scope="row">Garantía</th><td>3 años</td>', $body);
    contains('class="gm-proof"', $body, 'tira de tres razones');
    same(3, substr_count($body, 'class="gm-proof-item"'));
    contains('Desarrollo especializado', $body);
    contains('class="gm-cta"', $body, 'llamada a la acción oscura');
    notContains('Desarrollo a medida</a>', $body, 'sin enlace configurado no hay botón');
    $linked = gmApp(['cta_link' => '/desarrollo', 'cta_link2' => 'javascript:alert(1)'], ['catalog', 'media', 'orders']);
    gmSeed($linked);
    $other = (new TestClient($linked))->get('/producto/taladro-percutor')->body;
    contains('href="/desarrollo">Desarrollo a medida</a>', $other, 'el enlace propio de la tienda sí');
    notContains('javascript:', $other, 'un enlace peligroso se ignora');
    notContains('>Consultar</a>', $other);
    $off = gmApp(['proof_show' => 0, 'cta_show' => 0], ['catalog', 'media', 'orders']);
    gmSeed($off);
    $bare = (new TestClient($off))->get('/producto/taladro-percutor')->body;
    notContains('class="gm-proof"', $bare);
    notContains('class="gm-cta"', $bare);
});

test('la ficha conserva todos los huecos de la plantilla original del catálogo y los del envoltorio', function (): void {
    $original = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/catalog/views/front/product.php');
    $names = [];
    preg_match_all("/hook\('(product\.[a-z.]+)'/", $original, $names);
    $names = array_values(array_unique($names[1]));
    check(count($names) >= 7, 'la plantilla original declara sus huecos: ' . implode(', ', $names));
    $app = gmApp();
    gmSeed($app);
    foreach ($names as $name) {
        $app->hooks()->on($name, static function () use ($name): string {
            return '<i data-hole="' . $name . '"></i>';
        });
    }
    $body = (new TestClient($app))->get('/producto/taladro-percutor')->body;
    foreach ($names as $name) {
        same(1, substr_count($body, '<i data-hole="' . $name . '"></i>'), 'el hueco «' . $name . '» sale una vez');
    }
    $shell = gmApp();
    $holes = ['body.start', 'header.before', 'header.after', 'footer.before', 'footer.after', 'body.end', 'header.actions', 'home.top', 'home.bottom'];
    foreach ($holes as $name) {
        $shell->hooks()->on($name, static function () use ($name): string {
            return '<i data-hole="' . $name . '"></i>';
        });
    }
    gmShopRoutes($shell);
    $home = (new TestClient($shell))->get('/')->body;
    foreach ($holes as $name) {
        same(1, substr_count($home, '<i data-hole="' . $name . '"></i>'), 'el hueco «' . $name . '» sale una vez en la portada');
    }
});

test('la cabecera: franja de aviso, logotipo del tema, menú limpio, cuenta, carrito con contador y el botón oscuro solo con enlace', function (): void {
    $app = gmApp();
    gmMenu($app);
    gmShopRoutes($app);
    $body = (new TestClient($app))->get('/')->body;
    contains('<div class="gm-announce">', $body);
    contains('NUEVA IDENTIDAD', $body);
    contains('G. Martos evoluciona a Ecom Experts. Mismo contacto, mismo dominio.', $body);
    contains('class="gm-announce-aside"', $body, 'el dominio a la derecha');
    contains('<header class="af-header gm-header">', $body);
    contains('/img/logo.svg', $body, 'sin logotipo subido, el del tema');
    check(preg_match('#<img class="gm-logo-img gm-logo-theme" src="[^"]*/img/logo\.svg" alt="[^"]+" width="248" height="50"#', $body) === 1, 'el logotipo lleva ancho y alto');
    contains('<a class="af-nav-link" href="/modulos" aria-current="page">Módulos</a>', $body);
    contains('>Servicios</a>', $body);
    notContains('>Roto<', $body, 'un elemento sin dirección no se pinta');
    notContains('Grupo vacío', $body);
    contains('class="gm-account af-hide-sm" href="/mi-cuenta" data-af-account>Mi cuenta</a>', $body);
    contains('class="af-icon-btn" href="/carrito" data-af-drawer-open="af-cart"', $body);
    contains('data-af-cart-count', $body);
    contains('data-af-drawer-open="af-menu"', $body, 'el menú del móvil abre su panel');
    notContains('gm-contact', $body, 'sin enlace configurado no hay botón «Hablemos»');
    $linked = gmApp(['header_button_link' => '/contacto', 'announce_text' => 'Aviso <b>&', 'announce_aside' => 'otra <i>.test'], ['catalog', 'media']);
    gmShopRoutes($linked);
    $other = (new TestClient($linked))->get('/')->body;
    contains('<a class="af-btn gm-contact af-hide-sm" href="/contacto">Hablemos</a>', $other);
    contains('Aviso &lt;b&gt;&amp;', $other, 'el aviso del comerciante va escapado');
    contains('<span class="gm-announce-aside">otra &lt;i&gt;.test</span>', $other, 'el texto de la derecha también va escapado');
    $none = gmApp(['announce_show' => 0], ['catalog', 'media']);
    notContains('gm-announce', (new TestClient($none))->get('/')->body, 'la franja se puede quitar');
});

test('la portada: titular con su raya, diagrama, buscador con atajos, tarjetas de portada, servicios y presentación, y un solo h1', function (): void {
    $app = gmApp([], ['catalog', 'media', 'orders']);
    gmSeed($app);
    gmShopRoutes($app);
    $body = (new TestClient($app))->get('/')->body;
    contains('<h1 class="gm-hero-title" id="gm-hero-title">Tu tienda tiene<br>potencial. Dale<br>las herramientas.</h1>', $body);
    same(1, substr_count($body, '<h1'));
    contains('Herramientas para tu e-commerce', $body);
    contains('class="gm-diagram" role="img"', $body);
    contains('Tu PrestaShop', $body);
    contains('Venta B2B', $body);
    contains('href="#gm-catalog">Explorar módulos</a>', $body, 'sin enlace a toda la tienda, el botón baja a las tarjetas');
    notContains('Cuéntame tu proyecto</a>', $body, 'el segundo botón solo sale con enlace');
    contains('class="gm-search" role="search" action="/buscar"', $body);
    contains('href="/buscar?q=conector">Integraciones</a>', $body, 'atajos de búsqueda');
    contains('href="/buscar?q=B2B">Ventas y B2B</a>', $body);
    contains('id="gm-catalog"', $body);
    contains('Pequeñas herramientas.', $body);
    contains('class="gm-card gm-card-home af-product-card', $body, 'tarjetas de portada');
    contains('class="gm-card-more af-product-card-link"', $body, 'con su «Ver módulo ↗»');
    contains('Ver módulo', $body);
    contains('Norte Pro', $body, 'la marca como etiqueta monoespaciada');
    contains('class="gm-service gm-service-dark"', $body);
    contains('class="gm-service gm-service-pale"', $body);
    contains('Tu negocio no encaja<br>en un módulo estándar.', $body);
    contains('class="gm-continuity"', $body);
    contains('Verás la nueva marca en correos y facturas.', $body);
    $order = [strpos($body, 'class="af-container gm-hero"'), strpos($body, 'class="af-container gm-discovery"'), strpos($body, 'id="gm-catalog"'), strpos($body, 'id="servicios"'), strpos($body, 'class="af-container gm-about"')];
    $sorted = $order;
    sort($sorted);
    check($order === $sorted && !in_array(false, $order, true), 'orden: titular, buscador, productos, servicios y presentación');

    // Si una sección trae su h1, el del titular pasa a ser un párrafo.
    $withH1 = gmApp();
    $withH1->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'cms.home', 'order' => 10, 'html' => '<h1>Mi portada</h1>'];

        return $sections;
    });
    $other = (new TestClient($withH1))->get('/')->body;
    same(1, substr_count($other, '<h1'));
    contains('<p class="gm-hero-title"', $other);

    $custom = gmApp([
        'home_title' => 'Hola|mundo <b>', 'shop_link' => '/tienda', 'home_button2_link' => '/contacto', 'home_button2' => 'Escríbeme',
        'diagram_show' => 0, 'services_show' => 0, 'about_show' => 0, 'home_chips' => 'Uno=uno, Dos, =vacío',
    ], ['catalog', 'media', 'orders']);
    gmSeed($custom);
    gmShopRoutes($custom);
    $mine = (new TestClient($custom))->get('/')->body;
    contains('>Hola<br>mundo &lt;b&gt;</h1>', $mine, 'el titular propio, con su salto y escapado');
    contains('href="/tienda">Explorar módulos</a>', $mine);
    contains('class="gm-text-link" href="/tienda">Ver todos los módulos', $mine);
    contains('href="/contacto">Escríbeme</a>', $mine);
    contains('href="/buscar?q=uno">Uno</a>', $mine);
    contains('href="/buscar?q=Dos">Dos</a>', $mine, 'un atajo sin «=» busca su propia etiqueta');
    same(2, substr_count($mine, 'href="/buscar?q='), 'solo dos atajos válidos y el de «=vacío» se descarta');
    notContains('gm-diagram', $mine);
    notContains('gm-service', $mine);
    notContains('gm-about', $mine);
    $empty = (new TestClient(gmApp([], ['media'])))->get('/')->body;
    contains('af-empty', $empty, 'sin secciones, el aviso amable');
    notContains('gm-discovery', $empty, 'sin ruta de búsqueda no hay buscador');
});

test('la tarjeta del listado: imagen con insignia «¡Oferta!», nombre, extracto de dieciocho palabras, precio y botón azul', function (): void {
    $app = gmApp([], ['catalog', 'media', 'orders']);
    $ids = gmSeed($app);
    $web = new TestClient($app);
    $list = $web->get('/categoria/taladros')->body;
    $card = gmSlice($list, '<article class="gm-card af-product-card"', '</article>');
    contains('<a class="af-product-card-media" href="/producto/', $card);
    contains('class="gm-card-link af-product-card-link" href="/producto/', $card, 'el nombre enlaza a la ficha y su enlace se estira sobre toda la tarjeta');
    contains('<h3 class="af-product-card-name">', $card);
    check($ids['broca'] > 0, 'tienda creada');
    $all = gmSlice($list, '<ul class="af-grid af-cols-4 gm-grid" role="list">', '</main>');
    contains('<li><span class="af-badge af-badge-sale">¡Oferta!</span></li>', $all, 'la insignia de oferta con el texto de la tienda');
    contains('<s class="af-price-old"', $all, 'el precio anterior va tachado');
    contains('Precio anterior', $all, 'texto alternativo del precio anterior');
    check(preg_match('/<div class="gm-card-excerpt">Broca para hormigón y piedra de ocho milímetros con vástago SDS y punta de widia reforzada para taladrar…<\/div>/u', $all) === 1, 'el extracto se corta a dieciocho palabras con puntos suspensivos');
    contains('data-af-cart-add', $all, 'el botón añade por formulario');
    check(preg_match('/class="af-co-add gm-add af-btn af-btn-primary af-btn-sm" data-af-add[^>]*>Añadir al carrito<\/button>/', $all) === 1, 'el botón azul de añadir');
    contains('name="qty"', $all, 'con unidades');
    contains('data-af-option="', $all, 'con variantes, se eligen en la propia tarjeta');
    contains('class="gm-add af-btn af-btn-primary af-btn-sm is-disabled" aria-disabled="true">Agotado</span>', $all, 'sin existencias, un botón apagado');
    check(preg_match('/<span class="af-badge af-badge-sale af-price-off"[^>]*><\/span>/', $all) === 0, 'sin insignia de porcentaje vacía');

    // Sin módulo de pedidos no hay formulario de añadir, pero el resto de la tarjeta sigue.
    $bare = gmApp();
    gmSeed($bare);
    $plain = (new TestClient($bare))->get('/categoria/taladros')->body;
    notContains('data-af-cart-add', $plain);
    contains('af-product-card-name', $plain);
});

test('el listado: panel con el título, subcategorías con imagen, cuenta de resultados, orden, botón de filtros y panel lateral con su cierre', function (): void {
    $app = gmApp([], ['catalog', 'media', 'orders']);
    gmSeed($app);
    gmShopRoutes($app);
    $body = (new TestClient($app))->get('/categoria/taladros')->body;
    contains('<header class="gm-list-head">', $body);
    contains('<h1 class="gm-list-title af-title">Taladros</h1>', $body);
    same(1, substr_count($body, '<h1'));
    contains('class="gm-tiles af-grid af-cols-4" aria-label="Subcategorías"', $body);
    contains('class="gm-tile" href="/categoria/taladros/percutores"', $body);
    contains('<span class="gm-tile-count">3</span>', $body);
    check(preg_match('#<p class="gm-count af-muted" role="status">Mostrando 1–5 de 5 resultados</p>#u', $body) === 1, 'cuenta de resultados al estilo de la tienda de origen');
    contains('id="af-sort"', $body);
    contains('data-af-autosubmit', $body);
    contains('form="af-filters-form"', $body);
    contains('class="af-btn af-btn-ghost af-btn-sm gm-filter-btn af-hide-sm" data-af-drawer-open="af-filters"', $body, 'botón de filtros en escritorio');
    contains('id="af-filters"', $body);
    contains('class="af-drawer-head"', $body, 'el panel de filtros lleva su cierre también en escritorio');
    contains('data-af-drawer-close', $body);
    contains('class="af-grid af-cols-4 gm-grid"', $body);
    same(5, substr_count($body, '<article class="gm-card'), 'cinco tarjetas');
    $toolbar = strpos($body, 'class="gm-toolbar"');
    $grid = strpos($body, 'class="af-grid af-cols-4 gm-grid"');
    check($toolbar < $grid, 'la barra de orden va encima de la rejilla');

    // Una sola página: sin paginación; con filtros que no dejan nada, el aviso con su salida.
    notContains('af-pagination', $body);
    $none = (new TestClient($app))->get('/categoria/taladros?min=99999');
    same(200, $none->status);
    contains('No se ha encontrado ningún producto', $none->body);
    contains('Quitar los filtros', $none->body);
    contains('<p class="gm-count af-muted" role="status"></p>', $none->body, 'sin resultados no hay línea de cuenta con cifras');
});

test('contrato de móvil: barra inferior con buscar, carrito con contador y cuenta; con filtros solo en el listado; cabecera pegajosa', function (): void {
    $app = gmApp();
    gmSeed($app);
    gmShopRoutes($app);
    gmMenu($app);
    $web = new TestClient($app);
    $home = $web->get('/')->body;
    contains('<nav class="gm-mbar af-mobile-bar af-show-sm"', $home);
    contains('data-af-drawer-open="af-search"', $home);
    contains('data-af-drawer-open="af-cart"', $home);
    contains('data-af-cart-count', $home);
    contains('af-mobile-bar-label">Cuenta', $home);
    contains('af-has-bar', $home);
    notContains('data-af-drawer-open="af-filters"', $home, 'sin filtros funcionales no hay botón de filtros');
    contains('id="af-cart"', $home);
    contains('data-af-cart-lines', $home);
    contains('id="af-search"', $home, 'la capa de búsqueda del móvil existe');
    contains('id="af-menu"', $home, 'y el panel del menú');
    $list = $web->get('/categoria/taladros')->body;
    contains('<button type="button" class="af-mobile-bar-item" data-af-drawer-open="af-filters" aria-controls="af-filters"', $list);

    $css = gmCssPacked();
    check(preg_match('/\.af-header\{position:sticky/', $css) === 1, 'la cabecera es pegajosa en móvil');
    check(preg_match('/@media\(min-width:900px\)\{[^@]*\.af-header\.gm-header\{position:relative\}/', $css) === 1, 'y solo en escritorio deja de serlo');
    check(preg_match('/\.af-mobile-bar\{[^}]*position:fixed[^}]*bottom:0/', $css) === 1, 'la barra inferior es fija abajo');
    check(preg_match('/\.af-has-bar\{padding-bottom:calc\(var\(--bar-h\)\+env\(safe-area-inset-bottom\)\)/', $css) === 1, 'el contenido reserva el alto de la barra');
    check(preg_match('/\.af-mobile-bar-item\{[^}]*min-width:44px[^}]*min-height:var\(--bar-h\)/', $css) === 1, 'áreas táctiles de 44 px');
    check(preg_match('/--bar-h:3\.[0-9]+rem/', $css) === 1, 'la barra mide al menos 44 px');
    contains('.af-show-sm{display:none!important}', $css);
    contains('.af-hide-sm{display:none!important}', $css);
    contains('.gm-diagram{display:grid;', $css, 'el diagrama se recompone en móvil');
    contains('.af-header-actions.gm-account', $css, 'el enlace de la cuenta tiene su regla de escritorio');
});

test('el blog: índice con su etiqueta, tarjetas de dos columnas con «Leer artículo» y la entrada con la etiqueta «Guías»', function (): void {
    $app = cmsApp(['cms', 'catalog', 'media'], ['theme.active' => 'gmartos'], false);
    $term = cmsTerm($app, 'Novedades');
    $id = cmsInsertPost($app, ['title' => 'Primera <entrada> & más', 'excerpt' => 'Extracto del <artículo>', 'terms' => [$term]]);
    cmsInsertPost($app, ['title' => 'Segunda entrada']);
    check($id > 0, 'entrada creada');
    $web = new TestClient($app);
    $index = $web->get('/blog');
    same(200, $index->status);
    contains('<div class="af-container gm-editorial">', $index->body);
    contains('<p class="gm-kicker">Conocimiento para tu tienda</p>', $index->body);
    contains('class="af-grid af-cols-2 gm-articles"', $index->body);
    contains('class="gm-article af-post-card"', $index->body);
    contains('Leer artículo', $index->body);
    contains('Primera &lt;entrada&gt; &amp; más', $index->body);
    contains('Extracto del &lt;artículo&gt;', $index->body);
    notContains('Primera <entrada>', $index->body);
    contains('class="gm-chips" aria-label="Categorías"', $index->body);
    same(1, substr_count($index->body, '<h1'));
    check(preg_match('#href="(/blog/[^"]*primera[^"]*)"#', $index->body, $m) === 1, 'la tarjeta enlaza a la entrada');
    $post = $web->get($m[1]);
    same(200, $post->status);
    contains('<p class="gm-kicker">Guías</p>', $post->body);
    contains('class="af-prose gm-reading-content"', $post->body);
    contains('Texto de Primera', $post->body);
    same(1, substr_count($post->body, '<h1'));
    contains('af-header gm-header', $post->body);
});

test('el pie: logotipo inverso, frase, columnas del menú, cuenta y contacto de la tienda, y el © con los enlaces legales', function (): void {
    $app = gmApp([], ['catalog', 'media'], 'gmartos', ['store.email' => 'tienda@ejemplo.test', 'store.phone' => '999 000 001', 'store.name' => 'Tienda <b>&']);
    gmMenu($app);
    gmShopRoutes($app);
    $body = (new TestClient($app))->get('/')->body;
    $footer = gmSlice($body, '<footer class="af-footer">', '</footer>');
    contains('/img/logo-inverse.svg', $footer);
    contains('Herramientas para tiendas<br>que quieren ir más allá.', $footer);
    contains('aria-label="Explora"', $footer, 'cada elemento del menú con hijos es una columna');
    contains('<a href="/blog">Guías</a>', $footer);
    contains('<a href="/suelto">Suelto</a>', $footer, 'los elementos sueltos van juntos en una columna');
    contains('aria-label="Tu cuenta"', $footer);
    contains('<a href="/mi-cuenta">Mi cuenta</a>', $footer);
    contains('<a href="mailto:tienda@ejemplo.test">tienda@ejemplo.test</a>', $footer);
    contains('<a href="tel:999000001">999 000 001</a>', $footer);
    contains('Tienda &lt;b&gt;&amp;', $footer, 'el nombre de la tienda va escapado');
    notContains('Tienda <b>', $footer);
    contains('<a class="af-nav-link" href="/privacidad">Privacidad</a>', $footer);
    $custom = gmApp(['footer_tagline' => 'Una|dos'], ['catalog', 'media']);
    contains('Una<br>dos', (new TestClient($custom))->get('/')->body);
});

test('las ayudas del tema: enlaces seguros, líneas escapadas, menú limpio y extracto recortado', function (): void {
    $app = gmApp();
    gmSeed($app);
    foreach (['javascript:alert(1)', 'data:text/html,x', '//otro.test/x', 'ftp://x.test', '', '  '] as $bad) {
        same('', gmartos_link($bad), 'un enlace peligroso o vacío da «»: ' . $bad);
    }
    same('/ruta', gmartos_link('/ruta'));
    same('#ancla', gmartos_link('#ancla'));
    same('https://ejemplo.test/x', gmartos_link('https://ejemplo.test/x'));
    same('Uno<br>Dos &lt;b&gt;', gmartos_lines('Uno| Dos <b> |'), 'el «|» parte la línea y todo va escapado');
    same('', gmartos_lines(''));
    $menu = gmartos_clean_menu([['label' => 'A', 'url' => '/a'], ['label' => '', 'url' => '/x'], ['label' => 'Sin', 'url' => ''], ['label' => 'Grupo', 'url' => '', 'children' => [['label' => 'H', 'url' => '/h']]], 'basura']);
    same(['A', 'Grupo'], array_column($menu, 'label'));
    same('/h', $menu[1]['children'][0]['url']);
    $text = trim((string) gmartos_text('no_existe', 'De fábrica'));
    same('De fábrica', $text, 'sin opción, el texto de fábrica');
    same('', gmartos_excerpt(['id' => 0]), 'una tarjeta sin id no tiene extracto');
    $svg = gmartos_svg('arrow', 17);
    check(strpos($svg, 'width="17"') !== false && strpos($svg, 'aria-hidden="true"') !== false, 'los iconos son decorativos');
    same(0, preg_match('/<script|onload=/i', $svg), 'un icono no lleva código');
});

test('estructura distinta de la del tema base, de taller, de atelier y de aifec: cabecera, tarjeta, listado y ficha se parecen menos de un 82 %', function (): void {
    $gm = gmSurfaces('gmartos');
    foreach (['base', 'taller', 'atelier', 'aifec'] as $other) {
        $surfaces = gmSurfaces($other);
        $worst = [];
        foreach (['header', 'card', 'listing', 'product'] as $surface) {
            $byClass = gmSimilarity(gmSequence($surfaces[$surface], true), gmSequence($gm[$surface], true));
            $byTag = gmSimilarity(gmSequence($surfaces[$surface], false), gmSequence($gm[$surface], false));
            $worst[$surface] = round($byClass, 3) . ' / ' . round($byTag, 3);
            check($byClass < 0.82, $surface . ' contra ' . $other . ': la secuencia etiqueta.clase se parece un ' . round($byClass * 100) . ' %');
            check($byTag < 0.82, $surface . ' contra ' . $other . ': la secuencia de etiquetas se parece un ' . round($byTag * 100) . ' %');
            check(strlen($gm[$surface]) > 200, $surface . ' tiene contenido para comparar');
        }
        check(count($worst) === 4, 'las cuatro superficies medidas contra ' . $other . ': ' . json_encode($worst));
    }
});

test('un nombre de producto con <b>&: escapado en tarjeta, ficha, migas y datos para compartir', function (): void {
    $app = gmApp();
    gmSeed($app);
    $web = new TestClient($app);
    $product = $web->get('/producto/taladro-percutor')->body;
    contains('Taladro &lt;b&gt;&amp;', $product);
    notContains('Taladro <b>', $product);
    $list = $web->get('/categoria/taladros')->body;
    contains('Taladro &lt;b&gt;&amp;', $list);
    notContains('Taladro <b>', $list);
    $home = $web->get('/')->body;
    notContains('Taladro <b>', $home);
    $sample = $web->get('/tema/muestra')->body;
    same(1, substr_count($sample, '<h1'));
});

test('el carrito vacío y la muestra del tema se ven con la hoja de gmartos y sin errores', function (): void {
    $app = gmApp([], ['catalog', 'media', 'orders']);
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
    contains('class="gm-add-form"', $sample->body, 'las tarjetas de la muestra también se pueden añadir');
    $imgs = [];
    preg_match_all('/<img\b[^>]*>/', $sample->body, $imgs);
    foreach ($imgs[0] as $img) {
        check(strpos($img, ' width="') !== false && strpos($img, ' height="') !== false, 'toda imagen lleva ancho y alto: ' . substr($img, 0, 80));
    }
    $missing = $web->get('/no-existe-esta-pagina');
    same(404, $missing->status);
    contains('af-header gm-header', $missing->body, 'la página de error va con el envoltorio del tema');
});

test('peso: theme.css pesa como mucho 45 000 bytes y el tema no necesita guion propio', function (): void {
    $css = gmDir() . '/assets/css/theme.css';
    check(is_file($css), 'existe la hoja');
    check(filesize($css) <= 45000, 'theme.css pesa ' . filesize($css));
    $js = gmDir() . '/assets/js/theme.js';
    check(!is_file($js) || filesize($js) <= 25000, 'si hay guion propio pesa como mucho 25 000 bytes');
    foreach (gmFiles('assets/img') as $img) {
        check(filesize($img) <= 20000, basename($img) . ' pesa ' . filesize($img));
    }
});

test('ninguna dirección externa en las plantillas ni en los recursos (salvo el espacio de nombres de los SVG) y ningún @import', function (): void {
    $files = array_merge(gmFiles('templates'), gmFiles('assets'));
    check(count($files) >= 14, 'se han encontrado las plantillas y los recursos');
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        same(0, preg_match('#https?://(?!www\.w3\.org)#i', $text), basename($file) . ' lleva una dirección externa');
        notContains('@import', $text, basename($file));
        notContains('//cdn', $text, basename($file));
        notContains('fonts.googleapis', $text, basename($file));
    }
    foreach (['logo.svg', 'logo-inverse.svg'] as $svg) {
        $xml = @simplexml_load_string((string) file_get_contents(gmDir() . '/assets/img/' . $svg));
        check($xml !== false, $svg . ' es un SVG bien formado');
        notContains('<script', (string) file_get_contents(gmDir() . '/assets/img/' . $svg), $svg . ' no lleva código');
    }
});

test('theme.css: ningún color fuera de :root, sin fuentes ni iconos de fuera y con los medios de accesibilidad', function (): void {
    $css = (string) file_get_contents(gmDir() . '/assets/css/theme.css');
    $rest = gmCssWithoutRoot($css);
    $found = [];
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $rest, $found);
    same([], $found[0], 'colores fijos fuera de :root');
    check(preg_match('/\b(?:rgb|rgba|hsl|hsla)\(/', $rest) === 0, 'colores funcionales fuera de :root');
    notContains('url(', $css);
    check(preg_match('/^\s*@font-face/m', $css) === 0, 'sin @font-face activo');
    foreach (['prefers-reduced-motion', ':focus-visible', 'env(safe-area-inset-bottom)', 'min-height:44px', 'min-width:44px', 'flex-wrap:wrap', 'overflow-x:auto', 'line-through', 'object-fit:contain', 'position:sticky'] as $needle) {
        contains($needle, $css, 'theme.css no contiene «' . $needle . '»');
    }
    check(preg_match('/--radius:16px/', $css) === 1, 'radio grande de fábrica, como las tarjetas de origen');
    check(preg_match('/--container:1280px/', $css) === 1, 'ancho de origen de 1280 px');
    contains("--font:'Segoe UI',Arial,sans-serif", $css, 'la tipografía de origen, del sistema');
    contains('.gm-hero-title::after{', $css, 'la raya azul bajo el titular');
});

test('el contraste de los pares de colores de fábrica cumple AA (4,5 a 1) también en precio anterior, insignias, azul sobre fondo y texto sobre azul noche', function (): void {
    $css = (string) file_get_contents(gmDir() . '/assets/css/theme.css');
    $vars = [];
    check(preg_match('/:where\(:root\)\{(.*?)\}/s', $css, $m) === 1, 'bloque :root de fábrica');
    preg_match_all('/(--[a-z0-9-]+):(#[0-9a-fA-F]{6})/', $m[1], $found, PREG_SET_ORDER);
    foreach ($found as $pair) {
        $vars[$pair[1]] = $pair[2];
    }
    $lum = static function (string $hex): float {
        $out = 0.0;
        foreach ([1, 3, 5] as $i => $pos) {
            $v = hexdec(substr($hex, $pos, 2)) / 255;
            $v = $v <= 0.03928 ? $v / 12.92 : pow(($v + 0.055) / 1.055, 2.4);
            $out += [0.2126, 0.7152, 0.0722][$i] * $v;
        }

        return $out;
    };
    $ratio = static function (string $a, string $b) use ($lum): float {
        $la = $lum($a);
        $lb = $lum($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    };
    $pairs = [
        ['--ink', '--bg'], ['--ink', '--surface'], ['--ink', '--soft'], ['--ink', '--pale'], ['--muted', '--bg'], ['--muted', '--surface'],
        ['--muted', '--surface-2'], ['--muted', '--soft'], ['--muted', '--pale'], ['--muted', '--info-soft'], ['--accent-ink', '--accent'],
        ['--on-dark', '--sale'], ['--sale', '--surface'], ['--on-dark', '--dark'], ['--dark-muted', '--dark'], ['--link-dark', '--dark'],
        ['--pale', '--dark'], ['--accent', '--bg'], ['--accent', '--surface'], ['--secondary', '--bg'], ['--secondary', '--surface'],
        ['--ok', '--surface'], ['--warn', '--surface'], ['--bad', '--surface'], ['--ok', '--ok-soft'], ['--warn', '--warn-soft'],
        ['--bad', '--bad-soft'], ['--on-dark', '--muted'],
    ];
    foreach ($pairs as $pair) {
        check(isset($vars[$pair[0]], $vars[$pair[1]]), 'faltan las variables ' . implode(' y ', $pair));
        $r = $ratio($vars[$pair[0]], $vars[$pair[1]]);
        check($r >= 4.5, $pair[0] . ' sobre ' . $pair[1] . ' da un contraste de ' . round($r, 2));
    }
});

test('theme.css da estilo a todas las clases del contrato de estilos y a las propias de gmartos', function (): void {
    $css = (string) file_get_contents(gmDir() . '/assets/css/theme.css');
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
    $own = [];
    foreach (gmFiles('templates') as $file) {
        $text = (string) file_get_contents($file);
        $m = [];
        preg_match_all('/class="([^"]*)"/', $text, $m);
        foreach ($m[1] as $attr) {
            $attr = (string) preg_replace('/<\?=.*?\?>/s', ' ', $attr);
            foreach (preg_split('/\s+/', trim($attr)) ?: [] as $name) {
                if (strpos($name, 'gm-') === 0) {
                    $own[$name] = true;
                }
            }
        }
    }
    check(count($own) > 80, 'se han encontrado las clases propias: ' . count($own));
    // Clases que son solo marcas de la plantilla (sirven a una prueba o a un guion, no a la hoja).
    $marks = ['gm-logo-theme'];
    foreach (array_keys($own) as $name) {
        if (in_array($name, $marks, true)) {
            continue;
        }
        check(preg_match('/\.' . preg_quote($name, '/') . '(?![A-Za-z0-9_-])/', $css) === 1, 'theme.css no da estilo a .' . $name);
    }
});

test('theme.json es JSON válido, hijo de base con su hoja propia, y cada opción con var existe en :root', function (): void {
    $json = json_decode((string) file_get_contents(gmDir() . '/theme.json'), true);
    check(is_array($json), 'JSON válido');
    same('gmartos', $json['name']);
    same('base', $json['parent']);
    same('replace', $json['css'], 'la hoja sustituye a la del padre');
    check(!isset($json['js']) || $json['js'] !== 'replace', 'el guion del padre se sigue cargando');
    $css = (string) file_get_contents(gmDir() . '/assets/css/theme.css');
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
    foreach (['announce_show', 'header_button_link', 'shop_link', 'proof_show', 'cta_show', 'home_title', 'diagram_show', 'services_show', 'about_show', 'footer_tagline'] as $key) {
        check(in_array($key, $plain, true), 'falta la opción de estructura ' . $key);
    }
    foreach ($json['settings']['typography']['fields']['font_head']['options'] as $stack => $label) {
        check(isset($json['font_stacks'][$stack]), 'la tipografía «' . $stack . '» no tiene pila');
    }
    check(preg_match('/https?:/', json_encode($json['font_stacks']) ?: '') === 0, 'las pilas son del sistema');
    same(16.0, (float) $json['settings']['layout']['fields']['radius']['default'], 'radio de las tarjetas de origen');
    same('#2563eb', $json['settings']['colors']['fields']['accent']['default'], 'azul de origen');
    same('#10233f', $json['settings']['colors']['fields']['dark']['default'], 'azul noche de origen');
    check(is_file(gmDir() . '/preview.png'), 'preview.png existe');
    $size = getimagesize(gmDir() . '/preview.png');
    check(is_array($size) && $size[0] === 1200 && $size[1] === 900, 'preview.png mide 1200 × 900');
});

test('todas las plantillas y ficheros PHP del tema empiezan por la cabecera del proyecto, en UTF-8 sin BOM, con LF y sin etiqueta de cierre', function (): void {
    $names = ['home', 'partials/header', 'partials/footer', 'partials/mobile-bar', 'partials/product-card', 'partials/price', 'modules/catalog/front/listing',
        'modules/catalog/front/product', 'modules/catalog/front/partials/filters', 'modules/catalog/front/home/categories', 'modules/catalog/front/home/products',
        'modules/cms/front/blog', 'modules/cms/front/post', 'modules/cms/front/post-card', 'modules/cms/front/post-list'];
    foreach ($names as $name) {
        check(is_file(gmDir() . '/templates/' . $name . '.php'), 'falta templates/' . $name . '.php');
    }
    foreach (['functions.php', 'lang/es.php', 'assets/css/theme.css', 'assets/img/logo.svg', 'assets/img/logo-inverse.svg', 'theme.json', 'preview.png', 'NOTAS.md'] as $extra) {
        check(is_file(gmDir() . '/' . $extra), 'falta ' . $extra);
    }
    $php = array_filter(gmFiles(), static function (string $file): bool {
        return substr($file, -4) === '.php';
    });
    check(count($php) >= 17, 'se han encontrado los ficheros PHP: ' . count($php));
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
        check(preg_match('/\bnofilter\b/', $text) === 0, basename($file) . ' usa nofilter');
        check(preg_match('/\(array\)\s*\$/', $text) === 0, basename($file) . ' usa (array) $x en vez de is_array()');
    }
});

test('el catálogo castellano del tema tiene todas las cadenas, ninguna vacía y con tildes y eñes', function (): void {
    $lang = require gmDir() . '/lang/es.php';
    check(is_array($lang) && count($lang) > 150, 'el catálogo está cargado');
    foreach ($lang as $key => $value) {
        check(is_string($value) && trim($value) !== '', 'la cadena «' . $key . '» no tiene traducción');
        check(preg_match('/%[sd]%%/', (string) $value) === 0, 'la cadena «' . $key . '» lleva «%s%%»');
    }
    same('Añadir al carrito', $lang['Add to cart']);
    same('Hablemos', $lang['Get in touch']);
    same('¡Oferta!', $lang['Sale']);
    same('Características', $lang['Specifications']);
    same('Mostrando {from}–{to} de {total} resultados', $lang['Showing {from}–{to} of {total} results']);
    $app = gmApp([], ['catalog', 'media', 'orders']);
    gmSeed($app);
    $web = new TestClient($app);
    $body = $web->get('/producto/taladro-percutor')->body;
    contains('Añadir al carrito', $body);
    contains('También te puede interesar', $body);
    contains('Preguntas frecuentes', $body);
    notContains('Add to cart', $body);
    $list = $web->get('/categoria/taladros')->body;
    contains('Ordenar por', $list);
    notContains('Sort by', $list);
});
