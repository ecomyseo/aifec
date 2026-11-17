<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Ai\Generators;

/** El resumen corto del producto: una o dos frases, como un párrafo. */
final class SummaryGenerator extends Generator
{
    private const MAX_CHARS = 300;

    public function task(): string
    {
        return 'product.summary';
    }

    public function field(): string
    {
        return 'summary';
    }

    protected function instruction(string $language, array $options): string
    {
        return 'You write the short summary of a product for an online shop. Write in ' . $language . '. '
            . 'One or two sentences, at most ' . self::MAX_CHARS . ' characters, plain text. '
            . 'Use only facts that appear in the product data and never invent any. '
            . 'Answer with a JSON object that has a "summary" string.';
    }

    protected function schema(array $options): array
    {
        return ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']], 'required' => ['summary']];
    }

    public function proposed(array $json, array $context): ?string
    {
        $text = self::line($json, 'summary', self::MAX_CHARS);

        return $text === null ? null : '<p>' . e($text) . '</p>';
    }
}
