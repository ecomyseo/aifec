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

/** La descripción larga del producto: unos párrafos de texto plano que salen como HTML simple (<p>), nunca con HTML del modelo. */
final class DescriptionGenerator extends Generator
{
    private const MAX_PARAGRAPHS = 8;

    public function task(): string
    {
        return 'product.description';
    }

    public function field(): string
    {
        return 'description';
    }

    public function tier(): string
    {
        return 'standard';
    }

    protected function maxTokens(): int
    {
        return 1536;
    }

    protected function instruction(string $language, array $options): string
    {
        return 'You write the description of a product for an online shop. Write in ' . $language . '. '
            . 'Write two to four short paragraphs of plain text, about 120 to 250 words in total: no markdown, no HTML, no emojis, no headings. '
            . 'Use only facts that appear in the product data. Never invent measurements, materials, certifications, prices, stock, delivery times or guarantees; if a detail is missing, leave it out. '
            . 'Do not use empty praise or promises you cannot verify. '
            . 'Answer with a JSON object that has a "paragraphs" list of strings.';
    }

    protected function schema(array $options): array
    {
        return [
            'type' => 'object',
            'properties' => ['paragraphs' => ['type' => 'array', 'items' => ['type' => 'string']]],
            'required' => ['paragraphs'],
        ];
    }

    public function proposed(array $json, array $context): ?string
    {
        if (!isset($json['paragraphs']) || !is_array($json['paragraphs'])) {
            return null;
        }
        $html = [];
        foreach ($json['paragraphs'] as $paragraph) {
            if (!is_string($paragraph)) {
                return null;
            }
            $paragraph = trim((string) preg_replace('/\s+/u', ' ', $paragraph));
            if ($paragraph !== '') {
                $html[] = '<p>' . e($paragraph) . '</p>';
            }
        }
        if ($html === [] || count($html) > self::MAX_PARAGRAPHS) {
            return null;
        }

        return implode("\n", $html);
    }
}
