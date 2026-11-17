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

use Aifec\Modules\Ai\ProductFields;

/** Las preguntas frecuentes del producto: preguntas y respuestas que salen de los datos, como lista JSON. */
final class FaqsGenerator extends Generator
{
    private const MAX_ITEMS = 8;

    public function task(): string
    {
        return 'product.faqs';
    }

    public function field(): string
    {
        return 'faqs';
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
        return 'You write the frequently asked questions of a product for an online shop. Write in ' . $language . '. '
            . 'Three to six questions a buyer would really ask, each with a short answer of one or two sentences. '
            . 'Answer only with what the product data says: if the data does not answer a question, do not ask it. Never invent measurements, materials, compatibility, delivery times or guarantees. '
            . 'Answer with a JSON object that has a "faqs" list of objects with a "question" and an "answer" string.';
    }

    protected function schema(array $options): array
    {
        return [
            'type' => 'object',
            'properties' => ['faqs' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['question' => ['type' => 'string'], 'answer' => ['type' => 'string']],
                'required' => ['question', 'answer'],
            ]]],
            'required' => ['faqs'],
        ];
    }

    public function proposed(array $json, array $context): ?string
    {
        if (!isset($json['faqs']) || !is_array($json['faqs'])) {
            return null;
        }
        $list = ProductFields::decodeList('faqs', (string) json_encode($json['faqs'], JSON_UNESCAPED_UNICODE));
        if ($list === null || $list === [] || count($list) > self::MAX_ITEMS) {
            return null;
        }

        return ProductFields::encode($list);
    }
}
