<?php
/**
 * AIFEC — pruebas del blog: entradas con categorías y etiquetas, listados con filtros y paginación, imagen destacada, autor y
 * minutos de lectura, términos, programación y las palabras de las direcciones.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Cms\Cms;

require_once __DIR__ . '/helpers.php';

function cmsBlog(App $app): Cms
{
    $cms = $app->service('cms');
    check($cms instanceof Cms, 'el servicio cms es el del módulo');

    return $cms;
}

/** Cinco entradas con fechas distintas: la 1 es la más antigua y la 5 la más nueva. */
function cmsFiveFixtures(App $app): array
{
    $categoryId = cmsTerm($app, 'Cuidados');
    $tagId = cmsTerm($app, 'Roble', 'tag');
    $ids = [];
    for ($i = 1; $i <= 5; ++$i) {
        $terms = [];
        if ($i <= 3) {
            $terms[] = $categoryId;
        }
        if ($i === 2 || $i === 4) {
            $terms[] = $tagId;
        }
        $ids[$i] = cmsInsertPost($app, ['title' => 'Entrada ' . $i, 'published_at' => '2026-11-0' . $i . ' 10:00:00', 'terms' => $terms]);
    }

    return ['posts' => $ids, 'category' => $categoryId, 'tag' => $tagId];
}

/**
 * @param array<string,mixed> $result
 *
 * @return string[]
 */
function cmsPostTitles(array $result): array
{
    return array_map(static function (array $post): string {
        return $post['title'];
    }, $result['items']);
}

test('una entrada con categorías y etiquetas por el panel: crea las etiquetas nuevas y no repite las que ya hay', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $cat = cmsTerm($app, 'Cuidados');
    $r = cmsSavePost($web, ['status' => 'published', 'categories' => [(string) $cat], 'tags' => 'roble, cocina, Roble']);
    same(302, $r->status);
    $id = cmsLastId($app, 'posts');

    $post = cmsBlog($app)->post($id);
    check($post !== null, 'la entrada publicada se ve');
    same(['Cuidados'], array_map(static function (array $c): string {
        return $c['name'];
    }, $post['categories']));
    same(['roble', 'cocina'], array_map(static function (array $t): string {
        return $t['name'];
    }, $post['tags']), 'Roble y roble son la misma etiqueta');
    same(3, (int) $app->db()->value('SELECT COUNT(*) FROM {terms}'), 'la categoría y las dos etiquetas');
    contains('/blog/como-cuidar-la-madera', $post['url']);
    contains('/blog/etiqueta/roble', $post['tags'][0]['url']);
    contains('/blog/categoria/cuidados', $post['categories'][0]['url']);

    // Al volver a abrirla en el panel salen marcadas y escritas.
    $screen = $web->get('/admin/r/posts/' . $id)->body;
    contains('value="roble, cocina"', $screen);
    check((bool) preg_match('/value="' . $cat . '"[^>]*checked/', $screen), 'la categoría sale marcada');
});

test('quitar una categoría y una etiqueta al guardar las desengancha', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    $cat = cmsTerm($app, 'Cuidados');
    cmsSavePost($web, ['status' => 'published', 'categories' => [(string) $cat], 'tags' => 'roble']);
    $id = cmsLastId($app, 'posts');
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {post_terms} WHERE post_id = ?', [$id]));
    cmsSavePost($web, ['status' => 'published', 'categories' => [], 'tags' => ''], $id);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {post_terms} WHERE post_id = ?', [$id]));
    $post = cmsBlog($app)->post($id);
    same([], $post['categories']);
    same([], $post['tags']);
});

test('posts() devuelve las más recientes primero, con total, página y número de páginas', function (): void {
    $app = cmsApp();
    cmsFiveFixtures($app);
    $cms = cmsBlog($app);
    $all = $cms->posts([]);
    same(5, $all['total']);
    same(1, $all['pages']);
    same(10, $all['per_page']);
    same(['Entrada 5', 'Entrada 4', 'Entrada 3', 'Entrada 2', 'Entrada 1'], cmsPostTitles($all));

    $first = $cms->posts(['per_page' => 2]);
    same(['Entrada 5', 'Entrada 4'], cmsPostTitles($first));
    same(5, $first['total']);
    same(3, $first['pages']);
    same(1, $first['page']);
    same(['Entrada 3', 'Entrada 2'], cmsPostTitles($cms->posts(['per_page' => 2, 'page' => 2])));
    $last = $cms->posts(['per_page' => 2, 'page' => 3]);
    same(['Entrada 1'], cmsPostTitles($last));
    same(3, $cms->posts(['per_page' => 2, 'page' => 99])['page'], 'una página que no existe se queda en la última');
    same(1, $cms->posts(['per_page' => 2, 'page' => -4])['page']);
    same(100, $cms->posts(['per_page' => 5000])['per_page'], 'el tope por página');
});

test('posts() filtra por categoría, por etiqueta (identificador o dirección) y por texto', function (): void {
    $app = cmsApp();
    $fx = cmsFiveFixtures($app);
    $cms = cmsBlog($app);
    same(['Entrada 3', 'Entrada 2', 'Entrada 1'], cmsPostTitles($cms->posts(['term_id' => $fx['category']])));
    same(['Entrada 4', 'Entrada 2'], cmsPostTitles($cms->posts(['tag' => $fx['tag']])));
    same(['Entrada 4', 'Entrada 2'], cmsPostTitles($cms->posts(['tag' => 'roble'])), 'por la última parte de su dirección');
    same(['Entrada 2'], cmsPostTitles($cms->posts(['tag' => 'roble', 'term_id' => $fx['category']])), 'los dos filtros a la vez');
    same([], cmsPostTitles($cms->posts(['tag' => 'no-existe'])), 'una etiqueta que no existe no deja ninguna entrada');
    same(['Entrada 3'], cmsPostTitles($cms->posts(['q' => 'entrada 3'])));
    same(5, $cms->posts(['q' => 'Entrada'])['total']);
    same(0, $cms->posts(['q' => '%'])['total'], 'el % no es un comodín');
    same(0, $cms->posts(['q' => '_'])['total'], 'el _ tampoco');
    same(0, $cms->posts(['term_id' => 99999])['total']);
});

test('el ajuste de entradas por página manda cuando no se pide otro número', function (): void {
    $app = cmsApp(['cms'], ['cms.posts_per_page' => 2]);
    cmsFiveFixtures($app);
    same(2, count(cmsBlog($app)->posts([])['items']));
    same(3, cmsBlog($app)->posts([])['pages']);
    same(4, count(cmsBlog($app)->posts(['per_page' => 4])['items']));
});

test('un borrador y una entrada programada para más tarde no salen en el listado hasta que llega su hora', function (): void {
    $app = cmsApp();
    cmsInsertPost($app, ['title' => 'Publicada', 'published_at' => '2026-11-01 10:00:00']);
    cmsInsertPost($app, ['title' => 'Borrador', 'status' => 'draft']);
    $later = cmsInsertPost($app, ['title' => 'Programada', 'status' => 'scheduled', 'published_at' => '2026-11-12 13:00:00']);
    $cms = cmsBlog($app);
    same(['Publicada'], cmsPostTitles($cms->posts([])));
    same(null, $cms->post($later));
    same(1, $cms->posts([])['total']);
    $app->clock()->advance(3700);
    same(['Programada', 'Publicada'], cmsPostTitles($cms->posts([])), 'a su hora entra, la primera por ser la más nueva');
    check($cms->post($later) !== null, 'y la ficha se ve');
});

test('la entrada trae autor, minutos de lectura e imagen destacada de la biblioteca', function (): void {
    $app = cmsApp(['cms']);
    $author = aifecAdmin($app, 'editor', 'autora@tienda.test', 'Lucía Pérez');
    $media = aifecMedia($app, 'Mesa de roble');
    $words = trim(str_repeat('palabra ', 450));
    $id = cmsInsertPost($app, ['title' => 'Larga', 'author_id' => $author, 'media_id' => $media, 'blocks' => [['type' => 'paragraph', 'attrs' => ['html' => $words]]]]);
    $post = cmsBlog($app)->post($id);
    same('Lucía Pérez', $post['author']);
    same($author, $post['author_id']);
    same(3, $post['reading_minutes']);
    check(is_array($post['image']), 'hay imagen');
    same($media, $post['image']['id']);
    contains('2026/11/foto-', $post['image']['url']);
    same(1, cmsBlog($app)->post(cmsInsertPost($app, ['title' => 'Corta']))['reading_minutes'], 'como mínimo un minuto');
});

test('sin imagen elegida, la destacada es la primera de su contenido', function (): void {
    $app = cmsApp(['cms']);
    $media = aifecMedia($app, 'Primera');
    $id = cmsInsertPost($app, ['title' => 'Con imagen dentro', 'blocks' => [
        ['type' => 'paragraph', 'attrs' => ['html' => 'Antes']],
        ['type' => 'image', 'attrs' => ['media_id' => $media, 'alt' => 'Primera']],
    ]]);
    $post = cmsBlog($app)->post($id);
    check(is_array($post['image']), 'se toma la del contenido');
    same($media, $post['image']['id']);
    same(null, cmsBlog($app)->post(cmsInsertPost($app, ['title' => 'Sin nada']))['image']);
});

test('terms() da las categorías y las etiquetas por separado, con su número de entradas visibles y su traducción', function (): void {
    $app = cmsApp();
    $a = cmsTerm($app, 'Cuidados', 'category', 'Care');
    $b = cmsTerm($app, 'Ideas', 'category');
    cmsTerm($app, 'Roble', 'tag');
    cmsInsertPost($app, ['title' => 'Uno', 'terms' => [$a]]);
    cmsInsertPost($app, ['title' => 'Dos', 'terms' => [$a, $b]]);
    cmsInsertPost($app, ['title' => 'Borrador', 'status' => 'draft', 'terms' => [$b]]);
    $cms = cmsBlog($app);

    $categories = $cms->terms('category');
    same(['Cuidados', 'Ideas'], array_map(static function (array $t): string {
        return $t['name'];
    }, $categories));
    same([2, 1], array_map(static function (array $t): int {
        return $t['count'];
    }, $categories), 'el borrador no cuenta');
    same(['Roble'], array_map(static function (array $t): string {
        return $t['name'];
    }, $cms->terms('tag')));
    same('Care', $cms->terms('category', 'en')[0]['name']);
    same('Ideas', $cms->terms('category', 'en')[1]['name'], 'sin traducir, el nombre principal');
    contains('/en/blog/categoria/care', $cms->terms('category', 'en')[0]['url']);
    foreach (['id', 'name', 'description', 'url', 'parent_id', 'count'] as $key) {
        check(array_key_exists($key, $categories[0]), 'falta la clave ' . $key);
    }
});

test('las categorías del blog se crean por el panel con su dirección y no se borran si tienen subcategorías', function (): void {
    $app = cmsApp();
    cmsFakeFront($app);
    $web = adminClient($app);
    $web->get('/admin/r/post_categories/new');
    $r = $web->post('/admin/r/post_categories/save', ['_token' => aifecToken($web), 'f' => [
        'name' => ['es' => 'Cuidados & mimos', 'en' => 'Care'], 'slug' => ['es' => '', 'en' => ''], 'description' => ['es' => 'Todo sobre <b>cuidados</b>', 'en' => ''], 'position' => '0',
    ]]);
    same(302, $r->status);
    $id = cmsLastId($app, 'terms');
    same('category', (string) $app->db()->value('SELECT taxonomy FROM {terms} WHERE id = ?', [$id]));
    same('blog/categoria/cuidados-mimos', $app->slugs()->path('term', $id, 'es'));
    same('blog/categoria/care', $app->slugs()->path('term', $id, 'en'));
    same(200, $web->get('/blog/categoria/cuidados-mimos')->status);

    $web->get('/admin/r/post_categories/new');
    $web->post('/admin/r/post_categories/save', ['_token' => aifecToken($web), 'f' => [
        'name' => ['es' => 'Hija', 'en' => ''], 'slug' => ['es' => '', 'en' => ''], 'description' => ['es' => '', 'en' => ''], 'position' => '1', 'parent_id' => (string) $id,
    ]]);
    $child = cmsLastId($app, 'terms');
    same((string) $id, (string) $app->db()->value('SELECT parent_id FROM {terms} WHERE id = ?', [$child]));
    $web->get('/admin/r/post_categories/' . $id);
    $web->post('/admin/r/post_categories/' . $id . '/delete', ['_token' => aifecToken($web)]);
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {terms} WHERE id = ?', [$id]), 'con subcategorías no se borra');
    $web->get('/admin/r/post_categories/' . $id);
    same(422, $web->post('/admin/r/post_categories/' . $id . '/save', ['_token' => aifecToken($web), 'f' => [
        'name' => ['es' => 'Cuidados', 'en' => ''], 'slug' => ['es' => '', 'en' => ''], 'description' => ['es' => '', 'en' => ''], 'position' => '0', 'parent_id' => (string) $child,
    ]])->status, 'una categoría no puede colgar de su propia hija');

    $list = $web->get('/admin/r/post_categories')->body;
    contains('Cuidados &amp; mimos', $list);
});

test('borrar una etiqueta la quita de las entradas', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePost($web, ['status' => 'published', 'tags' => 'roble']);
    $post = cmsLastId($app, 'posts');
    $tag = cmsLastId($app, 'terms');
    $web->get('/admin/r/post_tags/' . $tag);
    $r = $web->post('/admin/r/post_tags/' . $tag . '/delete', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {post_terms} WHERE post_id = ?', [$post]));
    same([], cmsBlog($app)->post($post)['tags']);
});

test('con otra palabra para el blog, las direcciones nuevas la usan y las existentes se rehacen redirigiendo', function (): void {
    $app = cmsApp();
    cmsFakeFront($app);
    $web = adminClient($app);
    cmsSavePost($web, ['status' => 'published', 'slug' => ['es' => 'madera', 'en' => '']]);
    $id = cmsLastId($app, 'posts');
    same('blog/madera', $app->slugs()->path('post', $id, 'es'));
    same(200, $web->get('/blog/madera')->status);

    $app->settings()->set('urls.post', 'novedades');
    $app->hooks()->fire('settings.saved', 'cms', ['urls.post'], $app);
    same('novedades/madera', $app->slugs()->path('post', $id, 'es'));
    $old = $web->get('/blog/madera');
    same(301, $old->status);
    contains('/novedades/madera', $old->headers['Location']);
    same(200, $web->get('/novedades/madera')->status);
    contains('/novedades/madera', cmsBlog($app)->post($id)['url']);
});

test('una entrada nueva usa la palabra del blog que haya en los ajustes', function (): void {
    $app = cmsApp(['cms'], ['urls.post' => 'noticias']);
    $web = adminClient($app);
    cmsSavePost($web, ['status' => 'published']);
    $id = cmsLastId($app, 'posts');
    same('noticias/como-cuidar-la-madera', $app->slugs()->path('post', $id, 'es'));
});
