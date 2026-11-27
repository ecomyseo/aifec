<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai;

use Aifec\Ai\AiException;
use Aifec\Ai\AiMessage;
use Aifec\Ai\AiProvider;
use Aifec\Ai\AiRequest;
use Aifec\Ai\AiResult;
use Aifec\Ai\AiTool;
use Aifec\Ai\AnthropicProvider;
use Aifec\Ai\FakeProvider;
use Aifec\Ai\GeminiProvider;
use Aifec\Ai\OpenAiCompatProvider;
use Aifec\Kernel\App;

/**
 * La entrada única de toda la IA del sistema (servicio «ai»).
 *
 *   $ai->ask('product.description', ['system' => '…', 'messages' => [AiMessage::user('…')], 'schema' => […]], 'standard');
 *
 * Reglas que no se saltan:
 *  - apagada (ai.enabled) = ninguna llamada y AiDisabled («AI is off»);
 *  - con el gasto estimado de hoy en el tope diario, ninguna llamada y AiLimitReached («Daily AI limit reached»);
 *  - cada llamada al proveedor se apunta en ai_usage (bien o mal), con el error sin claves;
 *  - la IA propone y una persona aprueba: propose() solo crea una propuesta pendiente.
 *
 * Un nivel (economy, standard, advanced) tiene su proveedor, su modelo y su clave en los ajustes. Quien quiera su propio
 * proveedor (las pruebas) publica el servicio «ai.provider.<nivel>».
 */
final class Ai
{
    public const TIERS = ['economy', 'standard', 'advanced'];

    /** El modelo cuando el ajuste está vacío. */
    public const DEFAULT_MODEL = 'gemini-flash-latest';

    /**
     * Los proveedores que se pueden elegir. «openai» es cualquier servicio con la API de OpenAI y su propia dirección; los de
     * SERVICES son los mismos con la dirección ya puesta (se puede cambiar escribiendo otra en los ajustes).
     */
    public const KINDS = ['fake', 'gemini', 'anthropic', 'chatgpt', 'grok', 'mistral', 'deepseek', 'groq', 'openrouter', 'perplexity', 'openai'];

    /** @var array<string,string> proveedor => dirección base de su API (compatible con OpenAI) */
    public const SERVICES = [
        'chatgpt' => 'https://api.openai.com/v1',
        'grok' => 'https://api.x.ai/v1',
        'mistral' => 'https://api.mistral.ai/v1',
        'deepseek' => 'https://api.deepseek.com/v1',
        'groq' => 'https://api.groq.com/openai/v1',
        'openrouter' => 'https://openrouter.ai/api/v1',
        'perplexity' => 'https://api.perplexity.ai',
    ];

    /** @var array<string,string> el modelo de Claude de cada nivel cuando los ajustes lo dejan vacío */
    public const CLAUDE_MODELS = ['economy' => 'claude-haiku-5-5', 'standard' => 'claude-sonnet-5-5', 'advanced' => 'claude-opus-5-5'];

    /** @var App */
    private $app;

    /** @var array<string,FakeProvider> un proveedor simulado por nivel, para que guarde lo que se le pide */
    private $fakes = [];

    /** @var int la última fila de ai_usage que escribió esta clase */
    private $lastUsageId = 0;

    /** Contabilidad local a esta instancia: también incluye las herramientas de búsqueda llamadas dentro de un turno. */
    private $meter = ['calls' => 0, 'cost' => 0, 'unpriced' => 0];

    public function usageMeter(): array
    {
        return $this->meter;
    }

    /** @var Proposals|null */
    private $proposals;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function enabled(): bool
    {
        return $this->app->settings()->bool('ai.enabled', false);
    }

    /**
     * Pide una respuesta a un modelo.
     *
     * $request: system, messages (AiMessage o ['role','content']), schema (esquema JSON de la respuesta), tools (AiTool),
     * max_tokens, temperature, timeout (segundos) y cache_ttl (segundos: la misma tarea con la misma petición se contesta
     * sin gastar).
     *
     * @param array<string,mixed> $request
     *
     * @throws AiException si la IA está apagada, se pasó el tope diario o el proveedor falla
     */
    public function ask(string $task, array $request, string $tier = 'economy'): AiResult
    {
        $tier = $this->tier($tier);
        if (!$this->enabled()) {
            throw new AiDisabled('AI is off');
        }

        $ttl = isset($request['cache_ttl']) && is_numeric($request['cache_ttl']) ? max(0, (int) $request['cache_ttl']) : 0;
        $cacheKey = '';
        if ($ttl > 0 && empty($request['tools'])) {
            $cacheKey = 'ai.ask.' . sha1($task . '|' . $tier . '|' . $this->kind($tier) . '|' . $this->model($tier) . '|' . $this->fingerprint($request));
            $hit = $this->app->cache()->get($cacheKey);
            if (is_array($hit) && isset($hit['text'])) {
                return new AiResult(
                    (string) $hit['text'],
                    [],
                    isset($hit['json']) && is_array($hit['json']) ? $hit['json'] : null,
                    isset($hit['tokens_in']) ? (int) $hit['tokens_in'] : 0,
                    isset($hit['tokens_out']) ? (int) $hit['tokens_out'] : 0,
                    isset($hit['finish']) ? (string) $hit['finish'] : '',
                    isset($hit['model']) ? (string) $hit['model'] : ''
                );
            }
        }

        $this->guardSpending();

        $started = microtime(true);
        $kind = $this->kind($tier);
        $providerName = $kind;
        $model = $this->model($tier);
        try {
            $provider = $this->provider($tier);
            $providerName = $provider->name();
            $aiRequest = $this->buildRequest($request, $model);
            $result = $provider->generate($aiRequest);
        } catch (AiException $e) {
            $message = $this->clean($e->getMessage());
            $this->lastUsageId = $this->record($task, $tier, $providerName, $model, 0, 0, $this->elapsed($started), false, $message);

            throw new AiException($message, $e->isTransient(), $e->status());
        } catch (\Throwable $e) {
            $message = $this->clean('AI call failed: ' . $e->getMessage());
            $this->lastUsageId = $this->record($task, $tier, $providerName, $model, 0, 0, $this->elapsed($started), false, $message);

            throw new AiException($message);
        }

        $this->lastUsageId = $this->record(
            $task,
            $tier,
            $providerName,
            $result->model !== '' ? $result->model : $model,
            $result->tokensIn,
            $result->tokensOut,
            $this->elapsed($started),
            true,
            ''
        );
        if ($cacheKey !== '' && $result->toolCalls === []) {
            $this->app->cache()->set($cacheKey, [
                'text' => $result->text,
                'json' => $result->json,
                'tokens_in' => $result->tokensIn,
                'tokens_out' => $result->tokensOut,
                'finish' => $result->finishReason,
                'model' => $result->model,
            ], $ttl, ['ai']);
        }

        return $result;
    }

    /**
     * Un vector por texto, con el proveedor del nivel económico. Cuenta en el gasto como cualquier llamada.
     *
     * @param string[] $texts
     *
     * @return array<int,array<int,float>>
     *
     * @throws AiException
     */
    public function embed(array $texts): array
    {
        $tier = 'economy';
        if (!$this->enabled()) {
            throw new AiDisabled('AI is off');
        }
        $this->guardSpending();

        $started = microtime(true);
        $kind = $this->kind($tier);
        $providerName = $kind;
        $model = $this->model($tier);
        $chars = 0;
        foreach ($texts as $text) {
            $chars += strlen((string) $text);
        }
        $tokens = (int) ceil($chars / 4);
        try {
            $provider = $this->provider($tier);
            $providerName = $provider->name();
            $vectors = $provider->embed(array_values($texts));
        } catch (AiException $e) {
            $message = $this->clean($e->getMessage());
            $this->lastUsageId = $this->record('embed', $tier, $providerName, $model, 0, 0, $this->elapsed($started), false, $message);

            throw new AiException($message, $e->isTransient(), $e->status());
        } catch (\Throwable $e) {
            $message = $this->clean('AI call failed: ' . $e->getMessage());
            $this->lastUsageId = $this->record('embed', $tier, $providerName, $model, 0, 0, $this->elapsed($started), false, $message);

            throw new AiException($message);
        }
        $this->lastUsageId = $this->record('embed', $tier, $providerName, $model, $tokens, 0, $this->elapsed($started), true, '');

        return $vectors;
    }

    /**
     * Crea una propuesta pendiente (una sola por entidad + campo + idioma: la nueva sustituye a la anterior).
     *
     * @param array<string,mixed> $meta current (texto actual), model, task, user_id
     */
    public function propose(string $entity, int $id, string $field, ?string $lang, string $proposed, array $meta = []): int
    {
        return $this->proposals()->propose($entity, $id, $field, $lang, $proposed, $meta);
    }

    public function proposals(): Proposals
    {
        if ($this->proposals === null) {
            $this->proposals = new Proposals($this->app);
        }

        return $this->proposals;
    }

    /** La fila de ai_usage de la última llamada que hizo este servicio (0 si ninguna). */
    public function lastUsageId(): int
    {
        return $this->lastUsageId;
    }

    /**
     * Registro no secreto de una llamada concreta de este sitio, para conciliar presupuestos sin sumar tareas concurrentes.
     *
     * @return array<string,mixed>|null
     */
    public function usage(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->app->db()->row('SELECT id,task,tier,provider,model,tokens_in,tokens_out,cost_micro,ok FROM {ai_usage} WHERE id = ?', [$id]);
        if ($row === null) {
            return null;
        }
        foreach (['id', 'tokens_in', 'tokens_out', 'cost_micro'] as $field) {
            $row[$field] = (int) $row[$field];
        }
        $row['ok'] = (bool) $row['ok'];

        return $row;
    }

    /**
     * La llamada salió bien pero su respuesta no vale (no cumple el esquema): se apunta como fallo.
     */
    public function flagFailure(int $usageId, string $error): void
    {
        if ($usageId <= 0) {
            return;
        }
        $this->app->db()->update('ai_usage', ['ok' => 0, 'error' => substr($this->clean($error), 0, 500)], ['id' => $usageId]);
    }

    /** Gasto estimado de hoy, en millonésimas de céntimo. */
    public function spentTodayMicro(): int
    {
        return Usage::spentMicro($this->app);
    }

    /** true si hoy ya no se puede gastar más. */
    public function limitReached(): bool
    {
        return Usage::limitReason($this->app) !== '';
    }

    /** El proveedor de un nivel: el publicado como «ai.provider.<nivel>» o el de los ajustes. */
    public function provider(string $tier): AiProvider
    {
        $tier = $this->tier($tier);
        if ($this->app->site()->isDemo() && $this->kind($tier) === 'openai') {
            // Una dirección elegida por un visitante no puede recibir una clave heredada del sitio maestro.
            throw new AiException('Custom AI endpoints are unavailable in public demos');
        }
        $id = 'ai.provider.' . $tier;
        if ($this->app->has($id)) {
            $service = $this->app->service($id);
            if ($service instanceof AiProvider) {
                return $service;
            }
        }
        $settings = $this->app->settings();
        $kind = $this->kind($tier);
        if ($kind === 'fake') {
            if (!isset($this->fakes[$tier])) {
                $this->fakes[$tier] = new FakeProvider();
            }

            return $this->fakes[$tier];
        }
        $key = $this->app->secret('ai.' . $tier . '.key');
        if ($kind === 'anthropic') {
            if ($key === '') {
                throw new AiException('The ' . $tier . ' tier has no API key');
            }

            return new AnthropicProvider($this->app->http(), $key, $this->model($tier));
        }
        if ($kind === 'openai' || isset(self::SERVICES[$kind])) {
            // Un servicio con nombre trae su dirección; la de los ajustes, si la hay, manda (un proxy propio, otra región).
            $base = $settings->str('ai.' . $tier . '.base_url');
            if ($base === '' && isset(self::SERVICES[$kind])) {
                $base = self::SERVICES[$kind];
            }
            if ($base === '') {
                throw new AiException('The ' . $tier . ' tier has no base address');
            }
            if ($kind !== 'openai' && $key === '') {
                throw new AiException('The ' . $tier . ' tier has no API key');
            }
            $model = trim($settings->str('ai.' . $tier . '.model'));
            if ($model === '') {
                throw new AiException('The ' . $tier . ' tier has no model');
            }

            return new OpenAiCompatProvider($this->app->http(), $key, $base, $model);
        }
        if ($key === '') {
            throw new AiException('The ' . $tier . ' tier has no API key');
        }

        return new GeminiProvider($this->app->http(), $key, $this->model($tier));
    }

    /** El modelo de un nivel: el escrito en los ajustes o gemini-flash-latest si está vacío. */
    public function model(string $tier): string
    {
        $model = trim($this->app->settings()->str('ai.' . $this->tier($tier) . '.model'));
        if ($model === '' && $this->kind($tier) === 'anthropic') {
            return self::CLAUDE_MODELS[$this->tier($tier)];
        }

        return $model !== '' ? $model : self::DEFAULT_MODEL;
    }

    /** El proveedor elegido en los ajustes para un nivel: fake, gemini u openai. */
    public function kind(string $tier): string
    {
        $kind = $this->app->settings()->str('ai.' . $this->tier($tier) . '.provider', 'gemini');

        return in_array($kind, self::KINDS, true) ? $kind : 'gemini';
    }

    // ------------------------------------------------------------------ interior

    private function tier(string $tier): string
    {
        if (!in_array($tier, self::TIERS, true)) {
            throw new \InvalidArgumentException('Unknown AI tier: ' . $tier);
        }

        return $tier;
    }

    private function guardSpending(): void
    {
        $reason = Usage::limitReason($this->app);
        if ($reason !== '') {
            throw new AiLimitReached($reason);
        }
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }

    /**
     * @param array<string,mixed> $request
     */
    private function buildRequest(array $request, string $model): AiRequest
    {
        $messages = [];
        $given = isset($request['messages']) && is_array($request['messages']) ? $request['messages'] : [];
        foreach ($given as $message) {
            if ($message instanceof AiMessage) {
                $messages[] = $message;
            } elseif (is_array($message)) {
                $messages[] = new AiMessage(
                    isset($message['role']) && is_string($message['role']) ? $message['role'] : 'user',
                    isset($message['content']) && is_scalar($message['content']) ? (string) $message['content'] : ''
                );
            }
        }
        if (isset($request['prompt']) && is_string($request['prompt']) && $request['prompt'] !== '') {
            $messages[] = AiMessage::user($request['prompt']);
        }
        $tools = [];
        foreach (isset($request['tools']) && is_array($request['tools']) ? $request['tools'] : [] as $tool) {
            if ($tool instanceof AiTool) {
                $tools[] = $tool;
            }
        }
        $schema = isset($request['schema']) && is_array($request['schema']) ? $request['schema'] : null;
        $timeout = isset($request['timeout']) && is_numeric($request['timeout'])
            ? (float) $request['timeout']
            : (float) max(5, $this->app->settings()->int('ai.timeout', 30));

        $built = new AiRequest(
            isset($request['system']) && is_string($request['system']) ? $request['system'] : '',
            $messages,
            $tools,
            $schema,
            $model,
            isset($request['temperature']) && is_numeric($request['temperature']) ? (float) $request['temperature'] : null,
            isset($request['max_tokens']) && is_numeric($request['max_tokens']) ? (int) $request['max_tokens'] : 1024,
            $timeout
        );
        // 'text_only': con herramientas declaradas, esta petición solo admite texto (la última vuelta de un asistente).
        $built->textOnly = !empty($request['text_only']) && $tools !== [];
        $built->allowRetries = !array_key_exists('retry', $request) || $request['retry'] !== false;

        return $built;
    }

    /**
     * Huella de la petición para la caché por contenido.
     *
     * @param array<string,mixed> $request
     */
    private function fingerprint(array $request): string
    {
        unset($request['cache_ttl']);

        return (string) json_encode($request, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /** Quita las claves de los tres niveles de un texto que va a un error o al registro, y lo corta. */
    private function clean(string $text): string
    {
        foreach (self::TIERS as $tier) {
            $text = AiException::redact($text, $this->app->secret('ai.' . $tier . '.key'));
        }

        return mb_substr($text, 0, 500);
    }

    private function record(string $task, string $tier, string $provider, string $model, int $in, int $out, int $ms, bool $ok, string $error): int
    {
        $settings = $this->app->settings();
        $cost = max(0, $in) * max(0, $settings->int('ai.' . $tier . '.price_in', 0))
            + max(0, $out) * max(0, $settings->int('ai.' . $tier . '.price_out', 0));
        try {
            ++$this->meter['calls'];
            $this->meter['cost'] += $cost;
            if ($provider !== 'fake' && (($in > 0 && !$settings->int('ai.' . $tier . '.price_in')) || ($out > 0 && !$settings->int('ai.' . $tier . '.price_out')))) {
                ++$this->meter['unpriced'];
            }
            return $this->app->db()->insert('ai_usage', [
                'day' => Usage::day($this->app),
                'task' => substr($task, 0, 80),
                'tier' => $tier,
                'provider' => substr($provider, 0, 24),
                'model' => substr($model, 0, 120),
                'tokens_in' => max(0, $in),
                'tokens_out' => max(0, $out),
                'cost_micro' => $cost,
                'ms' => max(0, $ms),
                'ok' => $ok ? 1 : 0,
                'error' => $ok || $error === '' ? null : substr($error, 0, 500),
                'created_at' => $this->app->clock()->sql(),
            ]);
        } catch (\Throwable $e) {
            // Apuntar el gasto no puede tapar el error de verdad.
            $this->app->log()->exception($e, 'ai:usage');

            return 0;
        }
    }
}
