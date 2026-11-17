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

/**
 * Cliente HTTP de mentira para las pruebas: contesta lo que se le prepara y apunta lo que se le pide.
 *
 *   $http = new FakeHttpClient();
 *   $http->queue(new HttpResponse(200, [], '{"ok":true}'));          // respuestas en cola, por orden
 *   $http->queue(new HttpException('timeout'));                      // o un fallo de red
 *   $http->when('POST', '/v1/orders', function (HttpRequest $r) { return new HttpResponse(201, [], '{}'); });
 *   ...
 *   $http->requests[0]->url
 */
final class FakeHttpClient implements HttpClient
{
    /** @var HttpRequest[] todo lo que se ha pedido, por orden */
    public $requests = [];

    /** @var array<int,HttpResponse|HttpException> */
    private $queue = [];

    /** @var array<int,array{0:string,1:string,2:callable}> */
    private $rules = [];

    /**
     * @param HttpResponse|HttpException ...$responses
     */
    public function queue(...$responses): self
    {
        foreach ($responses as $response) {
            if (!$response instanceof HttpResponse && !$response instanceof HttpException) {
                throw new \InvalidArgumentException('FakeHttpClient::queue() expects HttpResponse or HttpException');
            }
            $this->queue[] = $response;
        }

        return $this;
    }

    /**
     * Regla por método («*» vale por cualquiera) y trozo de URL. El callable recibe la petición y
     * devuelve la respuesta (o lanza HttpException). Las reglas se miran antes que la cola.
     */
    public function when(string $method, string $urlContains, callable $responder): self
    {
        $this->rules[] = [strtoupper($method), $urlContains, $responder];

        return $this;
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $this->requests[] = $request;

        foreach ($this->rules as $rule) {
            if (($rule[0] === '*' || $rule[0] === $request->method) && strpos($request->url, $rule[1]) !== false) {
                return $rule[2]($request);
            }
        }

        if ($this->queue === []) {
            throw new HttpException('FakeHttpClient: no response prepared for ' . $request->method . ' ' . $request->url);
        }
        $next = array_shift($this->queue);
        if ($next instanceof HttpException) {
            throw $next;
        }

        return $next;
    }

    public function last(): ?HttpRequest
    {
        return $this->requests === [] ? null : $this->requests[count($this->requests) - 1];
    }
}
