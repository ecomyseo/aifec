<?php
/**
 * AIFEC — pruebas del blog en la tienda: el índice paginado con sus canónicas, la entrada (fecha, autor, minutos de lectura, imagen
 * destacada sin diferir, anterior y siguiente, relacionadas), las categorías y las etiquetas, y el canal de novedades.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/**
 * Monta $n entradas publicadas, la más reciente la última creada. Devuelve sus identificadores en orden de creación (de más
 * antigua a más reciente). Las fechas van de 1 de octubre en adelante, un día por entrada, siempre antes del «ahora» de las pruebas.
 *
 * @param array<string,mixed> $extra se mezcla en los datos de cada entrada
 *
 * @return int[]
 */
function cmsFrontBlogPosts(App $app, int $n, array $extra = []): array
{
    $ids = [];
    for ($i = 1; $i <= $n; ++$i) {
        $ids[] = cmsInsertPost($app, $extra + [
            'title' => sprintf('Entrada %02d', $i),
            'published_at' => sprintf('2026-10-%02d 10:00:00', $i),
        ]);
    }

    return $ids;
}

/** Cuántas tarjetas de entrada hay en un HTML. */
function cmsFrontBlogCards(string $html): int
{
    return substr_count($html, '<article class="af-card af-post-card');
}

test('el índice del blog lista las entradas más recientes primero, con migas, categorías y enlace al canal', function (): void {
    $app = cmsApp();
    $category = cmsTerm($app, 'Salón');
    cmsFrontBlogPosts($app, 3, ['terms' => [$category]]);
    cmsInsertPost($app, ['title' => 'Sin categoría', 'published_at' => '2026-10-20 10:00:00']);
    $r = (new TestClient($app))->get('/blog');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'));
    contains('<h1 class="af-title">Blog</h1>', $r->body);
    same(4, cmsFrontBlogCards($r->body));
    check(strpos($r->body, 'Sin categoría') < strpos($r->body, 'Entrada 03') && strpos($r->body, 'Entrada 03') < strpos($r->body, 'Entrada 01'), 'las más recientes primero');
    contains('<link rel="canonical" href="https://tienda.test/blog">', $r->body);
    contains('Salón (3)', $r->body, 'las categorías con su número de entradas');
    contains('href="/blog/feed.xml"', $r->body);
    contains('<li aria-current="page">Blog</li>', $r->body);
    contains('<meta property="og:type" content="website">', $r->body);
    check(in_array('cms', $r->cacheTags, true) && $r->cacheTtl === 600, 'cacheable');
    same('MISS', $r->headers['X-Cache']);
});

test('el índice del blog se pagina: una sola dirección por página, su canónica, anterior y siguiente, y 404 más allá de la última', function (): void {
    $app = cmsApp(['cms'], ['cms.posts_per_page' => 4]);
    cmsFrontBlogPosts($app, 9);
    $web = new TestClient($app);

    $one = $web->get('/blog');
    same(4, cmsFrontBlogCards($one->body));
    contains('<link rel="canonical" href="https://tienda.test/blog">', $one->body);
    contains('href="/blog?page=2" rel="next"', $one->body);
    notContains('rel="prev"', $one->body);

    $two = $web->get('/blog?page=2');
    same(4, cmsFrontBlogCards($two->body));
    contains('<link rel="canonical" href="https://tienda.test/blog?page=2">', $two->body);
    contains('<title>Blog · Página 2 · ', $two->body);
    contains('href="/blog" rel="prev"', $two->body, 'la primera página vuelve a su única dirección');
    contains('href="/blog?page=3" rel="next"', $two->body);
    check(strpos($two->body, 'Entrada 05') !== false && strpos($two->body, 'Entrada 09') === false, 'la segunda trae las siguientes, no las mismas');

    $three = $web->get('/blog?page=3');
    same(1, cmsFrontBlogCards($three->body));
    contains('Entrada 01', $three->body);
    notContains('rel="next"', $three->body);

    same(404, $web->get('/blog?page=4')->status, 'más allá de la última no es una copia de la última');
    $zero = $web->get('/blog?page=0');
    same(200, $zero->status);
    contains('<link rel="canonical" href="https://tienda.test/blog">', $zero->body, 'una página no válida es la primera');
    same(200, $web->get('/blog?page=abc')->status);
});

test('un blog sin entradas se ve y lo dice; un borrador y una entrada programada no salen y esta aparece a su hora', function (): void {
    $app = cmsApp();
    $web = new TestClient($app);
    $empty = $web->get('/blog');
    same(200, $empty->status);
    contains('Todavía no hay entradas aquí.', $empty->body);
    same(0, cmsFrontBlogCards($empty->body));

    $draft = cmsInsertPost($app, ['title' => 'Borrador', 'status' => 'draft']);
    $soon = cmsInsertPost($app, ['title' => 'Programada', 'status' => 'scheduled', 'published_at' => '2026-11-12 14:00:00']);
    same(404, $web->get('/blog/borrador')->status);
    same(404, $web->get('/blog/programada')->status);
    notContains('Programada', $web->get('/blog')->body);
    notContains('Programada', $web->get('/blog/feed.xml')->body);
    check($draft > 0 && $soon > 0);

    $app->clock()->advance(3 * 3600);
    same(200, $web->get('/blog/programada')->status, 'ya es su hora');
    contains('Programada', $web->get('/blog')->body);
});

test('una entrada se ve con su fecha, su autora, sus minutos de lectura, sus categorías y etiquetas y sus datos de cabecera', function (): void {
    $app = cmsApp();
    $autora = aifecAdmin($app, 'admin', 'lucia@ejemplo.test', 'Lucía Gómez');
    $categoria = cmsTerm($app, 'Salón');
    $etiqueta = cmsTerm($app, 'Roble', 'tag');
    $texto = trim(str_repeat('palabra ', 450));
    $id = cmsInsertPost($app, [
        'title' => 'Cómo cuidar la madera', 'author_id' => $autora, 'published_at' => '2026-10-05 09:00:00',
        'terms' => [$categoria, $etiqueta], 'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => $texto]]],
    ]);
    $r = (new TestClient($app))->get('/blog/como-cuidar-la-madera');
    same(200, $r->status);
    same(1, substr_count($r->body, '<h1'));
    contains('<h1 class="af-title">Cómo cuidar la madera</h1>', $r->body);
    contains('<time datetime="2026-10-05">', $r->body);
    contains('Lucía Gómez', $r->body);
    contains('Lectura de 3 minutos', $r->body);
    contains('<a class="af-badge" href="/blog/categoria/salon">Salón</a>', $r->body);
    contains('rel="tag">#Roble</a>', $r->body);
    contains('<link rel="canonical" href="https://tienda.test/blog/como-cuidar-la-madera">', $r->body);
    contains('<meta property="og:type" content="article">', $r->body);
    contains('<li aria-current="page">Cómo cuidar la madera</li>', $r->body);
    check(strpos($r->body, '>Blog</a>') !== false && strpos($r->body, '>Salón</a>') !== false, 'las migas llevan el blog y su categoría');
    check(in_array('post:' . $id, $r->cacheTags, true) && in_array('cms', $r->cacheTags, true), 'cacheable con su etiqueta');
    same(1, preg_match('#<article class="af-post">.*</article>#s', $r->body), 'el contenido va dentro de un artículo');
});

test('la lectura de una entrada breve es de un minuto, en singular', function (): void {
    $app = cmsApp();
    cmsInsertPost($app, ['title' => 'Breve', 'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => 'Muy poco texto.']]]]);
    contains('Lectura de 1 minuto', (new TestClient($app))->get('/blog/breve')->body);
});

test('la anterior y la siguiente: la del medio enseña las dos, la primera solo la siguiente y la última solo la anterior', function (): void {
    $app = cmsApp();
    $ids = cmsFrontBlogPosts($app, 3);
    cmsInsertPost($app, ['title' => 'Borrador cercano', 'status' => 'draft', 'published_at' => '2026-10-02 12:00:00']);
    $web = new TestClient($app);

    $middle = $web->get('/blog/entrada-02')->body;
    contains('href="/blog/entrada-01" rel="prev"', $middle);
    contains('href="/blog/entrada-03" rel="next"', $middle);
    notContains('Borrador cercano', $middle, 'un borrador no es «la siguiente»');

    $first = $web->get('/blog/entrada-01')->body;
    notContains('rel="prev"', $first);
    contains('href="/blog/entrada-02" rel="next"', $first);

    $last = $web->get('/blog/entrada-03')->body;
    contains('href="/blog/entrada-02" rel="prev"', $last);
    notContains('rel="next"', $last);
    check(count($ids) === 3);
});

test('dos entradas con la misma fecha se ordenan por su identificador y ninguna se salta', function (): void {
    $app = cmsApp();
    foreach (['Uno', 'Dos', 'Tres'] as $title) {
        cmsInsertPost($app, ['title' => $title, 'published_at' => '2026-10-10 10:00:00']);
    }
    $web = new TestClient($app);
    $two = $web->get('/blog/dos')->body;
    contains('href="/blog/uno" rel="prev"', $two);
    contains('href="/blog/tres" rel="next"', $two);
});

test('las relacionadas son de la misma categoría, sin la propia entrada, y una entrada sin categoría no las lleva', function (): void {
    $app = cmsApp();
    $x = cmsTerm($app, 'Cocina');
    $y = cmsTerm($app, 'Jardín');
    cmsFrontBlogPosts($app, 5, ['terms' => [$x]]);
    cmsInsertPost($app, ['title' => 'Entrada del jardín', 'terms' => [$y], 'published_at' => '2026-10-25 10:00:00']);
    cmsInsertPost($app, ['title' => 'Entrada suelta', 'published_at' => '2026-10-26 10:00:00']);
    $web = new TestClient($app);

    $body = $web->get('/blog/entrada-01')->body;
    contains('Entradas relacionadas', $body);
    preg_match('#<section class="af-section" aria-labelledby="af-related-title">.*?</section>#s', $body, $m);
    $related = isset($m[0]) ? $m[0] : '';
    same(3, cmsFrontBlogCards($related), 'tres como mucho');
    notContains('Entrada 01', $related, 'ella misma no');
    notContains('Entrada del jardín', $related, 'ni las de otra categoría');
    contains('Entrada 05', $related, 'las más recientes de su categoría');

    notContains('Entradas relacionadas', $web->get('/blog/entrada-suelta')->body);
    notContains('Entradas relacionadas', $web->get('/blog/entrada-del-jardin')->body, 'sola en su categoría');
});

test('una categoría y una etiqueta listan solo sus entradas, con su título, su canónica y su paginación', function (): void {
    $app = cmsApp(['cms'], ['cms.posts_per_page' => 2]);
    $salon = cmsTerm($app, 'Salón');
    $roble = cmsTerm($app, 'Roble', 'tag');
    cmsFrontBlogPosts($app, 3, ['terms' => [$salon]]);
    cmsInsertPost($app, ['title' => 'Con roble', 'terms' => [$roble], 'published_at' => '2026-10-20 10:00:00']);
    cmsInsertPost($app, ['title' => 'De otra cosa', 'published_at' => '2026-10-21 10:00:00']);
    $web = new TestClient($app);

    $category = $web->get('/blog/categoria/salon');
    same(200, $category->status);
    contains('<h1 class="af-title">Salón</h1>', $category->body);
    same(2, cmsFrontBlogCards($category->body));
    notContains('Con roble', $category->body);
    notContains('De otra cosa', $category->body);
    contains('<link rel="canonical" href="https://tienda.test/blog/categoria/salon">', $category->body);
    contains('href="/blog/categoria/salon?page=2" rel="next"', $category->body);
    contains('<li aria-current="page">Salón</li>', $category->body);
    contains('<meta property="og:type" content="website">', $category->body);

    $second = $web->get('/blog/categoria/salon?page=2');
    same(1, cmsFrontBlogCards($second->body));
    contains('<link rel="canonical" href="https://tienda.test/blog/categoria/salon?page=2">', $second->body);
    same(404, $web->get('/blog/categoria/salon?page=3')->status);

    $tag = $web->get('/blog/etiqueta/roble');
    same(200, $tag->status);
    contains('Con roble', $tag->body);
    same(1, cmsFrontBlogCards($tag->body));
    check(in_array('cms', $tag->cacheTags, true));
    same(404, $web->get('/blog/categoria/no-existe')->status);
});

test('la imagen destacada de una entrada se pinta sin diferir y antes del contenido', function (): void {
    $app = cmsApp();
    $media = aifecMedia($app, 'Mesa de roble');
    cmsInsertPost($app, ['title' => 'Con portada', 'media_id' => $media, 'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => 'Texto de la entrada']]]]);
    $r = (new TestClient($app))->get('/blog/con-portada');
    same(200, $r->status);
    same(1, preg_match('#<figure class="af-post-image">\s*(<img[^>]*>)#', $r->body, $m), 'la figura de la imagen destacada');
    contains('loading="eager"', $m[1]);
    contains('fetchpriority="high"', $m[1]);
    notContains('loading="lazy"', $m[1]);
    contains('alt="Con portada"', $m[1], 'su texto alternativo es el título de la entrada');
    $article = (string) substr($r->body, (int) strpos($r->body, '<article class="af-post">'));
    check(strpos($article, 'af-post-image') < strpos($article, 'Texto de la entrada'), 'antes del contenido');
    contains('<meta property="og:image" content="https://tienda.test/', $r->body, 'y la comparte con dirección completa');
});

test('sin imagen elegida, la primera imagen del contenido se pinta sin diferir y las demás siguen diferidas', function (): void {
    $app = cmsApp();
    $one = aifecMedia($app, 'Primera');
    $two = aifecMedia($app, 'Segunda');
    cmsInsertPost($app, ['title' => 'Con fotos', 'blocks' => [
        ['type' => 'paragraph', 'attrs' => ['html' => 'Antes']],
        ['type' => 'image', 'attrs' => ['media_id' => $one]],
        ['type' => 'image', 'attrs' => ['media_id' => $two]],
    ]]);
    $body = (new TestClient($app))->get('/blog/con-fotos')->body;
    notContains('af-post-image', $body, 'no hay figura aparte: la imagen ya está en el contenido');
    preg_match_all('#<img[^>]*>#', $body, $all);
    $images = $all[0];
    same(2, count($images));
    contains('loading="eager"', $images[0]);
    contains('fetchpriority="high"', $images[0]);
    contains('loading="lazy"', $images[1]);
    notContains('fetchpriority', $images[1]);
});

test('el canal de novedades es un Atom que se lee como XML: sus 20 últimas entradas, con fechas estándar y sin guiones', function (): void {
    $app = cmsApp();
    cmsFrontBlogPosts($app, 25);
    cmsInsertPost($app, ['title' => 'Café & té <con> "comillas"', 'published_at' => '2026-10-28 10:00:00', 'blocks' => [
        ['type' => 'paragraph', 'attrs' => ['html' => 'Texto <script>alert(1)</script> con <a href="/blog/entrada-01" onclick="x()">enlace</a>']],
    ]]);
    cmsInsertPost($app, ['title' => 'Borrador del canal', 'status' => 'draft']);
    $web = new TestClient($app);
    $r = $web->get('/blog/feed.xml');
    same(200, $r->status);
    same('application/atom+xml; charset=UTF-8', $r->headers['Content-Type']);
    check($r->cacheTtl === 600 && in_array('cms', $r->cacheTags, true), 'cacheable');
    notContains('<script', $r->body);
    notContains('alert(1)', $r->body);
    notContains('onclick', $r->body);
    notContains('Borrador del canal', $r->body);

    $xml = simplexml_load_string($r->body);
    check($xml !== false, 'es XML bien formado');
    same(20, count($xml->entry), 'las 20 últimas');
    same('Café & té <con> "comillas"', (string) $xml->entry[0]->title, 'la última es la más reciente y su título vuelve tal cual');
    same('Entrada 25', (string) $xml->entry[1]->title);
    foreach ($xml->entry as $entry) {
        same(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $entry->published), 'fecha estándar');
        same(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $entry->updated));
        check(strpos((string) $entry->id, 'https://tienda.test/blog/') === 0, 'dirección completa: ' . $entry->id);
    }
    check(strpos((string) $xml->entry[0]->content, 'href="https://tienda.test/blog/entrada-01"') !== false, 'los enlaces del contenido, completos');
    check(strpos((string) $xml->entry[0]->content, 'enlace') !== false, 'con el contenido de la entrada');
    same('https://tienda.test/blog', (string) $xml->id);
    same(1, preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', (string) $xml->updated));
});

test('un canal sin entradas también es un XML válido', function (): void {
    $app = cmsApp();
    $r = (new TestClient($app))->get('/blog/feed.xml');
    same(200, $r->status);
    $xml = simplexml_load_string($r->body);
    check($xml !== false);
    same(0, count($xml->entry));
});

test('la palabra del blog sale del ajuste: con «noticias» el índice y el canal viven ahí y /blog ya no existe', function (): void {
    $app = cmsApp(['cms'], ['urls.post' => 'noticias']);
    $web = new TestClient($app);
    same(200, $web->get('/noticias')->status);
    same(200, $web->get('/noticias/feed.xml')->status);
    same(404, $web->get('/blog')->status);
    contains('<link rel="canonical" href="https://tienda.test/noticias">', $web->get('/noticias')->body);
});

test('el blog en inglés: el índice, el canal y la entrada con su título traducido', function (): void {
    $app = cmsApp();
    cmsInsertPost($app, ['title' => 'Cómo cuidar la madera', 'en' => 'How to care for wood']);
    $web = new TestClient($app);
    $index = $web->get('/en/blog');
    same(200, $index->status);
    contains('How to care for wood', $index->body);
    contains('<link rel="canonical" href="https://tienda.test/en/blog">', $index->body);
    $post = $web->get('/en/blog/how-to-care-for-wood');
    same(200, $post->status);
    contains('<h1 class="af-title">How to care for wood</h1>', $post->body);
    contains('1 minute read', $post->body);
    $xml = simplexml_load_string($web->get('/en/blog/feed.xml')->body);
    check($xml !== false);
    same('How to care for wood', (string) $xml->entry[0]->title);
});

test('el título, el autor y el extracto de una entrada salen escapados en la tarjeta y en la entrada', function (): void {
    $app = cmsApp();
    $autor = aifecAdmin($app, 'admin', 'ines@ejemplo.test', 'Inés <i>M</i> & Co');
    cmsInsertPost($app, ['title' => 'Uno & <u>dos</u>', 'author_id' => $autor, 'excerpt' => 'Extracto con <b>negrita</b> & más', 'slug' => 'uno-dos',
        'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => 'Cuerpo']]]]);
    $web = new TestClient($app);
    $list = $web->get('/blog')->body;
    contains('Uno &amp; &lt;u&gt;dos&lt;/u&gt;', $list);
    contains('Extracto con &lt;b&gt;negrita&lt;/b&gt; &amp; más', $list);
    $post = $web->get('/blog/uno-dos')->body;
    contains('Inés &lt;i&gt;M&lt;/i&gt; &amp; Co', $post);
    notContains('<u>dos</u>', $post);
    notContains('<i>M</i>', $post);
});
