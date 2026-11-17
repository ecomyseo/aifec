<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\SearchLearning\Admin\LearningAccess;
use Aifec\Modules\SearchLearning\Admin\LearningController;

require_once __DIR__ . '/helpers.php';

test('la pestaña explica el contexto actual y leerla no crea rondas, trabajos ni llamadas', function (): void {
    [$app, $web] = learningPanelFixture();
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {jobs}');
    same(200, $web->get('/admin/search/learning')->status);
    contains('Probar y aprender', $web->last->body);
    contains('https://tienda.test', $web->last->body);
    contains('expected_context', $web->last->body);
    contains('name="budget"', $web->last->body);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {learning_runs}'));
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {jobs}'));
    same(405, $web->get('/admin/search/learning/runs')->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {learning_runs}'));
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {jobs}'));
    learningPanelNoCalls($app);
});

test('un perfil con comodín y el administrador público no pueden iniciar ni mutar aprendizaje', function (): void {
    [$app, $admin] = learningPanelFixture();
    $runId = learningPanelStart($admin);
    $run = $app->service('search_learning')->find($runId);
    foreach (['wild_learning', 'demo_admin'] as $role) {
        if ($app->db()->value('SELECT id FROM {roles} WHERE code = ?', [$role]) === null) {
            $app->db()->insert('roles', ['code' => $role, 'name' => 'Perfil de prueba', 'caps' => '["*"]']);
        } else {
            $app->db()->update('roles', ['caps' => '["*"]'], ['code' => $role]);
        }
        $web = adminClient($app, $role);
        same(200, $web->get('/admin/search/learning')->status);
        notContains('name="authorise"', $web->last->body);
        $token = aifecToken($web);
        $form = ['_token' => $token, 'expected_context' => $run['context_hash'], 'expected_version' => '0', 'mode' => 'simulated', 'lang' => 'es', 'profiles' => '1', 'tier' => 'economy', 'budget' => '0,50', 'authorise' => 1, 'reviewed' => 1];
        same(403, $web->post('/admin/search/learning/runs', $form)->status);
        foreach (['resume', 'cancel', 'validate', 'apply', 'revert'] as $action) {
            same(403, $web->post('/admin/search/learning/runs/' . $runId . '/' . $action, $form)->status);
        }
    }
    same(1, (int) $app->db()->value('SELECT COUNT(*) FROM {learning_runs}'));
    same('running', $app->service('search_learning')->find($runId)['status']);
    learningPanelNoCalls($app);
});

test('el alta exige POST, ficha, consentimiento y presupuesto positivo sin aceptar proveedor ni clave del formulario', function (): void {
    [$app, $web] = learningPanelFixture();
    $form = learningPanelForm($web);
    $without = $form;
    unset($without['_token']);
    same(419, $web->post('/admin/search/learning/runs', $without, ['accept' => 'application/json'])->status);
    foreach (['', '0', '-1', '1e2', '1.001', '1000.01'] as $budget) {
        same(422, $web->post('/admin/search/learning/runs', array_replace($form, ['budget' => $budget]))->status);
    }
    same(422, $web->post('/admin/search/learning/runs', array_replace($form, ['authorise' => '0']))->status);
    $id = learningPanelStart($web, ['provider' => 'external', 'key' => 'not-a-real-key', 'site_id' => 'another-shop']);
    $run = $app->service('search_learning')->find($id);
    same('fake', $run['provider_config']['provider']);
    same($app->site()->id, $run['context']['site_id']);
    same(false, array_key_exists('key', $run['options']));
    same(false, array_key_exists('provider', $run['options']));
    same(50, $run['budget_cents']);
    learningPanelNoCalls($app);
});

test('la URL y la versión obsoletas se rechazan en servidor antes de encolar una ronda', function (): void {
    [$app, $web] = learningPanelFixture();
    $form = learningPanelForm($web);
    learningPanelVersion($app, 1);
    same(409, $web->post('/admin/search/learning/runs', $form)->status);
    $form = learningPanelForm($web);
    $app->site()->config['url'] = 'https://otra-tienda.test';
    same(409, $web->post('/admin/search/learning/runs', $form)->status);
    same(0, (int) $app->db()->value('SELECT COUNT(*) FROM {learning_runs}'));
    learningPanelNoCalls($app);
});

test('la cancelación exige el contexto original y detiene la ronda sin aplicar propuestas', function (): void {
    [$app, $web] = learningPanelFixture();
    $id = learningPanelStart($web);
    $run = $app->service('search_learning')->find($id);
    $web->get('/admin/search/learning/runs/' . $id);
    $form = ['_token' => aifecToken($web), 'expected_context' => str_repeat('0', 64), 'expected_version' => '0'];
    same(409, $web->post('/admin/search/learning/runs/' . $id . '/cancel', $form)->status);
    $form['expected_context'] = $run['context_hash'];
    same(303, $web->post('/admin/search/learning/runs/' . $id . '/cancel', $form)->status);
    same('cancelled', $app->service('search_learning')->find($id)['status']);
    same(0, count($app->service('search_learning')->applications($id)));
    learningPanelNoCalls($app);
});

test('un administrador desactivado no conserva permiso por la identidad guardada en caché', function (): void {
    [$app, $web] = learningPanelFixture();
    $web->get('/admin/search/learning');
    check(LearningAccess::canManage($app));
    $id = $app->auth()->adminId();
    $app->db()->update('users', ['active' => 0], ['id' => $id]);
    same(false, LearningAccess::canManage($app));
    learningPanelNoCalls($app);
});

test('el presupuesto del formulario se convierte a céntimos sin coma flotante', function (): void {
    same(1, LearningController::budget('0,01'));
    same(110, LearningController::budget('1.10'));
    same(100000, LearningController::budget('1000'));
});

test('aplicar sin revisión expresa o desde una versión obsoleta no cambia la memoria', function (): void {
    [$app, $web] = learningPanelFixture();
    $id = learningPanelStart($web);
    $run = $app->service('search_learning')->find($id);
    $web->get('/admin/search/learning/runs/' . $id);
    $form = ['_token' => aifecToken($web), 'expected_context' => $run['context_hash'], 'expected_version' => '0'];
    same(422, $web->post('/admin/search/learning/runs/' . $id . '/apply', $form)->status);
    learningPanelVersion($app, 1);
    $form['reviewed'] = 1;
    same(409, $web->post('/admin/search/learning/runs/' . $id . '/apply', $form)->status);
    same(0, count($app->service('search_learning')->applications($id)));
    learningPanelNoCalls($app);
});

test('la guardia del controlador comprueba también la ficha fuera del intermediario de rutas', function (): void {
    [$app, $web] = learningPanelFixture();
    $web->get('/admin/search/learning');
    $request = \Aifec\Kernel\Request::create('POST', '/admin/search/learning/runs', [], [], ['sec-fetch-site' => 'same-origin']);
    throws(static function () use ($app, $request): void { LearningAccess::mutate($app, $request); }, \Aifec\Kernel\HttpError::class);
    learningPanelNoCalls($app);
});
