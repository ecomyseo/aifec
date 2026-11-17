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
 * Cliente HTTP saliente. Todo lo que llame a un servicio externo (IA, pasarelas, importadores)
 * recibe uno por constructor: en producción CurlHttpClient, en las pruebas FakeHttpClient.
 */
interface HttpClient
{
    /** @throws HttpException si no se llega a obtener respuesta (DNS, conexión, tiempo agotado) */
    public function send(HttpRequest $request): HttpResponse;
}
