<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai;

use Aifec\Ai\AiException;

/** Se ha alcanzado un tope de gasto, llamadas o fichas: no se llama al proveedor hasta su reinicio o ajuste. */
final class AiLimitReached extends AiException
{
}
