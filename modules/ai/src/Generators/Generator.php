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

/**
 * Una tarea de redacción: su instrucción (en inglés y sin datos de la tienda), el esquema JSON de la respuesta, los datos del
 * producto cercados con Prompt::fence() y cómo se convierte la respuesta en un texto propuesto.
 *
 * Lo que viene del producto es DATO, nunca instrucción: va solo en el mensaje del usuario, dentro de la cerca. La instrucción del
 * sistema no lleva ni un dato del producto, así que un texto malicioso dentro de una ficha no puede cambiarla.
 */
abstract class Generator
{
    /** Nombres de los idiomas que el sistema sabe pedir; el resto se pide por su código. */
    private const LANGUAGES = [
        'es' => 'Spanish', 'en' => 'English', 'fr' => 'French', 'de' => 'German', 'it' => 'Italian', 'pt' => 'Portuguese',
        'ca' => 'Catalan', 'eu' => 'Basque', 'gl' => 'Galician', 'nl' => 'Dutch', 'pl' => 'Polish', 'sv' => 'Swedish',
    ];

    /** Largo máximo de la descripción del producto que se manda al modelo. */
    private const MAX_DESCRIPTION_CHARS = 3000;

    /** Nombre de la tarea para el registro de gasto: «product.description». */
    abstract public function task(): string;

    /** Campo que propone: uno de ProductFields::FIELDS, o «alt» para el texto alternativo de una imagen. */
    abstract public function field(): string;

    /** Instrucción del sistema, en inglés. Solo recibe el nombre del idioma: nunca datos del producto. */
    abstract protected function instruction(string $language, array $options): string;

    /**
     * Esquema JSON de la respuesta.
     *
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    abstract protected function schema(array $options): array;

    /**
     * Convierte la respuesta ya decodificada en el texto propuesto, o null si no cumple el esquema.
     *
     * @param array<mixed>        $json
     * @param array<string,mixed> $context
     */
    abstract public function proposed(array $json, array $context): ?string;

    /** Entidad de la propuesta. */
    public function entity(): string
    {
        return 'product';
    }

    /** El nivel más barato que resuelve la tarea. */
    public function tier(): string
    {
        return 'economy';
    }

    protected function maxTokens(): int
    {
        return 1024;
    }

    /**
     * La petición para Ai::ask().
     *
     * @param array<string,mixed> $context  product (ProductData) y lo que añade cada tarea
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed>
     */
    public function request(array $context, string $lang, array $options = []): array
    {
        return [
            'system' => $this->instruction(self::languageName($lang), $options),
            'messages' => [AiMessage::user('Product data follows.' . "\n" . Prompt::fence('PRODUCT', $this->describe($context, $options)))],
            'schema' => $this->schema($options),
            'max_tokens' => $this->maxTokens(),
        ];
    }

    /** El nombre en inglés de un idioma para la instrucción: «es» → «Spanish». */
    public static function languageName(string $lang): string
    {
        $lang = strtolower(substr($lang, 0, 2));

        return isset(self::LANGUAGES[$lang]) ? self::LANGUAGES[$lang] : ($lang !== '' ? 'the language with code "' . $lang . '"' : 'Spanish');
    }

    /**
     * Los datos del producto como texto, para meter en la cerca.
     *
     * @param array<string,mixed> $context
     * @param array<string,mixed> $options
     */
    protected function describe(array $context, array $options): string
    {
        $product = isset($context['product']) && is_array($context['product']) ? $context['product'] : [];
        $lines = [];
        $lines[] = 'Name: ' . self::plain($product, 'name');
        if (isset($product['brand']) && is_array($product['brand']) && isset($product['brand']['name'])) {
            $lines[] = 'Brand: ' . (string) $product['brand']['name'];
        }
        if (isset($product['categories']) && is_array($product['categories'])) {
            $names = [];
            foreach ($product['categories'] as $category) {
                if (is_array($category) && isset($category['name'])) {
                    $names[] = (string) $category['name'];
                }
            }
            if ($names !== []) {
                $lines[] = 'Categories: ' . implode(', ', $names);
            }
        }
        foreach (['sku' => 'SKU', 'gtin' => 'GTIN', 'mpn' => 'MPN'] as $key => $label) {
            if (isset($product[$key]) && is_scalar($product[$key]) && (string) $product[$key] !== '') {
                $lines[] = $label . ': ' . (string) $product[$key];
            }
        }
        $summary = self::plain($product, 'summary');
        if ($summary !== '') {
            $lines[] = 'Summary: ' . $summary;
        }
        $description = self::plain($product, 'description');
        if ($description !== '') {
            $lines[] = 'Description: ' . Prompt::truncate($description, self::MAX_DESCRIPTION_CHARS);
        }
        $lines = array_merge($lines, self::dataLines($product));

        return implode("\n", $lines);
    }

    /**
     * Atributos, datos comprobables, medidas y peso del producto, una línea cada uno.
     *
     * @param array<string,mixed> $product
     *
     * @return string[]
     */
    public static function dataLines(array $product): array
    {
        $lines = [];
        foreach (isset($product['attributes']) && is_array($product['attributes']) ? $product['attributes'] : [] as $attribute) {
            if (is_array($attribute) && isset($attribute['name'], $attribute['value']) && is_scalar($attribute['value'])) {
                $lines[] = 'Attribute ' . (string) $attribute['name'] . ': ' . (string) $attribute['value'];
            }
        }
        foreach (isset($product['facts']) && is_array($product['facts']) ? $product['facts'] : [] as $fact) {
            if (is_array($fact) && isset($fact['label'], $fact['value'])) {
                $lines[] = 'Fact ' . (string) $fact['label'] . ': ' . (string) $fact['value'];
            }
        }
        if (isset($product['weight']) && is_numeric($product['weight']) && (int) $product['weight'] > 0) {
            $lines[] = 'Weight: ' . (int) $product['weight'] . ' g';
        }
        if (isset($product['dimensions']) && is_array($product['dimensions'])) {
            $d = $product['dimensions'];
            if (!empty($d['length']) || !empty($d['width']) || !empty($d['height'])) {
                $lines[] = 'Dimensions (length x width x height): ' . (int) ($d['length'] ?? 0) . ' x ' . (int) ($d['width'] ?? 0) . ' x ' . (int) ($d['height'] ?? 0) . ' mm';
            }
        }

        return $lines;
    }

    /**
     * Un campo de texto de la ficha sin etiquetas ni espacios sobrantes.
     *
     * @param array<string,mixed> $product
     */
    protected static function plain(array $product, string $key): string
    {
        if (!isset($product[$key]) || !is_scalar($product[$key])) {
            return '';
        }
        $text = (string) preg_replace('/<[^>]*>/', ' ', (string) $product[$key]);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Texto de una línea sacado de la respuesta: recortado, sin saltos y cortado a $max caracteres.
     *
     * @param array<mixed> $json
     */
    protected static function line(array $json, string $key, int $max): ?string
    {
        if (!isset($json[$key]) || !is_string($json[$key])) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', $json[$key]));
        if ($text === '') {
            return null;
        }

        return Prompt::truncate($text, $max);
    }
}
