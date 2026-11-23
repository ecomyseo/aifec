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
 * Proveedor para Claude (Anthropic): la API de Mensajes, con herramientas y JSON pedido en las instrucciones.
 * La clave va en la cabecera «x-api-key» y nunca sale en un mensaje de error. Anthropic no ofrece vectores: embed() lo dice.
 */
final class AnthropicProvider implements AiProvider
{
    public const ENDPOINT = 'https://api.anthropic.com/v1/messages';
    private const VERSION = '2023-06-01';

    /** @var string */
    private $apiKey;

    /** @var string */
    private $defaultModel;

    /** @var string */
    private $endpoint;

    /** @var AiTransport */
    private $transport;

    /**
     * @param callable|null $sleep recibe microsegundos; por defecto usleep (se inyecta en las pruebas)
     */
    public function __construct(HttpClient $http, string $apiKey, string $defaultModel, ?callable $sleep = null, string $endpoint = self::ENDPOINT)
    {
        $this->apiKey = $apiKey;
        $this->defaultModel = $defaultModel;
        $this->endpoint = $endpoint;
        $this->transport = new AiTransport($http, $apiKey, $sleep ?? 'usleep');
    }

    public function name(): string
    {
        return 'anthropic';
    }

    public function generate(AiRequest $r): AiResult
    {
        $model = $r->model !== '' ? $r->model : $this->defaultModel;
        $system = $r->system;
        if ($r->jsonSchema !== null) {
            $system = trim($system . "\n\nAnswer ONLY with one JSON value that follows this JSON Schema, without any text before or after it and without code fences:\n"
                . (string) json_encode(AiSchema::clean($r->jsonSchema, ['$schema']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        $body = ['model' => $model, 'max_tokens' => $r->effectiveMaxOutputTokens(), 'messages' => $this->buildMessages($r)];
        if ($system !== '') {
            $body['system'] = $system;
        }
        if ($r->temperature !== null) {
            $body['temperature'] = max(0.0, min(1.0, $r->temperature));
        }
        if ($r->tools !== []) {
            $tools = [];
            foreach ($r->tools as $tool) {
                $schema = $tool->parameters !== [] ? AiSchema::clean($tool->parameters, ['$schema']) : ['type' => 'object', 'properties' => new \stdClass()];
                $tools[] = ['name' => $tool->name, 'description' => $tool->description, 'input_schema' => $schema];
            }
            $body['tools'] = $tools;
            if ($r->textOnly) {
                $body['tool_choice'] = ['type' => 'none'];
            }
        }

        $response = $this->transport->post($this->endpoint, $body, ['x-api-key' => $this->apiKey, 'anthropic-version' => self::VERSION], $r->timeout, $r->allowRetries);

        return $this->parse($this->transport->decode($response), $r, $model);
    }

    public function embed(array $texts, string $model = ''): array
    {
        throw new AiException('Anthropic has no embeddings service: choose another provider for the vectors', false);
    }

    /**
     * Los mensajes que pide Anthropic: turnos que alternan usuario y asistente; las respuestas de las herramientas son bloques
     * «tool_result» dentro de un turno de usuario, y los turnos seguidos del mismo papel se juntan en uno.
     *
     * @return array<int,array<string,mixed>>
     */
    private function buildMessages(AiRequest $r): array
    {
        $turns = [];
        $push = static function (string $role, array $blocks) use (&$turns): void {
            $last = count($turns) - 1;
            if ($last >= 0 && $turns[$last]['role'] === $role) {
                $turns[$last]['content'] = array_merge($turns[$last]['content'], $blocks);

                return;
            }
            $turns[] = ['role' => $role, 'content' => $blocks];
        };
        $pending = [];
        foreach ($r->messages as $m) {
            if (!$m instanceof AiMessage) {
                continue;
            }
            if ($m->role === 'assistant') {
                $blocks = [];
                if (trim($m->content) !== '') {
                    $blocks[] = ['type' => 'text', 'text' => $m->content];
                }
                $pending = [];
                foreach ($m->toolCalls as $i => $call) {
                    $id = $call->id !== '' ? $call->id : 'toolu_' . ($i + 1);
                    $pending[] = ['id' => $id, 'name' => $call->name];
                    $blocks[] = ['type' => 'tool_use', 'id' => $id, 'name' => $call->name, 'input' => $call->arguments === [] ? new \stdClass() : $call->arguments];
                }
                if ($blocks !== []) {
                    $push('assistant', $blocks);
                }
                continue;
            }
            if ($m->role === 'tool') {
                $callId = $m->toolCallId;
                if ($callId === '') {
                    foreach ($pending as $k => $p) {
                        if ($p['name'] === $m->toolName) {
                            $callId = $p['id'];
                            unset($pending[$k]);
                            break;
                        }
                    }
                    if ($callId === '') {
                        $callId = 'toolu_' . $m->toolName;
                    }
                }
                $push('user', [['type' => 'tool_result', 'tool_use_id' => $callId,
                    'content' => (string) json_encode($m->toolResult === [] ? new \stdClass() : $m->toolResult, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR)]]);
                continue;
            }
            $push('user', [['type' => 'text', 'text' => $m->content !== '' ? $m->content : '.']]);
        }
        // La conversación tiene que empezar por el usuario.
        if ($turns === [] || $turns[0]['role'] !== 'user') {
            array_unshift($turns, ['role' => 'user', 'content' => [['type' => 'text', 'text' => '.']]]);
        }

        return $turns;
    }

    /**
     * @param array<mixed> $data
     */
    private function parse(array $data, AiRequest $r, string $model): AiResult
    {
        $blocks = isset($data['content']) && is_array($data['content']) ? $data['content'] : [];
        $text = '';
        $calls = [];
        foreach ($blocks as $i => $block) {
            if (!is_array($block) || !isset($block['type'])) {
                continue;
            }
            if ($block['type'] === 'text' && isset($block['text']) && is_string($block['text'])) {
                $text .= $block['text'];
            } elseif ($block['type'] === 'tool_use') {
                $input = isset($block['input']) && is_array($block['input']) ? $block['input'] : [];
                $id = isset($block['id']) && is_string($block['id']) && $block['id'] !== '' ? $block['id'] : 'toolu_' . ((int) $i + 1);
                $calls[] = new AiToolCall($id, (string) ($block['name'] ?? ''), $input);
            }
        }
        $text = trim($text);
        $finish = isset($data['stop_reason']) && is_string($data['stop_reason']) ? $data['stop_reason'] : '';
        if ($text === '' && $calls === [] && $finish === 'max_tokens') {
            throw new AiException('The service returned an empty answer (stop_reason max_tokens): the output budget was spent', true);
        }
        $json = null;
        if ($r->jsonSchema !== null && $calls === []) {
            $json = Prompt::decodeJson($text);
            if ($json === null) {
                throw new AiException('The service did not return valid JSON (stop_reason ' . ($finish !== '' ? $finish : 'none') . ')', true);
            }
        }
        $usage = isset($data['usage']) && is_array($data['usage']) ? $data['usage'] : [];
        $usedModel = isset($data['model']) && is_string($data['model']) && $data['model'] !== '' ? $data['model'] : $model;

        return new AiResult($text, $calls, $json, (int) ($usage['input_tokens'] ?? 0), (int) ($usage['output_tokens'] ?? 0), $finish, $usedModel);
    }
}
