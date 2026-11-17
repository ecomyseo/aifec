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

use Aifec\Ai\AiMessage;
use Aifec\Ai\Prompt;
use Aifec\Modules\Ai\ProductFields;

/**
 * La traducción de cualquier campo de un producto a otro idioma. El texto de origen es la propuesta de lo que hay en el idioma
 * principal; el campo sale de $options['field'] y el idioma de destino de $options['to'].
 *
 * Los campos HTML (resumen, descripción) se piden con sus etiquetas y se sanean al guardar; las listas de datos y de preguntas
 * se traducen elemento a elemento.
 */
final class TranslateGenerator extends Generator
{
    /** @var string el campo que se traduce: lo fija Generators::run() antes de pedir */
    private $field = 'description';

    public function task(): string
    {
        return 'product.translate';
    }

    public function field(): string
    {
        return $this->field;
    }

    /** Fija el campo que se traduce. */
    public function forField(string $field): self
    {
        if (!ProductFields::isField($field)) {
            throw new \InvalidArgumentException('Unknown field: ' . $field);
        }
        $clone = clone $this;
        $clone->field = $field;

        return $clone;
    }

    protected function maxTokens(): int
    {
        return 2048;
    }

    protected function instruction(string $language, array $options): string
    {
        $shape = $this->field === 'facts' || $this->field === 'faqs'
            ? 'Translate every item and keep the same number of items in the same order. '
            : ($this->field === 'summary' || $this->field === 'description'
                ? 'The source is HTML: keep its tags and structure and translate only the text. '
                : 'The source is a single line of plain text. ');

        return 'You translate product texts of an online shop into ' . $language . '. '
            . 'Translate faithfully: do not add, remove, improve or summarise anything, and keep numbers, units, model names and brand names as they are. '
            . $shape
            . 'Answer with a JSON object that matches the schema.';
    }

    protected function schema(array $options): array
    {
        if ($this->field === 'facts') {
            return ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'string']],
                'required' => ['label', 'value'],
            ]]], 'required' => ['items']];
        }
        if ($this->field === 'faqs') {
            return ['type' => 'object', 'properties' => ['items' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['question' => ['type' => 'string'], 'answer' => ['type' => 'string']],
                'required' => ['question', 'answer'],
            ]]], 'required' => ['items']];
        }

        return ['type' => 'object', 'properties' => ['text' => ['type' => 'string']], 'required' => ['text']];
    }

    /**
     * El texto de origen va cercado: es dato del producto, no una orden.
     */
    public function request(array $context, string $lang, array $options = []): array
    {
        $source = isset($options['source']) && is_string($options['source']) ? $options['source'] : '';

        return [
            'system' => $this->instruction(self::languageName($lang), $options),
            'messages' => [AiMessage::user('Source text follows.' . "\n" . Prompt::fence('SOURCE', $source))],
            'schema' => $this->schema($options),
            'max_tokens' => $this->maxTokens(),
        ];
    }

    public function proposed(array $json, array $context): ?string
    {
        if ($this->field === 'facts' || $this->field === 'faqs') {
            if (!isset($json['items']) || !is_array($json['items'])) {
                return null;
            }
            $list = ProductFields::decodeList($this->field, (string) json_encode($json['items'], JSON_UNESCAPED_UNICODE));
            if ($list === null || $list === []) {
                return null;
            }

            return ProductFields::encode($list);
        }
        if (!isset($json['text']) || !is_string($json['text'])) {
            return null;
        }
        $text = ProductFields::clean($this->field, $json['text']);

        return $text === '' ? null : $text;
    }
}
