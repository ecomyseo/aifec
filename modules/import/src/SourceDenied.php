<?php
/**
 * AIFEC — el origen de una migración niega el acceso (clave incorrecta o sin permiso, usuario de la base de datos
 * sin lectura). No se reintenta: la migración se pausa en el acto y lo dice.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import;

final class SourceDenied extends SourceError
{
}
