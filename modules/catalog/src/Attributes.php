<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog;

use Aifec\Kernel\App;

/**
 * Los atributos y sus valores, con sus nombres en el idioma pedido. Se leen de una vez y se guardan en la caché
 * (etiqueta «catalog»); a partir de ahí, convertir un identificador en un texto legible no cuesta ninguna consulta.
 */
final class Attributes
{
    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /**
     * Todos los atributos, en el orden de la tienda: id => ['id','code','type','unit','filterable','visible','variant',
     * 'position','name','values' => [id => ['id','code','color','position','label']],'by_code' => [código => id del valor]].
     *
     * @return array<int,array<string,mixed>>
     */
    public function all(string $lang): array
    {
        $key = 'attrs:' . $lang;
        $hit = $this->app->cache()->get($key);
        if (is_array($hit)) {
            return $hit;
        }
        $db = $this->app->db();
        $default = $this->app->defaultLang();
        $names = [];
        foreach ($db->select('SELECT attribute_id, lang, name FROM {attribute_i18n}') as $row) {
            $names[(int) $row['attribute_id']][(string) $row['lang']] = ['name' => (string) $row['name']];
        }
        $labels = [];
        foreach ($db->select('SELECT value_id, lang, label FROM {attribute_value_i18n}') as $row) {
            $labels[(int) $row['value_id']][(string) $row['lang']] = ['label' => (string) $row['label']];
        }
        $attrs = [];
        foreach ($db->select('SELECT * FROM {attributes} ORDER BY position, id') as $row) {
            $id = (int) $row['id'];
            $picked = Texts::pick(isset($names[$id]) ? $names[$id] : [], $lang, $default);
            $attrs[$id] = [
                'id' => $id,
                'code' => (string) $row['code'],
                'type' => (string) $row['type'],
                'unit' => isset($row['unit']) ? (string) $row['unit'] : '',
                'filterable' => (int) $row['filterable'] === 1,
                'visible' => (int) $row['visible'] === 1,
                'variant' => (int) $row['variant'] === 1,
                'position' => (int) $row['position'],
                'name' => $picked !== null && $picked['name'] !== '' ? (string) $picked['name'] : (string) $row['code'],
                'values' => [],
                'by_code' => [],
            ];
        }
        foreach ($db->select('SELECT * FROM {attribute_values} ORDER BY position, id') as $row) {
            $attributeId = (int) $row['attribute_id'];
            if (!isset($attrs[$attributeId])) {
                continue;
            }
            $id = (int) $row['id'];
            $picked = Texts::pick(isset($labels[$id]) ? $labels[$id] : [], $lang, $default);
            $attrs[$attributeId]['values'][$id] = [
                'id' => $id,
                'code' => (string) $row['code'],
                'color' => isset($row['color']) && $row['color'] !== '' ? (string) $row['color'] : null,
                'position' => (int) $row['position'],
                'label' => $picked !== null && $picked['label'] !== '' ? (string) $picked['label'] : (string) $row['code'],
            ];
            $attrs[$attributeId]['by_code'][(string) $row['code']] = $id;
        }
        $this->app->cache()->set($key, $attrs, 3600, ['catalog']);

        return $attrs;
    }

    /**
     * El valor de un atributo de un producto como lo lee una persona. Devuelve '' si no hay nada que enseñar.
     *
     * Lista (select, color, multiselect): las etiquetas traducidas, separadas por comas. Número: con su unidad.
     * Sí/no: «Sí» o «No» en el idioma pedido. Texto: el del idioma pedido, si no el que sirve para todos, si no el principal.
     *
     * @param array<string,mixed>            $attr atributo de all()
     * @param array<int,array<string,mixed>> $rows filas de product_attributes de ese atributo y producto
     */
    public function readable(array $attr, array $rows, string $lang): string
    {
        $type = (string) $attr['type'];
        if ($type === 'select' || $type === 'color' || $type === 'multiselect') {
            $ids = [];
            foreach ($rows as $row) {
                if (isset($row['value_id']) && $row['value_id'] !== null) {
                    $ids[(int) $row['value_id']] = true;
                }
            }
            $labels = [];
            foreach ($attr['values'] as $valueId => $value) {
                if (isset($ids[(int) $valueId])) {
                    $labels[] = (string) $value['label'];
                }
            }

            return implode(', ', $labels);
        }
        if ($type === 'number') {
            foreach ($rows as $row) {
                if (!isset($row['value_number']) || $row['value_number'] === null || $row['value_number'] === '') {
                    continue;
                }
                $number = (float) $row['value_number'];
                $trimmed = Texts::trimNumber($number);
                $dot = strpos($trimmed, '.');
                $decimals = $dot === false ? 0 : strlen($trimmed) - $dot - 1;
                $text = $this->app->i18n()->number($number, $decimals, $lang);

                return $attr['unit'] !== '' ? $text . ' ' . $attr['unit'] : $text;
            }

            return '';
        }
        if ($type === 'bool') {
            foreach ($rows as $row) {
                if (isset($row['value_number']) && $row['value_number'] !== null && $row['value_number'] !== '') {
                    return (float) $row['value_number'] !== 0.0
                        ? $this->app->i18n()->t('Yes', [], $lang)
                        : $this->app->i18n()->t('No', [], $lang);
                }
            }

            return '';
        }

        // Texto libre: por idioma.
        $byLang = [];
        foreach ($rows as $row) {
            $text = isset($row['value_text']) ? trim((string) $row['value_text']) : '';
            if ($text === '') {
                continue;
            }
            $rowLang = isset($row['lang']) && $row['lang'] !== null && $row['lang'] !== '' ? (string) $row['lang'] : '*';
            if (!isset($byLang[$rowLang])) {
                $byLang[$rowLang] = $text;
            }
        }
        foreach ([$lang, '*', $this->app->defaultLang()] as $candidate) {
            if (isset($byLang[$candidate])) {
                return $byLang[$candidate];
            }
        }

        return '';
    }

    /**
     * El nombre de una variante: su etiqueta si la tiene; si no, los valores de sus atributos unidos con « / »;
     * si tampoco, su referencia o su número.
     *
     * @param array<string,mixed> $variant   fila de variants
     * @param array<int,int>      $valueMap  id del atributo => id del valor
     * @param array<int,array<string,mixed>> $attrs resultado de all()
     */
    public function variantLabel(array $variant, array $valueMap, array $attrs): string
    {
        $label = isset($variant['label']) ? trim((string) $variant['label']) : '';
        if ($label !== '') {
            return $label;
        }
        $parts = [];
        foreach ($attrs as $attributeId => $attr) {
            if (isset($valueMap[$attributeId], $attr['values'][$valueMap[$attributeId]])) {
                $parts[] = (string) $attr['values'][$valueMap[$attributeId]]['label'];
            }
        }
        if ($parts !== []) {
            return implode(' / ', $parts);
        }
        $sku = isset($variant['sku']) ? trim((string) $variant['sku']) : '';

        return $sku !== '' ? $sku : '#' . (int) $variant['id'];
    }
}
