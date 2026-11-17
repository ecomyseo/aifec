<?php
/**
 * AIFEC — el asistente del panel: el recorrido completo por HTTP con el lector de mentira, los permisos, la casilla de
 * confirmación obligatoria y las pantallas de pausa, reanudación y cancelación.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Trabaja la migración por el latido de la pantalla hasta que se cierra. Devuelve la última respuesta del latido.
 *
 * @return array<string,mixed>
 */
function importPollUntilClosed(TestClient $web, int $id, string $token): array
{
    $json = [];
    for ($i = 0; $i < 60; ++$i) {
        $r = $web->post('/admin/import/' . $id . '/poll', ['_token' => $token]);
        same(200, $r->status);
        $json = $web->json();
        if (!empty($json['closed']) || $json['status'] === 'paused') {
            break;
        }
    }

    return $json;
}

test('el recorrido completo del asistente: origen, conexión, qué traer, equivalencias, simulacro, confirmar, en curso e informe', function (): void {
    $app = importApp();
    $web = adminClient($app);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 3), 'products' => importRecords('products', 2)];
    $token = aifecToken($web);

    // Histórico vacío y primer paso.
    $r = $web->get('/admin/import');
    same(200, $r->status);
    contains('Todavía no hay migraciones', $r->body);
    contains('Nueva migración', $r->body);
    $r = $web->get('/admin/import/new');
    same(200, $r->status);
    contains('Fake shop', $r->body);

    // Sin elegir origen no se crea nada; eligiéndolo, nace el borrador.
    $web->post('/admin/import/new', ['_token' => $token]);
    same(302, $web->last->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {import_runs}'));
    $web->post('/admin/import/new', ['_token' => $token, 'reader' => 'woocommerce:public', 'label' => 'Mi migración']);
    same(302, $web->last->status);
    contains('/admin/import/1/connection', $web->last->headers['Location']);
    same('draft', importRun($app, 1)['status']);

    // Conexión: una dirección mala se explica; la buena se prueba y se guarda.
    $r = $web->get('/admin/import/1/connection');
    same(200, $r->status);
    contains('Dirección de la tienda', $r->body);
    $r = $web->post('/admin/import/1/connection', ['_token' => $token, 'url' => 'http://example.com', 'key' => 'clave-de-mentira-7777']);
    same(422, $r->status);
    contains('Solo se admiten direcciones https (http solo para una dirección local)', $r->body);
    notContains('clave-de-mentira-7777', $r->body, 'ni siquiera al volver a pintar el formulario con errores');
    $r = $web->post('/admin/import/1/connection', ['_token' => $token, 'url' => 'https://origen.test', 'key' => 'clave-de-mentira-7777', 'probe' => '1']);
    same(200, $r->status);
    contains('La conexión funciona.', $r->body);
    contains('Versión', $r->body);
    contains('9.1', $r->body);
    notContains('clave-de-mentira-7777', $r->body);
    same(['es', 'fr'], importRun($app, 1)['options']['source_languages'], 'la prueba apunta los idiomas del origen');
    $r = $web->get('/admin/import/1/connection');
    contains('Guardada: déjala vacía para conservarla', $r->body, 'el secreto no vuelve, solo se sabe que hay uno');
    notContains('clave-de-mentira-7777', $r->body);
    $web->post('/admin/import/1/connection', ['_token' => $token, 'url' => 'https://origen.test', 'key' => '']);
    same(302, $web->last->status);
    contains('/admin/import/1/types', $web->last->headers['Location']);
    same('clave-de-mentira-7777', importService($app)->connections()->load(1)['config']['key'], 'una clave vacía conserva la guardada');

    // Qué traer.
    $r = $web->get('/admin/import/1/types');
    same(200, $r->status);
    contains('name="types[]" value="redirects"', $r->body);
    contains('Este origen no da este tipo de datos', $r->body);
    contains('Datos de personas', $r->body);
    $web->post('/admin/import/1/types', ['_token' => $token, 'since' => '']);
    same(302, $web->last->status);
    contains('Elige al menos una cosa que traer.', $web->follow()->body);
    $web->post('/admin/import/1/types', ['_token' => $token, 'types' => ['redirects'], 'since' => '31/12/2026']);
    contains('Escribe la fecha como AAAA-MM-DD.', $web->follow()->body);
    same([], importRun($app, 1)['options']['types'] ?? [], 'lo inválido no se guarda');
    $web->post('/admin/import/1/types', ['_token' => $token, 'types' => ['redirects', 'products', 'customers', 'inventado'], 'keep_urls' => '0', 'import_images' => '1', 'overwrite_local' => '0', 'since' => '', 'shop' => '']);
    same(302, $web->last->status);
    contains('/admin/import/1/equivalences', $web->last->headers['Location']);
    $options = importRun($app, 1)['options'];
    same(['products', 'redirects'], $options['types'], 'solo lo que se puede traer, en el orden de Types::ORDER');
    same(false, $options['keep_urls']);
    same(true, $options['import_images']);
    same(false, $options['overwrite_local']);

    // Equivalencias.
    $r = $web->get('/admin/import/1/equivalences');
    same(200, $r->status);
    $web->post('/admin/import/1/equivalences', ['_token' => $token]);
    same(302, $web->last->status);
    contains('/admin/import/1/simulation', $web->last->headers['Location']);

    // Simulacro.
    $r = $web->get('/admin/import/1/simulation');
    same(200, $r->status);
    contains('Hacer el simulacro', $r->body);
    $web->post('/admin/import/1/simulate', ['_token' => $token]);
    same(302, $web->last->status);
    $sim = (int) $app->db()->value('SELECT MAX(id) FROM {import_runs} WHERE dry_run = 1');
    same(2, $sim);
    $r = $web->get('/admin/import/1/simulation');
    contains('data-af-import-poll', $r->body);
    contains('data-af-import-open="1"', $r->body);
    $json = importPollUntilClosed($web, $sim, $token);
    same('done', $json['status']);
    same(true, $json['dry_run']);
    same(100, $json['percent']);
    contains('data-af-import-type="redirects"', $json['bars_html']);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'), 'el simulacro no escribió');
    $r = $web->get('/admin/import/1/simulation');
    contains('Qué pasaría', $r->body);
    contains('/admin/import/1/confirm', $r->body);

    // Confirmar: la casilla es obligatoria.
    $r = $web->get('/admin/import/1/confirm');
    same(200, $r->status);
    contains('He leído que no se enviará ningún correo y que las formas de pago quedan apagadas.', $r->body);
    $web->post('/admin/import/1/confirm', ['_token' => $token]);
    same(302, $web->last->status);
    contains('/admin/import/1/confirm', $web->last->headers['Location']);
    contains('Marca la casilla para confirmar que lo has leído.', $web->follow()->body);
    same('draft', importRun($app, 1)['status'], 'sin la casilla no empieza');
    $web->post('/admin/import/1/confirm', ['_token' => $token, 'ack' => '1']);
    same(302, $web->last->status);
    contains('/admin/import/1/progress', $web->last->headers['Location']);
    same('running', importRun($app, 1)['status']);

    // En curso e informe.
    $r = $web->get('/admin/import/1/progress');
    same(200, $r->status);
    contains('data-af-import-poll', $r->body);
    $json = importPollUntilClosed($web, 1, $token);
    same('done', $json['status']);
    $r = $web->get('/admin/import/1/progress');
    contains('Ver el informe', $r->body);
    same(5, (int) $app->db()->value('SELECT COUNT(*) FROM {redirects}'), 'la migración de verdad sí escribió');
    $r = $web->get('/admin/import/1/report');
    same(200, $r->status);
    contains('Por tipo', $r->body);
    same(200, $web->get('/admin/import/1/print')->status);
    same(200, $web->get('/admin/import/1/report.csv')->status);
    $r = $web->get('/admin/import');
    contains('Mi migración', $r->body);
    contains('Simulacro', $r->body);
});

test('sin el permiso import.run, ninguna pantalla ni acción se abre', function (): void {
    $app = importApp();
    importDraft($app);
    $web = adminClient($app, 'support');
    $token = aifecToken($web);
    foreach (['/admin/import', '/admin/import/new', '/admin/import/1/connection', '/admin/import/1/types', '/admin/import/1/equivalences', '/admin/import/1/simulation',
        '/admin/import/1/confirm', '/admin/import/1/progress', '/admin/import/1/report', '/admin/import/1/report.csv', '/admin/import/1/print'] as $path) {
        same(403, $web->get($path)->status, 'GET ' . $path);
    }
    foreach (['/admin/import/new', '/admin/import/1/connection', '/admin/import/1/types', '/admin/import/1/equivalences', '/admin/import/1/simulate',
        '/admin/import/1/confirm', '/admin/import/1/poll', '/admin/import/1/pause', '/admin/import/1/resume', '/admin/import/1/cancel'] as $path) {
        same(403, $web->post($path, ['_token' => $token, 'reader' => 'woocommerce:public', 'ack' => '1'])->status, 'POST ' . $path);
    }
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {import_runs}'), 'no se creó ni se tocó nada');
    same('draft', importRun($app, 1)['status']);
    $guest = new TestClient($app);
    check(in_array($guest->get('/admin/import')->status, [302, 401, 403], true), 'sin sesión tampoco');
});

test('un identificador que no existe da 404 en todas las pantallas', function (): void {
    $app = importApp();
    $web = adminClient($app);
    $token = aifecToken($web);
    foreach (['connection', 'types', 'equivalences', 'simulation', 'confirm', 'progress', 'report', 'report.csv', 'print'] as $page) {
        same(404, $web->get('/admin/import/77/' . $page)->status, $page);
    }
    foreach (['connection', 'poll', 'pause', 'resume', 'cancel', 'simulate', 'confirm'] as $action) {
        same(404, $web->post('/admin/import/77/' . $action, ['_token' => $token])->status, $action);
    }
});

test('el nombre de la migración sale escapado en el histórico', function (): void {
    $app = importApp();
    $web = adminClient($app);
    $token = aifecToken($web);
    $web->post('/admin/import/new', ['_token' => $token, 'reader' => 'woocommerce:public', 'label' => '<b>Tienda & Cía</b>']);
    $body = $web->get('/admin/import')->body;
    contains('&lt;b&gt;Tienda &amp; Cía&lt;/b&gt;', $body);
    notContains('<b>Tienda & Cía</b>', $body);
});

test('pausar, reanudar y cancelar desde la pantalla de progreso', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    $web = adminClient($app);
    $token = aifecToken($web);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 30)];
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id, false);
    importService($app)->runner()->step($id);

    $r = $web->get('/admin/import/' . $id . '/progress');
    contains('Pausar', $r->body);
    contains('Cancelar', $r->body);
    $web->post('/admin/import/' . $id . '/pause', ['_token' => $token]);
    same(302, $web->last->status);
    same('paused', importRun($app, $id)['status']);
    $r = $web->get('/admin/import/' . $id . '/progress');
    contains('Reanudar', $r->body);
    contains('Pausada por una persona', $r->body);
    notContains('data-af-import-open="1"', $r->body, 'en pausa la pantalla deja de preguntar');

    $web->post('/admin/import/' . $id . '/resume', ['_token' => $token]);
    same('running', importRun($app, $id)['status']);
    $json = importPollUntilClosed($web, $id, $token);
    same('done', $json['status'], 'tras reanudar, el latido la lleva hasta el final');
    same(30, importRun($app, $id)['counters']['redirects']['read']);

    $other = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($other, false);
    $web->post('/admin/import/' . $other . '/cancel', ['_token' => $token]);
    same(302, $web->last->status);
    same('cancelled', importRun($app, $other)['status']);
    contains('Cancelada', $web->get('/admin/import/' . $other . '/progress')->body);
});

test('una migración que ya empezó no vuelve a los pasos del borrador', function (): void {
    $app = importApp();
    $web = adminClient($app);
    importFiveTypes();
    $running = importDraft($app);
    importService($app)->runner()->start($running, false);
    $web->get('/admin/import/' . $running . '/connection');
    same(302, $web->last->status);
    contains('/admin/import/' . $running . '/progress', $web->last->headers['Location']);
    importService($app)->runner()->cancel($running);
    $web->get('/admin/import/' . $running . '/types');
    contains('/admin/import/' . $running . '/report', $web->last->headers['Location']);
    same(200, $web->get('/admin/import/' . $running . '/progress')->status, 'el progreso de una cerrada se puede ver');
});

test('una migración parada por un acceso denegado enseña el motivo y avisa en el inicio', function (): void {
    $app = importApp(['import', 'media', 'catalog'], ['import.step_records' => 10]);
    $web = adminClient($app);
    ImportFakeReader::$data = ['redirects' => importRecords('redirects', 30)];
    $id = importDraft($app, ['types' => ['redirects']]);
    importService($app)->runner()->start($id, false);
    ImportFakeReader::$failures = [new \Aifec\Modules\Import\SourceDenied('401 invalid key clave-de-mentira-7777')];
    importService($app)->runner()->step($id);
    $r = $web->get('/admin/import/' . $id . '/progress');
    contains('401 invalid key', $r->body);
    notContains('clave-de-mentira-7777', $r->body);
    $home = $web->get('/admin');
    contains('Hay una migración en pausa', $home->body);
    contains('/admin/import/' . $id . '/progress', $home->body);
});

test('el simulacro se niega si hay otra migración en curso', function (): void {
    $app = importApp();
    $web = adminClient($app);
    $token = aifecToken($web);
    importFiveTypes();
    $running = importDraft($app);
    importService($app)->runner()->start($running, false);
    $draft = importDraft($app);
    $web->post('/admin/import/' . $draft . '/simulate', ['_token' => $token]);
    same(302, $web->last->status);
    contains('Hay otra migración en curso.', $web->follow()->body);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {import_runs} WHERE dry_run = 1'), 'no nació ningún simulacro');
    $web->post('/admin/import/' . $draft . '/confirm', ['_token' => $token, 'ack' => '1']);
    contains('Hay otra migración en curso', $web->follow()->body, 'ni se empieza la real');
    same('draft', importRun($app, $draft)['status']);
});

test('el latido devuelve JSON sin caché con el estado y el HTML de las barras', function (): void {
    $app = importApp();
    $web = adminClient($app);
    $token = aifecToken($web);
    importFiveTypes();
    $id = importDraft($app);
    importService($app)->runner()->start($id);
    $r = $web->post('/admin/import/' . $id . '/poll', ['_token' => $token]);
    same(200, $r->status);
    contains('application/json', $r->headers['Content-Type']);
    contains('no-store', $r->headers['Cache-Control']);
    $json = $web->json();
    same(true, $json['ok']);
    same($id, $json['id']);
    check(in_array($json['status'], ['running', 'done'], true));
    contains('<progress', $json['bars_html']);
    check(isset($json['notices_html']) && is_string($json['notices_html']));
    same(['settings', 'tax_classes', 'categories', 'products', 'redirects'], array_column($json['types'], 'type'));
    check(!isset($json['conn']) && strpos((string) json_encode($json), 'clave-de-mentira-7777') === false, 'ni un dato de conexión');
});
