<?php
/**
 * AIFEC — pruebas del servicio «ai»: apagada, gasto apuntado, error sin claves, tope diario, niveles y modelos, caché por contenido,
 * y los proveedores que se montan desde los ajustes (con un cliente HTTP de mentira). Ninguna prueba llama a una IA de verdad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

use Aifec\Ai\AiException;
use Aifec\Ai\AiMessage;
use Aifec\Ai\AiResult;
use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpResponse;
use Aifec\Modules\Ai\Ai;
use Aifec\Modules\Ai\AiDisabled;
use Aifec\Modules\Ai\AiLimitReached;
use Aifec\Modules\Ai\Usage;

require_once __DIR__ . '/helpers.php';

test('con la IA apagada (el valor de fábrica) ask() lanza «AI is off» y no llama a nadie ni apunta nada', function (): void {
    $app = aiApp(['ai.enabled' => false]);
    $ai = $app->service('ai');
    check(!$ai->enabled(), 'apagada');
    throws(function () use ($ai): void {
        $ai->ask('test.task', ['prompt' => 'hola']);
    }, AiDisabled::class);
    try {
        $ai->ask('test.task', ['prompt' => 'hola']);
    } catch (AiException $e) {
        same('AI is off', $e->getMessage());
    }
    same([], aiFake($app)->requests, 'ninguna llamada al proveedor');
    same([], aiRows($app, 'ai_usage'), 'ni una fila de gasto');
});

test('un sitio nuevo trae la IA apagada: sin tocar nada, ask() no funciona', function (): void {
    $app = testApp(['modules' => ['ai']]);
    $app->boot();
    check(!$app->service('ai')->enabled());
    throws(function () use ($app): void {
        $app->service('ai')->ask('test.task', ['prompt' => 'hola']);
    }, AiDisabled::class);
});

test('encendida contesta y apunta en ai_usage el día, la tarea, el nivel, el proveedor, el modelo, las fichas, el coste y el tiempo', function (): void {
    $app = aiApp();
    aiFake($app)->script(new AiResult('Hola, mundo', [], null, 1000, 500, 'stop', 'gemini-flash-latest'));
    $result = $app->service('ai')->ask('product.test', ['system' => 'Sé breve', 'messages' => [AiMessage::user('Saluda')]]);
    same('Hola, mundo', $result->text);

    $rows = aiRows($app, 'ai_usage');
    same(1, count($rows));
    $row = $rows[0];
    same('2026-11-12', $row['day']);
    same('product.test', $row['task']);
    same('economy', $row['tier']);
    same('fake', $row['provider']);
    same('gemini-flash-latest', $row['model']);
    same(1000, (int) $row['tokens_in']);
    same(500, (int) $row['tokens_out']);
    same(1000 * 100 + 500 * 400, (int) $row['cost_micro'], 'fichas por céntimos por millón, en enteros');
    same(1, (int) $row['ok']);
    same(null, $row['error']);
    check((int) $row['ms'] >= 0);
    same('Sé breve', aiFake($app)->requests[0]->system);
    same('Saluda', aiFake($app)->requests[0]->messages[0]->content);
});

test('un error del proveedor se apunta como fallo, sin la clave, y se relanza sin la clave', function (): void {
    $app = aiApp();
    $app->setSecret('ai.economy.key', 'clave-de-mentira-123');
    $app->bind('ai.provider.economy', static function (): AiFailingProvider {
        return new AiFailingProvider('HTTP 503 de https://api.ejemplo.test/?key=clave-de-mentira-123 con clave-de-mentira-123');
    });
    try {
        $app->service('ai')->ask('product.test', ['prompt' => 'x']);
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        notContains('clave-de-mentira-123', $e->getMessage());
        contains('***', $e->getMessage());
        check($e->isTransient());
        same(503, $e->status());
    }
    $row = aiRows($app, 'ai_usage')[0];
    same(0, (int) $row['ok']);
    notContains('clave-de-mentira-123', (string) $row['error']);
    contains('***', (string) $row['error']);
    same(0, (int) $row['cost_micro']);
});

test('el tope diario: con el gasto de hoy en el tope no se llama, y al día siguiente vuelve a dejar', function (): void {
    $app = aiApp(['ai.daily_limit_cents' => 1]);
    $ai = $app->service('ai');
    aiFake($app)->script(new AiResult('uno', [], null, 10000, 0), new AiResult('dos', [], null, 10, 0));

    same('uno', $ai->ask('t', ['prompt' => 'a'])->text);
    same(1000000, Usage::spentMicro($app), 'exactamente un céntimo gastado');
    check($ai->limitReached());

    throws(function () use ($ai): void {
        $ai->ask('t', ['prompt' => 'b']);
    }, AiLimitReached::class);
    try {
        $ai->ask('t', ['prompt' => 'b']);
    } catch (AiException $e) {
        same('Daily AI limit reached', $e->getMessage());
    }
    same(1, count(aiFake($app)->requests), 'la segunda ni llegó al proveedor');
    same(1, count(aiRows($app, 'ai_usage')));

    $app->clock()->advance(86400);
    check(!$ai->limitReached(), 'otro día, otra cuenta');
    same('dos', $ai->ask('t', ['prompt' => 'b'])->text);
});

test('el tope de llamadas para la IA aunque no se hayan puesto los precios y el gasto estimado sea cero', function (): void {
    $app = aiApp(['ai.daily_limit_calls' => 2, 'ai.economy.price_in' => 0, 'ai.economy.price_out' => 0]);
    $ai = $app->service('ai');
    aiFake($app)->script(new AiResult('uno', [], null, 9000, 9000), new AiResult('dos', [], null, 9000, 9000), new AiResult('tres', [], null, 10, 10));

    same('uno', $ai->ask('t', ['prompt' => 'a'])->text);
    check(!$ai->limitReached(), 'una de dos');
    same('dos', $ai->ask('t', ['prompt' => 'b'])->text);
    same(0, Usage::spentMicro($app), 'sin precios, el gasto estimado es cero');
    same(2, Usage::calls($app));
    check($ai->limitReached(), 'y aun así el tope de llamadas lo para');
    throws(function () use ($ai): void {
        $ai->ask('t', ['prompt' => 'c']);
    }, AiLimitReached::class);
    same(2, count(aiFake($app)->requests), 'la tercera ni llegó al proveedor');

    $app->clock()->advance(86400);
    same(0, Usage::calls($app), 'otro día, otra cuenta');
    same('tres', $ai->ask('t', ['prompt' => 'c'])->text);
});

test('el tope de llamadas es de 500 al día si no se cambia, y con cero bloquea la IA', function (): void {
    same(500, Usage::limitCalls(aiApp()));
    $app = aiApp(['ai.daily_limit_calls' => 0]);
    throws(function () use ($app): void {
        $app->service('ai')->ask('t', ['prompt' => 'a']);
    }, AiLimitReached::class);
    same([], aiFake($app)->requests);
});

test('un tope de cero bloquea la IA entera', function (): void {
    $app = aiApp(['ai.daily_limit_cents' => 0]);
    throws(function () use ($app): void {
        $app->service('ai')->ask('t', ['prompt' => 'a']);
    }, AiLimitReached::class);
    same([], aiFake($app)->requests);
});

test('cada nivel usa su proveedor y su modelo, y el modelo vacío es gemini-flash-latest', function (): void {
    $app = aiApp(['ai.economy.model' => 'gemini-3-flash-preview', 'ai.standard.model' => '', 'ai.advanced.model' => 'gemini-3-pro-preview']);
    $ai = $app->service('ai');
    foreach (['economy', 'standard', 'advanced'] as $tier) {
        $ai->ask('t.' . $tier, ['prompt' => 'x'], $tier);
    }
    same('gemini-3-flash-preview', aiFake($app, 'economy')->requests[0]->model);
    same('gemini-flash-latest', aiFake($app, 'standard')->requests[0]->model);
    same('gemini-3-pro-preview', aiFake($app, 'advanced')->requests[0]->model);
    same(1, count(aiFake($app, 'economy')->requests));
    same(1, count(aiFake($app, 'standard')->requests));
    same(1, count(aiFake($app, 'advanced')->requests));
    same(['economy', 'standard', 'advanced'], array_column(aiRows($app, 'ai_usage'), 'tier'));
    throws(function () use ($ai): void {
        $ai->ask('t', ['prompt' => 'x'], 'gratis');
    }, \InvalidArgumentException::class);
});

test('la caché por contenido: la misma tarea con la misma petición no vuelve a llamar ni a gastar; otra petición sí', function (): void {
    $app = aiApp();
    $ai = $app->service('ai');
    aiFake($app)->script(aiJson(['a' => 1], 100, 50), aiJson(['a' => 2], 100, 50));
    $request = ['system' => 'S', 'prompt' => 'misma', 'cache_ttl' => 3600];

    $first = $ai->ask('t', $request);
    $second = $ai->ask('t', $request);
    same(['a' => 1], $first->json);
    same(['a' => 1], $second->json, 'la copia lleva lo mismo');
    same(1, count(aiFake($app)->requests), 'el proveedor solo se llamó una vez');
    same(1, count(aiRows($app, 'ai_usage')), 'y solo se apuntó una vez');

    same(['a' => 2], $ai->ask('t', ['system' => 'S', 'prompt' => 'otra', 'cache_ttl' => 3600])->json);
    same(2, count(aiFake($app)->requests));
    $ai->ask('otra.tarea', $request);
    same(3, count(aiFake($app)->requests), 'otra tarea no comparte la copia');

    $app->clock()->advance(3601);
    catalogNewRequest($app); // otra petición: la caché olvida su memoria y lee del disco, con su caducidad
    $ai->ask('t', $request);
    same(4, count(aiFake($app)->requests), 'pasado el tiempo se vuelve a pedir');
});

test('sin cache_ttl no hay caché: dos peticiones iguales son dos llamadas', function (): void {
    $app = aiApp();
    $ai = $app->service('ai');
    $ai->ask('t', ['prompt' => 'igual']);
    $ai->ask('t', ['prompt' => 'igual']);
    same(2, count(aiFake($app)->requests));
});

test('con el proveedor «fake» de los ajustes se usa el simulado sin publicar ningún servicio', function (): void {
    $app = testApp(['modules' => ['ai'], 'settings' => ['ai.enabled' => true, 'ai.economy.provider' => 'fake']]);
    $app->boot();
    $result = $app->service('ai')->ask('t', ['prompt' => 'una dos tres']);
    contains('[simulado] una dos tres', $result->text);
    same('fake', aiRows($app, 'ai_usage')[0]['provider']);
});

test('Gemini desde los ajustes: la clave va en la cabecera y nunca en la dirección ni en el registro, con un cliente HTTP de mentira', function (): void {
    $http = new FakeHttpClient();
    $http->queue(new HttpResponse(200, [], (string) json_encode([
        'candidates' => [['content' => ['parts' => [['text' => 'Hola desde Gemini']]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 12, 'candidatesTokenCount' => 3],
    ])));
    $app = testApp(['modules' => ['ai'], 'http' => $http, 'settings' => ['ai.enabled' => true, 'ai.economy.provider' => 'gemini', 'ai.economy.price_in' => 100]]);
    $app->boot();
    $app->setSecret('ai.economy.key', 'clave-de-mentira-123');

    $result = $app->service('ai')->ask('t', ['prompt' => 'hola']);
    same('Hola desde Gemini', $result->text);
    same(1, count($http->requests));
    contains('generativelanguage.googleapis.com', $http->requests[0]->url);
    contains('gemini-flash-latest', $http->requests[0]->url);
    notContains('clave-de-mentira-123', $http->requests[0]->url);
    $row = aiRows($app, 'ai_usage')[0];
    same('gemini', $row['provider']);
    same(12, (int) $row['tokens_in']);
    same(1200, (int) $row['cost_micro']);
});

test('Gemini sin clave falla con un mensaje claro, apunta el fallo y no sale a la red', function (): void {
    $http = new FakeHttpClient();
    $app = testApp(['modules' => ['ai'], 'http' => $http, 'settings' => ['ai.enabled' => true]]);
    $app->boot();
    try {
        $app->service('ai')->ask('t', ['prompt' => 'hola']);
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        contains('no API key', $e->getMessage());
    }
    same([], $http->requests);
    same(0, (int) aiRows($app, 'ai_usage')[0]['ok']);
});

test('embed() cuenta en el gasto como cualquier llamada y respeta el interruptor', function (): void {
    $app = aiApp();
    $vectors = $app->service('ai')->embed(['uno', 'dos']);
    same(2, count($vectors));
    $row = aiRows($app, 'ai_usage')[0];
    same('embed', $row['task']);
    check((int) $row['tokens_in'] > 0);

    $off = aiApp(['ai.enabled' => false]);
    throws(function () use ($off): void {
        $off->service('ai')->embed(['uno']);
    }, AiDisabled::class);
});

test('Usage::cents formatea con enteros y respeta la coma del idioma', function (): void {
    $app = aiApp();
    same('0,00', Usage::cents($app, 0));
    same('1,00', Usage::cents($app, 1000000));
    same('0,30', Usage::cents($app, 300000));
    same('0,01', Usage::cents($app, 5000));
    same('12,35', Usage::cents($app, 12345678));
});
