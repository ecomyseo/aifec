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

use Aifec\Http\HttpClient;
use Aifec\Modules\Import\Neutral;
use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

/**
 * El lector de PrestaShop 1.7, 8 y 9 por su servicio web (clave de API). Pide cada recurso con «output_format=JSON&display=full», lo
 * pagina con «limit=<desde>,<cuántos>&sort=[id_ASC]» y lo entrega al Mapper con las mismas columnas que la base de datos. Un recurso
 * sin permiso (401) deja sin leer solo ese tipo; si falla el primero, no hay acceso y se para.
 */
final class ApiReader extends BaseReader
{
    /** El recurso con el que se comprueba cada tipo y se lee. */
    private const RESOURCES = [
        'settings' => 'configurations', 'tax_classes' => 'tax_rule_groups', 'tax_rates' => 'tax_rules', 'shipping_zones' => 'zones', 'carriers' => 'carriers',
        'customer_groups' => 'groups', 'brands' => 'manufacturers', 'categories' => 'categories', 'attributes' => 'product_options', 'products' => 'products',
        'customers' => 'customers', 'coupons' => 'cart_rules', 'orders' => 'orders', 'pages' => 'content_management_system', 'team' => 'employees',
    ];

    /** @var Webservice */
    private $ws;

    /** @var array<string,bool> tipos que el servicio web no deja leer con esta clave */
    private $unavailable = [];

    /** @var array<int,array{active:bool,deleted:bool}>|null */
    private $taxMeta;

    /** @var array<int,array<string,mixed>>|null todos los transportistas, también los borrados (los pedidos antiguos los citan) */
    private $allCarriers;

    /**
     * @param HttpClient          $connection
     * @param array<string,mixed> $config     url, key, shop, cookie_key, timezone, now, requests_per_second, timeout
     */
    public function __construct($connection, array $config)
    {
        if (!$connection instanceof HttpClient) {
            throw new \InvalidArgumentException('The web service reader needs an HttpClient');
        }
        parent::__construct($config);
        $this->ws = new Webservice($connection, isset($config['url']) ? (string) $config['url'] : '', isset($config['key']) ? (string) $config['key'] : '', [
            'timeout' => isset($config['timeout']) ? $config['timeout'] : 20,
            'requests_per_second' => isset($config['requests_per_second']) ? $config['requests_per_second'] : 4,
            'shop' => isset($config['shop']) ? $config['shop'] : '',
        ]);
    }

    public static function describe(): array
    {
        return [
            'source' => 'prestashop',
            'mode' => 'api',
            'label' => 'PrestaShop (web service)',
            'help' => 'Reads the shop through its web service (PrestaShop 1.7, 8 or 9). Create a key in Advanced parameters > Webservice with read-only access (GET) to all the resources.',
            'fields' => [
                'url' => ['type' => 'url', 'label' => 'Shop address', 'required' => true, 'hint' => 'For example https://shop.example'],
                'key' => ['type' => 'secret', 'label' => 'Web service key', 'required' => true, 'hint' => 'The key of the web service, with read access'],
                'shop' => ['type' => 'text', 'label' => 'Shop (multistore)', 'required' => false, 'hint' => 'The shop number to read. Empty: the default shop', 'default' => ''],
                'cookie_key' => ['type' => 'secret', 'label' => 'Cookie key (cookie_key in app/config/parameters.php)', 'required' => false, 'hint' => 'Only needed so that the old customer passwords keep working'],
                'timezone' => ['type' => 'text', 'label' => 'Time zone', 'required' => false, 'hint' => 'Empty: the one of the shop settings', 'default' => ''],
            ],
        ];
    }

    public function mode(): string
    {
        return 'api';
    }

    public function key(): string
    {
        $host = (string) parse_url($this->ws->base(), PHP_URL_HOST);
        $path = trim((string) parse_url($this->ws->base(), PHP_URL_PATH), '/');

        return mb_substr($host . ($path !== '' ? '/' . $path : '') . ($this->shopConfig() !== '' ? '#' . $this->shopConfig() : ''), 0, 60);
    }

    private function shopConfig(): string
    {
        return isset($this->config['shop']) ? trim((string) $this->config['shop']) : '';
    }

    public function types(): array
    {
        return array_values(array_filter(array_keys(self::RESOURCES), function (string $type): bool {
            return !isset($this->unavailable[$type]);
        }));
    }

    // ------------------------------------------------------------------ el entorno

    protected function buildEnv(): Env
    {
        $env = new Env();
        $cfgRows = [];
        $names = self::CONFIG_NAMES;
        foreach (array_chunk($names, 25) as $chunk) {
            foreach ($this->ws->items('configurations', ['filter[name]' => '[' . implode('|', $chunk) . ']']) as $row) {
                $cfgRows[] = ['name' => $row['name'], 'value' => isset($row['value']) && !is_array($row['value']) ? $row['value'] : '', 'id_shop' => isset($row['id_shop']) ? $row['id_shop'] : 0, 'id_shop_group' => isset($row['id_shop_group']) ? $row['id_shop_group'] : 0];
            }
        }
        $shop = 1;
        if ($this->shopConfig() !== '' && ctype_digit($this->shopConfig())) {
            $shop = (int) $this->shopConfig();
        } else {
            $global = self::resolveConfig($cfgRows, 0, 0);
            $shop = isset($global['PS_SHOP_DEFAULT']) && (int) $global['PS_SHOP_DEFAULT'] > 0 ? (int) $global['PS_SHOP_DEFAULT'] : 1;
        }
        $env->shopId = $shop;
        $group = 0;
        $shops = $this->optional('shops', ['filter[id]' => '[' . $shop . ']']);
        if (isset($shops[0]['id_shop_group'])) {
            $group = (int) $shops[0]['id_shop_group'];
        }
        $cfg = self::resolveConfig($cfgRows, $shop, $group);
        $cfgLang = [];
        foreach ($this->optional('translated_configurations', ['filter[name]' => '[' . implode('|', self::CONFIG_LANG_NAMES) . ']']) as $row) {
            foreach (Webservice::lang(isset($row['value']) ? $row['value'] : []) as $langId => $text) {
                $resolved = self::resolveConfig([['name' => $row['name'], 'value' => $text, 'id_shop' => isset($row['id_shop']) ? $row['id_shop'] : 0, 'id_shop_group' => isset($row['id_shop_group']) ? $row['id_shop_group'] : 0]], $shop, $group);
                if (isset($resolved[(string) $row['name']])) {
                    $cfgLang[(string) $row['name']][$langId] = $resolved[(string) $row['name']];
                }
            }
        }
        $langs = $this->ws->all('languages', ['display' => '[id,iso_code,active]']);
        $currencies = $this->ws->all('currencies', ['display' => '[id,iso_code]']);
        $countries = $this->ws->all('countries', ['display' => '[id,id_zone,iso_code,active,contains_states]']);
        $states = $this->ws->all('states', ['display' => '[id,id_zone,id_country,iso_code,active]']);
        $taxes = [];
        $this->taxMeta = [];
        foreach ($this->optional('taxes', [], true) as $row) {
            $taxes[] = ['id' => $row['id'], 'rate' => $row['rate'], 'name' => Webservice::lang(isset($row['name']) ? $row['name'] : [])];
            $this->taxMeta[(int) $row['id']] = ['active' => Mapper::b(isset($row['active']) ? $row['active'] : 1), 'deleted' => Mapper::b(isset($row['deleted']) ? $row['deleted'] : 0)];
        }
        $orderStates = [];
        foreach ($this->optional('order_states', [], true) as $row) {
            $orderStates[] = ['id' => $row['id'], 'paid' => $row['paid'], 'shipped' => $row['shipped'], 'delivery' => $row['delivery'], 'logable' => $row['logable'], 'name' => Webservice::lang(isset($row['name']) ? $row['name'] : [])];
        }
        $groups = [];
        foreach ($this->optional('groups', [], true) as $row) {
            $groups[] = ['id' => $row['id'], 'reduction' => $row['reduction'], 'price_display_method' => $row['price_display_method'], 'name' => Webservice::lang(isset($row['name']) ? $row['name'] : [])];
        }
        $this->fillEnv($env, $cfg, $cfgLang, $langs, $currencies, $countries, $states, $taxes, $orderStates, $groups);
        $env->baseUrl = $this->ws->base();
        $last = $this->optional('order_invoices', ['display' => '[number]', 'sort' => '[number_DESC]'], false, 1);
        $number = isset($last[0]['number']) ? (int) $last[0]['number'] : 0;
        $env->nextInvoice = $number > 0 ? (string) ($number + 1) : '';
        $env->loaders = [
            'categories' => function (): array {
                $out = [];
                foreach ($this->ws->all('categories', ['display' => '[id,id_parent,is_root_category,link_rewrite]']) as $row) {
                    $out[(int) $row['id']] = ['parent' => (int) $row['id_parent'], 'root' => Mapper::b(isset($row['is_root_category']) ? $row['is_root_category'] : 0), 'rewrite' => Webservice::lang(isset($row['link_rewrite']) ? $row['link_rewrite'] : [])];
                }

                return $out;
            },
            'manufacturers' => function (): array {
                $out = [];
                foreach ($this->ws->all('manufacturers', ['display' => '[id,name]']) as $row) {
                    $out[(int) $row['id']] = (string) $row['name'];
                }

                return $out;
            },
            'suppliers' => function (): array {
                $out = [];
                foreach ($this->ws->all('suppliers', ['display' => '[id,name]']) as $row) {
                    $out[(int) $row['id']] = (string) $row['name'];
                }

                return $out;
            },
            'attribute_groups' => function (): array {
                $out = [];
                foreach ($this->ws->all('product_option_values', ['display' => '[id,id_attribute_group]']) as $row) {
                    $out[(int) $row['id']] = (int) $row['id_attribute_group'];
                }

                return $out;
            },
        ];

        return $env;
    }

    /**
     * Un recurso que no hace falta para leer: sin permiso (401) se queda vacío en vez de parar. Con «$every» pide todas las páginas.
     *
     * @param array<string,string> $query
     *
     * @return array<int,array<string,mixed>>
     */
    private function optional(string $resource, array $query = [], bool $every = false, ?int $count = null): array
    {
        try {
            return $every ? $this->ws->all($resource, $query) : $this->ws->items($resource, $query, 0, $count);
        } catch (SourceDenied $e) {
            return [];
        }
    }

    /** El servicio web no expone las páginas del sistema («meta»): la portada se queda sin título ni descripción. */
    protected function settingsMeta(): array
    {
        return ['home_title' => [], 'home_description' => []];
    }

    protected function zoneRows(): array
    {
        $out = [];
        foreach ($this->ws->all('zones') as $row) {
            $out[] = ['id' => $row['id'], 'name' => $row['name'], 'active' => $row['active']];
        }

        return $out;
    }

    // ------------------------------------------------------------------ lectura

    /**
     * Filtros del servidor de cada tipo (lo que la copia de la base de datos deja fuera con su WHERE).
     *
     * @return array<string,string>
     */
    private function filters(string $type): array
    {
        switch ($type) {
            case 'tax_classes':
                return ['filter[deleted]' => '0', 'filter[active]' => '1'];
            case 'categories':
                return ['filter[id_parent]' => '[1,999999999]'];
            case 'customers':
                return ['filter[deleted]' => '0', 'filter[id_shop]' => (string) $this->env()->shopId];
            case 'orders':
                return ['filter[id_shop]' => (string) $this->env()->shopId];
        }

        return [];
    }

    /**
     * Una tanda de un recurso: «limit=<desde>,<cuántos + 1>» (la fila de más dice si queda algo) y el cursor es la posición.
     *
     * @param array<string,string> $query
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string}
     */
    private function offsetPage(string $resource, array $query, string $cursor, int $limit): array
    {
        if ($cursor !== '' && preg_match('/^\d+$/', $cursor) !== 1) {
            throw new SourceError('Invalid cursor');
        }
        $offset = $cursor === '' ? 0 : (int) $cursor;
        $all = $this->ws->items($resource, $query, $offset, $limit + 1);
        $items = array_slice($all, 0, $limit);

        return [$items, count($all) > $limit ? (string) ($offset + $limit) : null];
    }

    protected function page(string $type, string $cursor, int $limit): array
    {
        $this->env();
        if ($type === 'settings') {
            return [[['id' => 'main']], null];
        }
        if ($type === 'attributes') {
            return $this->attributePage($cursor, $limit);
        }
        if ($type === 'shipping_zones') {
            list($zones, $next) = Batch::slice($this->zonesWithLocations(), $cursor, $limit);
            $offset = $cursor === '' ? 0 : (int) $cursor;
            foreach ($zones as $i => $zone) {
                $zones[$i]['position'] = $offset + $i;
            }

            return [$zones, $next];
        }
        if ($type === 'carriers') {
            list($rows, $next) = Batch::slice($this->carrierRows(), $cursor, $limit);

            return [$this->assembleCarriers($rows), $next];
        }
        if (!isset(self::RESOURCES[$type])) {
            throw new \InvalidArgumentException('Unknown type ' . $type);
        }
        list($items, $next) = $this->offsetPage(self::RESOURCES[$type], $this->filters($type), $cursor, $limit);

        return [$this->assemble($type, $items), $next];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function assemble(string $type, array $items): array
    {
        switch ($type) {
            case 'tax_classes':
                return $items;
            case 'tax_rates':
                return $this->taxRules($items);
            case 'customer_groups':
                return $this->langRows($items, ['name']);
            case 'brands':
                return $this->langRows($items, ['description', 'short_description', 'meta_title', 'meta_description']);
            case 'categories':
                return $this->langRows($items, ['name', 'description', 'link_rewrite', 'meta_title', 'meta_description']);
            case 'pages':
                return $this->langRows($items, ['meta_title', 'head_seo_title', 'meta_description', 'content', 'link_rewrite']);
            case 'products':
                return $this->products($items);
            case 'customers':
                return $this->customers($items);
            case 'coupons':
                return $this->coupons($items);
            case 'orders':
                return $this->orders($items);
            case 'team':
                foreach ($items as $i => $item) {
                    $items[$i]['profile'] = [];
                }

                return $items;
        }

        return $items;
    }

    /**
     * Pasa los campos con un texto por idioma a [id de idioma => texto].
     *
     * @param array<int,array<string,mixed>> $items
     * @param string[]                       $columns
     *
     * @return array<int,array<string,mixed>>
     */
    private function langRows(array $items, array $columns): array
    {
        foreach ($items as $i => $item) {
            foreach ($columns as $column) {
                $items[$i][$column] = Webservice::lang(isset($item[$column]) ? $item[$column] : []);
            }
        }

        return $items;
    }

    // ------------------------------------------------------------------ impuestos y envíos

    /**
     * @param array<int,array<string,mixed>> $items reglas de impuestos
     *
     * @return array<int,array<string,mixed>>
     */
    private function taxRules(array $items): array
    {
        $env = $this->env();
        $valid = [];
        foreach ($this->ws->all('tax_rule_groups', ['filter[deleted]' => '0', 'filter[active]' => '1', 'display' => '[id]']) as $row) {
            $valid[(int) $row['id']] = true;
        }
        $out = [];
        foreach ($items as $item) {
            $taxId = (int) $item['id_tax'];
            $meta = isset($this->taxMeta[$taxId]) ? $this->taxMeta[$taxId] : null;
            if (!isset($valid[(int) $item['id_tax_rules_group']]) || $meta === null || $meta['deleted'] || !isset($env->taxes[$taxId])) {
                continue;
            }
            $item['tax_rate'] = $env->taxes[$taxId]['rate'];
            $item['tax_name'] = $env->taxes[$taxId]['name'];
            $item['tax_active'] = $meta['active'];
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Todos los transportistas, los borrados también.
     *
     * @return array<int,array<string,mixed>>
     */
    private function everyCarrier(): array
    {
        if ($this->allCarriers === null) {
            $this->allCarriers = $this->ws->all('carriers');
        }

        return $this->allCarriers;
    }

    /**
     * Los transportistas vigentes (no borrados y activos), uno por «id_reference»: si se editó, el más nuevo.
     *
     * @return array<int,array<string,mixed>>
     */
    private function carrierRows(): array
    {
        $byReference = [];
        foreach ($this->everyCarrier() as $row) {
            if (Mapper::b(isset($row['deleted']) ? $row['deleted'] : 0) || !Mapper::b($row['active'])) {
                continue;
            }
            $reference = (int) $row['id_reference'] > 0 ? (int) $row['id_reference'] : (int) $row['id'];
            if (!isset($byReference[$reference]) || (int) $row['id'] > (int) $byReference[$reference]['id']) {
                $byReference[$reference] = $row;
            }
        }
        ksort($byReference);

        return array_values($byReference);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function assembleCarriers(array $rows): array
    {
        $ids = array_map(static function (array $row): int {
            return (int) $row['id'];
        }, $rows);
        $in = '[' . implode('|', $ids) . ']';
        $ranges = ['weight' => [], 'price' => []];
        foreach (['weight' => 'weight_ranges', 'price' => 'price_ranges'] as $kind => $resource) {
            foreach ($this->ws->all($resource, ['filter[id_carrier]' => $in]) as $range) {
                $ranges[$kind][(int) $range['id_carrier']][(int) $range['id']] = [$range['delimiter1'], $range['delimiter2']];
            }
        }
        $deliveries = [];
        foreach ($this->ws->all('deliveries', ['filter[id_carrier]' => $in]) as $delivery) {
            $deliveries[(int) $delivery['id_carrier']][] = $delivery;
        }
        $emitted = $this->emittedZones();
        foreach ($rows as $i => $row) {
            $id = (int) $row['id'];
            $rows[$i]['id_carrier'] = $id;
            $rows[$i]['delay'] = Webservice::lang(isset($row['delay']) ? $row['delay'] : []);
            $rows[$i]['tax_group'] = isset($row['id_tax_rules_group']) ? $row['id_tax_rules_group'] : 0;
            // El servicio web no da las zonas de un transportista (carrier_zone no se expone): salen de sus tarifas, que llevan la zona.
            $zoneIds = [];
            foreach (isset($deliveries[$id]) ? $deliveries[$id] : [] as $delivery) {
                if ((int) $delivery['id_zone'] > 0) {
                    $zoneIds[(int) $delivery['id_zone']] = (int) $delivery['id_zone'];
                }
            }
            ksort($zoneIds);
            $rows[$i]['zones'] = array_values($zoneIds);
            $rows[$i]['emitted_zones'] = $emitted;
            $rows[$i]['weight_ranges'] = isset($ranges['weight'][$id]) ? $ranges['weight'][$id] : [];
            $rows[$i]['price_ranges'] = isset($ranges['price'][$id]) ? $ranges['price'][$id] : [];
            $rows[$i]['deliveries'] = isset($deliveries[$id]) ? $deliveries[$id] : [];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ atributos

    /**
     * Los grupos de atributos y después las características, con un cursor «fase:posición».
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string}
     */
    private function attributePage(string $cursor, int $limit): array
    {
        $stage = 0;
        $offset = '';
        if ($cursor !== '') {
            if (preg_match('/^(\d+):(\d+)$/', $cursor, $m) !== 1) {
                throw new SourceError('Invalid cursor');
            }
            $stage = (int) $m[1];
            $offset = $m[2];
        }
        if ($stage === 0) {
            list($items, $next) = $this->offsetPage('product_options', [], $offset, $limit);
            $values = [];
            foreach ($this->ws->all('product_option_values') as $value) {
                $values[(int) $value['id_attribute_group']][] = $value;
            }
            $out = [];
            foreach ($items as $g) {
                $id = (int) $g['id'];
                $list = isset($values[$id]) ? $values[$id] : [];
                usort($list, static function (array $a, array $b): int {
                    return [(int) $a['position'], (int) $a['id']] <=> [(int) $b['position'], (int) $b['id']];
                });
                $out[] = [
                    'kind' => 'group', 'id' => $id, 'is_color_group' => isset($g['is_color_group']) ? $g['is_color_group'] : 0, 'group_type' => isset($g['group_type']) ? $g['group_type'] : '',
                    'position' => isset($g['position']) ? $g['position'] : 0,
                    'name' => Webservice::lang(isset($g['name']) ? $g['name'] : []), 'public_name' => Webservice::lang(isset($g['public_name']) ? $g['public_name'] : []),
                    'values' => array_map(static function (array $v): array {
                        return ['id' => (int) $v['id'], 'color' => isset($v['color']) ? (string) $v['color'] : '', 'position' => isset($v['position']) ? $v['position'] : 0, 'name' => Webservice::lang(isset($v['name']) ? $v['name'] : [])];
                    }, $list),
                ];
            }

            return [$out, $next !== null ? '0:' . $next : '1:0'];
        }
        list($items, $next) = $this->offsetPage('product_features', [], $offset, $limit);
        $values = [];
        foreach ($this->ws->all('product_feature_values', ['filter[custom]' => '0']) as $value) {
            $values[(int) $value['id_feature']][] = $value;
        }
        $out = [];
        foreach ($items as $f) {
            $id = (int) $f['id'];
            $out[] = [
                'kind' => 'feature', 'id' => $id, 'position' => isset($f['position']) ? $f['position'] : 0, 'name' => Webservice::lang(isset($f['name']) ? $f['name'] : []),
                'values' => array_map(static function (array $v): array {
                    return ['id' => (int) $v['id'], 'position' => isset($v['position']) ? $v['position'] : '', 'name' => Webservice::lang(isset($v['value']) ? $v['value'] : [])];
                }, isset($values[$id]) ? $values[$id] : []),
            ];
        }

        return [$out, $next !== null ? '1:' . $next : null];
    }

    // ------------------------------------------------------------------ productos

    /**
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function products(array $items): array
    {
        $ids = array_map(static function (array $item): int {
            return (int) $item['id'];
        }, $items);
        if ($ids === []) {
            return [];
        }
        $shop = $this->env()->shopId;
        $in = '[' . implode('|', $ids) . ']';
        $combinations = [];
        foreach ($this->ws->all('combinations', ['filter[id_product]' => $in]) as $row) {
            $row['values'] = Webservice::ids($row, 'product_option_values');
            $row['images'] = Webservice::ids($row, 'images');
            $combinations[(int) $row['id_product']][] = $row;
        }
        $stock = [];
        $outOfStock = [];
        foreach ($this->ws->all('stock_availables', ['filter[id_product]' => $in]) as $row) {
            $product = (int) $row['id_product'];
            $pa = (int) $row['id_product_attribute'];
            $rowShop = (int) (isset($row['id_shop']) ? $row['id_shop'] : 0);
            if ($rowShop !== 0 && $rowShop !== $shop) {
                continue;
            }
            if (!isset($stock[$product][$pa]) || $rowShop === $shop) {
                $stock[$product][$pa] = (int) $row['quantity'];
                if ($pa === 0) {
                    $outOfStock[$product] = (int) $row['out_of_stock'];
                }
            }
        }
        $specific = [];
        foreach ($this->ws->all('specific_prices', ['filter[id_product]' => $in]) as $row) {
            $rowShop = (int) (isset($row['id_shop']) ? $row['id_shop'] : 0);
            if ((int) (isset($row['id_cart']) ? $row['id_cart'] : 0) === 0 && ($rowShop === 0 || $rowShop === $shop)) {
                $specific[(int) $row['id_product']][] = $row;
            }
        }
        $suppliers = [];
        foreach ($this->ws->all('product_suppliers', ['filter[id_product]' => $in]) as $row) {
            $suppliers[(int) $row['id_product']][] = $row;
        }
        $tagIds = [];
        $valueIds = [];
        $attachmentIds = [];
        foreach ($items as $item) {
            $tagIds = array_merge($tagIds, Webservice::ids($item, 'tags'));
            foreach (Webservice::association($item, 'product_features') as $feature) {
                $valueIds[] = (int) (isset($feature['id_feature_value']) ? $feature['id_feature_value'] : 0);
            }
            $attachmentIds = array_merge($attachmentIds, Webservice::ids($item, 'attachments'));
        }
        $tags = [];
        foreach ($this->byIds('tags', $tagIds) as $row) {
            $tags[(int) $row['id']] = $row;
        }
        $values = [];
        foreach ($this->byIds('product_feature_values', $valueIds) as $row) {
            $values[(int) $row['id']] = $row;
        }
        $attachments = [];
        foreach ($this->byIds('attachments', $attachmentIds) as $row) {
            $row['name'] = Webservice::lang(isset($row['name']) ? $row['name'] : []);
            $attachments[(int) $row['id']] = $row;
        }
        $out = [];
        foreach ($items as $item) {
            $id = (int) $item['id'];
            $raw = $item;
            unset($raw['associations']);
            foreach (['name', 'description', 'description_short', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords'] as $column) {
                $raw[$column] = Webservice::lang(isset($item[$column]) ? $item[$column] : []);
            }
            $raw['id'] = $id;
            $raw['categories'] = Webservice::ids($item, 'categories');
            $cover = (int) (isset($item['id_default_image']) ? $item['id_default_image'] : 0);
            $raw['images'] = array_map(static function (int $imageId) use ($cover): array {
                return ['id' => $imageId, 'cover' => $imageId === $cover];
            }, Webservice::ids($item, 'images'));
            $raw['combinations'] = isset($combinations[$id]) ? $combinations[$id] : [];
            $raw['stock'] = isset($stock[$id]) ? $stock[$id] : [];
            $raw['out_of_stock'] = isset($outOfStock[$id]) ? $outOfStock[$id] : 2;
            $raw['features'] = [];
            foreach (Webservice::association($item, 'product_features') as $feature) {
                $valueId = (int) (isset($feature['id_feature_value']) ? $feature['id_feature_value'] : 0);
                $custom = isset($values[$valueId]) && Mapper::b(isset($values[$valueId]['custom']) ? $values[$valueId]['custom'] : 0);
                $raw['features'][] = [
                    'feature' => (int) $feature['id'], 'value' => $valueId, 'custom' => $custom,
                    'text' => $custom ? Webservice::lang(isset($values[$valueId]['value']) ? $values[$valueId]['value'] : []) : [],
                ];
            }
            $raw['tags'] = [];
            foreach (Webservice::ids($item, 'tags') as $tagId) {
                if (isset($tags[$tagId])) {
                    $raw['tags'][(int) $tags[$tagId]['id_lang']][] = (string) $tags[$tagId]['name'];
                }
            }
            $raw['accessories'] = Webservice::ids($item, 'accessories');
            $raw['specific_prices'] = isset($specific[$id]) ? $specific[$id] : [];
            $raw['attachments'] = [];
            foreach (Webservice::ids($item, 'attachments') as $attachmentId) {
                if (isset($attachments[$attachmentId])) {
                    $raw['attachments'][] = $attachments[$attachmentId];
                }
            }
            $raw['download'] = null;
            $raw['suppliers'] = isset($suppliers[$id]) ? $suppliers[$id] : [];
            $raw['pack'] = [];
            foreach (Webservice::association($item, 'product_bundle') as $bundle) {
                $raw['pack'][] = ['product' => (int) $bundle['id'], 'combination' => (int) (isset($bundle['id_product_attribute']) ? $bundle['id_product_attribute'] : 0), 'qty' => (int) $bundle['quantity']];
            }
            $out[] = $raw;
        }

        return $out;
    }

    /**
     * Elementos de un recurso por sus identificadores.
     *
     * @param array<int,int> $ids
     *
     * @return array<int,array<string,mixed>>
     */
    private function byIds(string $resource, array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (array_chunk($ids, 50) as $chunk) {
            foreach ($this->ws->all($resource, ['filter[id]' => '[' . implode('|', $chunk) . ']']) as $row) {
                $out[] = $row;
            }
        }

        return $out;
    }

    // ------------------------------------------------------------------ clientes, cupones, pedidos

    /**
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function customers(array $items): array
    {
        $ids = array_map(static function (array $item): int {
            return (int) $item['id'];
        }, $items);
        $addresses = [];
        if ($ids !== []) {
            foreach ($this->ws->all('addresses', ['filter[id_customer]' => '[' . implode('|', $ids) . ']', 'filter[deleted]' => '0']) as $row) {
                $addresses[(int) $row['id_customer']][] = $row;
            }
        }
        foreach ($items as $i => $item) {
            $customerAddresses = isset($addresses[(int) $item['id']]) ? $addresses[(int) $item['id']] : [];
            usort($customerAddresses, static function (array $a, array $b): int {
                return (int) $a['id'] <=> (int) $b['id'];
            });
            unset($items[$i]['associations']);
            $items[$i]['addresses'] = $customerAddresses;
            $items[$i]['group_ids'] = Webservice::ids($item, 'groups');
        }

        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function coupons(array $items): array
    {
        $ids = array_map(static function (array $item): int {
            return (int) $item['id'];
        }, $items);
        $uses = [];
        if ($ids !== []) {
            foreach ($this->ws->all('order_cart_rules', ['filter[id_cart_rule]' => '[' . implode('|', $ids) . ']', 'display' => '[id,id_cart_rule]']) as $row) {
                $rule = (int) $row['id_cart_rule'];
                $uses[$rule] = (isset($uses[$rule]) ? $uses[$rule] : 0) + 1;
            }
        }
        foreach ($items as $i => $item) {
            $id = (int) $item['id'];
            $items[$i]['name'] = Webservice::lang(isset($item['name']) ? $item['name'] : []);
            $items[$i]['uses'] = isset($uses[$id]) ? $uses[$id] : 0;
            // El servicio web no da las reglas de producto de un cupón (cart_rule_product_rule*): sin ellas, un cupón con
            // «product_restriction» sale marcado como no soportado (OrderMapper::coupon) y se cuenta en unmapped().
            $items[$i]['rule_groups'] = [];
            unset($items[$i]['associations']);
        }

        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     *
     * @return array<int,array<string,mixed>>
     */
    private function orders(array $items): array
    {
        $ids = array_map(static function (array $item): int {
            return (int) $item['id'];
        }, $items);
        if ($ids === []) {
            return [];
        }
        $in = '[' . implode('|', $ids) . ']';
        $details = [];
        foreach ($this->ws->all('order_details', ['filter[id_order]' => $in]) as $row) {
            $row['id_order_detail'] = $row['id'];
            $row['tax_ids'] = Webservice::ids($row, 'taxes');
            unset($row['associations']);
            $details[(int) $row['id_order']][] = $row;
        }
        $history = $this->groupBy($this->ws->all('order_histories', ['filter[id_order]' => $in]), 'id_order');
        $references = array_values(array_unique(array_filter(array_map(static function (array $item): string {
            return (string) $item['reference'];
        }, $items), static function (string $r): bool {
            return $r !== '';
        })));
        $payments = [];
        if ($references !== []) {
            $payments = $this->groupBy($this->ws->all('order_payments', ['filter[order_reference]' => '[' . implode('|', $references) . ']']), 'order_reference');
        }
        $invoices = $this->groupBy($this->ws->all('order_invoices', ['filter[id_order]' => $in]), 'id_order');
        $slips = [];
        foreach ($this->ws->all('order_slip', ['filter[id_order]' => $in]) as $row) {
            $row['details'] = array_map(static function (array $d): array {
                return ['id_order_detail' => $d['id'], 'product_quantity' => isset($d['product_quantity']) ? $d['product_quantity'] : 0, 'amount_tax_excl' => isset($d['amount_tax_excl']) ? $d['amount_tax_excl'] : 0, 'amount_tax_incl' => isset($d['amount_tax_incl']) ? $d['amount_tax_incl'] : 0];
            }, Webservice::association($row, 'order_slip_details'));
            unset($row['associations']);
            $slips[(int) $row['id_order']][] = $row;
        }
        $carrierRows = $this->groupBy($this->ws->all('order_carriers', ['filter[id_order]' => $in]), 'id_order');
        $rules = $this->groupBy($this->ws->all('order_cart_rules', ['filter[id_order]' => $in]), 'id_order');
        $ruleIds = [];
        foreach ($rules as $list) {
            foreach ($list as $row) {
                $ruleIds[] = (int) $row['id_cart_rule'];
            }
        }
        $codes = [];
        foreach ($this->byIds('cart_rules', $ruleIds) as $row) {
            $codes[(int) $row['id']] = (string) (isset($row['code']) ? $row['code'] : '');
        }
        $customerIds = array_map(static function (array $item): int {
            return (int) $item['id_customer'];
        }, $items);
        $emails = [];
        foreach ($this->byIds('customers', $customerIds) as $row) {
            $emails[(int) $row['id']] = (string) $row['email'];
        }
        $addressIds = [];
        foreach ($items as $item) {
            $addressIds[] = (int) $item['id_address_invoice'];
            $addressIds[] = (int) $item['id_address_delivery'];
        }
        $addresses = [];
        foreach ($this->byIds('addresses', $addressIds) as $row) {
            $addresses[(int) $row['id']] = $row;
        }
        $carriers = [];
        foreach ($this->everyCarrier() as $row) {
            $carriers[(int) $row['id']] = ['id_reference' => $row['id_reference'], 'name' => $row['name']];
        }
        $out = [];
        foreach ($items as $item) {
            $id = (int) $item['id'];
            $raw = $item;
            unset($raw['associations']);
            $raw['lines'] = isset($details[$id]) ? $details[$id] : [];
            $raw['history'] = isset($history[$id]) ? $history[$id] : [];
            $raw['payments'] = isset($payments[(string) $item['reference']]) ? $payments[(string) $item['reference']] : [];
            $raw['invoices'] = isset($invoices[$id]) ? $invoices[$id] : [];
            $raw['slips'] = isset($slips[$id]) ? $slips[$id] : [];
            $raw['order_carriers'] = isset($carrierRows[$id]) ? $carrierRows[$id] : [];
            $raw['cart_rules'] = array_map(static function (array $c) use ($codes): array {
                $c['code'] = isset($codes[(int) $c['id_cart_rule']]) ? $codes[(int) $c['id_cart_rule']] : '';

                return $c;
            }, isset($rules[$id]) ? $rules[$id] : []);
            $raw['email'] = isset($emails[(int) $item['id_customer']]) ? $emails[(int) $item['id_customer']] : '';
            $raw['invoice_address'] = isset($addresses[(int) $item['id_address_invoice']]) ? $addresses[(int) $item['id_address_invoice']] : null;
            $raw['delivery_address'] = isset($addresses[(int) $item['id_address_delivery']]) ? $addresses[(int) $item['id_address_delivery']] : null;
            $raw['carrier'] = isset($carriers[(int) $item['id_carrier']]) ? $carriers[(int) $item['id_carrier']] : null;
            $out[] = $raw;
        }

        return $out;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function groupBy(array $rows, string $column): array
    {
        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row[$column]][] = $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------ comprobar, contar, sumas de control, lo que no se trae

    /**
     * Cuántos hay de un recurso (con los filtros del tipo): se piden solo los identificadores.
     *
     * @param array<string,string> $query
     */
    private function countOf(string $resource, array $query = []): int
    {
        return count($this->ws->all($resource, ['display' => '[id]'] + $query, 1000));
    }

    public function count(string $type): ?int
    {
        if ($type === 'settings') {
            return 1;
        }
        if ($type === 'shipping_zones') {
            $this->env();

            return count($this->zonesWithLocations());
        }
        if ($type === 'carriers') {
            return count($this->carrierRows());
        }
        if ($type === 'attributes') {
            return $this->countOf('product_options') + $this->countOf('product_features');
        }
        if ($type === 'tax_rates') {
            $this->env();

            return count($this->taxRules($this->ws->all('tax_rules')));
        }
        if (!isset(self::RESOURCES[$type])) {
            return null;
        }

        return $this->countOf(self::RESOURCES[$type], $this->filters($type));
    }

    public function probe(): array
    {
        $result = ['ok' => false, 'message' => '', 'version' => '', 'shops' => [], 'languages' => [], 'currency' => '', 'warnings' => [], 'counts' => [], 'unavailable' => []];
        try {
            $env = $this->env();
            if ($env->version === '' || version_compare($env->version, '1.7', '<')) {
                $result['version'] = 'PrestaShop ' . ($env->version !== '' ? $env->version : '?');
                $result['message'] = 'This PrestaShop version is not supported: 1.7 or later is needed';

                return $result;
            }
            foreach (self::RESOURCES as $type => $resource) {
                if ($type !== 'settings' && !$this->ws->allowed($resource)) {
                    $this->unavailable[$type] = true;
                    $result['unavailable'][] = $type;
                }
            }
            $products = isset($this->unavailable['products']) ? 0 : $this->count('products');
            $first = isset($this->unavailable['products']) ? ['records' => []] : $this->read('products', '', 1);
            $result['counts']['products'] = $products;
            foreach (['categories', 'customers', 'orders'] as $type) {
                if (!isset($this->unavailable[$type])) {
                    $result['counts'][$type] = (int) $this->count($type);
                }
            }
            $customers = isset($this->unavailable['customers']) ? [] : $this->ws->items('customers', ['filter[deleted]' => '0', 'display' => '[id,passwd,is_guest]'], 0, 50);
            $shops = [];
            foreach ($this->ws->all('shops', ['display' => '[id,name]']) as $row) {
                $shops[] = ['id' => (string) $row['id'], 'name' => (string) $row['name']];
            }
        } catch (SourceDenied $e) {
            $result['message'] = 'Access was denied: check the web service key and its permissions';

            return $result;
        } catch (SourceError $e) {
            $result['message'] = 'The shop could not be reached through its web service';

            return $result;
        }
        $result['version'] = 'PrestaShop ' . $env->version;
        $result['languages'] = array_values($env->langs);
        $result['currency'] = isset($env->currencies[$env->cfgInt('PS_CURRENCY_DEFAULT')]) ? $env->currencies[$env->cfgInt('PS_CURRENCY_DEFAULT')] : '';
        if (count($shops) > 1) {
            $result['shops'] = $shops;
            $result['warnings'][] = 'The shop is multistore: only the chosen shop is read';
        }
        $old = 0;
        foreach ($customers as $customer) {
            if (!Mapper::b(isset($customer['is_guest']) ? $customer['is_guest'] : 0) && strncmp((string) ($customer['passwd'] ?? ''), '$2', 2) !== 0 && (string) ($customer['passwd'] ?? '') !== '') {
                ++$old;
            }
        }
        if ($old > 0 && $env->cfg('_cookie_key_given') !== '1') {
            $result['warnings'][] = 'Some customers have old PrestaShop passwords: without the cookie key they will have to reset them';
        }
        if (count($env->currencies) > 1) {
            $result['warnings'][] = 'The shop uses more than one currency: only the default one is kept';
        }
        if ($result['unavailable'] !== []) {
            $result['warnings'][] = 'Some resources are not available with this key: those types are not read';
        }
        if ($products < 1 || $first['records'] === []) {
            $result['message'] = 'The web service answered but the shop has no products to import';

            return $result;
        }
        $result['ok'] = true;
        $result['message'] = 'Connected to the web service';

        return $result;
    }

    public function checksums(): array
    {
        $sums = [];
        foreach (['products' => 'products', 'categories' => 'categories', 'customers' => 'customers', 'orders' => 'orders', 'coupons' => 'coupons', 'tax_rates' => 'tax_rates',
            'pages' => 'pages', 'carriers' => 'carriers', 'brands' => 'brands', 'customer_groups' => 'customer_groups'] as $key => $type) {
            if (!isset($this->unavailable[$type])) {
                $sums[$key . '_count'] = (int) $this->count($type);
            }
        }
        $sums['variants_count'] = $this->countOf('combinations');
        $sums['addresses_count'] = $this->countOf('addresses', ['filter[deleted]' => '0', 'filter[id_customer]' => '[1,999999999]']);
        $sums['order_items_count'] = $this->countOf('order_details');
        $total = 0;
        $valid = 0;
        foreach ($this->ws->all('orders', ['display' => '[id,total_paid_tax_incl,valid]'] + $this->filters('orders'), 500) as $row) {
            $total += Neutral::cents($row['total_paid_tax_incl']);
            $valid += Mapper::b(isset($row['valid']) ? $row['valid'] : 0) ? 1 : 0;
        }
        $sums['orders_total'] = $total;
        $sums['orders_valid_count'] = $valid;
        $shop = $this->env()->shopId;
        $stock = [];
        foreach ($this->ws->all('stock_availables', ['display' => '[id_product,id_product_attribute,quantity,id_shop]', 'filter[id_product_attribute]' => '0'], 1000) as $row) {
            $rowShop = (int) (isset($row['id_shop']) ? $row['id_shop'] : 0);
            $product = (int) $row['id_product'];
            if (($rowShop === 0 || $rowShop === $shop) && (!isset($stock[$product]) || $rowShop === $shop)) {
                $stock[$product] = (int) $row['quantity'];
            }
        }
        $sums['stock_total'] = array_sum($stock);

        return $sums;
    }

    public function unmapped(): array
    {
        $out = [];
        $counts = [
            ['suppliers', 'suppliers', [], 'Suppliers have no equivalent'],
            ['physical stores', 'stores', [], 'Physical stores have no equivalent'],
            ['warehouses', 'warehouses', [], 'Warehouses and advanced stock have no equivalent'],
            ['customer service messages', 'customer_messages', [], 'Customer messages are not brought'],
            ['abandoned carts', 'carts', ['filter[id_customer]' => '[1,999999999]'], 'Carts are not brought (abandoned ones included)'],
        ];
        foreach ($counts as $item) {
            try {
                $count = $this->countOf($item[1], $item[2]);
            } catch (SourceDenied $e) {
                continue;
            }
            if ($count > 0) {
                $out[] = ['kind' => 'feature', 'name' => $item[0], 'count' => $count, 'note' => $item[3]];
            }
        }
        try {
            $quantity = 0;
            $group = 0;
            $country = 0;
            foreach ($this->ws->all('specific_prices', ['display' => '[id,id_cart,from_quantity,id_group,id_country]'], 1000) as $row) {
                if ((int) $row['id_cart'] !== 0) {
                    continue;
                }
                $quantity += (int) $row['from_quantity'] > 1 ? 1 : 0;
                $group += (int) $row['id_group'] > 0 ? 1 : 0;
                $country += (int) $row['id_country'] > 0 ? 1 : 0;
            }
            foreach ([['quantity discounts', $quantity, 'Discounts by quantity are kept in the extra data of the product'], ['prices by customer group', $group, 'Prices for one customer group are kept in the extra data of the product'],
                ['prices by country', $country, 'Prices for one country are kept in the extra data of the product']] as $item) {
                if ($item[1] > 0) {
                    $out[] = ['kind' => 'feature', 'name' => $item[0], 'count' => $item[1], 'note' => $item[2]];
                }
            }
        } catch (SourceDenied $e) {
            // sin permiso para los precios específicos: no se cuentan
        }
        try {
            // Dos cosas que el servicio web no expone: las zonas de un transportista y las reglas de producto de un cupón.
            $zoned = [];
            foreach ($this->ws->all('deliveries', ['display' => '[id,id_carrier,id_zone]'], 1000) as $row) {
                if ((int) $row['id_zone'] > 0) {
                    $zoned[(int) $row['id_carrier']] = true;
                }
            }
            $withoutZones = 0;
            foreach ($this->carrierRows() as $row) {
                $withoutZones += isset($zoned[(int) $row['id']]) ? 0 : 1;
            }
            if ($withoutZones > 0) {
                $out[] = ['kind' => 'feature', 'name' => 'carriers without zones', 'count' => $withoutZones, 'note' => 'The web service does not give the zones of a carrier: they are taken from its price tables, so a carrier without prices is brought without zones'];
            }
            $restricted = $this->countOf('cart_rules', ['filter[product_restriction]' => '1']);
            if ($restricted > 0) {
                $out[] = ['kind' => 'feature', 'name' => 'coupons with product restrictions', 'count' => $restricted, 'note' => 'The web service does not give the product rules of a coupon: they are brought as not supported, use a copy of the database to bring them'];
            }
        } catch (SourceDenied $e) {
            // sin permiso para los transportistas o los cupones: no se cuentan
        }
        $out[] = ['kind' => 'feature', 'name' => 'installed modules', 'count' => 0, 'note' => 'The web service does not list the installed modules: use a copy of the database to see them'];

        return $out;
    }
}
