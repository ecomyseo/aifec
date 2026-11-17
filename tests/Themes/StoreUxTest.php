<?php
/**
 * AIFEC — pruebas de P100, «la tienda que la gente espera»: el pie con sus cuatro bloques en los seis temas, la insignia dentro
 * de la galería (mirando el árbol, no una clase suelta), las líneas de confianza junto al botón con los datos de envíos,
 * devoluciones y pagos, «También te puede interesar», la portada del tema base, el menú con una sola categoría principal,
 * el carrito (seguir comprando, lo que falta para el envío gratis, vacío con categorías) y los presupuestos de peso.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Front\StoreInfo;
use Aifec\Kernel\App;
use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;
use Aifec\Modules\Catalog\Front\HomeSections;
use Aifec\Modules\Catalog\Front\Related;
use Aifec\Modules\Shipping\RateEditor;

require_once dirname(__DIR__, 2) . '/modules/orders/tests/helpers.php';
require_once dirname(__DIR__, 2) . '/modules/cms/tests/helpers.php';
require_once dirname(__DIR__) . '/Perf/helpers.php';

/** Los seis temas del proyecto. */
function p100Themes(): array
{
    return ['aifec', 'atelier', 'base', 'despensa', 'gmartos', 'taller'];
}

/**
 * Una tienda completa con ese tema: catálogo, pedidos, contenidos, devoluciones (si se pide), contrarreembolso y pago de
 * prueba encendidos, un transportista con envío gratis a partir de 60 € y los datos de «Ajustes → General».
 *
 * @param array<string,mixed> $settings ajustes que pisan los de la prueba
 */
function p100App(string $theme, array $settings = [], bool $returns = true): App
{
    $modules = ['catalog', 'media', 'orders', 'cms', 'payments-cod', 'payments-simulated'];
    if ($returns) {
        $modules[] = 'returns';
    }
    // Los módulos que no vienen encendidos de serie (pagos, devoluciones) se encienden como lo haría el panel.
    $active = array_merge($modules, ['customers', 'tax', 'shipping', 'coupons', 'mail', 'jobs']);
    $settings += [
        'modules.active' => array_values(array_unique($active)),
        'theme.active' => $theme,
        'store.name' => 'Tienda <script>&',
        'store.email' => 'tienda@ejemplo.test',
        'store.phone' => '999 000 001',
        'store.address' => 'Calle Mayor 1',
        'payments.cod.countries' => ['ES'],
        'tax.prices_include_tax' => true,
        'tax.display_with_tax' => true,
    ];
    $app = testApp(['modules' => $modules, 'settings' => $settings]);
    $app->boot();

    return $app;
}

/** Un transportista a domicilio en la península: 4,95 € (o lo que se diga), gratis desde 60 €, de 2 a 3 días. Devuelve la tarifa. */
function p100Carrier(App $app, int $price = 495, ?int $freeFrom = 6000): int
{
    $carrier = shippingAddCarrier($app, 'std', ['name' => 'Estándar', 'free_from' => $freeFrom, 'min_days' => 2, 'max_days' => 3]);

    return shippingAddRate($app, $carrier, shippingZoneId($app, 'Peninsula'), 'weight', 0, 0, $price);
}

/** Menús «footer» y «legal» como los pondría el módulo de contenidos. */
function p100Menus(App $app): void
{
    $app->hooks()->filter('menu.items', static function (array $items, string $location): array {
        if ($location === 'footer') {
            return [['label' => 'Envíos & devoluciones', 'url' => '/envios-y-devoluciones'], ['label' => 'Contacto', 'url' => '/contacto']];
        }
        if ($location === 'legal') {
            return [['label' => 'Aviso legal', 'url' => '/aviso-legal'], ['label' => 'Privacidad', 'url' => '/privacidad']];
        }

        return $items;
    });
}

/**
 * Una categoría con seis productos (el de la ficha, cuatro que se pueden comprar, uno agotado que es el más reciente) y otra
 * categoría con uno más que no tiene que salir en los relacionados.
 *
 * @return array<string,int>
 */
function p100Catalog(App $app): array
{
    $ids = [];
    $ids['cat'] = aifecCategory($app, 'Zapatos <script>&');
    $ids['other'] = aifecCategory($app, 'Bolsos');
    $ids['self'] = aifecProduct($app, ['name' => 'Sandalia Leyre', 'slug' => 'sandalia', 'price' => 6499, 'images' => 2, 'categories' => [$ids['cat']], 'created_at' => '2026-11-10 10:00:00']);
    foreach (['bota' => '2026-11-01', 'mocasin' => '2026-11-02', 'zueco' => '2026-11-03', 'botin' => '2026-11-04'] as $slug => $day) {
        $ids[$slug] = aifecProduct($app, ['name' => ucfirst($slug), 'slug' => $slug, 'price' => 4000, 'images' => 1, 'categories' => [$ids['cat']], 'created_at' => $day . ' 10:00:00']);
    }
    $ids['agotado'] = aifecProduct($app, ['name' => 'Agotada', 'slug' => 'agotada', 'price' => 4000, 'stock' => 0, 'images' => 1, 'categories' => [$ids['cat']], 'created_at' => '2026-11-12 10:00:00']);
    $ids['bolso'] = aifecProduct($app, ['name' => 'Bolso', 'slug' => 'bolso', 'price' => 3000, 'images' => 1, 'categories' => [$ids['other']]]);

    return $ids;
}

/** El trozo de HTML entre una marca y la siguiente (o el final). */
function p100Slice(string $html, string $from, string $to): string
{
    $start = strpos($html, $from);
    check($start !== false, 'no está «' . $from . '»');
    $end = strpos($html, $to, (int) $start + strlen($from));

    return $end === false ? substr($html, (int) $start) : substr($html, (int) $start, (int) $end - (int) $start);
}

/** El pie de una página. */
function p100Footer(string $html): string
{
    return p100Slice($html, '<footer class="af-footer', '</footer>');
}

/**
 * ¿Está el primer elemento con esa clase DENTRO del contenedor de la galería (data-af-gallery) y fuera de la compra?
 * Se mira el árbol del documento, no el orden de las cadenas.
 *
 * @return array{found:bool,in_gallery:bool,in_buy:bool}
 */
function p100Ancestry(string $html, string $class): array
{
    $doc = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query('//*[contains(concat(" ", normalize-space(@class), " "), " ' . $class . ' ")]');
    if ($nodes === false || $nodes->length === 0) {
        return ['found' => false, 'in_gallery' => false, 'in_buy' => false];
    }
    $inGallery = false;
    $inBuy = false;
    for ($node = $nodes->item(0)->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
        if ($node->hasAttribute('data-af-gallery')) {
            $inGallery = true;
        }
        if (strpos(' ' . $node->getAttribute('class') . ' ', ' af-product-buy ') !== false) {
            $inBuy = true;
        }
    }

    return ['found' => true, 'in_gallery' => $inGallery, 'in_buy' => $inBuy];
}

/** Lo que la caché de página serviría para esa dirección (null si no la tiene). */
function p100Cached(App $app, string $uri): ?\Aifec\Kernel\Response
{
    $request = Request::create('GET', $uri);
    $request->host = 'tienda.test';
    $request->secure = true;

    return PageCache::serve($app->site(), $request, $app->clock());
}

/** Importe con el formato de la tienda. */
function p100Money(App $app, int $cents): string
{
    return $app->i18n()->money($cents, 'EUR');
}

// ------------------------------------------------------------------ A · el pie, en los seis temas

foreach (p100Themes() as $p100Theme) {
    test('tema ' . $p100Theme . ': el pie lleva categorías, los dos menús, correo, teléfono y formas de pago, todo escapado', function () use ($p100Theme): void {
        $app = p100App($p100Theme);
        p100Catalog($app);
        p100Menus($app);
        $payments = StoreInfo::payments($app);
        same(2, count($payments), 'contrarreembolso y pago de prueba están encendidos');
        $footer = p100Footer((new TestClient($app))->get('/')->body);
        contains('Zapatos &lt;script&gt;&amp;', $footer, 'la categoría principal, escapada');
        contains('href="/categoria/bolsos"', $footer, 'las categorías enlazan');
        notContains('<script>&', $footer);
        foreach (['/envios-y-devoluciones', '/contacto', '/aviso-legal', '/privacidad'] as $href) {
            contains('href="' . $href . '"', $footer, 'el menú enlaza ' . $href);
        }
        contains('Envíos &amp; devoluciones', $footer);
        contains('href="mailto:tienda@ejemplo.test"', $footer);
        contains('href="tel:999000001"', $footer);
        contains('999 000 001', $footer);
        foreach ($payments as $name) {
            contains(e($name), $footer, 'la forma de pago «' . $name . '» sale por su nombre');
        }
        notContains('<img', p100Slice($footer, 'Formas de pago', '</div>'), 'las formas de pago, sin logotipos de marcas ajenas');
    });

    test('tema ' . $p100Theme . ': en el pie, un dato vacío no pinta su línea', function () use ($p100Theme): void {
        $app = p100App($p100Theme, ['store.phone' => '   ', 'store.address' => ''], false);
        $footer = p100Footer((new TestClient($app))->get('/')->body);
        contains('mailto:tienda@ejemplo.test', $footer, 'el correo sí está');
        notContains('tel:', $footer, 'sin teléfono no hay enlace de teléfono');
        notContains('Calle Mayor', $footer, 'sin dirección no hay dirección');
        $app = p100App($p100Theme, ['store.email' => ''], false);
        $footer = p100Footer((new TestClient($app))->get('/')->body);
        contains('tel:999000001', $footer, 'el teléfono sí está');
        notContains('mailto:', $footer, 'sin correo no hay enlace de correo');
        $app = p100App($p100Theme, ['store.phone' => '', 'store.email' => '', 'store.address' => ''], false);
        $footer = p100Footer((new TestClient($app))->get('/')->body);
        notContains('tel:', $footer);
        notContains('mailto:', $footer);
    });
}

test('el pie sin catálogo ni formas de pago no pinta esos bloques', function (): void {
    $app = testApp(['settings' => ['store.email' => 'tienda@ejemplo.test']]);
    $footer = p100Footer((new TestClient($app))->get('/')->body);
    notContains('af-footer-cats', $footer);
    notContains('af-footer-pay', $footer);
    contains('mailto:tienda@ejemplo.test', $footer);
});

// ------------------------------------------------------------------ B · la insignia, dentro de la galería

foreach (p100Themes() as $p100Theme) {
    test('tema ' . $p100Theme . ': la insignia de la ficha está dentro del contenedor de la galería y fuera de la compra', function () use ($p100Theme): void {
        $app = p100App($p100Theme);
        p100Catalog($app);
        $body = (new TestClient($app))->get('/producto/sandalia')->body;
        $where = p100Ancestry($body, 'af-product-badges');
        check($where['found'], 'la ficha lleva la insignia «Nuevo»');
        check($where['in_gallery'], 'la insignia cuelga del contenedor de la galería');
        check(!$where['in_buy'], 'y no de la columna de compra (donde tapaba el título)');
        $title = p100Ancestry($body, 'af-title');
        check($title['in_buy'] && !$title['in_gallery'], 'el título sigue en la compra');
    });
}

// ------------------------------------------------------------------ E · confianza y relacionados, en los seis temas

foreach (p100Themes() as $p100Theme) {
    test('tema ' . $p100Theme . ': debajo del botón, envío, devolución y pago con los datos de la tienda', function () use ($p100Theme): void {
        $app = p100App($p100Theme);
        p100Carrier($app);
        p100Catalog($app);
        $body = (new TestClient($app))->get('/producto/sandalia')->body;
        $trust = p100Slice($body, '<ul class="af-trust', '</ul>');
        contains('data-af-trust-line="shipping"', $trust);
        contains(e('Envío gratis a partir de ' . p100Money($app, 6000)), $trust, 'el umbral sale del transportista');
        contains(e('Si no, desde ' . p100Money($app, 495)), $trust, 'y el precio más barato, de su tarifa');
        contains('Entrega en 2 a 3 días', $trust, 'el plazo, del transportista');
        contains('data-af-trust-line="returns"', $trust);
        contains('Devolución en 30 días', $trust, 'el plazo de returns.days');
        contains('data-af-trust-line="payment"', $trust);
        foreach (StoreInfo::payments($app) as $name) {
            contains(e($name), $trust);
        }
        $add = strpos($body, 'data-af-add');
        check($add !== false && $add < strpos($body, '<ul class="af-trust'), 'las líneas van debajo del botón de compra');
        notContains('<script', $trust);
        contains('data-af-store-style', $body, 'su hoja va dentro de la página');
    });

    test('tema ' . $p100Theme . ': sin el módulo de devoluciones su línea no sale', function () use ($p100Theme): void {
        $app = p100App($p100Theme, [], false);
        p100Carrier($app);
        p100Catalog($app);
        $body = (new TestClient($app))->get('/producto/sandalia')->body;
        contains('data-af-trust-line="shipping"', $body);
        notContains('data-af-trust-line="returns"', $body);
        notContains('Devolución en', $body);
    });

    test('tema ' . $p100Theme . ': «También te puede interesar»: misma categoría, sin el propio, comprables primero y cuatro como mucho', function () use ($p100Theme): void {
        $app = p100App($p100Theme);
        p100Catalog($app);
        $body = (new TestClient($app))->get('/producto/sandalia')->body;
        $related = p100Slice($body, 'id="af-related"', '</section>');
        contains('También te puede interesar', $related);
        foreach (['bota', 'mocasin', 'zueco', 'botin'] as $slug) {
            contains('href="/producto/' . $slug . '"', $related, 'sale ' . $slug);
        }
        notContains('href="/producto/sandalia"', $related, 'nunca el propio producto');
        notContains('href="/producto/agotada"', $related, 'la agotada (la más reciente) queda detrás de las que se compran');
        notContains('href="/producto/bolso"', $related, 'nada de otra categoría');
    });
}

test('relacionados: con los elegidos a mano mandan esos; sin categoría, los más recientes; con el ajuste apagado, nada', function (): void {
    $app = p100App('base');
    $ids = p100Catalog($app);
    $catalog = $app->service('catalog');
    $ids['manual'] = aifecProduct($app, ['name' => 'Elegida', 'slug' => 'elegida', 'related' => [$ids['bolso'], $ids['agotado']]]);
    $manual = Related::cards($app, $catalog->product($ids['manual']));
    same([$ids['bolso'], $ids['agotado']], array_map(static function (array $c): int {
        return (int) $c['id'];
    }, $manual), 'los elegidos a mano, los comprables primero');

    $loose = aifecProduct($app, ['name' => 'Suelta', 'slug' => 'suelta', 'created_at' => '2026-11-13 08:00:00']);
    $cards = Related::cards($app, $catalog->product($loose));
    same(4, count($cards), 'cuatro como mucho');
    foreach ($cards as $card) {
        check((int) $card['id'] !== $loose, 'sin categoría tampoco sale el propio');
        check((string) $card['availability']['status'] !== 'out_of_stock', 'los agotados, detrás');
    }

    $app->settings()->set('catalog.related', false);
    same([], Related::cards($app, $catalog->product($ids['self'])), 'el ajuste lo apaga');
    $app->invalidate(['pages']);
    notContains('id="af-related"', (new TestClient($app))->get('/producto/sandalia')->body);
});

test('la ficha con las líneas de confianza sigue en la caché de página y se renueva al cambiar una tarifa de envío', function (): void {
    $app = p100App('base');
    $rate = p100Carrier($app, 495, null);
    p100Catalog($app);
    $web = new TestClient($app);
    $first = $web->get('/producto/sandalia');
    contains(e('Envío desde ' . p100Money($app, 495)), $first->body);
    check(in_array('shipping', $web->last->cacheTags, true), 'la ficha lleva la etiqueta «shipping»');
    $cached = p100Cached($app, '/producto/sandalia');
    check($cached !== null, 'la ficha entra en la caché de página');
    contains(e('Envío desde ' . p100Money($app, 495)), (string) $cached->body);

    check(RateEditor::save($app, $rate, 595), 'la tarifa cambia como desde el panel');
    same(null, p100Cached($app, '/producto/sandalia'), 'el cambio de tarifa vacía la ficha guardada');
    $second = $web->get('/producto/sandalia')->body;
    contains(e('Envío desde ' . p100Money($app, 595)), $second);
    notContains(e('Envío desde ' . p100Money($app, 495)), $second);
});

test('sin tarifa calculable sin dirección, la línea de envío enlaza a la página de envíos o no sale', function (): void {
    $app = p100App('base', [], false);
    p100Catalog($app);
    $none = (new TestClient($app))->get('/producto/sandalia')->body;
    notContains('data-af-trust-line="shipping"', $none, 'sin transportistas ni página, nada inventado');
    cmsInsertPage($app, ['title' => 'Envíos y devoluciones']);
    $app->invalidate(['pages']);
    $with = (new TestClient($app))->get('/producto/sandalia')->body;
    $line = p100Slice($with, 'data-af-trust-line="shipping"', '</li>');
    contains('href="/envios-y-devoluciones"', $line);
    contains('Gastos y plazos de envío', $line);
});

// ------------------------------------------------------------------ C, D, F · la ficha no se rompe

test('atelier: la columna de compra no recorta nada y la galería tiene foto principal y miniaturas', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/themes/atelier/assets/css/theme.css');
    check(preg_match_all('/\.at-buy\{[^}]*\}/', $css, $rules) >= 0, 'se leen las reglas de .at-buy');
    foreach ($rules[0] as $rule) {
        notContains('max-height', $rule, 'la compra no tiene alto máximo');
        notContains('overflow', $rule, 'ni barra de desplazamiento propia');
    }
    $app = p100App('atelier');
    p100Catalog($app);
    $body = (new TestClient($app))->get('/producto/sandalia')->body;
    $gallery = p100Slice($body, '<div class="at-pgal af-gallery"', 'class="at-buy af-product-buy');
    same(1, substr_count($gallery, 'data-af-gallery-main'), 'una foto principal');
    same(2, substr_count($gallery, '<button type="button" class="at-pg-thumb"'), 'una miniatura (botón: vale con el teclado) por foto');
    same(2, substr_count($gallery, 'data-src='), 'cada miniatura cambia la principal');
});

test('la descripción larga usa el ancho útil con una medida de línea legible (centrada), en los temas que la ponen debajo', function (): void {
    $css = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/catalog/assets/store.css');
    check(preg_match('/section#af-description\{([^}]*)\}/', $css, $m) === 1, 'hay regla para la descripción');
    contains('max-width:76ch', $m[1]);
    contains('margin-inline:auto', $m[1]);
    foreach (['base', 'despensa', 'taller'] as $theme) {
        $app = p100App($theme);
        aifecProduct($app, ['name' => 'Bota', 'slug' => 'bota', 'description' => '<p>Texto largo de la bota.</p>']);
        $body = (new TestClient($app))->get('/producto/bota')->body;
        check(preg_match('#<section[^>]*id="af-description"#', $body) === 1, $theme . ': la descripción es una sección');
        contains('section#af-description{', $body, $theme . ': la regla llega a la página');
    }
});

// ------------------------------------------------------------------ G, H · portada

test('portada del tema base: cabecera con su botón, mosaico de categorías (con su imagen o la del primer producto) y ventajas', function (): void {
    $app = p100App('base', ['seo.home_description' => 'Calzado & bolsos hechos a mano']);
    p100Carrier($app);
    $bolsos = aifecCategory($app, 'Bolsos', null, ['media_id' => aifecMedia($app, 'Bolsos')]);
    $zapatos = aifecCategory($app, 'Zapatos');
    aifecProduct($app, ['name' => 'Agotado', 'slug' => 'agotado', 'stock' => 0, 'images' => 1, 'categories' => [$zapatos], 'created_at' => '2026-11-12 10:00:00']);
    $shoe = aifecProduct($app, ['name' => 'Bota', 'slug' => 'bota', 'images' => 1, 'categories' => [$zapatos]]);
    aifecProduct($app, ['name' => 'Bolso', 'slug' => 'bolso', 'images' => 1, 'categories' => [$bolsos], 'featured' => 1]);
    $body = (new TestClient($app))->get('/')->body;
    $hero = p100Slice($body, '<section class="af-hero"', '</section>');
    contains('<h1 class="af-hero-title" id="af-hero-title">Tienda &lt;script&gt;&amp;</h1>', $hero, 'sin título propio, el nombre de la tienda');
    contains('Calzado &amp; bolsos hechos a mano', $hero, 'y su descripción de portada');
    contains('href="/categoria/bolsos"', $hero, 'el botón lleva a la categoría principal');
    same(1, substr_count($body, '<h1'), 'un solo h1');
    $perks = p100Slice($body, 'af-home-perks', '</ul>');
    contains('af-trust-row', $perks);
    contains('data-af-trust-line="shipping"', $perks);
    $mosaic = p100Slice($body, 'data-section="catalog.categories"', '</section>');
    contains('href="/categoria/bolsos"', $mosaic, 'la categoría con imagen propia');
    contains('href="/categoria/zapatos"', $mosaic, 'y la que no tiene, con la de su producto');
    $card = $app->service('catalog')->card($shoe);
    $src = !empty($card['image']['card']) ? (string) $card['image']['card'] : (string) $card['image']['url'];
    contains(e($src), $mosaic, 'la foto es la del primer producto que se puede comprar');
    check(strpos($body, '<section class="af-hero"') < strpos($body, 'data-section="catalog.featured"'), 'la cabecera va antes que las secciones');
});

test('portada del tema base con título y texto de «Temas → Portada»', function (): void {
    $app = p100App('base', ['theme.options' => ['base' => ['home_title' => 'Hecho & a mano', 'home_text' => 'Desde 1990']]]);
    $hero = p100Slice((new TestClient($app))->get('/')->body, '<section class="af-hero"', '</section>');
    contains('Hecho &amp; a mano', $hero);
    contains('Desde 1990', $hero);
    notContains('af-btn-primary', $hero, 'sin categorías, sin botón que no lleve a nada');
});

test('portada de atelier: la cabecera usa la foto de un producto destacado y nunca el dibujo de otro sector', function (): void {
    $empty = (new TestClient(p100App('atelier')))->get('/')->body;
    notContains('campaign.svg', $empty, 'sin fotos, sin ilustración');
    contains('<span class="at-hero-img" aria-hidden="true"></span>', $empty);

    $app = p100App('atelier');
    $cat = aifecCategory($app, 'Calzado');
    aifecProduct($app, ['name' => 'Normal', 'slug' => 'normal', 'images' => 1, 'categories' => [$cat]]);
    $featured = aifecProduct($app, ['name' => 'Destacada', 'slug' => 'destacada', 'images' => 1, 'featured' => 1, 'categories' => [$cat]]);
    $body = (new TestClient($app))->get('/')->body;
    $hero = p100Slice($body, '<section class="at-hero', '</section>');
    $card = $app->service('catalog')->card($featured);
    $src = !empty($card['image']['large']) ? (string) $card['image']['large'] : (string) $card['image']['url'];
    contains(e($src), $hero, 'la foto del destacado');
    contains('at-hero-product', $hero, 'enseñada entera');
    notContains('campaign.svg', $body);
});

// ------------------------------------------------------------------ I · el menú con una sola categoría principal

test('menú: con UNA sola categoría principal, la cabecera enseña sus hijas en los seis temas', function (): void {
    foreach (p100Themes() as $theme) {
        $app = p100App($theme);
        $root = aifecCategory($app, 'Calzado');
        aifecCategory($app, 'Calzado de hombre', $root);
        aifecCategory($app, 'Calzado de mujer', $root);
        $items = menu('main');
        same(['Calzado de hombre', 'Calzado de mujer'], array_map(static function (array $i): string {
            return (string) $i['label'];
        }, $items), $theme . ': las hijas, a un clic');
        $body = (new TestClient($app))->get('/')->body;
        $header = p100Slice($body, '<header', '</header>');
        contains('href="/categoria/calzado/calzado-de-hombre"', $header, $theme);
        contains('href="/categoria/calzado/calzado-de-mujer"', $header, $theme);
    }
});

// ------------------------------------------------------------------ J · el carrito

test('carrito: seguir comprando, lo que falta para el envío gratis con su cifra exacta, confianza y relacionados', function (): void {
    $app = p100App('base');
    p100Carrier($app);
    $ids = p100Catalog($app);
    $web = new TestClient($app);
    aifecCartRun($web, static function ($cart) use ($ids): void {
        $cart->add($ids['bota'], 0, 1, []);
    });
    $body = $web->get('/carrito')->body;
    contains('data-af-continue', $body);
    contains('>Seguir comprando</a>', $body);
    contains(e('Te faltan ' . p100Money($app, 2000) . ' para el envío gratis'), $body, '60,00 − 40,00 = 20,00');
    contains('<progress max="6000" value="4000"', $body);
    contains('aria-label="Lo que llevas para el envío gratis"', $body);
    $aside = p100Slice($body, '<aside class="af-summary', '</aside>');
    check(strpos($aside, 'data-af-trust') > strpos($aside, 'Finalizar compra'), 'las líneas de confianza, bajo «Finalizar compra»');
    $related = p100Slice($body, 'id="af-related"', '</section>');
    contains('También te puede interesar', $related);
    notContains('href="/producto/bota"', $related, 'sin lo que ya está en el carrito');
    contains('href="/producto/sandalia"', $related, 'de la misma categoría que la primera línea');
    contains('continue.js', $body, 'el guion que recuerda la última categoría');

    aifecCartRun($web, static function ($cart) use ($ids): void {
        $cart->add($ids['mocasin'], 0, 1, []);
    });
    $over = $web->get('/carrito')->body;
    contains('Tu pedido tiene el envío gratis', $over, '80,00 € ya pasa del umbral');
    notContains('Te faltan', $over);
    contains('<progress max="6000" value="6000"', $over);
});

test('lo que falta para el envío gratis, al céntimo: por debajo, justo en el umbral y sin umbral', function (): void {
    $app = p100App('base');
    p100Carrier($app);
    $carrier = (int) $app->db()->value('SELECT id FROM {carriers} WHERE code = ?', ['std']);
    $cart = ['needs_shipping' => true, 'shipping_options' => [['carrier_id' => $carrier, 'price' => 495, 'needs_address' => true]], 'totals' => ['items_total' => 5999]];
    $below = StoreInfo::freeShipping($app, $cart);
    same(1, $below['missing'], 'a 59,99 € le falta un céntimo');
    same(false, $below['reached']);
    same(99, $below['percent']);
    $cart['totals']['items_total'] = 6000;
    $at = StoreInfo::freeShipping($app, $cart);
    same(0, $at['missing'], 'justo en el umbral ya es gratis');
    same(true, $at['reached']);
    $cart['totals']['items_total'] = 1234;
    same(4766, StoreInfo::freeShipping($app, $cart)['missing']);
    same(null, StoreInfo::freeShipping($app, ['needs_shipping' => false] + $cart), 'sin envío no hay mensaje');
    $app->db()->update('carriers', ['free_from' => null], ['id' => $carrier]);
    same(null, StoreInfo::freeShipping($app, $cart), 'sin umbral no hay mensaje');
});

test('carrito vacío: un mensaje, el buscador y las categorías principales', function (): void {
    $app = p100App('base');
    p100Catalog($app);
    $body = (new TestClient($app))->get('/carrito')->body;
    $empty = p100Slice($body, '<div class="af-card af-empty af-cart-empty"', '</div>');
    contains('Tu carrito está vacío', $empty);
    contains('role="search"', $empty);
    contains('name="q"', $empty);
    contains('href="/categoria/zapatos-script"', $empty);
    contains('Zapatos &lt;script&gt;&amp;', $empty);
    contains('href="/categoria/bolsos"', $empty);
    notContains('data-af-free-shipping', $body);
});

test('el guion de «Seguir comprando» sale en el listado de una categoría y no en la portada', function (): void {
    $app = p100App('base');
    p100Catalog($app);
    $web = new TestClient($app);
    contains('/catalog/', $web->get('/categoria/bolsos')->body);
    check(preg_match('#<script src="[^"]*/catalog/[^"]*continue\.js" defer></script>#', $web->get('/categoria/bolsos')->body) === 1, 'el guion diferido de la categoría');
    notContains('continue.js', $web->get('/')->body);
    $js = (string) file_get_contents(dirname(__DIR__, 2) . '/modules/catalog/assets/continue.js');
    contains('sessionStorage', $js);
    foreach (['fetch(', 'XMLHttpRequest', 'innerHTML', 'http://', 'https://', 'eval('] as $banned) {
        notContains($banned, $js);
    }
});

// ------------------------------------------------------------------ los datos (Aifec\Front\StoreInfo)

test('StoreInfo: el contacto quita los espacios del enlace del teléfono y deja vacío lo que no está', function (): void {
    $app = p100App('base', ['store.phone' => ' +34 999 000 001 ', 'store.address' => '']);
    $contact = StoreInfo::contact($app);
    same('+34 999 000 001', $contact['phone']);
    same('+34999000001', $contact['phone_href']);
    same('', $contact['address']);
    same('tienda@ejemplo.test', $contact['email']);
});

test('StoreInfo: categorías principales, o las hijas si hay una sola, seis como mucho', function (): void {
    $app = p100App('base');
    $root = aifecCategory($app, 'Calzado');
    foreach (['Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco', 'Seis', 'Siete'] as $name) {
        aifecCategory($app, $name, $root);
    }
    $names = array_column(StoreInfo::categories($app), 'name');
    same(['Uno', 'Dos', 'Tres', 'Cuatro', 'Cinco', 'Seis'], $names);
    same(['name' => 'Calzado', 'url' => '/categoria/calzado'], StoreInfo::mainCategory($app), 'la principal sigue siendo la única de primer nivel');
    aifecCategory($app, 'Bolsos');
    $app->invalidate(['catalog']);
    same(['Calzado', 'Bolsos'], array_column(StoreInfo::categories($app), 'name'), 'con dos principales, ellas');
});

test('StoreInfo: la recogida en tienda no da el «desde» ni el plazo, y un envío a 0 dice «Envío gratis»', function (): void {
    $app = p100App('base');
    $pickup = shippingAddCarrier($app, 'tienda', ['name' => 'Recogida', 'handler' => 'pickup', 'position' => 0]);
    shippingAddRate($app, $pickup, shippingZoneId($app, 'Peninsula'), 'weight', 0, 0, 0);
    same(null, StoreInfo::shipping($app), 'solo recogida: no hay envío a domicilio que anunciar');
    p100Carrier($app, 0, null);
    $lines = StoreInfo::trust($app);
    same('shipping', $lines[0]['code']);
    same('Envío gratis', $lines[0]['text']);
});

test('StoreInfo: el texto de quién paga la vuelta sale tal cual lo escribió la tienda, y la ficha lo escapa', function (): void {
    $app = p100App('base', ['returns.return_shipping_payer' => 'La vuelta la pagamos <b>nosotros</b> & tú no', 'returns.days' => 15]);
    p100Catalog($app);
    $returns = StoreInfo::returns($app);
    same(15, $returns['days']);
    $line = p100Slice((new TestClient($app))->get('/producto/sandalia')->body, 'data-af-trust-line="returns"', '</li>');
    contains('Devolución en 15 días', $line);
    contains('La vuelta la pagamos &lt;b&gt;nosotros&lt;/b&gt; &amp; tú no', $line);
    $app->settings()->set('returns.days', 0);
    same(null, StoreInfo::returns($app), 'con 0 días no se promete nada');
});

test('la hoja de las piezas comunes solo va en la ficha, el carrito y la portada', function (): void {
    $app = p100App('base');
    p100Catalog($app);
    $web = new TestClient($app);
    contains('data-af-store-style', $web->get('/producto/sandalia')->body);
    contains('data-af-store-style', $web->get('/carrito')->body);
    contains('data-af-store-style', $web->get('/')->body);
    notContains('data-af-store-style', $web->get('/categoria/bolsos')->body);
});

test('relacionados de un producto que no existe o con id 0: nada, sin errores', function (): void {
    $app = p100App('base');
    same([], Related::forId($app, 0));
    same([], Related::forId($app, 999999));
    same([], Related::cards($app, ['name' => 'Sin id']));
});

test('el pie: una dirección de varias líneas sale con saltos y escapada', function (): void {
    $app = p100App('base', ['store.address' => "Calle Mayor 1\n<b>Local</b> & 2"]);
    $footer = p100Footer((new TestClient($app))->get('/')->body);
    contains('Calle Mayor 1<br>' . "\n" . '&lt;b&gt;Local&lt;/b&gt; &amp; 2', $footer);
});

test('el pie pinta las formas de pago en el orden de orders.payment_order', function (): void {
    $app = p100App('base', ['orders.payment_order' => 'simulated,cod']);
    $names = StoreInfo::payments($app);
    same(2, count($names));
    $footer = p100Footer((new TestClient($app))->get('/')->body);
    check(strpos($footer, e($names[0])) < strpos($footer, e($names[1])), 'en su orden');
    $app->settings()->set('orders.payment_order', 'cod,simulated');
    same(array_reverse($names), StoreInfo::payments($app), 'cambiar el orden cambia el pie');
});

test('las líneas de confianza no salen en una ficha que no se puede comprar', function (): void {
    $app = p100App('base');
    p100Carrier($app);
    $ids = p100Catalog($app);
    $app->hooks()->setCurrentModule('catalog');
    $app->hooks()->filter('product.availability', static function (array $a, array $context) use ($ids): array {
        if ($context['id'] === $ids['self']) {
            $a['purchasable'] = false;
        }

        return $a;
    });
    $app->hooks()->setCurrentModule('core');
    $app->invalidate(['catalog']);
    $body = (new TestClient($app))->get('/producto/sandalia')->body;
    $own = strstr($body, 'id="af-related"', true);
    notContains('data-af-trust', $own !== false ? $own : $body);
});

// ------------------------------------------------------------------ caché: lo de cada página no cuesta consultas

test('con la caché hecha, los datos del pie y de la confianza, el menú y los relacionados no hacen ninguna consulta', function (): void {
    $app = p100App('base');
    p100Carrier($app);
    $ids = p100Catalog($app);
    $product = $app->service('catalog')->product($ids['self']);
    same([], StoreInfo::data($app)['trust'], 'el pie solo no calcula las líneas de confianza');
    $data = StoreInfo::data($app, true);
    menu('main');
    $cards = Related::cards($app, $product);
    $sections = HomeSections::add([], $app);
    $sectionIds = array_column($sections, 'id');
    check(in_array('catalog.categories', $sectionIds, true) && in_array('catalog.new', $sectionIds, true), 'la portada tiene el mosaico y las novedades');
    check($data['trust'] !== [] && $data['payments'] !== [] && $data['categories'] !== [], 'hay datos que guardar');
    same(4, count($cards));

    $next = perfNext($app);
    $next->boot();
    $next->service('catalog');
    $before = $next->dbConnected() ? $next->db()->queries : 0;
    same($data, StoreInfo::data($next, true), 'lo mismo, de la caché del sitio');
    same($data['payments'], StoreInfo::data($next)['payments'], 'el pie lee la misma entrada');
    $items = $next->hooks()->apply('menu.items', [], 'main', $next);
    check(is_array($items) && $items !== [], 'el menú sale');
    same(array_column($cards, 'id'), array_column(Related::cards($next, $product), 'id'), 'los mismos relacionados');
    same(array_column($sections, 'id'), array_column(HomeSections::add([], $next), 'id'), 'las mismas secciones de la portada (mosaico incluido)');
    same($before, $next->dbConnected() ? $next->db()->queries : 0, 'ninguna consulta');
});

test('la caché de los datos del pie se vacía al cambiar una tarifa, el catálogo o los contenidos', function (): void {
    $app = p100App('base');
    $rate = p100Carrier($app, 495, null);
    p100Catalog($app);
    contains(e('Envío desde ' . p100Money($app, 495)), StoreInfo::data($app, true)['trust'][0]['text']);
    check(RateEditor::save($app, $rate, 595), 'la tarifa cambia');
    contains(e('Envío desde ' . p100Money($app, 595)), StoreInfo::data($app, true)['trust'][0]['text']);
    aifecCategory($app, 'Sombreros');
    $app->invalidate(['catalog']);
    contains('Sombreros', implode(' ', array_column(StoreInfo::data($app)['categories'], 'name')));
});

// ------------------------------------------------------------------ 9 · peso y política de contenido

test('presupuestos: theme.css ≤ 45 000 y theme.js ≤ 25 000 en los seis temas; las hojas nuevas del catálogo ≤ 2 500', function (): void {
    $root = dirname(__DIR__, 2);
    foreach (p100Themes() as $theme) {
        $css = $root . '/themes/' . $theme . '/assets/css/theme.css';
        $js = $root . '/themes/' . $theme . '/assets/js/theme.js';
        check((int) filesize($css) <= 45000, $theme . ': theme.css pesa ' . filesize($css));
        // Un tema sin guion propio (gmartos) usa el del tema base.
        check(!is_file($js) || (int) filesize($js) <= 25000, $theme . ': theme.js pesa ' . (is_file($js) ? filesize($js) : 0));
    }
    $store = $root . '/modules/catalog/assets/store.css';
    check((int) filesize($store) <= 2500, 'store.css pesa ' . filesize($store));
    notContains('!important', (string) file_get_contents($store));
    check((int) filesize($root . '/modules/catalog/assets/continue.js') <= 2500, 'continue.js es pequeño');
    foreach (['modules/catalog/views/front/partials/trust.php', 'modules/orders/views/front/cart.php', 'themes/base/templates/home.php', 'themes/base/templates/partials/footer.php'] as $file) {
        $code = (string) file_get_contents($root . '/' . $file);
        check(preg_match('/<script(?![^>]*type="application\/json")/i', $code) !== 1, $file . ': sin <script> escrito');
        check(preg_match('/\son[a-z]+="/i', $code) !== 1, $file . ': sin atributos on…');
    }
});
