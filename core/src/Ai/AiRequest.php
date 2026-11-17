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

/** Lo que se le pide a un modelo, igual para cualquier proveedor. */
final class AiRequest
{
    /** Los modelos actuales piensan dentro del tope de salida: por debajo de esto devuelven vacío. */
    public const MIN_OUTPUT_TOKENS = 256;

    /** @var string instrucciones del sistema */
    public string $system;

    /** @var AiMessage[] */
    public array $messages;

    /** @var AiTool[] */
    public array $tools;

    /** @var array<string,mixed>|null si se da, la respuesta tiene que ser JSON con esta forma */
    public ?array $jsonSchema;

    /** @var string vacío = el modelo por defecto del proveedor */
    public string $model;

    /** @var float|null */
    public ?float $temperature;

    /** @var int */
    public int $maxOutputTokens;

    /** @var float segundos */
    public float $timeout;

    /**
     * @var bool las herramientas siguen declaradas (la conversación lleva sus llamadas) pero en esta petición el modelo solo
     *           puede contestar con texto: no puede pedir otra
     */
    public bool $textOnly = false;

    /** @var bool las rondas con presupuesto explícito pueden prohibir cualquier reenvío al proveedor */
    public bool $allowRetries = true;

    /**
     * @param AiMessage[]              $messages
     * @param AiTool[]                 $tools
     * @param array<string,mixed>|null $jsonSchema
     */
    public function __construct(
        string $system = '',
        array $messages = [],
        array $tools = [],
        ?array $jsonSchema = null,
        string $model = '',
        ?float $temperature = null,
        int $maxOutputTokens = 1024,
        float $timeout = 25.0
    ) {
        $this->system = $system;
        $this->messages = $messages;
        $this->tools = $tools;
        $this->jsonSchema = $jsonSchema;
        $this->model = $model;
        $this->temperature = $temperature;
        $this->maxOutputTokens = $maxOutputTokens;
        $this->timeout = $timeout;
    }

    /** Tope de salida que se envía de verdad: nunca menos de MIN_OUTPUT_TOKENS. */
    public function effectiveMaxOutputTokens(): int
    {
        return max(self::MIN_OUTPUT_TOKENS, $this->maxOutputTokens);
    }
}
