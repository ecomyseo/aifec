<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Assistant;

/**
 * El reloj en milisegundos con el que el asistente mide cuánto tarda la IA y cuánto las herramientas, y con el que corta un mensaje
 * que se alarga (el tope de 12 segundos). Es un reloj monótono, no una fecha: solo sirve para restar. Las pruebas lo sustituyen por
 * otro que avanza a mano (servicio «assistant.timer»).
 */
class Timer
{
    /** Los milisegundos de un reloj monótono. */
    public function ms(): int
    {
        return (int) floor(hrtime(true) / 1000000);
    }
}
