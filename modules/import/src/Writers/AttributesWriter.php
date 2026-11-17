<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Writers;

use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\WriteContext;

/**
 * Los atributos y las características del origen con sus valores. Un atributo se casa por su código y, si no lo tiene, por su nombre; un
 * valor, por su etiqueta. Solo los de lista (opción y color) llevan valores; solo ellos pueden servir para crear variantes.
 *
 * El identificador de un valor solo es único dentro de su atributo: la pareja se guarda con WriterBase::valueKey().
 */
final class AttributesWriter extends WriterBase
{
    public function type(): string
    {
        return 'attributes';
    }

    protected function store(array $record, WriteContext $ctx): array
    {
        $db = $this->db();
        $id = (string) $record['id'];
        $found = $this->resolve('attributes', $record, $ctx, function (int $localId) use ($db): ?array {
            return $db->find('attributes', ['id' => $localId]);
        }, function () use ($record, $ctx, $id): ?array {
            return $this->existing($record, $ctx, $id);
        });
        $row = $found['row'];
        $action = $found['action'];
        if ($action === self::UNCHANGED) {
            return $this->result(self::UNCHANGED, (int) $row['id']);
        }
        if ($ctx->dryRun()) {
            return $this->result($action, $row !== null ? (int) $row['id'] : null);
        }
        $type = (string) $record['type'];
        $list = in_array($type, ['select', 'color'], true);
        $variant = isset($record['variant']) && $record['variant'] === true;
        if ($variant && !$list) {
            $variant = false;
            $ctx->note('attributes', $id, 'note', 'Only choice lists and colours can make variants: this attribute is not used for them here');
        }
        $unit = isset($record['unit']) && is_string($record['unit']) ? trim($record['unit']) : '';
        $data = [
            'type' => $type, 'unit' => $unit !== '' ? $unit : null, 'filterable' => isset($record['filterable']) && $record['filterable'] === false ? 0 : 1,
            'visible' => isset($record['visible']) && $record['visible'] === false ? 0 : 1, 'variant' => $variant ? 1 : 0,
            'position' => isset($record['position']) ? (int) $record['position'] : 0,
        ];
        $names = $this->texts(isset($record['name']) && is_array($record['name']) ? $record['name'] : [], $ctx);
        if ($row === null) {
            $codes = array_map('strval', $db->column('SELECT code FROM {attributes}'));
            $main = $this->app->defaultLang();
            $source = isset($record['code']) && is_string($record['code']) && $record['code'] !== '' ? $record['code'] : (isset($names[$main]) ? $names[$main] : $id);
            $localId = $db->insert('attributes', $data + ['code' => $this->code($source, 'attribute', 64, $codes)]);
        } else {
            $localId = (int) $row['id'];
            $db->update('attributes', $data, ['id' => $localId]);
        }
        foreach ($names as $lang => $name) {
            $this->upsert('attribute_i18n', ['attribute_id' => $localId, 'lang' => $lang], ['name' => trim($name)]);
        }
        if ($list) {
            foreach (isset($record['values']) && is_array($record['values']) ? $record['values'] : [] as $value) {
                if (is_array($value)) {
                    $this->value($localId, $id, $value, $ctx);
                }
            }
        }
        $ctx->remember('attributes', $id, $localId, Neutral::hash($record));
        $this->app->invalidate(['catalog', 'attributes', 'attribute_values']);

        return $this->result($row === null ? self::CREATED : $action, $localId);
    }

    /**
     * El atributo de aquí que ya es este: el del mismo código o, si el origen no lo da, el del mismo nombre.
     *
     * @param array<string,mixed> $record
     *
     * @return array<string,mixed>|null
     */
    private function existing(array $record, WriteContext $ctx, string $id): ?array
    {
        $db = $this->db();
        $code = isset($record['code']) && is_string($record['code']) ? trim($record['code']) : '';
        if ($code !== '') {
            $params = [];
            $row = $db->row('SELECT * FROM {attributes} WHERE ' . $this->sameText('code', $code, $params), $params);
            if ($row !== null && !$this->claimed('attributes', (int) $row['id'], $id)) {
                return $row;
            }
        }
        $name = trim($this->mainText(isset($record['name']) && is_array($record['name']) ? $record['name'] : [], $ctx));
        if ($name === '') {
            return null;
        }
        $params = [$this->app->defaultLang()];
        $rows = $db->select(
            'SELECT a.* FROM {attributes} a JOIN {attribute_i18n} i ON i.attribute_id = a.id AND i.lang = ? WHERE ' . $this->sameText('i.name', $name, $params) . ' ORDER BY a.id',
            $params
        );
        foreach ($rows as $row) {
            if (!$this->claimed('attributes', (int) $row['id'], $id)) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Un valor de la lista: el de la pareja guardada, el que tiene la misma etiqueta o uno nuevo.
     *
     * @param array<string,mixed> $value
     */
    private function value(int $attributeId, string $attributeSource, array $value, WriteContext $ctx): void
    {
        $db = $this->db();
        $valueSource = (string) $value['id'];
        $key = self::valueKey($attributeSource, $valueSource);
        $labels = $this->texts(isset($value['label']) && is_array($value['label']) ? $value['label'] : [], $ctx);
        $main = $this->app->defaultLang();
        $label = isset($labels[$main]) ? $labels[$main] : '';
        $localId = $ctx->localId('attribute_values', $key);
        $row = $localId !== null ? $db->row('SELECT * FROM {attribute_values} WHERE id = ? AND attribute_id = ?', [$localId, $attributeId]) : null;
        if ($row === null && $label !== '') {
            $params = [$main, $attributeId];
            $candidates = $db->select(
                'SELECT v.* FROM {attribute_values} v JOIN {attribute_value_i18n} i ON i.value_id = v.id AND i.lang = ? WHERE v.attribute_id = ? AND ' . $this->sameText('i.label', $label, $params) . ' ORDER BY v.id',
                $params
            );
            foreach ($candidates as $candidate) {
                if (!$this->claimed('attribute_values', (int) $candidate['id'], $key)) {
                    $row = $candidate;
                    break;
                }
            }
        }
        $color = isset($value['color']) && is_string($value['color']) && preg_match('/^#[0-9a-fA-F]{6}$/D', $value['color']) === 1 ? strtolower($value['color']) : null;
        $data = ['color' => $color, 'position' => isset($value['position']) ? (int) $value['position'] : 0];
        if ($row === null) {
            $codes = array_map('strval', $db->column('SELECT code FROM {attribute_values} WHERE attribute_id = ?', [$attributeId]));
            $data['code'] = $this->code($label !== '' ? $label : $valueSource, 'value', 64, $codes);
            $data['attribute_id'] = $attributeId;
            $localId = $db->insert('attribute_values', $data);
        } else {
            $localId = (int) $row['id'];
            $db->update('attribute_values', $data, ['id' => $localId]);
        }
        foreach ($labels as $lang => $text) {
            $this->upsert('attribute_value_i18n', ['value_id' => $localId, 'lang' => $lang], ['label' => trim($text)]);
        }
        $ctx->remember('attribute_values', $key, $localId, Neutral::hash($value));
    }

    /**
     * Inserta o actualiza una fila por su clave natural.
     *
     * @param array<string,mixed> $key
     * @param array<string,mixed> $data
     */
    private function upsert(string $table, array $key, array $data): void
    {
        $db = $this->db();
        if ($db->find($table, $key) === null) {
            $db->insert($table, $key + $data);
        } else {
            $db->update($table, $data, $key);
        }
    }
}
