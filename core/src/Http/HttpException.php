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

/** No se obtuvo respuesta: fallo de red, de DNS o tiempo agotado. Un 4xx o un 5xx NO lanza esto. */
final class HttpException extends \RuntimeException
{
}
