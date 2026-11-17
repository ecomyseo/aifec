<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Sites;

/**
 * Un paquete de demostración que no vale. El mensaje dice qué fichero y qué elemento falla; `problems()` trae la lista
 * completa (el mensaje solo enseña los primeros).
 */
final class DemoPackageException extends \InvalidArgumentException
{
    /** @var string[] */
    private $problems;

    /**
     * @param string[] $problems
     */
    public function __construct(string $package, array $problems)
    {
        $this->problems = array_values($problems);
        $shown = array_slice($this->problems, 0, 8);
        $more = count($this->problems) - count($shown);
        parent::__construct('Invalid demo package «' . $package . '»: ' . implode(' | ', $shown) . ($more > 0 ? ' | and ' . $more . ' more' : ''));
    }

    /**
     * @return string[]
     */
    public function problems(): array
    {
        return $this->problems;
    }
}
