<?php
/**
 * AIFEC — pantalla de los valores de un atributo de lista: tabla editable con añadir, reordenar y borrar filas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Admin\Admin;
use Aifec\Admin\Form;
use Aifec\Kernel\App;
use Aifec\Kernel\HttpError;
use Aifec\Kernel\Request;
use Aifec\Kernel\Response;

/**
 * Los valores («Rojo», «Talla M») de un atributo select, multiselect o color: attribute_values (código, color, orden) y
 * attribute_value_i18n (etiqueta por idioma). Se guarda todo de una vez: el orden de las filas es la posición.
 * No se borra un valor que usa un producto o una variante: se dice por cuántos y no se guarda nada.
 */
final class AttributeValuesController
{
    private App $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    /** @param array<string,string> $params */
    public function show(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('catalog.view');
        $attribute = $this->attribute($params);

        return $this->screen($attribute, $this->currentRows((int) $attribute['id']), [], 200);
    }

    /** @param array<string,string> $params */
    public function save(Request $request, array $params): Response
    {
        $this->app->auth()->requireCap('catalog.edit');
        $attribute = $this->attribute($params);
        $attributeId = (int) $attribute['id'];
        $db = $this->app->db();
        $langs = $this->app->languages();
        $default = $this->app->defaultLang();
        $isColor = (string) $attribute['type'] === 'color';

        list($values, $errors) = $this->form($attribute)->validate($request);
        $submitted = isset($values['values']) && is_array($values['values']) ? $values['values'] : [];

        $existing = [];
        foreach ($db->select('SELECT id, code FROM {attribute_values} WHERE attribute_id = ?', [$attributeId]) as $row) {
            $existing[(int) $row['id']] = (string) $row['code'];
        }

        // Filas ya limpias, en el orden en que llegan.
        $clean = [];
        $codes = [];
        $kept = [];
        $problem = '';
        foreach (array_values($submitted) as $i => $row) {
            $n = $i + 1;
            $row = is_array($row) ? $row : [];
            $labels = [];
            foreach ($langs as $lang) {
                $labels[$lang] = isset($row['label_' . $lang]) ? trim((string) $row['label_' . $lang]) : '';
            }
            $code = isset($row['code']) ? trim((string) $row['code']) : '';
            $id = isset($row['id']) && $row['id'] !== null ? (int) $row['id'] : 0;
            if ($id > 0 && (!isset($existing[$id]) || isset($kept[$id]))) {
                $id = 0;
            }
            if ($code === '' && $id === 0 && implode('', $labels) === '') {
                continue; // fila vacía
            }
            if ($labels[$default] === '') {
                $problem = $problem !== '' ? $problem : t('Row {n}: {error}', ['n' => $n, 'error' => t('Write the label in the main language.')]);
                continue;
            }
            if ($code === '') {
                $code = TaxonomySlugs::make($labels[$default], 'value-' . $n);
            }
            if (isset($codes[$code])) {
                $problem = $problem !== '' ? $problem : t('Row {n}: {error}', ['n' => $n, 'error' => t('Another value already has this code.')]);
                continue;
            }
            $codes[$code] = true;
            if ($id > 0) {
                $kept[$id] = true;
            }
            $color = $isColor && isset($row['color']) ? strtolower(trim((string) $row['color'])) : '';
            $clean[] = ['id' => $id, 'code' => $code, 'color' => $color, 'labels' => $labels];
        }

        // Los que ya no están se borran, salvo que los use un producto o una variante.
        $removed = [];
        foreach ($existing as $valueId => $oldCode) {
            if (isset($kept[$valueId])) {
                continue;
            }
            $products = (int) $db->value('SELECT COUNT(DISTINCT product_id) FROM {product_attributes} WHERE value_id = ?', [$valueId]);
            $variants = (int) $db->value('SELECT COUNT(DISTINCT variant_id) FROM {variant_values} WHERE value_id = ?', [$valueId]);
            if ($products + $variants > 0 && $problem === '') {
                $label = $db->value('SELECT label FROM {attribute_value_i18n} WHERE value_id = ? AND lang = ?', [$valueId, $default]);
                $problem = t('The value «{label}» is in use: {products} products and {variants} variants. Remove it from them first.', [
                    'label' => $label !== null && (string) $label !== '' ? (string) $label : $oldCode,
                    'products' => $products,
                    'variants' => $variants,
                ]);
            }
            $removed[] = $valueId;
        }

        if ($errors !== [] || $problem !== '') {
            if ($problem !== '') {
                $errors['values'] = $problem;
            }

            return $this->screen($attribute, $submitted, $errors, 422);
        }

        $db->transaction(function () use ($db, $attributeId, $existing, $clean, $removed, $langs, $default): void {
            foreach ($removed as $valueId) {
                $db->delete('attribute_value_i18n', ['value_id' => $valueId]);
                $db->delete('attribute_values', ['id' => $valueId]);
            }
            // Un cambio de código entre dos filas (A pasa a ser B y B a ser A) choca con la clave única a mitad de camino:
            // primero se les da un código provisional.
            foreach ($clean as $item) {
                if ($item['id'] > 0 && $existing[$item['id']] !== $item['code']) {
                    $db->update('attribute_values', ['code' => '~' . $item['id']], ['id' => $item['id']]);
                }
            }
            foreach ($clean as $position => $item) {
                $data = ['code' => $item['code'], 'color' => $item['color'] !== '' ? $item['color'] : null, 'position' => $position];
                if ($item['id'] > 0) {
                    $valueId = $item['id'];
                    $db->update('attribute_values', $data, ['id' => $valueId]);
                } else {
                    $valueId = $db->insert('attribute_values', ['attribute_id' => $attributeId] + $data);
                }
                foreach ($langs as $lang) {
                    $label = $item['labels'][$lang];
                    if ($label === '' && $lang !== $default) {
                        $db->delete('attribute_value_i18n', ['value_id' => $valueId, 'lang' => $lang]);
                    } elseif ($label !== '') {
                        $db->upsert('attribute_value_i18n', ['value_id' => $valueId, 'lang' => $lang, 'label' => $label], ['value_id', 'lang']);
                    }
                }
            }
        });

        Admin::audit($this->app, 'update', 'attribute_values', (string) $attributeId, $this->name($attribute), [
            'values' => [(string) count($existing), (string) count($clean)],
        ]);
        $this->app->invalidate(['catalog', 'attributes', 'attribute_values']);
        Admin::flash($this->app, 'ok', t('Values saved'));

        return Response::redirect(AttributeAdmin::valuesUrl($this->app, $attributeId));
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @param array<string,string> $params
     *
     * @return array<string,mixed>
     */
    private function attribute(array $params): array
    {
        $row = $this->app->db()->find('attributes', ['id' => isset($params['id']) ? (int) $params['id'] : 0]);
        if ($row === null || !in_array((string) $row['type'], AttributeAdmin::LIST_TYPES, true)) {
            throw HttpError::notFound();
        }

        return $row;
    }

    /**
     * @param array<string,mixed> $attribute
     */
    private function name(array $attribute): string
    {
        $name = $this->app->db()->value('SELECT name FROM {attribute_i18n} WHERE attribute_id = ? AND lang = ?', [(int) $attribute['id'], $this->app->defaultLang()]);

        return $name !== null && (string) $name !== '' ? (string) $name : (string) $attribute['code'];
    }

    /**
     * @param array<string,mixed> $attribute
     */
    private function form(array $attribute): Form
    {
        $langs = $this->app->languages();
        $sub = ['id' => ['type' => 'hidden', 'rules' => 'int']];
        foreach ($langs as $lang) {
            $sub['label_' . $lang] = ['label' => count($langs) > 1 ? t('Label') . ' (' . strtoupper($lang) . ')' : 'Label', 'rules' => 'max:120'];
        }
        $sub['code'] = ['label' => 'Code', 'rules' => 'slug|max:64', 'placeholder' => 'Optional: it is taken from the label'];
        if ((string) $attribute['type'] === 'color') {
            $sub['color'] = ['type' => 'color', 'label' => 'Colour'];
        }

        return new Form($this->app, ['fields' => [
            'values' => ['type' => 'repeater', 'label' => 'Values', 'add' => 'Add a value', 'fields' => $sub],
        ]]);
    }

    /**
     * Los valores guardados, en el orden del atributo y con sus etiquetas, como filas del formulario.
     *
     * @return array<int,array<string,mixed>>
     */
    private function currentRows(int $attributeId): array
    {
        $db = $this->app->db();
        $rows = [];
        $ids = [];
        foreach ($db->select('SELECT id, code, color FROM {attribute_values} WHERE attribute_id = ? ORDER BY position, id', [$attributeId]) as $row) {
            $ids[] = (int) $row['id'];
            $rows[(int) $row['id']] = ['id' => (int) $row['id'], 'code' => (string) $row['code'], 'color' => isset($row['color']) ? (string) $row['color'] : ''];
        }
        if ($ids !== []) {
            $params = [];
            $marks = $db->in($ids, $params);
            foreach ($db->select('SELECT value_id, lang, label FROM {attribute_value_i18n} WHERE value_id IN (' . $marks . ')', $params) as $label) {
                $rows[(int) $label['value_id']]['label_' . (string) $label['lang']] = (string) $label['label'];
            }
        }

        return array_values($rows);
    }

    /**
     * @param array<string,mixed>             $attribute
     * @param array<int,array<string,mixed>>  $rows
     * @param array<string,string>            $errors
     */
    private function screen(array $attribute, array $rows, array $errors, int $status): Response
    {
        $id = (int) $attribute['id'];
        $name = $this->name($attribute);
        $canEdit = $this->app->auth()->can('catalog.edit');
        $html = $canEdit ? $this->form($attribute)->render(['values' => $rows], $errors, [
            'action' => AttributeAdmin::valuesUrl($this->app, $id),
            'cancel' => Admin::resourceUrl($this->app, 'attributes', $id),
        ]) : '';

        return Admin::page($this->app, 'catalog::admin/attribute_values', [
            'form' => $html,
            'canEdit' => $canEdit,
            'rows' => $rows,
            'lang' => $this->app->defaultLang(),
            'isColor' => (string) $attribute['type'] === 'color',
        ], [
            'title' => t('Values: {name}', ['name' => $name]),
            'crumbs' => [
                [t('Attributes'), Admin::resourceUrl($this->app, 'attributes')],
                [$name, Admin::resourceUrl($this->app, 'attributes', $id)],
            ],
            'active' => '/admin/r/attributes',
            'help' => Admin::helpText("Each row is a choice the merchant can pick in a product, for example Red or Size M.\nDrag the handle to change the order. The label of the main language is required.\nA value that a product or a variant uses cannot be deleted."),
        ], $status);
    }
}
