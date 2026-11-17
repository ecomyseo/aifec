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

test('el análisis histórico rechaza datos personales y no mezcla los casos simulados con search_log', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    $day = substr($app->clock()->sql(), 0, 10);
    foreach (['maquillándome', 'persona@cliente.test', 'llamar al 999000001'] as $phrase) {
        $app->db()->insert('search_log', ['day' => $day, 'lang' => 'es', 'phrase' => $phrase, 'missed' => 1, 'hits' => 3]);
    }
    $before = $app->db()->select('SELECT * FROM {search_log} ORDER BY id');
    $id = $engine->createRun(learningEngineOptions($engine, ['mode' => 'history', 'from' => $day, 'to' => $day, 'profiles' => 3]), $actor);
    same(['maquillándome'], $engine->find($id)['options']['queries']);
    $provider->answers[] = learningEngineAnswer($target);
    same('ready', $engine->tick($id));
    $run = $engine->find($id);
    $engine->validate($id, $actor, $run['context_hash'], 0);
    same($before, $app->db()->select('SELECT * FROM {search_log} ORDER BY id'));
    $report = $engine->report($id);
    same(true, $report['simulated']);
    foreach ($provider->requests as $request) {
        foreach ($request->messages as $message) {
            notContains('persona@cliente.test', $message->content); notContains('999000001', $message->content);
            contains('SEARCH_LEARNING_DATA', $message->content);
        }
    }
});

test('los ensayos no encolan embeddings fuera del presupuesto aunque la tienda use vectores', function (): void {
    [$app, $engine, $provider] = learningEngineFixture();
    aifecProduct($app, ['name' => 'Tocador Claro', 'summary' => 'Superficie de maquillaje.', 'stock' => 3]);
    $app->service('search')->reindexNow();
    $app->settings()->set('search.vectors', true);
    $before = (int) $app->db()->value('SELECT COUNT(*) FROM {jobs} WHERE type = ?', ['search.embed']);
    (new Aifec\Modules\SearchLearning\Engine\Corpus($app))->ask('tocador', 'es');
    same($before, (int) $app->db()->value('SELECT COUNT(*) FROM {jobs} WHERE type = ?', ['search.embed']));
    same([], $provider->requests);
    $app->service('search')->forget();
    $app->service('search')->query('tocador', ['lang' => 'es']);
    check((int) $app->db()->value('SELECT COUNT(*) FROM {jobs} WHERE type = ?', ['search.embed']) > $before, 'el ámbito de ensayo no cambia la configuración normal de la tienda');
});
