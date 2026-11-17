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
 * Proveedor para cualquier servicio con la API de OpenAI (OpenAI, Mistral, OpenRouter, Ollama…).
 * La clave va en la cabecera Authorization; si está vacía (Ollama local) no se envía.
 */
final class OpenAiCompatProvider implements AiProvider
{
    private const EMBED_BATCH = 100;

    /** @var string */
    private $apiKey;

    /** @var string */
    private $baseUrl;

    /** @var string */
    private $defaultModel;

    /** @var string */
    private $embeddingModel;

    /** @var AiTransport */
    private $transport;

    /**
     * @param callable|null $sleep recibe microsegundos; por defecto usleep (se inyecta en las pruebas)
     */
    public function __construct(
        HttpClient $http,
        string $apiKey,
        string $baseUrl,
        string $defaultModel,
        string $embeddingModel = '',
        ?callable $sleep = null
    ) {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->defaultModel = $defaultModel;
        $this->embeddingModel = $embeddingModel;
        $this->transport = new AiTransport($http, $apiKey, $sleep ?? 'usleep');
    }

    public function name(): string
    {
        return 'openai-compat';
    }

    public function generate(AiRequest $r): AiResult
    {
        $model = $r->model !== '' ? $r->model : $this->defaultModel;
        $body = ['model' => $model, 'messages' => $this->buildMessages($r)];
        if ($r->temperature !== null) {
            $body['temperature'] = $r->temperature;
        }
        // OpenAI exige max_completion_tokens en los modelos nuevos; el resto de servicios, max_tokens.
        $limitKey = strpos($this->baseUrl, 'api.openai.com') !== false ? 'max_completion_tokens' : 'max_tokens';
        $body[$limitKey] = $r->effectiveMaxOutputTokens();
        if ($r->tools !== []) {
            $tools = [];
            foreach ($r->tools as $tool) {
                $parameters = $tool->parameters !== []
                    ? AiSchema::clean($tool->parameters, ['$schema'])
                    : ['type' => 'object', 'properties' => new \stdClass()];
                $tools[] = ['type' => 'function', 'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $parameters,
                ]];
            }
            $body['tools'] = $tools;
            if ($r->textOnly) {
                $body['tool_choice'] = 'none';
            }
        }
        if ($r->jsonSchema !== null) {
            $body['response_format'] = ['type' => 'json_schema', 'json_schema' => [
                'name' => 'response',
                'schema' => AiSchema::clean($r->jsonSchema, ['$schema']),
            ]];
        }

        $response = $this->transport->post($this->baseUrl . '/chat/completions', $body, $this->headers(), $r->timeout, $r->allowRetries);
        $data = $this->transport->decode($response);

        return $this->parse($data, $r, $model);
    }

    public function embed(array $texts, string $model = ''): array
    {
        $model = $model !== '' ? $model : $this->embeddingModel;
        if ($model === '') {
            throw new AiException('No embedding model is configured for this provider', false);
        }
        $texts = array_values($texts);
        $vectors = [];
        foreach (array_chunk($texts, self::EMBED_BATCH) as $chunk) {
            $input = array_map(static fn ($t): string => (string) $t, $chunk);
            $response = $this->transport->post($this->baseUrl . '/embeddings', ['model' => $model, 'input' => $input], $this->headers(), 30.0);
            $data = $this->transport->decode($response);
            $rows = isset($data['data']) && is_array($data['data']) ? array_values($data['data']) : [];
            if (count($rows) !== count($chunk)) {
                throw new AiException('The service returned ' . count($rows) . ' vectors for ' . count($chunk) . ' texts', true, $response->status);
            }
            // El servicio puede devolverlos desordenados: manda el campo «index».
            usort($rows, static fn ($a, $b): int => (int) (is_array($a) ? ($a['index'] ?? 0) : 0) <=> (int) (is_array($b) ? ($b['index'] ?? 0) : 0));
            foreach ($rows as $row) {
                $values = is_array($row) && isset($row['embedding']) && is_array($row['embedding']) ? $row['embedding'] : [];
                if ($values === []) {
                    throw new AiException('The service returned an empty vector', true, $response->status);
                }
                $vectors[] = AiVector::normalize($values);
            }
        }

        return $vectors;
    }

    /** @return array<string,string> */
    private function headers(): array
    {
        return $this->apiKey !== '' ? ['Authorization' => 'Bearer ' . $this->apiKey] : [];
    }

    /** @return array<int,array<string,mixed>> */
    private function buildMessages(AiRequest $r): array
    {
        $out = [];
        if ($r->system !== '') {
            $out[] = ['role' => 'system', 'content' => $r->system];
        }
        /** @var array<int,array{id:string,name:string}> $pending llamadas del último turno del asistente sin contestar */
        $pending = [];
        foreach ($r->messages as $m) {
            if (!$m instanceof AiMessage) {
                continue;
            }
            if ($m->role === 'assistant') {
                $message = ['role' => 'assistant', 'content' => $m->content !== '' ? $m->content : null];
                $pending = [];
                if ($m->toolCalls !== []) {
                    $calls = [];
                    foreach ($m->toolCalls as $i => $call) {
                        $id = $call->id !== '' ? $call->id : 'call_' . ($i + 1);
                        $pending[] = ['id' => $id, 'name' => $call->name];
                        $calls[] = [
                            'id' => $id,
                            'type' => 'function',
                            'function' => [
                                'name' => $call->name,
                                'arguments' => $call->arguments === [] ? '{}' : (string) json_encode($call->arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                            ],
                        ];
                    }
                    $message['tool_calls'] = $calls;
                }
                $out[] = $message;
                continue;
            }
            if ($m->role === 'tool') {
                $callId = $m->toolCallId;
                if ($callId === '') {
                    // Sin identificador, se casa con la primera llamada pendiente del mismo nombre.
                    foreach ($pending as $k => $p) {
                        if ($p['name'] === $m->toolName) {
                            $callId = $p['id'];
                            unset($pending[$k]);
                            break;
                        }
                    }
                    if ($callId === '') {
                        $callId = 'call_' . $m->toolName;
                    }
                }
                $out[] = [
                    'role' => 'tool',
                    'tool_call_id' => $callId,
                    'content' => (string) json_encode($m->toolResult === [] ? new \stdClass() : $m->toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                ];
                continue;
            }
            $out[] = ['role' => 'user', 'content' => $m->content];
        }

        return $out;
    }

    /**
     * @param array<mixed> $data
     */
    private function parse(array $data, AiRequest $r, string $model): AiResult
    {
        $choice = isset($data['choices'][0]) && is_array($data['choices'][0]) ? $data['choices'][0] : null;
        if ($choice === null) {
            throw new AiException('The service returned no choices', true);
        }
        $message = isset($choice['message']) && is_array($choice['message']) ? $choice['message'] : [];
        $finish = isset($choice['finish_reason']) && is_string($choice['finish_reason']) ? $choice['finish_reason'] : '';

        $text = '';
        $content = $message['content'] ?? '';
        if (is_string($content)) {
            $text = $content;
        } elseif (is_array($content)) {
            foreach ($content as $part) {
                if (is_array($part) && isset($part['text']) && is_string($part['text'])) {
                    $text .= $part['text'];
                }
            }
        }
        $text = trim($text);

        $calls = [];
        $rawCalls = isset($message['tool_calls']) && is_array($message['tool_calls']) ? $message['tool_calls'] : [];
        foreach ($rawCalls as $i => $raw) {
            if (!is_array($raw) || !isset($raw['function']) || !is_array($raw['function'])) {
                continue;
            }
            $args = $raw['function']['arguments'] ?? [];
            if (is_string($args)) {
                $decoded = json_decode($args, true);
                $args = is_array($decoded) ? $decoded : [];
            } elseif (!is_array($args)) {
                $args = [];
            }
            $id = isset($raw['id']) && is_string($raw['id']) && $raw['id'] !== '' ? $raw['id'] : 'call_' . ((int) $i + 1);
            $calls[] = new AiToolCall($id, (string) ($raw['function']['name'] ?? ''), $args);
        }

        if ($text === '' && $calls === [] && $finish === 'length') {
            throw new AiException('The service returned an empty answer (finish_reason length): the output budget was spent', true);
        }

        $json = null;
        if ($r->jsonSchema !== null && $calls === []) {
            $json = Prompt::decodeJson($text);
            if ($json === null) {
                throw new AiException('The service did not return valid JSON (finish_reason ' . ($finish !== '' ? $finish : 'none') . ')', true);
            }
        }

        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $usedModel = isset($data['model']) && is_string($data['model']) && $data['model'] !== '' ? $data['model'] : $model;

        return new AiResult(
            $text,
            $calls,
            $json,
            (int) ($usage['prompt_tokens'] ?? 0),
            (int) ($usage['completion_tokens'] ?? 0),
            $finish,
            $usedModel
        );
    }
}
