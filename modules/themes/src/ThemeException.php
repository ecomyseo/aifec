<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Themes;

/**
 * Algo que el módulo de temas no puede hacer (un tema que no existe, un nombre no válido, un tema con fallos bloqueantes).
 * El mensaje va en inglés; el panel lo traduce al enseñarlo. Si viene de un informe del validador, lo lleva dentro.
 */
final class ThemeException extends \RuntimeException
{
    /** @var array<string,mixed> */
    private $report;

    /**
     * @param array<string,mixed> $report informe del validador que motivó el rechazo (vacío si no hay)
     */
    public function __construct(string $message, array $report = [])
    {
        parent::__construct($message);
        $this->report = $report;
    }

    /**
     * @return array<string,mixed>
     */
    public function report(): array
    {
        return $this->report;
    }
}
