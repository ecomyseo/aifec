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

use Aifec\Http\HttpClient;
use Aifec\Http\HttpException;
use Aifec\Http\HttpRequest;
use Aifec\Http\HttpResponse;

/**
 * Envío de una petición JSON a un servicio de IA con las reglas comunes: un fallo de red es
 * transitorio; 429 y 5xx se reintentan UNA vez tras una espera corta; y ningún mensaje de error
 * lleva la clave. Una petición con presupuesto propio puede prohibir el reintento.
 */
final class AiTransport
{
    /** @var HttpClient */
    private $http;

    /** @var string */
    private $secret;

    /** @var callable recibe microsegundos */
    private $sleep;

    /** @var int */
    private $retryDelayUs;

    public function __construct(HttpClient $http, string $secret, callable $sleep, int $retryDelayUs = 800000)
    {
        $this->http = $http;
        $this->secret = $secret;
        $this->sleep = $sleep;
        $this->retryDelayUs = $retryDelayUs;
    }

    /**
     * Devuelve la respuesta de cualquier estado salvo 429 y 5xx, que (tras el reintento) lanzan.
     *
     * @param mixed                $body
     * @param array<string,string> $headers
     *
     * @throws AiException
     */
    public function post(string $url, $body, array $headers, float $timeout, bool $allowRetries = true): HttpResponse
    {
        try {
            $request = HttpRequest::postJson($url, $body, $headers, $timeout);
        } catch (\JsonException $e) {
            throw new AiException('The request body cannot be encoded as JSON: ' . $e->getMessage(), false);
        }

        $last = null;
        $attempts = $allowRetries ? 2 : 1;
        for ($attempt = 0; $attempt < $attempts; ++$attempt) {
            try {
                $response = $this->http->send($request);
            } catch (HttpException $e) {
                throw new AiException('Network error: ' . AiException::redact($e->getMessage(), $this->secret), true);
            }
            if ($response->status !== 429 && $response->status < 500) {
                return $response;
            }
            $last = $response;
            if ($attempt + 1 < $attempts) {
                ($this->sleep)($this->retryDelayUs);
            }
        }

        throw $this->errorFor($last);
    }

    /** Excepción para una respuesta que no es 2xx. 429 y 5xx son transitorias; el resto, no. */
    public function errorFor(?HttpResponse $response): AiException
    {
        if ($response === null) {
            return new AiException('No response received', true);
        }
        $status = $response->status;
        $detail = '';
        $json = $response->json();
        if (is_array($json) && isset($json['error'])) {
            $err = $json['error'];
            if (is_array($err) && isset($err['message']) && is_string($err['message'])) {
                $detail = $err['message'];
            } elseif (is_string($err)) {
                $detail = $err;
            }
        }
        if ($detail === '') {
            $detail = (string) preg_replace('/\s+/', ' ', substr($response->body, 0, 300));
        }
        $detail = AiException::redact(trim($detail), $this->secret);
        if (strlen($detail) > 300) {
            $detail = substr($detail, 0, 300) . '...';
        }
        $transient = $status === 429 || $status >= 500;

        return new AiException('AI service answered HTTP ' . $status . ($detail !== '' ? ': ' . $detail : ''), $transient, $status);
    }

    /**
     * Cuerpo de una respuesta 2xx como array JSON; si no lo es, fallo transitorio (un servicio
     * a medias contesta HTML con un 200).
     *
     * @return array<mixed>
     *
     * @throws AiException
     */
    public function decode(HttpResponse $response): array
    {
        if (!$response->ok()) {
            throw $this->errorFor($response);
        }
        $json = $response->json();
        if (!is_array($json)) {
            throw new AiException('The AI service answered something that is not JSON', true, $response->status);
        }

        return $json;
    }
}
