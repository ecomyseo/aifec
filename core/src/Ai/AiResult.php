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

/** Lo que contesta un modelo, igual para cualquier proveedor. */
final class AiResult
{
    /** @var string */
    public string $text;

    /** @var AiToolCall[] */
    public array $toolCalls;

    /** @var array<mixed>|null respuesta decodificada cuando se pidió jsonSchema */
    public ?array $json;

    /** @var int */
    public int $tokensIn;

    /** @var int */
    public int $tokensOut;

    /** @var string motivo de fin que da el proveedor (STOP, MAX_TOKENS, stop, length…) */
    public string $finishReason;

    /** @var string modelo que contestó */
    public string $model;

    /**
     * @param AiToolCall[]     $toolCalls
     * @param array<mixed>|null $json
     */
    public function __construct(
        string $text = '',
        array $toolCalls = [],
        ?array $json = null,
        int $tokensIn = 0,
        int $tokensOut = 0,
        string $finishReason = '',
        string $model = ''
    ) {
        $this->text = $text;
        $this->toolCalls = $toolCalls;
        $this->json = $json;
        $this->tokensIn = $tokensIn;
        $this->tokensOut = $tokensOut;
        $this->finishReason = $finishReason;
        $this->model = $model;
    }
}
