<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Media;

/**
 * Error al leer, comprobar, convertir o limpiar un fichero multimedia: no es una imagen
 * admitida, es demasiado grande, está corrupta o no se puede escribir el resultado.
 */
class MediaException extends \RuntimeException
{
}
