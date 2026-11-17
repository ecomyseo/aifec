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

/** La IA está apagada en los ajustes: no se hace ninguna llamada. */
final class AiDisabled extends AiException
{
}
