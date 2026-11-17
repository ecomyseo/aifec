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
 * Fallo al hablar con un modelo. El mensaje NUNCA contiene la clave de la API.
 * isTransient() dice si merece la pena reintentar más tarde (red, 429, 5xx, respuesta cortada).
 */
class AiException extends \RuntimeException
{
    /** @var bool */
    private $transient;

    /** @var int */
    private $status;

    public function __construct(string $message, bool $transient = false, int $status = 0)
    {
        parent::__construct($message);
        $this->transient = $transient;
        $this->status = $status;
    }

    public function isTransient(): bool
    {
        return $this->transient;
    }

    /** Código HTTP de la respuesta que causó el fallo, o 0 si no hubo respuesta. */
    public function status(): int
    {
        return $this->status;
    }

    /** Quita la clave de un texto que va a entrar en un mensaje de error o en un registro. */
    public static function redact(string $text, string $secret): string
    {
        if ($secret === '') {
            return $text;
        }

        return str_replace($secret, '***', $text);
    }
}
