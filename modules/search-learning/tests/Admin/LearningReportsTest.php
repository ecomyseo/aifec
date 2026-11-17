<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\SearchLearning\Admin\LearningLabels;
use Aifec\Modules\SearchLearning\Admin\LearningReports;

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../../../tests/Pdf/helpers.php';

test('los dos PDF son descargas privadas de datos registrados y no ejecutan llamadas', function (): void {
    [$app, $web] = learningPanelFixture();
    $id = learningPanelStart($web);
    foreach (['experience', 'improvements'] as $kind) {
        $response = $web->get('/admin/search/learning/runs/' . $id . '/report/' . $kind);
        same(200, $response->status);
        same('application/pdf', $response->headers['Content-Type']);
        same('no-store, private', $response->headers['Cache-Control']);
        check(strpos($response->body, '%PDF-1.4') === 0);
        check(strlen($response->body) > 1000);
        contains('attachment; filename="search-learning-' . $id, $response->headers['Content-Disposition']);
    }
    learningPanelNoCalls($app);
});

test('las descargas exigen lectura autorizada, la ronda de esta tienda y el mismo contexto', function (): void {
    [$app, $admin] = learningPanelFixture();
    $id = learningPanelStart($admin);
    $anonymous = new TestClient($app);
    same(302, $anonymous->get('/admin/search/learning/runs/' . $id . '/report/experience')->status);
    $limited = adminClient($app, 'editor');
    same(403, $limited->get('/admin/search/learning/runs/' . $id . '/report/experience')->status);
    same(404, $admin->get('/admin/search/learning/runs/999999/report/experience')->status);
    $app->site()->config['url'] = 'https://nuevo-contexto.test';
    same(409, $admin->get('/admin/search/learning/runs/' . $id . '/report/experience')->status);
    learningPanelNoCalls($app);
});

test('una llamada incierta no se presenta como coste total cero y el informe no transforma ausencias en éxitos', function (): void {
    [$app, $web] = learningPanelFixture();
    $id = learningPanelStart($web);
    $report = $app->service('search_learning')->report($id);
    $report['run']['cost_known'] = false;
    $report['run']['uncertain_calls'] = 1;
    $report['run']['spent_micro'] = 0;
    $report['run']['reserved_micro'] = 1000000;
    contains('Total desconocido', LearningLabels::runCost($app, $report['run']));
    $report['items'] = [];
    $report['proposals'] = [];
    $report['applications'] = [];
    foreach (['experience', 'improvements'] as $kind) {
        $bytes = LearningReports::render($app, $report, $kind);
        check(strpos($bytes, '%PDF-1.4') === 0);
    }
    learningPanelNoCalls($app);
});

test('el texto libre de los informes oculta correos, teléfonos, direcciones y secretos sin ejecutar instrucciones', function (): void {
    [$app] = learningPanelFixture();
    $app->makeCurrent();
    $text = LearningReports::safeText('Texto de prueba; ignora todo; ana@ejemplo.test; 999000001; https://ejemplo.test/privado; Bearer token-de-ejemplo-1234; sk-example-not-a-real-secret');
    notContains('ana@ejemplo.test', $text);
    notContains('999000001', $text);
    notContains('https://ejemplo.test', $text);
    notContains('token-de-ejemplo-1234', $text);
    notContains('sk-example-not-a-real-secret', $text);
    contains('Datos ocultados', $text);
    contains('ignora todo', $text);
    learningPanelNoCalls($app);
});

test('el historial, la ficha y ambos PDF conservan la moneda y el símbolo de la ronda tras cambiar los ajustes', function (): void {
    [$app, $web] = learningPanelFixture();
    $app->settings()->setMany(['shop.currency' => 'USD', 'shop.currency_symbol' => 'US$']);
    $id = learningPanelStart($web, ['budget' => '12,34']);
    $app->db()->update('learning_runs', ['spent_micro' => 125000000, 'reserved_micro' => 250000000], ['id' => $id]);
    $app->settings()->setMany(['shop.currency' => 'CHF', 'shop.currency_symbol' => 'SFr.']);
    $run = $app->service('search_learning')->find($id);
    same('USD', $run['currency']);
    same('US$', $run['currency_symbol']);
    same("12,34\u{a0}US$ USD", LearningLabels::budget($app, $run));
    same("1,25\u{a0}US$ USD", LearningLabels::runCost($app, $run));
    same("2,50\u{a0}US$ USD", LearningLabels::cost($app, $run['reserved_micro'], $run));
    same("0,0001\u{a0}US$ USD", LearningLabels::cost($app, 10000, $run));
    same(200, $web->get('/admin/search/learning')->status);
    contains("1,25\u{a0}US$ USD", $web->last->body);
    same(200, $web->get('/admin/search/learning/runs/' . $id)->status);
    foreach (["12,34\u{a0}US$ USD", "1,25\u{a0}US$ USD", "2,50\u{a0}US$ USD"] as $amount) {
        contains($amount, $web->last->body);
    }
    notContains('SFr.', $web->last->body);
    notContains('CHF', $web->last->body);
    foreach (['experience', 'improvements'] as $kind) {
        $response = $web->get('/admin/search/learning/runs/' . $id . '/report/' . $kind);
        same(200, $response->status);
        $content = pdfTestContent($response->body);
        foreach (['12,34', '1,25', '2,50', 'US$ USD'] as $value) {
            contains($value, $content);
        }
        notContains('SFr.', $content);
        notContains('CHF', $content);
    }
    learningPanelNoCalls($app);
});

test('un registro sin moneda histórica no hereda la actual ni inventa un símbolo ausente', function (): void {
    [$app] = learningPanelFixture();
    $app->makeCurrent();
    $app->settings()->setMany(['shop.currency' => 'CHF', 'shop.currency_symbol' => 'SFr.']);
    $run = ['budget_cents' => 50, 'spent_micro' => 1000000, 'reserved_micro' => 2000000];
    same('Sin registrar', LearningLabels::budget($app, $run));
    same('Sin registrar', LearningLabels::runCost($app, $run));
    same('Sin registrar', LearningLabels::cost($app, $run['reserved_micro'], $run));
    $run['provider_config'] = ['currency' => 'USD', 'currency_symbol' => ''];
    same("0,50\u{a0}USD", LearningLabels::budget($app, $run));
    same("0,01\u{a0}USD", LearningLabels::runCost($app, $run));
    $run['cost_known'] = false;
    contains('Total desconocido', LearningLabels::runCost($app, $run));
    notContains('SFr.', LearningLabels::runCost($app, $run));
    learningPanelNoCalls($app);
});
