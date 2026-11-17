<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Erp;

/**
 * Un error de la API para ERP: estado HTTP, código estable para programas, mensaje (en inglés, fijo) y el campo que lo causó.
 * El intermediario lo convierte en {"error":{"status","code","message","field"}}.
 */
final class ApiException extends \RuntimeException
{
    /** @var int */
    private $status;

    /** @var string */
    private $errorCode;

    /** @var string|null */
    private $field;

    public function __construct(int $status, string $code, string $message, ?string $field = null)
    {
        parent::__construct($message);
        $this->status = $status;
        $this->errorCode = $code;
        $this->field = $field;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function field(): ?string
    {
        return $this->field;
    }

    public static function invalid(string $message, ?string $field = null): self
    {
        return new self(422, 'validation_failed', $message, $field);
    }

    public static function notFound(string $code, string $message): self
    {
        return new self(404, $code, $message);
    }
}
