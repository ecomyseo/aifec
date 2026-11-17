<?php
/**
 * AIFEC — escritores de páginas, entradas y categorías del blog: crean como el panel (textos por idioma, bloques, dirección, versión inicial),
 * convierten los bloques de WordPress y sanean el HTML, traen las imágenes del contenido y reescriben su dirección, cuentan los códigos cortos,
 * marcan la página legal si el hueco está vacío, responden en la dirección antigua, enlazan padres y categorías, repiten sin cambios, chocan con
 * lo editado aquí y no escriben nada en un simulacro ni avisan a nadie.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Http\HttpResponse;
use Aifec\Kernel\App;
use Aifec\Modules\Import\TypeWriter;
use Aifec\Modules\Import\Writers\PagesWriter;
use Aifec\Modules\Import\Writers\PostCategoriesWriter;
use Aifec\Modules\Import\Writers\PostsWriter;

require_once __DIR__ . '/WritersKit2.php';

/** Una página neutra: la de ejemplo con cambios. */
function pwPage(array $over = []): array
{
    if (array_key_exists('legal', $over) && $over['legal'] === null) {
        // «legal» no admite null en el formato neutro: una página que no es legal no lo trae.
        $record = wr('pages', array_diff_key($over, ['legal' => true]));
        unset($record['legal']);

        return $record;
    }

    return wr('pages', $over);
}

/** Una entrada neutra: la de ejemplo con cambios. */
function pwPost(array $over = []): array
{
    return wr('posts', $over);
}

/** Una categoría del blog neutra: la de ejemplo con cambios. */
function pwTerm(array $over = []): array
{
    return wr('post_categories', $over);
}

/** Los bloques guardados de una página o entrada, en un idioma. */
function pwBlocks(App $app, string $table, string $key, int $id, string $lang = 'es'): array
{
    $json = $app->db()->value('SELECT blocks FROM {' . $table . '} WHERE ' . $key . ' = ? AND lang = ?', [$id, $lang]);

    return is_string($json) ? (array) json_decode($json, true) : [];
}

/** Una imagen PNG lista para que la devuelva el cliente HTTP de pruebas. */
function pwPngResponse(int $seed = 1): HttpResponse
{
    $dir = tmpDir();

    return new HttpResponse(200, ['content-type' => 'image/png'], (string) file_get_contents(wrPng($dir, 'x.png', $seed)));
}

pwTest('la página de ejemplo se escribe como la deja el panel: textos, bloques, dirección, versión y caché', function (): void {
    [$app, $transport] = pwApp();
    $ctx = writersContext($app);
    $out = (new PagesWriter($app))->write(pwPage(['old_paths' => []]), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $id = (int) $out['id'];

    $row = $app->db()->find('pages', ['id' => $id]);
    same(['published', 'default', 0, 0, 0, '2024-01-10 09:00:00'], [$row['status'], $row['template'], (int) $row['position'], (int) $row['noindex'], (int) $row['is_home'], $row['published_at']], 'con la fecha del origen');
    $text = $app->db()->row('SELECT * FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, 'es']);
    same('Política de privacidad', $text['title']);
    $blocks = pwBlocks($app, 'page_i18n', 'page_id', $id);
    same(1, count($blocks));
    same('html', $blocks[0]['type']);
    contains('<h2>Quién trata tus datos</h2>', $blocks[0]['attrs']['html']);
    contains('Ñandú Muebles S.L.', $blocks[0]['attrs']['html']);
    same('politica-de-privacidad', $app->slugs()->path('page', $id, 'es'), 'la dirección sale del «slug» del origen');
    same(1, wrCount($app, 'revisions', "entity = 'page' AND entity_id = ?", [$id]), 'con su primera versión');

    $page = $app->service('cms')->page($id, 'es', true);
    same('Política de privacidad', $page['title']);
    contains('Quién trata tus datos', $page['text']);
    contains('<h2>Quién trata tus datos</h2>', $page['html']);
    pwSilent($app, $transport, 'tras escribir una página');
});

pwTest('con keep_urls la ruta antigua responde con la página; sin ella, la antigua redirige a la nueva', function (): void {
    [$app] = pwApp();
    $id = (int) (new PagesWriter($app))->write(pwPage(), writersContext($app))['id'];
    same('content/7-politica-de-privacidad', $app->slugs()->path('page', $id, 'es'), 'la antigua es la de ahora');
    $web = new TestClient($app);
    $old = $web->get('/content/7-politica-de-privacidad');
    same(200, $old->status, 'la ruta antigua responde');
    contains('Política de privacidad', $old->body);
    $new = $app->slugs()->resolve('es', 'politica-de-privacidad');
    same(['page', false], [$new['type'], $new['canonical']], 'y la nueva sigue resolviendo, redirigida a la antigua');

    [$other] = pwApp();
    $oid = (int) (new PagesWriter($other))->write(pwPage(), writersContext($other, ['keep_urls' => false]))['id'];
    same('politica-de-privacidad', $other->slugs()->path('page', $oid, 'es'));
    same('/politica-de-privacidad', wrValue($other, "SELECT target FROM {redirects} WHERE source = 'content/7-politica-de-privacidad'"));
    same(200, (new TestClient($other))->get('/politica-de-privacidad')->status);
});

pwTest('el HTML se sanea: nada de scripts, manejadores de eventos ni marcos de otro sitio', function (): void {
    [$app] = pwApp();
    $html = '<p onclick="x()">Hola <b>mundo</b> &amp; todos</p><script>alert(1)</script><iframe src="https://malo.test/x"></iframe><a href="javascript:alert(2)">clic</a>';
    $id = (int) (new PagesWriter($app))->write(pwPage(['old_paths' => [], 'content' => ['es' => $html]]), writersContext($app))['id'];
    $json = (string) wrValue($app, 'SELECT blocks FROM {page_i18n} WHERE page_id = ?', [$id]);
    foreach (['<script', 'alert(1)', 'onclick', 'malo.test', 'javascript:'] as $bad) {
        notContains($bad, $json, 'sin ' . $bad);
    }
    contains('mundo', $json);
});

pwTest('los bloques de WordPress se convierten en bloques propios y los códigos cortos que quedan se cuentan por nombre', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $content = "<!-- wp:heading -->\n<h2 class=\"wp-block-heading\">Cuidados</h2>\n<!-- /wp:heading -->\n\n<!-- wp:paragraph -->\n<p>Un paño húmedo.</p>\n<!-- /wp:paragraph -->\n\n"
        . "<!-- wp:shortcode -->\n[gallery ids=\"1,2\"]\n<!-- /wp:shortcode -->\n\n<!-- wp:paragraph -->\n<p>Escríbenos [contact-form-7 id=\"5\"] ya.</p>\n<!-- /wp:paragraph -->";
    $id = (int) (new PagesWriter($app))->write(pwPage(['old_paths' => [], 'content_format' => 'gutenberg', 'content' => ['es' => $content]]), $ctx)['id'];
    $blocks = pwBlocks($app, 'page_i18n', 'page_id', $id);
    same('heading', $blocks[0]['type']);
    same('paragraph', $blocks[1]['type']);
    same('Cuidados', $blocks[0]['attrs']['html']);
    same(1, $ctx->unmappedCount('shortcode', 'gallery'));
    same(1, $ctx->unmappedCount('shortcode', 'contact-form-7'));
    same(2, count(array_filter($ctx->unmapped, static function (array $u): bool {
        return $u['kind'] === 'shortcode';
    })), 'uno por nombre');
});

pwTest('las imágenes del contenido que eran de la tienda antigua se traen y se reescribe su dirección; las que fallan se quedan apuntando al origen y se apuntan', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app, ['source_host' => 'tienda.test']);
    $app->http()->queue(pwPngResponse(2));
    $app->http()->queue(new HttpResponse(404, [], 'no hay'));
    $content = '<p><img src="https://tienda.test/wp-content/uploads/2026/03/taller.png" alt="Taller"><img src="https://otro.test/foto.png" alt="Ajena">'
        . '<img src="/wp-content/uploads/2026/03/rota.png" alt="Rota"></p>';
    $id = (int) (new PagesWriter($app))->write(pwPage(['old_paths' => [], 'content' => ['es' => $content]]), $ctx)['id'];
    $json = (string) wrValue($app, 'SELECT blocks FROM {page_i18n} WHERE page_id = ?', [$id]);
    $html = stripslashes($json);

    same(1, wrCount($app, 'media'), 'solo se trajo la que se pudo');
    $url = $app->service('media')->url((int) wrValue($app, 'SELECT id FROM {media}'));
    check($url !== '', 'tiene dirección en la biblioteca');
    contains($url, $html, 'la dirección de aquí');
    notContains('https://tienda.test/wp-content/uploads/2026/03/taller.png', $html, 'ya no apunta al origen');
    contains('https://otro.test/foto.png', $html, 'una de otro sitio no se toca');
    contains('https://tienda.test/wp-content/uploads/2026/03/rota.png', $html, 'la que falló queda con la dirección completa del origen');
    contains('still points to the old shop', implode(' ', $ctx->noteTexts('pages')));

    // Volver a escribir la misma página con otro texto no baja otra vez la imagen.
    $app->http()->queue(new HttpResponse(404, [], 'no hay'));
    (new PagesWriter($app))->write(pwPage(['old_paths' => [], 'content' => ['es' => $content . '<p>más</p>']]), $ctx);
    same(1, wrCount($app, 'media'), 'sin duplicar');
});

pwTest('sin saber cuál es la tienda antigua, o con import_images apagado, el contenido se deja como está', function (): void {
    [$app] = pwApp();
    $content = '<p><img src="https://tienda.test/wp-content/uploads/a.png" alt="A"></p>';
    $app->http()->queue(pwPngResponse(3));
    (new PagesWriter($app))->write(pwPage(['old_paths' => [], 'content' => ['es' => $content]]), writersContext($app));
    same(0, wrCount($app, 'media'), 'sin dominio de origen no se baja nada');
    $off = writersContext($app, ['source_host' => 'tienda.test', 'import_images' => false]);
    (new PagesWriter($app))->write(pwPage(['id' => '8', 'slug' => ['es' => 'otra'], 'old_paths' => [], 'content' => ['es' => $content]]), $off);
    same(0, wrCount($app, 'media'), 'con import_images apagado tampoco');
});

pwTest('repetir la página no cambia ninguna tabla; cambiarla la actualiza; editada aquí choca; el simulacro no escribe', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new PagesWriter($app);
    $record = pwPage(['old_paths' => []]);
    $id = (int) $writer->write($record, $ctx)['id'];
    $counts = wrCounts($app);

    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    same($counts, wrCounts($app), 'ni una fila nueva');
    $changed = $writer->write(pwPage(['old_paths' => [], 'title' => ['es' => 'Privacidad'], 'content' => ['es' => '<p>Nuevo texto</p>']]), $ctx);
    same(TypeWriter::UPDATED, $changed['result']);
    same('Privacidad', wrValue($app, 'SELECT title FROM {page_i18n} WHERE page_id = ?', [$id]));
    contains('Nuevo texto', (string) wrValue($app, 'SELECT blocks FROM {page_i18n} WHERE page_id = ?', [$id]));
    same(wrCount($app, 'pages'), 1);
    same(wrCount($app, 'page_i18n'), 1, 'se actualiza en su sitio');

    $dry = writersContext($app)->dry(true);
    $before = wrCounts($app);
    same(TypeWriter::CREATED, $writer->write(pwPage(['id' => '8', 'slug' => ['es' => 'otra'], 'old_paths' => []]), $dry)['result']);
    same(TypeWriter::UPDATED, $writer->write(pwPage(['old_paths' => [], 'title' => ['es' => 'Otra vez']]), $dry)['result']);
    same($before, wrCounts($app), 'el simulacro no cambia nada');

    $app->clock()->advance(300);
    $app->db()->update('pages', ['noindex' => 1, 'updated_at' => $app->clock()->sql()], ['id' => $id]);
    $app->clock()->advance(300);
    same(TypeWriter::CONFLICT, $writer->write(pwPage(['old_paths' => [], 'title' => ['es' => 'Otra vez']]), $ctx)['result']);
    same('Privacidad', wrValue($app, 'SELECT title FROM {page_i18n} WHERE page_id = ?', [$id]), 'lo de aquí queda intacto');
    same(TypeWriter::UPDATED, $writer->write(pwPage(['old_paths' => [], 'title' => ['es' => 'Otra vez']]), writersContext($app, ['overwrite_local' => true]))['result']);
});

pwTest('una página que ya existe aquí con esa dirección se enlaza en vez de duplicarse', function (): void {
    [$app] = pwApp();
    $now = $app->clock()->sql();
    $mine = $app->db()->insert('pages', ['status' => 'published', 'template' => 'default', 'position' => 0, 'noindex' => 0, 'is_home' => 0, 'published_at' => $now, 'created_at' => $now, 'updated_at' => $now]);
    $app->db()->insert('page_i18n', ['page_id' => $mine, 'lang' => 'es', 'title' => 'Mi privacidad', 'blocks' => '[]']);
    $app->slugs()->set('page', $mine, 'es', 'politica-de-privacidad');
    $out = (new PagesWriter($app))->write(pwPage(['old_paths' => []]), writersContext($app));
    same(TypeWriter::UPDATED, $out['result'], 'casar por lo natural es una actualización');
    same($mine, $out['id']);
    same(1, wrCount($app, 'pages'));
    same('Política de privacidad', wrValue($app, 'SELECT title FROM {page_i18n} WHERE page_id = ?', [$mine]));
});

pwTest('la página madre se enlaza en la segunda pasada, y un círculo o una madre que no vino la deja en el primer nivel', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new PagesWriter($app);
    $a = pwPage(['id' => 'a', 'slug' => ['es' => 'a'], 'title' => ['es' => 'A'], 'old_paths' => [], 'legal' => null, 'parent_id' => 'b']);
    $b = pwPage(['id' => 'b', 'slug' => ['es' => 'b'], 'title' => ['es' => 'B'], 'old_paths' => [], 'legal' => null, 'parent_id' => 'a']);
    $c = pwPage(['id' => 'c', 'slug' => ['es' => 'c'], 'title' => ['es' => 'C'], 'old_paths' => [], 'legal' => null, 'parent_id' => 'zzz']);
    $d = pwPage(['id' => 'd', 'slug' => ['es' => 'd'], 'title' => ['es' => 'D'], 'old_paths' => [], 'legal' => null, 'parent_id' => 'c']);
    foreach ([$a, $b, $c, $d] as $record) {
        $writer->write($record, $ctx);
    }
    same(null, wrValue($app, 'SELECT parent_id FROM {pages}'), 'antes de la segunda pasada, todas arriba');
    foreach ([$a, $b, $c, $d] as $record) {
        $writer->link($record, $ctx);
    }
    $parent = static function (string $id) use ($app, $ctx): ?int {
        $p = $app->db()->value('SELECT parent_id FROM {pages} WHERE id = ?', [$ctx->localId('pages', $id)]);

        return $p !== null ? (int) $p : null;
    };
    same($ctx->localId('pages', 'b'), $parent('a'), 'A cuelga de B');
    same(null, $parent('b'), 'B no puede colgar de A: sería un círculo');
    same(null, $parent('c'), 'C: su madre no vino');
    same($ctx->localId('pages', 'c'), $parent('d'), 'D cuelga de C');
    $notes = implode(' | ', $ctx->noteTexts('pages'));
    contains('parent page was not brought or would make a loop', $notes);
    $dry = writersContext($app)->dry(true);
    $counts = wrCounts($app);
    $writer->link($d, $dry);
    same($counts, wrCounts($app), 'y la segunda pasada de un simulacro no escribe');
});

pwTest('la página legal se marca en los ajustes solo si ese hueco está vacío', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new PagesWriter($app);
    $id = (int) $writer->write(pwPage(['old_paths' => []]), $ctx)['id'];
    same(['privacy' => $id], $app->settings()->arr('cms.legal'), 'la privacidad del origen es la de aquí');
    $other = (int) $writer->write(pwPage(['id' => '9', 'slug' => ['es' => 'privacidad-2'], 'old_paths' => []]), $ctx)['id'];
    same(['privacy' => $id], $app->settings()->arr('cms.legal'), 'la segunda no pisa a la primera');
    $terms = (int) $writer->write(pwPage(['id' => '10', 'slug' => ['es' => 'condiciones'], 'title' => ['es' => 'Condiciones'], 'legal' => 'terms', 'old_paths' => []]), $ctx)['id'];
    $returns = (int) $writer->write(pwPage(['id' => '11', 'slug' => ['es' => 'devoluciones'], 'title' => ['es' => 'Devoluciones'], 'legal' => 'returns', 'old_paths' => []]), $ctx)['id'];
    $legal = (int) $writer->write(pwPage(['id' => '12', 'slug' => ['es' => 'aviso'], 'title' => ['es' => 'Aviso'], 'legal' => 'legal', 'old_paths' => []]), $ctx)['id'];
    same(['privacy' => $id, 'terms' => $terms, 'shipping-returns' => $returns, 'legal-notice' => $legal], $app->settings()->arr('cms.legal'), 'cada tipo en su hueco');
    check($other > 0, 'la segunda página existe aunque no se marque');

    [$full] = pwApp();
    $now = $full->clock()->sql();
    $mine = $full->db()->insert('pages', ['status' => 'published', 'template' => 'default', 'position' => 0, 'noindex' => 0, 'is_home' => 0, 'created_at' => $now, 'updated_at' => $now]);
    $full->settings()->set('cms.legal', ['privacy' => $mine]);
    (new PagesWriter($full))->write(pwPage(['old_paths' => []]), writersContext($full));
    same(['privacy' => $mine], $full->settings()->arr('cms.legal'), 'con el hueco ocupado, no se toca');
});

pwTest('los textos de cada idioma del sitio se escriben y un idioma que el sitio no tiene se cuenta', function (): void {
    [$app] = pwApp(['i18n.languages' => ['es', 'en']]);
    $ctx = writersContext($app);
    $record = pwPage(['old_paths' => [], 'title' => ['es' => 'Quiénes somos', 'en' => 'About us', 'fr' => 'Qui sommes-nous'], 'slug' => ['es' => 'quienes-somos', 'en' => 'about-us'],
        'content' => ['es' => '<p>Hola</p>', 'en' => '<p>Hello</p>', 'fr' => '<p>Bonjour</p>'], 'meta_title' => ['es' => 'Somos'], 'meta_description' => ['es' => 'Una tienda pequeña.']]);
    $id = (int) (new PagesWriter($app))->write($record, $ctx)['id'];
    same(2, wrCount($app, 'page_i18n', 'page_id = ?', [$id]), 'solo es y en');
    same(['Quiénes somos', 'About us'], [wrValue($app, "SELECT title FROM {page_i18n} WHERE page_id = ? AND lang = 'es'", [$id]), wrValue($app, "SELECT title FROM {page_i18n} WHERE page_id = ? AND lang = 'en'", [$id])]);
    same(['quienes-somos', 'about-us'], [$app->slugs()->path('page', $id, 'es'), $app->slugs()->path('page', $id, 'en')]);
    same(['Somos', 'Una tienda pequeña.'], [wrValue($app, "SELECT meta_title FROM {page_i18n} WHERE page_id = ? AND lang = 'es'", [$id]), wrValue($app, "SELECT meta_description FROM {page_i18n} WHERE page_id = ? AND lang = 'es'", [$id])]);
    same(1, $ctx->unmappedCount('language', 'fr'), 'el francés se cuenta una sola vez');
});

pwTest('el estado y la fecha del origen: lo privado queda en borrador con nota, y lo publicado con fecha futura queda programado', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new PagesWriter($app);
    $private = (int) $writer->write(pwPage(['id' => 'p', 'slug' => ['es' => 'p'], 'old_paths' => [], 'status' => 'private', 'legal' => null]), $ctx)['id'];
    $future = (int) $writer->write(pwPage(['id' => 'f', 'slug' => ['es' => 'f'], 'old_paths' => [], 'published_at' => '2027-01-01T08:00:00Z', 'legal' => null]), $ctx)['id'];
    $draft = (int) $writer->write(pwPage(['id' => 'd', 'slug' => ['es' => 'd'], 'old_paths' => [], 'status' => 'draft', 'published_at' => null, 'legal' => null]), $ctx)['id'];
    same(['draft', 'scheduled', 'draft'], [wrValue($app, 'SELECT status FROM {pages} WHERE id = ?', [$private]), wrValue($app, 'SELECT status FROM {pages} WHERE id = ?', [$future]), wrValue($app, 'SELECT status FROM {pages} WHERE id = ?', [$draft])]);
    same('2027-01-01 08:00:00', wrValue($app, 'SELECT published_at FROM {pages} WHERE id = ?', [$future]));
    same(null, wrValue($app, 'SELECT published_at FROM {pages} WHERE id = ?', [$draft]));
    contains('Private content', implode(' ', $ctx->noteTexts('pages')));
    same(['pages_count' => 3], $writer->checksums($ctx));
});

pwTest('la categoría del blog se escribe con su dirección, la antigua responde, la madre se enlaza después y una del mismo nombre no se duplica', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new PostCategoriesWriter($app);
    $parent = pwTerm(['id' => '3', 'name' => ['es' => 'Blog'], 'slug' => ['es' => 'blog-general'], 'old_paths' => []]);
    $child = pwTerm(['parent_id' => '3']);
    $p = (int) $writer->write($parent, $ctx)['id'];
    $out = $writer->write($child, $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $id = (int) $out['id'];
    same(['category', null], [wrValue($app, 'SELECT taxonomy FROM {terms} WHERE id = ?', [$id]), wrValue($app, 'SELECT parent_id FROM {terms} WHERE id = ?', [$id])]);
    same('Cuidados', wrValue($app, 'SELECT name FROM {term_i18n} WHERE term_id = ?', [$id]));
    same('category/cuidados', $app->slugs()->path('term', $id, 'es'), 'con keep_urls, la antigua es la de ahora');
    same('blog/categoria/blog-general', $app->slugs()->path('term', $p, 'es'));
    same(200, (new TestClient($app))->get('/category/cuidados')->status, 'y responde');
    $writer->link($parent, $ctx);
    $writer->link($child, $ctx);
    same($p, (int) wrValue($app, 'SELECT parent_id FROM {terms} WHERE id = ?', [$id]), 'la madre, en la segunda pasada');

    $now = $app->clock()->sql();
    $mine = $app->db()->insert('terms', ['taxonomy' => 'category', 'position' => 0]);
    $app->db()->insert('term_i18n', ['term_id' => $mine, 'lang' => 'es', 'name' => 'noticias']);
    $same = $writer->write(pwTerm(['id' => '9', 'name' => ['es' => 'Noticias'], 'slug' => ['es' => 'noticias'], 'old_paths' => []]), $ctx);
    same([TypeWriter::UPDATED, $mine], [$same['result'], $same['id']], 'por su nombre, sin mirar mayúsculas');
    same(3, wrCount($app, 'terms'));
    check($now !== '', 'hecho');
});

pwTest('la categoría del blog repite sin cambios, se actualiza, choca si se tocó aquí y el simulacro no escribe', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app);
    $writer = new PostCategoriesWriter($app);
    $record = pwTerm(['old_paths' => []]);
    $id = (int) $writer->write($record, $ctx)['id'];
    $writer->link($record, $ctx);
    $counts = wrCounts($app);

    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    same(TypeWriter::UPDATED, $writer->write(pwTerm(['old_paths' => [], 'description' => ['es' => '<p>Nueva</p>']]), $ctx)['result']);
    same('<p>Nueva</p>', wrValue($app, 'SELECT description FROM {term_i18n} WHERE term_id = ?', [$id]));
    same($counts, wrCounts($app), 'sin filas nuevas');

    $dry = writersContext($app)->dry(true);
    same(TypeWriter::CREATED, $writer->write(pwTerm(['id' => '8', 'name' => ['es' => 'Otra'], 'old_paths' => []]), $dry)['result']);
    same($counts, wrCounts($app));

    $app->db()->update('term_i18n', ['name' => 'Cuidados de la madera'], ['term_id' => $id]);
    same(TypeWriter::CONFLICT, $writer->write(pwTerm(['old_paths' => [], 'description' => ['es' => '<p>Otra</p>']]), $ctx)['result'], 'renombrada aquí: choca');
    same('Cuidados de la madera', wrValue($app, 'SELECT name FROM {term_i18n} WHERE term_id = ?', [$id]));
    same(TypeWriter::UPDATED, $writer->write(pwTerm(['old_paths' => [], 'description' => ['es' => '<p>Otra</p>']]), writersContext($app, ['overwrite_local' => true]))['result']);
    same('Cuidados', wrValue($app, 'SELECT name FROM {term_i18n} WHERE term_id = ?', [$id]));
});

pwTest('la entrada de ejemplo se escribe con su imagen destacada, su extracto, su categoría, su etiqueta y su fecha', function (): void {
    [$app, $transport] = pwApp();
    $ctx = writersContext($app);
    $category = (int) (new PostCategoriesWriter($app))->write(pwTerm(['old_paths' => []]), $ctx)['id'];
    $app->http()->queue(pwPngResponse(4));
    $out = (new PostsWriter($app))->write(pwPost(), $ctx);
    same(TypeWriter::CREATED, $out['result']);
    $id = (int) $out['id'];

    $row = $app->db()->find('posts', ['id' => $id]);
    same(['published', '2026-03-04 07:30:00'], [$row['status'], $row['published_at']]);
    check((int) $row['media_id'] > 0, 'con su imagen destacada en la biblioteca');
    $text = $app->db()->row('SELECT * FROM {post_i18n} WHERE post_id = ? AND lang = ?', [$id, 'es']);
    same(['Cómo cuidar una mesa de roble', 'Tres cosas que alargan su vida.'], [$text['title'], $text['excerpt']]);
    $blocks = pwBlocks($app, 'post_i18n', 'post_id', $id);
    same(['paragraph', 'Un paño húmedo y nada de sol directo.'], [$blocks[0]['type'], $blocks[0]['attrs']['html']], 'el bloque de WordPress, convertido');
    $terms = $app->db()->select('SELECT t.taxonomy AS taxonomy, t.id AS id FROM {post_terms} p JOIN {terms} t ON t.id = p.term_id WHERE p.post_id = ? ORDER BY t.taxonomy', [$id]);
    same(['category', 'tag'], array_column($terms, 'taxonomy'));
    same($category, (int) $terms[0]['id']);
    same('blog/etiqueta/roble', $app->slugs()->path('term', (int) $terms[1]['id'], 'es'), 'la etiqueta nueva, con su dirección');
    same('como-cuidar-una-mesa-de-roble', $app->slugs()->path('post', $id, 'es'), 'con keep_urls, la antigua');
    same(1, $ctx->unmappedCount('field', 'Post author'), 'el autor no se trae y se cuenta');

    $post = $app->service('cms')->post($id, 'es', true);
    same(['Cómo cuidar una mesa de roble', ['Cuidados']], [$post['title'], array_column($post['categories'], 'name')]);
    pwSilent($app, $transport, 'tras escribir una entrada');
});

pwTest('la entrada repite sin cambios, se actualiza, choca con lo editado aquí, no entra duplicada y el simulacro no escribe', function (): void {
    [$app] = pwApp();
    $ctx = writersContext($app, ['import_images' => false]);
    $writer = new PostsWriter($app);
    $record = pwPost(['old_paths' => [], 'image' => null]);
    $id = (int) $writer->write($record, $ctx)['id'];
    $counts = wrCounts($app);

    same(TypeWriter::UNCHANGED, $writer->write($record, $ctx)['result']);
    same(TypeWriter::UPDATED, $writer->write(pwPost(['old_paths' => [], 'image' => null, 'tags' => ['es' => ['roble', 'madera']]]), $ctx)['result']);
    same(2, wrCount($app, 'terms', "taxonomy = 'tag'"), 'la etiqueta nueva se crea y la que ya estaba no se repite');
    same(1, wrCount($app, 'posts'));
    same(1, wrCount($app, 'post_i18n'));
    same(2, wrCount($app, 'post_terms', 'post_id = ?', [$id]), 'sin la categoría (no se trajo) y con las dos etiquetas');
    contains('category of the post was not brought', implode(' ', $ctx->noteTexts('posts')));

    $dry = writersContext($app)->dry(true);
    $before = wrCounts($app);
    same(TypeWriter::CREATED, $writer->write(pwPost(['id' => '231', 'old_paths' => [], 'image' => null, 'slug' => ['es' => 'otra']]), $dry)['result']);
    same($before, wrCounts($app));

    $app->clock()->advance(300);
    $app->db()->update('posts', ['noindex' => 1, 'updated_at' => $app->clock()->sql()], ['id' => $id]);
    $app->clock()->advance(300);
    same(TypeWriter::CONFLICT, $writer->write(pwPost(['old_paths' => [], 'image' => null, 'excerpt' => ['es' => 'Otro']]), $ctx)['result']);
    same(TypeWriter::UPDATED, $writer->write(pwPost(['old_paths' => [], 'image' => null, 'excerpt' => ['es' => 'Otro']]), writersContext($app, ['overwrite_local' => true]))['result']);
    same(['posts_count' => 1], $writer->checksums($ctx));
    unset($counts);
});
