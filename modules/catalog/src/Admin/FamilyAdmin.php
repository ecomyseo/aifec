<?php
/**
 * AIFEC — tipos de producto (familias) en el panel: nombre, orden y los atributos que los componen.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Admin;

use Aifec\Kernel\App;

/**
 * Piezas del recurso «families» de admin.php. Una familia dice qué atributos se piden al dar de alta un producto de ese tipo
 * (una silla no pide lo mismo que un programa). Los atributos son casillas; su orden en family_attributes.position es el que
 * ya tenían en la familia, y uno recién marcado se pone al final.
 */
final class FamilyAdmin
{
    /**
     * @return array<string,mixed>
     */
    public static function listConfig(App $app): array
    {
        return [
            'from' => '{families} f',
            'select' => 'f.*, (SELECT COUNT(*) FROM {family_attributes} fa WHERE fa.family_id = f.id) AS attribute_total,'
                . ' (SELECT COUNT(*) FROM {products} p WHERE p.family_id = f.id) AS product_total',
            'key' => 'id',
            'key_sql' => 'f.id',
            'columns' => [
                'name' => ['label' => 'Name', 'link' => true, 'sort' => 'f.name'],
                'attribute_total' => ['label' => 'Attributes', 'type' => 'int'],
                'product_total' => ['label' => 'Products', 'type' => 'int', 'hide_sm' => true],
                'position' => ['label' => 'Order', 'type' => 'int', 'sort' => 'f.position', 'hide_sm' => true],
            ],
            'search' => ['f.name'],
            'sort' => ['f.position', 'asc'],
            'per_page' => 50,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function formDefinition(App $app, ?int $id): array
    {
        $members = $id !== null ? self::members($app, $id) : [];

        return [
            'fields' => [
                'name' => ['label' => 'Name', 'rules' => 'required|max:120'],
                'position' => ['type' => 'number', 'label' => 'Order', 'default' => 0, 'rules' => 'required|min:0', 'width' => 'sm'],
                'attributes' => ['type' => 'checks', 'label' => 'Attributes', 'store' => false, 'translate' => false, 'wide' => true,
                    'options' => self::attributeOptions($app, $members), 'default' => array_map('strval', $members),
                    'hint' => 'A product of this type asks for the ticked attributes. A newly ticked one goes to the end.'],
            ],
        ];
    }

    /**
     * Escribe de nuevo los atributos de la familia: los que ya estaban conservan su orden, los nuevos van al final.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function afterSave(int $id, array $values, ?array $current, App $app): void
    {
        $db = $app->db();
        $ticked = [];
        foreach (isset($values['attributes']) && is_array($values['attributes']) ? $values['attributes'] : [] as $attributeId) {
            $ticked[(int) $attributeId] = true;
        }
        $ordered = [];
        foreach (self::members($app, $id) as $attributeId) {
            if (isset($ticked[$attributeId])) {
                $ordered[] = $attributeId;
                unset($ticked[$attributeId]);
            }
        }
        foreach (array_keys($ticked) as $attributeId) {
            $ordered[] = (int) $attributeId;
        }
        $db->transaction(static function () use ($db, $id, $ordered): void {
            $db->delete('family_attributes', ['family_id' => $id]);
            foreach ($ordered as $position => $attributeId) {
                $db->insert('family_attributes', ['family_id' => $id, 'attribute_id' => $attributeId, 'position' => $position]);
            }
        });
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return string|null el motivo por el que no se puede borrar, o null
     */
    public static function beforeDelete(array $row, App $app): ?string
    {
        $products = (int) $app->db()->value('SELECT COUNT(*) FROM {products} WHERE family_id = ?', [(int) $row['id']]);
        if ($products > 0) {
            return tn('This product type has {n} product. Move it to another type first.', 'This product type has {n} products. Move them to another type first.', $products);
        }

        return null;
    }

    /**
     * @param array<string,mixed> $row
     */
    public static function afterDelete(array $row, App $app): void
    {
        $app->db()->delete('family_attributes', ['family_id' => (int) $row['id']]);
    }

    /**
     * Identificadores de los atributos de una familia, en su orden.
     *
     * @return int[]
     */
    private static function members(App $app, int $id): array
    {
        return array_map('intval', $app->db()->column('SELECT attribute_id FROM {family_attributes} WHERE family_id = ? ORDER BY position, attribute_id', [$id]));
    }

    /**
     * Todos los atributos como casillas: primero los de la familia (en su orden) y después el resto (en el orden de los atributos).
     *
     * @param int[] $members
     *
     * @return array<string,string> id => «Nombre (código)»
     */
    private static function attributeOptions(App $app, array $members): array
    {
        $db = $app->db();
        $default = $app->defaultLang();
        $names = [];
        foreach ($db->select('SELECT attribute_id, lang, name FROM {attribute_i18n}') as $row) {
            $names[(int) $row['attribute_id']][(string) $row['lang']] = (string) $row['name'];
        }
        $label = [];
        $rank = [];
        foreach ($db->select('SELECT id, code FROM {attributes} ORDER BY position, id') as $row) {
            $id = (int) $row['id'];
            $name = isset($names[$id][$default]) && $names[$id][$default] !== '' ? $names[$id][$default] : (string) $row['code'];
            $label[$id] = $name . ' (' . (string) $row['code'] . ')';
            $rank[] = $id;
        }
        $options = [];
        foreach ($members as $id) {
            if (isset($label[$id])) {
                $options[(string) $id] = $label[$id];
            }
        }
        foreach ($rank as $id) {
            if (!isset($options[(string) $id])) {
                $options[(string) $id] = $label[$id];
            }
        }

        return $options;
    }
}
