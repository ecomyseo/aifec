<?php
/**
 * AIFEC — pruebas de los ganchos y de la caché del módulo de contenidos: la acción content.saved, el filtro ai.apply (solo toca el
 * campo aprobado), la paleta del panel, los usos de una imagen y la caché por etiquetas del servicio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Kernel\App;
use Aifec\Modules\Cms\Cms;

require_once __DIR__ . '/helpers.php';

/**
 * Anota cada aviso content.saved: lista de [tipo, id].
 *
 * @return array{0:object}
 */
function cmsListenSaved(App $app): object
{
    $log = new \stdClass();
    $log->events = [];
    $app->hooks()->on('content.saved', static function (string $type, int $id) use ($log): void {
        $log->events[] = [$type, $id];
    });

    return $log;
}

/**
 * @param array<string,mixed> $proposal
 */
function cmsApplyProposal(App $app, array $proposal): bool
{
    return (bool) $app->hooks()->apply('ai.apply', false, $proposal + ['entity' => 'page', 'entity_id' => 1, 'field' => 'meta_title', 'lang' => 'es', 'proposed' => 'Texto aprobado']);
}

test('guardar y borrar una página o una entrada avisa con content.saved', function (): void {
    $app = cmsApp();
    $log = cmsListenSaved($app);
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'published']);
    same([['page', 1]], $log->events);
    cmsSavePost($web, ['status' => 'published']);
    same([['page', 1], ['post', 1]], $log->events);
    $web->get('/admin/r/pages/1');
    $web->post('/admin/r/pages/1/delete', ['_token' => aifecToken($web)]);
    same(['page', 1], $log->events[2], 'al borrar también');
    $web->get('/admin/r/posts/1');
    $web->post('/admin/r/posts/1/delete', ['_token' => aifecToken($web)]);
    same(['post', 1], $log->events[3]);
});

test('un error de validación no avisa ni guarda', function (): void {
    $app = cmsApp();
    $log = cmsListenSaved($app);
    $web = adminClient($app);
    same(422, cmsSavePage($web, ['title' => ['es' => '', 'en' => '']])->status);
    same([], $log->events);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {revisions}'));
});

test('ai.apply escribe solo el campo aprobado, en su idioma, y deja lo demás como estaba', function (): void {
    $app = cmsApp();
    $id = cmsInsertPage($app, ['title' => 'Quiénes somos', 'en' => 'About us', 'status' => 'draft', 'published_at' => null]);
    $before = $app->db()->row('SELECT * FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, 'es']);
    $log = cmsListenSaved($app);
    same(true, cmsApplyProposal($app, ['entity_id' => $id, 'field' => 'meta_title', 'proposed' => 'Título SEO aprobado']));

    $after = $app->db()->row('SELECT * FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, 'es']);
    same('Título SEO aprobado', $after['meta_title']);
    same($before['title'], $after['title']);
    same($before['blocks'], $after['blocks'], 'los bloques no se tocan');
    same('draft', (string) $app->db()->value('SELECT status FROM {pages} WHERE id = ?', [$id]), 'un borrador sigue siendo borrador');
    same('About us', (string) $app->db()->value('SELECT title FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, 'en']), 'el otro idioma no se toca');
    same('', (string) $app->db()->value('SELECT meta_title FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$id, 'en']));
    same([['page', $id]], $log->events);
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {revisions} WHERE entity = 'page' AND entity_id = ?", [$id]), 'deja su versión');

    /** @var Cms $cms */
    $cms = $app->service('cms');
    same('Título SEO aprobado', $cms->page($id, 'es', true)['seo']['title'], 'y la ficha ya lo trae (la caché se vació)');
});

test('ai.apply aplica el título, el extracto y la meta descripción de páginas y entradas, en un idioma que aún no existe solo el título', function (): void {
    $app = cmsApp();
    $page = cmsInsertPage($app, ['title' => 'Página']);
    $post = cmsInsertPost($app, ['title' => 'Entrada']);
    same(true, cmsApplyProposal($app, ['entity_id' => $page, 'field' => 'title', 'proposed' => 'Página mejorada']));
    // La bandeja de la IA entrega la fila de la propuesta, con el texto en «proposed_text»: también se aplica.
    same(true, (bool) $app->hooks()->apply('ai.apply', false, ['entity' => 'page', 'entity_id' => $page, 'field' => 'title', 'lang' => 'es', 'proposed_text' => 'Página de la bandeja']));
    same('Página de la bandeja', (string) $app->db()->value('SELECT title FROM {page_i18n} WHERE page_id = ? AND lang = ?', [$page, 'es']), 'una propuesta aprobada desde la bandeja se aplica');
    same(true, cmsApplyProposal($app, ['entity_id' => $page, 'field' => 'title', 'proposed' => 'Página mejorada']));
    same(true, cmsApplyProposal($app, ['entity_id' => $page, 'field' => 'excerpt', 'proposed' => 'Un extracto nuevo']));
    same(true, cmsApplyProposal($app, ['entity' => 'post', 'entity_id' => $post, 'field' => 'meta_description', 'proposed' => 'Descripción para buscadores']));
    same(true, cmsApplyProposal($app, ['entity' => 'post', 'entity_id' => $post, 'field' => 'excerpt', 'proposed' => 'Resumen de la entrada']));
    same('Página mejorada', (string) $app->db()->value('SELECT title FROM {page_i18n} WHERE page_id = ?', [$page]));
    same('Un extracto nuevo', (string) $app->db()->value('SELECT excerpt FROM {page_i18n} WHERE page_id = ?', [$page]));
    same('Descripción para buscadores', (string) $app->db()->value('SELECT meta_description FROM {post_i18n} WHERE post_id = ?', [$post]));
    /** @var Cms $cms */
    $cms = $app->service('cms');
    same('Resumen de la entrada', $cms->post($post)['excerpt']);

    same(false, cmsApplyProposal($app, ['entity_id' => $page, 'lang' => 'en', 'field' => 'meta_title']), 'un texto suelto no puede crear una traducción');
    same(true, cmsApplyProposal($app, ['entity_id' => $page, 'lang' => 'en', 'field' => 'title', 'proposed' => 'Better page']));
    same('Better page', $cms->page($page, 'en')['title']);
    same(true, cmsApplyProposal($app, ['entity_id' => $page, 'lang' => 'en', 'field' => 'meta_title', 'proposed' => 'SEO title']), 'y ya con traducción, los demás campos sí');
});

test('ai.apply rechaza lo que no es suyo o no se puede aplicar y no toca nada', function (): void {
    $app = cmsApp();
    $id = cmsInsertPage($app, ['title' => 'Página']);
    $snapshot = json_encode($app->db()->select('SELECT * FROM {page_i18n}'));
    same(false, cmsApplyProposal($app, ['entity' => 'product', 'entity_id' => $id]), 'una entidad de otro módulo');
    same(false, cmsApplyProposal($app, ['entity_id' => $id, 'field' => 'blocks']), 'los bloques no se aplican por esta vía');
    same(false, cmsApplyProposal($app, ['entity_id' => $id, 'field' => 'status']));
    same(false, cmsApplyProposal($app, ['entity_id' => 99999]), 'una página que no existe');
    same(false, cmsApplyProposal($app, ['entity_id' => 0]));
    same(false, cmsApplyProposal($app, ['entity_id' => $id, 'field' => 'title', 'proposed' => '   ']), 'un título vacío');
    same(false, cmsApplyProposal($app, ['entity_id' => $id, 'lang' => 'fr']), 'un idioma que el sitio no tiene');
    same($snapshot, json_encode($app->db()->select('SELECT * FROM {page_i18n}')));
    same(true, $app->hooks()->apply('ai.apply', true, ['entity' => 'product']), 'si otro módulo ya lo aplicó, se deja como está');
});

test('ai.apply limpia las etiquetas y respeta el largo máximo del campo', function (): void {
    $app = cmsApp();
    $id = cmsInsertPage($app, ['title' => 'Página']);
    same(true, cmsApplyProposal($app, ['entity_id' => $id, 'field' => 'meta_title', 'proposed' => '<b>Título</b> & más <script>x()</script>']));
    same('Título & más x()', (string) $app->db()->value('SELECT meta_title FROM {page_i18n} WHERE page_id = ?', [$id]));
    cmsApplyProposal($app, ['entity_id' => $id, 'field' => 'meta_description', 'proposed' => str_repeat('ñ', 900)]);
    same(320, mb_strlen((string) $app->db()->value('SELECT meta_description FROM {page_i18n} WHERE page_id = ?', [$id])));
});

test('la caché guarda la ficha mientras nadie avise y se rehace al vaciar su etiqueta o la de todos los contenidos', function (): void {
    $app = cmsApp();
    $id = cmsInsertPage($app, ['title' => 'Primera']);
    /** @var Cms $cms */
    $cms = $app->service('cms');
    same('Primera', $cms->page($id)['title']);
    $app->db()->update('page_i18n', ['title' => 'Segunda'], ['page_id' => $id, 'lang' => 'es']);
    same('Primera', $cms->page($id)['title'], 'sigue la copia guardada');
    $app->invalidate(['page:' . $id]);
    same('Segunda', $cms->page($id)['title']);
    $app->db()->update('page_i18n', ['title' => 'Tercera'], ['page_id' => $id, 'lang' => 'es']);
    $app->invalidate(['cms']);
    same('Tercera', $cms->page($id)['title']);
    $other = cmsInsertPage($app, ['title' => 'Otra']);
    $cms->page($other);
    $app->db()->update('page_i18n', ['title' => 'Cambiada'], ['page_id' => $other, 'lang' => 'es']);
    $app->invalidate(['page:' . $id]);
    same('Otra', $cms->page($other)['title'], 'vaciar una página no vacía las demás');
});

test('un guardado por el panel vacía la caché de esa ficha y de los menús que la enseñan', function (): void {
    $app = cmsApp();
    $web = adminClient($app);
    cmsSavePage($web, ['status' => 'published', 'title' => ['es' => 'Antes', 'en' => '']]);
    $id = cmsLastId($app, 'pages');
    /** @var Cms $cms */
    $cms = $app->service('cms');
    same('Antes', $cms->page($id)['title']);
    $web->get('/admin/cms/menus/main');
    $web->post('/admin/cms/menus/main', ['_token' => aifecToken($web), 'items' => [['type' => 'page', 'ref' => (string) $id, 'level' => '0', 'labels' => ['es' => '']]]]);
    same('Antes', $cms->menu('main')[0]['label']);
    cmsSavePage($web, ['status' => 'published', 'title' => ['es' => 'Después', 'en' => '']], $id);
    same('Después', $cms->page($id)['title']);
    same('Después', $cms->menu('main')[0]['label'], 'el menú también cambia');
});

test('una página con un formulario no congela su estado: el gracias y los errores salen al pintarla, no de la caché', function (): void {
    $app = cmsApp();
    $id = cmsInsertPage($app, ['title' => 'Contacto', 'blocks' => [['type' => 'form', 'attrs' => ['form_id' => 'contact']]]]);
    /** @var Cms $cms */
    $cms = $app->service('cms');
    contains('<form', $cms->page($id)['html']);
    $app->hooks()->filter('cms.form.state', static function ($state): array {
        return ['sent' => true, 'errors' => [], 'old' => []];
    });
    $sent = $cms->page($id)['html'];
    contains('Gracias. Hemos recibido tu mensaje', $sent);
    notContains('<form', $sent);
}
);

test('la paleta del panel encuentra páginas y entradas por su título, y solo a quien puede verlas', function (): void {
    $app = cmsApp();
    cmsInsertPage($app, ['title' => 'Quiénes somos']);
    cmsInsertPost($app, ['title' => 'Quiénes ganan la liga']);
    $web = adminClient($app);
    $web->get('/admin/palette.json?q=quién');
    $items = $web->json();
    $labels = array_map(static function ($item): string {
        return is_array($item) && isset($item['label']) ? (string) $item['label'] : '';
    }, isset($items['items']) && is_array($items['items']) ? $items['items'] : $items);
    check(in_array('Quiénes somos', $labels, true), 'la página');
    check(in_array('Quiénes ganan la liga', $labels, true), 'la entrada');
    $support = adminClient($app, 'support');
    $support->get('/admin/palette.json?q=quién');
    notContains('Quiénes somos', $support->last->body);
});

test('la biblioteca de medios dice en qué entradas se usa una imagen', function (): void {
    $app = cmsApp();
    $media = aifecMedia($app, 'Mesa');
    cmsInsertPost($app, ['title' => 'Con imagen', 'media_id' => $media]);
    cmsInsertPost($app, ['title' => 'Sin imagen']);
    $uses = $app->hooks()->apply('media.usage', [], $media);
    same(['Entrada: Con imagen'], $uses);
    same([], $app->hooks()->apply('media.usage', [], 99999));
});

test('sin el módulo de contenidos no hay servicio cms ni tablas de contenido', function (): void {
    $app = testApp();
    $app->boot();
    same(false, $app->has('cms'));
    same(false, $app->db()->tableExists('pages'));
    same([], $app->hooks()->apply('menu.items', [], 'main', $app));
});
