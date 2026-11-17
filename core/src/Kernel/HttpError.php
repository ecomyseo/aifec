<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Error con código HTTP que un controlador lanza para cortar: `throw HttpError::notFound();`.
 * El núcleo lo convierte en la página de error del tema (o en JSON si la petición lo espera).
 */
final class HttpError extends \RuntimeException
{
    /** @var int */
    private $status;

    public function __construct(int $status, string $message = '')
    {
        parent::__construct($message !== '' ? $message : 'HTTP ' . $status);
        $this->status = $status;
    }

    public function status(): int
    {
        return $this->status;
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return new self(403, $message);
    }

    public static function badRequest(string $message = 'Bad request'): self
    {
        return new self(400, $message);
    }

    public static function tooMany(string $message = 'Too many requests'): self
    {
        return new self(429, $message);
    }
}
