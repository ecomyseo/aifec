<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Modules\SearchLearning\Engine\Corpus;

require_once __DIR__ . '/helpers.php';

test('la API solo propone: el buscador real comprueba las mismas consultas y controles antes de aplicar', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    [$id, $scope, $version] = learningEngineReady($app, $engine, $provider, $actor, $target);
    same(0, $engine->version('es'));
    check($engine->proposals($id) !== []);
    foreach ($engine->proposals($id) as $proposal) { same('pending', $proposal['status']); }
    $result = $engine->validate($id, $actor, $scope, $version);
    same(true, $result['passed']); same(0, $result['regressions']); check($result['improvements'] > 0);
    same(0, $engine->version('es'), 'validar no aplica memoria');
    $application = $engine->apply($id, $actor, $scope, $version);
    same(1, $application['version']);
    $found = (new Corpus($app))->ask('maquillándome', 'es');
    check(in_array($target, $found['ids'], true));
    same(1, count($provider->requests), 'evaluar y aplicar no llaman al proveedor');
    check(array_filter($engine->items($id), static function (array $item): bool { return $item['kind'] === 'regression'; }) !== []);
});

test('una evidencia inventada o una ronda sin mejora medible no se dan por aprendidas', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    $bad = learningEngineAnswer($target)->json;
    $bad['cases'][0]['evidence'] = 'Una afirmación que el catálogo no contiene';
    $provider->answers[] = new Aifec\Ai\AiResult(json_encode($bad), [], $bad, 10, 20);
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    same('ready', $engine->tick($id));
    $run = $engine->find($id);
    $check = $engine->validate($id, $actor, $run['context_hash'], 0);
    same(false, $check['passed']); check($check['unverifiable'] > 0);
    throws(static function () use ($engine, $actor, $id, $run): void { $engine->apply($id, $actor, $run['context_hash'], 0); }, \RuntimeException::class);
    same(0, $engine->version('es'));
});

test('el evaluador acepta alternativas válidas y no fabrica un fallo por el ID elegido por la IA', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    $other = aifecProduct($app, ['name' => 'Tocador Otro', 'summary' => 'Superficie de maquillaje con espejo.', 'stock' => 3]);
    $app->service('search')->reindexNow();
    $corpus = new Corpus($app);
    $catalogue = $corpus->sample('es', 20);
    $targets = $corpus->targets('maquillándome', 'es', $catalogue);
    check(in_array($target, $targets, true)); check(in_array($other, $targets, true));
    same(10, Corpus::score(['ids' => [$target, $other]], $targets));
    same(10, Corpus::score(['ids' => [$other, $target]], $targets));
});

test('los cambios de catálogo o versión invalidan la revisión antes de aplicar', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    [$id, $scope, $version] = learningEngineReady($app, $engine, $provider, $actor, $target);
    $engine->validate($id, $actor, $scope, $version);
    $app->db()->update('product_i18n', ['summary' => 'Ahora tiene otro uso.'], ['product_id' => $target, 'lang' => 'es']);
    throws(static function () use ($engine, $id, $actor, $scope, $version): void { $engine->apply($id, $actor, $scope, $version); }, \RuntimeException::class);
    same(0, $engine->version('es'));
});

test('preview restaura memoria y memoizaciones aunque el evaluador termine con una excepción', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    $corpus = new Corpus($app);
    $product = $corpus->product($target, 'es');
    $before = $corpus->ask('maquillándome', 'es');
    $rule = ['kind' => 'intent', 'source' => $before['text'], 'target' => 'Tocador', 'product_ids' => [$target], 'evidence' => ['product_id' => $target, 'hash' => $product['hash']]];
    $memory = $app->service('search_learning.memory');
    throws(static function () use ($memory, $rule, $corpus, $target): void {
        $memory->preview('es', [$rule], static function () use ($corpus, $target): void {
            check(in_array($target, $corpus->ask('maquillándome', 'es')['ids'], true));
            throw new \LogicException('Interrupción de prueba');
        });
    }, \LogicException::class);
    same($before['ids'], $corpus->ask('maquillándome', 'es')['ids']);
    same(0, $engine->version('es'));
});

test('revertir conserva aplicaciones posteriores y exige el orden inverso y la versión actual', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture();
    [$first, $scope] = learningEngineReady($app, $engine, $provider, $actor, $target);
    $engine->validate($first, $actor, $scope, 0);
    $a = $engine->apply($first, $actor, $scope, 0);
    [$second] = learningEngineReady($app, $engine, $provider, $actor, $target, 'maquilladoramente');
    $engine->validate($second, $actor, $scope, 1);
    $b = $engine->apply($second, $actor, $scope, 1);
    throws(static function () use ($engine, $a, $actor, $scope): void { $engine->revert($a['id'], $actor, $scope, 2); }, \RuntimeException::class);
    same(2, $engine->version('es'));
    same(3, $engine->revert($b['id'], $actor, $scope, 2)['version']);
    same(4, $engine->revert($a['id'], $actor, $scope, 3)['version']);
    same([], $app->service('search_learning.memory')->state('es')['memory']);
});
