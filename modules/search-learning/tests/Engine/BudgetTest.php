<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiException;

require_once __DIR__ . '/helpers.php';

test('la reserva existe antes del proveedor, sin transacción abierta, y el coste se concilia con fichas registradas', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture(['ai.economy.price_in' => 100, 'ai.economy.price_out' => 400]);
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    $provider->answers[] = learningEngineAnswer($target);
    $provider->callback = static function ($request) use ($app, $engine, $id): void {
        check(!$app->db()->pdo()->inTransaction(), 'la BD queda libre durante HTTP');
        check($engine->find($id)['reserved_micro'] > 0);
        same(false, $request->allowRetries, 'no hay reintentos ocultos del transporte');
        same(1536, $request->maxOutputTokens);
    };
    $engine->tick($id);
    $run = $engine->find($id);
    same(47000, $run['spent_micro']);
    same(0, $run['reserved_micro']);
    same(150, $run['tokens_in']); same(80, $run['tokens_out']); same(1, $run['calls']);
    same(true, $run['cost_known']);
    same(1, count($provider->requests));
});

test('no se promete presupuesto de pago sin tarifas ni se llama cuando no cabe la reserva superior', function (): void {
    [$app, $engine, $provider, $actor] = learningEngineFixture(['ai.economy.provider' => 'gemini']);
    throws(static function () use ($engine, $actor): void { $engine->createRun(learningEngineOptions($engine), $actor); }, \InvalidArgumentException::class);
    same([], $engine->recent());
    $app->settings()->setMany(['ai.economy.price_in' => 10000000, 'ai.economy.price_out' => 10000000]);
    $id = $engine->createRun(learningEngineOptions($engine, ['budget_cents' => 1]), $actor);
    same('paused', $engine->tick($id));
    same('learning.budget', $engine->find($id)['error']);
    same(0, $engine->find($id)['calls']);
    same([], $provider->requests);
});

test('una llamada incierta conserva la reserva y no se repite automáticamente ni al reanudar', function (): void {
    [$app, $engine, $provider, $actor] = learningEngineFixture(['ai.economy.price_in' => 100, 'ai.economy.price_out' => 100]);
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    $provider->answers[] = new AiException('La respuesta se perdió después del envío');
    same('paused', $engine->tick($id));
    $run = $engine->find($id);
    check($run['reserved_micro'] > 0); same(false, $run['cost_known']); same(1, $run['uncertain_calls']);
    same('paused', $engine->tick($id));
    throws(static function () use ($engine, $actor, $id, $run): void { $engine->resume($id, $actor, $run['context_hash'], $run['version']); }, \RuntimeException::class);
    same(1, count($provider->requests));
    same([], $engine->proposals($id));
});

test('los límites globales de IA liberan una reserva que no salió y permiten reanudar solo por POST explícito', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture(['ai.daily_limit_calls' => 0]);
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    same('paused', $engine->tick($id));
    $run = $engine->find($id);
    same('learning.global_ai_limit', $run['error']); same(0, $run['calls']); same(0, $run['reserved_micro']);
    same([], $provider->requests);
    $app->settings()->set('ai.daily_limit_calls', 500);
    $app->request()->method = 'GET';
    throws(static function () use ($engine, $actor, $id, $run): void { $engine->resume($id, $actor, $run['context_hash'], 0); }, \RuntimeException::class);
    $app->request()->method = 'POST';
    $engine->resume($id, $actor, $run['context_hash'], 0);
    $provider->answers[] = learningEngineAnswer($target);
    same('ready', $engine->tick($id)); same(1, count($provider->requests));
});

test('una desviación de fichas respecto a la reserva queda registrada y detiene nuevos trabajos', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture(['ai.economy.price_in' => 100, 'ai.economy.price_out' => 100]);
    $id = $engine->createRun(learningEngineOptions($engine, ['budget_cents' => 10]), $actor);
    $data = learningEngineAnswer($target)->json;
    $provider->answers[] = new Aifec\Ai\AiResult(json_encode($data), [], $data, 900000, 10000, 'STOP', 'fake');
    same('paused', $engine->tick($id));
    same('learning.usage_deviation', $engine->find($id)['error']);
    same(91000000, $engine->find($id)['spent_micro']);
    same([], $engine->proposals($id));
});

test('un proveedor de pago sin uso completo informado conserva la reserva y no encadena llamadas gratis', function (): void {
    foreach ([[0, 0], [150, 0], [0, 80]] as $usage) {
        [$app, $engine, $provider, $actor, $target] = learningEngineFixture(['ai.economy.provider' => 'gemini', 'ai.economy.price_in' => 100, 'ai.economy.price_out' => 100]);
        $id = $engine->createRun(learningEngineOptions($engine), $actor);
        $data = learningEngineAnswer($target)->json;
        $provider->answers[] = new Aifec\Ai\AiResult(json_encode($data), [], $data, $usage[0], $usage[1], 'STOP', 'simulador-offline');
        same('paused', $engine->tick($id));
        $run = $engine->find($id);
        same('learning.usage_unknown', $run['error']);
        same(false, $run['cost_known']); check($run['reserved_micro'] > 0);
        same([], $engine->proposals($id));
        same('paused', $engine->tick($id)); same(1, count($provider->requests));
    }
});

test('la conciliación atribuye el registro de su llamada y detecta una tarifa cambiada durante el envío', function (): void {
    [$app, $engine, $provider, $actor, $target] = learningEngineFixture(['ai.economy.price_in' => 100, 'ai.economy.price_out' => 100]);
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    $provider->answers[] = learningEngineAnswer($target);
    $provider->callback = static function () use ($app): void { $app->settings()->set('ai.economy.price_out', 200); };
    same('paused', $engine->tick($id));
    $run = $engine->find($id);
    same('learning.provider_changed', $run['error']);
    same(31000, $run['spent_micro'], 'se conserva el coste de la fila exacta de AI Usage');
    same([], $engine->proposals($id));
});
