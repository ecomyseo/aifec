<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

/**
 * Las respuestas de ejemplo del servicio web de PrestaShop, sacadas de los mismos datos inventados que la copia de la base de
 * datos (fixtures/db/data.php), con la forma real del servicio web: todo son cadenas, los textos por idioma son
 * «[{"id":"1","value":"…"}]», las asociaciones van en «associations» como listas de «{"id":"…"}» y los campos ocultos
 * (tax_rate y tax_name de las líneas de pedido) no salen. Los JSON de esta carpeta se generan con build.php y una prueba
 * comprueba que siguen siendo lo que sale de data.php.
 */
final class WsExport
{
    /** @var array<string,string> recurso => fichero donde se guarda */
    public const FILES = [
        'configurations' => 'config', 'translated_configurations' => 'config', 'shops' => 'config', 'languages' => 'config', 'currencies' => 'config', 'countries' => 'config',
        'states' => 'config', 'zones' => 'config', 'taxes' => 'config', 'tax_rule_groups' => 'config', 'tax_rules' => 'config', 'groups' => 'config', 'order_states' => 'config',
        'carriers' => 'shipping', 'weight_ranges' => 'shipping', 'price_ranges' => 'shipping', 'deliveries' => 'shipping',
        'manufacturers' => 'catalog', 'categories' => 'catalog', 'product_options' => 'catalog', 'product_option_values' => 'catalog', 'product_features' => 'catalog',
        'product_feature_values' => 'catalog', 'products' => 'catalog', 'combinations' => 'catalog', 'stock_availables' => 'catalog', 'specific_prices' => 'catalog', 'tags' => 'catalog',
        'product_suppliers' => 'catalog', 'attachments' => 'catalog',
        'customers' => 'people', 'addresses' => 'people', 'employees' => 'people', 'cart_rules' => 'people',
        'orders' => 'sales', 'order_details' => 'sales', 'order_histories' => 'sales', 'order_payments' => 'sales', 'order_invoices' => 'sales', 'order_slip' => 'sales',
        'order_carriers' => 'sales', 'order_cart_rules' => 'sales',
        'content_management_system' => 'misc', 'suppliers' => 'misc', 'stores' => 'misc', 'warehouses' => 'misc', 'customer_messages' => 'misc', 'carts' => 'misc',
    ];

    /** @var PDO */
    private $pdo;

    /** @var int */
    private $shop;

    private function __construct(PDO $pdo, int $shop)
    {
        $this->pdo = $pdo;
        $this->shop = $shop;
    }

    /**
     * @return array<string,array<int,array<string,mixed>>> recurso => elementos
     */
    public static function export(PDO $pdo, int $shop = 1): array
    {
        $self = new self($pdo, $shop);
        $out = [];
        $self->config($out);
        $self->shipping($out);
        $self->catalog($out);
        $self->products($out);
        $self->people($out);
        $self->sales($out);
        $self->misc($out);
        ksort($out);

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function rows(string $table, string $order = '', string $where = ''): array
    {
        $sql = 'SELECT * FROM `ps_' . $table . '`' . ($where !== '' ? ' WHERE ' . $where : '') . ($order !== '' ? ' ORDER BY ' . $order : '');
        $rows = [];
        foreach ($this->pdo->query($sql) as $row) {
            $rows[] = self::strings(array_filter($row, 'is_string', ARRAY_FILTER_USE_KEY));
        }

        return $rows;
    }

    /**
     * @param array<string,mixed> $row
     *
     * @return array<string,string>
     */
    private static function strings(array $row): array
    {
        $out = [];
        foreach ($row as $key => $value) {
            $out[$key] = $value === null ? '' : (string) $value;
        }

        return $out;
    }

    /**
     * Los textos por idioma de una tabla «_lang», agrupados por clave: clave => columna => [{id, value}].
     *
     * @param string[] $columns
     *
     * @return array<string,array<string,array<int,array{id:string,value:string}>>>
     */
    private function lang(string $table, string $key, array $columns, string $where = ''): array
    {
        $out = [];
        foreach ($this->rows($table, $key . ', id_lang', $where) as $row) {
            foreach ($columns as $column) {
                $out[$row[$key]][$column][] = ['id' => $row['id_lang'], 'value' => isset($row[$column]) ? $row[$column] : ''];
            }
        }

        return $out;
    }

    /**
     * @param array<int,int|string> $ids
     *
     * @return array<int,array{id:string}>
     */
    private static function ids(array $ids): array
    {
        return array_map(static function ($id): array {
            return ['id' => (string) $id];
        }, array_values($ids));
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $out
     */
    private function config(array &$out): void
    {
        $out['configurations'] = array_map(static function (array $row): array {
            return ['id' => $row['id_configuration']] + $row;
        }, $this->rows('configuration', 'id_configuration'));
        $langs = $this->lang('configuration_lang', 'id_configuration', ['value']);
        $out['translated_configurations'] = [];
        foreach ($this->rows('configuration', 'id_configuration') as $row) {
            if (isset($langs[$row['id_configuration']])) {
                $out['translated_configurations'][] = ['id' => $row['id_configuration'], 'id_shop_group' => $row['id_shop_group'], 'id_shop' => $row['id_shop'], 'name' => $row['name'], 'value' => $langs[$row['id_configuration']]['value']];
            }
        }
        $out['shops'] = array_map(static function (array $row): array {
            return ['id' => $row['id_shop']] + $row;
        }, $this->rows('shop', 'id_shop'));
        $out['languages'] = array_map(static function (array $row): array {
            return ['id' => $row['id_lang']] + $row;
        }, $this->rows('lang', 'id_lang'));
        $out['currencies'] = array_map(static function (array $row): array {
            return ['id' => $row['id_currency']] + $row;
        }, $this->rows('currency', 'id_currency'));
        $out['countries'] = array_map(static function (array $row): array {
            return ['id' => $row['id_country']] + $row;
        }, $this->rows('country', 'id_country'));
        $out['states'] = array_map(static function (array $row): array {
            return ['id' => $row['id_state']] + $row;
        }, $this->rows('state', 'id_state'));
        $out['zones'] = array_map(static function (array $row): array {
            return ['id' => $row['id_zone']] + $row;
        }, $this->rows('zone', 'id_zone'));
        $names = $this->lang('tax_lang', 'id_tax', ['name']);
        $out['taxes'] = array_map(static function (array $row) use ($names): array {
            return ['id' => $row['id_tax']] + $row + ['name' => $names[$row['id_tax']]['name']];
        }, $this->rows('tax', 'id_tax'));
        $out['tax_rule_groups'] = array_map(static function (array $row): array {
            return ['id' => $row['id_tax_rules_group']] + $row;
        }, $this->rows('tax_rules_group', 'id_tax_rules_group'));
        $out['tax_rules'] = array_map(static function (array $row): array {
            return ['id' => $row['id_tax_rule']] + $row;
        }, $this->rows('tax_rule', 'id_tax_rule'));
        $groups = $this->lang('group_lang', 'id_group', ['name']);
        $out['groups'] = array_map(static function (array $row) use ($groups): array {
            return ['id' => $row['id_group']] + $row + ['name' => $groups[$row['id_group']]['name']];
        }, $this->rows('group', 'id_group'));
        $states = $this->lang('order_state_lang', 'id_order_state', ['name', 'template']);
        $out['order_states'] = array_map(static function (array $row) use ($states): array {
            return ['id' => $row['id_order_state']] + $row + ['name' => $states[$row['id_order_state']]['name'], 'template' => $states[$row['id_order_state']]['template']];
        }, $this->rows('order_state', 'id_order_state'));
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $out
     */
    private function shipping(array &$out): void
    {
        $delay = $this->lang('carrier_lang', 'id_carrier', ['delay'], 'id_shop = ' . $this->shop);
        $tax = [];
        foreach ($this->rows('carrier_tax_rules_group_shop', 'id_carrier', 'id_shop = ' . $this->shop) as $row) {
            $tax[$row['id_carrier']] = $row['id_tax_rules_group'];
        }
        $out['carriers'] = [];
        foreach ($this->rows('carrier', 'id_carrier') as $row) {
            $id = $row['id_carrier'];
            $item = ['id' => $id] + $row;
            $item['id_tax_rules_group'] = isset($tax[$id]) ? $tax[$id] : (isset($row['id_tax_rules_group']) && $row['id_tax_rules_group'] !== '' ? $row['id_tax_rules_group'] : '0');
            $item['delay'] = isset($delay[$id]) ? $delay[$id]['delay'] : [];
            // El servicio web real no tiene asociaciones en los transportistas (ni zonas ni grupos): el contraste con el código de
            // PrestaShop 8.2.7 y 9.1.1 lo demostró (utiles/aifec/contraste).
            $out['carriers'][] = $item;
        }
        foreach (['weight_ranges' => 'range_weight', 'price_ranges' => 'range_price'] as $resource => $table) {
            $out[$resource] = array_map(static function (array $row) use ($table): array {
                return ['id' => $row['id_' . $table]] + $row;
            }, $this->rows($table, 'id_' . $table));
        }
        $out['deliveries'] = array_map(static function (array $row): array {
            return ['id' => $row['id_delivery']] + $row;
        }, $this->rows('delivery', 'id_delivery'));
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $out
     */
    private function catalog(array &$out): void
    {
        $lang = $this->lang('manufacturer_lang', 'id_manufacturer', ['description', 'short_description', 'meta_title', 'meta_description']);
        $out['manufacturers'] = array_map(static function (array $row) use ($lang): array {
            return ['id' => $row['id_manufacturer']] + $row + $lang[$row['id_manufacturer']];
        }, $this->rows('manufacturer', 'id_manufacturer'));
        $lang = $this->lang('category_lang', 'id_category', ['name', 'description', 'link_rewrite', 'meta_title', 'meta_description'], 'id_shop = ' . $this->shop);
        $position = [];
        foreach ($this->rows('category_shop', 'id_category', 'id_shop = ' . $this->shop) as $row) {
            $position[$row['id_category']] = $row['position'];
        }
        $out['categories'] = [];
        foreach ($this->rows('category', 'id_category') as $row) {
            if (isset($lang[$row['id_category']])) {
                $out['categories'][] = ['id' => $row['id_category']] + array_merge($row, ['position' => $position[$row['id_category']]]) + $lang[$row['id_category']];
            }
        }
        $values = [];
        foreach ($this->rows('attribute', 'position, id_attribute') as $row) {
            $values[$row['id_attribute_group']][] = $row['id_attribute'];
        }
        $lang = $this->lang('attribute_group_lang', 'id_attribute_group', ['name', 'public_name']);
        $out['product_options'] = array_map(function (array $row) use ($lang, $values): array {
            return ['id' => $row['id_attribute_group']] + $row + $lang[$row['id_attribute_group']] + ['associations' => ['product_option_values' => self::ids(isset($values[$row['id_attribute_group']]) ? $values[$row['id_attribute_group']] : [])]];
        }, $this->rows('attribute_group', 'id_attribute_group'));
        $lang = $this->lang('attribute_lang', 'id_attribute', ['name']);
        $out['product_option_values'] = array_map(static function (array $row) use ($lang): array {
            return ['id' => $row['id_attribute']] + $row + $lang[$row['id_attribute']];
        }, $this->rows('attribute', 'id_attribute'));
        $lang = $this->lang('feature_lang', 'id_feature', ['name']);
        $out['product_features'] = array_map(static function (array $row) use ($lang): array {
            return ['id' => $row['id_feature']] + $row + $lang[$row['id_feature']];
        }, $this->rows('feature', 'id_feature'));
        $lang = $this->lang('feature_value_lang', 'id_feature_value', ['value']);
        $out['product_feature_values'] = array_map(static function (array $row) use ($lang): array {
            return ['id' => $row['id_feature_value']] + $row + $lang[$row['id_feature_value']];
        }, $this->rows('feature_value', 'id_feature_value'));
        $out['tags'] = array_map(static function (array $row): array {
            return ['id' => $row['id_tag']] + $row;
        }, $this->rows('tag', 'id_tag'));
        $out['product_suppliers'] = array_map(static function (array $row): array {
            return ['id' => $row['id_product_supplier']] + $row;
        }, $this->rows('product_supplier', 'id_product_supplier'));
        $lang = $this->lang('attachment_lang', 'id_attachment', ['name', 'description']);
        $out['attachments'] = array_map(static function (array $row) use ($lang): array {
            return ['id' => $row['id_attachment']] + $row + $lang[$row['id_attachment']];
        }, $this->rows('attachment', 'id_attachment'));
        $out['stock_availables'] = array_map(static function (array $row): array {
            return ['id' => $row['id_stock_available']] + $row;
        }, $this->rows('stock_available', 'id_stock_available'));
        $out['specific_prices'] = array_map(static function (array $row): array {
            return ['id' => $row['id_specific_price']] + $row;
        }, $this->rows('specific_price', 'id_specific_price'));
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $out
     */
    private function products(array &$out): void
    {
        $lang = $this->lang('product_lang', 'id_product', ['name', 'description', 'description_short', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords'], 'id_shop = ' . $this->shop);
        $shopRows = [];
        foreach ($this->rows('product_shop', 'id_product', 'id_shop = ' . $this->shop) as $row) {
            $shopRows[$row['id_product']] = $row;
        }
        $categories = [];
        foreach ($this->rows('category_product', 'id_category') as $row) {
            $categories[$row['id_product']][] = $row['id_category'];
        }
        $images = [];
        $cover = [];
        foreach ($this->rows('image', 'position, id_image') as $row) {
            $images[$row['id_product']][] = $row['id_image'];
            if ($row['cover'] === '1') {
                $cover[$row['id_product']] = $row['id_image'];
            }
        }
        $combinations = [];
        $options = [];
        $comboValues = [];
        foreach ($this->rows('product_attribute_combination', 'id_attribute') as $row) {
            $comboValues[$row['id_product_attribute']][] = $row['id_attribute'];
        }
        $comboImages = [];
        foreach ($this->rows('product_attribute_image', 'id_image') as $row) {
            $comboImages[$row['id_product_attribute']][] = $row['id_image'];
        }
        $shopCombos = [];
        foreach ($this->rows('product_attribute_shop', 'id_product_attribute', 'id_shop = ' . $this->shop) as $row) {
            $shopCombos[$row['id_product_attribute']] = $row;
        }
        $out['combinations'] = [];
        foreach ($this->rows('product_attribute', 'id_product_attribute') as $row) {
            $id = $row['id_product_attribute'];
            if (!isset($shopCombos[$id])) {
                continue;
            }
            $item = ['id' => $id] + array_merge($row, array_diff_key($shopCombos[$id], ['id_product' => 1, 'id_product_attribute' => 1, 'id_shop' => 1]));
            $item['associations'] = ['product_option_values' => self::ids(isset($comboValues[$id]) ? $comboValues[$id] : []), 'images' => self::ids(isset($comboImages[$id]) ? $comboImages[$id] : [])];
            $out['combinations'][] = $item;
            $combinations[$row['id_product']][] = $id;
            foreach (isset($comboValues[$id]) ? $comboValues[$id] : [] as $value) {
                $options[$row['id_product']][$value] = $value;
            }
        }
        $features = [];
        foreach ($this->rows('feature_product', 'id_feature') as $row) {
            $features[$row['id_product']][] = ['id' => $row['id_feature'], 'id_feature_value' => $row['id_feature_value']];
        }
        $tags = [];
        foreach ($this->rows('product_tag', 'id_tag') as $row) {
            $tags[$row['id_product']][] = $row['id_tag'];
        }
        $stock = [];
        foreach ($this->rows('stock_available', 'id_stock_available', 'id_shop = ' . $this->shop) as $row) {
            $stock[$row['id_product']][] = ['id' => $row['id_stock_available'], 'id_product_attribute' => $row['id_product_attribute']];
        }
        $attachments = [];
        foreach ($this->rows('product_attachment', 'id_attachment') as $row) {
            $attachments[$row['id_product']][] = $row['id_attachment'];
        }
        $accessories = [];
        foreach ($this->rows('accessory', 'id_product_2') as $row) {
            $accessories[$row['id_product_1']][] = $row['id_product_2'];
        }
        $bundle = [];
        foreach ($this->rows('pack', 'id_product_item') as $row) {
            $bundle[$row['id_product_pack']][] = ['id' => $row['id_product_item'], 'id_product_attribute' => $row['id_product_attribute_item'], 'quantity' => $row['quantity']];
        }
        $out['products'] = [];
        foreach ($this->rows('product', 'id_product') as $row) {
            $id = $row['id_product'];
            if (!isset($shopRows[$id])) {
                continue;
            }
            $item = ['id' => $id] + array_merge($row, array_diff_key($shopRows[$id], ['id_product' => 1, 'id_shop' => 1])) + $lang[$id];
            $item['id_default_image'] = isset($cover[$id]) ? $cover[$id] : '';
            $item['associations'] = [
                'categories' => self::ids(isset($categories[$id]) ? $categories[$id] : []),
                'images' => self::ids(isset($images[$id]) ? $images[$id] : []),
                'combinations' => self::ids(isset($combinations[$id]) ? $combinations[$id] : []),
                'product_option_values' => self::ids(isset($options[$id]) ? array_values($options[$id]) : []),
                'product_features' => isset($features[$id]) ? $features[$id] : [],
                'tags' => self::ids(isset($tags[$id]) ? $tags[$id] : []),
                'stock_availables' => isset($stock[$id]) ? $stock[$id] : [],
                'attachments' => self::ids(isset($attachments[$id]) ? $attachments[$id] : []),
                'accessories' => self::ids(isset($accessories[$id]) ? $accessories[$id] : []),
                'product_bundle' => isset($bundle[$id]) ? $bundle[$id] : [],
            ];
            $out['products'][] = $item;
        }
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $out
     */
    private function people(array &$out): void
    {
        $groups = [];
        foreach ($this->rows('customer_group', 'id_group') as $row) {
            $groups[$row['id_customer']][] = $row['id_group'];
        }
        $out['customers'] = array_map(static function (array $row) use ($groups): array {
            return ['id' => $row['id_customer']] + $row + ['associations' => ['groups' => self::ids(isset($groups[$row['id_customer']]) ? $groups[$row['id_customer']] : [])]];
        }, $this->rows('customer', 'id_customer'));
        $out['addresses'] = array_map(static function (array $row): array {
            return ['id' => $row['id_address']] + $row;
        }, $this->rows('address', 'id_address'));
        $out['employees'] = array_map(static function (array $row): array {
            return ['id' => $row['id_employee']] + $row;
        }, $this->rows('employee', 'id_employee'));
        $lang = $this->lang('cart_rule_lang', 'id_cart_rule', ['name']);
        $related = [];
        foreach (['country' => ['cart_rule_country', 'id_country'], 'carrier' => ['cart_rule_carrier', 'id_carrier'], 'group' => ['cart_rule_group', 'id_group']] as $name => $spec) {
            foreach ($this->rows($spec[0], $spec[1]) as $row) {
                $related[$name][$row['id_cart_rule']][] = $row[$spec[1]];
            }
        }
        foreach ($this->rows('cart_rule_combination', 'id_cart_rule_2') as $row) {
            $related['cart_rule'][$row['id_cart_rule_1']][] = $row['id_cart_rule_2'];
        }
        // Las reglas de producto (cart_rule_product_rule*) no salen: el servicio web real no las expone (contraste con el código de
        // PrestaShop 8.2.7 y 9.1.1, utiles/aifec/contraste).
        $out['cart_rules'] = array_map(static function (array $row) use ($lang, $related): array {
            $id = $row['id_cart_rule'];

            return ['id' => $id] + $row + $lang[$id] + ['associations' => [
                'country' => self::ids(isset($related['country'][$id]) ? $related['country'][$id] : []),
                'carrier' => self::ids(isset($related['carrier'][$id]) ? $related['carrier'][$id] : []),
                'group' => self::ids(isset($related['group'][$id]) ? $related['group'][$id] : []),
                'cart_rule' => self::ids(isset($related['cart_rule'][$id]) ? $related['cart_rule'][$id] : []),
            ]];
        }, $this->rows('cart_rule', 'id_cart_rule'));
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $out
     */
    private function sales(array &$out): void
    {
        $details = [];
        foreach ($this->rows('order_detail', 'id_order_detail') as $row) {
            $details[$row['id_order']][] = $row;
        }
        $out['orders'] = [];
        foreach ($this->rows('orders', 'id_order') as $row) {
            $rows = [];
            foreach (isset($details[$row['id_order']]) ? $details[$row['id_order']] : [] as $d) {
                $rows[] = ['id' => $d['id_order_detail'], 'product_id' => $d['product_id'], 'product_attribute_id' => $d['product_attribute_id'], 'product_quantity' => $d['product_quantity'], 'product_name' => $d['product_name'],
                    'product_reference' => $d['product_reference'], 'product_ean13' => $d['product_ean13'], 'product_isbn' => '', 'product_upc' => '', 'product_price' => $d['unit_price_tax_excl'],
                    'id_customization' => $d['id_customization'], 'unit_price_tax_incl' => $d['unit_price_tax_incl'], 'unit_price_tax_excl' => $d['unit_price_tax_excl']];
            }
            $out['orders'][] = ['id' => $row['id_order']] + $row + ['associations' => ['order_rows' => $rows]];
        }
        $taxes = [];
        foreach ($this->rows('order_detail_tax', 'id_tax') as $row) {
            $taxes[$row['id_order_detail']][] = $row['id_tax'];
        }
        $out['order_details'] = array_map(static function (array $row) use ($taxes): array {
            $id = $row['id_order_detail'];
            unset($row['tax_rate'], $row['tax_name']);

            return ['id' => $id] + $row + ['associations' => ['taxes' => self::ids(isset($taxes[$id]) ? $taxes[$id] : [])]];
        }, $this->rows('order_detail', 'id_order_detail'));
        $out['order_histories'] = array_map(static function (array $row): array {
            return ['id' => $row['id_order_history']] + $row;
        }, $this->rows('order_history', 'id_order_history'));
        $out['order_payments'] = array_map(static function (array $row): array {
            return ['id' => $row['id_order_payment']] + $row;
        }, $this->rows('order_payment', 'id_order_payment'));
        $out['order_invoices'] = array_map(static function (array $row): array {
            return ['id' => $row['id_order_invoice']] + $row;
        }, $this->rows('order_invoice', 'id_order_invoice'));
        $slipDetails = [];
        foreach ($this->rows('order_slip_detail', 'id_order_detail') as $row) {
            $slipDetails[$row['id_order_slip']][] = ['id' => $row['id_order_detail'], 'product_quantity' => $row['product_quantity'], 'amount_tax_excl' => $row['amount_tax_excl'], 'amount_tax_incl' => $row['amount_tax_incl']];
        }
        $out['order_slip'] = array_map(static function (array $row) use ($slipDetails): array {
            return ['id' => $row['id_order_slip']] + $row + ['associations' => ['order_slip_details' => isset($slipDetails[$row['id_order_slip']]) ? $slipDetails[$row['id_order_slip']] : []]];
        }, $this->rows('order_slip', 'id_order_slip'));
        $out['order_carriers'] = array_map(static function (array $row): array {
            return ['id' => $row['id_order_carrier']] + $row;
        }, $this->rows('order_carrier', 'id_order_carrier'));
        $out['order_cart_rules'] = array_map(static function (array $row): array {
            return ['id' => $row['id_order_cart_rule']] + $row;
        }, $this->rows('order_cart_rule', 'id_order_cart_rule'));
    }

    /**
     * @param array<string,array<int,array<string,mixed>>> $out
     */
    private function misc(array &$out): void
    {
        $lang = $this->lang('cms_lang', 'id_cms', ['meta_title', 'head_seo_title', 'meta_description', 'meta_keywords', 'content', 'link_rewrite'], 'id_shop = ' . $this->shop);
        $out['content_management_system'] = array_map(static function (array $row) use ($lang): array {
            return ['id' => $row['id_cms']] + $row + $lang[$row['id_cms']];
        }, $this->rows('cms', 'id_cms'));
        foreach (['suppliers' => 'supplier', 'stores' => 'store', 'warehouses' => 'warehouse', 'customer_messages' => 'customer_message', 'carts' => 'cart'] as $resource => $table) {
            $out[$resource] = array_map(static function (array $row) use ($table): array {
                return ['id' => $row['id_' . $table]] + $row;
            }, $this->rows($table, 'id_' . $table));
        }
    }
}
