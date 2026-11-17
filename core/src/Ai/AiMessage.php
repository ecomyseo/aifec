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

/** Un mensaje de la conversación: del usuario, del asistente (con sus llamadas a herramienta) o el resultado de una herramienta. */
final class AiMessage
{
    /** @var string 'user', 'assistant' o 'tool' */
    public string $role;

    /** @var string */
    public string $content;

    /** @var AiToolCall[] llamadas que pidió el asistente en este mensaje */
    public array $toolCalls;

    /** @var string nombre de la herramienta (mensajes 'tool') */
    public string $toolName;

    /** @var array<mixed> resultado de la herramienta (mensajes 'tool') */
    public array $toolResult;

    /** @var string identificador de la llamada a la que responde (opcional; si falta se casa por nombre) */
    public string $toolCallId;

    /**
     * @param AiToolCall[] $toolCalls
     * @param array<mixed> $toolResult
     */
    public function __construct(
        string $role = 'user',
        string $content = '',
        array $toolCalls = [],
        string $toolName = '',
        array $toolResult = [],
        string $toolCallId = ''
    ) {
        if (!in_array($role, ['user', 'assistant', 'tool'], true)) {
            throw new \InvalidArgumentException('Invalid message role: ' . $role);
        }
        $this->role = $role;
        $this->content = $content;
        $this->toolCalls = $toolCalls;
        $this->toolName = $toolName;
        $this->toolResult = $toolResult;
        $this->toolCallId = $toolCallId;
    }

    public static function user(string $text): self
    {
        return new self('user', $text);
    }

    /**
     * @param AiToolCall[] $calls
     */
    public static function assistant(string $text, array $calls = []): self
    {
        return new self('assistant', $text, $calls);
    }

    /**
     * @param array<mixed> $result
     */
    public static function tool(string $name, array $result, string $callId = ''): self
    {
        return new self('tool', '', [], $name, $result, $callId);
    }
}
