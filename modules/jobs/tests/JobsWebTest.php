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

/** Papel que solo puede mirar las tareas (jobs.view), sin reintentar ni ejecutar. */
function jobsViewerRole(\Aifec\Kernel\App $app): void
{
    $app->db()->insert('roles', ['code' => 'viewer', 'name' => 'Viewer', 'caps' => json_encode(['dashboard.view', 'jobs.view']), 'system' => 0]);
}

test('la dirección web /cron/{key}: 404 con clave mala, 200 con la buena y skipped si se repite enseguida', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $web = new TestClient($app);
    $q->register('demo.web', function (): string {
        return 'desde la web';
    });
    $id = $q->push('demo.web');

    $key = $q->webKey();
    same(48, strlen($key));
    same($key, $q->webKey(), 'la clave se guarda y no cambia');
    same($key, $app->settings()->str('jobs.web_key'));

    same(404, $web->get('/cron/z' . substr($key, 1))->status);
    same(404, $web->get('/cron/corta')->status);
    same('pending', jobsRow($app, $id)['status'], 'con clave mala no se ejecuta nada');

    $r = $web->get('/cron/' . $key);
    same(200, $r->status);
    contains('application/json', $r->headers['Content-Type']);
    contains('no-store', $r->headers['Cache-Control']);
    $json = $web->json();
    same(true, $json['ok']);
    same(1, $json['work']['done']);
    check(is_array($json['cron']['ran']), 'cron devuelve sus números');
    same('done', jobsRow($app, $id)['status']);
    same('desde la web', jobsRow($app, $id)['result']);

    $web->get('/cron/' . $key);
    same(['skipped' => true], $web->json());
    $app->clock()->advance(19);
    $web->get('/cron/' . $key);
    same(['skipped' => true], $web->json());
    $app->clock()->advance(1);
    $web->get('/cron/' . $key);
    same(true, $web->json()['ok'], 'pasados 20 segundos vuelve a ejecutar');
});

test('el panel lista los trabajos con su estado, filtra, busca y escapa lo que viene de los datos', function (): void {
    $app = jobsApp();
    $web = adminClient($app);
    $failed = jobsInsert($app, ['type' => 'x.failed', 'status' => 'failed', 'attempts' => 3, 'error' => 'Boom & <x>', 'finished_at' => $app->clock()->sql()]);
    jobsInsert($app, ['type' => 'x.done', 'status' => 'done', 'attempts' => 1, 'finished_at' => $app->clock()->sql()]);

    $r = $web->get('/admin/r/jobs');
    same(200, $r->status);
    contains('x.failed', $r->body);
    contains('x.done', $r->body);
    contains('Boom &amp; &lt;x&gt;', $r->body);
    notContains('Boom & <x>', $r->body);

    $r = $web->get('/admin/r/jobs?f%5Bstatus%5D=done');
    contains('x.done', $r->body);
    notContains('x.failed', $r->body);
    $r = $web->get('/admin/r/jobs?q=failed');
    contains('x.failed', $r->body);
    notContains('x.done', $r->body);

    $r = $web->get('/admin/r/jobs/' . $failed);
    same(200, $r->status);
    contains('Boom &amp; &lt;x&gt;', $r->body);
    contains('Reintentar', $r->body);
    same(403, $web->get('/admin/r/jobs/new')->status, 'los trabajos no se crean a mano');
    same(404, $web->get('/admin/r/jobs/99999')->status);
});

test('sin permiso no se ve nada (403); con jobs.view se ve pero no se puede reintentar ni ejecutar', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->schedule('demo.run', 3600, function (): void {
    });
    jobsViewerRole($app);
    $failed = jobsInsert($app, ['type' => 'x.failed', 'status' => 'failed', 'attempts' => 3, 'error' => 'e', 'finished_at' => $app->clock()->sql()]);

    $support = adminClient($app, 'support');
    same(403, $support->get('/admin/r/jobs')->status);
    same(403, $support->get('/admin/jobs/schedule')->status);
    $support->get('/admin');
    same(403, $support->post('/admin/jobs/retry/' . $failed, ['_token' => aifecToken($support)])->status);

    $viewer = adminClient($app, 'viewer');
    same(200, $viewer->get('/admin/r/jobs')->status);
    $r = $viewer->get('/admin/r/jobs/' . $failed);
    same(200, $r->status);
    notContains('Reintentar', $r->body);
    $r = $viewer->get('/admin/jobs/schedule');
    same(200, $r->status);
    contains('demo.run', $r->body);
    notContains('Ejecutar ahora', $r->body);
    notContains('/cron/', $r->body, 'la clave de la dirección web solo la ve quien puede editar');
    $token = aifecToken($viewer);
    same(403, $viewer->post('/admin/jobs/retry/' . $failed, ['_token' => $token])->status);
    same(403, $viewer->post('/admin/jobs/run/demo.run', ['_token' => $token])->status);
    same('failed', jobsRow($app, $failed)['status']);
});

test('«Retry» devuelve un fallido a pendiente y avisa; con un trabajo que no está fallido, no hace nada', function (): void {
    $app = jobsApp();
    $web = adminClient($app);
    $failed = jobsInsert($app, ['type' => 'x.failed', 'status' => 'failed', 'attempts' => 3, 'error' => 'e', 'finished_at' => $app->clock()->sql()]);
    $done = jobsInsert($app, ['type' => 'x.done', 'status' => 'done', 'attempts' => 1, 'finished_at' => $app->clock()->sql()]);

    $web->get('/admin/r/jobs/' . $failed);
    $r = $web->post('/admin/jobs/retry/' . $failed, ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('/admin/r/jobs/' . $failed, $r->headers['Location']);
    contains('El trabajo espera para ejecutarse de nuevo.', $web->follow()->body);
    $row = jobsRow($app, $failed);
    same('pending', $row['status']);
    same(0, (int) $row['attempts']);

    $r = $web->post('/admin/jobs/retry/' . $done, ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('Solo se pueden reintentar los trabajos fallidos.', $web->follow()->body);
    same('done', jobsRow($app, $done)['status']);

    same(404, $web->post('/admin/jobs/retry/99999', ['_token' => aifecToken($web)])->status);
});

test('la pantalla de tareas programadas enseña la dirección y la línea de cron, y «Run now» ejecuta la tarea', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $q->schedule('demo.run', 3600, function (): string {
        return 'nota & ñ';
    }, 'Tarea <de> prueba');
    $q->schedule('demo.fail', 'daily@04:10', function (): void {
        throw new \RuntimeException('se rompió & ñ');
    });
    $web = adminClient($app);

    $r = $web->get('/admin/jobs/schedule');
    same(200, $r->status);
    contains('/cron/' . $q->webKey(), $r->body);
    contains('cron --site=test', $r->body);
    contains('data-af-copy="', $r->body);
    contains('Tarea &lt;de&gt; prueba', $r->body);
    contains('Cada hora', $r->body);
    contains('Cada día a las 04:10', $r->body);
    contains('Ejecutar ahora', $r->body);
    contains('/admin/jobs/run/demo.run', $r->body);

    $r = $web->post('/admin/jobs/run/demo.run', ['_token' => aifecToken($web)]);
    same(302, $r->status);
    contains('La tarea «demo.run» ha terminado: nota &amp; ñ', $web->follow()->body);
    same(1, (int) jobsCronRow($app, 'demo.run')['runs']);

    $web->post('/admin/jobs/run/demo.fail', ['_token' => aifecToken($web)]);
    contains('La tarea «demo.fail» ha fallado: se rompió &amp; ñ', $web->follow()->body);
    same('se rompió & ñ', jobsCronRow($app, 'demo.fail')['last_error']);
    contains('se rompió &amp; ñ', $web->get('/admin/jobs/schedule')->body);

    same(404, $web->post('/admin/jobs/run/no.existe', ['_token' => aifecToken($web)])->status);
});

test('todas las pantallas del panel llevan el guion del latido, y /admin/jobs/tick contesta JSON y trabaja', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $web = adminClient($app);
    $r = $web->get('/admin');
    same(200, $r->status);
    contains('<script src="', $r->body);
    contains('tick.js', $r->body);
    contains('data-af-tick-url="/admin/jobs/tick"', $r->body);

    $q->register('demo.tick', function (): string {
        return 'latido';
    });
    $id = $q->push('demo.tick');

    // Sin la ficha del formulario, el latido no ejecuta nada.
    $token = aifecToken($web);
    $r = $web->post('/admin/jobs/tick');
    check($r->status !== 200, 'sin ficha no se acepta');
    same('pending', jobsRow($app, $id)['status']);

    $r = $web->post('/admin/jobs/tick', ['_token' => $token]);
    same(200, $r->status);
    contains('application/json', $r->headers['Content-Type']);
    $json = $web->json();
    same(true, $json['ok']);
    same(1, $json['work']['done']);
    same('done', jobsRow($app, $id)['status']);
    check($q->lastRun() !== null, 'el latido apunta que cron ha corrido');

    $anon = new TestClient($app);
    same(302, $anon->post('/admin/jobs/tick')->status, 'sin sesión del panel, a la entrada');
});

test('aviso de estado: sin cron reciente avisa; justo después de correr, no; y los fallidos de 24 h salen con su número', function (): void {
    $app = jobsApp();
    $q = jobsQueue($app);
    $web = adminClient($app);

    // El aviso lo da el inicio del panel con su propia frase (la del módulo no se repite al lado).
    $r = $web->get('/admin');
    contains('Las tareas programadas no se han ejecutado nunca', $r->body);
    notContains('Las tareas en segundo plano no se están ejecutando', $r->body, 'un solo aviso, no dos');
    contains('/admin/jobs/schedule', $r->body);

    $q->cron();
    notContains('Las tareas programadas no se', $web->get('/admin')->body);
    notContains('han fallado en las últimas 24 horas', $web->get('/admin')->body);
    notContains('ha fallado en las últimas 24 horas', $web->get('/admin')->body);

    $app->clock()->advance(901);
    contains('Las tareas programadas no se ejecutan desde hace', $web->get('/admin')->body);
    $q->cron();

    jobsInsert($app, ['status' => 'failed', 'finished_at' => $app->clock()->sql($app->clock()->now() - 3600), 'error' => 'x']);
    jobsInsert($app, ['status' => 'failed', 'finished_at' => $app->clock()->sql($app->clock()->now() - 90000), 'error' => 'viejo']);
    contains('1 trabajo en segundo plano ha fallado en las últimas 24 horas', $web->get('/admin')->body);
    jobsInsert($app, ['status' => 'failed', 'finished_at' => $app->clock()->sql(), 'error' => 'y']);
    contains('2 trabajos en segundo plano han fallado en las últimas 24 horas', $web->get('/admin')->body);

    // Quien no puede ver las tareas no recibe el aviso.
    $support = adminClient($app, 'support');
    notContains('Las tareas en segundo plano', $support->get('/admin')->body);
});

test('el servicio «jobs» está publicado, el módulo es esencial y sus ajustes tienen valores de serie', function (): void {
    $app = jobsApp();
    check($app->has('jobs'), 'el servicio jobs existe');
    same('Aifec\Modules\Jobs\Queue', get_class($app->service('jobs')));
    same(true, (bool) $app->modules()->manifest('jobs')['essential']);
    same(7, $app->settings()->int('jobs.keep_done_days'));
    same(30, $app->settings()->int('jobs.keep_failed_days'));

    $web = adminClient($app);
    $r = $web->get('/admin/settings/jobs');
    same(200, $r->status);
    contains('Días que se guardan los trabajos terminados', $r->body);
});
