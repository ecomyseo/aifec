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

use Aifec\Modules\Import\SourceReader;

/**
 * Lo común de los dos lectores de PrestaShop. Cada uno solo sabe ir a buscar las filas (por el servicio web o a la copia de la
 * base de datos) y las entrega en bruto, con los nombres de columna de PrestaShop; la conversión al formato neutro es de Mapper
 * y OrderMapper, de modo que el mismo producto, cliente o pedido sale igual lo lea quien lo lea.
 */
abstract class BaseReader implements SourceReader
{
    /** Las configuraciones de PrestaShop que se leen (el resto de la tabla, con módulos de todo tipo, no se toca). */
    protected const CONFIG_NAMES = [
        'PS_SHOP_NAME', 'PS_SHOP_EMAIL', 'PS_SHOP_PHONE', 'PS_SHOP_ADDR1', 'PS_SHOP_ADDR2', 'PS_SHOP_CODE', 'PS_SHOP_CITY', 'PS_SHOP_COUNTRY_ID',
        'PS_SHOP_STATE_ID', 'PS_SHOP_DETAILS', 'PS_SHOP_DOMAIN', 'PS_CURRENCY_DEFAULT', 'PS_LANG_DEFAULT', 'PS_COUNTRY_DEFAULT', 'PS_TIMEZONE',
        'PS_WEIGHT_UNIT', 'PS_DIMENSION_UNIT', 'PS_CUSTOMER_GROUP', 'PS_TAX_ADDRESS_TYPE', 'PS_TAX', 'PS_GUEST_CHECKOUT_ENABLED', 'PS_STOCK_MANAGEMENT',
        'PS_ORDER_OUT_OF_STOCK', 'PS_INVOICE_START_NUMBER', 'PS_MAIL_METHOD', 'PS_MAIL_SERVER', 'PS_MAIL_USER', 'PS_MAIL_PASSWD',
        'PS_MAIL_SMTP_ENCRYPTION', 'PS_MAIL_SMTP_PORT', 'PS_LOGO', 'PS_FAVICON', 'PS_CONDITIONS_CMS_ID', 'PS_CATALOG_MODE', 'PS_HOME_CATEGORY',
        'PS_ROOT_CATEGORY', 'PS_SHIPPING_METHOD', 'PS_SHIPPING_HANDLING', 'PS_SHIPPING_FREE_PRICE', 'PS_SHIPPING_FREE_WEIGHT', 'PS_VERSION_DB',
        'PS_SHOP_DEFAULT', 'PS_ROUTE_product_rule', 'PS_ROUTE_category_rule', 'PS_ROUTE_cms_rule', 'PS_ROUTE_manufacturer_rule',
        'PS_OS_CANCELED', 'PS_OS_REFUND', 'PS_OS_ERROR', 'PS_OS_OUTOFSTOCK_UNPAID', 'PS_OS_OUTOFSTOCK', 'PS_OS_BANKWIRE', 'PS_OS_CHEQUE',
        'PS_OS_COD_VALIDATION', 'PS_OS_OUTOFSTOCK_PAID', 'PS_OS_PAYMENT', 'PS_OS_WS_PAYMENT', 'PS_OS_PREPARATION', 'PS_OS_SHIPPING', 'PS_OS_DELIVERED',
    ];

    /** Las configuraciones que además tienen un valor por idioma. */
    protected const CONFIG_LANG_NAMES = ['PS_INVOICE_PREFIX', 'PS_CREDIT_SLIP_PREFIX'];

    /** @var array<string,mixed> */
    protected $config;

    /** @var Env|null */
    private $env;

    /** @var array<int,int>|null */
    private $emitted;

    /**
     * @param array<string,mixed> $config
     */
    protected function __construct(array $config)
    {
        // El instante «ahora» (para saber qué precio específico ha caducado) llega en la configuración; sin él, el reloj del sistema.
        if (!isset($config['now']) || !is_string($config['now']) || $config['now'] === '') {
            $config['now'] = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z');
        }
        $this->config = $config;
    }

    public function source(): string
    {
        return 'prestashop';
    }

    /** Las filas de un tipo en bruto (con «id») y el cursor siguiente. @return array{0:array<int,array<string,mixed>>,1:?string} */
    abstract protected function page(string $type, string $cursor, int $limit): array;

    /** Arma lo que hay que saber de la tienda (configuración, idiomas, impuestos…). */
    abstract protected function buildEnv(): Env;

    /** Título y descripción de la portada por idioma, si el origen los da. @return array{home_title:array<int,string>,home_description:array<int,string>} */
    abstract protected function settingsMeta(): array;

    /** Los países y provincias con su zona se piden al construir el entorno; esto devuelve las zonas activas en bruto. @return array<int,array<string,mixed>> */
    abstract protected function zoneRows(): array;

    public function env(): Env
    {
        if ($this->env === null) {
            $this->env = $this->buildEnv();
        }

        return $this->env;
    }

    /**
     * @return array<string,array{name:string,meaning:string}> estados de pedido para la tabla de equivalencias
     */
    public function orderStates(): array
    {
        return OrderMapper::states($this->env());
    }

    public function read(string $type, string $cursor, int $limit): array
    {
        if (!in_array($type, $this->types(), true)) {
            throw new \InvalidArgumentException('This reader cannot read ' . $type);
        }
        list($items, $next) = $this->page($type, $cursor, max(1, $limit));
        list($records, $skipped) = Batch::map($items, function (array $raw) use ($type): ?array {
            return $this->convert($type, $raw);
        });

        return Batch::result($records, $next, count($items), $skipped);
    }

    /**
     * @param array<string,mixed> $raw
     *
     * @return ?array<string,mixed>
     */
    protected function convert(string $type, array $raw): ?array
    {
        $env = $this->env();
        switch ($type) {
            case 'settings':
                return Mapper::settings($env, $this->settingsMeta());
            case 'tax_classes':
                return Mapper::taxClass($raw);
            case 'tax_rates':
                return Mapper::taxRate($raw, $env);
            case 'shipping_zones':
                return Mapper::zone($raw, $env, (int) $raw['position']);
            case 'carriers':
                return Mapper::carrier($raw, $env);
            case 'payment_methods':
                return Mapper::payment($raw, $env, (int) $raw['position']);
            case 'customer_groups':
                return Mapper::group($raw, $env);
            case 'brands':
                return Mapper::brand($raw, $env);
            case 'categories':
                return Mapper::category($raw, $env);
            case 'attributes':
                return Mapper::attribute($raw, $env);
            case 'products':
                return Mapper::product($raw, $env);
            case 'customers':
                return Mapper::customer($raw, $env);
            case 'coupons':
                return OrderMapper::coupon($raw, $env);
            case 'orders':
                return OrderMapper::order($raw, $env);
            case 'reviews':
                return Mapper::review($raw, $env);
            case 'pages':
                return Mapper::page($raw, $env);
            case 'redirects':
                return Mapper::redirect($raw);
            case 'team':
                return Mapper::employee($raw, $env);
            case 'subscribers':
                return Mapper::subscriber($raw, $env);
        }

        throw new \InvalidArgumentException('Unknown type ' . $type);
    }

    /**
     * Las zonas que se entregan: activas y con países o provincias asignados.
     *
     * @return array<int,array<string,mixed>>
     */
    protected function zonesWithLocations(): array
    {
        $out = [];
        foreach ($this->zoneRows() as $zone) {
            if (Mapper::b($zone['active']) && Mapper::zoneLocations($this->env(), (int) $zone['id']) !== []) {
                $out[] = $zone;
            }
        }

        return $out;
    }

    /** @return array<int,int> identificadores de las zonas que se entregan */
    protected function emittedZones(): array
    {
        if ($this->emitted === null) {
            $this->emitted = array_map(static function (array $zone): int {
                return (int) $zone['id'];
            }, $this->zonesWithLocations());
        }

        return $this->emitted;
    }

    /**
     * Resuelve la configuración de una tienda: el valor de la tienda gana al de su grupo y este al global.
     *
     * @param array<int,array<string,mixed>> $rows name, value, id_shop, id_shop_group
     *
     * @return array<string,string>
     */
    protected static function resolveConfig(array $rows, int $shopId, int $groupId): array
    {
        $best = [];
        foreach ($rows as $row) {
            $name = (string) $row['name'];
            $shop = (int) (isset($row['id_shop']) ? $row['id_shop'] : 0);
            $group = (int) (isset($row['id_shop_group']) ? $row['id_shop_group'] : 0);
            if ($shop > 0 && $shop !== $shopId) {
                continue;
            }
            if ($shop === 0 && $group > 0 && $group !== $groupId) {
                continue;
            }
            $level = $shop > 0 ? 3 : ($group > 0 ? 2 : 1);
            if (!isset($best[$name]) || $best[$name]['level'] < $level) {
                $best[$name] = ['level' => $level, 'value' => $row['value'] === null ? '' : (string) $row['value']];
            }
        }

        return array_map(static function (array $item): string {
            return $item['value'];
        }, $best);
    }

    /**
     * Rellena el entorno con los datos de la tienda que los dos lectores arman con el mismo formato.
     *
     * @param array<string,string>                           $cfg
     * @param array<string,array<int,string>>                $cfgLang
     * @param array<int,array<string,mixed>>                 $langs     id, iso_code, active
     * @param array<int,array<string,mixed>>                 $currencies id, iso_code
     * @param array<int,array<string,mixed>>                 $countries id, id_zone, iso_code, active
     * @param array<int,array<string,mixed>>                 $states    id, id_country, id_zone, iso_code, active
     * @param array<int,array<string,mixed>>                 $taxes     id, rate, name [idioma => texto]
     * @param array<int,array<string,mixed>>                 $orderStates id, paid, shipped, delivery, logable, name
     * @param array<int,array<string,mixed>>                 $groups    id, reduction, price_display_method, name
     */
    protected function fillEnv(Env $env, array $cfg, array $cfgLang, array $langs, array $currencies, array $countries, array $states, array $taxes, array $orderStates, array $groups): void
    {
        $env->cfg = $cfg;
        $env->cfgLang = $cfgLang;
        foreach ($langs as $lang) {
            $iso = strtolower(trim((string) $lang['iso_code']));
            $env->allLangs[(int) $lang['id']] = $iso;
            if (Mapper::b($lang['active'])) {
                $env->langs[(int) $lang['id']] = $iso;
            }
        }
        $env->defaultLang = (int) ($cfg['PS_LANG_DEFAULT'] ?? 0);
        if (!isset($env->allLangs[$env->defaultLang]) && $env->allLangs !== []) {
            $keys = array_keys($env->allLangs);
            $env->defaultLang = (int) $keys[0];
        }
        foreach ($currencies as $currency) {
            $env->currencies[(int) $currency['id']] = strtoupper(trim((string) $currency['iso_code']));
        }
        foreach ($countries as $country) {
            $env->countries[(int) $country['id']] = [
                'iso' => strtoupper(trim((string) $country['iso_code'])),
                'zone' => (int) $country['id_zone'],
                'states' => Mapper::b(isset($country['contains_states']) ? $country['contains_states'] : 0),
                'active' => Mapper::b($country['active']),
            ];
        }
        foreach ($states as $state) {
            $env->states[(int) $state['id']] = [
                'iso' => trim((string) $state['iso_code']),
                'country' => (int) $state['id_country'],
                'zone' => (int) $state['id_zone'],
                'active' => Mapper::b($state['active']),
            ];
        }
        foreach ($taxes as $tax) {
            $env->taxes[(int) $tax['id']] = ['rate' => Mapper::s($tax['rate']), 'name' => is_array($tax['name']) ? $tax['name'] : []];
        }
        foreach ($orderStates as $state) {
            $env->orderStates[(int) $state['id']] = [
                'name' => is_array($state['name']) ? $state['name'] : [],
                'paid' => Mapper::b($state['paid']),
                'shipped' => Mapper::b($state['shipped']),
                'delivery' => Mapper::b($state['delivery']),
                'logable' => Mapper::b($state['logable']),
            ];
        }
        foreach ($groups as $group) {
            $env->groups[(int) $group['id']] = [
                'display_with_tax' => (int) $group['price_display_method'] === 0,
                'name' => is_array($group['name']) ? $group['name'] : [],
                'reduction' => Mapper::s($group['reduction']),
            ];
        }
        $given = isset($this->config['timezone']) ? trim((string) $this->config['timezone']) : '';
        $env->timezone = $given !== '' ? $given : (isset($cfg['PS_TIMEZONE']) && $cfg['PS_TIMEZONE'] !== '' ? $cfg['PS_TIMEZONE'] : 'UTC');
        $env->weightUnit = strtolower($cfg['PS_WEIGHT_UNIT'] ?? 'kg');
        $env->dimensionUnit = strtolower($cfg['PS_DIMENSION_UNIT'] ?? 'cm');
        $env->version = $cfg['PS_VERSION_DB'] ?? '';
        $env->now = (string) $this->config['now'];
        $env->cfg['_cookie_key_given'] = isset($this->config['cookie_key']) && (string) $this->config['cookie_key'] !== '' ? '1' : '0';
    }

    /**
     * Una lista de filas de idioma a [id de idioma => valor] de una columna.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array<int,string>
     */
    protected static function langMap(array $rows, string $column, string $langColumn = 'id_lang'): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (isset($row[$langColumn]) && array_key_exists($column, $row)) {
                $out[(int) $row[$langColumn]] = $row[$column] === null ? '' : (string) $row[$column];
            }
        }

        return $out;
    }

    /** Versión mayor de PrestaShop que dice una cadena («8.2.0» da 8). */
    protected static function major(string $version): int
    {
        return (int) $version;
    }
}
