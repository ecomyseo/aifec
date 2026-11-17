<?php
/**
 * AIFEC — atributos en el panel: listado, formulario, reglas de código y variantes, y borrado seguro.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Admin\Admin;
use Aifec\Kernel\App;

/**
 * Piezas del recurso «attributes» de admin.php. El nombre vive en attribute_i18n; los valores de un atributo de lista se
 * editan en su pantalla propia (AttributeValuesController).
 *
 * Un atributo es «de lista» cuando tiene valores propios: select, multiselect y color. «Sirve para variantes» solo lo pueden ser
 * los de lista que de verdad se elijan de uno en uno: select y color.
 */
final class AttributeAdmin
{
    /** Tipos con lista de valores. */
    public const LIST_TYPES = ['select', 'multiselect', 'color'];

    /** Tipos que pueden crear variantes. */
    public const VARIANT_TYPES = ['select', 'color'];

    /**
     * @return array<string,string> tipo => texto
     */
    public static function typeOptions(): array
    {
        return [
            'select' => 'Choice list',
            'multiselect' => 'Multiple choice list',
            'text' => 'Text',
            'number' => 'Number',
            'bool' => 'Yes or no',
            'color' => 'Colour',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        return [
            'columns' => [
                'name' => ['label' => 'Name', 'link' => true, 'sort' => true],
                'code' => ['label' => 'Code', 'sort' => true, 'hide_sm' => true],
                'type' => ['label' => 'Type', 'type' => 'badge', 'map' => [
                    'select' => ['Choice list', 'info'], 'multiselect' => ['Multiple choice list', 'info'], 'text' => ['Text', ''],
                    'number' => ['Number', ''], 'bool' => ['Yes or no', ''], 'color' => ['Colour', 'info'],
                ]],
                'values' => ['label' => 'Values', 'type' => 'int', 'hide_sm' => true, 'render' => static function (array $row, App $app): string {
                    if (!in_array((string) $row['type'], self::LIST_TYPES, true)) {
                        return '<span class="af-muted">—</span>';
                    }
                    $count = (int) $app->db()->value('SELECT COUNT(*) FROM {attribute_values} WHERE attribute_id = ?', [(int) $row['id']]);

                    return '<a href="' . e(self::valuesUrl($app, (int) $row['id'])) . '">' . e((string) $count) . '</a>';
                }],
                'filterable' => ['label' => 'Used to filter', 'type' => 'bool', 'hide_sm' => true],
                'variant' => ['label' => 'Used for variants', 'type' => 'bool', 'hide_sm' => true],
                'position' => ['label' => 'Order', 'type' => 'int', 'sort' => true, 'hide_sm' => true],
            ],
            'search' => ['name', 'code'],
            'filters' => ['type' => ['label' => 'Type', 'options' => self::typeOptions()]],
            'sort' => ['position', 'asc'],
            'per_page' => 50,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id): array
    {
        $used = $id !== null && self::usage($app, $id) > 0;
        $codeHint = $used ? t('This attribute is used by products: its code cannot change.') : t('Short name without spaces, used in filters and imports.');

        return [
            'fields' => [
                'code' => ['type' => 'slug', 'code' => true, 'label' => 'Code', 'rules' => 'required|max:64|unique:attributes,code', 'width' => 'md', 'from' => 'name',
                    'attrs' => $used ? ['readonly' => true] : [], 'hint' => $codeHint],
                'name' => ['label' => 'Name', 'i18n' => true, 'rules' => 'required|max:120'],
                'type' => ['type' => 'select', 'label' => 'Type', 'options' => self::typeOptions(), 'default' => 'select', 'rules' => 'required'],
                'unit' => ['label' => 'Unit', 'rules' => 'max:16', 'width' => 'sm', 'hint' => 'For example kg or cm. It is shown after the value.'],
                'filterable' => ['type' => 'toggle', 'label' => 'Used to filter products', 'default' => 1],
                'visible' => ['type' => 'toggle', 'label' => 'Shown on the product page', 'default' => 1],
                'variant' => ['type' => 'toggle', 'label' => 'Used to make variants', 'default' => 0, 'hint' => 'Only choice lists and colours can make variants.'],
                'position' => ['type' => 'number', 'label' => 'Order', 'default' => 0, 'rules' => 'required|min:0', 'width' => 'sm'],
            ],
        ];
    }

    /**
     * Botones de la cabecera de la ficha: la pantalla de valores, si el atributo es de lista.
     *
     * @param array<string,mixed>|null $row
     */
    public static function actions(?array $row, App $app): string
    {
        if ($row === null || !in_array((string) $row['type'], self::LIST_TYPES, true)) {
            return '';
        }

        return '<a class="af-btn" href="' . e(self::valuesUrl($app, (int) $row['id'])) . '">' . Admin::icon('list') . e(t('Values')) . '</a>';
    }

    public static function valuesUrl(App $app, int $id): string
    {
        return $app->url()->plain('/admin/catalog/attributes/' . $id . '/values');
    }

    /**
     * El código no cambia si ya hay valores en productos, y «variantes» solo vale en los tipos que las admiten.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function beforeSave(array $values, ?array $current, App $app): array
    {
        $errors = [];
        if ($current !== null && isset($values['code']) && (string) $values['code'] !== (string) $current['code'] && self::usage($app, (int) $current['id']) > 0) {
            $errors['code'] = t('This attribute is used by products: its code cannot change.');
        }
        $type = isset($values['type']) ? (string) $values['type'] : 'select';
        if (!empty($values['variant']) && !in_array($type, self::VARIANT_TYPES, true)) {
            $errors['variant'] = t('Only choice lists and colours can make variants.');
        }
        if ($errors !== []) {
            return ['_errors' => $errors];
        }
        if (isset($values['unit']) && trim((string) $values['unit']) === '') {
            $values['unit'] = null;
        }

        return $values;
    }

    /**
     * Una traducción sin nombre no es una traducción: se quita.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        $default = $app->defaultLang();
        foreach ($app->languages() as $lang) {
            $name = isset($values['name'][$lang]) && is_string($values['name'][$lang]) ? trim($values['name'][$lang]) : '';
            if ($lang !== $default && $name === '') {
                $app->db()->delete('attribute_i18n', ['attribute_id' => $id, 'lang' => $lang]);
            }
        }
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return string|null el motivo por el que no se puede borrar, o null
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        $used = self::usage($app, (int) $row['id']);
        if ($used > 0) {
            return tn('This attribute is used by {n} product or variant. Remove it from there first.', 'This attribute is used by {n} products or variants. Remove it from there first.', $used);
        }

        return null;
    }

    /**
     * Se llevan con ella sus valores y su sitio en los tipos de producto.
     *
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        $db = $app->db();
        $id = (int) $row['id'];
        $db->run('DELETE FROM {attribute_value_i18n} WHERE value_id IN (SELECT id FROM {attribute_values} WHERE attribute_id = ?)', [$id]);
        $db->delete('attribute_values', ['attribute_id' => $id]);
        $db->delete('family_attributes', ['attribute_id' => $id]);
    }

    /**
     * Cuántos productos y variantes usan el atributo.
     */
    public static function usage(App $app, int $id): int
    {
        $db = $app->db();
        $products = (int) $db->value('SELECT COUNT(DISTINCT product_id) FROM {product_attributes} WHERE attribute_id = ?', [$id]);
        $variants = (int) $db->value('SELECT COUNT(DISTINCT variant_id) FROM {variant_values} WHERE attribute_id = ?', [$id]);

        return $products + $variants;
    }
}
