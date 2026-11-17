<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Kernel;

/**
 * Reloj del sistema. La lógica nunca llama a time() ni a date(): pide el instante aquí,
 * y así las pruebas pueden fijar la hora. Todo se guarda en UTC.
 */
class Clock
{
    /** @var int|null instante fijado (pruebas); null = el del sistema */
    private $fixed;

    public function __construct(?int $fixed = null)
    {
        $this->fixed = $fixed;
    }

    public function now(): int
    {
        return $this->fixed !== null ? $this->fixed : time();
    }

    /** Fecha y hora en UTC con el formato de la base de datos: «2026-11-12 16:30:00». */
    public function sql(?int $timestamp = null): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp !== null ? $timestamp : $this->now());
    }

    public function set(int $timestamp): void
    {
        $this->fixed = $timestamp;
    }

    public function advance(int $seconds): void
    {
        $this->fixed = $this->now() + $seconds;
    }

    /** Pasa «2026-11-12 16:30:00» (UTC) a marca de tiempo. Devuelve 0 si no es una fecha. */
    public static function parse(?string $sql): int
    {
        if ($sql === null || $sql === '') {
            return 0;
        }
        $t = strtotime($sql . ' UTC');

        return $t === false ? 0 : $t;
    }
}
