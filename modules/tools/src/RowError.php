<?php
/**
 * AIFEC — un error de una fila del CSV: la fila se salta, se apunta su motivo y las demás siguen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tools;

/**
 * El mensaje va en inglés y fijo; quien lo enseña lo pasa por la traducción.
 */
final class RowError extends \RuntimeException
{
}
