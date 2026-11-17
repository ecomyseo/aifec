<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Tax;

use Aifec\Db\Db;
use Aifec\Geo\Countries;
use Aifec\Kernel\App;

/**
 * Piezas del panel de impuestos: listados, formularios, validación y efectos de guardar y borrar de las clases y los tipos.
 * Sus definiciones son las que pintan Aifec\Admin\Table y Aifec\Admin\Form.
 */
final class TaxAdmin
{
    // ------------------------------------------------------------------ clases

    /**
     * @return array<string,mixed>
     */
    public static function classListConfig(): array
    {
        return [
            'from' => '{tax_classes} t',
            'select' => 't.*, (SELECT COUNT(*) FROM {tax_rates} r WHERE r.tax_class_id = t.id) AS rates_count',
            'key' => 'id',
            'key_sql' => 't.id',
            'columns' => [
                'name' => ['label' => 'Name', 'link' => true, 'sort' => 't.name', 'translate' => true],
                'code' => ['label' => 'Code', 'sort' => 't.code', 'render' => static function (array $row, App $app): string {
                    return '<span class="af-mono">' . e((string) $row['code']) . '</span>';
                }],
                'is_default' => ['label' => 'Default class', 'type' => 'bool'],
                'rates_count' => ['label' => 'Rates', 'type' => 'int', 'hide_sm' => true],
                'position' => ['label' => 'Order', 'type' => 'int', 'sort' => 't.position', 'hide_sm' => true],
            ],
            'search' => ['t.name', 't.code'],
            'sort' => ['t.position', 'asc'],
            'per_page' => 50,
        ];
    }

    /**
     * Formulario de una clase. El código de una clase en uso no se puede cambiar.
     *
     * @return array<string,mixed>
     */
    public static function classForm(App $app, ?int $id): array
    {
        $code = ['type' => 'slug', 'code' => true, 'label' => 'Code', 'rules' => 'required|max:32|unique:tax_classes,code', 'width' => 'md'];
        if ($id === null) {
            $code['from'] = 'name';
        } elseif (self::classInUse($app, $id)) {
            $code['attrs'] = ['readonly' => true];
            $code['hint'] = 'This class is in use: its code cannot be changed.';
        }

        return [
            'fields' => [
                'name' => ['label' => 'Name', 'rules' => 'required|max:80'],
                'code' => $code,
                'is_default' => ['type' => 'toggle', 'label' => 'Default class', 'default' => 0, 'hint' => 'Products without a class use this one. Only one class can be the default.'],
                'position' => ['type' => 'number', 'label' => 'Order', 'default' => 0, 'rules' => 'required|min:0', 'width' => 'sm'],
            ],
        ];
    }

    /**
     * El código de una clase en uso no cambia, y la clase por defecto no se deja sin marcar.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function classBeforeSave(array $values, ?array $current, App $app): array
    {
        $errors = [];
        if ($current !== null) {
            if (isset($values['code']) && (string) $values['code'] !== (string) $current['code'] && self::classInUse($app, (int) $current['id'])) {
                $errors['code'] = t('This class is in use: its code cannot be changed.');
            }
            if ((int) $current['is_default'] === 1 && empty($values['is_default'])) {
                $errors['is_default'] = t('There must always be a default class. Make another class the default instead.');
            }
        }

        return $errors !== [] ? ['_errors' => $errors] : $values;
    }

    /**
     * Solo hay una clase por defecto: la última que se marca.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function classAfterSave(int $id, array $values, ?array $current, App $app): void
    {
        if (!empty($values['is_default'])) {
            $app->db()->run('UPDATE {tax_classes} SET is_default = 0 WHERE id <> ?', [$id]);
        }
    }

    /**
     * No se borra la clase por defecto, ni una con tipos, ni una que usen productos o métodos de envío.
     *
     * @param array<string,mixed> $row
     */
    public static function classBeforeDelete(array $row, App $app): ?string
    {
        $id = (int) $row['id'];
        if ((int) $row['is_default'] === 1) {
            return t('The default class cannot be deleted.');
        }
        if (self::rateCount($app, $id) > 0) {
            return t('This class has tax rates. Delete them first.');
        }
        if (self::usedByRecords($app, $id)) {
            return t('Products or shipping methods use this class. Move them to another class first.');
        }

        return null;
    }

    /** ¿Tiene tipos o la usa algún producto o método de envío? */
    public static function classInUse(App $app, int $id): bool
    {
        return self::rateCount($app, $id) > 0 || self::usedByRecords($app, $id);
    }

    // ------------------------------------------------------------------ tipos

    /**
     * @return array<string,mixed>
     */
    public static function rateListConfig(): array
    {
        return [
            'from' => '{tax_rates} t LEFT JOIN {tax_classes} c ON c.id = t.tax_class_id',
            'select' => 't.*, c.name AS class_name',
            'key' => 'id',
            'key_sql' => 't.id',
            'columns' => [
                'class_name' => ['label' => 'Class', 'sort' => 'c.name', 'translate' => true],
                'country' => ['label' => 'Country', 'sort' => 't.country', 'render' => static function (array $row, App $app): string {
                    return self::countryCell($row, $app);
                }],
                'postcodes' => ['label' => 'Postcodes', 'hide_sm' => true, 'render' => static function (array $row, App $app): string {
                    return self::postcodesCell($row, $app);
                }],
                'rate' => ['label' => 'Rate', 'sort' => 't.rate', 'render' => static function (array $row, App $app): string {
                    return self::rateCell($row, $app);
                }],
                'name' => ['label' => 'Name', 'link' => true, 'sort' => 't.name'],
                'priority' => ['label' => 'Priority', 'type' => 'int', 'sort' => 't.priority', 'hide_sm' => true],
                'active' => ['label' => 'Active', 'type' => 'bool'],
            ],
            'search' => ['t.name', 't.postcodes', 't.region'],
            'filters' => [
                'class' => ['label' => 'Class', 'column' => 't.tax_class_id', 'options' => static function (App $app): array {
                    $options = [];
                    foreach ($app->db()->select('SELECT id, name FROM {tax_classes} ORDER BY position, id') as $row) {
                        $options[(string) $row['id']] = (string) $row['name'];
                    }

                    return $options;
                }],
                'country' => ['label' => 'Country', 'column' => 't.country', 'raw' => true, 'options' => static function (App $app): array {
                    $options = [];
                    foreach ($app->db()->select('SELECT DISTINCT country FROM {tax_rates} ORDER BY country') as $row) {
                        $iso = (string) $row['country'];
                        $options[$iso] = self::countryLabel($app, $iso);
                    }

                    return $options;
                }],
            ],
            'sort' => ['t.id', 'asc'],
            'per_page' => 50,
        ];
    }

    /**
     * Países para el formulario de un tipo: ['ES' => 'España', …].
     *
     * @return array<string,string>
     */
    public static function countryOptions(App $app): array
    {
        return Countries::all($app->i18n()->locale());
    }

    /**
     * Normaliza y valida lo que el formulario ya ha leído: país en mayúsculas, región recortada y códigos postales válidos.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function rateBeforeSave(array $values, ?array $current, App $app): array
    {
        $postcodes = Postcodes::normalize(isset($values['postcodes']) && is_scalar($values['postcodes']) ? (string) $values['postcodes'] : '');
        if ($postcodes === null) {
            return ['_errors' => ['postcodes' => t('Use prefixes or ranges separated by commas, for example 35,38 or 51000-51999.')]];
        }
        $values['postcodes'] = $postcodes;
        $values['country'] = strtoupper(trim(isset($values['country']) && is_scalar($values['country']) ? (string) $values['country'] : ''));
        $values['region'] = trim(isset($values['region']) && is_scalar($values['region']) ? (string) $values['region'] : '');

        return $values;
    }

    /** @param array<string,mixed> $row */
    public static function rateCell(array $row, App $app): string
    {
        return e(self::percent($app, (int) $row['rate']));
    }

    /** @param array<string,mixed> $row */
    public static function countryCell(array $row, App $app): string
    {
        $text = self::countryLabel($app, (string) $row['country']);
        $region = trim((string) $row['region']);

        return e($text) . ($region !== '' ? ' <span class="af-muted af-small">' . e($region) . '</span>' : '');
    }

    /** @param array<string,mixed> $row */
    public static function postcodesCell(array $row, App $app): string
    {
        $list = trim((string) $row['postcodes']);

        return $list !== '' ? '<span class="af-mono">' . e($list) . '</span>' : '<span class="af-muted">—</span>';
    }

    /** Un tipo en centésimas como se lee: 2100 → «21 %», 1050 → «10,5 %», 725 → «7,25 %». */
    public static function percent(App $app, int $rate): string
    {
        $decimals = $rate % 100 === 0 ? 0 : ($rate % 10 === 0 ? 1 : 2);

        return $app->i18n()->number($rate / 100, $decimals) . ' %';
    }

    // ------------------------------------------------------------------ piezas

    private static function countryLabel(App $app, string $iso): string
    {
        $name = Countries::name($iso, $app->i18n()->locale());

        return $name !== '' ? $iso . ' · ' . $name : $iso;
    }

    private static function rateCount(App $app, int $classId): int
    {
        return (int) $app->db()->value('SELECT COUNT(*) FROM {tax_rates} WHERE tax_class_id = ?', [$classId]);
    }

    /** ¿Algún producto o método de envío usa la clase? Solo se mira si esas tablas existen y traen la columna. */
    private static function usedByRecords(App $app, int $classId): bool
    {
        $db = $app->db();
        if (self::hasClassColumn($db, 'products') && $db->value('SELECT id FROM {products} WHERE tax_class_id = ?', [$classId]) !== null) {
            return true;
        }

        return self::hasClassColumn($db, 'carriers') && $db->value('SELECT id FROM {carriers} WHERE tax_class_id = ?', [$classId]) !== null;
    }

    private static function hasClassColumn(Db $db, string $table): bool
    {
        return $db->tableExists($table) && in_array('tax_class_id', $db->columns($table), true);
    }
}
