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

/** El título para los buscadores: una línea de texto plano de unos 60 caracteres. */
final class MetaTitleGenerator extends Generator
{
    private const MAX_CHARS = 70;

    public function task(): string
    {
        return 'product.meta_title';
    }

    public function field(): string
    {
        return 'meta_title';
    }

    protected function instruction(string $language, array $options): string
    {
        return 'You write the search engine title of a product page for an online shop. Write in ' . $language . '. '
            . 'One line of plain text of about 60 characters: the product name first and, if it fits, its most useful distinguishing detail. '
            . 'Use only facts that appear in the product data. No quotes, no emojis, no brand slogans. '
            . 'Answer with a JSON object that has a "title" string.';
    }

    protected function schema(array $options): array
    {
        return ['type' => 'object', 'properties' => ['title' => ['type' => 'string']], 'required' => ['title']];
    }

    public function proposed(array $json, array $context): ?string
    {
        return self::line($json, 'title', self::MAX_CHARS);
    }
}
