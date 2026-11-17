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

/** Petición HTTP saliente. Se trata como inmutable: no se modifican sus propiedades después de crearla. */
final class HttpRequest
{
    /** @var string */
    public $method;

    /** @var string */
    public $url;

    /** @var array<string,string> cabeceras «Nombre => valor» */
    public $headers;

    /** @var string|null */
    public $body;

    /** @var float segundos */
    public $timeout;

    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $method, string $url, array $headers = [], ?string $body = null, float $timeout = 20.0)
    {
        $this->method = strtoupper($method);
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;
        $this->timeout = $timeout;
    }

    /** @param array<string,string> $headers */
    public static function get(string $url, array $headers = [], float $timeout = 20.0): self
    {
        return new self('GET', $url, $headers, null, $timeout);
    }

    /**
     * @param mixed                $data    cualquier valor que se pueda pasar a JSON
     * @param array<string,string> $headers
     */
    public static function postJson(string $url, $data, array $headers = [], float $timeout = 20.0): self
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return new self('POST', $url, $headers + ['Content-Type' => 'application/json'], $json, $timeout);
    }

    /**
     * @param array<string,string|int|float> $fields
     * @param array<string,string>           $headers
     */
    public static function postForm(string $url, array $fields, array $headers = [], float $timeout = 20.0): self
    {
        return new self(
            'POST',
            $url,
            $headers + ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($fields),
            $timeout
        );
    }
}
