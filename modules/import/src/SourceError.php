<?php
/**
 * AIFEC — el origen de una migración ha fallado (red, error del servidor, consulta que revienta). Se puede
 * reintentar: a la tercera seguida, la migración se pausa con este motivo. El mensaje nunca lleva credenciales.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

class SourceError extends \RuntimeException
{
}
