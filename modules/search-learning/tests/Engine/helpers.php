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
use Aifec\Ai\AiProvider;
use Aifec\Ai\AiRequest;
use Aifec\Ai\AiResult;
use Aifec\Kernel\App;
use Aifec\Modules\SearchLearning\Engine\Learning;

require_once __DIR__ . '/../../../search/tests/helpers.php';

/** Proveedor controlado que permite observar la reserva y cambiar el contexto DURANTE una respuesta. */
final class LearningEngineProvider implements AiProvider
{
    public array $requests = [];
    public $callback = null;
    public array $answers = [];
    public function name(): string { return 'fake'; }
    public function generate(AiRequest $request): AiResult
    {
        $this->requests[] = $request;
        if ($this->callback !== null) { ($this->callback)($request); }
        $answer = array_shift($this->answers);
        if ($answer instanceof \Throwable) { throw $answer; }
        if (!$answer instanceof AiResult) { throw new AiException('No scripted answer'); }
        return $answer;
    }
    public function embed(array $texts, string $model = ''): array { throw new AiException('Embedding is not used by learning'); }
}

function learningEngineFixture(array $settings = []): array
{
    $app = searchApp($settings + ['ai.enabled' => true, 'ai.economy.provider' => 'fake', 'ai.economy.price_in' => 0, 'ai.economy.price_out' => 0,
        'modules.active' => ['catalog', 'search', 'media', 'jobs', 'ai', 'search-learning']], ['jobs', 'ai', 'search-learning']);
    $target = aifecProduct($app, ['name' => 'Tocador Nube', 'summary' => 'Superficie de maquillaje con espejo.', 'price' => 10000, 'stock' => 3]);
    aifecProduct($app, ['name' => 'Mesa Roble', 'summary' => 'Mesa para el comedor.', 'price' => 20000, 'stock' => 4]);
    $app->service('search')->reindexNow();
    $provider = new LearningEngineProvider();
    $app->bind('ai.provider.economy', static function () use ($provider): LearningEngineProvider { return $provider; });
    $web = adminClient($app);
    $actor = (int) $app->auth()->adminId();
    $app->request()->method = 'POST';
    return [$app, $app->service('search_learning'), $provider, $actor, $target, $web];
}

function learningEngineOptions(Learning $engine, array $overrides = []): array
{
    return $overrides + ['mode' => 'simulated', 'lang' => 'es', 'profiles' => 1, 'budget_cents' => 100, 'tier' => 'economy',
        'expected_context' => $engine->context('es')['hash'], 'expected_version' => $engine->version('es')];
}

function learningEngineAnswer(int $target, string $query = 'maquillándome'): AiResult
{
    $data = ['cases' => [['profile' => 'Persona ficticia con poca experiencia', 'query' => $query, 'target_id' => $target,
        'evidence' => 'Superficie de maquillaje con espejo.', 'replacement' => 'Tocador', 'reason' => 'La ficha describe el uso y el mueble.', 'rank_ids' => []]]];
    return new AiResult(json_encode($data, JSON_UNESCAPED_UNICODE), [], $data, 150, 80, 'STOP', 'modelo-simulado');
}

function learningEngineReady(App $app, Learning $engine, LearningEngineProvider $provider, int $actor, int $target, string $query = 'maquillándome'): array
{
    $app->request()->method = 'POST';
    $provider->answers[] = learningEngineAnswer($target, $query);
    $id = $engine->createRun(learningEngineOptions($engine), $actor);
    same('ready', $engine->tick($id));
    $run = $engine->find($id);
    return [$id, $run['context_hash'], (int) $run['version']];
}
