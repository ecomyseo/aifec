<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Jobs;

/**
 * Un manejador la lanza cuando repetir el trabajo no lo arreglaría (dirección mal escrita, dato que falta,
 * servicio que contesta «no»): el trabajo pasa a «failed» a la primera, sin gastar más intentos.
 * Está pensada para heredarse (un módulo puede tener sus propios fallos permanentes).
 */
class PermanentFailure extends \RuntimeException
{
}
