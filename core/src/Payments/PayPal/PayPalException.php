<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Payments\PayPal;

/** Error al hablar con PayPal. Nunca lleva el secreto ni la ficha de acceso. */
final class PayPalException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var string */
    private $paypalName;

    /** @var string */
    private $debugId;

    /** @var bool */
    private $transient;

    /** @var string */
    private $issue;

    public function __construct(
        string $message,
        int $status = 0,
        string $paypalName = '',
        string $debugId = '',
        bool $transient = false,
        ?\Throwable $previous = null,
        string $issue = ''
    ) {
        parent::__construct($message, 0, $previous);
        $this->status = $status;
        $this->paypalName = $paypalName;
        $this->debugId = $debugId;
        $this->transient = $transient;
        $this->issue = $issue;
    }

    /** Campo «issue» del primer detalle del error (por ejemplo NOT_ENABLED_FOR_PAYMENT_SOURCE), o vacío. */
    public function issue(): string
    {
        return $this->issue;
    }

    /** Estado HTTP de la respuesta (0 si no hubo respuesta). */
    public function status(): int
    {
        return $this->status;
    }

    /** Campo «name» del error de PayPal (por ejemplo UNPROCESSABLE_ENTITY). */
    public function paypalName(): string
    {
        return $this->paypalName;
    }

    /** Campo «debug_id», el que pide el soporte de PayPal. */
    public function debugId(): string
    {
        return $this->debugId;
    }

    /** Verdadero si merece la pena reintentar más tarde (red caída, 429, 5xx). */
    public function isTransient(): bool
    {
        return $this->transient;
    }
}
