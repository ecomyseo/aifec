<?php
/**
 * AIFEC — pruebas de la ficha de producto de la tienda: lo que lleva el HTML (un solo h1, precio, canónica, imagen para
 * compartir, galería antes que la compra), el JSON de variantes y su escape, borradores, agotados, el formulario de compra
 * según haya o no módulo de pedidos, los huecos del tema, la caché de página y la compra sin JavaScript.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Kernel\PageCache;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;
use Aifec\Modules\Catalog\Front\Variants;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../orders/tests/helpers.php';

if (!function_exists('frontServe')) {
    /** La copia de la caché de página de una dirección, tal como la serviría el servidor antes de arrancar la aplicación. */
    function frontServe(App $app, string $uri): ?Response
    {
        $request = Request::create('GET', $uri);
        $request->host = 'tienda.test';
        $request->secure = true;

        return PageCache::serve($app->site(), $request, $app->clock());
    }
}

if (!function_exists('frontCheckPage')) {
    /**
     * Lo que ninguna página de la tienda puede llevar: un href vacío, una dirección de otro dominio o un formulario POST cuya
     * ruta no pase por el intermediario «csrf».
     */
    function frontCheckPage(App $app, string $html, string $label): void
    {
        notContains('href=""', $html, $label . ': href vacío');
        preg_match_all('#(?:href|src|action)="(?:https?:)?//([^/"?\#]+)#i', $html, $hosts);
        foreach ($hosts[1] as $host) {
            same('tienda.test', $host, $label . ': dirección de otro dominio');
        }
        preg_match_all('/<form\b[^>]*>/i', $html, $forms);
        foreach ($forms[0] as $tag) {
            if (preg_match('/\bmethod="post"/i', $tag) !== 1) {
                continue;
            }
            $action = preg_match('/\baction="([^"]*)"/i', $tag, $a) === 1 ? html_entity_decode($a[1]) : '';
            $match = $app->router()->match('POST', (string) parse_url($action, PHP_URL_PATH));
            check($match !== null && in_array('csrf', $match['route']->middleware, true), $label . ': formulario POST sin csrf (' . $action . ')');
        }
    }
}

/**
 * La tienda de las pruebas de la ficha: una categoría con su hija, una marca y una atributo de talla.
 *
 * @return array<string,int>
 */
function frontProductShop(App $app): array
{
    $ids = [];
    $ids['muebles'] = aifecCategory($app, 'Muebles');
    $ids['sillas'] = aifecCategory($app, 'Sillas', $ids['muebles']);
    $ids['nordica'] = aifecBrand($app, 'Nórdica');
    aifecAttribute($app, 'talla', 'Talla', ['s' => 'S', 'm' => 'M']);
    aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo <i>&', 'azul' => 'Azul']);

    return $ids;
}

/** El JSON de variantes de una página, descodificado; falla si no está o no es JSON válido. */
function frontVariantsJson(string $html): array
{
    check(preg_match('#<script type="application/json" data-af-variants-data>(.*?)</script>#s', $html, $m) === 1, 'hay JSON de variantes');
    $data = json_decode($m[1], true);
    check(is_array($data), 'el JSON de variantes es válido: ' . json_last_error_msg());

    return $data;
}

test('la ficha: 200, un solo h1, el precio formateado, la canónica, los datos de cabecera y la imagen para compartir', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $ids = frontProductShop($app);
    $id = aifecProduct($app, [
        'name' => 'Silla Ergo', 'slug' => 'silla-ergo', 'price' => 5990, 'images' => 2, 'categories' => [$ids['sillas']], 'brand_id' => $ids['nordica'],
        'summary' => '<p>Resumen corto</p>', 'description' => '<p>Texto largo de la silla</p>', 'sku' => 'SILLA-1', 'gtin' => '8412345678905',
        'meta_title' => 'Silla Ergo | Muebles', 'meta_description' => 'La silla más cómoda',
    ]);
    $web = new TestClient($app);
    $r = $web->get('/producto/silla-ergo');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'), 'un solo h1');
    contains('<h1 class="af-title">Silla Ergo</h1>', $r->body);
    contains(e(money(5990)), $r->body, 'el precio ya formateado');
    contains('<link rel="canonical" href="https://tienda.test/producto/silla-ergo">', $r->body);
    contains('<title>Silla Ergo | Muebles · ', $r->body);
    contains('<meta name="description" content="La silla más cómoda">', $r->body);
    contains('<meta property="og:type" content="product">', $r->body);
    check(preg_match('#<meta property="og:image" content="https://tienda\.test/[^"]+">#', $r->body) === 1, 'og:image con la dirección completa de la primera imagen');
    contains('af-type-product', $r->body, 'el tipo de página llega al envoltorio del tema');
    contains('Texto largo de la silla', $r->body);
    contains('Resumen corto', $r->body);
    contains('8412345678905', $r->body, 'el GTIN está a la vista de las máquinas');
    contains('SILLA-1', $r->body);
    contains('href="/marca/nordica"', $r->body, 'la marca es un enlace');
    same(600, $r->cacheTtl);
    check(in_array('product:' . $id, $r->cacheTags, true) && in_array('catalog', $r->cacheTags, true) && in_array('tax', $r->cacheTags, true), 'se invalida con su etiqueta, «catalog» y «tax»');
});

test('migas: portada, categorías de la principal y el producto sin enlace', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $ids = frontProductShop($app);
    aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo', 'categories' => [$ids['sillas']]]);
    $web = new TestClient($app);
    $body = $web->get('/producto/silla-ergo')->body;
    check(preg_match('#<nav class="af-breadcrumbs".*?</nav>#s', $body, $m) === 1, 'hay migas');
    $crumbs = $m[0];
    $posHome = strpos($crumbs, 'href="/"');
    $posMuebles = strpos($crumbs, 'href="/categoria/muebles"');
    $posSillas = strpos($crumbs, 'href="/categoria/muebles/sillas"');
    check($posHome !== false && $posMuebles !== false && $posSillas !== false, 'portada, categoría madre y categoría de la principal');
    check($posHome < $posMuebles && $posMuebles < $posSillas, 'en ese orden');
    contains('<li aria-current="page">Silla Ergo</li>', $crumbs, 'el producto es el último y no enlaza');
});

test('la galería va antes que la compra, la primera imagen no es diferida y las demás sí', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo', 'images' => 3]);
    $web = new TestClient($app);
    $body = $web->get('/producto/silla-ergo')->body;
    $gallery = strpos($body, 'af-product-gallery');
    $buy = strpos($body, 'af-product-buy');
    check($gallery !== false && $buy !== false && $gallery < $buy, 'galería a la izquierda: va antes en el HTML');
    check(preg_match('#<img[^>]*data-af-gallery-main[^>]*>#', $body, $main) === 1, 'hay imagen principal');
    contains('loading="eager"', $main[0]);
    contains('fetchpriority="high"', $main[0]);
    notContains('loading="lazy"', $main[0]);
    check(preg_match_all('#<ul class="af-gallery-thumbs">.*?</ul>#s', $body, $thumbs) === 1, 'hay miniaturas');
    same(3, substr_count($thumbs[0][0], '<li>'), 'una miniatura por imagen');
    contains('loading="lazy"', $thumbs[0][0]);
    contains('aria-current="true"', $thumbs[0][0]);
});

test('una sola imagen: sin miniaturas; sin imágenes: hueco y sin errores', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    aifecProduct($app, ['name' => 'Una foto', 'slug' => 'una-foto', 'images' => 1]);
    aifecProduct($app, ['name' => 'Sin foto', 'slug' => 'sin-foto']);
    $web = new TestClient($app);
    notContains('af-gallery-thumbs', $web->get('/producto/una-foto')->body);
    $r = $web->get('/producto/sin-foto');
    same(200, $r->status);
    contains('af-noimage', $r->body);
    notContains('og:image', $r->body, 'sin imagen no hay og:image');
});

test('variantes: el JSON es válido y lleva una entrada por variante, con precio en céntimos y formateado', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontProductShop($app);
    aifecProduct($app, [
        'name' => 'Camiseta', 'slug' => 'camiseta', 'price' => 1500,
        'variants' => [
            ['sku' => 'CAM-S', 'stock' => 3, 'values' => ['talla' => 's']],
            ['sku' => 'CAM-M', 'price' => 2500, 'compare_price' => 3000, 'stock' => 0, 'values' => ['talla' => 'm']],
        ],
    ]);
    $web = new TestClient($app);
    $body = $web->get('/producto/camiseta')->body;
    $data = frontVariantsJson($body);
    same(2, count($data['variants']), 'una entrada por variante');
    same('Camiseta', $data['name']);
    $byValue = [];
    foreach ($data['variants'] as $variant) {
        $byValue[$variant['values']['talla']] = $variant;
    }
    same(1500, $byValue['s']['price']['amount']);
    same(money(1500), $byValue['s']['price']['formatted']);
    same('CAM-S', $byValue['s']['sku']);
    same(2500, $byValue['m']['price']['amount']);
    same(3000, $byValue['m']['price']['compare']);
    same(money(3000), $byValue['m']['price']['compare_formatted']);
    same('out_of_stock', $byValue['m']['availability']['status']);
    check($byValue['s']['id'] > 0 && $byValue['s']['id'] !== $byValue['m']['id'], 'cada variante con su identificador');
    contains('data-af-variants', $body, 'el contenedor que theme.js maneja');
    // Una opción por valor, la elegida de serie marcada, y el selector es un grupo de radio con el nombre de la opción.
    contains('data-af-option="talla"', $body);
    same(2, substr_count($body, 'name="opt[talla]"'), 'un radio por valor');
    // El estado lo lleva el radio nativo: una etiqueta no puede llevar aria-pressed (PageSpeed, «navegación agéntica»,
    // 13/11/2026: «Elements must only use supported ARIA attributes» en <label class="af-variant" aria-pressed>).
    // La talla no se marca de serie (14/11/2026: sin mirarla se compraba la primera): quien compra la elige.
    same(1, preg_match('/<label class="af-variant" data-value="s">\s*<input class="af-sr" type="radio" name="opt\[talla\]" value="s">/', $body), 'la talla S, sin marcar');
    same(1, preg_match('/<label class="af-variant is-out" data-value="m">\s*<input class="af-sr" type="radio" name="opt\[talla\]" value="m" disabled>/', $body), 'la M, sin marcar y apagada');
    same(0, substr_count($body, 'name="opt[talla]" value="s" checked') + substr_count($body, 'name="opt[talla]" value="m" checked'), 'ninguna talla marcada');
    // La M está agotada: se ve, se dice y no se puede elegir (14/11/2026: la ficha no decía qué tallas quedaban).
    contains('name="opt[talla]" value="m" disabled', $body);
    contains('· agotada', $body);
    notContains('name="opt[talla]" value="s" disabled', $body, 'la S sí se puede elegir');
    same(0, preg_match('/<label\b[^>]*\baria-pressed/', $body), 'ninguna etiqueta con aria-pressed');
});

test('un nombre con <b>&: escapado en el HTML y dentro del JSON, que no puede cerrar la etiqueta script', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontProductShop($app);
    aifecProduct($app, [
        'name' => 'Camiseta <b>&</b> "x"', 'slug' => 'camiseta-rara', 'price' => 1500,
        'variants' => [
            ['sku' => 'R', 'stock' => 3, 'values' => ['color' => 'rojo']],
            ['sku' => 'A', 'stock' => 3, 'values' => ['color' => 'azul']],
        ],
    ]);
    $web = new TestClient($app);
    $body = $web->get('/producto/camiseta-rara')->body;
    contains('<h1 class="af-title">Camiseta &lt;b&gt;&amp;&lt;/b&gt; &quot;x&quot;</h1>', $body);
    notContains('Camiseta <b>', $body, 'el nombre no entra sin escapar');
    notContains('Rojo <i>&', $body, 'la etiqueta de la opción tampoco');
    $data = frontVariantsJson($body);
    same('Camiseta <b>&</b> "x"', $data['name'], 'dentro del JSON el nombre sigue siendo el mismo');
    $labels = array_map(static function (array $variant): string {
        return $variant['label'];
    }, $data['variants']);
    check(in_array('Rojo <i>&', $labels, true), 'la etiqueta de la variante con <i>& dentro del JSON: ' . json_encode($labels));
    // Cada script abierto está cerrado: nada de lo que va dentro del JSON puede cerrar la etiqueta antes de tiempo.
    same(substr_count($body, '<script'), substr_count($body, '</script>'), 'scripts abiertos y cerrados');
    check(preg_match('#data-af-variants-data>[^<]*</script>#', $body) === 1, 'el JSON no lleva ningún «<» sin escapar');
});

test('un borrador y un producto que aún no toca publicar no existen para el público', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    aifecProduct($app, ['name' => 'Borrador', 'slug' => 'borrador', 'status' => 'draft']);
    aifecProduct($app, ['name' => 'Futuro', 'slug' => 'futuro', 'published_at' => '2026-12-01 00:00:00']);
    $web = new TestClient($app);
    same(404, $web->get('/producto/borrador')->status);
    same(404, $web->get('/producto/futuro')->status);
});

test('agotado sin venta bajo pedido: botón desactivado con su texto; con existencias, activo', function (): void {
    $app = catalogApp([], ['orders', 'media']);
    aifecProduct($app, ['name' => 'Agotada', 'slug' => 'agotada', 'stock' => 0]);
    aifecProduct($app, ['name' => 'Bajo pedido', 'slug' => 'bajo-pedido', 'stock' => 0, 'backorders' => 'yes']);
    aifecProduct($app, ['name' => 'Disponible', 'slug' => 'disponible', 'stock' => 5]);
    $web = new TestClient($app);
    $out = $web->get('/producto/agotada')->body;
    check(preg_match('#<button[^>]*data-af-add[^>]*disabled[^>]*>([^<]*)</button>#', $out, $m) === 1, 'el botón está desactivado');
    same('Agotado', $m[1]);
    contains('is-out', $out, 'la disponibilidad se pinta como agotada');
    check(preg_match('#<button[^>]*data-af-add[^>]*disabled#', $web->get('/producto/bajo-pedido')->body) !== 1, 'bajo pedido se puede comprar');
    $ok = $web->get('/producto/disponible')->body;
    check(preg_match('#<button[^>]*data-af-add[^>]*>Añadir al carrito</button>#', $ok) === 1, 'con existencias, el botón activo');
});

test('sin módulo de pedidos no hay formulario de compra; con él, apunta a cart.add con producto, variante y cantidad', function (): void {
    $catalogOnly = catalogApp([], ['catalog', 'media']);
    aifecProduct($catalogOnly, ['name' => 'Silla', 'slug' => 'silla']);
    $web = new TestClient($catalogOnly);
    $body = $web->get('/producto/silla')->body;
    notContains('data-af-cart-add', $body, 'sin pedidos no hay formulario de compra');
    notContains('data-af-add', $body, 'ni botón');
    notContains('<form method="post"', $body);

    $shop = catalogApp([], ['orders', 'media']);
    $id = aifecProduct($shop, ['name' => 'Silla', 'slug' => 'silla', 'min_qty' => 2]);
    $buyer = new TestClient($shop);
    $page = $buyer->get('/producto/silla')->body;
    check(preg_match('#<form method="post" action="([^"]+)" data-af-cart-add>#', $page, $m) === 1, 'hay formulario de compra');
    same(route('cart.add'), html_entity_decode($m[1]), 'apunta a cart.add');
    contains('name="product_id" value="' . $id . '"', $page);
    contains('name="variant_id" value="0"', $page);
    contains('name="qty" value="2"', $page, 'la cantidad empieza en el mínimo');
    contains('data-af-variant-id', $page);
});

test('los huecos de la ficha se pintan, cada uno con el producto, y en su sitio', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo', 'description' => '<p>Larga</p>']);
    $slots = ['product.gallery.before', 'product.gallery.after', 'product.summary.before', 'product.price.after', 'product.buy.after', 'product.summary.after', 'product.tabs', 'product.bottom'];
    foreach ($slots as $slot) {
        $app->hooks()->slot($slot, static function ($product) use ($slot): string {
            return '<i data-slot="' . $slot . '">' . $product['name'] . '</i>';
        });
    }
    $web = new TestClient($app);
    $body = $web->get('/producto/silla-ergo')->body;
    $last = -1;
    foreach (['product.gallery.before', 'product.gallery.after', 'product.summary.before', 'product.price.after', 'product.buy.after', 'product.summary.after', 'product.tabs', 'product.bottom'] as $slot) {
        $pos = strpos($body, 'data-slot="' . $slot . '">Silla Ergo');
        check($pos !== false, 'el hueco ' . $slot . ' se pinta con el producto');
        check($pos > $last, 'el hueco ' . $slot . ' va después del anterior');
        $last = (int) $pos;
    }
});

test('descripción, datos clave, características, preguntas frecuentes y relacionados', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    aifecAttribute($app, 'acabado', 'Acabado', ['barniz' => 'Barniz natural']);
    $rel = aifecProduct($app, ['name' => 'Mesa Roble', 'slug' => 'mesa-roble', 'price' => 12000]);
    aifecProduct($app, [
        'name' => 'Silla Ergo', 'slug' => 'silla-ergo', 'related' => [$rel],
        'facts' => [['label' => 'Material', 'value' => 'Roble macizo']],
        'attributes' => ['acabado' => 'barniz'],
        'faqs' => [['question' => '¿Se monta sola?', 'answer' => 'No, trae instrucciones <script>alert(1)</script>']],
        'description' => '<p>Descripción <script>alert(2)</script>larga</p>',
    ]);
    $web = new TestClient($app);
    $body = $web->get('/producto/silla-ergo')->body;
    contains('<dl class="af-facts">', $body);
    contains('Roble macizo', $body);
    contains('<table class="af-table">', $body);
    contains('Barniz natural', $body, 'el atributo visible en la tabla de características');
    contains('<details', $body);
    contains('¿Se monta sola?', $body);
    contains('No, trae instrucciones', $body);
    notContains('<script>alert(1)', $body, 'la respuesta de una pregunta se limpia');
    notContains('<script>alert(2)', $body, 'la descripción se limpia');
    check(preg_match('#<section class="af-section af-related" id="af-related">.*?Mesa Roble#s', $body) === 1, 'el relacionado sale en su sección');
    check(in_array('product:' . $rel, $web->last->cacheTags, true), 'la ficha se invalida también cuando cambia un relacionado');
});

test('caché de página: segunda petición anónima = HIT y, tras invalidar el producto, MISS', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    $id = aifecProduct($app, ['name' => 'Silla Ergo', 'slug' => 'silla-ergo']);
    $web = new TestClient($app);
    same(null, frontServe($app, '/producto/silla-ergo'), 'antes de la primera visita no hay copia');
    $first = $web->get('/producto/silla-ergo');
    same('MISS', $first->headers['X-Cache']);
    $copy = frontServe($app, '/producto/silla-ergo');
    check($copy !== null, 'la segunda se sirve de la caché');
    same('HIT', $copy->headers['X-Cache']);
    contains('Silla Ergo', $copy->body);
    $app->invalidate(['product:' . $id]);
    same(null, frontServe($app, '/producto/silla-ergo'), 'invalidar la etiqueta del producto vacía su copia');
    same('MISS', $web->get('/producto/silla-ergo')->headers['X-Cache']);
});

test('la ficha no lleva href vacío, direcciones de otro dominio ni formularios POST sin csrf', function (): void {
    $app = catalogApp([], ['orders', 'media']);
    frontProductShop($app);
    $rel = aifecProduct($app, ['name' => 'Mesa', 'slug' => 'mesa', 'images' => 1]);
    aifecProduct($app, [
        'name' => 'Camiseta', 'slug' => 'camiseta', 'images' => 2, 'related' => [$rel],
        'variants' => [['sku' => 'S', 'stock' => 3, 'values' => ['talla' => 's']], ['sku' => 'M', 'stock' => 3, 'values' => ['talla' => 'm']]],
    ]);
    $web = new TestClient($app);
    $r = $web->get('/producto/camiseta');
    same(200, $r->status);
    frontCheckPage($app, $r->body, 'ficha con variantes');
    frontCheckPage($app, $web->get('/producto/mesa')->body, 'ficha simple');
});

test('Variants: elegida de serie, resolución por opciones y datos del JSON', function (): void {
    $app = catalogApp([], ['catalog', 'media']);
    frontProductShop($app);
    $id = aifecProduct($app, [
        'name' => 'Camiseta', 'slug' => 'camiseta',
        'variants' => [
            ['sku' => 'R-S', 'stock' => 0, 'values' => ['color' => 'rojo', 'talla' => 's']],
            ['sku' => 'R-M', 'stock' => 4, 'values' => ['color' => 'rojo', 'talla' => 'm']],
            ['sku' => 'A-M', 'stock' => 2, 'values' => ['color' => 'azul', 'talla' => 'm']],
        ],
    ]);
    $product = aifecCatalog($app)->product($id);
    $initial = Variants::initial($product);
    same('R-M', $initial['sku'], 'la primera que se puede comprar, no la primera');
    $azulM = Variants::resolve($product, ['color' => 'azul', 'talla' => 'm']);
    check($azulM !== null, 'se resuelve una combinación que existe');
    $found = null;
    foreach ($product['variants'] as $variant) {
        if ($variant['id'] === $azulM) {
            $found = $variant;
        }
    }
    same('A-M', $found['sku']);
    same(null, Variants::resolve($product, ['color' => 'azul']), 'falta una opción');
    same(null, Variants::resolve($product, ['color' => 'azul', 'talla' => 'xl']), 'combinación que no existe');
    same(null, Variants::resolve($product, ['color' => ['azul'], 'talla' => 'm']), 'un valor que no es texto');
    same(null, Variants::resolve($product, []));
    same(3, count(Variants::data($product)['variants']));
    same(null, Variants::initial(aifecCatalog($app)->product(aifecProduct($app, ['name' => 'Simple']))), 'sin variantes no hay inicial');
});

test('sin JavaScript: el paso propio resuelve la variante por las opciones y la añade al carrito', function (): void {
    $app = aifecCartApp();
    aifecAttribute($app, 'color', 'Color', ['rojo' => 'Rojo', 'azul' => 'Azul']);
    $id = aifecProduct($app, [
        'name' => 'Camiseta', 'slug' => 'camiseta', 'price' => 1500,
        'variants' => [
            ['sku' => 'R', 'stock' => 5, 'values' => ['color' => 'rojo']],
            ['sku' => 'A', 'price' => 1800, 'stock' => 5, 'values' => ['color' => 'azul']],
        ],
    ]);
    $web = new TestClient($app);
    $page = $web->get('/producto/camiseta')->body;
    check(preg_match('#<button[^>]*data-af-add[^>]*formaction="([^"]+)"#', $page, $m) === 1, 'el botón lleva el paso propio como formaction');
    $choose = html_entity_decode($m[1]);
    same(route('catalog.choose'), $choose);
    check(preg_match('#<form method="post" action="([^"]+)" data-af-cart-add>#', $page, $f) === 1 && html_entity_decode($f[1]) === route('cart.add'), 'con JavaScript el formulario sigue yendo a cart.add');

    $r = $web->post($choose, ['product_id' => (string) $id, 'qty' => '2', 'opt' => ['color' => 'azul']]);
    same(302, $r->status);
    same(route('cart'), $r->headers['Location']);
    $lines = aifecCartRun($web, static function ($cart): array {
        return array_values($cart->summary()['lines']);
    });
    same(1, count($lines));
    same(2, $lines[0]['qty']);
    same(1800, $lines[0]['unit_price'], 'la variante azul, la de 18 €');

    // Sin elegir nada, no se añade nada y el aviso queda para la página del carrito.
    $bad = $web->post($choose, ['product_id' => (string) $id, 'qty' => '1', 'opt' => ['color' => 'verde']]);
    same(302, $bad->status);
    same(2, aifecCartRun($web, static function ($cart): int {
        return $cart->count();
    }), 'el carrito sigue igual');
    same(404, $web->post($choose, ['product_id' => '99999', 'opt' => ['color' => 'rojo']])->status, 'un producto que no existe');
});
