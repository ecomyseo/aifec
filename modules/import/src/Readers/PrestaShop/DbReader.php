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
use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;

/**
 * El lector de PrestaShop 1.7, 8 y 9 de una copia de su base de datos. Solo ejecuta SELECT, pagina por clave, lee de las tablas
 * «_shop» para la tienda elegida y se adapta a lo que cambia entre versiones (comprueba qué tablas y columnas existen).
 */
final class DbReader extends BaseReader
{
    /** Módulos de pago que se saben leer: módulo => [código, título, prefijo de su configuración]. */
    private const PAYMENT_MODULES = [
        'ps_wirepayment' => ['bank_transfer', 'Bank transfer', 'BANK_WIRE_'],
        'ps_checkpayment' => ['cheque', 'Payment by cheque', 'CHEQUE_'],
        'ps_cashondelivery' => ['cash_on_delivery', 'Cash on delivery', ''],
        'paypal' => ['paypal', 'PayPal', 'PAYPAL_'],
        'redsys' => ['redsys', 'Card (Redsys)', 'REDSYS_'],
        'redsysoficial' => ['redsys', 'Card (Redsys)', 'REDSYS_'],
        'ps_redsys' => ['redsys', 'Card (Redsys)', 'REDSYS_'],
    ];

    /** Módulos que vienen con PrestaShop o que ya se traen por otro camino: no se cuentan como «sin equivalente». */
    private const KNOWN_MODULES = [
        'productcomments', 'ps_emailsubscription', 'paypal', 'redsys', 'redsysoficial', 'ps_wirepayment', 'ps_checkpayment', 'ps_cashondelivery',
        'blockreassurance', 'contactform', 'dashactivity', 'dashgoals', 'dashproducts', 'dashtrends', 'graphnvd3', 'gamification', 'pagesnotfound',
        'sekeywords', 'welcome', 'psgdpr', 'autoupgrade', 'blockwishlist', 'wishlist',
    ];

    /** @var Database */
    private $db;

    /** @var int|null */
    private $shop;

    /** @var array<int,mixed>|null */
    private $shopRow;

    /**
     * @param \PDO                $connection copia de la base de datos de PrestaShop, de solo lectura
     * @param array<string,mixed> $config     prefix, shop, files, base_url, cookie_key, timezone, now
     */
    public function __construct($connection, array $config)
    {
        if (!$connection instanceof \PDO) {
            throw new \InvalidArgumentException('The database reader needs a PDO connection');
        }
        parent::__construct($config);
        $this->db = new Database($connection, isset($config['prefix']) ? (string) $config['prefix'] : 'ps_');
    }

    public static function describe(): array
    {
        return [
            'source' => 'prestashop',
            'mode' => 'db',
            'label' => 'PrestaShop (copy of the database)',
            'help' => 'Reads a copy of the shop database (PrestaShop 1.7, 8 or 9). Nothing is written to it. For the images, give the folder with the shop files or the address of the shop.',
            'fields' => [
                'prefix' => ['type' => 'text', 'label' => 'Table prefix', 'required' => true, 'hint' => 'Usually ps_', 'default' => 'ps_'],
                'shop' => ['type' => 'text', 'label' => 'Shop (multistore)', 'required' => false, 'hint' => 'The shop number to read. Empty: the default shop', 'default' => ''],
                'files' => ['type' => 'path', 'label' => 'Folder with the shop files', 'required' => false, 'hint' => 'The folder that has img/ and download/. Without it the images are downloaded from the shop address'],
                'base_url' => ['type' => 'url', 'label' => 'Shop address', 'required' => false, 'hint' => 'For the images that are not in the folder'],
                'cookie_key' => ['type' => 'secret', 'label' => 'Cookie key (cookie_key in app/config/parameters.php)', 'required' => false, 'hint' => 'Only needed so that the old customer passwords keep working'],
                'timezone' => ['type' => 'text', 'label' => 'Time zone', 'required' => false, 'hint' => 'Empty: the one of the shop settings', 'default' => ''],
            ],
        ];
    }

    public function mode(): string
    {
        return 'db';
    }

    public function key(): string
    {
        $name = '';
        try {
            $name = Mapper::s($this->db->value('SELECT DATABASE()'));
        } catch (SourceError $e) {
            $name = 'db';
        }

        return mb_substr(($name !== '' ? $name : 'db') . ':' . $this->db->prefix() . ':' . $this->shopId(), 0, 60);
    }

    // ------------------------------------------------------------------ la tienda y su entorno

    private function shopId(): int
    {
        if ($this->shop === null) {
            $given = isset($this->config['shop']) ? trim((string) $this->config['shop']) : '';
            if ($given !== '' && ctype_digit($given) && (int) $given > 0) {
                $this->shop = (int) $given;
            } else {
                $value = $this->db->tableExists('configuration')
                    ? $this->scalar('SELECT value FROM {c} WHERE name = ? AND (id_shop IS NULL OR id_shop = 0) AND (id_shop_group IS NULL OR id_shop_group = 0)', ['PS_SHOP_DEFAULT'])
                    : null;
                $this->shop = $value !== null && (int) $value > 0 ? (int) $value : 1;
            }
        }

        return $this->shop;
    }

    /** Una consulta con los nombres de tabla entre llaves («{product}») con el prefijo puesto. @param array<int,int|string> $params */
    private function q(string $sql): string
    {
        return (string) preg_replace_callback('/\{(\w+)\}/', function (array $m): string {
            return $this->db->t($m[1] === 'c' ? 'configuration' : $m[1]);
        }, $sql);
    }

    /**
     * @param array<int,int|string> $params
     *
     * @return array<int,array<string,mixed>>
     */
    private function rows(string $sql, array $params = []): array
    {
        return $this->db->rows($this->q($sql), $params);
    }

    /**
     * @param array<int,int|string> $params
     */
    private function one(string $sql, array $params = []): ?array
    {
        return $this->db->row($this->q($sql), $params);
    }

    /**
     * @param array<int,int|string> $params
     *
     * @return mixed
     */
    private function scalar(string $sql, array $params = [])
    {
        return $this->db->value($this->q($sql), $params);
    }

    /** ¿Existe la tabla «_shop» (cambia entre versiones y entre instalaciones)? Devuelve la unión y su valor, o nada. @return array{0:string,1:array<int,int>} */
    private function shopJoin(string $table, string $idColumn, string $alias, string $on): array
    {
        if (!$this->db->tableExists($table)) {
            return ['', []];
        }

        return [' INNER JOIN {' . $table . '} ' . $alias . ' ON ' . $alias . '.' . $idColumn . ' = ' . $on . ' AND ' . $alias . '.id_shop = ?', [$this->shopId()]];
    }

    protected function buildEnv(): Env
    {
        $env = new Env();
        $shop = $this->shopId();
        $env->shopId = $shop;
        $group = 0;
        $domain = '';
        if ($this->db->tableExists('shop')) {
            $row = $this->one('SELECT * FROM {shop} WHERE id_shop = ?', [$shop]);
            $group = $row !== null ? (int) ($row['id_shop_group'] ?? 0) : 0;
        }
        if ($this->db->tableExists('shop_url')) {
            $row = $this->one('SELECT * FROM {shop_url} WHERE id_shop = ? AND main = 1', [$shop]);
            if ($row !== null) {
                $host = Mapper::s($row['domain_ssl'] ?? '') !== '' ? 'https://' . Mapper::s($row['domain_ssl']) : 'http://' . Mapper::s($row['domain'] ?? '');
                $domain = rtrim($host . '/' . trim(Mapper::s($row['physical_uri'] ?? '/'), '/'), '/');
            }
        }
        $names = self::CONFIG_NAMES;
        $cfg = self::resolveConfig($this->rows(
            'SELECT name, value, id_shop, id_shop_group FROM {c} WHERE name IN (' . $this->db->marks(count($names)) . ')',
            $names
        ), $shop, $group);
        $cfgLang = [];
        $langNames = self::CONFIG_LANG_NAMES;
        $byConfig = [];
        foreach ($this->rows(
            'SELECT c.name, c.id_shop, c.id_shop_group, l.id_lang, l.value FROM {c} c INNER JOIN {configuration_lang} l ON l.id_configuration = c.id_configuration WHERE c.name IN (' . $this->db->marks(count($langNames)) . ')',
            $langNames
        ) as $row) {
            $byConfig[(string) $row['name'] . '|' . (int) $row['id_lang']][] = ['name' => $row['name'], 'value' => $row['value'], 'id_shop' => $row['id_shop'], 'id_shop_group' => $row['id_shop_group']];
        }
        foreach ($byConfig as $key => $rows) {
            $parts = explode('|', $key);
            $resolved = self::resolveConfig($rows, $shop, $group);
            if (isset($resolved[$parts[0]])) {
                $cfgLang[$parts[0]][(int) $parts[1]] = $resolved[$parts[0]];
            }
        }
        $langs = $this->rows('SELECT * FROM {lang}');
        if ($this->db->tableExists('lang_shop')) {
            $inShop = [];
            foreach ($this->rows('SELECT id_lang FROM {lang_shop} WHERE id_shop = ?', [$shop]) as $row) {
                $inShop[(int) $row['id_lang']] = true;
            }
            $langs = array_values(array_filter($langs, static function (array $lang) use ($inShop): bool {
                return isset($inShop[(int) $lang['id_lang']]);
            }));
        }
        $langs = array_map(static function (array $row): array {
            return ['id' => $row['id_lang'], 'iso_code' => $row['iso_code'], 'active' => $row['active']];
        }, $langs);
        $currencies = array_map(static function (array $row): array {
            return ['id' => $row['id_currency'], 'iso_code' => $row['iso_code']];
        }, $this->rows('SELECT id_currency, iso_code FROM {currency}'));
        $countries = array_map(static function (array $row): array {
            return ['id' => $row['id_country'], 'id_zone' => $row['id_zone'], 'iso_code' => $row['iso_code'], 'active' => $row['active'], 'contains_states' => $row['contains_states']];
        }, $this->rows('SELECT id_country, id_zone, iso_code, active, contains_states FROM {country}'));
        $states = array_map(static function (array $row): array {
            return ['id' => $row['id_state'], 'id_country' => $row['id_country'], 'id_zone' => $row['id_zone'], 'iso_code' => $row['iso_code'], 'active' => $row['active']];
        }, $this->rows('SELECT id_state, id_country, id_zone, iso_code, active FROM {state}'));
        $taxLang = [];
        foreach ($this->rows('SELECT id_tax, id_lang, name FROM {tax_lang}') as $row) {
            $taxLang[(int) $row['id_tax']][(int) $row['id_lang']] = (string) $row['name'];
        }
        $taxes = [];
        foreach ($this->rows('SELECT id_tax, rate FROM {tax}') as $row) {
            $taxes[] = ['id' => $row['id_tax'], 'rate' => $row['rate'], 'name' => isset($taxLang[(int) $row['id_tax']]) ? $taxLang[(int) $row['id_tax']] : []];
        }
        $stateLang = [];
        foreach ($this->rows('SELECT id_order_state, id_lang, name FROM {order_state_lang}') as $row) {
            $stateLang[(int) $row['id_order_state']][(int) $row['id_lang']] = (string) $row['name'];
        }
        $orderStates = [];
        foreach ($this->rows('SELECT * FROM {order_state}') as $row) {
            $orderStates[] = [
                'id' => $row['id_order_state'], 'paid' => $row['paid'], 'shipped' => $row['shipped'], 'delivery' => $row['delivery'], 'logable' => $row['logable'],
                'name' => isset($stateLang[(int) $row['id_order_state']]) ? $stateLang[(int) $row['id_order_state']] : [],
            ];
        }
        $groupLang = [];
        foreach ($this->rows('SELECT id_group, id_lang, name FROM `{group_lang}`') as $row) {
            $groupLang[(int) $row['id_group']][(int) $row['id_lang']] = (string) $row['name'];
        }
        $groups = [];
        foreach ($this->rows('SELECT * FROM `{group}`') as $row) {
            $groups[] = [
                'id' => $row['id_group'], 'reduction' => $row['reduction'], 'price_display_method' => $row['price_display_method'],
                'name' => isset($groupLang[(int) $row['id_group']]) ? $groupLang[(int) $row['id_group']] : [],
            ];
        }
        $this->fillEnv($env, $cfg, $cfgLang, $langs, $currencies, $countries, $states, $taxes, $orderStates, $groups);
        $given = isset($this->config['base_url']) ? trim((string) $this->config['base_url']) : '';
        $env->baseUrl = rtrim($given !== '' ? $given : $domain, '/');
        $last = $this->scalar('SELECT MAX(number) FROM {order_invoice}');
        $env->nextInvoice = $last !== null && (int) $last > 0 ? (string) ((int) $last + 1) : '';
        $files = isset($this->config['files']) ? rtrim(str_replace('\\', '/', (string) $this->config['files']), '/') : '';
        $env->image = function (string $relative) use ($files, $env): array {
            if ($files !== '' && is_file($files . '/' . $relative)) {
                return ['path' => $relative];
            }

            return ['url' => $env->baseUrl . '/' . $relative];
        };
        $env->loaders = $this->loaders($shop);

        return $env;
    }

    /**
     * @return array<string,callable>
     */
    private function loaders(int $shop): array
    {
        return [
            'categories' => function () use ($shop): array {
                $out = [];
                foreach ($this->rows('SELECT * FROM {category}') as $row) {
                    $out[(int) $row['id_category']] = ['parent' => (int) $row['id_parent'], 'root' => Mapper::b($row['is_root_category'] ?? 0), 'rewrite' => []];
                }
                foreach ($this->rows('SELECT id_category, id_lang, link_rewrite FROM {category_lang} WHERE id_shop = ?', [$shop]) as $row) {
                    if (isset($out[(int) $row['id_category']])) {
                        $out[(int) $row['id_category']]['rewrite'][(int) $row['id_lang']] = (string) $row['link_rewrite'];
                    }
                }

                return $out;
            },
            'manufacturers' => function (): array {
                $out = [];
                foreach ($this->rows('SELECT id_manufacturer, name FROM {manufacturer}') as $row) {
                    $out[(int) $row['id_manufacturer']] = (string) $row['name'];
                }

                return $out;
            },
            'suppliers' => function (): array {
                $out = [];
                if ($this->db->tableExists('supplier')) {
                    foreach ($this->rows('SELECT id_supplier, name FROM {supplier}') as $row) {
                        $out[(int) $row['id_supplier']] = (string) $row['name'];
                    }
                }

                return $out;
            },
            'attribute_groups' => function (): array {
                $out = [];
                foreach ($this->rows('SELECT id_attribute, id_attribute_group FROM {attribute}') as $row) {
                    $out[(int) $row['id_attribute']] = (int) $row['id_attribute_group'];
                }

                return $out;
            },
        ];
    }

    protected function settingsMeta(): array
    {
        $title = [];
        $description = [];
        if ($this->db->tableExists('meta_lang')) {
            foreach ($this->rows(
                'SELECT l.id_lang, l.title, l.description FROM {meta} m INNER JOIN {meta_lang} l ON l.id_meta = m.id_meta WHERE m.page = ? AND l.id_shop = ?',
                ['index', $this->shopId()]
            ) as $row) {
                $title[(int) $row['id_lang']] = (string) $row['title'];
                $description[(int) $row['id_lang']] = (string) $row['description'];
            }
        }

        return ['home_title' => $title, 'home_description' => $description];
    }

    protected function zoneRows(): array
    {
        return array_map(static function (array $row): array {
            return ['id' => $row['id_zone'], 'name' => $row['name'], 'active' => $row['active']];
        }, $this->rows('SELECT id_zone, name, active FROM {zone} ORDER BY id_zone'));
    }

    // ------------------------------------------------------------------ tipos

    public function types(): array
    {
        $types = ['settings', 'tax_classes', 'tax_rates', 'shipping_zones', 'carriers', 'payment_methods', 'customer_groups', 'brands', 'categories', 'attributes',
            'products', 'customers', 'coupons', 'orders'];
        if ($this->db->tableExists('product_comment')) {
            $types[] = 'reviews';
        }
        foreach (['pages', 'redirects', 'team', 'subscribers'] as $type) {
            $types[] = $type;
        }

        return $types;
    }

    /**
     * Cómo se lee un tipo por claves: select, from, params (en el orden en que aparecen), where, id y key. Sirve para paginar y para contar.
     *
     * @return ?array{select:string,from:string,params:array<int,int|string>,where:string,id:string,key:string}
     */
    private function spec(string $type): ?array
    {
        $shop = $this->shopId();
        switch ($type) {
            case 'tax_classes':
                list($join, $p) = $this->shopJoin('tax_rules_group_shop', 'id_tax_rules_group', 'gs', 'g.id_tax_rules_group');

                return ['select' => 'SELECT g.id_tax_rules_group AS id, g.name', 'from' => 'FROM {tax_rules_group} g' . $join, 'params' => $p,
                    'where' => 'g.deleted = 0 AND g.active = 1', 'id' => 'g.id_tax_rules_group', 'key' => 'id'];
            case 'tax_rates':
                return ['select' => 'SELECT r.*, t.rate AS tax_rate, t.active AS tax_active',
                    'from' => 'FROM {tax_rule} r INNER JOIN {tax} t ON t.id_tax = r.id_tax INNER JOIN {tax_rules_group} g ON g.id_tax_rules_group = r.id_tax_rules_group',
                    'params' => [], 'where' => 'g.deleted = 0 AND g.active = 1 AND t.deleted = 0', 'id' => 'r.id_tax_rule', 'key' => 'id_tax_rule'];
            case 'customer_groups':
                list($join, $p) = $this->shopJoin('group_shop', 'id_group', 'gs', 'g.id_group');

                return ['select' => 'SELECT g.*', 'from' => 'FROM `{group}` g' . $join, 'params' => $p, 'where' => '1 = 1', 'id' => 'g.id_group', 'key' => 'id_group'];
            case 'brands':
                list($join, $p) = $this->shopJoin('manufacturer_shop', 'id_manufacturer', 'ms', 'm.id_manufacturer');

                return ['select' => 'SELECT m.*', 'from' => 'FROM {manufacturer} m' . $join, 'params' => $p, 'where' => '1 = 1', 'id' => 'm.id_manufacturer', 'key' => 'id_manufacturer'];
            case 'categories':
                list($join, $p) = $this->shopJoin('category_shop', 'id_category', 'cs', 'c.id_category');

                return ['select' => 'SELECT c.*, ' . ($join !== '' ? 'cs.position AS position_shop' : 'c.position AS position_shop'), 'from' => 'FROM {category} c' . $join, 'params' => $p,
                    'where' => 'c.id_parent > 0', 'id' => 'c.id_category', 'key' => 'id_category'];
            case 'products':
                list($join, $p) = $this->shopJoin('product_shop', 'id_product', 's', 'p.id_product');

                return ['select' => 'SELECT p.*', 'from' => 'FROM {product} p' . $join, 'params' => $p, 'where' => '1 = 1', 'id' => 'p.id_product', 'key' => 'id_product'];
            case 'customers':
                return ['select' => 'SELECT c.*', 'from' => 'FROM {customer} c', 'params' => [$shop], 'where' => 'c.deleted = 0 AND c.id_shop = ?', 'id' => 'c.id_customer', 'key' => 'id_customer'];
            case 'coupons':
                $where = '1 = 1';
                $params = [];
                if ($this->db->tableExists('cart_rule_shop')) {
                    $where = '(c.shop_restriction = 0 OR EXISTS (SELECT 1 FROM {cart_rule_shop} s WHERE s.id_cart_rule = c.id_cart_rule AND s.id_shop = ?))';
                    $params = [$shop];
                }

                return ['select' => 'SELECT c.*', 'from' => 'FROM {cart_rule} c', 'params' => $params, 'where' => $where, 'id' => 'c.id_cart_rule', 'key' => 'id_cart_rule'];
            case 'orders':
                return ['select' => 'SELECT o.*', 'from' => 'FROM {orders} o', 'params' => [$shop], 'where' => 'o.id_shop = ?', 'id' => 'o.id_order', 'key' => 'id_order'];
            case 'reviews':
                return ['select' => 'SELECT c.*', 'from' => 'FROM {product_comment} c', 'params' => [], 'where' => 'c.deleted = 0', 'id' => 'c.id_product_comment', 'key' => 'id_product_comment'];
            case 'pages':
                list($join, $p) = $this->shopJoin('cms_shop', 'id_cms', 'cs', 'c.id_cms');

                return ['select' => 'SELECT c.*', 'from' => 'FROM {cms} c' . $join, 'params' => $p, 'where' => '1 = 1', 'id' => 'c.id_cms', 'key' => 'id_cms'];
            case 'team':
                list($join, $p) = $this->shopJoin('employee_shop', 'id_employee', 'es', 'e.id_employee');
                $login = $this->db->columnExists('employee', 'last_connection_date') ? ', e.last_connection_date' : '';

                return ['select' => 'SELECT e.id_employee, e.id_profile, e.id_lang, e.lastname, e.firstname, e.email, e.active' . $login, 'from' => 'FROM {employee} e' . $join,
                    'params' => $p, 'where' => '1 = 1', 'id' => 'e.id_employee', 'key' => 'id_employee'];
        }

        return null;
    }

    public function count(string $type): ?int
    {
        $spec = $this->spec($type);
        if ($spec !== null) {
            return $this->db->count($this->q('SELECT COUNT(*) ' . $spec['from'] . ' WHERE (' . $spec['where'] . ')'), $spec['params']);
        }
        switch ($type) {
            case 'settings':
                return 1;
            case 'shipping_zones':
                return count($this->zonesWithLocations());
            case 'carriers':
                return count($this->carrierRows());
            case 'payment_methods':
                return count($this->paymentRows());
            case 'attributes':
                return $this->db->count($this->q('SELECT COUNT(*) FROM {attribute_group}')) + $this->db->count($this->q('SELECT COUNT(*) FROM {feature}'));
            case 'redirects':
                return $this->db->count($this->q('SELECT COUNT(*) FROM {product_shop} WHERE id_shop = ? AND (redirect_type LIKE ? OR redirect_type LIKE ?)'), [$this->shopId(), '301%', '302%']);
            case 'subscribers':
                return ($this->db->tableExists('emailsubscription') ? $this->db->count($this->q('SELECT COUNT(*) FROM {emailsubscription}')) : 0)
                    + $this->db->count($this->q('SELECT COUNT(*) FROM {customer} WHERE deleted = 0 AND id_shop = ? AND newsletter = 1'), [$this->shopId()]);
        }

        return null;
    }

    protected function page(string $type, string $cursor, int $limit): array
    {
        $spec = $this->spec($type);
        if ($spec !== null) {
            list($rows, $next) = $this->db->page($this->q($spec['select'] . ' ' . $spec['from']), $spec['params'], $this->q($spec['where']), $spec['id'], $spec['key'], $cursor, $limit);

            return [$this->assemble($type, $rows), $next];
        }
        switch ($type) {
            case 'settings':
                return [[['id' => 'main']], null];
            case 'shipping_zones':
                list($zones, $next) = Batch::slice($this->zonesWithLocations(), $cursor, $limit);
                $offset = $cursor === '' ? 0 : (int) $cursor;
                foreach ($zones as $i => $zone) {
                    $zones[$i]['position'] = $offset + $i;
                }

                return [$zones, $next];
            case 'carriers':
                list($rows, $next) = Batch::slice($this->carrierRows(), $cursor, $limit);

                return [$this->assembleCarriers($rows), $next];
            case 'payment_methods':
                list($rows, $next) = Batch::slice($this->paymentRows(), $cursor, $limit);

                return [$rows, $next];
            case 'attributes':
                return Batch::stages([
                    function (int $last, int $n): array {
                        return $this->attributeGroups($last, $n);
                    },
                    function (int $last, int $n): array {
                        return $this->features($last, $n);
                    },
                ], $cursor, $limit);
            case 'redirects':
                list($rows, $next) = $this->db->page(
                    $this->q('SELECT p.* FROM {product} p INNER JOIN {product_shop} s ON s.id_product = p.id_product'),
                    [$this->shopId(), '301%', '302%'],
                    $this->q('s.id_shop = ? AND (s.redirect_type LIKE ? OR s.redirect_type LIKE ?)'),
                    'p.id_product',
                    'id_product',
                    $cursor,
                    $limit
                );

                return [$this->redirectRows($rows), $next];
            case 'subscribers':
                return $this->subscribers($cursor, $limit);
        }
        throw new \InvalidArgumentException('Unknown type ' . $type);
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function assemble(string $type, array $rows): array
    {
        switch ($type) {
            case 'tax_rates':
                $env = $this->env();
                foreach ($rows as $i => $row) {
                    $rows[$i]['id'] = $row['id_tax_rule'];
                    $rows[$i]['tax_name'] = isset($env->taxes[(int) $row['id_tax']]) ? $env->taxes[(int) $row['id_tax']]['name'] : [];
                }

                return $rows;
            case 'customer_groups':
                return $this->withLang($rows, 'id_group', 'group_lang', ['name'], '`{group_lang}`');
            case 'brands':
                return $this->withLang($rows, 'id_manufacturer', 'manufacturer_lang', ['description', 'short_description', 'meta_title', 'meta_description']);
            case 'categories':
                foreach ($rows as $i => $row) {
                    $rows[$i]['position'] = $row['position_shop'];
                }

                return $this->withLang($rows, 'id_category', 'category_lang', ['name', 'description', 'link_rewrite', 'meta_title', 'meta_description'], '', 'AND id_shop = ?', [$this->shopId()]);
            case 'products':
                return $this->products($rows);
            case 'customers':
                return $this->customers($rows);
            case 'coupons':
                return $this->coupons($rows);
            case 'orders':
                return $this->orders($rows);
            case 'reviews':
                foreach ($rows as $i => $row) {
                    $rows[$i]['id'] = $row['id_product_comment'];
                }

                return $rows;
            case 'pages':
                return $this->withLang($rows, 'id_cms', 'cms_lang', ['meta_title', 'head_seo_title', 'meta_description', 'content', 'link_rewrite'], '', 'AND id_shop = ?', [$this->shopId()]);
            case 'team':
                return $this->employees($rows);
        }

        return $rows;
    }

    /**
     * Añade a cada fila sus textos por idioma y su «id» (la clave primaria).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param string[]                       $columns
     * @param array<int,int|string>          $extraParams
     *
     * @return array<int,array<string,mixed>>
     */
    private function withLang(array $rows, string $idColumn, string $table, array $columns, string $tableSql = '', string $extra = '', array $extraParams = []): array
    {
        $ids = array_map(static function (array $row) use ($idColumn): int {
            return (int) $row[$idColumn];
        }, $rows);
        $grouped = $this->groupedQ($table, $idColumn, $ids, $extra, $extraParams, '', $tableSql);
        foreach ($rows as $i => $row) {
            $rows[$i]['id'] = $row[$idColumn];
            $langRows = isset($grouped[(string) $row[$idColumn]]) ? $grouped[(string) $row[$idColumn]] : [];
            foreach ($columns as $column) {
                $rows[$i][$column] = self::langMap($langRows, $column);
            }
        }

        return $rows;
    }

    /**
     * Como Database::grouped pero con la tabla puesta por nombre (con el prefijo) y la opción de citarla.
     *
     * @param array<int,int|string> $ids
     * @param array<int,int|string> $extraParams
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    private function groupedQ(string $table, string $column, array $ids, string $extra = '', array $extraParams = [], string $order = '', string $tableSql = ''): array
    {
        if ($ids === [] || !$this->db->tableExists($table)) {
            return [];
        }
        $sql = 'SELECT * FROM ' . ($tableSql !== '' ? $tableSql : '{' . $table . '}') . ' WHERE ' . $column . ' IN (' . $this->db->marks(count($ids)) . ') ' . $extra . ($order !== '' ? ' ORDER BY ' . $order : '');
        $out = [];
        foreach ($this->rows($sql, array_merge(array_values($ids), $extraParams)) as $row) {
            $out[(string) $row[$column]][] = $row;
        }

        return $out;
    }

    // ------------------------------------------------------------------ envíos

    /**
     * Los transportistas vigentes (no borrados y activos), uno por «id_reference»: si se editó, el más nuevo.
     *
     * @return array<int,array<string,mixed>>
     */
    private function carrierRows(): array
    {
        list($join, $p) = $this->shopJoin('carrier_shop', 'id_carrier', 'cs', 'c.id_carrier');
        $rows = $this->rows('SELECT c.* FROM {carrier} c' . $join . ' WHERE c.deleted = 0 AND c.active = 1 ORDER BY c.id_carrier', $p);
        $byReference = [];
        foreach ($rows as $row) {
            $reference = (int) $row['id_reference'] > 0 ? (int) $row['id_reference'] : (int) $row['id_carrier'];
            $byReference[$reference] = $row;
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
            return (int) $row['id_carrier'];
        }, $rows);
        $shop = $this->shopId();
        $delay = $this->groupedQ('carrier_lang', 'id_carrier', $ids, 'AND id_shop = ?', [$shop]);
        $tax = $this->groupedQ('carrier_tax_rules_group_shop', 'id_carrier', $ids, 'AND id_shop = ?', [$shop]);
        $zones = $this->groupedQ('carrier_zone', 'id_carrier', $ids);
        $weight = $this->groupedQ('range_weight', 'id_carrier', $ids);
        $price = $this->groupedQ('range_price', 'id_carrier', $ids);
        $deliveries = $this->groupedQ('delivery', 'id_carrier', $ids, 'AND (id_shop IS NULL OR id_shop = 0 OR id_shop = ?)', [$shop]);
        $emitted = $this->emittedZones();
        foreach ($rows as $i => $row) {
            $id = (string) $row['id_carrier'];
            $rows[$i]['id'] = $row['id_carrier'];
            $rows[$i]['delay'] = self::langMap(isset($delay[$id]) ? $delay[$id] : [], 'delay');
            $group = isset($tax[$id][0]['id_tax_rules_group']) ? $tax[$id][0]['id_tax_rules_group'] : (isset($row['id_tax_rules_group']) ? $row['id_tax_rules_group'] : 0);
            $rows[$i]['tax_group'] = $group;
            $rows[$i]['zones'] = array_map(static function (array $z): int {
                return (int) $z['id_zone'];
            }, isset($zones[$id]) ? $zones[$id] : []);
            $rows[$i]['emitted_zones'] = $emitted;
            $rows[$i]['weight_ranges'] = [];
            foreach (isset($weight[$id]) ? $weight[$id] : [] as $range) {
                $rows[$i]['weight_ranges'][(int) $range['id_range_weight']] = [$range['delimiter1'], $range['delimiter2']];
            }
            $rows[$i]['price_ranges'] = [];
            foreach (isset($price[$id]) ? $price[$id] : [] as $range) {
                $rows[$i]['price_ranges'][(int) $range['id_range_price']] = [$range['delimiter1'], $range['delimiter2']];
            }
            $rows[$i]['deliveries'] = isset($deliveries[$id]) ? $deliveries[$id] : [];
        }

        return $rows;
    }

    // ------------------------------------------------------------------ formas de pago

    /**
     * @return array<int,array<string,mixed>>
     */
    private function paymentRows(): array
    {
        if (!$this->db->tableExists('module')) {
            return [];
        }
        $names = array_keys(self::PAYMENT_MODULES);
        $modules = $this->rows('SELECT id_module, name, active FROM {module} WHERE name IN (' . $this->db->marks(count($names)) . ') ORDER BY id_module', $names);
        $inShop = [];
        $hasShopTable = $this->db->tableExists('module_shop');
        if ($hasShopTable) {
            foreach ($this->rows('SELECT id_module FROM {module_shop} WHERE id_shop = ?', [$this->shopId()]) as $row) {
                $inShop[(int) $row['id_module']] = true;
            }
        }
        $out = [];
        foreach ($modules as $i => $module) {
            list($code, $title, $prefix) = self::PAYMENT_MODULES[(string) $module['name']];
            $settings = [];
            if ($prefix !== '') {
                $like = str_replace(['!', '_', '%'], ['!!', '!_', '!%'], $prefix) . '%';
                foreach ($this->rows('SELECT name, value, id_shop, id_shop_group FROM {c} WHERE name LIKE ? ESCAPE \'!\'', [$like]) as $row) {
                    $resolved = self::resolveConfig([$row], $this->shopId(), 0);
                    if (isset($resolved[(string) $row['name']])) {
                        $settings[(string) $row['name']] = $resolved[(string) $row['name']];
                    }
                }
            }
            ksort($settings);
            $out[] = [
                'id' => (string) $module['name'], 'name' => (string) $module['name'], 'code' => $code, 'title' => $title,
                'active' => Mapper::b($module['active']) && (!$hasShopTable || isset($inShop[(int) $module['id_module']])),
                'sandbox' => isset($settings['PAYPAL_SANDBOX']) ? $settings['PAYPAL_SANDBOX'] === '1' : null,
                'settings' => $settings, 'position' => $i,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ atributos y características

    /**
     * @return array<int,array<string,mixed>>
     */
    private function attributeGroups(int $last, int $n): array
    {
        list($join, $p) = $this->shopJoin('attribute_group_shop', 'id_attribute_group', 'gs', 'g.id_attribute_group');
        $groups = $this->rows('SELECT g.* FROM {attribute_group} g' . $join . ' WHERE g.id_attribute_group > ? ORDER BY g.id_attribute_group LIMIT ?', array_merge($p, [$last, $n]));
        $ids = array_map(static function (array $g): int {
            return (int) $g['id_attribute_group'];
        }, $groups);
        $lang = $this->groupedQ('attribute_group_lang', 'id_attribute_group', $ids);
        list($ajoin, $ap) = $this->shopJoin('attribute_shop', 'id_attribute', 'ash', 'a.id_attribute');
        $values = [];
        if ($ids !== []) {
            foreach ($this->rows(
                'SELECT a.* FROM {attribute} a' . $ajoin . ' WHERE a.id_attribute_group IN (' . $this->db->marks(count($ids)) . ') ORDER BY a.position, a.id_attribute',
                array_merge($ap, $ids)
            ) as $row) {
                $values[(int) $row['id_attribute_group']][] = $row;
            }
        }
        $valueIds = [];
        foreach ($values as $list) {
            foreach ($list as $row) {
                $valueIds[] = (int) $row['id_attribute'];
            }
        }
        $valueLang = $this->groupedQ('attribute_lang', 'id_attribute', $valueIds);
        $out = [];
        foreach ($groups as $g) {
            $id = (int) $g['id_attribute_group'];
            $items = [];
            foreach (isset($values[$id]) ? $values[$id] : [] as $row) {
                $items[] = [
                    'id' => (int) $row['id_attribute'], 'color' => isset($row['color']) ? (string) $row['color'] : '', 'position' => $row['position'],
                    'name' => self::langMap(isset($valueLang[(string) $row['id_attribute']]) ? $valueLang[(string) $row['id_attribute']] : [], 'name'),
                ];
            }
            $out[] = [
                'kind' => 'group', 'id' => $id, 'is_color_group' => $g['is_color_group'], 'group_type' => isset($g['group_type']) ? $g['group_type'] : '', 'position' => $g['position'],
                'name' => self::langMap(isset($lang[(string) $id]) ? $lang[(string) $id] : [], 'name'),
                'public_name' => self::langMap(isset($lang[(string) $id]) ? $lang[(string) $id] : [], 'public_name'),
                'values' => $items,
            ];
        }

        return $out;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function features(int $last, int $n): array
    {
        $features = $this->rows('SELECT * FROM {feature} WHERE id_feature > ? ORDER BY id_feature LIMIT ?', [$last, $n]);
        $ids = array_map(static function (array $f): int {
            return (int) $f['id_feature'];
        }, $features);
        $lang = $this->groupedQ('feature_lang', 'id_feature', $ids);
        $values = $this->groupedQ('feature_value', 'id_feature', $ids, 'AND custom = 0', [], 'id_feature_value');
        $valueIds = [];
        foreach ($values as $list) {
            foreach ($list as $row) {
                $valueIds[] = (int) $row['id_feature_value'];
            }
        }
        $valueLang = $this->groupedQ('feature_value_lang', 'id_feature_value', $valueIds);
        $out = [];
        foreach ($features as $f) {
            $id = (int) $f['id_feature'];
            $items = [];
            foreach (isset($values[(string) $id]) ? $values[(string) $id] : [] as $row) {
                $items[] = [
                    'id' => (int) $row['id_feature_value'], 'position' => isset($row['position']) ? $row['position'] : '',
                    'name' => self::langMap(isset($valueLang[(string) $row['id_feature_value']]) ? $valueLang[(string) $row['id_feature_value']] : [], 'value'),
                ];
            }
            $out[] = [
                'kind' => 'feature', 'id' => $id, 'position' => $f['position'],
                'name' => self::langMap(isset($lang[(string) $id]) ? $lang[(string) $id] : [], 'name'), 'values' => $items,
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ productos

    /**
     * @param array<int,array<string,mixed>> $rows filas completas de «product»
     *
     * @return array<int,array<string,mixed>>
     */
    private function products(array $rows): array
    {
        $ids = array_map(static function (array $row): int {
            return (int) $row['id_product'];
        }, $rows);
        if ($ids === []) {
            return [];
        }
        $shop = $this->shopId();
        $marks = $this->db->marks(count($ids));
        $shopRows = [];
        if ($this->db->tableExists('product_shop')) {
            foreach ($this->rows('SELECT * FROM {product_shop} WHERE id_shop = ? AND id_product IN (' . $marks . ')', array_merge([$shop], $ids)) as $row) {
                $shopRows[(int) $row['id_product']] = $row;
            }
        }
        $lang = $this->groupedQ('product_lang', 'id_product', $ids, 'AND id_shop = ?', [$shop]);
        $categories = $this->groupedQ('category_product', 'id_product', $ids, '', [], 'id_category');
        $images = [];
        $imageSql = $this->db->tableExists('image_shop')
            ? 'SELECT i.id_image, i.id_product, i.position, s.cover AS cover FROM {image} i INNER JOIN {image_shop} s ON s.id_image = i.id_image AND s.id_shop = ? WHERE i.id_product IN (' . $marks . ') ORDER BY i.position, i.id_image'
            : 'SELECT i.id_image, i.id_product, i.position, i.cover AS cover FROM {image} i WHERE i.id_product IN (' . $marks . ') ORDER BY i.position, i.id_image';
        foreach ($this->rows($imageSql, $this->db->tableExists('image_shop') ? array_merge([$shop], $ids) : $ids) as $row) {
            $images[(int) $row['id_product']][] = ['id' => (int) $row['id_image'], 'cover' => Mapper::b($row['cover'])];
        }
        $combinations = $this->combinations($ids);
        $stock = [];
        $outOfStock = [];
        foreach ($this->rows('SELECT id_product, id_product_attribute, quantity, out_of_stock, id_shop FROM {stock_available} WHERE id_product IN (' . $marks . ') AND (id_shop = ? OR id_shop = 0)', array_merge($ids, [$shop])) as $row) {
            $product = (int) $row['id_product'];
            $pa = (int) $row['id_product_attribute'];
            if (!isset($stock[$product][$pa]) || (int) $row['id_shop'] === $shop) {
                $stock[$product][$pa] = (int) $row['quantity'];
                if ($pa === 0) {
                    $outOfStock[$product] = (int) $row['out_of_stock'];
                }
            }
        }
        $features = [];
        $customIds = [];
        foreach ($this->rows(
            'SELECT fp.id_product, fp.id_feature, fp.id_feature_value, fv.custom FROM {feature_product} fp INNER JOIN {feature_value} fv ON fv.id_feature_value = fp.id_feature_value WHERE fp.id_product IN (' . $marks . ') ORDER BY fp.id_feature, fp.id_feature_value',
            $ids
        ) as $row) {
            $features[(int) $row['id_product']][] = ['feature' => (int) $row['id_feature'], 'value' => (int) $row['id_feature_value'], 'custom' => Mapper::b($row['custom']), 'text' => []];
            if (Mapper::b($row['custom'])) {
                $customIds[] = (int) $row['id_feature_value'];
            }
        }
        $customLang = $this->groupedQ('feature_value_lang', 'id_feature_value', $customIds);
        foreach ($features as $product => $list) {
            foreach ($list as $i => $feature) {
                if ($feature['custom']) {
                    $features[$product][$i]['text'] = self::langMap(isset($customLang[(string) $feature['value']]) ? $customLang[(string) $feature['value']] : [], 'value');
                }
            }
        }
        $tags = [];
        foreach ($this->rows('SELECT pt.id_product, pt.id_lang, t.name FROM {product_tag} pt INNER JOIN {tag} t ON t.id_tag = pt.id_tag WHERE pt.id_product IN (' . $marks . ')', $ids) as $row) {
            $tags[(int) $row['id_product']][(int) $row['id_lang']][] = (string) $row['name'];
        }
        $accessories = $this->groupedQ('accessory', 'id_product_1', $ids, '', [], 'id_product_2');
        $specific = $this->groupedQ('specific_price', 'id_product', $ids, 'AND id_cart = 0 AND (id_shop = 0 OR id_shop = ?)', [$shop], 'id_specific_price');
        $attachments = [];
        if ($this->db->tableExists('product_attachment')) {
            $attachmentRows = $this->rows('SELECT pa.id_product, a.* FROM {product_attachment} pa INNER JOIN {attachment} a ON a.id_attachment = pa.id_attachment WHERE pa.id_product IN (' . $marks . ') ORDER BY a.id_attachment', $ids);
            $attachmentLang = $this->groupedQ('attachment_lang', 'id_attachment', array_map(static function (array $row): int {
                return (int) $row['id_attachment'];
            }, $attachmentRows));
            foreach ($attachmentRows as $row) {
                $row['name'] = self::langMap(isset($attachmentLang[(string) $row['id_attachment']]) ? $attachmentLang[(string) $row['id_attachment']] : [], 'name');
                $attachments[(int) $row['id_product']][] = $row;
            }
        }
        $download = $this->groupedQ('product_download', 'id_product', $ids, 'AND active = 1', [], 'id_product_download');
        $suppliers = $this->groupedQ('product_supplier', 'id_product', $ids, '', [], 'id_product_supplier');
        $packs = $this->groupedQ('pack', 'id_product_pack', $ids);
        $out = [];
        foreach ($rows as $row) {
            $id = (int) $row['id_product'];
            $raw = $row;
            foreach (isset($shopRows[$id]) ? $shopRows[$id] : [] as $key => $value) {
                if ($key !== 'id_product' && $key !== 'id_shop') {
                    $raw[$key] = $value;
                }
            }
            $langRows = isset($lang[(string) $id]) ? $lang[(string) $id] : [];
            $raw['id'] = $id;
            foreach (['name', 'description', 'description_short', 'link_rewrite', 'meta_title', 'meta_description', 'meta_keywords'] as $column) {
                $raw[$column] = self::langMap($langRows, $column);
            }
            $raw['categories'] = array_map(static function (array $c): int {
                return (int) $c['id_category'];
            }, isset($categories[(string) $id]) ? $categories[(string) $id] : []);
            $raw['images'] = isset($images[$id]) ? $images[$id] : [];
            $raw['combinations'] = isset($combinations[$id]) ? $combinations[$id] : [];
            $raw['stock'] = isset($stock[$id]) ? $stock[$id] : [];
            $raw['out_of_stock'] = isset($outOfStock[$id]) ? $outOfStock[$id] : 2;
            $raw['features'] = isset($features[$id]) ? $features[$id] : [];
            $raw['tags'] = isset($tags[$id]) ? $tags[$id] : [];
            $raw['accessories'] = array_map(static function (array $a): int {
                return (int) $a['id_product_2'];
            }, isset($accessories[(string) $id]) ? $accessories[(string) $id] : []);
            $raw['specific_prices'] = isset($specific[(string) $id]) ? $specific[(string) $id] : [];
            $raw['attachments'] = isset($attachments[$id]) ? $attachments[$id] : [];
            $raw['download'] = isset($download[(string) $id][0]) ? $download[(string) $id][0] : null;
            $raw['suppliers'] = isset($suppliers[(string) $id]) ? $suppliers[(string) $id] : [];
            $raw['pack'] = [];
            foreach (isset($packs[(string) $id]) ? $packs[(string) $id] : [] as $item) {
                $raw['pack'][] = ['product' => (int) $item['id_product_item'], 'combination' => (int) $item['id_product_attribute_item'], 'qty' => (int) $item['quantity']];
            }
            $out[] = $raw;
        }

        return $out;
    }

    /**
     * Las combinaciones de cada producto, con sus valores de atributo e imágenes y con los datos de la tienda elegida.
     *
     * @param array<int,int> $productIds
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function combinations(array $productIds): array
    {
        $rows = $this->groupedQ('product_attribute', 'id_product', $productIds, '', [], 'id_product_attribute');
        if ($rows === []) {
            return [];
        }
        $shopRows = [];
        $hasShop = $this->db->tableExists('product_attribute_shop');
        if ($hasShop) {
            foreach ($this->groupedQ('product_attribute_shop', 'id_product', $productIds, 'AND id_shop = ?', [$this->shopId()]) as $list) {
                foreach ($list as $row) {
                    $shopRows[(int) $row['id_product_attribute']] = $row;
                }
            }
        }
        $paIds = [];
        foreach ($rows as $list) {
            foreach ($list as $row) {
                $paIds[] = (int) $row['id_product_attribute'];
            }
        }
        $values = $this->groupedQ('product_attribute_combination', 'id_product_attribute', $paIds, '', [], 'id_attribute');
        $images = $this->groupedQ('product_attribute_image', 'id_product_attribute', $paIds, '', [], 'id_image');
        $out = [];
        foreach ($rows as $productId => $list) {
            foreach ($list as $row) {
                $pa = (int) $row['id_product_attribute'];
                if ($hasShop && !isset($shopRows[$pa])) {
                    continue;
                }
                $combination = $row;
                foreach (isset($shopRows[$pa]) ? $shopRows[$pa] : [] as $key => $value) {
                    if (!in_array($key, ['id_product', 'id_product_attribute', 'id_shop'], true)) {
                        $combination[$key] = $value;
                    }
                }
                $combination['id'] = $pa;
                $combination['values'] = array_map(static function (array $v): int {
                    return (int) $v['id_attribute'];
                }, isset($values[(string) $pa]) ? $values[(string) $pa] : []);
                $combination['images'] = array_map(static function (array $v): int {
                    return (int) $v['id_image'];
                }, isset($images[(string) $pa]) ? $images[(string) $pa] : []);
                $out[(int) $productId][] = $combination;
            }
        }

        return $out;
    }

    /**
     * Las redirecciones de productos: la dirección del producto y la de lo que apunta («301-product», «302-category»).
     *
     * @param array<int,array<string,mixed>> $rows filas de «product»
     *
     * @return array<int,array<string,mixed>>
     */
    private function redirectRows(array $rows): array
    {
        $env = $this->env();
        $products = $this->products($rows);
        $targets = [];
        foreach ($products as $product) {
            if (strpos(Mapper::s($product['redirect_type']), '-product') !== false && (int) $product['id_type_redirected'] > 0) {
                $targets[] = (int) $product['id_type_redirected'];
            }
        }
        $targetRows = $targets === [] ? [] : $this->products($this->rows('SELECT * FROM {product} WHERE id_product IN (' . $this->db->marks(count($targets)) . ')', $targets));
        $byId = [];
        foreach ($targetRows as $row) {
            $byId[(int) $row['id']] = $row;
        }
        $categories = $env->lookup('categories');
        $out = [];
        foreach ($products as $product) {
            $target = '';
            $type = Mapper::s($product['redirect_type']);
            $to = (int) $product['id_type_redirected'];
            if (strpos($type, '-product') !== false && isset($byId[$to])) {
                $paths = Routes::paths($env, 'product_rule', Routes::productParams($env, $byId[$to]));
                $target = isset($paths[0]) ? $paths[0] : '';
            } elseif (strpos($type, '-category') !== false && isset($categories[$to])) {
                $paths = Routes::paths($env, 'category_rule', ['id' => (string) $to, 'rewrite' => $categories[$to]['rewrite']]);
                $target = isset($paths[0]) ? $paths[0] : '';
            }
            $out[] = [
                'id' => $product['id'], 'redirect_type' => $type, 'target' => $target,
                'paths' => Routes::paths($env, 'product_rule', Routes::productParams($env, $product)),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------------ clientes, cupones, pedidos, equipo, suscritos

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function customers(array $rows): array
    {
        $ids = array_map(static function (array $row): int {
            return (int) $row['id_customer'];
        }, $rows);
        $addresses = $this->groupedQ('address', 'id_customer', $ids, 'AND deleted = 0', [], 'id_address');
        $groups = $this->groupedQ('customer_group', 'id_customer', $ids, '', [], 'id_group');
        foreach ($rows as $i => $row) {
            $id = (string) $row['id_customer'];
            $rows[$i]['id'] = $row['id_customer'];
            $rows[$i]['addresses'] = array_map(static function (array $a): array {
                $a['id'] = $a['id_address'];

                return $a;
            }, isset($addresses[$id]) ? $addresses[$id] : []);
            $rows[$i]['group_ids'] = array_map(static function (array $g): int {
                return (int) $g['id_group'];
            }, isset($groups[$id]) ? $groups[$id] : []);
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function coupons(array $rows): array
    {
        $ids = array_map(static function (array $row): int {
            return (int) $row['id_cart_rule'];
        }, $rows);
        $names = $this->groupedQ('cart_rule_lang', 'id_cart_rule', $ids);
        $uses = [];
        if ($ids !== []) {
            foreach ($this->rows('SELECT id_cart_rule, COUNT(*) AS n FROM {order_cart_rule} WHERE id_cart_rule IN (' . $this->db->marks(count($ids)) . ') GROUP BY id_cart_rule', $ids) as $row) {
                $uses[(int) $row['id_cart_rule']] = (int) $row['n'];
            }
        }
        $groups = $this->groupedQ('cart_rule_product_rule_group', 'id_cart_rule', $ids, '', [], 'id_product_rule_group');
        $groupIds = [];
        foreach ($groups as $list) {
            foreach ($list as $row) {
                $groupIds[] = (int) $row['id_product_rule_group'];
            }
        }
        $rules = $this->groupedQ('cart_rule_product_rule', 'id_product_rule_group', $groupIds, '', [], 'id_product_rule');
        $ruleIds = [];
        foreach ($rules as $list) {
            foreach ($list as $row) {
                $ruleIds[] = (int) $row['id_product_rule'];
            }
        }
        $items = $this->groupedQ('cart_rule_product_rule_value', 'id_product_rule', $ruleIds, '', [], 'id_item');
        foreach ($rows as $i => $row) {
            $id = (int) $row['id_cart_rule'];
            $rows[$i]['id'] = $id;
            $rows[$i]['name'] = self::langMap(isset($names[(string) $id]) ? $names[(string) $id] : [], 'name');
            $rows[$i]['uses'] = isset($uses[$id]) ? $uses[$id] : 0;
            $ruleGroups = [];
            foreach (isset($groups[(string) $id]) ? $groups[(string) $id] : [] as $group) {
                $ruleList = [];
                foreach (isset($rules[(string) $group['id_product_rule_group']]) ? $rules[(string) $group['id_product_rule_group']] : [] as $rule) {
                    $ruleList[] = ['type' => $rule['type'], 'items' => array_map(static function (array $v): int {
                        return (int) $v['id_item'];
                    }, isset($items[(string) $rule['id_product_rule']]) ? $items[(string) $rule['id_product_rule']] : [])];
                }
                $ruleGroups[] = ['rules' => $ruleList];
            }
            $rows[$i]['rule_groups'] = $ruleGroups;
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function orders(array $rows): array
    {
        $ids = array_map(static function (array $row): int {
            return (int) $row['id_order'];
        }, $rows);
        $details = $this->groupedQ('order_detail', 'id_order', $ids, '', [], 'id_order_detail');
        $detailIds = [];
        foreach ($details as $list) {
            foreach ($list as $row) {
                $detailIds[] = (int) $row['id_order_detail'];
            }
        }
        $taxes = $this->groupedQ('order_detail_tax', 'id_order_detail', $detailIds, '', [], 'id_tax');
        $history = $this->groupedQ('order_history', 'id_order', $ids, '', [], 'id_order_history');
        $references = array_values(array_unique(array_map(static function (array $row): string {
            return (string) $row['reference'];
        }, $rows)));
        $payments = $this->groupedQ('order_payment', 'order_reference', array_values(array_filter($references, static function (string $r): bool {
            return $r !== '';
        })), '', [], 'id_order_payment');
        $invoices = $this->groupedQ('order_invoice', 'id_order', $ids, '', [], 'id_order_invoice');
        $slips = $this->groupedQ('order_slip', 'id_order', $ids, '', [], 'id_order_slip');
        $slipIds = [];
        foreach ($slips as $list) {
            foreach ($list as $row) {
                $slipIds[] = (int) $row['id_order_slip'];
            }
        }
        $slipDetails = $this->groupedQ('order_slip_detail', 'id_order_slip', $slipIds);
        $carrierRows = $this->groupedQ('order_carrier', 'id_order', $ids, '', [], 'id_order_carrier');
        $rules = $this->groupedQ('order_cart_rule', 'id_order', $ids, '', [], 'id_order_cart_rule');
        $ruleIds = [];
        foreach ($rules as $list) {
            foreach ($list as $row) {
                $ruleIds[] = (int) $row['id_cart_rule'];
            }
        }
        $codes = [];
        if ($ruleIds !== []) {
            foreach ($this->rows('SELECT id_cart_rule, code FROM {cart_rule} WHERE id_cart_rule IN (' . $this->db->marks(count(array_unique($ruleIds))) . ')', array_values(array_unique($ruleIds))) as $row) {
                $codes[(int) $row['id_cart_rule']] = (string) $row['code'];
            }
        }
        $customerIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
            return (int) $row['id_customer'];
        }, $rows))));
        $emails = [];
        if ($customerIds !== []) {
            foreach ($this->rows('SELECT id_customer, email FROM {customer} WHERE id_customer IN (' . $this->db->marks(count($customerIds)) . ')', $customerIds) as $row) {
                $emails[(int) $row['id_customer']] = (string) $row['email'];
            }
        }
        $addressIds = [];
        foreach ($rows as $row) {
            $addressIds[] = (int) $row['id_address_invoice'];
            $addressIds[] = (int) $row['id_address_delivery'];
        }
        $addressIds = array_values(array_unique(array_filter($addressIds)));
        $addresses = [];
        if ($addressIds !== []) {
            foreach ($this->rows('SELECT * FROM {address} WHERE id_address IN (' . $this->db->marks(count($addressIds)) . ')', $addressIds) as $row) {
                $row['id'] = $row['id_address'];
                $addresses[(int) $row['id_address']] = $row;
            }
        }
        $carrierIds = array_values(array_unique(array_filter(array_map(static function (array $row): int {
            return (int) $row['id_carrier'];
        }, $rows))));
        $carriers = [];
        if ($carrierIds !== []) {
            foreach ($this->rows('SELECT id_carrier, id_reference, name FROM {carrier} WHERE id_carrier IN (' . $this->db->marks(count($carrierIds)) . ')', $carrierIds) as $row) {
                $carriers[(int) $row['id_carrier']] = ['id_reference' => $row['id_reference'], 'name' => $row['name']];
            }
        }
        foreach ($rows as $i => $row) {
            $id = (string) $row['id_order'];
            $rows[$i]['id'] = $row['id_order'];
            $lines = [];
            foreach (isset($details[$id]) ? $details[$id] : [] as $detail) {
                $detail['tax_ids'] = array_map(static function (array $t): int {
                    return (int) $t['id_tax'];
                }, isset($taxes[(string) $detail['id_order_detail']]) ? $taxes[(string) $detail['id_order_detail']] : []);
                $lines[] = $detail;
            }
            $rows[$i]['lines'] = $lines;
            $rows[$i]['history'] = array_map(static function (array $h): array {
                $h['id'] = $h['id_order_history'];

                return $h;
            }, isset($history[$id]) ? $history[$id] : []);
            $rows[$i]['payments'] = isset($payments[(string) $row['reference']]) ? $payments[(string) $row['reference']] : [];
            $rows[$i]['invoices'] = array_map(static function (array $v): array {
                $v['id'] = $v['id_order_invoice'];

                return $v;
            }, isset($invoices[$id]) ? $invoices[$id] : []);
            $orderSlips = [];
            foreach (isset($slips[$id]) ? $slips[$id] : [] as $slip) {
                $slip['id'] = $slip['id_order_slip'];
                $slip['details'] = isset($slipDetails[(string) $slip['id_order_slip']]) ? $slipDetails[(string) $slip['id_order_slip']] : [];
                $orderSlips[] = $slip;
            }
            $rows[$i]['slips'] = $orderSlips;
            $rows[$i]['order_carriers'] = isset($carrierRows[$id]) ? $carrierRows[$id] : [];
            $rows[$i]['cart_rules'] = array_map(static function (array $c) use ($codes): array {
                $c['code'] = isset($codes[(int) $c['id_cart_rule']]) ? $codes[(int) $c['id_cart_rule']] : '';

                return $c;
            }, isset($rules[$id]) ? $rules[$id] : []);
            $rows[$i]['email'] = isset($emails[(int) $row['id_customer']]) ? $emails[(int) $row['id_customer']] : '';
            $rows[$i]['invoice_address'] = isset($addresses[(int) $row['id_address_invoice']]) ? $addresses[(int) $row['id_address_invoice']] : null;
            $rows[$i]['delivery_address'] = isset($addresses[(int) $row['id_address_delivery']]) ? $addresses[(int) $row['id_address_delivery']] : null;
            $rows[$i]['carrier'] = isset($carriers[(int) $row['id_carrier']]) ? $carriers[(int) $row['id_carrier']] : null;
        }

        return $rows;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,array<string,mixed>>
     */
    private function employees(array $rows): array
    {
        $names = $this->groupedQ('profile_lang', 'id_profile', array_map(static function (array $row): int {
            return (int) $row['id_profile'];
        }, $rows));
        foreach ($rows as $i => $row) {
            $rows[$i]['id'] = $row['id_employee'];
            $rows[$i]['profile'] = self::langMap(isset($names[(string) $row['id_profile']]) ? $names[(string) $row['id_profile']] : [], 'name');
        }

        return $rows;
    }

    /**
     * Los suscritos del módulo de boletín («e») y después los clientes que tienen el boletín activado («c»).
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string}
     */
    private function subscribers(string $cursor, int $limit): array
    {
        $shop = $this->shopId();
        $stages = [
            function (int $last, int $n) use ($shop): array {
                if (!$this->db->tableExists('emailsubscription')) {
                    return [];
                }
                $out = [];
                foreach ($this->rows('SELECT * FROM {emailsubscription} WHERE id > ? AND (id_shop IS NULL OR id_shop = 0 OR id_shop = ?) ORDER BY id LIMIT ?', [$last, $shop, $n]) as $row) {
                    $out[] = ['id' => (int) $row['id'], 'sid' => 'e' . $row['id'], 'email' => $row['email'], 'date' => $row['newsletter_date_add'] ?? null, 'source' => $row['http_referer'] ?? '',
                        'confirmed' => $row['active'] ?? 1, 'id_lang' => $row['id_lang'] ?? 0];
                }

                return $out;
            },
            function (int $last, int $n) use ($shop): array {
                $out = [];
                foreach ($this->rows('SELECT id_customer, email, firstname, lastname, newsletter_date_add, id_lang FROM {customer} WHERE id_customer > ? AND deleted = 0 AND id_shop = ? AND newsletter = 1 ORDER BY id_customer LIMIT ?', [$last, $shop, $n]) as $row) {
                    $out[] = ['id' => (int) $row['id_customer'], 'sid' => 'c' . $row['id_customer'], 'email' => $row['email'], 'name' => trim($row['firstname'] . ' ' . $row['lastname']),
                        'date' => $row['newsletter_date_add'], 'source' => 'customer', 'confirmed' => 1, 'id_lang' => $row['id_lang']];
                }

                return $out;
            },
        ];
        list($rows, $next) = Batch::stages($stages, $cursor, $limit);
        foreach ($rows as $i => $row) {
            $rows[$i]['id'] = $row['sid'];
            unset($rows[$i]['sid']);
        }

        return [$rows, $next];
    }

    // ------------------------------------------------------------------ comprobar, sumas de control, lo que no se trae

    public function probe(): array
    {
        $result = ['ok' => false, 'message' => '', 'version' => '', 'shops' => [], 'languages' => [], 'currency' => '', 'warnings' => [], 'counts' => []];
        try {
            $env = $this->env();
            if ($env->version === '' || version_compare($env->version, '1.7', '<')) {
                $result['version'] = 'PrestaShop ' . ($env->version !== '' ? $env->version : '?');
                $result['message'] = 'This PrestaShop version is not supported: 1.7 or later is needed';

                return $result;
            }
            $products = $this->count('products');
            $first = $this->read('products', '', 1);
            $result['counts'] = [
                'products' => (int) $products, 'categories' => (int) $this->count('categories'), 'customers' => (int) $this->count('customers'), 'orders' => (int) $this->count('orders'),
            ];
        } catch (SourceDenied $e) {
            $result['message'] = 'The database user has no permission to read the shop tables';

            return $result;
        } catch (SourceError $e) {
            $result['message'] = 'The database could not be read: is it a PrestaShop database and is the table prefix right?';

            return $result;
        }
        $result['version'] = 'PrestaShop ' . ($env->version !== '' ? $env->version : '?');
        $result['languages'] = array_values($env->langs);
        $result['currency'] = isset($env->currencies[$env->cfgInt('PS_CURRENCY_DEFAULT')]) ? $env->currencies[$env->cfgInt('PS_CURRENCY_DEFAULT')] : '';
        $shops = [];
        if ($this->db->tableExists('shop')) {
            foreach ($this->rows('SELECT id_shop, name FROM {shop} WHERE deleted = 0 ORDER BY id_shop') as $row) {
                $shops[] = ['id' => (string) $row['id_shop'], 'name' => (string) $row['name']];
            }
        }
        if (count($shops) > 1) {
            $result['shops'] = $shops;
            $result['warnings'][] = 'The shop is multistore: only the chosen shop is read';
        }
        if ($env->cfg('_cookie_key_given') !== '1'
            && $this->db->count($this->q('SELECT COUNT(*) FROM {customer} WHERE passwd NOT LIKE ? AND is_guest = 0 AND deleted = 0'), ['$2%']) > 0) {
            $result['warnings'][] = 'Some customers have old PrestaShop passwords: without the cookie key they will have to reset them';
        }
        if (count($env->currencies) > 1) {
            $result['warnings'][] = 'The shop uses more than one currency: only the default one is kept';
        }
        if ($products < 1 || $first['records'] === []) {
            $result['message'] = 'The database was read but the shop has no products to import';

            return $result;
        }
        $result['ok'] = true;
        $result['message'] = 'Connected to the database';

        return $result;
    }

    public function checksums(): array
    {
        $shop = $this->shopId();
        $sums = [
            'products_count' => (int) $this->count('products'),
            'categories_count' => (int) $this->count('categories'),
            'customers_count' => (int) $this->count('customers'),
            'orders_count' => (int) $this->count('orders'),
            'coupons_count' => (int) $this->count('coupons'),
            'tax_rates_count' => (int) $this->count('tax_rates'),
            'pages_count' => (int) $this->count('pages'),
            'carriers_count' => (int) $this->count('carriers'),
            'brands_count' => (int) $this->count('brands'),
            'customer_groups_count' => (int) $this->count('customer_groups'),
        ];
        $sums['variants_count'] = $this->db->tableExists('product_attribute_shop')
            ? $this->db->count($this->q('SELECT COUNT(*) FROM {product_attribute_shop} WHERE id_shop = ?'), [$shop])
            : $this->db->count($this->q('SELECT COUNT(*) FROM {product_attribute}'));
        $sums['addresses_count'] = $this->db->count($this->q('SELECT COUNT(*) FROM {address} a INNER JOIN {customer} c ON c.id_customer = a.id_customer WHERE a.deleted = 0 AND c.deleted = 0 AND c.id_shop = ?'), [$shop]);
        $sums['orders_valid_count'] = $this->db->count($this->q('SELECT COUNT(*) FROM {orders} WHERE id_shop = ? AND valid = 1'), [$shop]);
        $sums['order_items_count'] = $this->db->count($this->q('SELECT COUNT(*) FROM {order_detail} d INNER JOIN {orders} o ON o.id_order = d.id_order WHERE o.id_shop = ?'), [$shop]);
        $total = 0;
        $cursor = '';
        do {
            list($rows, $next) = $this->db->page($this->q('SELECT o.id_order, o.total_paid_tax_incl FROM {orders} o'), [$shop], 'o.id_shop = ?', 'o.id_order', 'id_order', $cursor, 1000);
            foreach ($rows as $row) {
                $total += Neutral::cents($row['total_paid_tax_incl']);
            }
            $cursor = (string) $next;
        } while ($next !== null);
        $sums['orders_total'] = $total;
        $stock = $this->scalar('SELECT SUM(quantity) FROM {stock_available} WHERE id_product_attribute = 0 AND id_shop = ?', [$shop]);
        if ($stock === null) {
            $stock = $this->scalar('SELECT SUM(quantity) FROM {stock_available} WHERE id_product_attribute = 0 AND id_shop = 0');
        }
        $sums['stock_total'] = (int) $stock;

        return $sums;
    }

    public function unmapped(): array
    {
        $out = [];
        $shop = $this->shopId();
        if ($this->db->tableExists('module')) {
            foreach ($this->rows('SELECT name, active FROM {module} ORDER BY name') as $row) {
                $name = (string) $row['name'];
                if (strncmp($name, 'ps_', 3) === 0 || in_array($name, self::KNOWN_MODULES, true) || strncmp($name, 'stats', 5) === 0) {
                    continue;
                }
                $out[] = ['kind' => 'module', 'name' => $name, 'count' => 1, 'note' => Mapper::b($row['active']) ? 'Installed and active: not brought' : 'Installed but inactive: not brought'];
            }
        }
        $counts = [
            ['pack products', 'SELECT COUNT(DISTINCT id_product_pack) FROM {pack}', [], 'Product packs have no equivalent: the pack items are kept in the extra data'],
            ['products with customization', 'SELECT COUNT(*) FROM {product_shop} WHERE id_shop = ? AND customizable > 0', [$shop], 'Customization fields have no equivalent'],
            ['quantity discounts', 'SELECT COUNT(*) FROM {specific_price} WHERE id_cart = 0 AND from_quantity > 1', [], 'Discounts by quantity are kept in the extra data of the product'],
            ['prices by customer group', 'SELECT COUNT(*) FROM {specific_price} WHERE id_cart = 0 AND id_group > 0', [], 'Prices for one customer group are kept in the extra data of the product'],
            ['prices by country', 'SELECT COUNT(*) FROM {specific_price} WHERE id_cart = 0 AND id_country > 0', [], 'Prices for one country are kept in the extra data of the product'],
            ['suppliers', 'SELECT COUNT(*) FROM {supplier}', [], 'Suppliers have no equivalent'],
            ['physical stores', 'SELECT COUNT(*) FROM {store}', [], 'Physical stores have no equivalent'],
            ['warehouses', 'SELECT COUNT(*) FROM {warehouse}', [], 'Warehouses and advanced stock have no equivalent'],
            ['customer service messages', 'SELECT COUNT(*) FROM {customer_message}', [], 'Customer messages are not brought'],
            ['abandoned carts', 'SELECT COUNT(*) FROM {cart} c WHERE c.id_customer > 0 AND NOT EXISTS (SELECT 1 FROM {orders} o WHERE o.id_cart = c.id_cart)', [], 'Abandoned carts are not brought'],
        ];
        foreach ($counts as $item) {
            preg_match_all('/\{(\w+)\}/', $item[1], $m);
            $present = true;
            foreach ($m[1] as $table) {
                $present = $present && $this->db->tableExists($table);
            }
            if (!$present) {
                continue;
            }
            $count = (int) $this->scalar($item[1], $item[2]);
            if ($count > 0) {
                $out[] = ['kind' => 'feature', 'name' => $item[0], 'count' => $count, 'note' => $item[3]];
            }
        }
        $env = $this->env();
        $default = $env->cfgInt('PS_CURRENCY_DEFAULT');
        $extraCurrencies = count($env->currencies) - (isset($env->currencies[$default]) ? 1 : 0);
        if ($extraCurrencies > 0) {
            $out[] = ['kind' => 'feature', 'name' => 'additional currencies', 'count' => $extraCurrencies, 'note' => 'Only the default currency is imported'];
        }
        if (count($env->langs) > 1) {
            $out[] = ['kind' => 'feature', 'name' => 'additional languages', 'count' => count($env->langs) - 1, 'note' => 'Texts are brought in every language; the languages this site does not have are reported when writing'];
        }
        $behavior = $this->db->count($this->q('SELECT COUNT(*) FROM {tax_rule} WHERE behavior = 1'));
        if ($behavior > 0) {
            $out[] = ['kind' => 'feature', 'name' => 'tax rules that combine taxes', 'count' => $behavior, 'note' => 'The way of combining taxes is kept in the extra data of the rate'];
        }

        return $out;
    }
}
