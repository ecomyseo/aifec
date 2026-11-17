<?php
/**
 * AIFEC — pruebas del tema taller (bricolaje y ferretería): que sus cinco superficies responden con su hoja y su
 * estructura propias, que conservan los datos y los huecos de las plantillas que sustituyen, el contrato de móvil, las
 * opciones del tema y las comprobaciones estáticas del presupuesto de peso y del contrato de estilos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Autoload;
use Aifec\Kernel\App;

require_once dirname(__DIR__, 2) . '/modules/catalog/tests/helpers.php';

/** Carpeta del tema taller. */
function tallerDir(): string
{
    return rtrim(str_replace('\\', '/', Autoload::root()), '/') . '/themes/taller';
}

/**
 * Todos los ficheros de una carpeta del tema (recursivo).
 *
 * @return string[]
 */
function tallerFiles(string $sub = ''): array
{
    $files = [];
    $dir = tallerDir() . ($sub !== '' ? '/' . $sub : '');
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
function tallerCssWithoutRoot(string $css): string
{
    return (string) preg_replace('/:where\(:root\)\s*\{[^}]*\}|:root\s*\{[^}]*\}/', '', $css);
}

/** La hoja sin comentarios ni espacios, para comparar reglas sin depender del formato. */
function tallerCssPacked(): string
{
    $css = (string) file_get_contents(tallerDir() . '/assets/css/theme.css');
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $css);

    return (string) preg_replace('/\s+/', '', $css);
}

/**
 * Aplicación con el tema activo, el catálogo y los medios, arrancada.
 *
 * @param array<string,mixed> $options  opciones del tema que cambia el comerciante
 * @param string[]            $modules
 * @param string              $theme    taller, base o atelier (para comparar estructuras)
 * @param array<string,mixed> $extra    ajustes de la tienda (store.phone…)
 */
function tallerApp(array $options = [], array $modules = ['catalog', 'media'], string $theme = 'taller', array $extra = []): App
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
function tallerShopRoutes(App $app): void
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
function tallerMenu(App $app): void
{
    $app->hooks()->filter('menu.items', static function (array $items, string $location): array {
        if ($location !== 'main') {
            return $items;
        }

        return [
            ['label' => 'Herramientas', 'url' => '/herramientas', 'current' => true, 'children' => [
                ['label' => 'Taladros', 'url' => '/herramientas/taladros', 'children' => [['label' => 'Percutores', 'url' => '/herramientas/taladros/percutores']]],
                ['label' => 'Sierras', 'url' => '/herramientas/sierras', 'children' => []],
            ]],
            ['label' => 'Pintura', 'url' => '/pintura', 'children' => []],
            ['label' => 'Roto', 'url' => ''],
            ['label' => 'Grupo vacío', 'url' => '', 'children' => []],
        ];
    });
}

/**
 * La tienda de las pruebas: una categoría, una marca, atributos, un taladro con variantes, tres imágenes, características
 * clave, un documento, preguntas y relacionados, y otros cuatro productos para llenar el listado. Con los mismos datos en
 * cualquier tema.
 *
 * @return array<string,int>
 */
function tallerSeed(App $app): array
{
    $app->boot();
    $ids = [];
    $ids['cat'] = aifecCategory($app, 'Taladros', null, ['media_id' => aifecMedia($app, 'Taladros')]);
    $ids['brand'] = aifecBrand($app, 'Norte Pro');
    aifecAttribute($app, 'potencia', 'Potencia', ['500' => '500 W', '750' => '750 W', '1000' => '1000 W']);
    $ids['broca'] = aifecProduct($app, [
        'name' => 'Broca de widia', 'slug' => 'broca-widia', 'price' => 590, 'images' => 2, 'categories' => [$ids['cat']], 'brand_id' => $ids['brand'],
        'sku' => 'BRO-8', 'facts' => [['label' => 'Diámetro', 'value' => '8 mm'], ['label' => 'Longitud', 'value' => '120 mm'], ['label' => 'Material', 'value' => 'Widia'], ['label' => 'Vástago', 'value' => 'SDS']],
    ]);
    $ids['maletin'] = aifecProduct($app, ['name' => 'Maletín de brocas', 'slug' => 'maletin-brocas', 'price' => 2490, 'images' => 1, 'categories' => [$ids['cat']], 'stock' => 0, 'sku' => 'MAL-1']);
    aifecProduct($app, ['name' => 'Atornillador 12 V', 'slug' => 'atornillador-12v', 'price' => 5990, 'images' => 2, 'categories' => [$ids['cat']], 'compare_price' => 7990, 'sku' => 'ATO-12']);
    aifecProduct($app, ['name' => 'Nivel láser', 'slug' => 'nivel-laser', 'price' => 3900, 'categories' => [$ids['cat']]]);
    $ids['drill'] = aifecProduct($app, [
        'name' => 'Taladro <b>&', 'slug' => 'taladro-percutor', 'price' => 8900, 'compare_price' => 11900, 'images' => 3,
        'categories' => [$ids['cat']], 'brand_id' => $ids['brand'], 'sku' => 'TAL-750', 'gtin' => '8412345678905',
        'summary' => '<p>Resumen corto</p>', 'description' => '<p>Texto largo del taladro</p>',
        'facts' => [['label' => 'Potencia', 'value' => '750 W'], ['label' => 'Mandril', 'value' => '13 mm'], ['label' => 'Peso', 'value' => '2,1 kg']],
        'attributes' => ['potencia' => '750'],
        'faqs' => [['question' => '¿Trae maletín?', 'answer' => '<p>Sí.</p>']],
        'related' => [$ids['broca'], $ids['maletin']],
        'extra' => ['ficha' => ['label' => 'Ficha técnica (PDF)', 'value' => '/docs/ficha-taladro.pdf', 'type' => 'file', 'public' => true], 'garantia' => ['label' => 'Garantía', 'value' => '3 años', 'type' => 'text', 'public' => true]],
        'variants' => [
            ['sku' => 'TAL-500', 'stock' => 3, 'values' => ['potencia' => '500']],
            ['sku' => 'TAL-1000', 'stock' => 5, 'values' => ['potencia' => '1000']],
        ],
    ]);

    return $ids;
}

/** La secuencia «etiqueta.clase» de las etiquetas de abrir de un trozo de HTML: lo que sirve para comparar composiciones. */
function tallerSequence(string $html, bool $withClass): string
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
function tallerSimilarity(string $a, string $b): float
{
    $percent = 0.0;
    similar_text($a, $b, $percent);

    return $percent / 100;
}

/** La parte de una página que va entre dos marcas. */
function tallerSlice(string $html, string $from, string $to): string
{
    $start = strpos($html, $from);
    check($start !== false, 'no está «' . $from . '»');
    $end = strpos($html, $to, (int) $start);
    check($end !== false, 'no está «' . $to . '»');

    return substr($html, (int) $start, (int) $end - (int) $start);
}

/** Cada superficie de una tienda con los mismos datos, para compararla entre temas. */
function tallerSurfaces(string $theme): array
{
    $app = tallerApp([], ['catalog', 'media', 'orders'], $theme);
    tallerSeed($app);
    tallerMenu($app);
    tallerShopRoutes($app);
    $web = new TestClient($app);
    $list = $web->get('/categoria/taladros')->body;
    $product = $web->get('/producto/taladro-percutor')->body;
    check(strpos($list, '<article') !== false, 'el listado de ' . $theme . ' trae tarjetas');
    $card = tallerSlice($list, '<article', '</article>');

    return [
        'header' => tallerSlice($list, '<header class="', '</header>'),
        'card' => $card,
        'listing' => tallerSlice($list, '<main id="main"', '</main>'),
        'product' => tallerSlice($product, '<main id="main"', '</main>'),
    ];
}

test('portada, listado, ficha, carrito y muestra responden 200 con la hoja de taller y no con la del tema base', function (): void {
    $app = tallerApp([], ['catalog', 'media', 'orders']);
    tallerSeed($app);
    $web = new TestClient($app);
    foreach (['/', '/categoria/taladros', '/producto/taladro-percutor', '/carrito', '/tema/muestra'] as $uri) {
        $r = $web->get($uri);
        same(200, $r->status, $uri);
        contains('/assets/t/taller/', $r->body, $uri);
        check(preg_match('#<link rel="stylesheet" href="[^"]*/assets/t/taller/[^"]*/css/theme\.css">#', $r->body) === 1, $uri . ': enlaza theme.css de taller');
        check(preg_match('#/assets/t/base/[^"]*/css/theme\.css#', $r->body) === 0, $uri . ': no enlaza la hoja del tema base');
        check(preg_match('#/assets/t/base/[^"]*/js/theme\.js" defer#', $r->body) === 1, $uri . ': el guion del tema base sigue cargándose');
        check(preg_match('#/assets/t/taller/[^"]*/js/theme\.js" defer#', $r->body) === 1, $uri . ': y el pequeño de taller va con defer');
        contains('tl-header af-header', $r->body, $uri . ': la cabecera es la de taller');
        notContains('href=""', $r->body, $uri);
    }
});

test('la ficha: galería antes que la compra, un solo h1, formulario de compra, JSON de variantes válido y datos completos', function (): void {
    $app = tallerApp([], ['catalog', 'media', 'orders']);
    tallerSeed($app);
    $body = (new TestClient($app))->get('/producto/taladro-percutor')->body;
    $gallery = strpos($body, 'af-product-gallery');
    $buy = strpos($body, 'af-product-buy');
    check($gallery !== false && $buy !== false && $gallery < $buy, 'la galería va antes que la compra en el HTML (izquierda en escritorio)');
    same(1, substr_count($body, '<h1'), 'un solo h1');
    contains('<h1 class="tl-pdp-title af-title">Taladro &lt;b&gt;&amp;</h1>', $body);
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
    same(2, substr_count($body, '<article class="tl-card af-product-card'), 'dos accesorios relacionados');
    contains('af-variant', $body, 'variantes como botones');
    contains('data-af-option="potencia"', $body);
});

test('la ficha: miniaturas en vertical, tabla de características a todo el ancho antes que la descripción, documentos y precio con y sin IVA', function (): void {
    $app = tallerApp([], ['catalog', 'media', 'orders']);
    tallerSeed($app);
    $body = (new TestClient($app))->get('/producto/taladro-percutor')->body;
    $gal = tallerSlice($body, '<div class="tl-gal af-gallery"', 'class="tl-buy af-product-buy');
    contains('data-af-gallery', $gal);
    check(strpos($gal, 'tl-gal-stage') < strpos($gal, 'tl-gal-rail'), 'la foto grande y detrás la tira de miniaturas (el CSS la pone a la izquierda)');
    contains('<ol class="tl-gal-rail af-gallery-thumbs">', $gal);
    same(3, substr_count($gal, 'data-src='), 'una miniatura por imagen');
    same(3, substr_count($gal, 'data-image-id='), 'cada miniatura sabe qué imagen es (lo usa theme.js al elegir variante)');
    same(1, substr_count($gal, 'data-af-gallery-main'), 'una sola imagen principal');
    check(preg_match('#<img[^>]*data-af-gallery-main[^>]*>#', $gal, $main) === 1, 'hay imagen principal');
    contains('loading="eager"', $main[0]);
    contains('fetchpriority="high"', $main[0]);

    $specs = strpos($body, 'id="af-specs"');
    $desc = strpos($body, 'id="af-description"');
    check($specs !== false && $desc !== false && $specs < $desc, 'la tabla de características va antes que la descripción');
    contains('<table class="tl-table af-table">', $body);
    contains('<th scope="row">Garantía</th><td>3 años</td>', $body);
    notContains('/docs/ficha-taladro.pdf</td>', $body, 'el documento no se pinta como fila de la tabla');
    contains('id="af-docs"', $body, 'documentos descargables');
    contains('<a class="tl-doc" href="/docs/ficha-taladro.pdf">', $body);
    contains('Ficha técnica (PDF)', $body);
    contains('data-tl-gross', $body, 'precio con IVA');
    contains('data-tl-net', $body, 'y sin IVA a la vez');
    contains('IVA incluido', $body);
    contains('Sin IVA', $body);
    contains('Referencia:', $body, 'referencia y marca encima del título');
    check(strpos($body, 'tl-buy-ref') < strpos($body, 'tl-pdp-title'), 'referencia y marca van antes del nombre');

    $none = tallerApp();
    aifecProduct($none, ['name' => 'Sin foto', 'slug' => 'sin-foto']);
    $r = (new TestClient($none))->get('/producto/sin-foto');
    same(200, $r->status);
    contains('af-noimage', $r->body, 'sin imágenes: hueco y sin errores');
    notContains('id="af-docs"', $r->body, 'sin documentos no hay bloque');
    notContains('tl-gal-rail', $r->body, 'con una sola imagen no hay tira de miniaturas');
});

test('un documento con una dirección peligrosa no se enlaza y el resto de la ficha sigue', function (): void {
    check(function_exists('taller_doc'), 'functions.php define taller_doc');
    same(null, taller_doc(['public' => true, 'type' => 'file', 'label' => 'x', 'value' => 'javascript:alert(1)']));
    same(null, taller_doc(['public' => true, 'type' => 'file', 'label' => 'x', 'value' => '//otro.test/a.pdf']));
    same(null, taller_doc(['public' => false, 'type' => 'file', 'label' => 'x', 'value' => '/a.pdf']));
    same(null, taller_doc(['public' => true, 'type' => 'text', 'label' => 'x', 'value' => '/a.pdf']));
    same(null, taller_doc('texto'));
    same(['label' => 'Manual', 'url' => 'https://tienda.test/m.pdf'], taller_doc(['public' => true, 'type' => 'url', 'label' => 'Manual', 'value' => 'https://tienda.test/m.pdf']));
});

test('la ficha conserva todos los huecos de la plantilla original del catálogo y los del envoltorio', function (): void {
    $original = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/catalog/views/front/product.php');
    $names = [];
    preg_match_all("/hook\('(product\.[a-z.]+)'/", $original, $names);
    $names = array_values(array_unique($names[1]));
    check(count($names) >= 7, 'la plantilla original declara sus huecos: ' . implode(', ', $names));
    $app = tallerApp();
    tallerSeed($app);
    foreach ($names as $name) {
        $app->hooks()->on($name, static function () use ($name): string {
            return '<i data-hole="' . $name . '"></i>';
        });
    }
    $body = (new TestClient($app))->get('/producto/taladro-percutor')->body;
    foreach ($names as $name) {
        same(1, substr_count($body, '<i data-hole="' . $name . '"></i>'), 'el hueco «' . $name . '» sale una vez');
    }
    // Y los de la portada y el envoltorio que taller también sustituye.
    $shell = tallerApp();
    $holes = ['body.start', 'header.before', 'header.after', 'footer.before', 'footer.after', 'body.end', 'header.actions', 'home.top', 'home.bottom'];
    foreach ($holes as $name) {
        $shell->hooks()->on($name, static function () use ($name): string {
            return '<i data-hole="' . $name . '"></i>';
        });
    }
    tallerShopRoutes($shell);
    $home = (new TestClient($shell))->get('/')->body;
    foreach ($holes as $name) {
        same(1, substr_count($home, '<i data-hole="' . $name . '"></i>'), 'el hueco «' . $name . '» sale una vez en la portada');
    }
});

test('la cabecera en dos filas: logotipo, buscador grande, teléfono, cuenta y carrito con importe; debajo la barra oscura con su botón', function (): void {
    $app = tallerApp([], ['catalog', 'media'], 'taller', ['store.phone' => '999 000 111']);
    tallerMenu($app);
    tallerShopRoutes($app);
    $body = (new TestClient($app))->get('/')->body;
    $header = tallerSlice($body, '<header class="tl-header af-header', '</header>');
    contains('class="tl-logo af-logo"', $header);
    $top = strpos($header, 'class="tl-top af-container"');
    $bar = strpos($header, 'class="tl-bar');
    check($top !== false && $bar !== false && $top < $bar, 'primero la fila de arriba y después la barra');
    check(strpos($header, 'tl-logo') < strpos($header, 'tl-search') && strpos($header, 'tl-search') < strpos($header, 'tl-phone') && strpos($header, 'tl-phone') < strpos($header, 'tl-actions'), 'logotipo, buscador, teléfono y acciones por ese orden');
    contains('role="search"', $header, 'el buscador está en la cabecera');
    contains('class="tl-search-input af-input"', $header);
    contains('class="tl-search-btn af-btn af-btn-primary"', $header, 'con su botón');
    contains('Busca por producto, marca o referencia…', $header, 'el texto sale traducido');
    contains('href="tel:999000111"', $header);
    contains('999 000 111', $header);
    contains('href="/mi-cuenta"', $header);
    contains('data-af-cart-count', $header, 'el carrito lleva su contador');
    contains('data-tl-cart-total', $header, 'y su importe');
    contains('class="tl-allcats"', $header, 'botón «Todas las categorías»');
    contains('data-af-drawer-open="tl-cats"', $header);
    contains('<a class="tl-menu-link" href="/herramientas" aria-current="page">Herramientas</a>', $header);
    contains('<ul class="tl-sub">', $header, 'los hijos van en un desplegable');
    contains('href="/herramientas/taladros"', $header);
    contains('href="/pintura"', $header);
    notContains('Roto', $body);
    notContains('Grupo vacío', $body);
    // El panel que abre el botón, con el árbol completo.
    $panel = tallerSlice($body, '<aside class="tl-cats-panel af-drawer" id="tl-cats"', '</aside>');
    same(2, substr_count($panel, 'class="tl-cats-group"'), 'un grupo por elemento principal');
    contains('class="tl-cats-head" href="/herramientas"', $panel);
    contains('href="/herramientas/taladros/percutores"', $panel, 'hasta el tercer nivel');
    notContains('id="af-menu"', $body, 'el panel de categorías es también el menú del móvil');
    notContains('href=""', $body);
});

test('la portada: banda con su h1, categorías con icono, ofertas con insignia grande, marcas, ventajas y un solo h1', function (): void {
    $app = tallerApp([], ['catalog', 'media', 'orders']);
    $ids = tallerSeed($app);
    aifecBrand($app, 'Segunda Marca');
    tallerShopRoutes($app);
    $body = (new TestClient($app))->get('/')->body;
    contains('<section class="tl-band"', $body);
    contains('<h1 class="tl-band-title" id="tl-band-title">Todo para tu taller</h1>', $body);
    same(1, substr_count($body, '<h1'));
    contains('/img/workbench.svg', $body, 'el dibujo propio de la banda');
    contains('class="tl-cat-grid"', $body, 'rejilla de categorías');
    contains('class="tl-cat-ico"', $body, 'cada categoría con su icono');
    contains('<svg class="tl-ico"', $body);
    contains('data-section="catalog.sale"', $body);
    contains('class="tl-shelf tl-deals"', $body, 'ofertas de la semana');
    contains('Ofertas de la semana', $body);
    contains('class="tl-deal-off"', $body, 'insignia de porcentaje grande');
    contains('−25', $body, 'el 25 % de las ofertas (59,90 frente a 79,90 es 25 %) o el de otra oferta');
    contains('class="tl-brands af-container"', $body, 'franja de marcas');
    contains('Segunda Marca', $body);
    contains('class="tl-perks af-container"', $body, 'ventajas del servicio');
    contains('Envío en 24 h', $body);
    contains('Recogida en tienda', $body);
    contains('Devolución fácil', $body);
    $cats = strpos($body, 'data-section="catalog.categories"');
    $deals = strpos($body, 'data-section="catalog.sale"');
    $brands = strpos($body, 'class="tl-brands');
    $perks = strpos($body, 'class="tl-perks');
    check($cats < $deals && $deals < $brands && $brands < $perks, 'orden: categorías, ofertas, marcas y ventajas');
    check($ids['cat'] > 0, 'la tienda de pruebas se ha creado');

    // Si una sección trae su h1, el de la banda pasa a ser un párrafo.
    $withH1 = tallerApp();
    $withH1->hooks()->filter('home.sections', static function (array $sections): array {
        $sections[] = ['id' => 'cms.home', 'order' => 10, 'html' => '<h1>Mi portada</h1>'];

        return $sections;
    });
    $other = (new TestClient($withH1))->get('/')->body;
    same(1, substr_count($other, '<h1'));
    contains('<p class="tl-band-title"', $other);
    check(strpos($other, 'data-section="cms.home"') < strpos($other, 'class="tl-perks'), 'el contenido propio de la portada va antes de las ventajas');

    $empty = (new TestClient(tallerApp(['perks_show' => 0, 'brands_show' => 0])))->get('/')->body;
    notContains('class="tl-perks', $empty);
    notContains('class="tl-brands', $empty);
    contains('af-empty', $empty, 'sin secciones, el aviso amable');
});

test('las categorías de la portada: de seis a ocho por fila, icono por nombre, todo escapado', function (): void {
    $app = tallerApp();
    $cats = [];
    foreach (['Taladros', 'Pintura', 'Tornillería', 'Fontanería', 'Jardín', 'Sierras <i>', 'Iluminación', 'Medición'] as $name) {
        $cats[] = ['name' => $name, 'url' => '/categoria/' . strtolower($name), 'image' => null];
    }
    $html = $app->view()->render('catalog::front/home/categories', ['title' => 'Compra por categoría', 'categories' => $cats]);
    same(8, substr_count($html, '<li class="tl-cat">'), 'ocho teselas');
    contains('Sierras &lt;i&gt;', $html);
    contains('id="tl-cats-title"', $html);
    same('drill', taller_icon_key('Taladros eléctricos', 5));
    same('roller', taller_icon_key('Pintura y barnices', 0));
    same('bolt', taller_icon_key('Tornillería', 0));
    same('hammer', taller_icon_key('Cosas varias', 1), 'sin pista, por su posición en la serie');
    same(taller_icon_key('Cosas varias', 1), taller_icon_key('Cosas varias', 9), 'la serie se repite');
    $css = tallerCssPacked();
    check(preg_match('/@media\(min-width:1000px\)\{[^}]*\.tl-cat-grid\{grid-template-columns:repeat\(6,/', $css) === 1, 'seis por fila en escritorio');
    check(preg_match('/@media\(min-width:1200px\)\{[^}]*\.tl-cat-grid\{grid-template-columns:repeat\(8,/', $css) === 1, 'ocho en pantallas anchas');
    check(strlen(taller_svg('no-existe')) > 50 && strpos(taller_svg('no-existe'), '<svg class="tl-ico"') === 0, 'un icono que no existe no rompe nada');
});

test('la tarjeta: compacta con borde, referencia, nombre, tres características, disponibilidad con punto, precio y botón Añadir', function (): void {
    $app = tallerApp([], ['catalog', 'media', 'orders']);
    $ids = tallerSeed($app);
    $web = new TestClient($app);
    $list = $web->get('/categoria/taladros')->body;
    // La primera tarjeta de la rejilla es la de un producto con cuatro datos clave y referencia.
    $cards = [];
    preg_match_all('#<article class="tl-card af-product-card[^"]*"[^>]*>.*?</article>#s', $list, $cards);
    same(5, count($cards[0]), 'cinco productos en la rejilla');
    $found = '';
    foreach ($cards[0] as $card) {
        if (strpos($card, 'Broca de widia') !== false) {
            $found = $card;
        }
    }
    check($found !== '', 'está la tarjeta de la broca');
    contains('class="tl-card-ref"', $found);
    contains('Ref. BRO-8', $found, 'referencia visible');
    contains('Norte Pro', $found, 'marca');
    contains('<h3 class="tl-card-name af-product-card-name">Broca de widia</h3>', $found);
    same(3, substr_count($found, '<li><span class="tl-k">'), 'solo tres de las cuatro características');
    contains('<span class="tl-k">Diámetro</span> <span class="tl-v">8 mm</span>', $found);
    notContains('Vástago', $found, 'la cuarta no sale');
    contains('class="tl-stock af-availability is-ok"', $found, 'disponibilidad con punto de color');
    contains('class="tl-price af-price af-price-md"', $found, 'precio');
    contains('<form class="af-co tl-add" method="post" action="/carrito/add"', $found, 'botón de añadir siempre visible, con su formulario (y sus unidades)');
    contains('name="qty"', $found, 'con unidades');
    contains('data-af-cart-add', $found);
    contains('name="product_id" value="' . $ids['broca'] . '"', $found);
    contains('data-af-add', $found);
    contains('Añadir', $found);
    notContains('<a class="tl-card-link af-product-card-link" href="/producto/broca-widia"><form', $found, 'el formulario no va dentro del enlace');
    check(strpos($found, '</a>') < strpos($found, '<form'), 'el formulario va después del enlace de la tarjeta');

    // Agotado: sin formulario. Con variantes: se eligen en la propia tarjeta. Con oferta: precio anterior tachado.
    $soldout = '';
    $variants = '';
    $sale = '';
    foreach ($cards[0] as $card) {
        if (strpos($card, 'Maletín de brocas') !== false) {
            $soldout = $card;
        }
        if (strpos($card, 'Atornillador 12 V') !== false) {
            $sale = $card;
        }
        if (strpos($card, 'Taladro &lt;b&gt;&amp;') !== false) {
            $variants = $card;
        }
    }
    contains('is-out', $soldout);
    contains('class="tl-stock af-availability is-out"', $soldout);
    notContains('<form', $soldout, 'un producto agotado no se puede añadir');
    contains('aria-disabled="true"', $soldout);
    contains('data-af-variants', $variants, 'con variantes se elige en la propia tarjeta');
    contains('<form class="af-co tl-add"', $variants);
    contains('data-af-option="', $variants, 'con sus opciones');
    contains('formaction="/catalogo/elegir-variante"', $variants, 'y sin JavaScript resuelve el servidor');
    contains('af-price-old', $sale);
    contains('−25', $sale, 'insignia con el porcentaje');
    contains('Taladro &lt;b&gt;&amp;', $variants, 'el nombre sale escapado');
    notContains('Taladro <b>', $list);
    check(strlen($found) > 200, 'tarjeta con contenido');
});

test('la tarjeta: opciones de características y de marca, tarjeta sin datos extra y «deal» con su insignia', function (): void {
    $two = tallerApp(['card_specs' => '2'], ['catalog', 'media', 'orders']);
    tallerSeed($two);
    $html = (new TestClient($two))->get('/categoria/taladros')->body;
    $broca = [];
    preg_match('#<article class="tl-card af-product-card"[^>]*>(?:(?!</article>).)*Broca de widia.*?</article>#s', $html, $broca);
    check(isset($broca[0]), 'tarjeta de la broca');
    same(2, substr_count($broca[0], '<li><span class="tl-k">'), 'con la opción a dos, dos características');

    $none = tallerApp(['card_specs' => '0'], ['catalog', 'media', 'orders']);
    tallerSeed($none);
    notContains('tl-card-specs', (new TestClient($none))->get('/categoria/taladros')->body, 'con la opción a cero no hay lista');

    $nobrand = tallerApp(['show_brand_in_card' => 0], ['catalog', 'media', 'orders']);
    tallerSeed($nobrand);
    notContains('tl-card-brand', (new TestClient($nobrand))->get('/categoria/taladros')->body);

    // Una tarjeta a pelo, sin id ni datos: no revienta y no inventa nada.
    $app = tallerApp();
    $bare = $app->view()->render('partials/product-card', ['product' => ['name' => 'Suelta <i>', 'url' => '/p/1', 'image' => null, 'price' => ['amount' => 1000, 'currency' => 'EUR'], 'badges' => []]]);
    contains('Suelta &lt;i&gt;', $bare);
    notContains('tl-card-ref', $bare);
    notContains('tl-card-specs', $bare);
    notContains('<form', $bare, 'sin identificador no hay formulario');
    // «deal».
    $deal = $app->view()->render('partials/product-card', ['deal' => true, 'product' => ['id' => 7, 'name' => 'Oferta', 'url' => '/p/7', 'image' => null, 'price' => ['amount' => 750, 'compare' => 1000, 'currency' => 'EUR'], 'badges' => ['sale']]]);
    contains('class="tl-deal-off"', $deal);
    contains('−25', $deal);
    contains('tl-card-deal', $deal);
    $plain = $app->view()->render('partials/product-card', ['product' => ['id' => 7, 'name' => 'Oferta', 'url' => '/p/7', 'image' => null, 'price' => ['amount' => 750, 'compare' => 1000, 'currency' => 'EUR'], 'badges' => ['sale']]]);
    notContains('tl-deal-off', $plain, 'sin «deal» no hay insignia grande');
});

test('el listado: barra lateral de filtros plegables con contador, orden, conmutador rejilla / lista y rejilla de cuatro', function (): void {
    $app = tallerApp([], ['catalog', 'media', 'orders']);
    tallerSeed($app);
    $web = new TestClient($app);
    $r = $web->get('/categoria/taladros');
    same(200, $r->status);
    $body = $r->body;
    same(1, substr_count($body, '<h1'));
    contains('<h1 class="tl-list-title af-title">Taladros</h1>', $body);
    contains('tl-listing af-listing', $body);
    contains('id="af-filters"', $body, 'el panel de filtros del móvil existe');
    contains('data-af-drawer-open="af-filters"', $body, 'y la barra inferior tiene el botón');
    contains('id="af-filters-form"', $body);
    $filters = tallerSlice($body, '<aside class="tl-filters af-listing-filters af-drawer"', '</aside>');
    $details = substr_count($filters, '<details class="tl-facet af-filter"');
    check($details >= 3, 'una faceta plegable por característica más precio y existencias: ' . $details);
    contains('<summary class="tl-facet-head">', $filters);
    contains('class="tl-facet-count"', $filters, 'contador de valores');
    contains('class="tl-opt-n"', $filters, 'contador por valor');
    contains('name="f[potencia][]"', $filters);
    contains('name="min"', $filters);
    contains('name="max"', $filters);
    contains('name="stock"', $filters);
    contains('name="sale"', $filters);
    contains('type="submit"', $filters);
    contains('name="sort" form="af-filters-form" data-af-autosubmit', $body, 'el orden se envía con el formulario de filtros');
    contains('class="tl-toolbar"', $body);
    contains('data-tl-view="grid"', $body, 'por defecto, rejilla');
    contains('data-tl-view-set="grid" aria-pressed="true"', $body);
    contains('data-tl-view-set="list" aria-pressed="false"', $body);
    contains('<div class="tl-grid af-grid tl-cols-4">', $body);
    notContains('class="tl-chips"', $body, 'sin subcategorías no hay fila de enlaces');

    $css = tallerCssPacked();
    check(preg_match('/@media\(min-width:900px\)\{[^@]*\.af-listing\{grid-template-columns:16\.5rem/', $css) === 1, 'en escritorio hay columna lateral de 16,5 rem');
    contains('.tl-js.tl-view{display:inline-flex}', $css, 'el conmutador solo sale con JavaScript');
    contains('.tl-results[data-tl-view="list"].tl-card-specs{', $css, 'la lista pinta las características en columnas');
    check(preg_match('/\.tl-results\[data-tl-view="list"\]\.tl-card-specs\{[^}]*grid-template-columns:repeat\(3,/', $css) === 1, 'tres columnas de características en la lista');
});

test('el listado: facetas largas con «Ver más», filas anchas con la opción de lista y tres columnas si se pide', function (): void {
    $app = tallerApp(['listing_view' => 'list', 'listing_cols' => '3'], ['catalog', 'media', 'orders']);
    $cat = aifecCategory($app, 'Brocas');
    $values = [];
    for ($i = 1; $i <= 8; ++$i) {
        $values['d' . $i] = $i . ' mm';
    }
    aifecAttribute($app, 'diametro', 'Diámetro', $values);
    for ($i = 1; $i <= 8; ++$i) {
        aifecProduct($app, ['name' => 'Broca ' . $i, 'slug' => 'broca-' . $i, 'categories' => [$cat], 'attributes' => ['diametro' => 'd' . $i]]);
    }
    $body = (new TestClient($app))->get('/categoria/brocas')->body;
    contains('data-tl-view="list"', $body, 'la vista de lista es la de serie con la opción');
    contains('data-tl-view-set="list" aria-pressed="true"', $body);
    contains('<div class="tl-grid af-grid tl-cols-3">', $body);
    $filters = tallerSlice($body, '<aside class="tl-filters', '</aside>');
    contains('<details class="tl-more">', $filters, 'las facetas largas esconden el resto');
    contains('Ver 2 más', $filters);
    same(8, substr_count($filters, 'name="f[diametro][]"'), 'los ocho valores siguen estando en el formulario');
    contains('<details class="tl-facet af-filter" open>', $filters);

    // Con un valor marcado, la faceta sale abierta y con su contador de marcados.
    $picked = (new TestClient($app))->get('/categoria/brocas?f[diametro][]=d3')->body;
    contains('class="tl-facet-picked">1</span>', $picked);
    contains('<input type="checkbox" name="f[diametro][]" value="d3" checked>', $picked);
    contains('Quitar los filtros', $picked);
});

test('un listado vacío o sin filtros marcados sigue funcionando y la página de error va con el envoltorio de taller', function (): void {
    $app = tallerApp();
    aifecCategory($app, 'Vacía');
    $web = new TestClient($app);
    $r = $web->get('/categoria/vacia');
    same(200, $r->status);
    contains('tl-empty af-empty', $r->body);
    contains('No se ha encontrado ningún producto', $r->body);
    $missing = $web->get('/esto-no-existe');
    same(404, $missing->status);
    contains('tl-header af-header', $missing->body);
    contains('class="af-empty af-error"', $missing->body);
    same(1, substr_count($missing->body, '<h1'));
});

test('contrato de móvil: barra inferior con buscar, carrito con contador y cuenta; con filtros solo en el listado; cabecera pegajosa', function (): void {
    $app = tallerApp();
    tallerSeed($app);
    tallerShopRoutes($app);
    tallerMenu($app);
    $web = new TestClient($app);
    $home = $web->get('/')->body;
    contains('<nav class="tl-mbar af-mobile-bar af-show-sm"', $home);
    contains('data-af-drawer-open="af-search"', $home);
    contains('data-af-drawer-open="af-cart"', $home);
    contains('data-af-cart-count', $home);
    contains('af-mobile-bar-label">Cuenta', $home);
    contains('af-has-bar', $home);
    notContains('data-af-drawer-open="af-filters"', $home, 'sin filtros funcionales no hay botón de filtros');
    contains('id="af-cart"', $home);
    contains('data-af-cart-lines', $home);
    contains('id="af-search"', $home, 'la capa de búsqueda del móvil existe');
    contains('class="tl-burger af-icon-btn af-show-sm" data-af-drawer-open="tl-cats"', $home, 'el menú del móvil abre el panel de categorías');
    $list = $web->get('/categoria/taladros')->body;
    contains('data-af-drawer-open="af-filters" aria-controls="af-filters"', $list);

    $css = tallerCssPacked();
    check(preg_match('/\.af-header\{position:sticky/', $css) === 1, 'la cabecera es pegajosa en móvil');
    check(preg_match('/@media\(min-width:900px\)\{[^@]*\.af-header\.tl-header\{position:relative\}/', $css) === 1, 'y solo en escritorio deja de serlo');
    check(preg_match('/\.af-mobile-bar\{[^}]*position:fixed[^}]*bottom:0/', $css) === 1, 'la barra inferior es fija abajo');
    check(preg_match('/\.af-has-bar\{padding-bottom:calc\(var\(--bar-h\)\+env\(safe-area-inset-bottom\)\)/', $css) === 1, 'el contenido reserva el alto de la barra');
    check(preg_match('/\.af-mobile-bar-item\{[^}]*min-width:44px[^}]*min-height:var\(--bar-h\)/', $css) === 1, 'áreas táctiles de 44 px');
    check(preg_match('/--bar-h:3\.[0-9]+rem/', $css) === 1, 'la barra mide al menos 44 px');
    contains('.af-show-sm{display:none!important}', $css);
    contains('.af-hide-sm{display:none!important}', $css);
});

test('las opciones de estructura del tema llegan al HTML: teléfono, importe, barra, vista, columnas, miniaturas y portada', function (): void {
    $phone = ['store.phone' => '999 000 111'];
    $on = (new TestClient(tallerApp([], ['catalog', 'media'], 'taller', $phone)))->get('/')->body;
    contains('class="tl-phone"', $on);
    $off = (new TestClient(tallerApp(['header_phone' => 0], ['catalog', 'media'], 'taller', $phone)))->get('/')->body;
    notContains('class="tl-phone"', $off);
    notContains('class="tl-phone"', (new TestClient(tallerApp()))->get('/')->body, 'sin teléfono configurado no hay bloque');

    $noAmount = tallerApp(['cart_amount' => 0], ['catalog', 'media', 'orders']);
    notContains('data-tl-cart-total', (new TestClient($noAmount))->get('/')->body);

    $yellow = tallerApp(['nav_bar' => 'yellow']);
    tallerMenu($yellow);
    contains('class="tl-bar tl-bar-yellow af-hide-sm"', (new TestClient($yellow))->get('/')->body);
    $dark = tallerApp();
    tallerMenu($dark);
    $darkBody = (new TestClient($dark))->get('/')->body;
    contains('class="tl-bar af-hide-sm"', $darkBody);
    notContains('tl-bar-yellow', $darkBody);
    $noMenu = (new TestClient(tallerApp()))->get('/')->body;
    notContains('class="tl-bar', $noMenu, 'sin menú no hay barra');

    $below = tallerApp(['gallery_thumbs' => 'below']);
    tallerSeed($below);
    contains('tl-pdp af-product tl-pdp-below', (new TestClient($below))->get('/producto/taladro-percutor')->body);
    $side = tallerApp();
    tallerSeed($side);
    notContains('tl-pdp-below', (new TestClient($side))->get('/producto/taladro-percutor')->body);

    $home = (new TestClient(tallerApp(['home_title' => 'Ferretería & más', 'home_text' => 'Todo <a> mano'])))->get('/')->body;
    contains('Ferretería &amp; más', $home);
    contains('Todo &lt;a&gt; mano', $home);

    $tips = tallerApp(['tips_show' => 0]);
    notContains('tl-tips', (new TestClient($tips))->get('/')->body);

    // El acento y el radio llegan como variable del <head>.
    $accent = (new TestClient(tallerApp(['accent' => '#112233'])))->get('/')->body;
    contains('--accent:#112233', $accent);
    contains('--radius:4px', $accent);
});

test('las ayudas del tema: enlaces seguros, teléfono, menú limpio y marcas o consejos que no existen', function (): void {
    $app = tallerApp();
    check(function_exists('taller_link') && function_exists('taller_tel') && function_exists('taller_clean_menu'), 'functions.php define las ayudas');
    same('', taller_link('javascript:alert(1)'));
    same('', taller_link('//otro.test/x'));
    same('', taller_link('data:text/html,x'));
    same('', taller_link(''));
    same('#ofertas', taller_link('#ofertas'));
    same('https://tienda.test/x', taller_link('https://tienda.test/x'));
    same('/ofertas', taller_link('/ofertas'));
    same('tel:+34999000111', taller_tel('+34 999 000 111'));
    same('', taller_tel('sin números'));
    same([], taller_clean_menu([['label' => '', 'url' => '/a'], ['label' => 'Sin url', 'url' => ''], 'no es un array']));
    $clean = taller_clean_menu([['label' => 'A', 'url' => '/a', 'children' => [['label' => 'B', 'url' => ''], ['label' => 'C', 'url' => '/c']]]]);
    same(1, count($clean));
    same(1, count($clean[0]['children']), 'los hijos rotos también se quitan');
    same([], taller_home_brands(8), 'sin marcas, nada');
    same([], taller_home_tips(3), 'sin blog, nada');
    check($app instanceof App, 'la tienda sigue arrancando');
    $info = taller_card_info(['id' => 0, 'name' => 'x']);
    same(['ref' => '', 'specs' => []], $info);
    $given = taller_card_info(['id' => 0, 'sku' => ' R-1 ', 'facts' => [['label' => 'A', 'value' => '1'], ['label' => 'B', 'value' => ''], ['label' => 'C', 'value' => '3'], ['label' => 'D', 'value' => '4'], ['label' => 'E', 'value' => '5']]]);
    same('R-1', $given['ref']);
    same(3, count($given['specs']), 'como mucho tres, sin las vacías');
});

test('las marcas y los consejos de la portada salen cuando existen y sus textos van escapados', function (): void {
    $app = tallerApp();
    aifecBrand($app, 'Marca <b>&');
    $html = $app->view()->render('partials/brands', ['brands' => taller_home_brands(8)]);
    contains('Marca &lt;b&gt;&amp;', $html);
    contains('class="tl-brand-name"', $html, 'sin logotipo, el nombre');
    same('', trim($app->view()->render('partials/brands', ['brands' => []])), 'sin marcas no se pinta nada');
    $tips = $app->view()->render('partials/tips', ['tips' => [['title' => 'Cómo colgar un <b>cuadro</b>', 'url' => '/blog/cuadro', 'excerpt' => 'Con "taco" & tornillo', 'image' => null]]]);
    contains('Cómo colgar un &lt;b&gt;cuadro&lt;/b&gt;', $tips);
    contains('Con &quot;taco&quot; &amp; tornillo', $tips);
    contains('href="/blog/cuadro"', $tips);
    same('', trim($app->view()->render('partials/tips', ['tips' => []])));
});

test('estructura distinta de la del tema base y de atelier: cabecera, tarjeta, listado y ficha se parecen menos de un 82 %', function (): void {
    $taller = tallerSurfaces('taller');
    foreach (['base', 'atelier'] as $other) {
        $surfaces = tallerSurfaces($other);
        $worst = [];
        foreach (['header', 'card', 'listing', 'product'] as $surface) {
            $byClass = tallerSimilarity(tallerSequence($surfaces[$surface], true), tallerSequence($taller[$surface], true));
            $byTag = tallerSimilarity(tallerSequence($surfaces[$surface], false), tallerSequence($taller[$surface], false));
            $worst[$surface] = round($byClass, 3) . ' / ' . round($byTag, 3);
            check($byClass < 0.82, $surface . ' contra ' . $other . ': la secuencia etiqueta.clase se parece un ' . round($byClass * 100) . ' %');
            check($byTag < 0.82, $surface . ' contra ' . $other . ': la secuencia de etiquetas se parece un ' . round($byTag * 100) . ' %');
            check(strlen($taller[$surface]) > 200, $surface . ' tiene contenido para comparar');
        }
        check(count($worst) === 4, 'las cuatro superficies medidas contra ' . $other . ': ' . json_encode($worst));
    }
});

test('un nombre de producto con <b>&: escapado en tarjeta, ficha, migas y datos para compartir', function (): void {
    $app = tallerApp();
    tallerSeed($app);
    $web = new TestClient($app);
    $product = $web->get('/producto/taladro-percutor')->body;
    contains('Taladro &lt;b&gt;&amp;', $product);
    notContains('Taladro <b>', $product);
    $list = $web->get('/categoria/taladros')->body;
    contains('Taladro &lt;b&gt;&amp;', $list);
    notContains('Taladro <b>', $list);
    $sample = $web->get('/tema/muestra')->body;
    same(1, substr_count($sample, '<h1'));
});

test('el carrito vacío y la muestra del tema se ven con la hoja de taller y sin errores', function (): void {
    $app = tallerApp([], ['catalog', 'media', 'orders']);
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
    contains('class="tl-add"', $sample->body, 'las tarjetas de la muestra también se pueden añadir');
    $imgs = [];
    preg_match_all('/<img\b[^>]*>/', $sample->body, $imgs);
    foreach ($imgs[0] as $img) {
        check(strpos($img, ' width="') !== false && strpos($img, ' height="') !== false, 'toda imagen lleva ancho y alto: ' . substr($img, 0, 80));
    }
});

test('peso: theme.css pesa como mucho 45 000 bytes y el guion propio como mucho 8 000', function (): void {
    $css = tallerDir() . '/assets/css/theme.css';
    $js = tallerDir() . '/assets/js/theme.js';
    check(is_file($css) && is_file($js), 'existen la hoja y el guion');
    check(filesize($css) <= 45000, 'theme.css pesa ' . filesize($css));
    check(filesize($js) <= 8000, 'theme.js pesa ' . filesize($js));
});

test('ninguna dirección externa en las plantillas ni en los recursos (salvo el espacio de nombres de los SVG) y ningún @import', function (): void {
    $files = array_merge(tallerFiles('templates'), tallerFiles('assets'));
    check(count($files) >= 15, 'se han encontrado las plantillas y los recursos');
    foreach ($files as $file) {
        $text = (string) file_get_contents($file);
        same(0, preg_match('#https?://(?!www\.w3\.org)#i', $text), basename($file) . ' lleva una dirección externa');
        notContains('@import', $text, basename($file));
        notContains('//cdn', $text, basename($file));
    }
    $xml = @simplexml_load_string((string) file_get_contents(tallerDir() . '/assets/img/workbench.svg'));
    check($xml !== false, 'workbench.svg es un SVG bien formado');
});

test('theme.css: ningún color fuera de :root, sin fuentes ni iconos de fuera y con los medios de accesibilidad', function (): void {
    $css = (string) file_get_contents(tallerDir() . '/assets/css/theme.css');
    $rest = tallerCssWithoutRoot($css);
    $found = [];
    preg_match_all('/#[0-9a-fA-F]{3,8}\b/', $rest, $found);
    same([], $found[0], 'colores fijos fuera de :root');
    check(preg_match('/\b(?:rgb|rgba|hsl|hsla)\(/', $rest) === 0, 'colores funcionales fuera de :root');
    notContains('@font-face', $css);
    notContains('url(', $css);
    foreach (['prefers-reduced-motion', ':focus-visible', 'env(safe-area-inset-bottom)', 'min-height:44px', 'min-width:44px', 'flex-wrap:wrap', 'overflow-x:auto', 'line-through', 'object-fit:contain', 'position:sticky'] as $needle) {
        contains($needle, $css, 'theme.css no contiene «' . $needle . '»');
    }
    notContains('fonts.googleapis', $css);
    // Identidad: insignias cuadradas y radio pequeño por variable.
    check(preg_match('/\.af-badge\{[^}]*border-radius:0/', tallerCssPacked()) === 1, 'insignias cuadradas');
    check(preg_match('/--radius:4px/', $css) === 1, 'radio pequeño de fábrica');
});

test('el contraste de los pares de colores de fábrica cumple AA (4,5 a 1) también en precio anterior, insignias y texto sobre el acento', function (): void {
    $css = (string) file_get_contents(tallerDir() . '/assets/css/theme.css');
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
        ['--ink', '--bg'], ['--ink', '--surface'], ['--muted', '--bg'], ['--muted', '--surface'], ['--muted', '--surface-2'],
        ['--accent-ink', '--accent'], ['--on-dark', '--sale'], ['--sale', '--surface'], ['--on-dark', '--dark'], ['--accent', '--dark'],
        ['--dark-muted', '--dark'], ['--link', '--surface'], ['--link', '--bg'], ['--ok', '--surface'], ['--warn', '--surface'], ['--bad', '--surface'],
        ['--on-dark', '--muted'], ['--ink', '--accent-soft'], ['--sale', '--accent-soft'],
    ];
    foreach ($pairs as $pair) {
        check(isset($vars[$pair[0]], $vars[$pair[1]]), 'faltan las variables ' . implode(' y ', $pair));
        $r = $ratio($vars[$pair[0]], $vars[$pair[1]]);
        check($r >= 4.5, $pair[0] . ' sobre ' . $pair[1] . ' da un contraste de ' . round($r, 2));
    }
});

test('theme.css da estilo a todas las clases del contrato de estilos y a las propias de taller', function (): void {
    $css = (string) file_get_contents(tallerDir() . '/assets/css/theme.css');
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
    // Y a las clases que pintan las plantillas propias de taller.
    $own = [];
    foreach (tallerFiles('templates') as $file) {
        $text = (string) file_get_contents($file);
        $m = [];
        preg_match_all('/class="([^"]*)"/', $text, $m);
        foreach ($m[1] as $attr) {
            $attr = (string) preg_replace('/<\?=.*?\?>/s', ' ', $attr);
            foreach (preg_split('/\s+/', trim($attr)) ?: [] as $name) {
                if (strpos($name, 'tl-') === 0) {
                    $own[$name] = true;
                }
            }
        }
    }
    check(count($own) > 60, 'se han encontrado las clases propias: ' . count($own));
    foreach (array_keys($own) as $name) {
        // Los prefijos que la plantilla completa con un número («tl-cols-4») se miran por la clase entera.
        if (substr($name, -1) === '-') {
            continue;
        }
        check(preg_match('/\.' . preg_quote($name, '/') . '(?![A-Za-z0-9_-])/', $css) === 1, 'theme.css no da estilo a .' . $name);
    }
    foreach (['tl-cols-3', 'tl-cols-4', 'tl-cols-5'] as $name) {
        check(preg_match('/\.' . $name . '(?![A-Za-z0-9_-])/', $css) === 1, 'theme.css no da estilo a .' . $name);
    }
});

test('theme.json es JSON válido, hijo de base con su hoja propia, y cada opción con var existe en :root', function (): void {
    $json = json_decode((string) file_get_contents(tallerDir() . '/theme.json'), true);
    check(is_array($json), 'JSON válido');
    same('Taller', $json['name']);
    same('base', $json['parent']);
    same('replace', $json['css'], 'la hoja sustituye a la del padre');
    check(!isset($json['js']) || $json['js'] !== 'replace', 'el guion del padre se sigue cargando');
    $css = (string) file_get_contents(tallerDir() . '/assets/css/theme.css');
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
    foreach (['header_phone', 'cart_amount', 'nav_bar', 'listing_cols', 'listing_view', 'card_specs', 'gallery_thumbs'] as $key) {
        check(in_array($key, $plain, true), 'falta la opción de estructura ' . $key);
    }
    foreach ($json['settings']['typography']['fields']['font_head']['options'] as $stack => $label) {
        check(isset($json['font_stacks'][$stack]) || in_array($stack, ['system', 'serif', 'rounded', 'mono'], true), 'la tipografía «' . $stack . '» no tiene pila');
    }
    check(preg_match('/https?:/', json_encode($json['font_stacks']) ?: '') === 0, 'las pilas son del sistema');
    same(4.0, (float) $json['settings']['layout']['fields']['radius']['default'], 'radio pequeño');
    same('#ffc20e', $json['settings']['colors']['fields']['accent']['default'], 'amarillo de seguridad');
    same('#1d2125', $json['settings']['colors']['fields']['dark']['default'], 'negro carbón');
    check(is_file(tallerDir() . '/preview.png'), 'preview.png existe');
    $size = getimagesize(tallerDir() . '/preview.png');
    check(is_array($size) && $size[0] === 1200 && $size[1] === 900, 'preview.png mide 1200 × 900');
});

test('todas las plantillas y ficheros PHP del tema empiezan por la cabecera del proyecto, en UTF-8 sin BOM, con LF y sin etiqueta de cierre', function (): void {
    $names = ['home', 'partials/header', 'partials/drawers', 'partials/mobile-bar', 'partials/product-card', 'partials/price', 'partials/cats', 'partials/brands',
        'partials/perks', 'partials/tips', 'modules/catalog/front/listing', 'modules/catalog/front/product', 'modules/catalog/front/partials/filters',
        'modules/catalog/front/home/categories', 'modules/catalog/front/home/products'];
    foreach ($names as $name) {
        check(is_file(tallerDir() . '/templates/' . $name . '.php'), 'falta templates/' . $name . '.php');
    }
    foreach (['functions.php', 'lang/es.php', 'assets/css/theme.css', 'assets/js/theme.js', 'assets/img/workbench.svg', 'theme.json', 'preview.png'] as $extra) {
        check(is_file(tallerDir() . '/' . $extra), 'falta ' . $extra);
    }
    $php = array_filter(tallerFiles(), static function (string $file): bool {
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
    }
});

test('theme.js propio: solo comportamiento mínimo, sin ventanas del navegador ni peticiones directas y sin cadenas visibles', function (): void {
    $js = (string) file_get_contents(tallerDir() . '/assets/js/theme.js');
    foreach (['alert(', 'confirm(', 'prompt(', 'eval(', 'document.write', 'innerHTML', 'fetch(', 'XMLHttpRequest', 'http://', 'https://'] as $banned) {
        notContains($banned, $js, 'theme.js no puede usar ' . $banned);
    }
    foreach (['data-tl-view-set', 'data-tl-cart-total', 'data-tl-tax', 'localStorage', 'Aifec.get'] as $needle) {
        contains($needle, $js, 'theme.js no contiene «' . $needle . '»');
    }
    notContains('"use strict"', $js);
    contains("'use strict'", $js);
});

test('el catálogo castellano del tema tiene todas las cadenas, ninguna vacía y con tildes y eñes', function (): void {
    $lang = require tallerDir() . '/lang/es.php';
    check(is_array($lang) && count($lang) > 100, 'el catálogo está cargado');
    foreach ($lang as $key => $value) {
        check(is_string($value) && trim($value) !== '', 'la cadena «' . $key . '» no tiene traducción');
    }
    same('Añadir al carrito', $lang['Add to cart']);
    same('Todas las categorías', $lang['All categories']);
    same('Ofertas de la semana', $lang['Weekly deals']);
    same('Características técnicas', $lang['Specifications']);
    // Con el idioma castellano, la ficha, el listado y la portada salen en castellano.
    $app = tallerApp([], ['catalog', 'media', 'orders']);
    tallerSeed($app);
    $web = new TestClient($app);
    $body = $web->get('/producto/taladro-percutor')->body;
    contains('Añadir al carrito', $body);
    contains('Características técnicas', $body);
    contains('También te puede interesar', $body);
    notContains('Add to cart', $body);
    $list = $web->get('/categoria/taladros')->body;
    contains('Ordenar por', $list);
    contains('Disponibilidad', $list);
    notContains('Sort by', $list);
});
