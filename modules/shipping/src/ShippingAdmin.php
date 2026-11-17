<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Shipping;

use Aifec\Geo\Countries;
use Aifec\Kernel\App;
use Aifec\Modules\Tax\Postcodes;
use Aifec\Validation\Validator;

/**
 * Piezas del panel de envíos: listados, formularios, validación y efectos de guardar y borrar de las zonas y los transportistas.
 * Sus definiciones son las que pintan Aifec\Admin\Table y Aifec\Admin\Form.
 *
 * Las localizaciones de una zona y las tarifas de un transportista son filas de otras tablas: el formulario las pinta como un
 * «repeater» (sin guardarlas en la fila principal) y se escriben en after_save. Los límites de una tarifa se escriben como texto
 * porque su unidad depende de la base: gramos enteros para el peso, importes («4,95») para el importe.
 */
final class ShippingAdmin
{
    // ------------------------------------------------------------------ zonas

    /**
     * @return array<string,mixed>
     */
    public static function zoneListConfig(): array
    {
        return [
            'from' => '{shipping_zones} z',
            'select' => 'z.*, (SELECT COUNT(*) FROM {carrier_rates} r WHERE r.zone_id = z.id) AS rates_count',
            'key' => 'id',
            'key_sql' => 'z.id',
            'columns' => [
                'name' => ['label' => 'Name', 'link' => true, 'sort' => 'z.name', 'translate' => true],
                'locations' => ['label' => 'Where', 'render' => static function (array $row, App $app): string {
                    return self::locationsCell($row, $app);
                }],
                'rates_count' => ['label' => 'Rates', 'type' => 'int', 'hide_sm' => true],
                'position' => ['label' => 'Order', 'type' => 'int', 'sort' => 'z.position', 'hide_sm' => true],
                'active' => ['label' => 'Active', 'type' => 'bool'],
            ],
            'search' => ['z.name'],
            'sort' => ['z.position', 'asc'],
            'per_page' => 50,
        ];
    }

    /**
     * Formulario de una zona. Sus localizaciones viven en zone_locations.
     *
     * @return array<string,mixed>
     */
    public static function zoneForm(App $app, ?int $id): array
    {
        $country = self::defaultCountry($app);
        $rows = $id !== null ? self::zoneLocations($app, $id) : [['country' => $country, 'region' => '', 'postcodes' => '']];

        return [
            'fields' => [
                'name' => ['label' => 'Name', 'rules' => 'required|max:120'],
                'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1],
                'position' => ['type' => 'number', 'label' => 'Order', 'default' => 0, 'rules' => 'required|min:0', 'width' => 'sm',
                    'hint' => 'When two zones fit a destination equally, the one with the lower order wins.'],
                'locations' => ['type' => 'repeater', 'label' => 'Where it applies', 'store' => false, 'rules' => 'required', 'default' => $rows,
                    'add' => 'Add a place', 'hint' => 'The most specific place wins: postcodes, then region, then country, then the rest of the world.',
                    'fields' => [
                        'country' => ['type' => 'select', 'label' => 'Country', 'rules' => 'required', 'translate' => false, 'default' => $country,
                            'options' => static function (App $app): array {
                                return self::countryOptions($app);
                            }],
                        'region' => ['label' => 'Region', 'rules' => 'max:16'],
                        'postcodes' => ['label' => 'Postcodes', 'rules' => 'max:255', 'placeholder' => 'For example 35,38 or 51000-51999'],
                    ]],
            ],
        ];
    }

    /**
     * Normaliza las localizaciones: «resto del mundo» no lleva región ni códigos, y los códigos postales tienen que ser válidos.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function zoneBeforeSave(array $values, ?array $current, App $app): array
    {
        $clean = [];
        $rows = isset($values['locations']) && is_array($values['locations']) ? $values['locations'] : [];
        foreach (array_values($rows) as $i => $row) {
            $row = is_array($row) ? $row : [];
            $country = strtoupper(self::text($row, 'country'));
            $region = self::text($row, 'region');
            $postcodes = self::text($row, 'postcodes');
            if ($country === '*') {
                $region = '';
                $postcodes = '';
            } else {
                $normal = Postcodes::normalize($postcodes);
                if ($normal === null) {
                    return ['_errors' => ['locations' => t('Row {n}: {error}', ['n' => $i + 1, 'error' => t('Use prefixes or ranges separated by commas, for example 35,38 or 51000-51999.')])]];
                }
                $postcodes = $normal;
            }
            $clean[] = ['country' => $country, 'region' => $region, 'postcodes' => $postcodes];
        }
        $values['locations'] = $clean;

        return $values;
    }

    /**
     * Escribe de nuevo las localizaciones de la zona.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function zoneAfterSave(int $id, array $values, ?array $current, App $app): void
    {
        $db = $app->db();
        $rows = isset($values['locations']) && is_array($values['locations']) ? $values['locations'] : [];
        $db->transaction(static function () use ($db, $id, $rows): void {
            $db->delete('zone_locations', ['zone_id' => $id]);
            foreach ($rows as $row) {
                $db->insert('zone_locations', [
                    'zone_id' => $id,
                    'country' => (string) $row['country'],
                    'region' => (string) $row['region'],
                    'postcodes' => (string) $row['postcodes'],
                ]);
            }
        });
    }

    /**
     * No se borra una zona con tarifas.
     *
     * @param array<string,mixed> $row
     */
    public static function zoneBeforeDelete(array $row, App $app): ?string
    {
        if ((int) $app->db()->value('SELECT COUNT(*) FROM {carrier_rates} WHERE zone_id = ?', [(int) $row['id']]) > 0) {
            return t('This zone has carrier rates. Delete them first.');
        }

        return null;
    }

    /** @param array<string,mixed> $row */
    public static function zoneAfterDelete(array $row, App $app): void
    {
        $app->db()->delete('zone_locations', ['zone_id' => (int) $row['id']]);
    }

    /** @param array<string,mixed> $row */
    public static function locationsCell(array $row, App $app): string
    {
        $parts = [];
        foreach (self::zoneLocations($app, (int) $row['id']) as $location) {
            $label = $location['country'] === '*' ? t('Rest of the world') : $location['country'];
            $detail = $location['postcodes'] !== '' ? $location['postcodes'] : $location['region'];
            $parts[] = e($label) . ($detail !== '' ? ' <span class="af-mono af-small">' . e($detail) . '</span>' : '');
        }
        if ($parts === []) {
            return '<span class="af-muted">—</span>';
        }
        $shown = array_slice($parts, 0, 3);

        return implode('<br>', $shown) . (count($parts) > 3 ? '<br><span class="af-muted af-small">+' . (count($parts) - 3) . '</span>' : '');
    }

    /**
     * Países para el formulario de una zona: «resto del mundo» y todos los demás.
     *
     * @return array<string,string>
     */
    public static function countryOptions(App $app): array
    {
        return ['*' => t('Rest of the world')] + Countries::all($app->i18n()->locale());
    }

    // ------------------------------------------------------------------ transportistas

    /**
     * @return array<string,mixed>
     */
    public static function carrierListConfig(): array
    {
        return [
            'from' => '{carriers} c',
            'select' => 'c.*',
            'key' => 'id',
            'key_sql' => 'c.id',
            'columns' => [
                'name' => ['label' => 'Name', 'link' => true, 'sort' => 'c.name'],
                'code' => ['label' => 'Code', 'sort' => 'c.code', 'hide_sm' => true, 'render' => static function (array $row, App $app): string {
                    return '<span class="af-mono">' . e((string) $row['code']) . '</span>';
                }],
                'handler' => ['label' => 'Calculation', 'render' => static function (array $row, App $app): string {
                    return e(self::handlerLabel($app, (string) $row['handler']));
                }],
                'delay' => ['label' => 'Delivery time', 'hide_sm' => true, 'render' => static function (array $row, App $app): string {
                    return (string) $row['delay'] !== '' ? e((string) $row['delay']) : self::notStated($app, (int) $row['id']);
                }],
                'free_from' => ['label' => 'Free from', 'hide_sm' => true, 'render' => static function (array $row, App $app): string {
                    return $row['free_from'] !== null && $row['free_from'] !== '' ? e($app->i18n()->money((int) $row['free_from'], $app->settings()->str('shop.currency', 'EUR'))) : self::notStated($app, (int) $row['id']);
                }],
                'position' => ['label' => 'Order', 'type' => 'int', 'sort' => 'c.position', 'hide_sm' => true],
                'active' => ['label' => 'Active', 'type' => 'bool'],
            ],
            'search' => ['c.name', 'c.code'],
            'filters' => [
                'active' => ['label' => 'Status', 'column' => 'c.active', 'options' => ['1' => 'Active', '0' => 'Inactive']],
            ],
            'sort' => ['c.position', 'asc'],
            'per_page' => 50,
        ];
    }

    /**
     * Formulario de un transportista: pestaña «shmain» (datos) y «shrates» (tarifas, o precio y dirección si es recogida en tienda).
     *
     * @return array<string,mixed>
     */
    public static function carrierForm(App $app, ?int $id): array
    {
        $settings = [];
        $rates = [];
        if ($id !== null) {
            $row = $app->db()->row('SELECT settings FROM {carriers} WHERE id = ?', [$id]);
            $decoded = $row !== null && is_string($row['settings']) ? json_decode($row['settings'], true) : null;
            $settings = is_array($decoded) ? $decoded : [];
            $rates = self::carrierRates($app, $id);
        }
        $code = ['type' => 'slug', 'code' => true, 'label' => 'Code', 'rules' => 'required|max:40|unique:carriers,code', 'width' => 'md',
            'hint' => 'A short name without spaces, for example gls-24.'];
        if ($id === null) {
            $code['from'] = 'name';
        }

        return [
            'tabs' => ['shmain' => 'Carrier', 'shrates' => 'Rates'],
            'fields' => [
                'name' => ['label' => 'Name', 'rules' => 'required|max:120', 'tab' => 'shmain'],
                'code' => $code + ['tab' => 'shmain'],
                'handler' => ['type' => 'select', 'label' => 'Calculation', 'default' => 'rates', 'rules' => 'required', 'translate' => false, 'width' => 'md', 'tab' => 'shmain',
                    'hint' => 'A rate table, or pickup in store. Plugins can add more.',
                    'options' => static function (App $app): array {
                        return self::handlerOptions($app);
                    }],
                'delay' => ['label' => 'Delivery time', 'rules' => 'max:191', 'tab' => 'shmain', 'hint' => 'The text the customer sees, for example 24-48 h.'],
                'min_days' => ['type' => 'number', 'label' => 'Minimum days', 'rules' => 'min:0|max:365', 'width' => 'sm', 'tab' => 'shmain'],
                'max_days' => ['type' => 'number', 'label' => 'Maximum days', 'rules' => 'min:0|max:365', 'width' => 'sm', 'tab' => 'shmain'],
                'max_weight' => ['type' => 'number', 'label' => 'Maximum weight', 'default' => 0, 'rules' => 'required|min:0', 'suffix' => 'g', 'width' => 'sm', 'tab' => 'shmain',
                    'hint' => 'The heaviest package this carrier takes. 0 means no limit.'],
                'free_from' => ['type' => 'money', 'label' => 'Free from', 'rules' => 'min:0', 'tab' => 'shmain',
                    'hint' => 'Orders whose products reach this amount ship free. Leave it empty for never.'],
                'tax_class_id' => ['type' => 'select', 'label' => 'Tax class', 'translate' => true, 'null_empty' => true, 'empty' => 'As set in the tax settings', 'width' => 'md', 'tab' => 'shmain',
                    'options_from' => ['table' => 'tax_classes', 'value' => 'id', 'label' => 'name', 'order' => 'position, id']],
                'tracking_url' => ['label' => 'Tracking link', 'rules' => 'max:255', 'tab' => 'shmain',
                    'hint' => 'Use {code} where the tracking code goes, for example https://carrier.example/track/{code}.'],
                'media_id' => ['type' => 'image', 'label' => 'Logo', 'tab' => 'shmain'],
                'position' => ['type' => 'number', 'label' => 'Order', 'default' => 0, 'rules' => 'required|min:0', 'width' => 'sm', 'tab' => 'shmain'],
                'active' => ['type' => 'toggle', 'label' => 'Active', 'default' => 1, 'tab' => 'shmain'],

                'pickup_price' => ['type' => 'money', 'label' => 'Pickup price', 'store' => false, 'rules' => 'min:0', 'tab' => 'shrates', 'show_if' => ['handler' => 'pickup'],
                    'default' => isset($settings['price']) && is_numeric($settings['price']) ? (int) $settings['price'] : 0,
                    'hint' => 'Leave it at 0 for free pickup.'],
                'pickup_address' => ['type' => 'textarea', 'label' => 'Pickup address', 'store' => false, 'rules' => 'max:500', 'rows' => 3, 'tab' => 'shrates', 'show_if' => ['handler' => 'pickup'],
                    'default' => isset($settings['address']) && is_scalar($settings['address']) ? (string) $settings['address'] : '',
                    'hint' => 'Where and when the customer collects the order.'],
                'rates' => ['type' => 'repeater', 'label' => 'Rates', 'store' => false, 'tab' => 'shrates', 'show_if' => ['handler' => 'rates'], 'default' => $rates,
                    'add' => 'Add a rate',
                    'hint' => 'For each zone, the price of every weight or order amount range. A package that fits no range cannot be sent with this carrier.',
                    'fields' => [
                        'zone_id' => ['type' => 'select', 'label' => 'Zone', 'rules' => 'required', 'translate' => true,
                            'options_from' => ['table' => 'shipping_zones', 'value' => 'id', 'label' => 'name', 'order' => 'position, id']],
                        'basis' => ['type' => 'select', 'label' => 'Based on', 'rules' => 'required', 'options' => ['weight' => 'Weight (g)', 'price' => 'Order amount']],
                        'min' => ['label' => 'From', 'rules' => 'max:20', 'default' => '0'],
                        'max' => ['label' => 'Up to (0 = no limit)', 'rules' => 'max:20', 'default' => '0'],
                        'price' => ['type' => 'money', 'label' => 'Price', 'rules' => 'required|min:0'],
                    ]],
            ],
        ];
    }

    /**
     * Comprueba los días y las tarifas, y deja las tarifas con sus límites ya en gramos o en céntimos.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     *
     * @return array<string,mixed>
     */
    public static function carrierBeforeSave(array $values, ?array $current, App $app): array
    {
        $errors = [];
        $values['code'] = strtolower(trim(isset($values['code']) && is_scalar($values['code']) ? (string) $values['code'] : ''));
        if (isset($values['min_days'], $values['max_days']) && $values['min_days'] !== null && $values['max_days'] !== null && (int) $values['max_days'] < (int) $values['min_days']) {
            $errors['max_days'] = t('The maximum cannot be lower than the minimum.');
        }

        $rates = [];
        if (isset($values['handler']) && (string) $values['handler'] === 'rates') {
            $rows = isset($values['rates']) && is_array($values['rates']) ? array_values($values['rates']) : [];
            foreach ($rows as $i => $row) {
                $row = is_array($row) ? $row : [];
                $basis = self::text($row, 'basis') === 'price' ? 'price' : 'weight';
                $min = self::limit($basis, self::text($row, 'min'));
                $max = self::limit($basis, self::text($row, 'max'));
                $problem = '';
                if ($min === null || $max === null) {
                    $problem = $basis === 'price'
                        ? t('The limits of an order amount range are amounts, for example 50 or 12,50.')
                        : t('The limits of a weight range are whole grams, for example 1000.');
                } elseif ($max !== 0 && $max < $min) {
                    $problem = t('"Up to" cannot be lower than "From".');
                }
                if ($problem !== '') {
                    $errors['rates'] = t('Row {n}: {error}', ['n' => $i + 1, 'error' => $problem]);
                    break;
                }
                $rates[] = [
                    'zone_id' => (int) self::text($row, 'zone_id'),
                    'basis' => $basis,
                    'min' => (int) $min,
                    'max' => (int) $max,
                    'price' => isset($row['price']) && is_numeric($row['price']) ? (int) $row['price'] : 0,
                ];
            }
        }
        $values['rates'] = $rates;

        return $errors !== [] ? ['_errors' => $errors] : $values;
    }

    /**
     * Escribe las tarifas (solo si el cálculo es por tabla) y, en la recogida en tienda, el precio y la dirección.
     *
     * @param array<string,mixed>      $values
     * @param array<string,mixed>|null $current
     */
    public static function carrierAfterSave(int $id, array $values, ?array $current, App $app): void
    {
        $db = $app->db();
        $handler = isset($values['handler']) ? (string) $values['handler'] : '';
        if ($handler === 'pickup') {
            $row = $db->row('SELECT settings FROM {carriers} WHERE id = ?', [$id]);
            $decoded = $row !== null && is_string($row['settings']) ? json_decode($row['settings'], true) : null;
            $settings = is_array($decoded) ? $decoded : [];
            $settings['price'] = isset($values['pickup_price']) && is_numeric($values['pickup_price']) ? max(0, (int) $values['pickup_price']) : 0;
            $settings['address'] = isset($values['pickup_address']) && is_scalar($values['pickup_address']) ? trim((string) $values['pickup_address']) : '';
            $db->update('carriers', ['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)], ['id' => $id]);
        }
        if ($handler === 'rates') {
            $rates = isset($values['rates']) && is_array($values['rates']) ? $values['rates'] : [];
            $db->transaction(static function () use ($db, $id, $rates): void {
                $db->delete('carrier_rates', ['carrier_id' => $id]);
                foreach ($rates as $rate) {
                    $db->insert('carrier_rates', [
                        'carrier_id' => $id,
                        'zone_id' => (int) $rate['zone_id'],
                        'basis' => (string) $rate['basis'],
                        'min' => (int) $rate['min'],
                        'max' => (int) $rate['max'],
                        'price' => (int) $rate['price'],
                    ]);
                }
            });
        }
    }

    /**
     * No se borra un transportista con pedidos: se desactiva.
     *
     * @param array<string,mixed> $row
     */
    public static function carrierBeforeDelete(array $row, App $app): ?string
    {
        $db = $app->db();
        if ($db->tableExists('orders') && in_array('carrier_id', $db->columns('orders'), true)
            && $db->value('SELECT id FROM {orders} WHERE carrier_id = ?', [(int) $row['id']]) !== null) {
            return t('Orders were shipped with this carrier. Deactivate it instead of deleting it.');
        }

        return null;
    }

    /** @param array<string,mixed> $row */
    public static function carrierAfterDelete(array $row, App $app): void
    {
        $app->db()->delete('carrier_rates', ['carrier_id' => (int) $row['id']]);
    }

    /**
     * Los cálculos que se pueden elegir: los de serie y los que han registrado los plugins.
     *
     * @return array<string,string>
     */
    public static function handlerOptions(App $app): array
    {
        $options = [];
        $service = $app->service('shipping');
        foreach ($service instanceof Shipping ? $service->handlerCodes() : ['rates', 'pickup'] as $code) {
            $options[$code] = self::handlerLabel($app, $code);
        }

        return $options;
    }

    /**
     * La celda de un dato del transportista que no se ha rellenado: dice «Sin indicar» y enlaza a su ficha, en vez de un guion que
     * no dice si es un fallo, un cero o que no hay.
     */
    public static function notStated(App $app, int $id): string
    {
        return '<a class="af-muted" href="' . e(\Aifec\Admin\Admin::resourceUrl($app, 'carriers', $id)) . '">' . e(t('Not stated')) . '</a>';
    }

    public static function handlerLabel(App $app, string $code): string
    {
        if ($code === 'rates') {
            return t('Rate table');
        }
        if ($code === 'pickup') {
            return t('Pickup in store');
        }

        return $code;
    }

    // ------------------------------------------------------------------ piezas

    /**
     * @return array<int,array{country:string,region:string,postcodes:string}>
     */
    private static function zoneLocations(App $app, int $zoneId): array
    {
        $out = [];
        foreach ($app->db()->select('SELECT country, region, postcodes FROM {zone_locations} WHERE zone_id = ? ORDER BY id', [$zoneId]) as $row) {
            $out[] = ['country' => (string) $row['country'], 'region' => (string) $row['region'], 'postcodes' => (string) $row['postcodes']];
        }

        return $out;
    }

    /**
     * Las tarifas de un transportista tal como se enseñan en el formulario: los importes con coma decimal, el peso en gramos.
     *
     * @return array<int,array<string,mixed>>
     */
    private static function carrierRates(App $app, int $carrierId): array
    {
        $db = $app->db();
        $lo = $db->ident('min');
        $hi = $db->ident('max');
        $out = [];
        $rows = $db->select('SELECT zone_id, basis, ' . $lo . ' AS lo, ' . $hi . ' AS hi, price FROM {carrier_rates} WHERE carrier_id = ? ORDER BY id', [$carrierId]);
        foreach ($rows as $row) {
            $basis = (string) $row['basis'] === 'price' ? 'price' : 'weight';
            $show = static function ($value) use ($basis): string {
                return $basis === 'price' ? number_format(((int) $value) / 100, 2, ',', '') : (string) (int) $value;
            };
            $out[] = ['zone_id' => (string) (int) $row['zone_id'], 'basis' => $basis, 'min' => $show($row['lo']), 'max' => $show($row['hi']), 'price' => (int) $row['price']];
        }

        return $out;
    }

    /**
     * Un límite de tramo: gramos enteros para el peso, un importe para el importe. Vacío vale 0. Null si no se entiende o es negativo.
     */
    private static function limit(string $basis, string $text): ?int
    {
        if ($text === '') {
            return 0;
        }
        if ($basis === 'weight') {
            return preg_match('/^\d{1,9}$/D', $text) === 1 ? (int) $text : null;
        }
        $cents = Validator::toCents($text);

        return $cents !== null && $cents >= 0 ? $cents : null;
    }

    /**
     * @param array<string,mixed> $row
     */
    private static function text(array $row, string $key): string
    {
        return isset($row[$key]) && is_scalar($row[$key]) ? trim((string) $row[$key]) : '';
    }

    private static function defaultCountry(App $app): string
    {
        $service = $app->service('shipping');

        return $service instanceof Shipping ? $service->defaultCountry() : 'ES';
    }
}
