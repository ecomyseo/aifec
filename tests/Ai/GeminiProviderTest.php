<?php
/**
 * AIFEC — pruebas del proveedor Gemini (sin red: FakeHttpClient).
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
use Aifec\Ai\GeminiProvider;
use Aifec\Http\FakeHttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;

const AI_GEM_KEY = 'AIza-SECRET-key-123';

/** @return array{0:GeminiProvider,1:FakeHttpClient,2:object} */
function aiGemSetup(): array
{
    $http = new FakeHttpClient();
    $log = new \stdClass();
    $log->sleeps = [];
    $sleep = static function (int $us) use ($log): void {
        $log->sleeps[] = $us;
    };

    return [new GeminiProvider($http, AI_GEM_KEY, 'gemini-flash-latest', 'gemini-embedding-001', 'https://generativelanguage.googleapis.com/v1beta/models', $sleep), $http, $log];
}

/** @param array<int,array<string,mixed>> $parts */
function aiGemOk(array $parts, string $finish = 'STOP', array $usage = ['promptTokenCount' => 12, 'candidatesTokenCount' => 7]): HttpResponse
{
    $payload = ['candidates' => [['content' => ['role' => 'model', 'parts' => $parts], 'finishReason' => $finish]], 'usageMetadata' => $usage];

    return new HttpResponse(200, [], (string) json_encode($payload, JSON_UNESCAPED_UNICODE));
}

function aiGemBody(HttpRequest $r): array
{
    $data = json_decode((string) $r->body, true);

    return is_array($data) ? $data : [];
}

test('Gemini: el cuerpo lleva systemInstruction, papeles traducidos, herramientas y thinkingConfig', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(aiGemOk([['text' => 'Hola']]));

    $req = new AiRequest(
        'Eres un asistente.',
        [
            AiMessage::user('Busca sillas'),
            AiMessage::assistant('Voy a buscar', [new AiToolCall('c1', 'search', ['q' => 'sillas'])]),
            AiMessage::tool('search', ['total' => 3]),
            AiMessage::user('¿Y cuántas hay?'),
        ],
        [new AiTool('search', 'Busca productos', ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]])],
        null,
        '',
        0.2,
        1000
    );
    $ai->generate($req);

    $r = $http->requests[0];
    $b = aiGemBody($r);
    same('POST', $r->method);
    same('Eres un asistente.', $b['systemInstruction']['parts'][0]['text']);
    same(['user', 'model', 'user', 'user'], array_column($b['contents'], 'role'));
    same('Busca sillas', $b['contents'][0]['parts'][0]['text']);
    same('Voy a buscar', $b['contents'][1]['parts'][0]['text']);
    same(['q' => 'sillas'], $b['contents'][1]['parts'][1]['functionCall']['args']);
    same('search', $b['contents'][1]['parts'][1]['functionCall']['name']);
    same('search', $b['contents'][2]['parts'][0]['functionResponse']['name']);
    same(['total' => 3], $b['contents'][2]['parts'][0]['functionResponse']['response']);
    same('search', $b['tools'][0]['functionDeclarations'][0]['name']);
    same('Busca productos', $b['tools'][0]['functionDeclarations'][0]['description']);
    same('low', $b['generationConfig']['thinkingConfig']['thinkingLevel']);
    same(1000, $b['generationConfig']['maxOutputTokens']);
    near(0.2, $b['generationConfig']['temperature']);
    contains('/gemini-flash-latest:generateContent', $r->url);
    check(!isset($b['toolConfig']), 'de serie el modelo decide si llama a una herramienta');

    // «Solo texto»: las herramientas siguen declaradas (la conversación lleva sus llamadas) pero el modelo no puede pedir otra.
    $http->queue(aiGemOk([['text' => 'Hay tres.']]));
    $req->textOnly = true;
    $ai->generate($req);
    $b = aiGemBody($http->requests[1]);
    same('search', $b['tools'][0]['functionDeclarations'][0]['name'], 'siguen declaradas');
    same(['functionCallingConfig' => ['mode' => 'NONE']], $b['toolConfig']);
    $http->queue(aiGemOk([['text' => 'Hola']]));
    $plain = new AiRequest('Eres un asistente.', [AiMessage::user('Hola')]);
    $plain->textOnly = true;
    $ai->generate($plain);
    check(!isset(aiGemBody($http->requests[2])['toolConfig']), 'sin herramientas no se manda nada de eso');
});

test('Gemini: la clave va en la cabecera y nunca en la URL ni en el cuerpo', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(aiGemOk([['text' => 'ok']]));
    $ai->generate(new AiRequest('', [AiMessage::user('hola')]));

    $r = $http->requests[0];
    same(AI_GEM_KEY, $r->headers['x-goog-api-key']);
    notContains(AI_GEM_KEY, $r->url);
    notContains('key=', $r->url);
    notContains(AI_GEM_KEY, (string) $r->body);
});

test('Gemini: el tope de salida nunca baja de 256 y sin sistema no hay systemInstruction', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(aiGemOk([['text' => 'ok']]));
    $ai->generate(new AiRequest('', [AiMessage::user('hola')], [], null, '', null, 10));

    $b = aiGemBody($http->requests[0]);
    same(256, $b['generationConfig']['maxOutputTokens']);
    same(false, isset($b['systemInstruction']));
    same(false, isset($b['generationConfig']['temperature']));
    same(false, isset($b['tools']));
});

test('Gemini: respuestas de herramienta seguidas van en un solo turno y los argumentos vacíos son {}', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(aiGemOk([['text' => 'ok']]));
    $ai->generate(new AiRequest('', [
        AiMessage::user('dos cosas'),
        AiMessage::assistant('', [new AiToolCall('a', 'uno', []), new AiToolCall('b', 'dos', [])]),
        AiMessage::tool('uno', []),
        AiMessage::tool('dos', ['x' => 1]),
    ]));

    $raw = (string) $http->requests[0]->body;
    contains('"args":{}', $raw);
    contains('"response":{}', $raw);
    $b = aiGemBody($http->requests[0]);
    same(3, count($b['contents']));
    same(2, count($b['contents'][2]['parts']));
    same('dos', $b['contents'][2]['parts'][1]['functionResponse']['name']);
});

test('Gemini: respuesta de texto con los tokens de usageMetadata', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(aiGemOk([['text' => 'Hola, '], ['text' => 'mundo ñandú 😀 & más']], 'STOP', ['promptTokenCount' => 20, 'candidatesTokenCount' => 5, 'thoughtsTokenCount' => 3]));
    $res = $ai->generate(new AiRequest('', [AiMessage::user('hola')]));

    same('Hola, mundo ñandú 😀 & más', $res->text);
    same([], $res->toolCalls);
    same(null, $res->json);
    same(20, $res->tokensIn);
    same(8, $res->tokensOut);
    same('STOP', $res->finishReason);
    same('gemini-flash-latest', $res->model);
});

test('Gemini: respuesta con functionCall y las partes de pensamiento no cuentan como texto', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(aiGemOk([
        ['text' => 'razonando…', 'thought' => true],
        ['functionCall' => ['name' => 'search', 'args' => ['q' => 'silla', 'n' => 2]]],
        ['functionCall' => ['name' => 'ping', 'args' => new stdClass()]],
    ]));
    $res = $ai->generate(new AiRequest('', [AiMessage::user('busca')]));

    same('', $res->text);
    same(2, count($res->toolCalls));
    same('search', $res->toolCalls[0]->name);
    same(['q' => 'silla', 'n' => 2], $res->toolCalls[0]->arguments);
    same('call_1', $res->toolCalls[0]->id);
    same('ping', $res->toolCalls[1]->name);
    same([], $res->toolCalls[1]->arguments);
});

test('Gemini: lo que acompaña a una llamada (la firma de pensamiento) vuelve intacto en el turno siguiente', function (): void {
    // Los Gemini que piensan mandan una firma en la misma parte que la llamada y contestan 400 («Function call is missing a
    // thought_signature») si la llamada vuelve sin ella: sin esto no hay ni una conversación con herramientas.
    [$ai, $http] = aiGemSetup();
    $signature = 'RmlybWEgZGUgcHJ1ZWJh';
    $http->queue(
        aiGemOk([
            ['text' => 'razonando…', 'thought' => true],
            ['functionCall' => ['name' => 'search', 'args' => ['q' => 'silla & mesa'], 'id' => 'fc-77'], 'thoughtSignature' => $signature],
            ['functionCall' => ['name' => 'ping', 'args' => new stdClass()]],
        ]),
        aiGemOk([['text' => 'listo']])
    );
    $first = $ai->generate(new AiRequest('', [AiMessage::user('busca')]));
    same('fc-77', $first->toolCalls[0]->id);
    same(['gemini' => ['part' => ['thoughtSignature' => $signature], 'id' => 'fc-77']], $first->toolCalls[0]->extra);
    same('call_2', $first->toolCalls[1]->id);
    same([], $first->toolCalls[1]->extra);

    $second = $ai->generate(new AiRequest('', [
        AiMessage::user('busca'),
        AiMessage::assistant('', $first->toolCalls),
        AiMessage::tool('search', ['n' => 1], $first->toolCalls[0]->id),
        AiMessage::tool('ping', [], $first->toolCalls[1]->id),
    ]));
    same('listo', $second->text);

    $b = aiGemBody($http->requests[1]);
    same('model', $b['contents'][1]['role']);
    same(
        ['functionCall' => ['name' => 'search', 'args' => ['q' => 'silla & mesa'], 'id' => 'fc-77'], 'thoughtSignature' => $signature],
        $b['contents'][1]['parts'][0]
    );
    // La llamada que vino sin nada más vuelve como siempre: sin firma inventada y sin identificador.
    same(['functionCall' => ['name' => 'ping', 'args' => []]], $b['contents'][1]['parts'][1]);
    same('fc-77', $b['contents'][2]['parts'][0]['functionResponse']['id']);
    same(false, isset($b['contents'][2]['parts'][1]['functionResponse']['id']));
});

test('Gemini: jsonSchema pide JSON, quita las claves no admitidas y decodifica la respuesta', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(aiGemOk([['text' => '{"titulo":"Silla","etiquetas":["a","b"]}']]));
    $schema = [
        '$schema' => 'http://json-schema.org/draft-07/schema#',
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'titulo' => ['type' => 'string'],
            'etiquetas' => ['type' => 'array', 'items' => ['type' => 'string', 'additionalProperties' => false]],
            'examples' => ['type' => 'string'],
        ],
        'required' => ['titulo'],
    ];
    $res = $ai->generate(new AiRequest('', [AiMessage::user('ficha')], [], $schema));

    $b = aiGemBody($http->requests[0]);
    $g = $b['generationConfig'];
    same('application/json', $g['responseMimeType']);
    same(false, isset($g['responseSchema']['$schema']));
    same(false, isset($g['responseSchema']['additionalProperties']));
    same(false, isset($g['responseSchema']['properties']['etiquetas']['items']['additionalProperties']));
    same('string', $g['responseSchema']['properties']['examples']['type'], 'un nombre de propiedad no se toca');
    same(['titulo' => 'Silla', 'etiquetas' => ['a', 'b']], $res->json);
});

test('Gemini: si la API contesta 400 se repite una sola vez sin thinkingConfig', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(new HttpResponse(400, [], '{"error":{"message":"thinking is not supported"}}'), aiGemOk([['text' => 'vale']]));
    $res = $ai->generate(new AiRequest('', [AiMessage::user('hola')]));

    same('vale', $res->text);
    same(2, count($http->requests));
    same(true, isset(aiGemBody($http->requests[0])['generationConfig']['thinkingConfig']));
    same(false, isset(aiGemBody($http->requests[1])['generationConfig']['thinkingConfig']));
    same(256 <= aiGemBody($http->requests[1])['generationConfig']['maxOutputTokens'], true);
});

test('Gemini: un 400 que se repite es una excepción no transitoria y no hay un tercer intento', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(new HttpResponse(400, [], '{"error":{"message":"bad schema"}}'), new HttpResponse(400, [], '{"error":{"message":"bad schema"}}'));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('hola')]));
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(false, $e->isTransient());
        same(400, $e->status());
        contains('bad schema', $e->getMessage());
    }
    same(2, count($http->requests));
});

test('Gemini: respuesta vacía por MAX_TOKENS es una excepción transitoria', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(aiGemOk([], 'MAX_TOKENS'));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('hola')]));
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(true, $e->isTransient());
        contains('MAX_TOKENS', $e->getMessage());
    }
});

test('Gemini: con jsonSchema, un texto que no es JSON es transitorio y no un JSON nulo', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(aiGemOk([['text' => '{"titulo": "cortado']], 'MAX_TOKENS'));
    throws(function () use ($ai): void {
        $ai->generate(new AiRequest('', [AiMessage::user('x')], [], ['type' => 'object']));
    }, AiException::class);
});

test('Gemini: 429 y después 200 hace un reintento tras una espera corta', function (): void {
    [$ai, $http, $log] = aiGemSetup();
    $http->queue(new HttpResponse(429, [], '{"error":{"message":"quota"}}'), aiGemOk([['text' => 'ya']]));
    $res = $ai->generate(new AiRequest('', [AiMessage::user('hola')]));

    same('ya', $res->text);
    same(2, count($http->requests));
    same(1, count($log->sleeps));
    check($log->sleeps[0] > 0 && $log->sleeps[0] <= 5000000, 'espera corta');
});

test('Gemini: 500 dos veces es una excepción transitoria con un solo reintento', function (): void {
    [$ai, $http, $log] = aiGemSetup();
    $http->queue(new HttpResponse(500, [], 'boom'), new HttpResponse(503, [], '<html>down</html>'));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('hola')]));
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(true, $e->isTransient());
        same(503, $e->status());
    }
    same(2, count($http->requests));
    same(1, count($log->sleeps));
});

test('Gemini: 403 es no transitoria y el mensaje no contiene la clave aunque la API la repita', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(new HttpResponse(403, [], '{"error":{"message":"The key ' . AI_GEM_KEY . ' has no access"}}'));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('hola')]));
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(false, $e->isTransient());
        same(403, $e->status());
        notContains(AI_GEM_KEY, $e->getMessage());
        contains('has no access', $e->getMessage());
    }
    same(1, count($http->requests), 'un 403 no se reintenta');
});

test('Gemini: un fallo de red es transitorio y tampoco filtra la clave', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(new HttpException('cURL error: timeout talking to host with ' . AI_GEM_KEY));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('hola')]));
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(true, $e->isTransient());
        same(0, $e->status());
        notContains(AI_GEM_KEY, $e->getMessage());
        contains('timeout', $e->getMessage());
    }
});

test('Gemini: una petición bloqueada por contenido no es transitoria', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(new HttpResponse(200, [], '{"promptFeedback":{"blockReason":"SAFETY"}}'));
    try {
        $ai->generate(new AiRequest('', [AiMessage::user('x')]));
        check(false, 'tenía que lanzar');
    } catch (AiException $e) {
        same(false, $e->isTransient());
        contains('SAFETY', $e->getMessage());
    }
});

test('Gemini: el modelo de la petición manda sobre el por defecto y acepta el prefijo models/', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(aiGemOk([['text' => 'a']]), aiGemOk([['text' => 'b']]));
    $ai->generate(new AiRequest('', [AiMessage::user('x')], [], null, 'gemini-3-pro-preview'));
    $ai->generate(new AiRequest('', [AiMessage::user('x')], [], null, 'models/gemini-flash-lite-latest'));

    contains('/models/gemini-3-pro-preview:generateContent', $http->requests[0]->url);
    contains('/models/gemini-flash-lite-latest:generateContent', $http->requests[1]->url);
});

test('Gemini: 120 textos son 3 peticiones, en orden y con los vectores normalizados', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->when('POST', ':batchEmbedContents', static function (HttpRequest $r): HttpResponse {
        $embeddings = [];
        $body = json_decode((string) $r->body, true);
        foreach ($body['requests'] as $item) {
            $n = (int) substr($item['content']['parts'][0]['text'], 1);
            $embeddings[] = ['values' => [(float) $n, 1.0, 0.0]];
        }

        return new HttpResponse(200, [], (string) json_encode(['embeddings' => $embeddings]));
    });
    $texts = [];
    for ($i = 0; $i < 120; ++$i) {
        $texts[] = 't' . $i;
    }
    $vectors = $ai->embed($texts);

    same(3, count($http->requests));
    same(120, count($vectors));
    $sizes = [];
    foreach ($http->requests as $req) {
        $sizes[] = count(aiGemBody($req)['requests']);
    }
    same([50, 50, 20], $sizes);
    $first = aiGemBody($http->requests[0])['requests'][0];
    same(768, $first['outputDimensionality']);
    same('models/gemini-embedding-001', $first['model']);
    notContains(AI_GEM_KEY, $http->requests[0]->url);
    foreach ([0, 49, 50, 99, 119] as $i) {
        near(1.0, sqrt($vectors[$i][0] ** 2 + $vectors[$i][1] ** 2 + $vectors[$i][2] ** 2), 1e-9, 'norma del vector ' . $i);
        if ($i > 0) {
            near((float) $i, $vectors[$i][0] / $vectors[$i][1], 1e-9, 'orden del vector ' . $i);
        }
    }
});

test('Gemini: embed sin textos no hace ninguna petición y un recuento erróneo lanza', function (): void {
    [$ai, $http] = aiGemSetup();
    same([], $ai->embed([]));
    same(0, count($http->requests));

    $http->queue(new HttpResponse(200, [], '{"embeddings":[{"values":[1,0]}]}'));
    throws(function () use ($ai): void {
        $ai->embed(['a', 'b']);
    }, AiException::class);
});

test('Gemini: embed acepta un texto vacío sin perder el orden', function (): void {
    [$ai, $http] = aiGemSetup();
    $http->queue(new HttpResponse(200, [], '{"embeddings":[{"values":[3,4]},{"values":[0,2]}]}'));
    $v = $ai->embed(['', 'ñ']);

    same(' ', aiGemBody($http->requests[0])['requests'][0]['content']['parts'][0]['text']);
    near(0.6, $v[0][0], 1e-9);
    near(0.8, $v[0][1], 1e-9);
    near(1.0, $v[1][1], 1e-9);
});
