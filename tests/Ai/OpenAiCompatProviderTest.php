<?php
/**
 * AIFEC — pruebas del proveedor compatible con OpenAI (sin red: FakeHttpClient).
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
use Aifec\Ai\OpenAiCompatProvider;
use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;

const AI_OAI_KEY = 'sk-SECRET-openai-999';

/** @return array{0:OpenAiCompatProvider,1:FakeHttpClient} */
function aiOaiSetup(string $base = 'https://api.mistral.test/v1', string $embedModel = 'embed-small'): array
{
    $http = new FakeHttpClient();

    return [new OpenAiCompatProvider($http, AI_OAI_KEY, $base, 'chat-model', $embedModel, static function (int $us): void {
    }), $http];
}

function aiOaiBody(HttpRequest $r): array
{
    $data = json_decode((string) $r->body, true);

    return is_array($data) ? $data : [];
}

/** @param array<string,mixed> $message */
function aiOaiOk(array $message, string $finish = 'stop'): HttpResponse
{
    return new HttpResponse(200, [], (string) json_encode([
        'model' => 'chat-model-2026',
        'choices' => [['message' => $message + ['role' => 'assistant'], 'finish_reason' => $finish]],
        'usage' => ['prompt_tokens' => 31, 'completion_tokens' => 9],
    ], JSON_UNESCAPED_UNICODE));
}

test('OpenAI compatible: mensajes con papel system, herramientas de tipo function y cabecera Authorization', function (): void {
    [$ai, $http] = aiOaiSetup();
    $http->queue(aiOaiOk(['content' => 'Hola']));
    $res = $ai->generate(new AiRequest(
        'Eres un asistente.',
        [AiMessage::user('Hola ñ & 😀')],
        [new AiTool('search', 'Busca', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]])],
        null,
        '',
        0.5,
        100
    ));

    $r = $http->requests[0];
    $b = aiOaiBody($r);
    same('https://api.mistral.test/v1/chat/completions', $r->url);
    same('Bearer ' . AI_OAI_KEY, $r->headers['Authorization']);
    notContains(AI_OAI_KEY, $r->url);
    same('chat-model', $b['model']);
    same(['system', 'user'], array_column($b['messages'], 'role'));
    same('Hola ñ & 😀', $b['messages'][1]['content']);
    same('function', $b['tools'][0]['type']);
    same('search', $b['tools'][0]['function']['name']);
    same('string', $b['tools'][0]['function']['parameters']['properties']['q']['type']);
    check(!isset($b['tool_choice']), 'de serie el modelo decide si llama a una herramienta');
    $only = new AiRequest('Eres un asistente.', [AiMessage::user('Hola')], [new AiTool('search', 'Busca', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]])]);
    $only->textOnly = true;
    $http->queue(aiOaiOk(['content' => 'Hola']));
    $ai->generate($only);
    $onlyBody = aiOaiBody($http->requests[1]);
    same('none', $onlyBody['tool_choice'], '«solo texto»: declaradas, pero sin poder llamarlas');
    same('search', $onlyBody['tools'][0]['function']['name']);
    same(256, $b['max_tokens'], 'el mínimo efectivo es 256');
    near(0.5, $b['temperature']);
    same('Hola', $res->text);
    same(31, $res->tokensIn);
    same(9, $res->tokensOut);
    same('stop', $res->finishReason);
    same('chat-model-2026', $res->model);
});

test('OpenAI compatible: ida y vuelta de tool_calls y mensajes tool', function (): void {
    [$ai, $http] = aiOaiSetup();
    $http->queue(aiOaiOk(['content' => null, 'tool_calls' => [
        ['id' => 'call_9', 'type' => 'function', 'function' => ['name' => 'search', 'arguments' => '{"q":"silla","n":2}']],
    ]], 'tool_calls'));
    $first = $ai->generate(new AiRequest('', [AiMessage::user('busca silla')]));

    same('', $first->text);
    same(1, count($first->toolCalls));
    same('call_9', $first->toolCalls[0]->id);
    same('search', $first->toolCalls[0]->name);
    same(['q' => 'silla', 'n' => 2], $first->toolCalls[0]->arguments);
    same('tool_calls', $first->finishReason);

    $http->queue(aiOaiOk(['content' => 'Hay 3']));
    $second = $ai->generate(new AiRequest('', [
        AiMessage::user('busca silla'),
        AiMessage::assistant('', $first->toolCalls),
        AiMessage::tool('search', ['total' => 3]),
    ]));
    same('Hay 3', $second->text);

    $msgs = aiOaiBody($http->requests[1])['messages'];
    same(['user', 'assistant', 'tool'], array_column($msgs, 'role'));
    same(null, $msgs[1]['content']);
    same('call_9', $msgs[1]['tool_calls'][0]['id']);
    same('function', $msgs[1]['tool_calls'][0]['type']);
    same('{"q":"silla","n":2}', $msgs[1]['tool_calls'][0]['function']['arguments']);
    same('call_9', $msgs[2]['tool_call_id'], 'se casa por nombre con la llamada pendiente');
    same('{"total":3}', $msgs[2]['content']);
});

test('OpenAI compatible: dos llamadas del mismo turno se casan cada una con su resultado', function (): void {
    [$ai, $http] = aiOaiSetup();
    $http->queue(aiOaiOk(['content' => 'ok']));
    $ai->generate(new AiRequest('', [
        AiMessage::user('x'),
        AiMessage::assistant('', [new AiToolCall('a1', 'uno', []), new AiToolCall('b2', 'dos', ['k' => 'ñ'])]),
        AiMessage::tool('dos', ['r' => 2]),
        AiMessage::tool('uno', [], 'a1'),
    ]));

    $msgs = aiOaiBody($http->requests[0])['messages'];
    same('{}', $msgs[1]['tool_calls'][0]['function']['arguments']);
    same('b2', $msgs[2]['tool_call_id']);
    same('a1', $msgs[3]['tool_call_id']);
    same('{}', $msgs[3]['content']);
});

test('OpenAI compatible: jsonSchema usa response_format json_schema y decodifica la respuesta', function (): void {
    [$ai, $http] = aiOaiSetup();
    $http->queue(aiOaiOk(['content' => "```json\n{\"ok\":true}\n```"]));
    $res = $ai->generate(new AiRequest('', [AiMessage::user('x')], [], ['type' => 'object', 'properties' => ['ok' => ['type' => 'boolean']]]));

    $b = aiOaiBody($http->requests[0]);
    same('json_schema', $b['response_format']['type']);
    same('boolean', $b['response_format']['json_schema']['schema']['properties']['ok']['type']);
    same(['ok' => true], $res->json);
});

test('OpenAI compatible: api.openai.com usa max_completion_tokens y sin clave no hay cabecera', function (): void {
    [$ai, $http] = aiOaiSetup('https://api.openai.com/v1/');
    $http->queue(aiOaiOk(['content' => 'a']));
    $ai->generate(new AiRequest('', [AiMessage::user('x')], [], null, '', null, 800));
    $b = aiOaiBody($http->requests[0]);
    same(800, $b['max_completion_tokens']);
    same(false, isset($b['max_tokens']));
    same('https://api.openai.com/v1/chat/completions', $http->requests[0]->url);

    $http2 = new FakeHttpClient();
    $local = new OpenAiCompatProvider($http2, '', 'http://localhost:11434/v1', 'llama');
    $http2->queue(aiOaiOk(['content' => 'a']));
    $local->generate(new AiRequest('', [AiMessage::user('x')]));
    same([], array_intersect_key($http2->requests[0]->headers, ['Authorization' => 1]));
});

test('OpenAI compatible: un 401 no es transitorio, un 429 sí, y la clave no sale en el mensaje', function (): void {
    [$ai, $http] = aiOaiSetup();
    $http->queue(new HttpResponse(401, [], '{"error":{"message":"Incorrect API key provided: ' . AI_OAI_KEY . '"}}'));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('x')]));
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(false, $e->isTransient());
        same(401, $e->status());
        notContains(AI_OAI_KEY, $e->getMessage());
    }

    $http->queue(new HttpResponse(429, [], '{}'), new HttpResponse(429, [], '{}'));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('x')]));
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(true, $e->isTransient());
        same(429, $e->status());
    }

    $http->queue(new HttpException('connection reset ' . AI_OAI_KEY));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('x')]));
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(true, $e->isTransient());
        notContains(AI_OAI_KEY, $e->getMessage());
    }
});

test('OpenAI compatible: respuesta vacía cortada por length es transitoria', function (): void {
    [$ai, $http] = aiOaiSetup();
    $http->queue(aiOaiOk(['content' => ''], 'length'));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('x')]));
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(true, $e->isTransient());
    }
});

test('OpenAI compatible: embed devuelve vectores normalizados en orden aunque lleguen desordenados', function (): void {
    [$ai, $http] = aiOaiSetup();
    $http->queue(new HttpResponse(200, [], (string) json_encode(['data' => [
        ['index' => 1, 'embedding' => [0.0, 5.0]],
        ['index' => 0, 'embedding' => [3.0, 4.0]],
    ]])));
    $v = $ai->embed(['uno', 'dos']);

    $r = $http->requests[0];
    same('https://api.mistral.test/v1/embeddings', $r->url);
    same(['model' => 'embed-small', 'input' => ['uno', 'dos']], aiOaiBody($r));
    near(0.6, $v[0][0], 1e-9);
    near(0.8, $v[0][1], 1e-9);
    near(1.0, $v[1][1], 1e-9);
});

test('OpenAI compatible: embed sin modelo de vectores lanza una excepción no transitoria', function (): void {
    [$ai, $http] = aiOaiSetup('https://api.mistral.test/v1', '');
    try {
        $ai->embed(['hola']);
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(false, $e->isTransient());
    }
    same(0, count($http->requests));
    $http->queue(new HttpResponse(200, [], '{"data":[{"index":0,"embedding":[1,0]}]}'));
    same([[1.0, 0.0]], $ai->embed(['hola'], 'otro-modelo'), 'un modelo dado en la llamada sí vale');
});
