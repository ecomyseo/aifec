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

/**
 * Proveedor simulado: determinista, sin red y sin gasto. Sirve para las pruebas y las demos.
 *
 *   $ai = new FakeProvider();
 *   $ai->script(new AiResult('Hola'), new AiResult('', [new AiToolCall('1', 'buscar')]));   // respuestas por orden
 *   $ai->generate($request);                  // lo pedido queda en $ai->requests
 *
 * Sin guion contesta así: con jsonSchema, un JSON mínimo válido para el esquema; con herramientas y un
 * último mensaje del usuario que nombra alguna, esa llamada con argumentos vacíos; si no, «[simulado] »
 * y las 12 primeras palabras del último mensaje del usuario. Si el último mensaje de la conversación
 * ya es un resultado de herramienta no se pide otra llamada (evita bucles): contesta con texto.
 */
final class FakeProvider implements AiProvider
{
    /** @var AiRequest[] todo lo que se ha pedido, por orden */
    public $requests = [];

    /** @var AiResult[] */
    private $queue = [];

    /** Palabras demasiado corrientes para decidir que un mensaje habla de una herramienta. */
    private const STOP_WORDS = ['the', 'and', 'for', 'with', 'that', 'this', 'from', 'que', 'los', 'las', 'una', 'uno', 'del', 'por', 'con', 'para', 'como'];

    public function name(): string
    {
        return 'fake';
    }

    /** Encola respuestas: la primera petición recibe la primera, y así hasta que se acaban. */
    public function script(AiResult ...$results): self
    {
        foreach ($results as $result) {
            $this->queue[] = $result;
        }

        return $this;
    }

    public function generate(AiRequest $r): AiResult
    {
        $this->requests[] = $r;
        if ($this->queue !== []) {
            return array_shift($this->queue);
        }

        $model = $r->model !== '' ? $r->model : 'fake';
        $input = $r->system;
        foreach ($r->messages as $m) {
            $input .= ' ' . $m->content;
        }
        $tokensIn = (int) ceil(strlen($input) / 4);

        if ($r->jsonSchema !== null) {
            $value = $this->minimal($r->jsonSchema);
            $text = (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $decoded = json_decode($text, true);

            return new AiResult($text, [], is_array($decoded) ? $decoded : null, $tokensIn, (int) ceil(strlen($text) / 4), 'stop', $model);
        }

        $last = $r->messages === [] ? null : $r->messages[count($r->messages) - 1];
        $lastUser = '';
        foreach ($r->messages as $m) {
            if ($m->role === 'user') {
                $lastUser = $m->content;
            }
        }

        if ($r->tools !== [] && $last !== null && $last->role === 'user') {
            $words = $this->words($lastUser);
            foreach ($r->tools as $tool) {
                $toolWords = $this->words($this->splitCamel($tool->name) . ' ' . $tool->description);
                if (array_intersect($words, $toolWords) !== []) {
                    $call = new AiToolCall('sim_' . $tool->name, $tool->name, []);

                    return new AiResult('', [$call], null, $tokensIn, 8, 'tool_calls', $model);
                }
            }
        }

        $parts = preg_split('/\s+/u', trim($lastUser), -1, PREG_SPLIT_NO_EMPTY);
        $text = '[simulado] ' . implode(' ', array_slice(is_array($parts) ? $parts : [], 0, 12));

        return new AiResult($text, [], null, $tokensIn, (int) ceil(strlen($text) / 4), 'stop', $model);
    }

    public function embed(array $texts, string $model = ''): array
    {
        $useVector = class_exists('Aifec\\Search\\Vector') && method_exists('Aifec\\Search\\Vector', 'hashEmbedding');
        $out = [];
        foreach (array_values($texts) as $text) {
            $text = (string) $text;
            if ($useVector) {
                $vector = call_user_func(['Aifec\\Search\\Vector', 'hashEmbedding'], $text, 256, 'es');
                $out[] = array_map(static fn ($v): float => (float) $v, is_array($vector) ? array_values($vector) : []);
            } else {
                $out[] = AiVector::hash($text, 256);
            }
        }

        return $out;
    }

    /**
     * Valor mínimo que cumple el esquema. Los objetos se devuelven como stdClass para que {} no
     * se convierta en [].
     *
     * @param array<mixed> $schema
     *
     * @return mixed
     */
    private function minimal(array $schema)
    {
        if (isset($schema['enum']) && is_array($schema['enum']) && $schema['enum'] !== []) {
            $enum = array_values($schema['enum']);

            return $enum[0];
        }
        foreach (['anyOf', 'oneOf'] as $key) {
            if (isset($schema[$key]) && is_array($schema[$key]) && isset($schema[$key][0]) && is_array($schema[$key][0])) {
                return $this->minimal($schema[$key][0]);
            }
        }

        $type = $schema['type'] ?? null;
        if (is_array($type)) {
            $first = null;
            foreach ($type as $t) {
                if ($t !== 'null') {
                    $first = $t;
                    break;
                }
            }
            $type = $first;
        }
        if ($type === null) {
            $type = isset($schema['properties']) ? 'object' : (isset($schema['items']) ? 'array' : null);
        }
        $type = is_string($type) ? strtolower($type) : null;

        switch ($type) {
            case 'object':
                $out = [];
                $props = isset($schema['properties']) && is_array($schema['properties']) ? $schema['properties'] : [];
                foreach ($props as $name => $sub) {
                    $out[(string) $name] = is_array($sub) ? $this->minimal($sub) : null;
                }

                return (object) $out;
            case 'array':
                return [];
            case 'string':
                return '';
            case 'integer':
            case 'number':
                return 0;
            case 'boolean':
                return false;
            default:
                return null;
        }
    }

    /**
     * Raíces de las palabras (minúscula, sin «s» final y a 5 letras como mucho, para que «producto»,
     * «productos» y «buscar», «busca» coincidan), sin las demasiado cortas ni las corrientes.
     *
     * @return string[]
     */
    private function words(string $text): array
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach (is_array($parts) ? $parts : [] as $word) {
            if (strlen($word) < 3 || in_array($word, self::STOP_WORDS, true)) {
                continue;
            }
            $stem = rtrim($word, 's');
            if (mb_strlen($stem, 'UTF-8') > 5) {
                $stem = mb_substr($stem, 0, 5, 'UTF-8');
            }
            $out[$stem] = $stem;
        }

        return array_values($out);
    }

    private function splitCamel(string $name): string
    {
        return (string) preg_replace('/(?<=\p{Ll})(?=\p{Lu})/u', ' ', $name);
    }
}
