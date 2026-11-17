<?php
/**
 * AIFEC — pruebas de las páginas legales de partida: se crean como borradores en cada idioma, no se duplican, la pantalla deja
 * claro que hay que revisarlas y los datos de la empresa se leen de Ajustes AL PINTAR (14/11/2026: el usuario pidió que las
 * páginas legales de todas las tiendas usen siempre los datos de empresa; antes se copiaban el día que se creaban).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\Cms\Cms;

require_once __DIR__ . '/helpers.php';

/**
 * @return array<string,mixed>
 */
function cmsShop(): array
{
    return [
        'store.name' => 'Muebles & Co', 'store.legal_name' => 'Muebles Ejemplo S.L.', 'store.vat_id' => 'B12345678',
        'store.email' => 'tienda@ejemplo.test', 'store.phone' => '999000001', 'store.address' => "Calle Mayor 1\n28001 Madrid",
    ];
}

test('la pantalla de páginas legales avisa de que son un punto de partida y ofrece crearlas', function (): void {
    $app = cmsApp(['cms'], cmsShop());
    $web = adminClient($app);
    $r = $web->get('/admin/cms/legal');
    same(200, $r->status);
    contains('solo un punto de partida', $r->body);
    contains('Aviso legal', $r->body);
    contains('Política de privacidad', $r->body);
    contains('Política de cookies', $r->body);
    contains('Condiciones de compra', $r->body);
    contains('Envíos y devoluciones', $r->body);
    contains('Sin crear', $r->body);
    contains('Crear las páginas que faltan como borradores', $r->body);
    notContains('Faltan algunos datos', $r->body, 'con los datos de la tienda completos no hay aviso');
});

test('crear las páginas legales deja cinco borradores que enseñan los datos de la tienda', function (): void {
    $app = cmsApp(['cms'], cmsShop());
    $web = adminClient($app);
    $web->get('/admin/cms/legal');
    $r = $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('5 páginas creadas como borradores', $web->follow()->body);

    same(5, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'));
    same(5, (int) $app->db()->value("SELECT COUNT(*) FROM {pages} WHERE status = 'draft'"), 'todas como borrador');
    $titles = $app->db()->column("SELECT title FROM {page_i18n} WHERE lang = 'es' ORDER BY page_id");
    same(['Aviso legal', 'Política de privacidad', 'Política de cookies', 'Condiciones de compra', 'Envíos y devoluciones'], $titles);

    /** @var Cms $cms */
    $cms = $app->service('cms');
    same(null, $cms->page(1), 'un borrador no se ve en la tienda');
    $notice = (string) $cms->page(1, 'es', true)['html'];
    contains('Muebles Ejemplo S.L.', $notice, 'el nombre legal');
    contains('B12345678', $notice, 'el NIF');
    contains('Calle Mayor 1<br>28001 Madrid', $notice, 'la dirección con sus líneas');
    contains('mailto:tienda@ejemplo.test', $notice);
    contains('tel:999000001', $notice);
    contains('Muebles &amp; Co', $notice, 'el nombre comercial sale escapado en el HTML');
    $privacy = $cms->page(2, 'es', true);
    contains('Quién es el responsable de tus datos', $privacy['text']);
    contains('B12345678', (string) $privacy['html'], 'la privacidad también lleva al responsable');
    contains('Muebles Ejemplo S.L.', (string) $cms->page(4, 'es', true)['html'], 'y las condiciones, al vendedor');
    contains('Tus derechos', $privacy['text']);
    $terms = $cms->page(4, 'es', true);
    contains('14 días', $terms['text']);
    same('aviso-legal', $app->slugs()->path('page', 1, 'es'));
    same('politica-de-privacidad', $app->slugs()->path('page', 2, 'es'));
    same('envios-y-devoluciones', $app->slugs()->path('page', 5, 'es'));
    same(5, (int) $app->db()->value("SELECT COUNT(*) FROM {revisions} WHERE entity = 'page'"), 'cada una deja su versión');
});

test('las páginas legales se crean en cada idioma del sitio (en inglés lo que no está traducido)', function (): void {
    $app = cmsApp(['cms'], cmsShop());
    $web = adminClient($app);
    $web->get('/admin/cms/legal');
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    same('Legal notice', (string) $app->db()->value("SELECT title FROM {page_i18n} WHERE page_id = 1 AND lang = 'en'"));
    same('legal-notice', $app->slugs()->path('page', 1, 'en'));
    /** @var Cms $cms */
    $cms = $app->service('cms');
    $en = $cms->page(1, 'en', true);
    contains('This website belongs to:', $en['text']);
    contains('Muebles Ejemplo S.L.', (string) $en['html']);

});

test('volver a crearlas no duplica nada, y si se borra una solo se rehace esa', function (): void {
    $app = cmsApp(['cms'], cmsShop());
    $web = adminClient($app);
    $web->get('/admin/cms/legal');
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    $web->get('/admin/cms/legal');
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    contains('Todas las páginas legales ya existen', $web->follow()->body);
    same(5, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'));

    $web->get('/admin/r/pages/3');
    $web->post('/admin/r/pages/3/delete', ['_token' => aifecToken($web)]);
    same(4, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'));
    $screen = $web->get('/admin/cms/legal')->body;
    same(1, substr_count($screen, 'Sin crear'), 'solo falta una');
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    same(5, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'));
    same(1, (int) $app->db()->value("SELECT COUNT(*) FROM {page_i18n} WHERE lang = 'es' AND title = 'Política de cookies'"));
});

test('una página legal que ya se está editando no se pisa al volver a crear', function (): void {
    $app = cmsApp(['cms'], cmsShop());
    $web = adminClient($app);
    $web->get('/admin/cms/legal');
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    $app->db()->update('page_i18n', ['title' => 'Mi aviso revisado'], ['page_id' => 1, 'lang' => 'es']);
    $web->get('/admin/cms/legal');
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    same('Mi aviso revisado', (string) $app->db()->value("SELECT title FROM {page_i18n} WHERE page_id = 1 AND lang = 'es'"));
});

test('si faltan datos de la tienda la pantalla lo avisa y las páginas enseñan solo lo que hay, sin huecos', function (): void {
    $app = cmsApp(['cms'], ['store.name' => 'Tienda sin datos']);
    $web = adminClient($app);
    $screen = $web->get('/admin/cms/legal')->body;
    contains('Faltan algunos datos de la tienda', $screen);
    contains('no saldrán en las páginas legales', $screen);
    contains('Razón social', $screen);
    contains('/admin/settings/core', $screen);
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    /** @var Cms $cms */
    $cms = $app->service('cms');
    $notice = (string) $cms->page(1, 'es', true)['html'];
    contains('Tienda sin datos', $notice, 'sin razón social, el nombre de la tienda');
    notContains('…', $notice, 'nada de puntos suspensivos a la vista');
    notContains('<dt>NIF</dt>', $notice, 'un dato vacío no sale');
});

test('las páginas legales enseñan los datos de Ajustes de AHORA, no los del día en que se crearon', function (): void {
    $app = cmsApp(['cms'], cmsShop());
    $web = adminClient($app);
    $web->get('/admin/cms/legal');
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    /** @var Cms $cms */
    $cms = $app->service('cms');
    contains('B12345678', (string) $cms->page(1, 'es', true)['html']);
    $app->settings()->set('store.vat_id', 'B87654321');
    $app->settings()->set('store.email', 'nuevo@ejemplo.test');
    $app->invalidate(['settings']);
    $html = (string) $cms->page(1, 'es', true)['html'];
    contains('B87654321', $html, 'el NIF nuevo');
    notContains('B12345678', $html, 'y ni rastro del viejo');
    contains('nuevo@ejemplo.test', (string) $cms->page(4, 'es', true)['html']);
    $stored = (string) $app->db()->value('SELECT blocks FROM {page_i18n} WHERE page_id = 1 AND lang = ?', ['es']);
    notContains('B12345678', $stored, 'el texto guardado no copia los datos de la empresa');
});

test('rehacer las páginas legales cambia el texto viejo por el que lee los datos de Ajustes y guarda versión', function (): void {
    $app = cmsApp(['cms'], cmsShop());
    $web = adminClient($app);
    $web->get('/admin/cms/legal');
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    $old = json_encode([['type' => 'paragraph', 'attrs' => ['html' => 'Aviso legal de ejemplo con un NIF viejo B00000000']]]);
    $app->db()->update('page_i18n', ['blocks' => $old], ['page_id' => 1, 'lang' => 'es']);
    $before = (int) $app->db()->value("SELECT COUNT(*) FROM {revisions} WHERE entity = 'page'");
    $screen = $web->get('/admin/cms/legal')->body;
    contains('Rehacer las páginas legales con los textos actuales', $screen);
    $r = $web->post('/admin/cms/legal', ['_token' => aifecToken($web), 'refresh' => '1']);
    same(302, $r->status);
    contains('5 páginas legales rehechas', $web->follow()->body);
    /** @var Cms $cms */
    $cms = $app->service('cms');
    $html = (string) $cms->page(1, 'es', true)['html'];
    notContains('B00000000', $html);
    contains('B12345678', $html);
    same(5, (int) $app->db()->value('SELECT COUNT(*) FROM {pages}'), 'no crea páginas nuevas');
    check((int) $app->db()->value("SELECT COUNT(*) FROM {revisions} WHERE entity = 'page'") >= $before + 5, 'cada una deja su versión');
});

test('un nombre de tienda con HTML no se cuela en las páginas legales', function (): void {
    $app = cmsApp(['cms'], ['store.name' => '<script>alert(1)</script>Tienda', 'store.legal_name' => 'A & B <b>S.L.</b>']);
    $web = adminClient($app);
    $web->get('/admin/cms/legal');
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    $stored = (string) $app->db()->value('SELECT blocks FROM {page_i18n} WHERE page_id = 1 AND lang = ?', ['es']);
    notContains('<script', $stored);
    notContains('<b>S.L.</b>', $stored);
    /** @var Cms $cms */
    $cms = $app->service('cms');
    $html = (string) $cms->page(1, 'es', true)['html'];
    contains('A &amp; B &lt;b&gt;S.L.&lt;/b&gt;', $html);
    notContains('<script>alert', $html);
});

test('en una tienda de demostración los datos de empresa llevan delante el aviso de que son de ejemplo', function (): void {
    $app = cmsApp(['cms'], cmsShop());
    $web = adminClient($app);
    $web->get('/admin/cms/legal');
    $web->post('/admin/cms/legal', ['_token' => aifecToken($web)]);
    /** @var Cms $cms */
    $cms = $app->service('cms');
    notContains('tienda de demostración', (string) $cms->page(1, 'es', true)['html'], 'una tienda de verdad no lo dice');
    $demo = testApp(['modules' => ['cms'], 'config' => ['demo' => true]]);
    $demo->boot();
    $painters = new \Aifec\Modules\Cms\Painters($demo, $demo->service('cms'));
    contains('Esta es una tienda de demostración', $painters->company([]));
});

test('cms:legal crea las que faltan, rehace solo las pedidas con --only y las publica', function (): void {
    $app = cmsApp(['cms'], cmsShop());
    $commands = require \Aifec\Autoload::root() . '/modules/cms/cli.php';
    $run = static function (array $args) use ($app, $commands): string {
        $io = new \Aifec\Cli\Console(false);
        same(0, (int) $commands['cms:legal']['run']($app, $args, $io));

        return $io->output;
    };
    contains('legal-notice: not created', $run([]));
    $out = $run(['create' => true, 'publish' => true]);
    contains('Created: legal-notice, privacy, cookies, terms, shipping-returns', $out);
    contains('Published: 5', $out);
    same(5, (int) $app->db()->value("SELECT COUNT(*) FROM {pages} WHERE status = 'published'"));
    $mine = json_encode([['type' => 'paragraph', 'attrs' => ['html' => 'Mi texto propio']]]);
    $app->db()->update('page_i18n', ['blocks' => $mine], ['page_id' => 5, 'lang' => 'es']);
    $app->db()->update('page_i18n', ['blocks' => $mine], ['page_id' => 1, 'lang' => 'es']);
    contains('Rewritten: legal-notice', $run(['refresh' => true, 'only' => 'legal-notice,privacy']));
    notContains('Mi texto propio', (string) $app->db()->value("SELECT blocks FROM {page_i18n} WHERE page_id = 1 AND lang = 'es'"), 'el aviso legal, rehecho');
    contains('Mi texto propio', (string) $app->db()->value("SELECT blocks FROM {page_i18n} WHERE page_id = 5 AND lang = 'es'"), 'la de envíos, que no se pidió, se queda');
});
