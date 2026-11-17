<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\PrestaShop;

use Aifec\Modules\Import\Neutral;

/**
 * Convierte lo que se lee de PrestaShop al formato neutro. Lo comparten los dos lectores: reciben filas con los nombres de
 * columna de la base de datos (el servicio web usa los mismos), con los textos por idioma como [id de idioma => texto] y las
 * listas ya armadas; aquí está toda la lógica de negocio (impuestos, precios de combinaciones, direcciones antiguas…).
 * Las conversiones de pedidos y cupones están en OrderMapper.
 */
final class Mapper
{
    // ------------------------------------------------------------------ valores sueltos

    /** @param mixed $v */
    public static function s($v): string
    {
        return $v === null || is_array($v) ? '' : trim((string) $v);
    }

    /** @param mixed $v */
    public static function i($v): int
    {
        return $v === null || is_array($v) ? 0 : (int) $v;
    }

    /** @param mixed $v */
    public static function b($v): bool
    {
        if (is_bool($v)) {
            return $v;
        }

        return $v !== null && !is_array($v) && (int) $v !== 0;
    }

    /**
     * Un decimal del origen («82.644628», 12, «-1,5») a millonésimas, sin pasar por coma flotante.
     *
     * @param mixed $v
     */
    public static function dec6($v): int
    {
        if (is_int($v)) {
            return $v * 1000000;
        }
        $text = is_float($v) ? number_format($v, 6, '.', '') : str_replace(',', '.', self::s($v));
        if (preg_match('/^([+-]?)(\d*)(?:\.(\d*))?$/', $text, $m) !== 1 || ($m[2] === '' && (!isset($m[3]) || $m[3] === ''))) {
            return 0;
        }
        $whole = $m[2] === '' ? '0' : $m[2];
        $fraction = substr(str_pad(isset($m[3]) ? $m[3] : '', 7, '0'), 0, 6);
        $value = (int) ($whole . $fraction);

        return $m[1] === '-' ? -$value : $value;
    }

    /** Millonésimas a céntimos, redondeando la mitad lejos de cero. */
    public static function centsOf(int $micros): int
    {
        $abs = intdiv(abs($micros) + 5000, 10000);

        return $micros < 0 ? -$abs : $abs;
    }

    /** @param mixed $v */
    public static function cents($v): int
    {
        return self::centsOf(self::dec6($v));
    }

    /**
     * @param mixed $v
     *
     * @return array<int,string> idioma => texto
     */
    private static function langs($v): array
    {
        return is_array($v) ? $v : [];
    }

    // ------------------------------------------------------------------ configuración

    /**
     * @param array{home_title?:array<int,string>,home_description?:array<int,string>} $meta
     *
     * @return array<string,mixed>
     */
    public static function settings(Env $env, array $meta): array
    {
        $weightUnit = strtolower($env->cfg('PS_WEIGHT_UNIT', 'kg'));
        $dimensionUnit = strtolower($env->cfg('PS_DIMENSION_UNIT', 'cm'));
        $group = $env->cfgInt('PS_CUSTOMER_GROUP');
        $record = [
            'id' => 'main',
            'shop_name' => $env->cfg('PS_SHOP_NAME'),
            'email' => $env->cfg('PS_SHOP_EMAIL'),
            'phone' => $env->cfg('PS_SHOP_PHONE'),
            'currency' => isset($env->currencies[$env->cfgInt('PS_CURRENCY_DEFAULT')]) ? $env->currencies[$env->cfgInt('PS_CURRENCY_DEFAULT')] : '',
            'languages' => array_values($env->langs),
            'timezone' => $env->cfg('PS_TIMEZONE', 'UTC'),
            'weight_unit' => in_array($weightUnit, ['kg', 'g', 'lb', 'oz'], true) ? $weightUnit : 'kg',
            'dimension_unit' => in_array($dimensionUnit, ['m', 'cm', 'mm', 'in'], true) ? $dimensionUnit : 'cm',
            'prices_include_tax' => false,
            'display_prices_with_tax' => isset($env->groups[$group]) ? $env->groups[$group]['display_with_tax'] : true,
            'tax_based_on' => $env->cfg('PS_TAX_ADDRESS_TYPE') === 'id_address_invoice' ? 'billing' : 'shipping',
            'guest_checkout' => $env->cfg('PS_GUEST_CHECKOUT_ENABLED') === '1',
            'stock_management' => $env->cfg('PS_STOCK_MANAGEMENT', '1') === '1',
            'invoice_number_prefix' => $env->cfgLang('PS_INVOICE_PREFIX', $env->defaultLang),
            'invoice_number_next' => $env->nextInvoice !== '' ? max(1, (int) $env->nextInvoice) : max(1, $env->cfgInt('PS_INVOICE_START_NUMBER')),
        ];
        if (isset($env->allLangs[$env->defaultLang])) {
            $record['default_language'] = $env->allLangs[$env->defaultLang];
        }
        $address = ['line1' => $env->cfg('PS_SHOP_ADDR1'), 'line2' => $env->cfg('PS_SHOP_ADDR2'), 'postcode' => $env->cfg('PS_SHOP_CODE'), 'city' => $env->cfg('PS_SHOP_CITY')];
        $region = $env->region($env->cfgInt('PS_SHOP_STATE_ID'));
        if ($region !== '') {
            $address['region'] = $region;
        }
        $country = $env->country($env->cfgInt('PS_SHOP_COUNTRY_ID'));
        if ($country !== '') {
            $address['country'] = $country;
        }
        $record['address'] = $address;
        if ($env->cfg('PS_LOGO') !== '') {
            $record['logo'] = $env->image('img/' . $env->cfg('PS_LOGO'));
        }
        if ($env->cfg('PS_FAVICON') !== '') {
            $record['icon'] = $env->image('img/' . $env->cfg('PS_FAVICON'));
        }
        $method = $env->cfgInt('PS_MAIL_METHOD');
        $encryption = strtolower($env->cfg('PS_MAIL_SMTP_ENCRYPTION', 'off'));
        $record['mail'] = [
            'transport' => $method === 2 ? 'smtp' : ($method === 1 ? 'php' : 'other'),
            'host' => $env->cfg('PS_MAIL_SERVER'),
            'port' => max(0, $env->cfgInt('PS_MAIL_SMTP_PORT')),
            'encryption' => $encryption === 'ssl' || $encryption === 'tls' ? $encryption : 'none',
            'username' => $env->cfg('PS_MAIL_USER'),
            'password' => $env->cfg('PS_MAIL_PASSWD'),
            'from_email' => $env->cfg('PS_SHOP_EMAIL'),
            'from_name' => $env->cfg('PS_SHOP_NAME'),
        ];
        $legal = [];
        if ($env->cfgInt('PS_CONDITIONS_CMS_ID') > 0) {
            $legal['terms'] = (string) $env->cfgInt('PS_CONDITIONS_CMS_ID');
        }
        $record['legal_pages'] = $legal;
        $title = $env->text(isset($meta['home_title']) ? $meta['home_title'] : []);
        if ($title !== []) {
            $record['home_title'] = $title;
        }
        $description = $env->text(isset($meta['home_description']) ? $meta['home_description'] : []);
        if ($description !== []) {
            $record['home_description'] = $description;
        }
        // PrestaShop guarda los precios sin impuestos y con seis decimales: lo que paga el cliente sale de redondear UNA vez el precio con impuestos.
        // Quien escribe los ajustes lo usa para no pasar la tienda de aquí a «precios sin impuestos», donde una base en céntimos no siempre puede
        // formar ese importe (82,644628 € + 21 % son 100,00 €; con 82,64 € salen 99,99 € y con 82,65 €, 100,01 €).
        $extra = [
            'ps_version' => $env->version, 'tax_enabled' => $env->cfg('PS_TAX', '1') === '1', 'catalog_mode' => $env->cfg('PS_CATALOG_MODE') === '1',
            'net_price_precision' => true,
        ];
        if ($env->cfg('PS_SHOP_DETAILS') !== '') {
            $extra['shop_details'] = $env->cfg('PS_SHOP_DETAILS');
        }
        if ($env->cfg('PS_SHOP_DOMAIN') !== '') {
            $extra['shop_domain'] = $env->cfg('PS_SHOP_DOMAIN');
        }
        $iso = $env->country($env->cfgInt('PS_COUNTRY_DEFAULT'));
        if ($iso !== '') {
            $extra['default_country'] = $iso;
        }
        $extra['currencies'] = array_values($env->currencies);
        $record['extra'] = $extra;

        return $record;
    }

    // ------------------------------------------------------------------ impuestos

    /**
     * @param array<string,mixed> $r id, name
     *
     * @return array<string,mixed>
     */
    public static function taxClass(array $r): array
    {
        $name = self::s($r['name']);
        $code = Routes::str2url($name);

        return ['id' => self::s($r['id']), 'code' => $code !== '' ? $code : 'group-' . self::s($r['id']), 'name' => $name !== '' ? $name : 'Tax group ' . self::s($r['id']), 'is_default' => false];
    }

    /**
     * @param array<string,mixed> $r id, id_tax_rules_group, id_country, id_state, zipcode_from, zipcode_to, behavior, description, tax_rate, tax_name, tax_active
     *
     * @return array<string,mixed>
     */
    public static function taxRate(array $r, Env $env): array
    {
        $country = $env->country(self::i($r['id_country']));
        if ($country === '') {
            throw new \InvalidArgumentException('The tax rule points to a country that does not exist');
        }
        $behavior = self::i(isset($r['behavior']) ? $r['behavior'] : 0);
        $name = $env->one(self::langs(isset($r['tax_name']) ? $r['tax_name'] : []));
        $record = [
            'id' => self::s($r['id']),
            'class_id' => self::s($r['id_tax_rules_group']),
            'country' => $country,
            'region' => $env->region(self::i(isset($r['id_state']) ? $r['id_state'] : 0)),
            'postcodes' => self::postcodes(self::s(isset($r['zipcode_from']) ? $r['zipcode_from'] : ''), self::s(isset($r['zipcode_to']) ? $r['zipcode_to'] : '')),
            'rate' => Neutral::rate(self::s($r['tax_rate'])),
            'name' => $name !== '' ? $name : self::s(isset($r['description']) ? $r['description'] : ''),
            'priority' => 0,
            'active' => self::b(isset($r['tax_active']) ? $r['tax_active'] : 1),
            'compound' => $behavior === 2,
            'applies_to_shipping' => true,
        ];
        $extra = [];
        if ($behavior !== 0) {
            $extra['behavior'] = $behavior === 1 ? 'combine' : 'one_after_another';
        }
        if (self::s(isset($r['description']) ? $r['description'] : '') !== '') {
            $extra['description'] = self::s($r['description']);
        }
        if ($extra !== []) {
            $record['extra'] = $extra;
        }

        return $record;
    }

    /** Rango de códigos postales de una regla: «desde-hasta», el mismo código si coinciden, o vacío si es «0». */
    private static function postcodes(string $from, string $to): string
    {
        $from = (string) preg_replace('/[^0-9A-Za-z\-]/', '', $from);
        $to = (string) preg_replace('/[^0-9A-Za-z\-]/', '', $to);
        if (($from === '' || $from === '0') && ($to === '' || $to === '0')) {
            return '';
        }
        if ($to === '' || $to === '0' || $to === $from) {
            return $from;
        }

        return ($from === '' || $from === '0' ? $to : $from . '-' . $to);
    }

    // ------------------------------------------------------------------ envíos

    /**
     * Lo que cubre una zona: un país entero si todas sus provincias están en la zona; si no, sus provincias con el código
     * ISO sin el prefijo del país.
     *
     * @return array<int,array{country:string,region:string,postcodes:string}>
     */
    public static function zoneLocations(Env $env, int $zoneId): array
    {
        $locations = [];
        foreach ($env->countries as $countryId => $country) {
            if (!$country['active'] || $country['iso'] === '') {
                continue;
            }
            $own = [];
            foreach ($env->states as $state) {
                if ($state['country'] === $countryId && $state['active']) {
                    $own[] = $state;
                }
            }
            if ($own === []) {
                if ($country['zone'] === $zoneId) {
                    $locations[] = ['country' => $country['iso'], 'region' => '', 'postcodes' => ''];
                }
                continue;
            }
            $inZone = array_values(array_filter($own, static function (array $state) use ($zoneId): bool {
                return $state['zone'] === $zoneId;
            }));
            if (count($inZone) === count($own)) {
                $locations[] = ['country' => $country['iso'], 'region' => '', 'postcodes' => ''];
                continue;
            }
            foreach ($inZone as $state) {
                $region = Env::regionCode($state['iso']);
                if ($region !== '') {
                    $locations[] = ['country' => $country['iso'], 'region' => $region, 'postcodes' => ''];
                }
            }
        }

        return $locations;
    }

    /**
     * @param array<string,mixed> $zone id, name, active
     *
     * @return array<string,mixed>
     */
    public static function zone(array $zone, Env $env, int $position): array
    {
        return [
            'id' => self::s($zone['id']),
            'name' => self::s($zone['name']) !== '' ? self::s($zone['name']) : 'Zone ' . self::s($zone['id']),
            'locations' => self::zoneLocations($env, self::i($zone['id'])),
            'position' => $position,
            'active' => self::b($zone['active']),
        ];
    }

    /**
     * @param array<string,mixed> $r fila del transportista (id_carrier, id_reference, name, url, is_free, shipping_method, shipping_handling,
     *                               range_behavior, is_module, external_module_name, position, max_weight, max_width…) más delay [idioma => texto],
     *                               tax_group, zones (ids), emitted_zones (ids), weight_ranges y price_ranges [id => [desde, hasta]],
     *                               deliveries [id_range_price, id_range_weight, id_zone, price]
     *
     * @return array<string,mixed>
     */
    public static function carrier(array $r, Env $env): array
    {
        $method = self::i(isset($r['shipping_method']) ? $r['shipping_method'] : 0);
        if ($method === 0) {
            $method = $env->cfgInt('PS_SHIPPING_METHOD') !== 0 ? 1 : 2;
        }
        $free = self::b($r['is_free']) || $method === 3;
        $name = self::s($r['name']);
        $emitted = array_map('intval', isset($r['emitted_zones']) && is_array($r['emitted_zones']) ? $r['emitted_zones'] : []);
        $zones = [];
        foreach (isset($r['zones']) && is_array($r['zones']) ? $r['zones'] : [] as $zoneId) {
            if (in_array((int) $zoneId, $emitted, true)) {
                $zones[] = (string) (int) $zoneId;
            }
        }
        $rates = [];
        if (!$free) {
            foreach (isset($r['deliveries']) && is_array($r['deliveries']) ? $r['deliveries'] : [] as $delivery) {
                $zoneId = self::i($delivery['id_zone']);
                if (!in_array($zoneId, $emitted, true)) {
                    continue;
                }
                $weightId = self::i(isset($delivery['id_range_weight']) ? $delivery['id_range_weight'] : 0);
                $priceId = self::i(isset($delivery['id_range_price']) ? $delivery['id_range_price'] : 0);
                if ($method === 1 && $weightId > 0 && isset($r['weight_ranges'][$weightId])) {
                    $range = $r['weight_ranges'][$weightId];
                    $min = Neutral::grams(self::s($range[0]), $env->weightUnit);
                    $max = self::s($range[1]) === '' ? null : Neutral::grams(self::s($range[1]), $env->weightUnit);
                    $basis = 'weight';
                } elseif ($method === 2 && $priceId > 0 && isset($r['price_ranges'][$priceId])) {
                    $range = $r['price_ranges'][$priceId];
                    $min = Neutral::cents(self::s($range[0]));
                    $max = self::s($range[1]) === '' ? null : Neutral::cents(self::s($range[1]));
                    $basis = 'subtotal';
                } else {
                    continue;
                }
                $rates[] = ['zone_id' => (string) $zoneId, 'basis' => $basis, 'min' => $min, 'max' => $max, 'price' => self::cents($delivery['price']), 'price_micros' => self::dec6($delivery['price'])];
            }
            usort($rates, static function (array $a, array $b): int {
                return [(int) $a['zone_id'], $a['basis'], $a['min']] <=> [(int) $b['zone_id'], $b['basis'], $b['min']];
            });
        }
        $record = [
            'id' => self::s($r['id_reference']) !== '' && self::s($r['id_reference']) !== '0' ? self::s($r['id_reference']) : self::s($r['id_carrier']),
            'name' => $name !== '' && $name !== '0' ? $name : ($env->cfg('PS_SHOP_NAME') !== '' ? $env->cfg('PS_SHOP_NAME') : 'Carrier ' . self::s($r['id_carrier'])),
            'delay' => $env->text(self::langs(isset($r['delay']) ? $r['delay'] : [])),
            'kind' => $free ? 'free' : 'rates',
            'tax_class_id' => self::i(isset($r['tax_group']) ? $r['tax_group'] : 0) > 0 ? (string) self::i($r['tax_group']) : null,
            'price_includes_tax' => false,
            'free_from' => !$free && Neutral::cents($env->cfg('PS_SHIPPING_FREE_PRICE', '0')) > 0 ? Neutral::cents($env->cfg('PS_SHIPPING_FREE_PRICE')) : null,
            'max_weight' => self::dec6(isset($r['max_weight']) ? $r['max_weight'] : 0) > 0 ? Neutral::grams(self::s($r['max_weight']), $env->weightUnit) : null,
            'tracking_url' => self::s(isset($r['url']) ? $r['url'] : ''),
            'active' => true,
            'position' => max(0, self::i(isset($r['position']) ? $r['position'] : 0)),
            'handling_fee' => self::b(isset($r['shipping_handling']) ? $r['shipping_handling'] : 0) ? Neutral::cents($env->cfg('PS_SHIPPING_HANDLING', '0')) : 0,
            'rates' => $rates,
            'zone_ids' => $zones,
        ];
        if ($record['handling_fee'] > 0) {
            // La tarifa de gestión también se guarda con seis decimales: el escritor la suma a cada tarifa antes de convertir impuestos.
            $record['handling_fee_micros'] = self::dec6($env->cfg('PS_SHIPPING_HANDLING', '0'));
        }
        $extra = ['id_carrier' => self::s($r['id_carrier'])];
        if (Neutral::cents($env->cfg('PS_SHIPPING_FREE_WEIGHT', '0')) > 0) {
            $extra['free_weight'] = $env->cfg('PS_SHIPPING_FREE_WEIGHT');
        }
        if (self::i(isset($r['range_behavior']) ? $r['range_behavior'] : 0) !== 0) {
            $extra['range_behavior'] = 'disable_when_out_of_range';
        }
        if (self::b(isset($r['is_module']) ? $r['is_module'] : 0)) {
            $extra['module'] = self::s(isset($r['external_module_name']) ? $r['external_module_name'] : '');
        }
        $record['extra'] = $extra;

        return $record;
    }

    // ------------------------------------------------------------------ clientes, marcas, categorías, atributos

    /**
     * @param array<string,mixed> $r id, reduction, name
     *
     * @return array<string,mixed>
     */
    public static function group(array $r, Env $env): array
    {
        $name = $env->text(self::langs($r['name']));
        if ($name === []) {
            $name = [isset($env->langs[$env->defaultLang]) ? $env->langs[$env->defaultLang] : 'es' => 'Group ' . self::s($r['id'])];
        }

        return [
            'id' => self::s($r['id']),
            'name' => $name,
            'discount' => Neutral::rate(self::s($r['reduction'])),
            'tax_exempt' => false,
            'is_default' => self::i($r['id']) === $env->cfgInt('PS_CUSTOMER_GROUP'),
            'extra' => ['display_prices_with_tax' => self::i(isset($r['price_display_method']) ? $r['price_display_method'] : 0) === 0],
        ];
    }

    /**
     * @param array<string,mixed> $r id, name, active, date_upd, description, short_description, meta_title, meta_description
     *
     * @return array<string,mixed>
     */
    public static function brand(array $r, Env $env): array
    {
        $id = self::i($r['id']);
        $name = self::s($r['name']);
        if ($name === '') {
            throw new \InvalidArgumentException('The brand has no name');
        }
        $slug = Routes::str2url($name);
        $iso = isset($env->langs[$env->defaultLang]) ? $env->langs[$env->defaultLang] : 'es';
        $record = [
            'id' => (string) $id,
            'name' => $name,
            'description' => $env->text(self::langs(isset($r['description']) ? $r['description'] : [])),
            'logo' => $env->image('img/m/' . $id . '.jpg'),
            'active' => self::b($r['active']),
            'position' => 0,
            'slug' => $slug !== '' ? [$iso => $slug] : [],
            'meta_title' => $env->text(self::langs(isset($r['meta_title']) ? $r['meta_title'] : [])),
            'meta_description' => $env->text(self::langs(isset($r['meta_description']) ? $r['meta_description'] : [])),
            'old_paths' => Routes::paths($env, 'manufacturer_rule', ['id' => (string) $id, 'rewrite' => $slug]),
        ];
        $updated = $env->utc(isset($r['date_upd']) ? $r['date_upd'] : null);
        if ($updated !== null) {
            $record['updated_at'] = $updated;
        }
        $short = $env->text(self::langs(isset($r['short_description']) ? $r['short_description'] : []));
        $short = array_filter($short, static function (string $text): bool {
            return trim($text) !== '';
        });
        if ($short !== []) {
            $record['extra'] = ['short_description' => $short];
        }

        return $record;
    }

    /**
     * @param array<string,mixed> $r id, id_parent, active, position, name, description, link_rewrite, meta_title, meta_description, date_upd
     *
     * @return array<string,mixed>
     */
    public static function category(array $r, Env $env): array
    {
        $id = self::i($r['id']);
        $name = $env->text(self::langs($r['name']));
        if (trim(implode('', $name)) === '') {
            throw new \InvalidArgumentException('The category has no name');
        }
        $parent = self::i($r['id_parent']);
        $record = [
            'id' => (string) $id,
            'parent_id' => $parent > 1 && $parent !== $env->cfgInt('PS_ROOT_CATEGORY') ? (string) $parent : null,
            'name' => $name,
            'description' => $env->text(self::langs(isset($r['description']) ? $r['description'] : [])),
            'slug' => $env->text(self::langs(isset($r['link_rewrite']) ? $r['link_rewrite'] : [])),
            'position' => max(0, self::i(isset($r['position']) ? $r['position'] : 0)),
            'active' => self::b($r['active']),
            'in_menu' => true,
            'image' => $env->image('img/c/' . $id . '.jpg'),
            'meta_title' => $env->text(self::langs(isset($r['meta_title']) ? $r['meta_title'] : [])),
            'meta_description' => $env->text(self::langs(isset($r['meta_description']) ? $r['meta_description'] : [])),
            'old_paths' => Routes::paths($env, 'category_rule', ['id' => (string) $id, 'rewrite' => self::langs(isset($r['link_rewrite']) ? $r['link_rewrite'] : [])]),
        ];
        $updated = $env->utc(isset($r['date_upd']) ? $r['date_upd'] : null);
        if ($updated !== null) {
            $record['updated_at'] = $updated;
        }
        if (self::b(isset($r['is_root_category']) ? $r['is_root_category'] : 0)) {
            $record['extra'] = ['shop_root' => true];
        }

        return $record;
    }

    /**
     * Un grupo de atributos (id «a12») o una característica (id «f12»).
     *
     * @param array<string,mixed> $r kind (group|feature), id, name, public_name, is_color_group, group_type, position, values [id, name, color, position]
     *
     * @return array<string,mixed>
     */
    public static function attribute(array $r, Env $env): array
    {
        $isGroup = $r['kind'] === 'group';
        $id = self::i($r['id']);
        $name = $env->text(self::langs($isGroup && isset($r['public_name']) ? $r['public_name'] : $r['name']));
        if (trim(implode('', $name)) === '') {
            $name = $env->text(self::langs($r['name']));
        }
        if (trim(implode('', $name)) === '') {
            throw new \InvalidArgumentException('The attribute has no name');
        }
        $isColor = $isGroup && (self::b(isset($r['is_color_group']) ? $r['is_color_group'] : 0) || self::s(isset($r['group_type']) ? $r['group_type'] : '') === 'color');
        $values = [];
        $position = 0;
        foreach (isset($r['values']) && is_array($r['values']) ? $r['values'] : [] as $value) {
            $label = $env->text(self::langs($value['name']));
            if (trim(implode('', $label)) === '') {
                continue;
            }
            $item = ['id' => self::s($value['id']), 'label' => $label, 'position' => isset($value['position']) && self::s($value['position']) !== '' ? max(0, self::i($value['position'])) : $position];
            if ($isColor && self::s(isset($value['color']) ? $value['color'] : '') !== '') {
                $item['color'] = self::s($value['color']);
            }
            $values[] = $item;
            ++$position;
        }
        $record = [
            'id' => ($isGroup ? 'a' : 'f') . $id,
            'code' => Routes::str2url($env->one(self::langs($r['name']))),
            'name' => $name,
            'type' => $isColor ? 'color' : 'select',
            'variant' => $isGroup,
            'filterable' => true,
            'visible' => true,
            'position' => max(0, self::i(isset($r['position']) ? $r['position'] : 0)),
            'values' => $values,
        ];
        if ($isGroup) {
            $internal = $env->text(self::langs($r['name']));
            if ($internal !== $name) {
                $record['extra'] = ['admin_name' => $internal];
            }
        }

        return $record;
    }

    // ------------------------------------------------------------------ productos

    /**
     * @param array<string,mixed> $r fila de producto (columnas de product y product_shop) con name, description, description_short, link_rewrite,
     *                               meta_title, meta_description [idioma => texto]; categories [ids]; images [[id, cover]]; combinations
     *                               [[id, reference, ean13, price, wholesale_price, weight, ecotax, minimal_quantity, default_on, values [ids],
     *                               images [ids]]]; features [[feature, value, custom, text]]; tags [idioma => [nombres]]; stock [combinación => cantidad];
     *                               out_of_stock; accessories [ids]; specific_prices; attachments; download; suppliers; pack
     *
     * @return array<string,mixed>
     */
    public static function product(array $r, Env $env): array
    {
        $id = self::s($r['id']);
        $name = $env->text(self::langs($r['name']));
        if (trim(implode('', $name)) === '') {
            throw new \InvalidArgumentException('The product has no name');
        }
        $price6 = self::dec6(isset($r['price']) ? $r['price'] : 0);
        $images = [];
        $coverSeen = false;
        foreach (isset($r['images']) && is_array($r['images']) ? $r['images'] : [] as $position => $image) {
            $part = $env->image(Env::productImagePath(self::i($image['id'])));
            $part['position'] = (int) $position;
            $part['cover'] = self::b(isset($image['cover']) ? $image['cover'] : 0) && !$coverSeen;
            $coverSeen = $coverSeen || $part['cover'];
            $images[] = $part;
        }
        $stock = isset($r['stock']) && is_array($r['stock']) ? $r['stock'] : [];
        $isVirtual = self::b(isset($r['is_virtual']) ? $r['is_virtual'] : 0);
        $visibility = self::s(isset($r['visibility']) ? $r['visibility'] : 'both');
        $backorders = self::i(isset($r['out_of_stock']) ? $r['out_of_stock'] : 2);
        if ($backorders === 2) {
            $backorders = $env->cfg('PS_ORDER_OUT_OF_STOCK') === '1' ? 1 : 0;
        }
        $sale = self::sale($r, $price6, $env);
        $record = [
            'id' => $id,
            'kind' => $isVirtual ? 'digital' : 'physical',
            'sku' => self::s(isset($r['reference']) ? $r['reference'] : ''),
            'gtin' => self::s(isset($r['ean13']) ? $r['ean13'] : '') !== '' ? self::s($r['ean13']) : self::s(isset($r['upc']) ? $r['upc'] : ''),
            'mpn' => self::s(isset($r['mpn']) ? $r['mpn'] : ''),
            'brand_id' => self::i(isset($r['id_manufacturer']) ? $r['id_manufacturer'] : 0) > 0 ? (string) self::i($r['id_manufacturer']) : null,
            'category_ids' => array_map('strval', isset($r['categories']) && is_array($r['categories']) ? array_values($r['categories']) : []),
            'main_category_id' => self::i(isset($r['id_category_default']) ? $r['id_category_default'] : 0) > 0 ? (string) self::i($r['id_category_default']) : null,
            'tax_class_id' => self::i(isset($r['id_tax_rules_group']) ? $r['id_tax_rules_group'] : 0) > 0 ? (string) self::i($r['id_tax_rules_group']) : null,
            'name' => $name,
            'summary' => $env->text(self::langs(isset($r['description_short']) ? $r['description_short'] : [])),
            'description' => $env->text(self::langs(isset($r['description']) ? $r['description'] : [])),
            'slug' => $env->text(self::langs(isset($r['link_rewrite']) ? $r['link_rewrite'] : [])),
            'price' => self::centsOf($price6),
            'price_includes_tax' => false,
            'compare_price' => null,
            'sale_price' => $sale['price'],
            'sale_from' => $sale['from'],
            'sale_to' => $sale['to'],
            'cost' => self::dec6(isset($r['wholesale_price']) ? $r['wholesale_price'] : 0) > 0 ? self::cents($r['wholesale_price']) : null,
            'manage_stock' => $env->cfg('PS_STOCK_MANAGEMENT', '1') === '1',
            'stock' => isset($stock[0]) ? (int) $stock[0] : 0,
            'backorders' => $backorders === 1 ? 'yes' : 'no',
            'min_qty' => max(0, self::i(isset($r['minimal_quantity']) ? $r['minimal_quantity'] : 1)),
            'max_qty' => null,
            'weight' => Neutral::grams(isset($r['weight']) ? $r['weight'] : 0, $env->weightUnit),
            'length' => Neutral::millimetres(isset($r['depth']) ? $r['depth'] : 0, $env->dimensionUnit),
            'width' => Neutral::millimetres(isset($r['width']) ? $r['width'] : 0, $env->dimensionUnit),
            'height' => Neutral::millimetres(isset($r['height']) ? $r['height'] : 0, $env->dimensionUnit),
            'status' => self::b(isset($r['active']) ? $r['active'] : 0) ? 'published' : 'draft',
            'visibility' => $visibility === 'catalog' ? 'catalog' : ($visibility === 'search' ? 'search' : ($visibility === 'none' ? 'hidden' : 'visible')),
            'featured' => false,
            'images' => $images,
            'attributes' => self::productAttributes(isset($r['features']) && is_array($r['features']) ? $r['features'] : [], $env),
            'tags' => self::tags(isset($r['tags']) && is_array($r['tags']) ? $r['tags'] : [], $env),
            'files' => self::productFiles($r, $env),
            'variants' => [],
            'related_ids' => array_map('strval', isset($r['accessories']) && is_array($r['accessories']) ? array_values($r['accessories']) : []),
            'meta_title' => $env->text(self::langs(isset($r['meta_title']) ? $r['meta_title'] : [])),
            'meta_description' => $env->text(self::langs(isset($r['meta_description']) ? $r['meta_description'] : [])),
            'old_paths' => Routes::paths($env, 'product_rule', Routes::productParams($env, ['id' => $id] + $r)),
        ];
        $created = $env->utc(isset($r['date_add']) ? $r['date_add'] : null);
        if ($created !== null) {
            $record['created_at'] = $created;
        }
        $updated = $env->utc(isset($r['date_upd']) ? $r['date_upd'] : null);
        if ($updated !== null) {
            $record['updated_at'] = $updated;
        }
        // Las millonésimas que guarda PrestaShop: el escritor convierte impuestos desde ellas y no desde el céntimo ya redondeado.
        $record['price_micros'] = $price6;
        if ($sale['price'] !== null) {
            $record['sale_price_micros'] = $sale['micros'];
        }
        if ($sale['reduction'] !== null) {
            $record['sale_reduction'] = $sale['reduction'];
        }
        $variants = self::variants($r, $price6, $stock, count($images), $env, $skipped);
        $record['variants'] = $variants;
        $record['extra'] = self::productExtra($r, $price6, $sale['rest'], $variants, $skipped, $env);

        return $record;
    }

    /**
     * El precio de oferta: un precio específico general (todos los grupos, países y monedas, desde una unidad) que rebaja el
     * precio y no ha caducado. Los demás se devuelven aparte para guardarlos en «extra».
     *
     * @param array<string,mixed> $r
     *
     * @return array{price:?int,micros:?int,reduction:?array<string,mixed>,from:?string,to:?string,rest:array<int,array<string,mixed>>}
     */
    private static function sale(array $r, int $price6, Env $env): array
    {
        $best = null;
        $reduced = null;
        $rest = [];
        foreach (isset($r['specific_prices']) && is_array($r['specific_prices']) ? $r['specific_prices'] : [] as $sp) {
            $from = $env->utc(isset($sp['from']) ? $sp['from'] : null);
            $to = $env->utc(isset($sp['to']) ? $sp['to'] : null);
            $general = self::i(isset($sp['id_group']) ? $sp['id_group'] : 0) === 0 && self::i(isset($sp['id_country']) ? $sp['id_country'] : 0) === 0
                && self::i(isset($sp['id_currency']) ? $sp['id_currency'] : 0) === 0 && self::i(isset($sp['id_customer']) ? $sp['id_customer'] : 0) === 0
                && self::i(isset($sp['id_product_attribute']) ? $sp['id_product_attribute'] : 0) === 0 && self::i(isset($sp['from_quantity']) ? $sp['from_quantity'] : 1) <= 1;
            $expired = $to !== null && strcmp($to, $env->now) < 0;
            $type = self::s(isset($sp['reduction_type']) ? $sp['reduction_type'] : 'amount');
            $reduction6 = self::dec6(isset($sp['reduction']) ? $sp['reduction'] : 0);
            $fixed = isset($sp['price']) && self::s($sp['price']) !== '' && self::dec6($sp['price']) >= 0 && self::s($sp['price']) !== '-1' && strpos(self::s($sp['price']), '-') !== 0;
            $base6 = $fixed ? self::dec6($sp['price']) : $price6;
            $taxed = $type === 'amount' && $reduction6 > 0 && self::b(isset($sp['reduction_tax']) ? $sp['reduction_tax'] : 0);
            $sale6 = $base6;
            if ($type === 'percentage') {
                $sale6 = $base6 - intdiv($base6 * $reduction6 + 500000, 1000000);
            } elseif (!$taxed) {
                $sale6 = $base6 - $reduction6;
            }
            $sale6 = max(0, $sale6);
            if ($general && !$expired && !$taxed && $sale6 < $price6) {
                if ($best === null || $sale6 < $best['p']) {
                    if ($best !== null) {
                        $rest[] = $best['sp'];
                    }
                    $best = ['p' => $sale6, 'from' => $from, 'to' => $to, 'sp' => self::specificExtra($sp, $from, $to)];
                    continue;
                }
            }
            // Rebaja por importe CON impuestos (la forma por defecto desde 1.7): no se puede convertir en un precio sin impuestos porque
            // aquí no se conoce el tipo; se entrega tal cual y la resta la hace el escritor. Solo sobre el precio del producto (sin precio fijo).
            if ($general && !$expired && $taxed && !$fixed && ($reduced === null || $reduction6 > $reduced['r'])) {
                $reduced = ['r' => $reduction6, 'from' => $from, 'to' => $to, 'at' => count($rest)];
            }
            $rest[] = self::specificExtra($sp, $from, $to);
        }
        // Solo cuando no hay ya un precio rebajado: entonces la rebaja elegida sale de «extra» y viaja en su campo.
        if ($best !== null) {
            $reduced = null;
        } elseif ($reduced !== null) {
            unset($rest[$reduced['at']]);
            $rest = array_values($rest);
        }

        return [
            'price' => $best === null ? null : self::centsOf($best['p']),
            'micros' => $best === null ? null : $best['p'],
            'reduction' => $reduced === null ? null : ['amount' => self::centsOf($reduced['r']), 'amount_micros' => $reduced['r'], 'includes_tax' => true],
            'from' => $best !== null ? $best['from'] : ($reduced !== null ? $reduced['from'] : null),
            'to' => $best !== null ? $best['to'] : ($reduced !== null ? $reduced['to'] : null),
            'rest' => $rest,
        ];
    }

    /**
     * @param array<string,mixed> $sp
     *
     * @return array<string,mixed>
     */
    private static function specificExtra(array $sp, ?string $from, ?string $to): array
    {
        return [
            'id' => self::s(isset($sp['id']) ? $sp['id'] : (isset($sp['id_specific_price']) ? $sp['id_specific_price'] : '')),
            'group' => self::i(isset($sp['id_group']) ? $sp['id_group'] : 0),
            'country' => self::i(isset($sp['id_country']) ? $sp['id_country'] : 0),
            'currency' => self::i(isset($sp['id_currency']) ? $sp['id_currency'] : 0),
            'customer' => self::i(isset($sp['id_customer']) ? $sp['id_customer'] : 0),
            'combination' => self::i(isset($sp['id_product_attribute']) ? $sp['id_product_attribute'] : 0),
            'from_quantity' => self::i(isset($sp['from_quantity']) ? $sp['from_quantity'] : 1),
            'price' => self::s(isset($sp['price']) ? $sp['price'] : '-1'),
            'reduction' => self::s(isset($sp['reduction']) ? $sp['reduction'] : '0'),
            'reduction_type' => self::s(isset($sp['reduction_type']) ? $sp['reduction_type'] : 'amount'),
            'reduction_tax' => self::b(isset($sp['reduction_tax']) ? $sp['reduction_tax'] : 0),
            'from' => $from,
            'to' => $to,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $features
     *
     * @return array<int,array<string,mixed>>
     */
    private static function productAttributes(array $features, Env $env): array
    {
        $byFeature = [];
        $out = [];
        foreach ($features as $feature) {
            $featureId = self::i($feature['feature']);
            if (self::b(isset($feature['custom']) ? $feature['custom'] : 0)) {
                $text = $env->text(self::langs(isset($feature['text']) ? $feature['text'] : []));
                if (trim(implode('', $text)) !== '') {
                    $out[] = ['attribute_id' => 'f' . $featureId, 'text' => $text, '_o' => $featureId];
                }
            } elseif (self::i(isset($feature['value']) ? $feature['value'] : 0) > 0) {
                $byFeature[$featureId][] = (string) self::i($feature['value']);
            }
        }
        foreach ($byFeature as $featureId => $ids) {
            $out[] = ['attribute_id' => 'f' . $featureId, 'value_ids' => array_values(array_unique($ids)), '_o' => $featureId];
        }
        usort($out, static function (array $a, array $b): int {
            return $a['_o'] <=> $b['_o'];
        });
        foreach ($out as $i => $item) {
            unset($out[$i]['_o']);
        }

        return array_values($out);
    }

    /**
     * @param array<int,array<int,string>> $tags
     *
     * @return array<string,array<int,string>>
     */
    private static function tags(array $tags, Env $env): array
    {
        $out = [];
        foreach ($env->orderedLangs() as $id => $iso) {
            if (isset($tags[$id]) && is_array($tags[$id]) && $tags[$id] !== []) {
                $names = array_values(array_unique(array_map('strval', $tags[$id])));
                sort($names);
                $out[$iso] = $names;
            }
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $r
     *
     * @return array<int,array<string,mixed>>
     */
    private static function productFiles(array $r, Env $env): array
    {
        $files = [];
        foreach (isset($r['attachments']) && is_array($r['attachments']) ? $r['attachments'] : [] as $attachment) {
            $file = self::s(isset($attachment['file']) ? $attachment['file'] : '');
            $label = self::s(isset($attachment['file_name']) ? $attachment['file_name'] : '');
            if ($label === '') {
                $label = $env->one(self::langs(isset($attachment['name']) ? $attachment['name'] : []));
            }
            if ($file === '' && $label === '') {
                continue;
            }
            $item = ['name' => $label !== '' ? $label : $file, 'bytes' => max(0, self::i(isset($attachment['file_size']) ? $attachment['file_size'] : 0))];
            if ($file !== '') {
                $item['path'] = 'download/' . $file;
            }
            $files[] = $item;
        }
        if (isset($r['download']) && is_array($r['download']) && self::s(isset($r['download']['filename']) ? $r['download']['filename'] : '') !== '') {
            $download = $r['download'];
            $files[] = [
                'name' => self::s(isset($download['display_filename']) ? $download['display_filename'] : '') !== '' ? self::s($download['display_filename']) : self::s($download['filename']),
                'path' => 'download/' . self::s($download['filename']),
            ];
        }

        return $files;
    }

    /**
     * @param array<string,mixed> $r
     * @param array<int,int>      $stock
     *
     * @return array<int,array<string,mixed>>
     */
    private static function variants(array $r, int $price6, array $stock, int $imageCount, Env $env, ?int &$skipped): array
    {
        $skipped = 0;
        $groups = $env->lookup('attribute_groups');
        $productImages = [];
        foreach (isset($r['images']) && is_array($r['images']) ? $r['images'] : [] as $position => $image) {
            $productImages[self::i($image['id'])] = (int) $position;
        }
        $variants = [];
        $position = 0;
        foreach (isset($r['combinations']) && is_array($r['combinations']) ? $r['combinations'] : [] as $c) {
            $values = [];
            foreach (isset($c['values']) && is_array($c['values']) ? $c['values'] : [] as $attributeId) {
                $group = isset($groups[(int) $attributeId]) ? (int) $groups[(int) $attributeId] : 0;
                if ($group > 0) {
                    $values[$group . ':' . (int) $attributeId] = ['attribute_id' => 'a' . $group, 'value_id' => (string) (int) $attributeId, '_g' => $group, '_v' => (int) $attributeId];
                }
            }
            if ($values === []) {
                ++$skipped;
                continue;
            }
            usort($values, static function (array $a, array $b): int {
                return [$a['_g'], $a['_v']] <=> [$b['_g'], $b['_v']];
            });
            foreach ($values as $k => $value) {
                unset($values[$k]['_g'], $values[$k]['_v']);
            }
            $final6 = $price6 + self::dec6(isset($c['price']) ? $c['price'] : 0);
            $comboId = self::i($c['id']);
            $variant = [
                'id' => (string) $comboId,
                'sku' => self::s(isset($c['reference']) ? $c['reference'] : ''),
                'gtin' => self::s(isset($c['ean13']) ? $c['ean13'] : ''),
                'price' => self::centsOf($final6),
                'price_micros' => $final6,
                'compare_price' => null,
                'cost' => self::dec6(isset($c['wholesale_price']) ? $c['wholesale_price'] : 0) > 0 ? self::cents($c['wholesale_price']) : (self::dec6(isset($r['wholesale_price']) ? $r['wholesale_price'] : 0) > 0 ? self::cents($r['wholesale_price']) : null),
                'stock' => isset($stock[$comboId]) ? (int) $stock[$comboId] : 0,
                'weight' => max(0, Neutral::grams(isset($r['weight']) ? $r['weight'] : 0, $env->weightUnit) + (int) round(self::dec6(isset($c['weight']) ? $c['weight'] : 0) * self::weightFactor($env) / 1000000)),
                'active' => true,
                'position' => $position,
                'values' => array_values($values),
            ];
            foreach (isset($c['images']) && is_array($c['images']) ? $c['images'] : [] as $imageId) {
                if (isset($productImages[(int) $imageId])) {
                    $variant['image_index'] = $productImages[(int) $imageId];
                    break;
                }
            }
            if (isset($variant['image_index']) && $variant['image_index'] >= $imageCount) {
                unset($variant['image_index']);
            }
            $variants[] = $variant;
            ++$position;
        }

        return $variants;
    }

    private static function weightFactor(Env $env): float
    {
        $factors = ['kg' => 1000.0, 'g' => 1.0, 'lb' => 453.59237, 'oz' => 28.349523125];

        return isset($factors[$env->weightUnit]) ? $factors[$env->weightUnit] : 1000.0;
    }

    /**
     * @param array<string,mixed>            $r
     * @param array<int,array<string,mixed>> $rest      precios específicos que no son la oferta
     * @param array<int,array<string,mixed>> $variants
     *
     * @return array<string,mixed>
     */
    private static function productExtra(array $r, int $price6, array $rest, array $variants, ?int $skipped, Env $env): array
    {
        $extra = [];
        $condition = self::s(isset($r['condition']) ? $r['condition'] : 'new');
        if ($condition !== '' && $condition !== 'new') {
            $extra['condition'] = $condition;
        }
        if (self::dec6(isset($r['ecotax']) ? $r['ecotax'] : 0) > 0) {
            $extra['ecotax'] = self::cents($r['ecotax']);
        }
        if (self::s(isset($r['unity']) ? $r['unity'] : '') !== '') {
            $extra['unity'] = self::s($r['unity']);
        }
        $ratio6 = self::dec6(isset($r['unit_price_ratio']) ? $r['unit_price_ratio'] : 0);
        if ($ratio6 > 0) {
            $extra['unit_price'] = self::centsOf(intdiv($price6 * 1000000 + intdiv($ratio6, 2), $ratio6));
        }
        foreach (['isbn', 'upc', 'supplier_reference'] as $key) {
            if (self::s(isset($r[$key]) ? $r[$key] : '') !== '') {
                $extra[$key] = self::s($r[$key]);
            }
        }
        $extra['available_for_order'] = self::b(isset($r['available_for_order']) ? $r['available_for_order'] : 1);
        $extra['show_price'] = self::b(isset($r['show_price']) ? $r['show_price'] : 1);
        if (self::b(isset($r['on_sale']) ? $r['on_sale'] : 0)) {
            $extra['on_sale'] = true;
        }
        if (self::b(isset($r['online_only']) ? $r['online_only'] : 0)) {
            $extra['online_only'] = true;
        }
        $available = self::s(isset($r['available_date']) ? $r['available_date'] : '');
        if ($available !== '' && strncmp($available, '0000-00-00', 10) !== 0) {
            $extra['available_date'] = substr($available, 0, 10);
        }
        foreach (['customizable', 'text_fields', 'uploadable_files'] as $key) {
            if (self::i(isset($r[$key]) ? $r[$key] : 0) > 0) {
                $extra[$key] = self::i($r[$key]);
            }
        }
        if (isset($r['download']) && is_array($r['download']) && self::s(isset($r['download']['filename']) ? $r['download']['filename'] : '') !== '') {
            $extra['download'] = [
                'times' => self::i(isset($r['download']['nb_downloadable']) ? $r['download']['nb_downloadable'] : 0),
                'days' => self::i(isset($r['download']['nb_days_accessible']) ? $r['download']['nb_days_accessible'] : 0),
            ];
        }
        $pack = [];
        foreach (isset($r['pack']) && is_array($r['pack']) ? $r['pack'] : [] as $item) {
            $pack[] = ['product' => self::s($item['product']), 'combination' => self::s(isset($item['combination']) ? $item['combination'] : '0'), 'qty' => self::i($item['qty'])];
        }
        if ($pack !== []) {
            $extra['pack'] = $pack;
        }
        $suppliers = [];
        foreach (isset($r['suppliers']) && is_array($r['suppliers']) ? $r['suppliers'] : [] as $item) {
            $suppliers[] = [
                'supplier' => self::s($item['id_supplier']),
                'reference' => self::s(isset($item['product_supplier_reference']) ? $item['product_supplier_reference'] : ''),
                'price' => self::cents(isset($item['product_supplier_price_te']) ? $item['product_supplier_price_te'] : 0),
            ];
        }
        if ($suppliers !== []) {
            $extra['suppliers'] = $suppliers;
        }
        if ($rest !== []) {
            $extra['specific_prices'] = $rest;
        }
        foreach ($variants as $variant) {
            if (self::b(isset($r['combinations']) ? self::defaultOn($r['combinations'], (int) $variant['id']) : 0)) {
                $extra['default_variant'] = $variant['id'];
            }
        }
        if ($skipped !== null && $skipped > 0) {
            $extra['skipped_combinations'] = $skipped;
        }

        return $extra;
    }

    /**
     * @param array<int,array<string,mixed>> $combinations
     */
    private static function defaultOn(array $combinations, int $id): bool
    {
        foreach ($combinations as $c) {
            if (self::i($c['id']) === $id) {
                return self::b(isset($c['default_on']) ? $c['default_on'] : 0);
            }
        }

        return false;
    }

    // ------------------------------------------------------------------ clientes

    /**
     * Una dirección como parte «address».
     *
     * @param array<string,mixed> $a
     *
     * @return array<string,mixed>
     */
    public static function address(array $a, Env $env, bool $default): array
    {
        $phone = self::s(isset($a['phone_mobile']) ? $a['phone_mobile'] : '');
        $out = [
            'id' => self::s($a['id']),
            'label' => self::s(isset($a['alias']) ? $a['alias'] : ''),
            'first_name' => self::s(isset($a['firstname']) ? $a['firstname'] : ''),
            'last_name' => self::s(isset($a['lastname']) ? $a['lastname'] : ''),
            'company' => self::s(isset($a['company']) ? $a['company'] : ''),
            'vat_id' => self::s(isset($a['vat_number']) ? $a['vat_number'] : '') !== '' ? self::s($a['vat_number']) : self::s(isset($a['dni']) ? $a['dni'] : ''),
            'phone' => $phone !== '' ? $phone : self::s(isset($a['phone']) ? $a['phone'] : ''),
            'line1' => self::s(isset($a['address1']) ? $a['address1'] : ''),
            'line2' => self::s(isset($a['address2']) ? $a['address2'] : ''),
            'postcode' => self::s(isset($a['postcode']) ? $a['postcode'] : ''),
            'city' => self::s(isset($a['city']) ? $a['city'] : ''),
            'region' => $env->region(self::i(isset($a['id_state']) ? $a['id_state'] : 0)),
            'default_shipping' => $default,
            'default_billing' => $default,
        ];
        $country = $env->country(self::i(isset($a['id_country']) ? $a['id_country'] : 0));
        if ($country !== '') {
            $out['country'] = $country;
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $r fila de cliente más addresses (filas de dirección no borradas, por id) y group_ids
     *
     * @return array<string,mixed>
     */
    public static function customer(array $r, Env $env): array
    {
        $email = self::s($r['email']);
        if ($email === '') {
            throw new \InvalidArgumentException('The customer has no email');
        }
        $addresses = [];
        $vat = '';
        $phone = '';
        foreach (isset($r['addresses']) && is_array($r['addresses']) ? array_values($r['addresses']) : [] as $i => $a) {
            $part = self::address($a, $env, $i === 0);
            $addresses[] = $part;
            $vat = $vat !== '' ? $vat : $part['vat_id'];
            $phone = $phone !== '' ? $phone : $part['phone'];
        }
        $scheme = 'unknown';
        $hash = self::s(isset($r['passwd']) ? $r['passwd'] : '');
        if (preg_match('/^\$2[abxy]\$\d{2}\$/', $hash) === 1) {
            $scheme = 'bcrypt';
        } elseif (preg_match('/^[0-9a-f]{32}$/i', $hash) === 1) {
            $scheme = 'prestashop-md5';
        }
        $guest = self::b(isset($r['is_guest']) ? $r['is_guest'] : 0);
        $newsletter = self::b(isset($r['newsletter']) ? $r['newsletter'] : 0);
        $marketingAt = $env->utc(isset($r['newsletter_date_add']) ? $r['newsletter_date_add'] : null);
        $consents = [];
        if ($newsletter) {
            $consents[] = ['kind' => 'marketing', 'granted' => true, 'at' => $marketingAt, 'context' => 'newsletter'];
        }
        if (self::b(isset($r['optin']) ? $r['optin'] : 0)) {
            $consents[] = ['kind' => 'other', 'granted' => true, 'at' => null, 'context' => 'partner_offers'];
        }
        $record = [
            'id' => self::s($r['id']),
            'email' => $email,
            'first_name' => self::s(isset($r['firstname']) ? $r['firstname'] : ''),
            'last_name' => self::s(isset($r['lastname']) ? $r['lastname'] : ''),
            'company' => self::s(isset($r['company']) ? $r['company'] : ''),
            'vat_id' => $vat,
            'phone' => $phone,
            'group_id' => self::i(isset($r['id_default_group']) ? $r['id_default_group'] : 0) > 0 ? (string) self::i($r['id_default_group']) : null,
            'active' => self::b(isset($r['active']) ? $r['active'] : 1),
            'guest' => $guest,
            'marketing' => $newsletter,
            'marketing_at' => $marketingAt,
            'password_hash' => $guest || $hash === '' ? null : $hash,
            'password_scheme' => $scheme,
            'notes' => self::s(isset($r['note']) ? $r['note'] : ''),
            'last_login_at' => null,
            'addresses' => $addresses,
            'consents' => $consents,
        ];
        $iso = $env->iso(self::i(isset($r['id_lang']) ? $r['id_lang'] : 0));
        if ($iso !== '') {
            $record['locale'] = $iso;
        }
        $created = $env->utc(isset($r['date_add']) ? $r['date_add'] : null);
        if ($created !== null) {
            $record['created_at'] = $created;
        }
        $updated = $env->utc(isset($r['date_upd']) ? $r['date_upd'] : null);
        if ($updated !== null) {
            $record['updated_at'] = $updated;
        }
        $extra = [];
        if ($scheme === 'prestashop-md5' && !$guest) {
            $extra['cookie_key_needed'] = $env->cfg('_cookie_key_given') !== '1';
        }
        foreach (['id_gender' => 'gender', 'birthday' => 'birthday', 'website' => 'website', 'siret' => 'siret', 'ape' => 'ape'] as $column => $key) {
            $value = self::s(isset($r[$column]) ? $r[$column] : '');
            if ($value !== '' && $value !== '0' && $value !== '0000-00-00') {
                $extra[$key] = $value;
            }
        }
        $groups = array_values(array_map('strval', isset($r['group_ids']) && is_array($r['group_ids']) ? $r['group_ids'] : []));
        sort($groups);
        if (count($groups) > 1) {
            $extra['group_ids'] = $groups;
        }
        if ($extra !== []) {
            $record['extra'] = $extra;
        }

        return $record;
    }

    // ------------------------------------------------------------------ contenidos, equipo, suscritos, opiniones, pagos, redirecciones

    /**
     * @param array<string,mixed> $r id, id_cms_category, active, meta_title, head_seo_title, meta_description, content, link_rewrite
     *
     * @return array<string,mixed>
     */
    public static function page(array $r, Env $env): array
    {
        $title = $env->text(self::langs($r['meta_title']));
        if (trim(implode('', $title)) === '') {
            throw new \InvalidArgumentException('The page has no title');
        }
        $id = self::i($r['id']);
        $seo = $env->text(self::langs(isset($r['head_seo_title']) ? $r['head_seo_title'] : []));
        $record = [
            'id' => (string) $id,
            'title' => $title,
            'slug' => $env->text(self::langs(isset($r['link_rewrite']) ? $r['link_rewrite'] : [])),
            'content' => $env->text(self::langs(isset($r['content']) ? $r['content'] : [])),
            'content_format' => 'html',
            'status' => self::b($r['active']) ? 'published' : 'draft',
            'parent_id' => null,
            'meta_title' => trim(implode('', $seo)) !== '' ? $seo : $title,
            'meta_description' => $env->text(self::langs(isset($r['meta_description']) ? $r['meta_description'] : [])),
            'old_paths' => Routes::paths($env, 'cms_rule', ['id' => (string) $id, 'rewrite' => self::langs(isset($r['link_rewrite']) ? $r['link_rewrite'] : [])]),
        ];
        if ($id === $env->cfgInt('PS_CONDITIONS_CMS_ID')) {
            $record['legal'] = 'terms';
        }
        $extra = [];
        if (self::i(isset($r['id_cms_category']) ? $r['id_cms_category'] : 0) > 0) {
            $extra['cms_category'] = self::s($r['id_cms_category']);
        }
        if (isset($r['indexation']) && !self::b($r['indexation'])) {
            $extra['noindex'] = true;
        }
        if ($extra !== []) {
            $record['extra'] = $extra;
        }

        return $record;
    }

    /**
     * @param array<string,mixed> $r id, firstname, lastname, email, id_profile, id_lang, active, last_connection_date, profile (nombre por idioma)
     *
     * @return array<string,mixed>
     */
    public static function employee(array $r, Env $env): array
    {
        $email = self::s($r['email']);
        $name = trim(self::s(isset($r['firstname']) ? $r['firstname'] : '') . ' ' . self::s(isset($r['lastname']) ? $r['lastname'] : ''));
        if ($email === '' || $name === '') {
            throw new \InvalidArgumentException('The employee has no name or email');
        }
        $profile = self::i(isset($r['id_profile']) ? $r['id_profile'] : 0);
        $meanings = [1 => 'admin', 2 => 'manager', 3 => 'editor', 4 => 'manager'];
        $record = [
            'id' => self::s($r['id']),
            'email' => $email,
            'name' => $name,
            'role' => $env->one(self::langs(isset($r['profile']) ? $r['profile'] : [])),
            'role_meaning' => isset($meanings[$profile]) ? $meanings[$profile] : 'other',
            'active' => self::b(isset($r['active']) ? $r['active'] : 0),
            'last_login_at' => $env->utc(isset($r['last_connection_date']) ? $r['last_connection_date'] : null),
        ];
        $iso = $env->iso(self::i(isset($r['id_lang']) ? $r['id_lang'] : 0));
        if ($iso !== '') {
            $record['locale'] = $iso;
        }

        return $record;
    }

    /**
     * Un suscrito del módulo de boletín («e12») o un cliente con el boletín activado («c12»).
     *
     * @param array<string,mixed> $r id, email, name, date, source, confirmed, id_lang
     *
     * @return array<string,mixed>
     */
    public static function subscriber(array $r, Env $env): array
    {
        $email = self::s($r['email']);
        if ($email === '') {
            throw new \InvalidArgumentException('The subscriber has no email');
        }
        $record = [
            'id' => self::s($r['id']),
            'email' => $email,
            'name' => self::s(isset($r['name']) ? $r['name'] : ''),
            'granted_at' => $env->utc(isset($r['date']) ? $r['date'] : null),
            'source' => self::s(isset($r['source']) ? $r['source'] : ''),
            'confirmed' => self::b(isset($r['confirmed']) ? $r['confirmed'] : 1),
        ];
        $iso = $env->iso(self::i(isset($r['id_lang']) ? $r['id_lang'] : 0));
        if ($iso !== '') {
            $record['locale'] = $iso;
        }

        return $record;
    }

    /**
     * Una opinión del módulo «productcomments».
     *
     * @param array<string,mixed> $r id_product_comment, id_product, id_customer, customer_name, title, content, grade, validate, date_add
     *
     * @return array<string,mixed>
     */
    public static function review(array $r, Env $env): array
    {
        $grade = (int) round(((float) self::dec6(isset($r['grade']) ? $r['grade'] : 0)) / 1000000);
        $record = [
            'id' => self::s($r['id']),
            'product_id' => self::s($r['id_product']),
            'author' => self::s(isset($r['customer_name']) ? $r['customer_name'] : ''),
            'rating' => max(1, min(5, $grade)),
            'title' => self::s(isset($r['title']) ? $r['title'] : ''),
            'body' => self::s(isset($r['content']) ? $r['content'] : ''),
            'status' => self::b(isset($r['validate']) ? $r['validate'] : 0) ? 'approved' : 'pending',
            'verified' => false,
            'at' => $env->utc(isset($r['date_add']) ? $r['date_add'] : null),
        ];
        if (self::i(isset($r['id_customer']) ? $r['id_customer'] : 0) > 0) {
            $record['customer_id'] = self::s($r['id_customer']);
        }

        return $record;
    }

    /**
     * Una forma de pago de un módulo de pago conocido.
     *
     * @param array<string,mixed> $r name (módulo), code, title, active, sandbox, settings [clave => valor]
     *
     * @return array<string,mixed>
     */
    public static function payment(array $r, Env $env, int $position): array
    {
        $iso = isset($env->langs[$env->defaultLang]) ? $env->langs[$env->defaultLang] : 'en';
        $settings = [];
        foreach (isset($r['settings']) && is_array($r['settings']) ? $r['settings'] : [] as $key => $value) {
            $settings[(string) $key] = (string) $value;
        }

        return [
            'id' => self::s($r['name']),
            'code' => self::s($r['code']),
            'title' => [$iso => self::s($r['title'])],
            'enabled' => self::b($r['active']),
            'sandbox' => isset($r['sandbox']) && $r['sandbox'] !== null ? self::b($r['sandbox']) : null,
            'settings' => $settings,
            'min_amount' => null,
            'max_amount' => null,
            'countries' => [],
            'position' => $position,
        ];
    }

    /**
     * Una redirección de un producto («301-product», «302-category»…).
     *
     * @param array<string,mixed> $r id, redirect_type, id_type_redirected, paths (la del producto), target (dirección de lo que apunta)
     *
     * @return array<string,mixed>|null
     */
    public static function redirect(array $r): ?array
    {
        $type = self::s($r['redirect_type']);
        $code = strncmp($type, '302', 3) === 0 ? 302 : (strncmp($type, '301', 3) === 0 ? 301 : 0);
        if ($code === 0 || self::s($r['target']) === '' || !isset($r['paths'][0])) {
            return null;
        }

        return ['id' => 'p' . self::s($r['id']), 'source' => (string) $r['paths'][0], 'target' => self::s($r['target']), 'code' => $code, 'active' => true];
    }
}
