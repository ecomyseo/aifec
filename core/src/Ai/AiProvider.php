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

/**
 * Una sola forma de pedir texto, JSON, llamadas a herramientas y vectores a cualquier modelo.
 * Los módulos reciben un AiProvider por constructor; en las pruebas y las demos, FakeProvider.
 */
interface AiProvider
{
    /** Nombre corto del proveedor ('gemini', 'openai-compat', 'fake'). */
    public function name(): string;

    /** @throws AiException */
    public function generate(AiRequest $r): AiResult;

    /**
     * Un vector de floats por texto, en el mismo orden que los textos.
     *
     * @param string[] $texts
     *
     * @return array<int,array<int,float>>
     *
     * @throws AiException
     */
    public function embed(array $texts, string $model = ''): array;
}
