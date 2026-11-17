<?php
/**
 * AIFEC — pruebas del panel del buscador: la pantalla de búsquedas (sin resultado y más repetidas, 7 y 30 días), el alta de un
 * sinónimo ya rellena desde una búsqueda, el recurso de sinónimos con su validación, el botón de reindexar, los ajustes y los
 * permisos (403 sin ellos).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;

require_once __DIR__ . '/helpers.php';

/** Un papel que solo puede mirar (search.view) y otro que puede mirar y cambiar. */
function searchRoles(App $app): void
{
    $app->db()->insert('roles', ['code' => 'viewer', 'name' => 'Viewer', 'caps' => json_encode(['dashboard.view', 'search.view']), 'system' => 0]);
}

/**
 * @param array<string,mixed> $form
 */
function searchSaveSynonym(TestClient $web, array $form, ?int $id = null): Aifec\Kernel\Response
{
    $web->get($id === null ? '/admin/r/synonyms/new' : '/admin/r/synonyms/' . $id);

    return $web->post('/admin/r/synonyms/' . ($id === null ? '' : $id . '/') . 'save', ['_token' => aifecToken($web), 'f' => $form]);
}

test('la pantalla se abre con permiso; con solo «ver» no hay botones de cambiar; sin permiso, 403', function (): void {
    $app = searchApp([], ['jobs']);
    searchShop($app);
    searchRoles($app);
    $web = adminClient($app);
    $r = $web->get('/admin/search');
    same(200, $r->status);
    contains('Sin resultado', $r->body);
    contains('Más buscadas', $r->body);
    contains('/admin/search/reindex', $r->body, 'quien puede cambiar ve el botón de reindexar');
    contains('Reindexar', $r->body);

    $viewer = adminClient($app, 'viewer');
    $r = $viewer->get('/admin/search');
    same(200, $r->status, 'con «ver» se ve');
    notContains('/admin/search/reindex', $r->body);
    notContains('Reindexar', $r->body);

    same(403, adminClient($app, 'support')->get('/admin/search')->status);
    same(403, adminClient($app, 'editor')->get('/admin/r/synonyms')->status);
    $token = aifecToken($viewer);
    same(403, $viewer->post('/admin/search/reindex', ['_token' => $token])->status, 'ni reindexar sin permiso');
    same(0, searchJobCount($app, 'search.reindex'));
});

test('lista las búsquedas sin resultado y las más repetidas, con el botón de crear sinónimo; 7 y 30 días', function (): void {
    $app = searchApp([], ['jobs']);
    searchShop($app);
    $log = searchService($app)->log();
    $log->record('sillon cama', 'es', 0, true);
    $log->record('sillon cama', 'es', 0, true);
    $log->record('mesa', 'es', 2, false);
    $app->clock()->advance(10 * 86400);
    $log->record('futon', 'es', 0, true);
    $web = adminClient($app);

    $r = $web->get('/admin/search');
    contains('<strong>futon</strong>', $r->body);
    notContains('sillon cama', $r->body, 'lo de hace diez días no sale en la ventana de 7');
    $r = $web->get('/admin/search?days=30');
    contains('<strong>sillon cama</strong>', $r->body);
    contains('<strong>futon</strong>', $r->body);
    contains('<strong>mesa</strong>', $r->body, 'las más repetidas llevan también las que sí encontraron');
    contains('href="/admin/r/synonyms/new?source=sillon+cama"', $r->body, 'el botón abre el alta con la consulta ya escrita');
    contains('Crear sinónimo', $r->body);
    check(substr_count($r->body, 'sillon cama') >= 2, 'aparece en las dos listas');
});

test('el alta de un sinónimo desde una búsqueda sale rellena: la consulta como origen y «en un sentido»', function (): void {
    $app = searchApp([], ['jobs']);
    $web = adminClient($app);
    $r = $web->get('/admin/r/synonyms/new?source=sillon+cama');
    same(200, $r->status);
    same('sillon cama', inputValue($r->body, 'f[source]'));
    check(preg_match('/<option value="oneway"[^>]*selected/', $r->body) === 1, 'el tipo viene como «en un sentido»');
    $empty = $web->get('/admin/r/synonyms/new');
    same('', (string) inputValue($empty->body, 'f[source]'), 'sin consulta, vacío');
    check(preg_match('/<option value="equal"[^>]*selected/', $empty->body) === 1, 'y «en los dos sentidos»');
});

test('guardar un sinónimo lo deja en la base de datos y el buscador lo usa enseguida', function (): void {
    $app = searchApp([], ['jobs']);
    $ids = searchShop($app);
    $futon = aifecProduct($app, ['name' => 'Futón japonés']);
    searchService($app)->index('product', $futon);
    $web = adminClient($app);
    same(true, searchService($app)->query('sillon cama')['relaxed'], 'sin el sinónimo, solo sale lo que casa a medias («sillón»)');

    $r = searchSaveSynonym($web, ['mode' => 'oneway', 'source' => 'sillon cama', 'terms' => "futón;  sofá cama\n futón", 'lang' => '']);
    same(302, $r->status);
    $row = $app->db()->row('SELECT * FROM {synonyms}');
    same('oneway', $row['mode']);
    same('sillon cama', $row['source']);
    same('futón, sofá cama', $row['terms'], 'la lista se limpia: sin repetidos y separada por comas');
    same('', (string) $row['lang']);
    same([$futon], searchIds($app, 'sillon cama'), 'la caché de sinónimos se vació al guardar');

    // Editarlo cambia la regla.
    $id = (int) $row['id'];
    searchSaveSynonym($web, ['mode' => 'oneway', 'source' => 'sillon cama', 'terms' => 'mesa de roble', 'lang' => 'es'], $id);
    same('mesa de roble', $app->db()->value('SELECT terms FROM {synonyms} WHERE id = ?', [$id]));
    same([$ids['mesa']], searchIds($app, 'sillon cama'));
    unset($ids);
});

test('un sinónimo sin sentido no se guarda: sin origen, o con un solo término «en los dos sentidos»', function (): void {
    $app = searchApp([], ['jobs']);
    $web = adminClient($app);
    $r = searchSaveSynonym($web, ['mode' => 'oneway', 'source' => '', 'terms' => 'futón', 'lang' => '']);
    same(422, $r->status);
    contains('Escribe la palabra que teclean los clientes', $r->body);
    $r = searchSaveSynonym($web, ['mode' => 'equal', 'source' => '', 'terms' => 'sofá', 'lang' => '']);
    same(422, $r->status);
    contains('Escribe al menos dos palabras que signifiquen lo mismo', $r->body);
    $r = searchSaveSynonym($web, ['mode' => 'equal', 'source' => '', 'terms' => ' , ; ', 'lang' => '']);
    same(422, $r->status, 'una lista vacía');
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {synonyms}'), 'no se guardó nada');
});

test('el listado de sinónimos escapa lo que escribe el comerciante: «&», «<» y comillas', function (): void {
    $app = searchApp([], ['jobs']);
    $web = adminClient($app);
    searchSaveSynonym($web, ['mode' => 'equal', 'source' => '', 'terms' => 'a & b, <c>, "d"', 'lang' => '']);
    $r = $web->get('/admin/r/synonyms');
    same(200, $r->status);
    contains('a &amp; b, &lt;c&gt;, &quot;d&quot;', $r->body);
    notContains('<c>', $r->body);
    same(302, searchSaveSynonym($web, ['mode' => 'equal', 'source' => '', 'terms' => 'x, y', 'lang' => 'es'])->status);
    same(2, (int) $app->db()->value('SELECT COUNT(*) FROM {synonyms}'));
});

test('reindexar desde el panel encola un solo trabajo, la cola lo hace por tandas y el índice queda completo', function (): void {
    $app = searchApp([], ['jobs']);
    searchBulkProducts($app, 130);
    searchRoles($app);
    $web = adminClient($app);
    same(0, count(searchDocs($app)), 'todavía no hay nada indexado');
    $r = $web->get('/admin/search');
    $r = $web->post('/admin/search/reindex', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    same('/admin/search#tab=srindex', $r->headers['Location']);
    contains('El índice se está rehaciendo en segundo plano', $web->follow()->body);
    same(1, searchJobCount($app, 'search.reindex', 'pending'));
    $web->post('/admin/search/reindex', ['_token' => aifecToken($web)]);
    same(1, searchJobCount($app, 'search.reindex'), 'pulsar otra vez no encola otro mientras hay uno');

    searchWork($app);
    same(130, count(searchDocs($app)), 'los 130 productos');
    same(2, searchJobCount($app, 'search.reindex', 'done'), 'dos tandas: 100 y 30');
    searchCheckTerms($app);
    $after = $web->get('/admin/search');
    contains('130', $after->body);
});

test('el estado del índice dice cuántos documentos hay, cuándo se indexó y cuántos trabajos esperan', function (): void {
    $app = searchApp([], ['jobs']);
    $web = adminClient($app);
    $r = $web->get('/admin/search');
    contains('El índice está vacío', $r->body);
    searchShop($app);
    $r = $web->get('/admin/search');
    notContains('El índice está vacío', $r->body);
    contains('Productos', $r->body);
    contains($app->clock()->sql(), $r->body, 'cuándo se indexó por última vez');
});

test('los ajustes se abren y se guardan: pesos, umbral y las dos opciones de IA', function (): void {
    $app = searchApp([], ['jobs']);
    searchRoles($app);
    $web = adminClient($app);
    $r = $web->get('/admin/settings/search');
    same(200, $r->status);
    contains('Orden de los resultados', $r->body);
    contains('Ayuda de la IA', $r->body);
    $form = [
        'search.w_name' => '9', 'search.w_ref' => '4', 'search.w_brand' => '3', 'search.w_cats' => '2.5', 'search.w_tags' => '2',
        'search.w_attrs' => '2', 'search.w_summary' => '1.5', 'search.w_desc' => '1', 'search.w_extra' => '0.8', 'search.min_score' => '0.5',
        'search.vectors' => '0', 'search.ai_expand' => '1', 'search.meaning_threshold' => '0.7',
    ];
    $r = $web->post('/admin/settings/search', ['_token' => aifecToken($web), 'f' => $form]);
    same(302, $r->status, $r->body);
    same(9.0, (float) $app->settings()->get('search.w_name'));
    same(0.5, (float) $app->settings()->get('search.min_score'));
    same(0.7, (float) $app->settings()->get('search.meaning_threshold'), 'el parecido para ofrecer algo por significado');
    same(false, $app->settings()->bool('search.vectors'));
    same(true, $app->settings()->bool('search.ai_expand'));
    $form['search.min_score'] = '3';
    $web->get('/admin/settings/search');
    $r = $web->post('/admin/settings/search', ['_token' => aifecToken($web), 'f' => $form]);
    same(422, $r->status, 'un umbral fuera de 0 a 1 no se acepta');
    same(0.5, (float) $app->settings()->get('search.min_score'), 'y lo guardado sigue igual');
    same(403, adminClient($app, 'viewer')->get('/admin/settings/search')->status);
});

test('el menú del panel lleva «Search» y «Synonyms» en Marketing y los ajustes en Ajustes', function (): void {
    $app = searchApp([], ['jobs']);
    $web = adminClient($app);
    $r = $web->get('/admin');
    contains('href="/admin/search"', $r->body);
    // Desde P102, «Sinónimos» y los ajustes son pestañas de «Buscador»: misma dirección, en la barra de pestañas de la pantalla.
    $tabs = $web->get('/admin/search')->body;
    contains('role="tab" href="/admin/r/synonyms"', $tabs);
    contains('role="tab" href="/admin/settings/search"', $tabs);
    same(200, $web->get('/admin/r/synonyms')->status);
});
