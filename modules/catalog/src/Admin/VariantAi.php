<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Kernel\App;

/**
 * La ayuda de la IA para el alta de productos, solo con el módulo «ai» cargado y encendido (el catálogo no depende de él):
 * entender unas combinaciones escritas cuando las reglas no bastan, y proponer la descripción corta y la categoría de un producto
 * a partir de su nombre. Siempre es una propuesta: no guarda nada, la pantalla la enseña y la persona la acepta.
 */
final class VariantAi
{
    /** ¿Puede esta persona usar la IA ahora mismo? */
    public static function available(App $app): bool
    {
        if (!$app->has('ai') || !$app->auth()->can('ai.use')) {
            return false;
        }
        $ai = $app->service('ai');

        return is_object($ai) && method_exists($ai, 'enabled') && method_exists($ai, 'ask') && $ai->enabled();
    }

    /**
     * Pregunta a la IA (nivel económico) qué valores de qué atributos son los del texto. Solo se aceptan identificadores que
     * existen: lo que invente se descarta.
     *
     * @param array<int,array<string,mixed>> $attributes los de VariantAdmin::parserAttributes()
     *
     * @return array<int,int[]> atributo => ids de valores (puede venir vacío)
     *
     * @throws \Throwable si la IA falla (apagada, tope del día, proveedor caído)
     */
    public static function interpret(App $app, string $text, array $attributes): array
    {
        $catalog = [];
        foreach ($attributes as $id => $attr) {
            $values = [];
            foreach ($attr['values'] as $valueId => $value) {
                $values[] = ['id' => (int) $valueId, 'label' => (string) $value['label']];
            }
            $catalog[] = ['id' => (int) $id, 'name' => (string) $attr['name'], 'values' => $values];
        }
        $request = [
            'system' => 'You turn a short description of product variants, written by a shop owner, into choices of attribute values. '
                . 'Use only the attribute ids and value ids of the catalogue you are given and never invent any. '
                . 'Expand ranges such as "sizes S to L" using the order of the values. If part of the text matches nothing, leave it out. '
                . 'Answer with a JSON object that has a "choices" list; each choice has the "attribute" id and the list of "values" ids.',
            'messages' => [['role' => 'user', 'content' => (string) json_encode(['text' => $text, 'catalogue' => $catalog], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'schema' => [
                'type' => 'object',
                'properties' => ['choices' => ['type' => 'array', 'items' => [
                    'type' => 'object',
                    'properties' => ['attribute' => ['type' => 'integer'], 'values' => ['type' => 'array', 'items' => ['type' => 'integer']]],
                    'required' => ['attribute', 'values'],
                ]]],
                'required' => ['choices'],
            ],
            'max_tokens' => 600,
            'temperature' => 0,
        ];
        $result = $app->service('ai')->ask('catalog.variants_text', $request, 'economy');
        $json = is_object($result) && isset($result->json) && is_array($result->json) ? $result->json : [];

        $chosen = [];
        foreach (isset($json['choices']) && is_array($json['choices']) ? $json['choices'] : [] as $choice) {
            if (!is_array($choice) || !isset($choice['attribute'], $choice['values']) || !is_numeric($choice['attribute']) || !is_array($choice['values'])) {
                continue;
            }
            $attributeId = (int) $choice['attribute'];
            if (!isset($attributes[$attributeId])) {
                continue;
            }
            foreach ($choice['values'] as $valueId) {
                if (is_numeric($valueId) && isset($attributes[$attributeId]['values'][(int) $valueId])
                    && (!isset($chosen[$attributeId]) || !in_array((int) $valueId, $chosen[$attributeId], true))) {
                    $chosen[$attributeId][] = (int) $valueId;
                }
            }
        }

        return $chosen;
    }

    /**
     * Propone, a partir del nombre de un producto, una descripción corta y una de las categorías que ya existen.
     *
     * @param array<string,string> $categories id => nombre (con su ruta) de las categorías que hay
     *
     * @return array{summary:string,category_id:?int}
     *
     * @throws \Throwable si la IA falla
     */
    public static function fill(App $app, string $name, array $categories): array
    {
        $list = [];
        foreach ($categories as $id => $label) {
            $list[] = ['id' => (int) $id, 'name' => (string) $label];
        }
        $request = [
            'system' => 'You help a shop owner create a product. From the product name, write a short description in the language of the name: '
                . 'one or two sentences, plain text, at most 300 characters, with no invented figures or claims. '
                . 'Then choose the best category from the list by its id, or 0 if none fits. '
                . 'Answer with a JSON object that has a "summary" string and a "category_id" integer.',
            'messages' => [['role' => 'user', 'content' => (string) json_encode(['name' => $name, 'categories' => $list], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]],
            'schema' => [
                'type' => 'object',
                'properties' => ['summary' => ['type' => 'string'], 'category_id' => ['type' => 'integer']],
                'required' => ['summary'],
            ],
            'max_tokens' => 400,
        ];
        $result = $app->service('ai')->ask('catalog.product_fill', $request, 'economy');
        $json = is_object($result) && isset($result->json) && is_array($result->json) ? $result->json : [];

        $summary = isset($json['summary']) && is_string($json['summary']) ? trim(strip_tags($json['summary'])) : '';
        $summary = mb_substr($summary, 0, 300, 'UTF-8');
        $category = isset($json['category_id']) && is_numeric($json['category_id']) && isset($categories[(string) (int) $json['category_id']]) ? (int) $json['category_id'] : null;

        return ['summary' => $summary, 'category_id' => $category];
    }
}
