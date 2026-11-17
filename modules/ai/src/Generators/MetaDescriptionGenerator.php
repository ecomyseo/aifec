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

/** La descripción para los buscadores: una frase de texto plano de unos 150 caracteres. */
final class MetaDescriptionGenerator extends Generator
{
    private const MAX_CHARS = 170;

    public function task(): string
    {
        return 'product.meta_description';
    }

    public function field(): string
    {
        return 'meta_description';
    }

    protected function instruction(string $language, array $options): string
    {
        return 'You write the search engine description of a product page for an online shop. Write in ' . $language . '. '
            . 'One or two sentences of plain text, about 150 characters, that say what the product is and what makes it useful. '
            . 'Use only facts that appear in the product data and never invent any. No quotes, no emojis. '
            . 'Answer with a JSON object that has a "description" string.';
    }

    protected function schema(array $options): array
    {
        return ['type' => 'object', 'properties' => ['description' => ['type' => 'string']], 'required' => ['description']];
    }

    public function proposed(array $json, array $context): ?string
    {
        return self::line($json, 'description', self::MAX_CHARS);
    }
}
