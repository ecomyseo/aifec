<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Ai;

use Aifec\Http\HttpClient;

/**
 * Proveedor Gemini (API REST de Google). La clave va en la cabecera x-goog-api-key, nunca en la URL.
 *
 * Los Gemini actuales PIENSAN dentro de maxOutputTokens: se pide pensar poco (thinkingLevel low),
 * nunca menos de 256 tokens de salida; si se permiten reintentos y la API contesta 400, se repite una vez sin thinkingConfig.
 */
final class GeminiProvider implements AiProvider
{
    private const EMBED_BATCH = 50;
    private const EMBED_DIMENSIONS = 768;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $defaultModel;

    /** @var string */
    private $embeddingModel;

    /** @var string */
    private $baseUrl;

    /** @var AiTransport */
    private $transport;

    /**
     * @param callable|null $sleep recibe microsegundos; por defecto usleep (se inyecta en las pruebas)
     */
    public function __construct(
        HttpClient $http,
        string $apiKey,
        string $defaultModel = 'gemini-flash-latest',
        string $embeddingModel = 'gemini-embedding-001',
        string $baseUrl = 'https://generativelanguage.googleapis.com/v1beta/models',
        ?callable $sleep = null
    ) {
        $this->apiKey = $apiKey;
        $this->defaultModel = $defaultModel;
        $this->embeddingModel = $embeddingModel;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = new AiTransport($http, $apiKey, $sleep ?? 'usleep');
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function generate(AiRequest $r): AiResult
    {
        $model = $r->model !== '' ? $r->model : $this->defaultModel;
        $url = $this->modelUrl($model, 'generateContent');
        $body = $this->buildBody($r);

        $response = $this->transport->post($url, $body, $this->headers(), $r->timeout, $r->allowRetries);
        if ($r->allowRetries && $response->status === 400 && isset($body['generationConfig']['thinkingConfig'])) {
            // Hay modelos que no admiten thinkingConfig: se repite una sola vez sin él.
            unset($body['generationConfig']['thinkingConfig']);
            $response = $this->transport->post($url, $body, $this->headers(), $r->timeout, $r->allowRetries);
        }
        $data = $this->transport->decode($response);

        return $this->parse($data, $r, $model);
    }

    public function embed(array $texts, string $model = ''): array
    {
        $texts = array_values($texts);
        if ($texts === []) {
            return [];
        }
        $model = $model !== '' ? $model : $this->embeddingModel;
        $url = $this->modelUrl($model, 'batchEmbedContents');
        $fullName = 'models/' . $this->bareModel($model);

        $vectors = [];
        foreach (array_chunk($texts, self::EMBED_BATCH) as $chunk) {
            $requests = [];
            foreach ($chunk as $text) {
                $text = (string) $text;
                $requests[] = [
                    'model' => $fullName,
                    // Gemini rechaza un texto vacío: se manda un espacio para no perder el orden.
                    'content' => ['parts' => [['text' => $text !== '' ? $text : ' ']]],
                    'outputDimensionality' => self::EMBED_DIMENSIONS,
                ];
            }
            $response = $this->transport->post($url, ['requests' => $requests], $this->headers(), 30.0);
            $data = $this->transport->decode($response);
            $embeddings = isset($data['embeddings']) && is_array($data['embeddings']) ? array_values($data['embeddings']) : [];
            if (count($embeddings) !== count($chunk)) {
                throw new AiException('Gemini returned ' . count($embeddings) . ' vectors for ' . count($chunk) . ' texts', true, $response->status);
            }
            foreach ($embeddings as $embedding) {
                $values = is_array($embedding) && isset($embedding['values']) && is_array($embedding['values']) ? $embedding['values'] : [];
                if ($values === []) {
                    throw new AiException('Gemini returned an empty vector', true, $response->status);
                }
                $vectors[] = AiVector::normalize($values);
            }
        }

        return $vectors;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return ['x-goog-api-key' => $this->apiKey];
    }

    private function bareModel(string $model): string
    {
        return (string) preg_replace('#^models/#', '', $model);
    }

    private function modelUrl(string $model, string $method): string
    {
        return $this->baseUrl . '/' . rawurlencode($this->bareModel($model)) . ':' . $method;
    }

    /** @return array<string,mixed> */
    private function buildBody(AiRequest $r): array
    {
        $generation = [];
        if ($r->temperature !== null) {
            $generation['temperature'] = $r->temperature;
        }
        $generation['maxOutputTokens'] = $r->effectiveMaxOutputTokens();
        $generation['thinkingConfig'] = ['thinkingLevel' => 'low'];
        if ($r->jsonSchema !== null) {
            $generation['responseMimeType'] = 'application/json';
            $generation['responseSchema'] = AiSchema::clean($r->jsonSchema, AiSchema::GEMINI_UNSUPPORTED);
        }

        $body = [];
        if ($r->system !== '') {
            $body['systemInstruction'] = ['parts' => [['text' => $r->system]]];
        }
        $body['contents'] = $this->buildContents($r->messages);
        if ($r->tools !== []) {
            $declarations = [];
            foreach ($r->tools as $tool) {
                $declaration = ['name' => $tool->name, 'description' => $tool->description];
                if ($tool->parameters !== []) {
                    $declaration['parameters'] = AiSchema::clean($tool->parameters, AiSchema::GEMINI_UNSUPPORTED);
                }
                $declarations[] = $declaration;
            }
            $body['tools'] = [['functionDeclarations' => $declarations]];
            if ($r->textOnly) {
                // Declaradas pero sin poder llamarlas: el modelo contesta con texto.
                $body['toolConfig'] = ['functionCallingConfig' => ['mode' => 'NONE']];
            }
        }
        $body['generationConfig'] = $generation;

        return $body;
    }

    /**
     * @param AiMessage[] $messages
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildContents(array $messages): array
    {
        $contents = [];
        $lastWasTool = false;
        $realIds = [];
        foreach ($messages as $m) {
            if (!$m instanceof AiMessage) {
                continue;
            }
            if ($m->role === 'tool') {
                $functionResponse = ['name' => $m->toolName, 'response' => $this->asObject($m->toolResult)];
                if ($m->toolCallId !== '' && isset($realIds[$m->toolCallId])) {
                    // El identificador solo vuelve si lo puso Gemini en su llamada (los «call_N» son nuestros).
                    $functionResponse['id'] = $m->toolCallId;
                }
                $part = ['functionResponse' => $functionResponse];
                if ($lastWasTool) {
                    // Varias respuestas de herramienta seguidas van en UN solo turno, como las pidió el modelo.
                    $contents[count($contents) - 1]['parts'][] = $part;
                } else {
                    $contents[] = ['role' => 'user', 'parts' => [$part]];
                }
                $lastWasTool = true;
                continue;
            }
            $lastWasTool = false;
            if ($m->role === 'assistant') {
                $parts = [];
                if ($m->content !== '') {
                    $parts[] = ['text' => $m->content];
                }
                foreach ($m->toolCalls as $call) {
                    $functionCall = ['name' => $call->name, 'args' => $this->asObject($call->arguments)];
                    $kept = isset($call->extra['gemini']) && is_array($call->extra['gemini']) ? $call->extra['gemini'] : [];
                    if (isset($kept['id']) && is_string($kept['id']) && $kept['id'] !== '') {
                        $functionCall['id'] = $kept['id'];
                        $realIds[$kept['id']] = true;
                    }
                    $part = ['functionCall' => $functionCall];
                    // Lo que acompañaba a la llamada cuando la pidió el modelo (la firma de pensamiento) vuelve en su misma
                    // parte y sin tocar: los Gemini que piensan rechazan con un 400 la llamada que vuelve sin su firma.
                    foreach (isset($kept['part']) && is_array($kept['part']) ? $kept['part'] : [] as $key => $value) {
                        if (is_string($key) && $key !== 'functionCall') {
                            $part[$key] = $value;
                        }
                    }
                    $parts[] = $part;
                }
                if ($parts !== []) {
                    $contents[] = ['role' => 'model', 'parts' => $parts];
                }
                continue;
            }
            if ($m->content !== '') {
                $contents[] = ['role' => 'user', 'parts' => [['text' => $m->content]]];
            }
        }

        return $contents;
    }

    /**
     * Gemini exige un objeto JSON: un array vacío se envía como {} y una lista se envuelve.
     *
     * @param array<mixed> $value
     *
     * @return array<mixed>|\stdClass
     */
    private function asObject(array $value)
    {
        if ($value === []) {
            return new \stdClass();
        }
        if (array_is_list($value)) {
            return ['result' => $value];
        }

        return $value;
    }

    /**
     * @param array<mixed> $data
     */
    private function parse(array $data, AiRequest $r, string $model): AiResult
    {
        $usage = isset($data['usageMetadata']) && is_array($data['usageMetadata']) ? $data['usageMetadata'] : [];
        $tokensIn = (int) ($usage['promptTokenCount'] ?? 0);
        // Los tokens de «pensar» también se cobran como salida.
        $tokensOut = (int) ($usage['candidatesTokenCount'] ?? 0) + (int) ($usage['thoughtsTokenCount'] ?? 0);
        $usedModel = isset($data['modelVersion']) && is_string($data['modelVersion']) && $data['modelVersion'] !== '' ? $data['modelVersion'] : $model;

        $candidate = isset($data['candidates'][0]) && is_array($data['candidates'][0]) ? $data['candidates'][0] : null;
        if ($candidate === null) {
            $block = isset($data['promptFeedback']['blockReason']) && is_string($data['promptFeedback']['blockReason'])
                ? $data['promptFeedback']['blockReason'] : '';
            if ($block !== '') {
                throw new AiException('Gemini blocked the request: ' . $block, false);
            }
            throw new AiException('Gemini returned no candidates', true);
        }

        $finish = isset($candidate['finishReason']) && is_string($candidate['finishReason']) ? $candidate['finishReason'] : '';
        $parts = isset($candidate['content']['parts']) && is_array($candidate['content']['parts']) ? $candidate['content']['parts'] : [];

        $text = '';
        $calls = [];
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['functionCall']) && is_array($part['functionCall'])) {
                $fc = $part['functionCall'];
                $args = isset($fc['args']) && is_array($fc['args']) ? $fc['args'] : [];
                $realId = isset($fc['id']) && is_string($fc['id']) && $fc['id'] !== '' ? $fc['id'] : '';
                $id = $realId !== '' ? $realId : 'call_' . (count($calls) + 1);
                // Lo que viene en la misma parte que la llamada (la firma de pensamiento) se guarda tal cual: hay que
                // devolverlo en el turno siguiente o la API contesta 400 «missing a thought_signature».
                $with = $part;
                unset($with['functionCall']);
                $extra = [];
                if ($with !== [] || $realId !== '') {
                    $extra = ['gemini' => ['part' => $with, 'id' => $realId]];
                }
                $calls[] = new AiToolCall($id, (string) ($fc['name'] ?? ''), $args, $extra);
                continue;
            }
            if (isset($part['text']) && is_string($part['text']) && empty($part['thought'])) {
                $text .= $part['text'];
            }
        }
        $text = trim($text);

        if ($text === '' && $calls === []) {
            if ($finish === 'MAX_TOKENS') {
                throw new AiException('Gemini returned an empty answer (finishReason MAX_TOKENS): the output budget was spent', true);
            }
            if (in_array($finish, ['SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY'], true)) {
                throw new AiException('Gemini did not answer (finishReason ' . $finish . ')', false);
            }
        }

        $json = null;
        if ($r->jsonSchema !== null && $calls === []) {
            $json = Prompt::decodeJson($text);
            if ($json === null) {
                throw new AiException('Gemini did not return valid JSON (finishReason ' . ($finish !== '' ? $finish : 'none') . ')', true);
            }
        }

        return new AiResult($text, $calls, $json, $tokensIn, $tokensOut, $finish, $usedModel);
    }
}
