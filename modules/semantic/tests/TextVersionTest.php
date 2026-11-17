<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/*
 * La versión en texto de cada página pública: «<dirección>.md» y «Accept: text/markdown», compuesta desde los datos.
 */

test('GET /producto/silla.md es Markdown con nombre, precio formateado y la tabla de datos, sin etiquetas HTML', function (): void {
    $app = semanticApp();
    $id = semanticProduct($app, [
        'sku' => 'SILLA-1', 'gtin' => '8412345678905', 'price' => 1999,
        'summary' => '<p>Silla <em>cómoda</em>.</p>', 'description' => '<h2>Detalles</h2><p>Roble <b>macizo</b> y <a href="/envios">envío rápido</a>.</p><script>alert(1)</script>',
        'facts' => [['label' => 'Material', 'value' => 'Roble']], 'attributes' => ['peso' => 130],
    ]);
    $web = new TestClient($app);

    $r = $web->get('/producto/silla.md');
    same(200, $r->status);
    same('text/plain; charset=UTF-8', $r->contentType(), 'con .md va como texto plano: el lector de Gemini no abre text/markdown');
    check(!isset($r->headers['Vary']) || stripos((string) $r->headers['Vary'], 'accept') === false, 'y no depende de la cabecera Accept');
    $md = $r->body;
    contains('# Silla de roble', $md);
    contains($app->i18n()->money(1999, 'EUR'), $md, 'el precio sale formateado como en la ficha');
    contains('En stock', $md);
    contains('| Material | Roble |', $md);
    contains('| Peso | 130 |', $md);
    contains('| SKU | SILLA-1 |', $md);
    contains('| GTIN | 8412345678905 |', $md);
    contains('Silla *cómoda*.', $md);
    contains('Roble **macizo** y [envío rápido](/envios)', $md);
    contains('https://tienda.test/producto/silla', $md);
    check(preg_match('#</?[a-z][a-z0-9]*[ >/]#i', $md) !== 1, 'ninguna etiqueta HTML en el texto');
    notContains('alert(1)', $md);
    check($id > 0, 'el producto existe');
});

test('la misma dirección con «Accept: text/markdown» da lo mismo que la dirección con .md', function (): void {
    $app = semanticApp();
    semanticProduct($app);
    $web = new TestClient($app);

    $viaSuffix = $web->get('/producto/silla.md');
    $viaAccept = $web->get('/producto/silla', ['accept' => 'text/markdown']);
    same(200, $viaAccept->status);
    same('text/markdown; charset=UTF-8', $viaAccept->contentType());
    same($viaSuffix->body, $viaAccept->body);
    same('Accept', $viaAccept->headers['Vary']);
    $html = $web->get('/producto/silla', ['accept' => 'text/html']);
    contains('text/html', $html->contentType(), 'sin pedirlo, la dirección sigue dando la página');
});

test('la versión en texto lleva la canónica, no se indexa y se guarda en la caché con las etiquetas de su página', function (): void {
    $app = semanticApp();
    $id = semanticProduct($app);
    $web = new TestClient($app);

    $md = $web->get('/producto/silla.md');
    same('<https://tienda.test/producto/silla>; rel="canonical"', $md->headers['Link']);
    same('noindex', $md->headers['X-Robots-Tag']);
    $html = $web->get('/producto/silla');
    check($md->cacheTtl > 0, 'es cacheable');
    foreach ($html->cacheTags as $tag) {
        check(in_array($tag, $md->cacheTags, true), 'la etiqueta «' . $tag . '» de la página también está en su versión en texto');
    }
    check(in_array('product:' . $id, $md->cacheTags, true), 'lleva la etiqueta del producto');
});

test('un borrador, un producto que no existe y una dirección con .md que no es nada dan 404', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['name' => 'Borrador', 'slug' => 'borrador', 'status' => 'draft']);
    $web = new TestClient($app);

    same(404, $web->get('/producto/borrador.md')->status);
    same(404, $web->get('/producto/no-existe.md')->status);
    same(404, $web->get('/nada-por-aqui.md')->status);
    same(404, $web->get('/.md')->status);
    same(404, $web->get('/admin.md')->status);
    // Con la negociación, una página que no existe sigue su camino de siempre: su 404 normal.
    $r = $web->get('/producto/borrador', ['accept' => 'text/markdown']);
    same(404, $r->status);
    contains('text/html', $r->contentType());
});

test('lo que no es público no tiene versión en texto: el carrito y la cuenta dan 404 con .md', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'orders']);
    $web = new TestClient($app);

    same(200, $web->get('/carrito')->status, 'el carrito existe en HTML');
    same(404, $web->get('/carrito.md')->status);
    same(404, $web->get('/mi-cuenta.md')->status);
    $r = $web->get('/carrito', ['accept' => 'text/markdown']);
    same(200, $r->status, 'con la negociación el carrito sigue siendo el de siempre');
    contains('text/html', $r->contentType());
});

test('la página HTML enlaza su versión en texto con rel="alternate" type="text/markdown"', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms']);
    $sillas = aifecCategory($app, 'Sillas');
    semanticProduct($app, ['categories' => [$sillas]]);
    cmsInsertPage($app, ['title' => 'Aviso legal']);
    $web = new TestClient($app);

    foreach ([
        '/producto/silla' => 'https://tienda.test/producto/silla.md',
        '/categoria/sillas' => 'https://tienda.test/categoria/sillas.md',
        '/aviso-legal' => 'https://tienda.test/aviso-legal.md',
        '/' => 'https://tienda.test/index.md',
        '/blog' => 'https://tienda.test/blog.md',
    ] as $path => $md) {
        contains('<link rel="alternate" type="text/markdown" href="' . $md . '">', $web->get($path)->body, 'la página ' . $path . ' enlaza ' . $md);
        same(200, $web->get(substr($md, strlen('https://tienda.test')))->status, $md . ' existe');
    }
});

test('con variantes, la versión en texto lleva la tabla de variantes con opción, precio y disponibilidad', function (): void {
    $app = semanticApp();
    semanticProduct($app, ['variants' => [
        ['sku' => 'S-R', 'label' => 'Rojo', 'price' => 1999, 'stock' => 3, 'values' => ['color' => 'rojo']],
        ['sku' => 'S-A', 'label' => 'Azul', 'price' => 2199, 'stock' => 0, 'values' => ['color' => 'azul']],
    ]]);
    $web = new TestClient($app);

    $md = $web->get('/producto/silla.md')->body;
    contains('## Variantes', $md);
    contains('| Opción | Precio | Disponibilidad |', $md);
    // En una celda de tabla los espacios se colapsan (también el que no parte línea del precio).
    contains('| Rojo | ' . str_replace("\u{a0}", ' ', $app->i18n()->money(1999, 'EUR')) . ' | Quedan 3 |', $md);
    contains('| Azul | ' . str_replace("\u{a0}", ' ', $app->i18n()->money(2199, 'EUR')) . ' | Agotado |', $md);
    contains('Desde ' . $app->i18n()->money(1999, 'EUR'), $md, 'el precio de la ficha es «desde»');
});

test('las preguntas frecuentes, el envío y las devoluciones salen en la versión en texto del producto', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'shipping'], ['semantic.returns.days' => 14]);
    shippingAddRate($app, shippingAddCarrier($app, 'unico', ['min_days' => 2, 'max_days' => 4]), shippingZoneId($app, 'Peninsula'), 'weight', 0, 0, 495);
    semanticProduct($app, ['faqs' => [['question' => '¿Se monta solo?', 'answer' => '<p>No: <b>hay instrucciones</b>.</p>']]]);
    $web = new TestClient($app);

    $md = $web->get('/producto/silla.md')->body;
    contains('## Preguntas frecuentes', $md);
    contains('### ¿Se monta solo?', $md);
    contains('No: **hay instrucciones**.', $md);
    contains('## Envío y devoluciones', $md);
    contains('**Envío:** desde ' . $app->i18n()->money(495, 'EUR') . ' (de 2 a 4 días)', $md);
    contains('**Devoluciones:** 14 días', $md);
});

test('una categoría en texto lleva su descripción, las subcategorías y sus productos enlazados con precio y disponibilidad', function (): void {
    $app = semanticApp();
    $muebles = aifecCategory($app, 'Muebles', null, ['description' => '<p>Todo para <b>casa</b>.</p>']);
    $sillas = aifecCategory($app, 'Sillas', $muebles);
    aifecProduct($app, ['name' => 'Mesa baja', 'categories' => [$muebles], 'price' => 4500]);
    aifecProduct($app, ['name' => 'Silla alta', 'categories' => [$sillas], 'price' => 2500, 'stock' => 0]);
    $web = new TestClient($app);

    $md = $web->get('/categoria/muebles.md')->body;
    contains('# Muebles', $md);
    contains('Todo para **casa**.', $md);
    contains('## Subcategorías', $md);
    contains('- [Sillas](https://tienda.test/categoria/muebles/sillas.md)', $md);
    contains('- [Mesa baja](https://tienda.test/producto/mesa-baja.md): ' . $app->i18n()->money(4500, 'EUR') . ' · En stock', $md);
    contains('[Silla alta](https://tienda.test/producto/silla-alta.md)', $md, 'los de las subcategorías también salen');
    contains('Agotado', $md);
});

test('una página y una entrada en texto llevan su título y su contenido, y el blog la lista de entradas', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media', 'cms']);
    cmsInsertPage($app, ['title' => 'Aviso legal', 'blocks' => [['type' => 'heading', 'attrs' => ['level' => 2, 'text' => 'Titular']], ['type' => 'paragraph', 'attrs' => ['html' => 'Texto <b>legal</b> completo']]]]);
    cmsInsertPost($app, ['title' => 'Cómo cuidar la madera', 'published_at' => '2026-11-10 09:30:00']);
    $web = new TestClient($app);

    $page = $web->get('/aviso-legal.md');
    same(200, $page->status);
    contains('# Aviso legal', $page->body);
    contains('Texto **legal** completo', $page->body);
    $post = $web->get('/blog/como-cuidar-la-madera.md');
    same(200, $post->status);
    contains('# Cómo cuidar la madera', $post->body);
    contains('**Publicado:** 2026-11-10', $post->body);
    $blog = $web->get('/blog.md');
    same(200, $blog->status);
    contains('- [Cómo cuidar la madera](https://tienda.test/blog/como-cuidar-la-madera.md): 2026-11-10', $blog->body);
});

test('la portada en texto dice qué es la tienda y enlaza categorías y productos, con /index.md y con la negociación', function (): void {
    $app = semanticApp(['semantic', 'catalog', 'media'], ['store.name' => 'Muebles Norte', 'semantic.summary' => 'Muebles de roble hechos a mano.']);
    $sillas = aifecCategory($app, 'Sillas');
    semanticProduct($app, ['categories' => [$sillas]]);
    $web = new TestClient($app);

    $md = $web->get('/index.md')->body;
    contains('# Muebles Norte', $md);
    contains('> Muebles de roble hechos a mano.', $md);
    contains('- [Sillas](https://tienda.test/categoria/sillas.md)', $md);
    contains('- [Silla de roble](https://tienda.test/producto/silla.md)', $md);
    same($md, $web->get('/', ['accept' => 'text/markdown'])->body);
});

test('una dirección antigua del producto redirige con 301 a la versión en texto de la dirección buena', function (): void {
    $app = semanticApp();
    $id = semanticProduct($app);
    $app->slugs()->set('product', $id, 'es', 'producto/silla-nueva');
    $web = new TestClient($app);

    $r = $web->get('/producto/silla.md');
    same(301, $r->status);
    same('/producto/silla-nueva.md', $r->headers['Location']);
    same(200, $web->get('/producto/silla-nueva.md')->status);
});

test('un nombre con barras, asteriscos, corchetes, «<» y barras verticales no rompe la tabla ni el enlace', function (): void {
    $app = semanticApp();
    $sillas = aifecCategory($app, 'Sillas');
    semanticProduct($app, ['name' => 'Silla *pro* [A|B] <x>', 'slug' => 'silla', 'categories' => [$sillas], 'facts' => [['label' => 'Medida | ancho', 'value' => 'a | b']]]);
    $web = new TestClient($app);

    $md = $web->get('/producto/silla.md')->body;
    contains('# Silla \\*pro\\* \\[A\\|B\\] \\<x>', $md);
    contains('| Medida \\| ancho | a \\| b |', $md);
    contains('- [Silla \\*pro\\* \\[A\\|B\\] \\<x>](https://tienda.test/producto/silla.md)', $web->get('/categoria/sillas.md')->body);
});

test('el servicio markdown() compone el texto desde los datos que se le dan, sin pasar por ninguna página', function (): void {
    $app = semanticApp();
    $id = semanticProduct($app);

    $md = $app->service('semantic')->markdown('product', $app->service('catalog')->product($id));
    contains('# Silla de roble', $md);
    same('', $app->service('semantic')->markdown('desconocido', []));
});
