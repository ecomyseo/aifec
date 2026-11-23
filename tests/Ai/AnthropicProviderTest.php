<?php
/**
 * AIFEC — pruebas del proveedor de Claude (Anthropic) y de los servicios de IA que se pueden elegir en los ajustes (sin red).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiException;
use Aifec\Ai\AiMessage;
use Aifec\Ai\AiRequest;
use Aifec\Ai\AiTool;
use Aifec\Ai\AiToolCall;
use Aifec\Ai\AnthropicProvider;
use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;
use Aifec\Modules\Ai\Ai;

const AI_ANT_KEY = 'sk-ant-SECRET-claude-123';

/** @return array{0:AnthropicProvider,1:FakeHttpClient} */
function aiAntSetup(): array
{
    $http = new FakeHttpClient();

    return [new AnthropicProvider($http, AI_ANT_KEY, 'claude-sonnet-5-5', static function (int $us): void {
    }), $http];
}

/** @return array<string,mixed> */
function aiAntBody(HttpRequest $r): array
{
    $data = json_decode((string) $r->body, true);

    return is_array($data) ? $data : [];
}

/** @param array<int,array<string,mixed>> $content */
function aiAntOk(array $content, string $stop = 'end_turn'): HttpResponse
{
    return new HttpResponse(200, [], (string) json_encode(['model' => 'claude-sonnet-5-5', 'content' => $content, 'stop_reason' => $stop,
        'usage' => ['input_tokens' => 21, 'output_tokens' => 7]], JSON_UNESCAPED_UNICODE));
}

test('Claude: la petición va a /v1/messages con x-api-key y anthropic-version, system aparte y max_tokens', function (): void {
    [$ai, $http] = aiAntSetup();
    $http->queue(aiAntOk([['type' => 'text', 'text' => 'Hola']]));
    $result = $ai->generate(new AiRequest('Eres el asistente de una tienda.', [AiMessage::user('Buenas')], [], null, '', 0.4, 300));
    same('Hola', $result->text);
    same(21, $result->tokensIn);
    same(7, $result->tokensOut);
    same('end_turn', $result->finishReason);
    $request = $http->requests[0];
    same('https://api.anthropic.com/v1/messages', $request->url);
    same(AI_ANT_KEY, $request->headers['x-api-key']);
    same('2023-06-01', $request->headers['anthropic-version']);
    $body = aiAntBody($request);
    same('claude-sonnet-5-5', $body['model']);
    same('Eres el asistente de una tienda.', $body['system']);
    same(300, $body['max_tokens']);
    same(0.4, $body['temperature']);
    same([['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Buenas']]]], $body['messages']);
    notContains(AI_ANT_KEY, (string) $request->body, 'la clave no va en el cuerpo');
    notContains(AI_ANT_KEY, $request->url, 'ni en la dirección');
    same('anthropic', $ai->name());
});

test('Claude: el tope de salida nunca baja de 256 y el modelo de la petición manda sobre el de serie', function (): void {
    [$ai, $http] = aiAntSetup();
    $http->queue(aiAntOk([['type' => 'text', 'text' => 'ok']]));
    $ai->generate(new AiRequest('', [AiMessage::user('x')], [], null, 'claude-opus-5-5', null, 10));
    $body = aiAntBody($http->requests[0]);
    same(256, $body['max_tokens']);
    same('claude-opus-5-5', $body['model']);
    check(!isset($body['system']), 'sin instrucciones no hay system');
    check(!isset($body['temperature']), 'ni temperatura');
});

test('Claude: herramientas, llamadas y resultados: ida y vuelta con tool_use y tool_result', function (): void {
    [$ai, $http] = aiAntSetup();
    $tool = new AiTool('buscar', 'Busca productos', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']]);
    $http->queue(aiAntOk([['type' => 'text', 'text' => 'Miro.'], ['type' => 'tool_use', 'id' => 'toolu_01', 'name' => 'buscar', 'input' => ['q' => 'silla']]], 'tool_use'));
    $first = $ai->generate(new AiRequest('s', [AiMessage::user('Quiero una silla')], [$tool]));
    same('Miro.', $first->text);
    same(1, count($first->toolCalls));
    same('buscar', $first->toolCalls[0]->name);
    same(['q' => 'silla'], $first->toolCalls[0]->arguments);
    same('toolu_01', $first->toolCalls[0]->id);
    $sent = aiAntBody($http->requests[0]);
    same('buscar', $sent['tools'][0]['name']);
    same('object', $sent['tools'][0]['input_schema']['type']);
    check(!isset($sent['tool_choice']), 'sin textOnly no se fija tool_choice');

    $http->queue(aiAntOk([['type' => 'text', 'text' => 'Hay 3 sillas.']]));
    $second = new AiRequest('s', [
        AiMessage::user('Quiero una silla'),
        AiMessage::assistant('Miro.', [new AiToolCall('toolu_01', 'buscar', ['q' => 'silla'])]),
        AiMessage::tool('buscar', ['total' => 3], 'toolu_01'),
    ], [$tool]);
    $second->textOnly = true;
    $ai->generate($second);
    $body = aiAntBody($http->requests[1]);
    same(['type' => 'none'], $body['tool_choice'], 'textOnly: el modelo no puede pedir otra herramienta');
    $roles = array_column($body['messages'], 'role');
    same(['user', 'assistant', 'user'], $roles, 'los turnos alternan usuario y asistente');
    same('tool_use', $body['messages'][1]['content'][1]['type']);
    same('tool_result', $body['messages'][2]['content'][0]['type']);
    same('toolu_01', $body['messages'][2]['content'][0]['tool_use_id']);
    same('{"total":3}', $body['messages'][2]['content'][0]['content']);
});

test('Claude: dos resultados de herramienta seguidos van en un solo turno de usuario y los argumentos vacíos son {}', function (): void {
    [$ai, $http] = aiAntSetup();
    $http->queue(aiAntOk([['type' => 'text', 'text' => 'Listo']]));
    $ai->generate(new AiRequest('', [
        AiMessage::user('Mira el carrito y el envío'),
        AiMessage::assistant('', [new AiToolCall('a', 'cart_view', []), new AiToolCall('b', 'shipping', [])]),
        AiMessage::tool('cart_view', [], 'a'),
        AiMessage::tool('shipping', ['ok' => true], 'b'),
    ]));
    $body = aiAntBody($http->requests[0]);
    same(['user', 'assistant', 'user'], array_column($body['messages'], 'role'));
    same(2, count($body['messages'][2]['content']), 'los dos resultados, en el mismo turno');
    contains('"input":{}', (string) $http->requests[0]->body, 'argumentos vacíos como objeto JSON');
    contains('"content":"{}"', (string) $http->requests[0]->body);
});

test('Claude: una conversación que no empieza por el usuario se arregla y no queda un turno vacío', function (): void {
    [$ai, $http] = aiAntSetup();
    $http->queue(aiAntOk([['type' => 'text', 'text' => 'ok']]));
    $ai->generate(new AiRequest('', [AiMessage::assistant('Hola, ¿en qué te ayudo?')]));
    $body = aiAntBody($http->requests[0]);
    same('user', $body['messages'][0]['role']);
    same('assistant', $body['messages'][1]['role']);
});

test('Claude: jsonSchema se pide en las instrucciones y la respuesta se decodifica', function (): void {
    [$ai, $http] = aiAntSetup();
    $http->queue(aiAntOk([['type' => 'text', 'text' => "```json\n{\"categoria\":\"sillas\"}\n```"]]));
    $result = $ai->generate(new AiRequest('Clasifica.', [AiMessage::user('silla roja')], [], ['type' => 'object', 'properties' => ['categoria' => ['type' => 'string']]]));
    same(['categoria' => 'sillas'], $result->json);
    $body = aiAntBody($http->requests[0]);
    contains('JSON Schema', $body['system']);
    contains('"categoria"', $body['system']);
    contains('Clasifica.', $body['system']);
    $http->queue(aiAntOk([['type' => 'text', 'text' => 'no es json']]));
    throws(function () use ($ai): void {
        $ai->generate(new AiRequest('', [AiMessage::user('x')], [], ['type' => 'object']));
    }, AiException::class);
});

test('Claude: errores: 401 no es transitorio, 429 sí, una respuesta vacía por tope se avisa y la clave no sale nunca', function (): void {
    [$ai, $http] = aiAntSetup();
    $http->queue(new HttpResponse(401, [], (string) json_encode(['error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key ' . AI_ANT_KEY]])));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('x')]));
        throw new \RuntimeException('tenía que fallar');
    } catch (AiException $e) {
        same(false, $e->isTransient(), 'un 401 no se reintenta');
        notContains(AI_ANT_KEY, $e->getMessage(), 'el mensaje no lleva la clave');
        contains('HTTP 401', $e->getMessage());
    }
    [$ai2, $http2] = aiAntSetup();
    $http2->queue(new HttpResponse(429, [], '{}'), new HttpResponse(429, [], '{}'));
    try {
        $ai2->generate(new AiRequest('', [AiMessage::user('x')]));
        throw new \RuntimeException('tenía que fallar');
    } catch (AiException $e) {
        same(true, $e->isTransient(), 'un 429 es transitorio');
        same(2, count($http2->requests), 'y se reintenta una vez');
    }
    [$ai3, $http3] = aiAntSetup();
    $http3->queue(aiAntOk([], 'max_tokens'));
    throws(function () use ($ai3): void {
        $ai3->generate(new AiRequest('', [AiMessage::user('x')]));
    }, AiException::class);
    [$ai4, $http4] = aiAntSetup();
    $http4->queue(new HttpException('timeout contra ' . AI_ANT_KEY));
    try {
        $ai4->generate(new AiRequest('', [AiMessage::user('x')]));
        throw new \RuntimeException('tenía que fallar');
    } catch (AiException $e) {
        notContains(AI_ANT_KEY, $e->getMessage(), 'ni un fallo de red enseña la clave');
    }
});

test('Claude: no hay vectores y lo dice', function (): void {
    [$ai] = aiAntSetup();
    throws(function () use ($ai): void {
        $ai->embed(['hola']);
    }, AiException::class);
});

test('los ajustes ofrecen Claude, ChatGPT, Grok, Mistral, DeepSeek, Groq, OpenRouter y Perplexity además de Gemini y los compatibles', function (): void {
    $settings = require dirname(__DIR__, 2) . '/modules/ai/settings.php';
    $options = $settings['tabs']['aimodels']['fields']['ai.economy.provider']['options'];
    foreach (['fake', 'gemini', 'anthropic', 'chatgpt', 'grok', 'mistral', 'deepseek', 'groq', 'openrouter', 'perplexity', 'openai'] as $kind) {
        check(isset($options[$kind]), 'se puede elegir ' . $kind);
    }
    same(array_keys($options), Ai::KINDS, 'y la lista de los ajustes es la que admite el código');
});

test('cada servicio elegido en los ajustes monta su proveedor con su dirección y su modelo, y pide su clave', function (): void {
    $cases = [
        'chatgpt' => ['https://api.openai.com/v1/chat/completions', 'openai-compat'],
        'grok' => ['https://api.x.ai/v1/chat/completions', 'openai-compat'],
        'mistral' => ['https://api.mistral.ai/v1/chat/completions', 'openai-compat'],
        'deepseek' => ['https://api.deepseek.com/v1/chat/completions', 'openai-compat'],
        'groq' => ['https://api.groq.com/openai/v1/chat/completions', 'openai-compat'],
        'openrouter' => ['https://openrouter.ai/api/v1/chat/completions', 'openai-compat'],
        'perplexity' => ['https://api.perplexity.ai/chat/completions', 'openai-compat'],
        'anthropic' => ['https://api.anthropic.com/v1/messages', 'anthropic'],
    ];
    foreach ($cases as $kind => [$url, $name]) {
        $http = new FakeHttpClient();
        $app = testApp(['modules' => ['ai'], 'http' => $http, 'settings' => ['ai.enabled' => true, 'ai.economy.provider' => $kind, 'ai.economy.model' => $kind === 'anthropic' ? '' : 'un-modelo']]);
        $app->boot();
        $app->setSecret('ai.economy.key', 'clave-de-' . $kind);
        /** @var Ai $ai */
        $ai = $app->service('ai');
        same($kind, $ai->kind('economy'));
        $provider = $ai->provider('economy');
        same($name, $provider->name(), $kind);
        $http->queue($kind === 'anthropic' ? aiAntOk([['type' => 'text', 'text' => 'ok']]) : new HttpResponse(200, [], (string) json_encode(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => []])));
        $provider->generate(new AiRequest('', [AiMessage::user('x')]));
        same($url, $http->requests[0]->url, $kind . ': su dirección');
        if ($kind === 'anthropic') {
            same('claude-haiku-5-5', aiAntBody($http->requests[0])['model'], 'Claude: el modelo del nivel economía si los ajustes no escriben uno');
        } else {
            same('un-modelo', aiAntBody($http->requests[0])['model'], $kind . ': el modelo de los ajustes');
        }
    }
    // Sin clave, un servicio con nombre no se monta; sin modelo, tampoco (salvo Claude, que tiene uno por nivel).
    $app = testApp(['modules' => ['ai'], 'settings' => ['ai.enabled' => true, 'ai.economy.provider' => 'grok', 'ai.economy.model' => 'x']]);
    $app->boot();
    throws(function () use ($app): void {
        $app->service('ai')->provider('economy');
    }, AiException::class);
    $app->setSecret('ai.economy.key', 'k');
    $app->settings()->set('ai.economy.model', '');
    throws(function () use ($app): void {
        $app->service('ai')->provider('economy');
    }, AiException::class);
    // La dirección de los ajustes manda sobre la del servicio (un proxy propio).
    $http = new FakeHttpClient();
    $app = testApp(['modules' => ['ai'], 'http' => $http, 'settings' => ['ai.enabled' => true, 'ai.economy.provider' => 'grok', 'ai.economy.model' => 'm', 'ai.economy.base_url' => 'https://proxy.tienda.test/v1']]);
    $app->boot();
    $app->setSecret('ai.economy.key', 'k');
    $http->queue(new HttpResponse(200, [], (string) json_encode(['choices' => [['message' => ['content' => 'ok'], 'finish_reason' => 'stop']], 'usage' => []])));
    $app->service('ai')->provider('economy')->generate(new AiRequest('', [AiMessage::user('x')]));
    same('https://proxy.tienda.test/v1/chat/completions', $http->requests[0]->url);
});
