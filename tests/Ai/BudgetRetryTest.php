<?php
/**
 * AIFEC — una petición presupuestada no oculta un segundo envío de pago.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiException;
use Aifec\Ai\AiMessage;
use Aifec\Ai\AiProvider;
use Aifec\Ai\AiRequest;
use Aifec\Ai\GeminiProvider;
use Aifec\Ai\OpenAiCompatProvider;
use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpResponse;

require_once __DIR__ . '/../../modules/ai/tests/helpers.php';

function budgetRetryProvider(string $kind, FakeHttpClient $http, object $waits): AiProvider
{
    $sleep = static function (int $microseconds) use ($waits): void { ++$waits->count; };
    return $kind === 'gemini'
        ? new GeminiProvider($http, 'clave-de-prueba', 'gemini-flash-latest', 'embed', 'https://proveedor.example.test/models', $sleep)
        : new OpenAiCompatProvider($http, 'clave-de-prueba', 'https://proveedor.example.test/v1', 'chat', 'embed', $sleep);
}

function budgetRetrySuccess(string $kind): HttpResponse
{
    $body = $kind === 'gemini'
        ? ['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => 'AIFEC']]], 'finishReason' => 'STOP']], 'usageMetadata' => ['promptTokenCount' => 5, 'candidatesTokenCount' => 2]]
        : ['model' => 'chat', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'AIFEC'], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]];
    return new HttpResponse(200, [], (string) json_encode($body));
}

test('una petición presupuestada no reenvía errores 429 ni 5xx en ninguno de los dos proveedores', function (): void {
    foreach (['gemini', 'openai'] as $kind) {
        foreach ([429, 503] as $status) {
            $http = new FakeHttpClient();
            $waits = (object) ['count' => 0];
            $provider = budgetRetryProvider($kind, $http, $waits);
            $http->queue(new HttpResponse($status, [], '{"error":{"message":"Rechazo de prueba"}}'), budgetRetrySuccess($kind));
            $request = new AiRequest('', [AiMessage::user('Prueba ficticia')]);
            $request->allowRetries = false;
            $failed = false;
            try {
                $provider->generate($request);
            } catch (AiException $error) {
                $failed = true;
                same($status, $error->status());
            }
            check($failed, 'la respuesta transitoria se devuelve al control de presupuesto');
            same(1, count($http->requests), 'solo se envía una petición al proveedor');
            same(0, $waits->count, 'no hay espera de reintento');
        }
    }
});

test('Gemini tampoco repite un 400 quitando opciones cuando se prohíben reintentos', function (): void {
    $http = new FakeHttpClient();
    $provider = budgetRetryProvider('gemini', $http, (object) ['count' => 0]);
    $http->queue(new HttpResponse(400, [], '{"error":{"message":"Opción no admitida"}}'), budgetRetrySuccess('gemini'));
    $request = new AiRequest('', [AiMessage::user('Prueba ficticia')]);
    $request->allowRetries = false;
    $failed = false;
    try {
        $provider->generate($request);
    } catch (AiException $error) {
        $failed = true;
        same(400, $error->status());
    }
    check($failed);
    same(1, count($http->requests));
});

test('las peticiones normales conservan su reintento transitorio habitual', function (): void {
    foreach (['gemini', 'openai'] as $kind) {
        $http = new FakeHttpClient();
        $waits = (object) ['count' => 0];
        $provider = budgetRetryProvider($kind, $http, $waits);
        $http->queue(new HttpResponse(503, [], '{"error":"Temporal"}'), budgetRetrySuccess($kind));
        $result = $provider->generate(new AiRequest('', [AiMessage::user('Prueba ficticia')]));
        same('AIFEC', $result->text);
        same(2, count($http->requests));
        same(1, $waits->count);
    }
});

test('el servicio común conserva la prohibición de reintento indicada por la ronda', function (): void {
    $app = aiApp();
    $app->service('ai')->ask('search.learning.test', ['prompt' => 'Prueba ficticia', 'retry' => false]);
    same(false, aiFake($app)->requests[0]->allowRetries);
    $app->service('ai')->ask('normal.test', ['prompt' => 'Prueba ficticia']);
    same(true, aiFake($app)->requests[1]->allowRetries);
});

test('la conciliación obtiene solo la llamada concreta del sitio y no expone errores ni contenido', function (): void {
    $app = aiApp();
    $ai = $app->service('ai');
    aiFake($app)->script(aiJson(['case' => 'primero'], 120, 30), aiJson(['case' => 'otro'], 900, 80));
    $ai->ask('search.learning.test', ['prompt' => 'Primera prueba ficticia', 'retry' => false]);
    $id = $ai->lastUsageId();
    $expected = $ai->usage($id);
    $ai->ask('different.task', ['prompt' => 'Otra prueba independiente']);
    same($expected, $ai->usage($id), 'la llamada posterior no se suma a la anterior');
    same(['id', 'task', 'tier', 'provider', 'model', 'tokens_in', 'tokens_out', 'cost_micro', 'ok'], array_keys($expected));
    same(120, $expected['tokens_in']);
    same(30, $expected['tokens_out']);
    same(true, $expected['ok']);
    check(is_int($expected['cost_micro']));
    same(null, $ai->usage(0));
    same(null, $ai->usage(99999999));
    $other = aiApp();
    same(null, $other->service('ai')->usage($id), 'otra tienda no encuentra esta llamada');
});
