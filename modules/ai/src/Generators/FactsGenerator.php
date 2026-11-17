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

/**
 * Los datos clave (pares etiqueta/valor) sacados de lo que ya dice la ficha. No inventa: la instrucción lo exige y, además, cada
 * valor que propone el modelo se comprueba contra el texto y los atributos del producto; el que no aparece en ellos se descarta.
 */
final class FactsGenerator extends Generator
{
    private const MAX_ITEMS = 12;

    public function task(): string
    {
        return 'product.facts';
    }

    public function field(): string
    {
        return 'facts';
    }

    protected function instruction(string $language, array $options): string
    {
        return 'You extract the key facts of a product for an online shop. Write the labels in ' . $language . '. '
            . 'A fact is a short label and a value, for example "Material" and "Solid oak". '
            . 'Extract only facts that are written in the product data, with the same value: never invent, convert, round or guess a measurement, a material or a certification. '
            . 'Never include the price, the availability or the stock: the shop shows those itself, live. '
            . 'If the product data has no facts, return an empty list. '
            . 'Answer with a JSON object that has a "facts" list of objects with a "label" and a "value" string.';
    }

    protected function schema(array $options): array
    {
        return [
            'type' => 'object',
            'properties' => ['facts' => ['type' => 'array', 'items' => [
                'type' => 'object',
                'properties' => ['label' => ['type' => 'string'], 'value' => ['type' => 'string']],
                'required' => ['label', 'value'],
            ]]],
            'required' => ['facts'],
        ];
    }

    public function proposed(array $json, array $context): ?string
    {
        if (!isset($json['facts']) || !is_array($json['facts'])) {
            return null;
        }
        $list = ProductFields::decodeList('facts', (string) json_encode($json['facts'], JSON_UNESCAPED_UNICODE));
        if ($list === null) {
            return null;
        }
        $product = isset($context['product']) && is_array($context['product']) ? $context['product'] : [];
        $corpus = self::corpus($product);
        $kept = [];
        foreach ($list as $fact) {
            if (self::supported($fact['value'], $corpus)) {
                $kept[] = $fact;
            }
        }
        // El precio y la disponibilidad los da la tienda al momento: guardados como dato se quedan viejos y la ficha acaba
        // contradiciéndose («En stock» arriba, «Agotado» en la tabla). No se proponen aunque el modelo los traiga.
        $kept = \Aifec\Modules\Catalog\Texts::shownFacts($kept);
        if ($kept === [] || count($kept) > self::MAX_ITEMS) {
            return null;
        }

        return ProductFields::encode($kept);
    }

    /**
     * Todo el texto de la ficha en el que puede apoyarse un dato: nombre, resúmenes, descripción, atributos, datos que ya tenía,
     * marca, medidas y peso. En minúsculas y con los espacios colapsados.
     *
     * @param array<string,mixed> $product
     */
    private static function corpus(array $product): string
    {
        $parts = [self::plain($product, 'name'), self::plain($product, 'summary'), self::plain($product, 'description'), self::plain($product, 'sku'), self::plain($product, 'gtin'), self::plain($product, 'mpn')];
        if (isset($product['brand']) && is_array($product['brand']) && isset($product['brand']['name'])) {
            $parts[] = (string) $product['brand']['name'];
        }
        foreach (isset($product['categories']) && is_array($product['categories']) ? $product['categories'] : [] as $category) {
            if (is_array($category) && isset($category['name'])) {
                $parts[] = (string) $category['name'];
            }
        }
        foreach (self::dataLines($product) as $line) {
            $parts[] = $line;
        }

        return self::normal(implode(' ', $parts));
    }

    /**
     * ¿Aparece el valor en la ficha? O el texto entero o, si no, todas sus palabras (las de más de tres letras) y todos sus números,
     * cada uno como palabra suelta: «130» no se apoya en «130000».
     */
    private static function supported(string $value, string $corpus): bool
    {
        $needle = self::normal($value);
        if ($needle === '') {
            return false;
        }
        if (self::has($corpus, $needle)) {
            return true;
        }
        $tokens = preg_split('/[^\p{L}\p{N}.,]+/u', $needle, -1, PREG_SPLIT_NO_EMPTY);
        $checked = 0;
        foreach (is_array($tokens) ? $tokens : [] as $token) {
            $token = trim($token, '.,');
            if ($token === '') {
                continue;
            }
            if (preg_match('/\d/', $token) !== 1 && mb_strlen($token, 'UTF-8') <= 3) {
                continue;
            }
            if (!self::has($corpus, $token)) {
                return false;
            }
            ++$checked;
        }

        return $checked > 0;
    }

    /** El texto aparece en el corpus como palabra o palabras completas. */
    private static function has(string $corpus, string $needle): bool
    {
        return preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $corpus) === 1;
    }

    private static function normal(string $text): string
    {
        $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
