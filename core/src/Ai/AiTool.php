<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Ai;

/** Una herramienta que se ofrece al modelo. */
final class AiTool
{
    /** @var string */
    public string $name;

    /** @var string */
    public string $description;

    /** @var array<string,mixed> esquema JSON de los argumentos */
    public array $parameters;

    /**
     * @param array<string,mixed> $parameters
     */
    public function __construct(string $name = '', string $description = '', array $parameters = [])
    {
        $this->name = $name;
        $this->description = $description;
        $this->parameters = $parameters;
    }
}
