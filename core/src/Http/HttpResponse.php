<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Http;

final class HttpResponse
{
    /** @var int */
    public $status;

    /** @var array<string,string> cabeceras con el nombre en minúsculas */
    public $headers;

    /** @var string */
    public $body;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(int $status, array $headers = [], string $body = '')
    {
        $this->status = $status;
        $this->headers = array_change_key_case($headers, CASE_LOWER);
        $this->body = $body;
    }

    public function ok(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Cuerpo como JSON. Devuelve null si no es JSON válido (un servicio caído contesta HTML).
     *
     * @return mixed
     */
    public function json()
    {
        if ($this->body === '') {
            return null;
        }
        try {
            return json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }
}
