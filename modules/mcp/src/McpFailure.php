<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Mcp;

/**
 * Una herramienta que no ha podido hacer lo que se le pedía (sin la clave, sin la confirmación, con la ficha vieja…). Sale al
 * cliente como un resultado con isError = true, no como un error del protocolo.
 */
final class McpFailure extends \RuntimeException
{
}
