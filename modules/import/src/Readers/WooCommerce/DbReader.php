<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Import\Readers\WooCommerce;

use Aifec\Modules\Import\SourceDenied;
use Aifec\Modules\Import\SourceError;
use Aifec\Modules\Import\SourceReader;

/**
 * Lee una tienda de WooCommerce de una copia de su base de datos (un \PDO ya abierto, solo lectura) y, si se indica, de la carpeta
 * de ficheros de la copia. Arma las mismas formas que entrega la API (wc/v3 y wp/v2) y las pasa por el mismo Mapper, de modo que
 * para el mismo producto, cliente o pedido sale el mismo registro; añade lo que la API no da: el resumen de la contraseña de los
 * clientes, las redirecciones de Redirection y de Rank Math y los metadatos de Yoast y de Rank Math.
 */
final class DbReader implements SourceReader
{
    /** Datos extra de un producto o de una variación que se leen. */
    private const PRODUCT_META = [
        '_sku', '_global_unique_id', '_regular_price', '_sale_price', '_price', '_sale_price_dates_from', '_sale_price_dates_to', '_virtual',
        '_downloadable', '_downloadable_files', '_download_limit', '_download_expiry', '_manage_stock', '_stock', '_stock_status', '_backorders',
        '_weight', '_length', '_width', '_height', '_tax_status', '_tax_class', '_sold_individually', '_upsell_ids', '_crosssell_ids', '_related_ids',
        '_purchase_note', '_product_image_gallery', '_thumbnail_id', '_product_attributes', '_product_url', '_button_text', '_children',
        '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_primary_product_cat', 'rank_math_title', 'rank_math_description',
    ];

    /** Datos extra de SEO de una página, una entrada o un producto. */
    private const SEO_META = ['_yoast_wpseo_title', '_yoast_wpseo_metadesc', 'rank_math_title', 'rank_math_description'];

    /** Datos extra del identificador fiscal de un cliente. */
    private const VAT_META = ['billing_nif', '_billing_nif', 'billing_vat', '_billing_vat', 'billing_cif', '_billing_cif', 'billing_vat_number', 'vat_number', 'billing_dni'];

    /** Ajustes de WooCommerce y de WordPress que se leen. */
    private const SETTING_OPTIONS = [
        'blogname', 'blogdescription', 'timezone_string', 'gmt_offset', 'admin_email', 'site_logo', 'site_icon', 'wp_page_for_privacy_policy', 'WPLANG', 'siteurl',
        'woocommerce_store_address', 'woocommerce_store_address_2', 'woocommerce_store_city', 'woocommerce_default_country', 'woocommerce_store_postcode',
        'woocommerce_currency', 'woocommerce_currency_pos', 'woocommerce_price_num_decimals', 'woocommerce_weight_unit', 'woocommerce_dimension_unit',
        'woocommerce_prices_include_tax', 'woocommerce_tax_based_on', 'woocommerce_tax_display_shop', 'woocommerce_calc_taxes',
        'woocommerce_enable_guest_checkout', 'woocommerce_manage_stock', 'woocommerce_email_from_name', 'woocommerce_email_from_address',
        'woocommerce_terms_page_id', 'woocommerce_refund_returns_page_id', 'woocommerce_version',
    ];

    /** Papeles de usuario que trae WordPress y WooCommerce: los demás se apuntan como sin traer. */
    private const STANDARD_ROLES = ['administrator', 'editor', 'author', 'contributor', 'subscriber', 'customer', 'shop_manager'];

    /** @var Database */
    private $db;

    /** @var array<string,mixed> */
    private $config;

    /** @var string carpeta de ficheros de la copia, sin barra final */
    private $files;

    /** @var ?Mapper */
    private $mapper;

    /** @var ?Permalinks */
    private $permalinks;

    /** @var ?bool */
    private $hpos;

    /** @var array<string,array<int,array<string,mixed>>> */
    private $termIndex = [];

    /** @var array<int,array<string,mixed>>|null */
    private $attributeTaxonomies;

    /** @var array<string,array<int,array<string,mixed>>> */
    private $taxonomyTerms = [];

    /** @var array<int,array<string,mixed>> */
    private $pageIndex = [];

    /** @var array<string,string> */
    private $labels = [];

    public static function describe(): array
    {
        return [
            'source' => 'woocommerce', 'mode' => 'db', 'label' => 'WooCommerce (copy of the database)',
            'help' => 'Reads a copy of the shop database and, if given, a copy of its files folder. It also brings what the API does not give: the customers\' password hashes, redirects and SEO data.',
            'fields' => [
                'prefix' => ['type' => 'text', 'label' => 'Table prefix', 'required' => false, 'hint' => 'wp_', 'default' => 'wp_'],
                'shop' => ['type' => 'text', 'label' => 'Site number (multisite only)', 'required' => false, 'hint' => '2'],
                'files' => ['type' => 'path', 'label' => 'Folder with the copy of the files', 'required' => false, 'hint' => 'The folder that holds wp-content'],
                'base_url' => ['type' => 'url', 'label' => 'Address of the old shop (to download images)', 'required' => false, 'hint' => 'https://shop.example'],
                'timezone' => ['type' => 'text', 'label' => 'Time zone (if the shop does not say)', 'required' => false, 'hint' => 'Europe/Madrid'],
                'locale' => ['type' => 'text', 'label' => 'Shop language (if the shop does not say)', 'required' => false, 'hint' => 'es_ES'],
                'brand_taxonomy' => ['type' => 'text', 'label' => 'Brand taxonomy', 'required' => false, 'hint' => 'product_brand', 'default' => 'product_brand'],
            ],
        ];
    }

    /**
     * @param mixed               $connection un \PDO ya abierto, de solo lectura
     * @param array<string,mixed> $config
     */
    public function __construct($connection, array $config)
    {
        if (!$connection instanceof \PDO) {
            throw new \InvalidArgumentException('A PDO connection is required');
        }
        $this->config = $config;
        $base = (string) ($config['prefix'] ?? 'wp_');
        $base = $base === '' ? 'wp_' : $base;
        $shop = trim((string) ($config['shop'] ?? ''));
        $site = $shop !== '' && $shop !== '1' && preg_match('/^\d+$/', $shop) === 1 ? $base . $shop . '_' : $base;
        $this->db = new Database($connection, $base, $site);
        $files = trim((string) ($config['files'] ?? ''));
        $this->files = $files === '' ? '' : rtrim(str_replace('\\', '/', $files), '/');
    }

    public function source(): string
    {
        return 'woocommerce';
    }

    public function mode(): string
    {
        return 'db';
    }

    public function key(): string
    {
        $database = trim((string) ($this->config['database'] ?? ''));

        return substr(($database !== '' ? $database : $this->db->driver()) . ':' . $this->db->sitePrefix(), 0, 60);
    }

    public function types(): array
    {
        return [
            'settings', 'tax_classes', 'tax_rates', 'shipping_zones', 'carriers', 'payment_methods', 'brands', 'categories', 'attributes', 'products',
            'customers', 'coupons', 'orders', 'reviews', 'media', 'pages', 'post_categories', 'posts', 'menus', 'redirects', 'team',
        ];
    }

    // ------------------------------------------------------------------ la tienda: traductor y enlaces

    private function baseUrl(): string
    {
        $url = trim((string) ($this->config['base_url'] ?? ''));
        if ($url === '') {
            $url = (string) $this->db->option('siteurl');
        }

        return rtrim($url, '/');
    }

    /** @return array<string,string> los ajustes que existen */
    private function settingsMap(): array
    {
        $map = [];
        foreach ($this->db->options(self::SETTING_OPTIONS) as $name => $value) {
            if ($value !== null) {
                $map[$name] = $value;
            }
        }

        return $map;
    }

    private function mapper(): Mapper
    {
        if ($this->mapper === null) {
            $o = $this->settingsMap();
            $legal = [];
            foreach (['woocommerce_terms_page_id' => 'terms', 'woocommerce_refund_returns_page_id' => 'returns', 'wp_page_for_privacy_policy' => 'privacy'] as $option => $kind) {
                if ((int) ($o[$option] ?? 0) > 0) {
                    $legal[(int) $o[$option]] = $kind;
                }
            }
            $timezone = trim((string) ($this->config['timezone'] ?? ''));
            $this->mapper = new Mapper([
                'locale' => trim((string) ($this->config['locale'] ?? '')) !== '' ? (string) $this->config['locale'] : ($o['WPLANG'] ?? ''),
                'timezone' => $timezone !== '' ? $timezone : Mapper::timezone($o['timezone_string'] ?? '', $o['gmt_offset'] ?? 0),
                'weight_unit' => $o['woocommerce_weight_unit'] ?? 'kg', 'dimension_unit' => $o['woocommerce_dimension_unit'] ?? 'cm',
                'prices_include_tax' => ($o['woocommerce_prices_include_tax'] ?? 'no') === 'yes', 'legal_pages' => $legal, 'site_url' => $this->baseUrl(),
            ]);
        }

        return $this->mapper;
    }

    private function permalinks(): Permalinks
    {
        if ($this->permalinks === null) {
            $this->permalinks = new Permalinks((string) $this->db->option('permalink_structure'), $this->db->optionArray('woocommerce_permalinks'), (string) $this->db->option('category_base'));
        }

        return $this->permalinks;
    }

    private function hpos(): bool
    {
        if ($this->hpos === null) {
            $this->hpos = $this->db->option('woocommerce_custom_orders_table_enabled') === 'yes' && $this->db->tableExists('wc_orders')
                && $this->db->rows('SELECT id FROM ' . $this->db->t('wc_orders') . ' LIMIT 1') !== [];
        }

        return $this->hpos;
    }

    // ------------------------------------------------------------------ comprobar, contar, sumas, sin traer

    public function probe(): array
    {
        $result = ['ok' => false, 'message' => '', 'version' => '', 'shops' => [], 'languages' => [], 'currency' => '', 'warnings' => [], 'counts' => []];
        try {
            if (!$this->db->tableExists('posts') || !$this->db->tableExists('options')) {
                $result['message'] = 'The WordPress tables were not found: check the table prefix';

                return $result;
            }
            $o = $this->settingsMap();
            $products = $this->count('products');
            $first = $this->read('products', '', 1);
            $result['counts'] = ['products' => (int) $products, 'orders' => (int) $this->count('orders'), 'customers' => (int) $this->count('customers')];
            $result['version'] = 'WooCommerce ' . ($o['woocommerce_version'] ?? '?') . ', HPOS ' . ($this->hpos() ? 'on' : 'off');
            $result['currency'] = $o['woocommerce_currency'] ?? '';
            $result['languages'] = [$this->mapper()->language()];
            if ($this->db->tableExists('blogs', true)) {
                foreach ($this->db->rows('SELECT blog_id, domain, path FROM ' . $this->db->u('blogs') . ' ORDER BY blog_id') as $blog) {
                    $result['shops'][] = ['id' => (string) $blog['blog_id'], 'name' => (string) $blog['domain'] . (string) $blog['path']];
                }
            }
            foreach (Mapper::unmappedPlugins($this->plugins()) as $entry) {
                if (strpos($entry['note'], 'Multilingual') === 0) {
                    $result['warnings'][] = 'WPML or Polylang found: only the main language is brought and the other languages are not';
                }
            }
        } catch (SourceDenied $e) {
            $result['message'] = 'The database user has no permission to read the shop data';

            return $result;
        } catch (SourceError $e) {
            $result['message'] = 'The database could not be read';

            return $result;
        }
        if ($products < 1 || ($first['records'] === [] && $first['skipped'] === [])) {
            $result['message'] = 'The database answered but it has no products to read';

            return $result;
        }
        $result['ok'] = true;
        $result['message'] = 'Connected to the database';

        return $result;
    }

    public function count(string $type): ?int
    {
        $posts = $this->db->t('posts');
        $gone = "('trash', 'auto-draft')";
        switch ($type) {
            case 'products':
                return $this->db->count('SELECT COUNT(*) FROM ' . $posts . " WHERE post_type = 'product' AND post_status NOT IN " . $gone);
            case 'categories':
            case 'brands':
            case 'post_categories':
                $taxonomy = $type === 'categories' ? 'product_cat' : ($type === 'brands' ? $this->brandTaxonomy() : 'category');

                return $this->db->count('SELECT COUNT(*) FROM ' . $this->db->t('term_taxonomy') . ' WHERE taxonomy = ?', [$taxonomy]);
            case 'customers':
                $capabilities = $this->db->u('usermeta');

                return $this->db->count('SELECT COUNT(*) FROM ' . $this->db->u('users') . ' WHERE ID IN (SELECT user_id FROM ' . $capabilities . ' WHERE meta_key = ? AND meta_value LIKE ?)', [$this->db->sitePrefix() . 'capabilities', '%"customer"%']);
            case 'coupons':
                return $this->db->count('SELECT COUNT(*) FROM ' . $posts . " WHERE post_type = 'shop_coupon' AND post_status NOT IN " . $gone);
            case 'orders':
                if ($this->hpos()) {
                    return $this->db->count('SELECT COUNT(*) FROM ' . $this->db->t('wc_orders') . " WHERE type = 'shop_order' AND status NOT IN ('trash', 'auto-draft', 'checkout-draft', 'wc-checkout-draft')");
                }

                return $this->db->count('SELECT COUNT(*) FROM ' . $posts . " WHERE post_type = 'shop_order' AND post_status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft')");
            case 'tax_rates':
                return $this->db->tableExists('woocommerce_tax_rates') ? $this->db->count('SELECT COUNT(*) FROM ' . $this->db->t('woocommerce_tax_rates')) : 0;
            case 'pages':
            case 'posts':
                return $this->db->count('SELECT COUNT(*) FROM ' . $posts . ' WHERE post_type = ? AND post_status NOT IN (\'trash\', \'auto-draft\', \'inherit\')', [$type === 'pages' ? 'page' : 'post']);
            case 'media':
                return $this->db->count('SELECT COUNT(*) FROM ' . $posts . " WHERE post_type = 'attachment'");
            case 'reviews':
                return $this->db->count('SELECT COUNT(*) FROM ' . $this->db->t('comments') . " WHERE comment_type = 'review' AND comment_approved <> 'trash'");
        }

        return null;
    }

    public function checksums(): array
    {
        $posts = $this->db->t('posts');
        $sums = [];
        foreach (['products' => 'products_count', 'categories' => 'categories_count', 'customers' => 'customers_count', 'orders' => 'orders_count', 'coupons' => 'coupons_count', 'tax_rates' => 'tax_rates_count', 'pages' => 'pages_count', 'posts' => 'posts_count'] as $type => $name) {
            $sums[$name] = (int) $this->count($type);
        }
        $sums['variants_count'] = $this->db->count('SELECT COUNT(*) FROM ' . $posts . " WHERE post_type = 'product_variation' AND post_status IN ('publish', 'private')");

        $orders = $this->hpos() ? 'SELECT id FROM ' . $this->db->t('wc_orders') . " WHERE type = 'shop_order' AND status NOT IN ('trash', 'auto-draft', 'checkout-draft', 'wc-checkout-draft')"
            : 'SELECT ID FROM ' . $posts . " WHERE post_type = 'shop_order' AND post_status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft')";
        $sums['order_items_count'] = $this->db->count('SELECT COUNT(*) FROM ' . $this->db->t('woocommerce_order_items') . " WHERE order_item_type IN ('line_item', 'shipping', 'fee') AND order_id IN (" . $orders . ')');

        $total = 0;
        $cursor = '';
        do {
            list($rows, $cursor) = $this->hpos()
                ? $this->db->page('SELECT id, total_amount AS amount FROM ' . $this->db->t('wc_orders'), "type = 'shop_order' AND status NOT IN ('trash', 'auto-draft', 'checkout-draft', 'wc-checkout-draft')", [], 'id', 'id', (string) $cursor, 500)
                : $this->db->page('SELECT p.ID, m.meta_value AS amount FROM ' . $posts . ' p JOIN ' . $this->db->t('postmeta') . " m ON m.post_id = p.ID AND m.meta_key = '_order_total'", "p.post_type = 'shop_order' AND p.post_status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft')", [], 'p.ID', 'ID', (string) $cursor, 500);
            foreach ($rows as $row) {
                $total += \Aifec\Modules\Import\Neutral::cents((string) $row['amount']);
            }
        } while ($cursor !== null);
        $sums['orders_total'] = $total;

        $stock = 0;
        $cursor = '';
        do {
            list($rows, $cursor) = $this->db->page(
                'SELECT p.ID, m.meta_value AS stock FROM ' . $posts . ' p JOIN ' . $this->db->t('postmeta') . " m ON m.post_id = p.ID AND m.meta_key = '_stock' JOIN " . $this->db->t('postmeta') . " g ON g.post_id = p.ID AND g.meta_key = '_manage_stock' AND g.meta_value = 'yes'",
                "p.post_type IN ('product', 'product_variation') AND p.post_status NOT IN ('trash', 'auto-draft')",
                [],
                'p.ID',
                'ID',
                (string) $cursor,
                500
            );
            foreach ($rows as $row) {
                $stock += is_numeric($row['stock']) ? (int) $row['stock'] : 0;
            }
        } while ($cursor !== null);
        $sums['stock_total'] = $stock;

        return $sums;
    }

    /**
     * @return array<int,array{slug:string,name:string}>
     */
    private function plugins(): array
    {
        $out = [];
        foreach ($this->db->optionArray('active_plugins') as $path) {
            if (!is_string($path) || $path === '') {
                continue;
            }
            $slug = strpos($path, '/') !== false ? substr($path, 0, (int) strpos($path, '/')) : (string) preg_replace('/\.php$/', '', $path);
            $out[] = ['slug' => $slug, 'name' => $this->pluginName($path, $slug)];
        }

        return $out;
    }

    /** El nombre del plugin: la cabecera de su fichero si está la carpeta de ficheros; si no, su carpeta con otro aspecto. */
    private function pluginName(string $path, string $slug): string
    {
        $file = $this->files !== '' ? $this->files . '/wp-content/plugins/' . $path : '';
        if ($file !== '' && is_file($file)) {
            $head = (string) file_get_contents($file, false, null, 0, 8192);
            if (preg_match('/^[ \t\/*#@]*Plugin Name:\s*(.+)$/mi', $head, $m) === 1 && trim($m[1]) !== '') {
                return trim($m[1]);
            }
        }

        return ucwords(str_replace(['-', '_'], ' ', $slug));
    }

    public function unmapped(): array
    {
        $out = Mapper::unmappedPlugins($this->plugins());
        $grouped = $this->db->count(
            'SELECT COUNT(*) FROM ' . $this->db->t('term_relationships') . ' tr JOIN ' . $this->db->t('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN '
            . $this->db->t('terms') . " t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'product_type' AND t.slug = 'grouped'"
        );
        if ($grouped > 0) {
            $out[] = ['kind' => 'feature', 'name' => 'grouped products', 'count' => $grouped, 'note' => 'Brought as simple products; their grouping is not'];
        }
        foreach ($this->db->optionArray($this->db->sitePrefix() . 'user_roles') as $role => $unused) {
            if (!is_string($role) || in_array($role, self::STANDARD_ROLES, true)) {
                continue;
            }
            $users = $this->db->count('SELECT COUNT(*) FROM ' . $this->db->u('usermeta') . ' WHERE meta_key = ? AND meta_value LIKE ?', [$this->db->sitePrefix() . 'capabilities', '%"' . $role . '"%']);
            $out[] = ['kind' => 'role', 'name' => $role, 'count' => $users, 'note' => 'Custom user role: there are no customer groups to bring it to'];
        }

        return $out;
    }

    // ------------------------------------------------------------------ leer

    public function read(string $type, string $cursor, int $limit): array
    {
        switch ($type) {
            case 'settings':
                return $this->fromList([['id' => 'main']], $cursor, $limit, function (): array {
                    return $this->mapper()->settings($this->settingsMap(), $this->siteData());
                });
            case 'tax_classes':
                return $this->fromList($this->taxClasses(), $cursor, $limit, function (array $c): array {
                    return $this->mapper()->taxClass((string) $c['slug'], (string) $c['name']);
                }, 'slug');
            case 'tax_rates':
                return $this->readTaxRates($cursor, $limit);
            case 'shipping_zones':
                return $this->fromList($this->zones(), $cursor, $limit, function (array $z): array {
                    return $this->mapper()->zone($z['zone'], $z['locations']);
                }, 'key');
            case 'carriers':
                return $this->fromList($this->methods(), $cursor, $limit, function (array $m): array {
                    return $this->mapper()->carrier($m['zone'], $m['method']);
                }, 'key');
            case 'payment_methods':
                return $this->fromList($this->gateways(), $cursor, $limit, function (array $g): array {
                    return $this->mapper()->paymentMethod($g);
                });
            case 'brands':
                return $this->readBrands($cursor, $limit);
            case 'categories':
                return $this->readCategories($cursor, $limit);
            case 'attributes':
                return $this->readAttributes($cursor, $limit);
            case 'products':
                return $this->readProducts($cursor, $limit);
            case 'customers':
                return $this->readCustomers($cursor, $limit);
            case 'coupons':
                return $this->readCoupons($cursor, $limit);
            case 'orders':
                return $this->readOrders($cursor, $limit);
            case 'reviews':
                return $this->readReviews($cursor, $limit);
            case 'media':
                return $this->readMedia($cursor, $limit);
            case 'pages':
                return $this->readPages($cursor, $limit);
            case 'post_categories':
                return $this->readPostCategories($cursor, $limit);
            case 'posts':
                return $this->readPosts($cursor, $limit);
            case 'menus':
                return $this->fromList($this->menus(), $cursor, $limit, function (array $m): array {
                    return $this->mapper()->menu($m['menu'], $m['items']);
                }, 'key');
            case 'redirects':
                return $this->readRedirects($cursor, $limit);
            case 'team':
                return $this->readTeam($cursor, $limit);
        }
        throw new \InvalidArgumentException('This reader cannot read ' . $type);
    }

    /**
     * Un tipo pequeño que está entero en memoria.
     *
     * @param array<int,mixed> $items
     *
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function fromList(array $items, string $cursor, int $limit, callable $convert, string $idKey = 'id'): array
    {
        list($page, $next) = Batch::slice($items, $cursor, $limit);
        list($records, $skipped) = Batch::map($page, $convert, $idKey);

        return Batch::result($records, $next, count($page), $skipped);
    }

    /**
     * Una tanda por clave de filas ya leídas.
     *
     * @param array<int,array<string,mixed>> $rows
     *
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function fromRows(array $rows, ?string $next, callable $convert, string $idKey): array
    {
        list($records, $skipped) = Batch::map($rows, $convert, $idKey);

        return Batch::result($records, $next, count($rows), $skipped);
    }

    // ------------------------------------------------------------------ utilidades de lectura

    /** Un instante en segundos desde 1970 a «AAAA-MM-DD HH:MM:SS» en GMT; vacío si no hay. @param mixed $value */
    private function stamp($value): string
    {
        $seconds = is_numeric($value) ? (int) $value : 0;

        return $seconds > 0 ? gmdate('Y-m-d H:i:s', $seconds) : '';
    }

    /**
     * @param mixed $value lista separada por comas («12,15»)
     *
     * @return int[]
     */
    private function csvIds($value): array
    {
        $out = [];
        foreach (explode(',', (string) $value) as $id) {
            if ((int) trim($id) > 0) {
                $out[] = (int) trim($id);
            }
        }

        return $out;
    }

    /**
     * @param array<mixed,mixed> $value
     *
     * @return int[]
     */
    private function intList(array $value): array
    {
        $out = [];
        foreach ($value as $id) {
            if (is_scalar($id) && (int) $id > 0) {
                $out[] = (int) $id;
            }
        }

        return $out;
    }

    /**
     * Los términos de varios objetos: id de objeto => taxonomía => [{id, name, slug, parent}].
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,array<int,array<string,mixed>>>>
     */
    private function termsFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $out = [];
        $sql = 'SELECT tr.object_id AS oid, tt.taxonomy AS taxonomy, t.term_id AS term_id, t.name AS name, t.slug AS slug, tt.parent AS parent FROM '
            . $this->db->t('term_relationships') . ' tr JOIN ' . $this->db->t('term_taxonomy') . ' tt ON tt.term_taxonomy_id = tr.term_taxonomy_id JOIN '
            . $this->db->t('terms') . ' t ON t.term_id = tt.term_id WHERE tr.object_id IN (' . $this->db->marks(count($ids)) . ') ORDER BY t.term_id';
        foreach ($this->db->rows($sql, $ids) as $row) {
            $out[(int) $row['oid']][(string) $row['taxonomy']][] = ['id' => (int) $row['term_id'], 'name' => (string) $row['name'], 'slug' => (string) $row['slug'], 'parent' => (int) $row['parent']];
        }

        return $out;
    }

    /**
     * Todos los términos de una taxonomía: id => {slug, name, parent, description}.
     *
     * @return array<int,array<string,mixed>>
     */
    private function termIndex(string $taxonomy): array
    {
        if (!isset($this->termIndex[$taxonomy])) {
            $this->termIndex[$taxonomy] = [];
            $sql = 'SELECT t.term_id, t.name, t.slug, tt.parent, tt.description FROM ' . $this->db->t('terms') . ' t JOIN ' . $this->db->t('term_taxonomy') . ' tt ON tt.term_id = t.term_id WHERE tt.taxonomy = ? ORDER BY t.term_id';
            foreach ($this->db->rows($sql, [$taxonomy]) as $row) {
                $this->termIndex[$taxonomy][(int) $row['term_id']] = ['slug' => (string) $row['slug'], 'name' => (string) $row['name'], 'parent' => (int) $row['parent'], 'description' => (string) $row['description']];
            }
        }

        return $this->termIndex[$taxonomy];
    }

    /** La ruta jerárquica de un término («muebles/mesas»). */
    private function termPath(string $taxonomy, int $id): string
    {
        $index = $this->termIndex($taxonomy);
        $parts = [];
        for ($guard = 0; $id > 0 && isset($index[$id]) && $guard < 20; ++$guard) {
            array_unshift($parts, $index[$id]['slug']);
            $id = (int) $index[$id]['parent'];
        }

        return implode('/', $parts);
    }

    /**
     * Las imágenes (adjuntos) de una lista de identificadores: id => {id, src, alt, path}.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    private function images(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        $out = [];
        $meta = $this->db->meta('postmeta', 'post_id', $ids, ['_wp_attached_file', '_wp_attachment_image_alt']);
        foreach ($ids as $id) {
            $file = trim($meta[$id]['_wp_attached_file'] ?? '');
            if ($file === '') {
                continue;
            }
            $image = ['id' => $id, 'src' => $this->baseUrl() !== '' ? $this->baseUrl() . '/wp-content/uploads/' . $file : '', 'alt' => $meta[$id]['_wp_attachment_image_alt'] ?? ''];
            $local = $this->localFile($file);
            if ($local !== null) {
                $image['path'] = $local;
            }
            $out[$id] = $image;
        }

        return $out;
    }

    /** La ruta del fichero en la copia de ficheros, si está. */
    private function localFile(string $file): ?string
    {
        if ($this->files === '') {
            return null;
        }
        foreach ([$this->files . '/wp-content/uploads/' . $file, $this->files . '/uploads/' . $file, $this->files . '/' . $file] as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function brandTaxonomy(): string
    {
        $taxonomy = trim((string) ($this->config['brand_taxonomy'] ?? ''));

        return $taxonomy !== '' && preg_match('/^[a-z0-9_\-]+$/', $taxonomy) === 1 ? $taxonomy : 'product_brand';
    }

    // ------------------------------------------------------------------ configuración

    /** @return array<string,mixed> */
    private function siteData(): array
    {
        $o = $this->settingsMap();
        $data = [
            'title' => $o['blogname'] ?? '', 'tagline' => $o['blogdescription'] ?? '', 'timezone_string' => $o['timezone_string'] ?? '',
            'gmt_offset' => $o['gmt_offset'] ?? 0, 'admin_email' => $o['admin_email'] ?? '',
        ];
        $ids = [(int) ($o['site_logo'] ?? 0), (int) ($o['site_icon'] ?? 0)];
        $images = $this->images($ids);
        foreach (['logo' => 0, 'icon' => 1] as $field => $at) {
            $image = $images[$ids[$at]] ?? null;
            if ($image !== null) {
                $data[$field] = isset($image['path']) ? ['path' => $image['path']] : ['url' => $image['src']];
            }
        }
        $last = $this->hpos() ? $this->db->count('SELECT MAX(id) FROM ' . $this->db->t('wc_orders') . " WHERE type = 'shop_order' AND status NOT IN ('trash', 'auto-draft', 'checkout-draft', 'wc-checkout-draft')")
            : $this->db->count('SELECT MAX(ID) FROM ' . $this->db->t('posts') . " WHERE post_type = 'shop_order' AND post_status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft')");
        if ($last > 0) {
            $data['next_order_number'] = $last + 1;
        }

        return $data;
    }

    /** @return array<int,array<string,string>> */
    private function taxClasses(): array
    {
        $classes = [['slug' => 'standard', 'name' => 'Standard']];
        if ($this->db->tableExists('wc_tax_rate_classes')) {
            foreach ($this->db->rows('SELECT slug, name FROM ' . $this->db->t('wc_tax_rate_classes') . ' ORDER BY tax_rate_class_id') as $row) {
                $classes[] = ['slug' => (string) $row['slug'], 'name' => (string) $row['name']];
            }
        } else {
            foreach (explode("\n", (string) $this->db->option('woocommerce_tax_classes')) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $classes[] = ['slug' => Mapper::slug($name), 'name' => $name];
                }
            }
        }

        return $classes;
    }

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readTaxRates(string $cursor, int $limit): array
    {
        if (!$this->db->tableExists('woocommerce_tax_rates')) {
            return Batch::result([], null, 0, []);
        }
        list($rows, $next) = $this->db->page(
            'SELECT tax_rate_id, tax_rate_country, tax_rate_state, tax_rate, tax_rate_name, tax_rate_priority, tax_rate_compound, tax_rate_shipping, tax_rate_class FROM ' . $this->db->t('woocommerce_tax_rates'),
            '',
            [],
            'tax_rate_id',
            'tax_rate_id',
            $cursor,
            $limit
        );
        $ids = array_map(static function (array $r): int {
            return (int) $r['tax_rate_id'];
        }, $rows);
        $postcodes = [];
        $cities = [];
        if ($ids !== [] && $this->db->tableExists('woocommerce_tax_rate_locations')) {
            $sql = 'SELECT tax_rate_id, location_code, location_type FROM ' . $this->db->t('woocommerce_tax_rate_locations') . ' WHERE tax_rate_id IN (' . $this->db->marks(count($ids)) . ') ORDER BY location_id';
            foreach ($this->db->rows($sql, $ids) as $location) {
                if ((string) $location['location_type'] === 'postcode') {
                    $postcodes[(int) $location['tax_rate_id']][] = (string) $location['location_code'];
                } else {
                    $cities[(int) $location['tax_rate_id']][] = (string) $location['location_code'];
                }
            }
        }
        $shaped = [];
        foreach ($rows as $row) {
            $id = (int) $row['tax_rate_id'];
            $shaped[] = [
                'id' => $id, 'country' => (string) $row['tax_rate_country'], 'state' => (string) $row['tax_rate_state'], 'postcodes' => $postcodes[$id] ?? [],
                'cities' => $cities[$id] ?? [], 'rate' => (string) $row['tax_rate'], 'name' => (string) $row['tax_rate_name'], 'priority' => (int) $row['tax_rate_priority'],
                'compound' => (string) $row['tax_rate_compound'] === '1', 'shipping' => (string) $row['tax_rate_shipping'] === '1', 'class' => (string) $row['tax_rate_class'],
            ];
        }

        return $this->fromRows($shaped, $next, function (array $t): array {
            return $this->mapper()->taxRate($t);
        }, 'id');
    }

    /**
     * Las zonas de envío con sus ubicaciones; la zona 0 («resto del mundo») si tiene métodos.
     *
     * @return array<int,array<string,mixed>>
     */
    private function zones(): array
    {
        if (!$this->db->tableExists('woocommerce_shipping_zones')) {
            return [];
        }
        $locations = [];
        foreach ($this->db->rows('SELECT zone_id, location_code, location_type FROM ' . $this->db->t('woocommerce_shipping_zone_locations') . ' ORDER BY location_id') as $row) {
            $locations[(int) $row['zone_id']][] = ['code' => (string) $row['location_code'], 'type' => (string) $row['location_type']];
        }
        $zones = [];
        foreach ($this->db->rows('SELECT zone_id, zone_name, zone_order FROM ' . $this->db->t('woocommerce_shipping_zones') . ' ORDER BY zone_id') as $row) {
            $id = (int) $row['zone_id'];
            $zones[] = ['key' => (string) $id, 'zone' => ['id' => $id, 'name' => (string) $row['zone_name'], 'order' => (int) $row['zone_order']], 'locations' => $locations[$id] ?? []];
        }
        if ($this->db->count('SELECT COUNT(*) FROM ' . $this->db->t('woocommerce_shipping_zone_methods') . ' WHERE zone_id = 0') > 0) {
            $zones[] = ['key' => '0', 'zone' => ['id' => 0, 'name' => 'Locations not covered by your other zones', 'order' => 9999], 'locations' => []];
        }

        return $zones;
    }

    /**
     * Los métodos de envío de cada zona, con sus ajustes.
     *
     * @return array<int,array<string,mixed>>
     */
    private function methods(): array
    {
        $methods = [];
        foreach ($this->zones() as $zone) {
            $sql = 'SELECT instance_id, method_id, method_order, is_enabled FROM ' . $this->db->t('woocommerce_shipping_zone_methods') . ' WHERE zone_id = ? ORDER BY method_order, instance_id';
            foreach ($this->db->rows($sql, [(int) $zone['zone']['id']]) as $row) {
                $instance = (int) $row['instance_id'];
                $method = (string) $row['method_id'];
                $methods[] = [
                    'key' => (string) $instance, 'zone' => $zone['zone'],
                    'method' => [
                        'instance_id' => $instance, 'method_id' => $method, 'order' => (int) $row['method_order'], 'enabled' => (string) $row['is_enabled'] === '1',
                        'settings' => $this->db->optionArray('woocommerce_' . $method . '_' . $instance . '_settings'),
                    ],
                ];
            }
        }

        return $methods;
    }

    /**
     * Las pasarelas de pago: las de la lista de orden de WooCommerce o, si no hay, las que tienen ajustes guardados.
     *
     * @return array<int,array<string,mixed>>
     */
    private function gateways(): array
    {
        $order = $this->db->optionArray('woocommerce_gateway_order');
        $ids = [];
        foreach ($order as $id => $unused) {
            if (is_string($id) && $id !== '') {
                $ids[] = $id;
            }
        }
        if ($ids === []) {
            foreach ($this->db->rows('SELECT option_name FROM ' . $this->db->t('options') . " WHERE option_name LIKE 'woocommerce%' ORDER BY option_name") as $row) {
                $name = (string) $row['option_name'];
                if (preg_match('/^woocommerce_(.+)_settings$/', $name, $m) === 1 && preg_match('/_\d+$/', $m[1]) !== 1 && !in_array($m[1], ['email', 'pickup_location', 'local_pickup'], true) && strpos($m[1], 'email_') !== 0) {
                    $ids[] = $m[1];
                }
            }
        }
        $gateways = [];
        foreach ($ids as $position => $id) {
            $settings = $this->db->optionArray('woocommerce_' . $id . '_settings');
            $gateways[] = ['id' => $id, 'title' => (string) ($settings['title'] ?? ''), 'enabled' => (string) ($settings['enabled'] ?? '') === 'yes', 'order' => (int) ($order[$id] ?? $position), 'settings' => $settings];
        }

        return $gateways;
    }

    // ------------------------------------------------------------------ catálogo

    /**
     * @return array<int,array<string,mixed>> atributos globales, por id
     */
    private function attributeTaxonomies(): array
    {
        if ($this->attributeTaxonomies === null) {
            $this->attributeTaxonomies = [];
            if ($this->db->tableExists('woocommerce_attribute_taxonomies')) {
                foreach ($this->db->rows('SELECT attribute_id, attribute_name, attribute_label, attribute_type FROM ' . $this->db->t('woocommerce_attribute_taxonomies') . ' ORDER BY attribute_id') as $row) {
                    $this->attributeTaxonomies[(int) $row['attribute_id']] = ['id' => (int) $row['attribute_id'], 'name' => (string) $row['attribute_name'], 'label' => (string) $row['attribute_label'], 'type' => (string) $row['attribute_type']];
                }
            }
        }

        return $this->attributeTaxonomies;
    }

    /** El atributo global de una taxonomía «pa_xxx» (o null). @return ?array<string,mixed> */
    private function attributeOf(string $taxonomy): ?array
    {
        foreach ($this->attributeTaxonomies() as $attribute) {
            if ('pa_' . $attribute['name'] === $taxonomy) {
                return $attribute;
            }
        }

        return null;
    }

    /**
     * Los términos de un atributo global.
     *
     * @return array<int,array<string,mixed>>
     */
    private function attributeTerms(string $taxonomy): array
    {
        if (!isset($this->taxonomyTerms[$taxonomy])) {
            $this->taxonomyTerms[$taxonomy] = [];
            foreach ($this->termIndex($taxonomy) as $id => $term) {
                $this->taxonomyTerms[$taxonomy][] = ['id' => $id, 'name' => $term['name'], 'slug' => $term['slug']];
            }
        }

        return $this->taxonomyTerms[$taxonomy];
    }

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readAttributes(string $cursor, int $limit): array
    {
        if (!$this->db->tableExists('woocommerce_attribute_taxonomies')) {
            return Batch::result([], null, 0, []);
        }
        list($rows, $next) = $this->db->page(
            'SELECT attribute_id, attribute_name, attribute_label, attribute_type FROM ' . $this->db->t('woocommerce_attribute_taxonomies'),
            '',
            [],
            'attribute_id',
            'attribute_id',
            $cursor,
            $limit
        );

        return $this->fromRows($rows, $next, function (array $r): array {
            $taxonomy = 'pa_' . (string) $r['attribute_name'];

            return $this->mapper()->attribute(
                ['id' => (int) $r['attribute_id'], 'name' => (string) $r['attribute_label'], 'slug' => $taxonomy, 'type' => (string) $r['attribute_type'], 'position' => (int) $r['attribute_id'] - 1],
                $this->attributeTerms($taxonomy)
            );
        }, 'attribute_id');
    }

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readBrands(string $cursor, int $limit): array
    {
        $taxonomy = $this->brandTaxonomy();
        list($rows, $next) = $this->db->page(
            'SELECT t.term_id, t.name, t.slug, tt.description FROM ' . $this->db->t('terms') . ' t JOIN ' . $this->db->t('term_taxonomy') . ' tt ON tt.term_id = t.term_id',
            'tt.taxonomy = ?',
            [$taxonomy],
            't.term_id',
            'term_id',
            $cursor,
            $limit
        );
        $ids = array_map(static function (array $r): int {
            return (int) $r['term_id'];
        }, $rows);
        $meta = $this->db->meta('termmeta', 'term_id', $ids, ['thumbnail_id']);
        $images = $this->images(array_map(static function (array $m): int {
            return (int) ($m['thumbnail_id'] ?? 0);
        }, $meta));

        return $this->fromRows($rows, $next, function (array $r) use ($meta, $images): array {
            $id = (int) $r['term_id'];
            $image = $images[(int) ($meta[$id]['thumbnail_id'] ?? 0)] ?? null;

            return $this->mapper()->brand(['id' => $id, 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'description' => (string) $r['description'], 'image' => $image]);
        }, 'term_id');
    }

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readCategories(string $cursor, int $limit): array
    {
        list($rows, $next) = $this->db->page(
            'SELECT t.term_id, t.name, t.slug, tt.parent, tt.description FROM ' . $this->db->t('terms') . ' t JOIN ' . $this->db->t('term_taxonomy') . ' tt ON tt.term_id = t.term_id',
            "tt.taxonomy = 'product_cat'",
            [],
            't.term_id',
            'term_id',
            $cursor,
            $limit
        );
        $ids = array_map(static function (array $r): int {
            return (int) $r['term_id'];
        }, $rows);
        $meta = $this->db->meta('termmeta', 'term_id', $ids, ['thumbnail_id', 'order']);
        $images = $this->images(array_map(static function (array $m): int {
            return (int) ($m['thumbnail_id'] ?? 0);
        }, $meta));

        return $this->fromRows($rows, $next, function (array $r) use ($meta, $images): array {
            $id = (int) $r['term_id'];
            $image = $images[(int) ($meta[$id]['thumbnail_id'] ?? 0)] ?? null;
            $path = $this->permalinks()->productCategory($this->termPath('product_cat', $id));

            return $this->mapper()->category(
                ['id' => $id, 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'parent' => (int) $r['parent'], 'description' => (string) $r['description'], 'menu_order' => (int) ($meta[$id]['order'] ?? 0), 'image' => $image],
                $path !== null ? [$path] : []
            );
        }, 'term_id');
    }

    // ------------------------------------------------------------------ productos

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readProducts(string $cursor, int $limit): array
    {
        $posts = $this->db->t('posts');
        list($rows, $next) = $this->db->page(
            'SELECT p.ID, p.post_title, p.post_name, p.post_content, p.post_excerpt, p.post_status, p.post_date, p.post_date_gmt, p.post_modified, p.post_modified_gmt, p.menu_order, p.comment_status FROM ' . $posts . ' p',
            "p.post_type = 'product' AND p.post_status NOT IN ('trash', 'auto-draft')",
            [],
            'p.ID',
            'ID',
            $cursor,
            $limit
        );
        $ids = array_map(static function (array $r): int {
            return (int) $r['ID'];
        }, $rows);
        $variationRows = [];
        if ($ids !== []) {
            $variationRows = $this->db->rows(
                'SELECT ID, post_parent, post_status, menu_order FROM ' . $posts . " WHERE post_type = 'product_variation' AND post_status IN ('publish', 'private') AND post_parent IN (" . $this->db->marks(count($ids)) . ') ORDER BY menu_order, ID',
                $ids
            );
        }
        $variationIds = array_map(static function (array $r): int {
            return (int) $r['ID'];
        }, $variationRows);
        $meta = $this->db->meta('postmeta', 'post_id', array_merge($ids, $variationIds), self::PRODUCT_META, 'attribute');
        $terms = $this->termsFor($ids);
        $imageIds = [];
        foreach ($meta as $m) {
            $imageIds[] = (int) ($m['_thumbnail_id'] ?? 0);
            foreach ($this->csvIds($m['_product_image_gallery'] ?? '') as $id) {
                $imageIds[] = $id;
            }
        }
        $images = $this->images($imageIds);
        $byParent = [];
        foreach ($variationRows as $v) {
            $byParent[(int) $v['post_parent']][] = $v;
        }
        $termLookup = [];
        foreach ($this->attributeTaxonomies() as $id => $attribute) {
            $termLookup[$id] = $this->attributeTerms('pa_' . $attribute['name']);
        }

        return $this->fromRows($rows, $next, function (array $row) use ($meta, $terms, $images, $byParent, $termLookup): array {
            $id = (int) $row['ID'];
            $product = $this->wcProduct($row, $meta[$id] ?? [], $terms[$id] ?? [], $images);
            $variations = [];
            foreach ($byParent[$id] ?? [] as $v) {
                $variations[] = $this->wcVariation($v, $meta[(int) $v['ID']] ?? [], $meta[$id] ?? [], $images);
            }

            return $this->mapper()->product($product, $variations, $termLookup);
        }, 'ID');
    }

    /**
     * Las fechas de una oferta (marcas de tiempo) en el formato de la API.
     *
     * @param array<string,string> $m
     *
     * @return array<string,string>
     */
    private function saleDates(array $m): array
    {
        return ['date_on_sale_from_gmt' => $this->stamp($m['_sale_price_dates_from'] ?? ''), 'date_on_sale_to_gmt' => $this->stamp($m['_sale_price_dates_to'] ?? '')];
    }

    /**
     * Un producto con la forma de wc/v3.
     *
     * @param array<string,mixed>                       $row
     * @param array<string,string>                      $m      datos extra
     * @param array<string,array<int,array<string,mixed>>> $terms  sus términos por taxonomía
     * @param array<int,array<string,mixed>>            $images
     *
     * @return array<string,mixed>
     */
    private function wcProduct(array $row, array $m, array $terms, array $images): array
    {
        $get = static function (string $key, string $default = '') use ($m): string {
            return isset($m[$key]) ? $m[$key] : $default;
        };
        $slugs = static function (string $taxonomy) use ($terms): array {
            return array_map(static function (array $t): string {
                return (string) $t['slug'];
            }, $terms[$taxonomy] ?? []);
        };
        $visibility = $slugs('product_visibility');
        $noCatalog = in_array('exclude-from-catalog', $visibility, true);
        $noSearch = in_array('exclude-from-search', $visibility, true);
        $type = $slugs('product_type');
        $manage = $get('_manage_stock') === 'yes';
        $imageList = [];
        foreach (array_merge([(int) $get('_thumbnail_id')], $this->csvIds($get('_product_image_gallery'))) as $imageId) {
            if (isset($images[$imageId])) {
                $imageList[] = $images[$imageId];
            }
        }
        $downloads = [];
        foreach (Database::unserialize($get('_downloadable_files')) as $download) {
            if (is_array($download)) {
                $downloads[] = ['id' => (string) ($download['id'] ?? ''), 'name' => (string) ($download['name'] ?? ''), 'file' => (string) ($download['file'] ?? '')];
            }
        }
        $categories = $terms['product_cat'] ?? [];
        $paths = [];
        $primary = $categories;
        usort($primary, static function (array $a, array $b): int {
            return [$b['parent'], $a['id']] <=> [$a['parent'], $b['id']];
        });
        $slug = (string) $row['post_name'];
        $path = $this->permalinks()->product($slug, $primary !== [] ? $this->termPath('product_cat', (int) $primary[0]['id']) : '');
        if ($path !== null) {
            $paths = [$path];
        }
        $brands = $terms[$this->brandTaxonomy()] ?? [];
        $seo = [];
        foreach (self::SEO_META as $key) {
            if (isset($m[$key])) {
                $seo[] = ['key' => $key, 'value' => $m[$key]];
            }
        }
        if (isset($m['_yoast_wpseo_primary_product_cat'])) {
            $seo[] = ['key' => '_yoast_wpseo_primary_product_cat', 'value' => $m['_yoast_wpseo_primary_product_cat']];
        }

        return [
            'id' => (int) $row['ID'], 'name' => (string) $row['post_title'], 'slug' => $slug, 'permalink' => $paths !== [] ? $paths[0] : '',
            'date_created' => (string) $row['post_date'], 'date_created_gmt' => (string) $row['post_date_gmt'], 'date_modified' => (string) $row['post_modified'], 'date_modified_gmt' => (string) $row['post_modified_gmt'],
            'type' => $type !== [] ? $type[0] : 'simple', 'status' => (string) $row['post_status'], 'featured' => in_array('featured', $visibility, true),
            'catalog_visibility' => $noCatalog && $noSearch ? 'hidden' : ($noCatalog ? 'search' : ($noSearch ? 'catalog' : 'visible')),
            'description' => (string) $row['post_content'], 'short_description' => (string) $row['post_excerpt'], 'sku' => $get('_sku'), 'global_unique_id' => $get('_global_unique_id'),
            'price' => $get('_price'), 'regular_price' => $get('_regular_price'), 'sale_price' => $get('_sale_price'),
            'virtual' => $get('_virtual') === 'yes', 'downloadable' => $get('_downloadable') === 'yes', 'downloads' => $downloads,
            'download_limit' => (int) $get('_download_limit', '-1'), 'download_expiry' => (int) $get('_download_expiry', '-1'),
            'external_url' => $get('_product_url'), 'button_text' => $get('_button_text'), 'tax_status' => $get('_tax_status', 'taxable'), 'tax_class' => $get('_tax_class'),
            'manage_stock' => $manage, 'stock_quantity' => $manage ? (int) $get('_stock', '0') : null, 'stock_status' => $get('_stock_status', 'instock'),
            'backorders' => $get('_backorders', 'no'), 'sold_individually' => $get('_sold_individually') === 'yes', 'weight' => $get('_weight'),
            'dimensions' => ['length' => $get('_length'), 'width' => $get('_width'), 'height' => $get('_height')],
            'shipping_class' => $slugs('product_shipping_class') !== [] ? $slugs('product_shipping_class')[0] : '', 'reviews_allowed' => (string) $row['comment_status'] === 'open',
            'upsell_ids' => $this->intList(Database::unserialize($get('_upsell_ids'))), 'cross_sell_ids' => $this->intList(Database::unserialize($get('_crosssell_ids'))),
            'related_ids' => $this->intList(Database::unserialize($get('_related_ids'))), 'purchase_note' => $get('_purchase_note'),
            'grouped_products' => $this->intList(Database::unserialize($get('_children'))),
            'categories' => array_map(static function (array $t): array {
                return ['id' => $t['id'], 'name' => $t['name'], 'slug' => $t['slug']];
            }, $categories),
            'tags' => array_map(static function (array $t): array {
                return ['id' => $t['id'], 'name' => $t['name'], 'slug' => $t['slug']];
            }, $terms['product_tag'] ?? []),
            'brands' => array_map(static function (array $t): array {
                return ['id' => $t['id'], 'name' => $t['name'], 'slug' => $t['slug']];
            }, $brands),
            'images' => $imageList, 'attributes' => $this->productAttributes($get('_product_attributes'), $terms), 'menu_order' => (int) $row['menu_order'],
            'meta_data' => $seo,
        ] + $this->saleDates($m);
    }

    /**
     * Los atributos de un producto con la forma de la API: {id, name, position, visible, variation, options}.
     *
     * @param array<string,array<int,array<string,mixed>>> $terms
     *
     * @return array<int,array<string,mixed>>
     */
    private function productAttributes(string $serialized, array $terms): array
    {
        $list = [];
        foreach (Database::unserialize($serialized) as $key => $a) {
            if (!is_array($a)) {
                continue;
            }
            $taxonomy = (string) $key;
            $global = !empty($a['is_taxonomy']) ? $this->attributeOf($taxonomy) : null;
            if ($global !== null) {
                $options = array_map(static function (array $t): string {
                    return (string) $t['name'];
                }, $terms[$taxonomy] ?? []);
            } else {
                $options = array_values(array_filter(array_map('trim', explode('|', (string) ($a['value'] ?? ''))), static function (string $v): bool {
                    return $v !== '';
                }));
            }
            $list[] = [
                'id' => $global !== null ? (int) $global['id'] : 0, 'name' => $global !== null ? (string) $global['label'] : (string) ($a['name'] ?? $key),
                'position' => (int) ($a['position'] ?? 0), 'visible' => !empty($a['is_visible']), 'variation' => !empty($a['is_variation']), 'options' => $options,
            ];
        }
        usort($list, static function (array $a, array $b): int {
            return $a['position'] <=> $b['position'];
        });

        return $list;
    }

    /**
     * Una variación con la forma de wc/v3.
     *
     * @param array<string,mixed>              $row
     * @param array<string,string>             $m       datos extra de la variación
     * @param array<string,string>             $parent  datos extra del producto (para saber cómo se llaman sus atributos)
     * @param array<int,array<string,mixed>>   $images
     *
     * @return array<string,mixed>
     */
    private function wcVariation(array $row, array $m, array $parent, array $images): array
    {
        $get = static function (string $key, string $default = '') use ($m): string {
            return isset($m[$key]) ? $m[$key] : $default;
        };
        $names = [];
        foreach (Database::unserialize((string) ($parent['_product_attributes'] ?? '')) as $key => $a) {
            if (is_array($a)) {
                $names[(string) $key] = (string) ($a['name'] ?? $key);
            }
        }
        $attributes = [];
        foreach ($m as $key => $value) {
            if (strncmp($key, 'attribute_', 10) !== 0) {
                continue;
            }
            $taxonomy = substr($key, 10);
            $global = strncmp($taxonomy, 'pa_', 3) === 0 ? $this->attributeOf($taxonomy) : null;
            $option = $value;
            if ($global !== null) {
                foreach ($this->attributeTerms($taxonomy) as $term) {
                    if ($term['slug'] === $value) {
                        $option = (string) $term['name'];
                    }
                }
            }
            $attributes[] = ['id' => $global !== null ? (int) $global['id'] : 0, 'name' => $global !== null ? (string) $global['label'] : ($names[$taxonomy] ?? $taxonomy), 'option' => $option];
        }
        $manage = $get('_manage_stock');

        return [
            'id' => (int) $row['ID'], 'status' => (string) $row['post_status'], 'sku' => $get('_sku'), 'global_unique_id' => $get('_global_unique_id'),
            'price' => $get('_price'), 'regular_price' => $get('_regular_price'), 'sale_price' => $get('_sale_price'),
            'manage_stock' => $manage === 'yes' ? true : ($manage === 'parent' ? 'parent' : false), 'stock_quantity' => $manage === 'yes' ? (int) $get('_stock', '0') : null,
            'weight' => $get('_weight'), 'image' => $images[(int) $get('_thumbnail_id')] ?? null, 'attributes' => $attributes, 'menu_order' => (int) $row['menu_order'],
        ] + $this->saleDates($m);
    }

    // ------------------------------------------------------------------ clientes y cupones

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readCustomers(string $cursor, int $limit): array
    {
        list($rows, $next) = $this->db->page(
            'SELECT ID, user_login, user_pass, user_email, user_registered FROM ' . $this->db->u('users'),
            'ID IN (SELECT user_id FROM ' . $this->db->u('usermeta') . ' WHERE meta_key = ? AND meta_value LIKE ?)',
            [$this->db->sitePrefix() . 'capabilities', '%"customer"%'],
            'ID',
            'ID',
            $cursor,
            $limit
        );
        $ids = array_map(static function (array $r): int {
            return (int) $r['ID'];
        }, $rows);
        $keys = [];
        foreach (['billing', 'shipping'] as $kind) {
            foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $field) {
                $keys[] = $kind . '_' . $field;
            }
        }
        $keys = array_merge($keys, ['first_name', 'last_name', 'locale', 'last_update'], self::VAT_META);
        $meta = $this->db->meta('usermeta', 'user_id', $ids, $keys, '', true);

        return $this->fromRows($rows, $next, function (array $row) use ($meta): array {
            $id = (int) $row['ID'];
            $m = $meta[$id] ?? [];
            $address = static function (string $kind) use ($m): array {
                $out = [];
                foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $field) {
                    $out[$field] = $m[$kind . '_' . $field] ?? '';
                }

                return $out;
            };
            $vat = [];
            foreach (self::VAT_META as $key) {
                if (isset($m[$key])) {
                    $vat[] = ['key' => $key, 'value' => $m[$key]];
                }
            }
            $customer = [
                'id' => $id, 'date_created_gmt' => (string) $row['user_registered'], 'date_modified_gmt' => $this->stamp($m['last_update'] ?? ''), 'email' => (string) $row['user_email'],
                'username' => (string) $row['user_login'], 'first_name' => $m['first_name'] ?? '', 'last_name' => $m['last_name'] ?? '', 'locale' => $m['locale'] ?? '',
                'billing' => $address('billing'), 'shipping' => $address('shipping'), 'meta_data' => $vat,
            ];

            return $this->mapper()->customer($customer, (string) $row['user_pass']);
        }, 'ID');
    }

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readCoupons(string $cursor, int $limit): array
    {
        list($rows, $next) = $this->db->page(
            'SELECT ID, post_title, post_excerpt, post_status, post_modified, post_modified_gmt FROM ' . $this->db->t('posts'),
            "post_type = 'shop_coupon' AND post_status NOT IN ('trash', 'auto-draft')",
            [],
            'ID',
            'ID',
            $cursor,
            $limit
        );
        $ids = array_map(static function (array $r): int {
            return (int) $r['ID'];
        }, $rows);
        $meta = $this->db->meta('postmeta', 'post_id', $ids, [
            'discount_type', 'coupon_amount', 'individual_use', 'product_ids', 'exclude_product_ids', 'usage_limit', 'usage_limit_per_user', 'limit_usage_to_x_items',
            'usage_count', 'date_expires', 'free_shipping', 'product_categories', 'exclude_product_categories', 'exclude_sale_items', 'minimum_amount', 'maximum_amount', 'customer_email',
        ]);

        return $this->fromRows($rows, $next, function (array $row) use ($meta): array {
            $id = (int) $row['ID'];
            $m = $meta[$id] ?? [];
            $get = static function (string $key, string $default = '') use ($m): string {
                return isset($m[$key]) ? $m[$key] : $default;
            };

            return $this->mapper()->coupon([
                'id' => $id, 'code' => (string) $row['post_title'], 'status' => (string) $row['post_status'], 'date_modified' => (string) $row['post_modified'], 'date_modified_gmt' => (string) $row['post_modified_gmt'],
                'description' => (string) $row['post_excerpt'], 'discount_type' => $get('discount_type', 'fixed_cart'), 'amount' => $get('coupon_amount', '0'),
                'date_expires_gmt' => $this->stamp($get('date_expires')), 'usage_count' => (int) $get('usage_count', '0'), 'individual_use' => $get('individual_use') === 'yes',
                'product_ids' => $this->csvIds($get('product_ids')), 'excluded_product_ids' => $this->csvIds($get('exclude_product_ids')),
                'usage_limit' => (int) $get('usage_limit', '0'), 'usage_limit_per_user' => (int) $get('usage_limit_per_user', '0'),
                'limit_usage_to_x_items' => (int) $get('limit_usage_to_x_items', '0'), 'free_shipping' => $get('free_shipping') === 'yes',
                'product_categories' => $this->intList(Database::unserialize($get('product_categories'))),
                'excluded_product_categories' => $this->intList(Database::unserialize($get('exclude_product_categories'))),
                'exclude_sale_items' => $get('exclude_sale_items') === 'yes', 'minimum_amount' => $get('minimum_amount'), 'maximum_amount' => $get('maximum_amount'),
                'email_restrictions' => array_map('strval', array_values(Database::unserialize($get('customer_email')))),
            ]);
        }, 'ID');
    }

    // ------------------------------------------------------------------ pedidos

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readOrders(string $cursor, int $limit): array
    {
        list($headers, $next) = $this->hpos() ? $this->hposOrders($cursor, $limit) : $this->postOrders($cursor, $limit);
        $ids = array_map(static function (array $h): int {
            return (int) $h['id'];
        }, $headers);
        $items = $this->orderItems($ids);
        $refunds = $this->refundsFor($ids);
        $notes = $this->notesFor($ids);

        return $this->fromRows($headers, $next, function (array $h) use ($items, $refunds, $notes): array {
            $id = (int) $h['id'];

            return $this->mapper()->order($h + ($items[$id] ?? []), $refunds[$id] ?? [], $notes[$id] ?? []);
        }, 'id');
    }

    /**
     * @param array<string,string> $m
     *
     * @return array<string,string>
     */
    private function addressFrom(array $m, string $prefix): array
    {
        $out = [];
        foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $field) {
            $out[$field] = $m[$prefix . $field] ?? '';
        }

        return $out;
    }

    /**
     * Pedidos de la tabla de entradas.
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string}
     */
    private function postOrders(string $cursor, int $limit): array
    {
        list($rows, $next) = $this->db->page(
            'SELECT p.ID, p.post_status, p.post_date, p.post_date_gmt, p.post_modified, p.post_modified_gmt, p.post_excerpt, p.post_parent FROM ' . $this->db->t('posts') . ' p',
            "p.post_type = 'shop_order' AND p.post_status NOT IN ('trash', 'auto-draft', 'wc-checkout-draft')",
            [],
            'p.ID',
            'ID',
            $cursor,
            $limit
        );
        $ids = array_map(static function (array $r): int {
            return (int) $r['ID'];
        }, $rows);
        $keys = [
            '_order_total', '_order_tax', '_order_shipping_tax', '_order_currency', '_prices_include_tax', '_customer_user', '_payment_method', '_payment_method_title',
            '_transaction_id', '_date_paid', '_date_completed', '_order_key', '_created_via', '_customer_ip_address', '_cart_hash', '_order_number',
        ];
        foreach (['_billing_', '_shipping_'] as $prefix) {
            foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $field) {
                $keys[] = $prefix . $field;
            }
        }
        $meta = $this->db->meta('postmeta', 'post_id', $ids, array_merge($keys, self::VAT_META));
        $headers = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $m = $meta[$id] ?? [];
            $vat = [];
            foreach (self::VAT_META as $key) {
                if (isset($m[$key])) {
                    $vat[] = ['key' => $key, 'value' => $m[$key]];
                }
            }
            $headers[] = [
                'id' => $id, 'status' => (string) $row['post_status'], 'date_created' => (string) $row['post_date'], 'date_created_gmt' => (string) $row['post_date_gmt'],
                'date_modified' => (string) $row['post_modified'], 'date_modified_gmt' => (string) $row['post_modified_gmt'], 'customer_note' => (string) $row['post_excerpt'],
                'parent_id' => (int) $row['post_parent'], 'number' => $m['_order_number'] ?? '', 'currency' => $m['_order_currency'] ?? '', 'customer_id' => (int) ($m['_customer_user'] ?? 0),
                'total' => $m['_order_total'] ?? '0',
                'total_tax' => Mapper::decimal(\Aifec\Modules\Import\Neutral::cents($m['_order_tax'] ?? '0') + \Aifec\Modules\Import\Neutral::cents($m['_order_shipping_tax'] ?? '0')),
                'prices_include_tax' => ($m['_prices_include_tax'] ?? '') === 'yes', 'payment_method' => $m['_payment_method'] ?? '', 'payment_method_title' => $m['_payment_method_title'] ?? '',
                'transaction_id' => $m['_transaction_id'] ?? '', 'date_paid_gmt' => $this->stamp($m['_date_paid'] ?? ''), 'date_completed_gmt' => $this->stamp($m['_date_completed'] ?? ''),
                'order_key' => $m['_order_key'] ?? '', 'created_via' => $m['_created_via'] ?? '', 'customer_ip_address' => $m['_customer_ip_address'] ?? '', 'cart_hash' => $m['_cart_hash'] ?? '',
                'billing' => $this->addressFrom($m, '_billing_'), 'shipping' => $this->addressFrom($m, '_shipping_'), 'meta_data' => $vat,
            ];
        }

        return [$headers, $next];
    }

    /**
     * Pedidos de las tablas HPOS.
     *
     * @return array{0:array<int,array<string,mixed>>,1:?string}
     */
    private function hposOrders(string $cursor, int $limit): array
    {
        list($rows, $next) = $this->db->page(
            'SELECT o.id, o.status, o.currency, o.tax_amount, o.total_amount, o.customer_id, o.date_created_gmt, o.date_updated_gmt, o.parent_order_id, o.payment_method, o.payment_method_title, o.transaction_id, o.ip_address, o.customer_note FROM ' . $this->db->t('wc_orders') . ' o',
            "o.type = 'shop_order' AND o.status NOT IN ('trash', 'auto-draft', 'checkout-draft', 'wc-checkout-draft')",
            [],
            'o.id',
            'id',
            $cursor,
            $limit
        );
        $ids = array_map(static function (array $r): int {
            return (int) $r['id'];
        }, $rows);
        $addresses = [];
        $operational = [];
        $meta = [];
        if ($ids !== []) {
            $marks = $this->db->marks(count($ids));
            foreach ($this->db->rows('SELECT order_id, address_type, first_name, last_name, company, address_1, address_2, city, state, postcode, country, email, phone FROM ' . $this->db->t('wc_order_addresses') . ' WHERE order_id IN (' . $marks . ')', $ids) as $a) {
                $addresses[(int) $a['order_id']][(string) $a['address_type']] = $a;
            }
            foreach ($this->db->rows('SELECT order_id, created_via, prices_include_tax, cart_hash, order_key, date_paid_gmt, date_completed_gmt FROM ' . $this->db->t('wc_order_operational_data') . ' WHERE order_id IN (' . $marks . ')', $ids) as $d) {
                $operational[(int) $d['order_id']] = $d;
            }
            $meta = $this->db->meta('wc_orders_meta', 'order_id', $ids, array_merge(['_order_number'], self::VAT_META));
        }
        $headers = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $op = $operational[$id] ?? [];
            $m = $meta[$id] ?? [];
            $vat = [];
            foreach (self::VAT_META as $key) {
                if (isset($m[$key])) {
                    $vat[] = ['key' => $key, 'value' => $m[$key]];
                }
            }
            $address = static function (string $type) use ($addresses, $id): array {
                $a = $addresses[$id][$type] ?? [];
                $out = [];
                foreach (['first_name', 'last_name', 'company', 'address_1', 'address_2', 'city', 'state', 'postcode', 'country', 'email', 'phone'] as $field) {
                    $out[$field] = isset($a[$field]) ? (string) $a[$field] : '';
                }

                return $out;
            };
            $headers[] = [
                'id' => $id, 'status' => (string) $row['status'], 'date_created' => '', 'date_created_gmt' => (string) $row['date_created_gmt'], 'date_modified' => '',
                'date_modified_gmt' => (string) $row['date_updated_gmt'], 'customer_note' => (string) $row['customer_note'], 'parent_id' => (int) $row['parent_order_id'],
                'number' => $m['_order_number'] ?? '', 'currency' => (string) $row['currency'], 'customer_id' => (int) $row['customer_id'], 'total' => (string) $row['total_amount'],
                'total_tax' => (string) $row['tax_amount'], 'prices_include_tax' => (string) ($op['prices_include_tax'] ?? '') === '1', 'payment_method' => (string) $row['payment_method'],
                'payment_method_title' => (string) $row['payment_method_title'], 'transaction_id' => (string) $row['transaction_id'],
                'date_paid_gmt' => (string) ($op['date_paid_gmt'] ?? ''), 'date_completed_gmt' => (string) ($op['date_completed_gmt'] ?? ''), 'order_key' => (string) ($op['order_key'] ?? ''),
                'created_via' => (string) ($op['created_via'] ?? ''), 'customer_ip_address' => (string) $row['ip_address'], 'cart_hash' => (string) ($op['cart_hash'] ?? ''),
                'billing' => $address('billing'), 'shipping' => $address('shipping'), 'meta_data' => $vat,
            ];
        }

        return [$headers, $next];
    }

    /**
     * Las líneas de varios pedidos (o devoluciones): productos, impuestos, envíos, tasas y cupones.
     *
     * @param int[] $orderIds
     *
     * @return array<int,array<string,array<int,array<string,mixed>>>>
     */
    private function orderItems(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $items = $this->db->rows('SELECT order_item_id, order_item_name, order_item_type, order_id FROM ' . $this->db->t('woocommerce_order_items') . ' WHERE order_id IN (' . $this->db->marks(count($orderIds)) . ') ORDER BY order_item_id', $orderIds);
        $itemIds = array_map(static function (array $r): int {
            return (int) $r['order_item_id'];
        }, $items);
        $meta = $this->db->meta('woocommerce_order_itemmeta', 'order_item_id', $itemIds);
        $productIds = [];
        foreach ($meta as $m) {
            $productIds[] = (int) ($m['_variation_id'] ?? 0) > 0 ? (int) $m['_variation_id'] : (int) ($m['_product_id'] ?? 0);
        }
        $skus = $this->db->meta('postmeta', 'post_id', array_values(array_unique(array_filter($productIds))), ['_sku']);
        $out = [];
        foreach ($items as $item) {
            $id = (int) $item['order_item_id'];
            $m = $meta[$id] ?? [];
            $order = (int) $item['order_id'];
            $name = (string) $item['order_item_name'];
            switch ((string) $item['order_item_type']) {
                case 'line_item':
                    $productId = (int) ($m['_variation_id'] ?? 0) > 0 ? (int) $m['_variation_id'] : (int) ($m['_product_id'] ?? 0);
                    $data = [];
                    foreach ($m as $key => $value) {
                        if (strncmp($key, '_', 1) !== 0) {
                            $data[] = ['key' => $key, 'value' => $value, 'display_key' => $this->metaLabel($key), 'display_value' => $this->metaValue($key, $value)];
                        }
                    }
                    $out[$order]['line_items'][] = [
                        'id' => $id, 'name' => $name, 'product_id' => (int) ($m['_product_id'] ?? 0), 'variation_id' => (int) ($m['_variation_id'] ?? 0),
                        'quantity' => (int) ($m['_qty'] ?? 1), 'tax_class' => $m['_tax_class'] ?? '', 'subtotal' => $m['_line_subtotal'] ?? '0', 'subtotal_tax' => $m['_line_subtotal_tax'] ?? '0',
                        'total' => $m['_line_total'] ?? '0', 'total_tax' => $m['_line_tax'] ?? '0', 'taxes' => $this->itemTaxes($m['_line_tax_data'] ?? ''),
                        'sku' => $skus[$productId]['_sku'] ?? '', 'meta_data' => $data, 'refunded_item_id' => (int) ($m['_refunded_item_id'] ?? 0),
                    ];
                    break;
                case 'tax':
                    $out[$order]['tax_lines'][] = [
                        'id' => $id, 'rate_code' => $name, 'rate_id' => (int) ($m['rate_id'] ?? 0), 'label' => $m['label'] ?? $name, 'compound' => ($m['compound'] ?? '') === '1',
                        'tax_total' => $m['tax_amount'] ?? '0', 'shipping_tax_total' => $m['shipping_tax_amount'] ?? '0', 'rate_percent' => $m['rate_percent'] ?? '0',
                    ];
                    break;
                case 'shipping':
                    $out[$order]['shipping_lines'][] = [
                        'id' => $id, 'method_title' => $name, 'method_id' => $m['method_id'] ?? '', 'instance_id' => (int) ($m['instance_id'] ?? 0), 'total' => $m['cost'] ?? '0',
                        'total_tax' => $m['total_tax'] ?? '0', 'taxes' => $this->itemTaxes($m['taxes'] ?? '', true), 'refunded_item_id' => (int) ($m['_refunded_item_id'] ?? 0),
                    ];
                    break;
                case 'fee':
                    $out[$order]['fee_lines'][] = [
                        'id' => $id, 'name' => $name, 'total' => $m['_line_total'] ?? '0', 'total_tax' => $m['_line_tax'] ?? '0', 'taxes' => $this->itemTaxes($m['_line_tax_data'] ?? ''),
                        'refunded_item_id' => (int) ($m['_refunded_item_id'] ?? 0),
                    ];
                    break;
                case 'coupon':
                    $out[$order]['coupon_lines'][] = ['id' => $id, 'code' => $name, 'discount' => $m['discount_amount'] ?? '0', 'discount_tax' => $m['discount_amount_tax'] ?? '0'];
                    break;
            }
        }

        return $out;
    }

    /**
     * Los impuestos de una línea: de {total: {tipo: importe}, subtotal: {…}} a [{id, total, subtotal}].
     *
     * @return array<int,array<string,string>>
     */
    private function itemTaxes(string $serialized, bool $shipping = false): array
    {
        $data = Database::unserialize($serialized);
        $total = is_array($data['total'] ?? null) ? $data['total'] : ($shipping ? $data : []);
        $subtotal = is_array($data['subtotal'] ?? null) ? $data['subtotal'] : [];
        $out = [];
        foreach ($total as $rate => $amount) {
            $out[] = ['id' => (int) $rate, 'total' => (string) $amount, 'subtotal' => isset($subtotal[$rate]) ? (string) $subtotal[$rate] : ''];
        }

        return $out;
    }

    /** El nombre legible de un dato de línea de pedido (el atributo «pa_tamano» se llama «Tamaño»). */
    private function metaLabel(string $key): string
    {
        if (strncmp($key, 'pa_', 3) === 0 && ($attribute = $this->attributeOf($key)) !== null && (string) $attribute['label'] !== '') {
            return (string) $attribute['label'];
        }

        return $key;
    }

    /** El valor legible de un dato de línea de pedido (el espacio «pequena» de un atributo se llama «Pequeña»). */
    private function metaValue(string $key, string $value): string
    {
        if (strncmp($key, 'pa_', 3) === 0) {
            foreach ($this->attributeTerms($key) as $term) {
                if ($term['slug'] === $value) {
                    return (string) $term['name'];
                }
            }
        }

        return $value;
    }

    /**
     * Las devoluciones de varios pedidos con sus líneas.
     *
     * @param int[] $orderIds
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function refundsFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $marks = $this->db->marks(count($orderIds));
        $refunds = [];
        if ($this->hpos()) {
            $rows = $this->db->rows('SELECT id, parent_order_id AS parent, date_created_gmt AS gmt, total_amount AS amount, customer_note AS reason FROM ' . $this->db->t('wc_orders') . " WHERE type = 'shop_order_refund' AND parent_order_id IN (" . $marks . ') ORDER BY id', $orderIds);
        } else {
            $rows = $this->db->rows('SELECT p.ID AS id, p.post_parent AS parent, p.post_date_gmt AS gmt, m.meta_value AS amount, p.post_excerpt AS reason FROM ' . $this->db->t('posts') . ' p LEFT JOIN ' . $this->db->t('postmeta') . " m ON m.post_id = p.ID AND m.meta_key = '_refund_amount' WHERE p.post_type = 'shop_order_refund' AND p.post_parent IN (" . $marks . ') ORDER BY p.ID', $orderIds);
        }
        $items = $this->orderItems(array_map(static function (array $r): int {
            return (int) $r['id'];
        }, $rows));
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $refunds[(int) $row['parent']][] = ['id' => $id, 'date_created_gmt' => (string) $row['gmt'], 'amount' => ltrim((string) $row['amount'], '-'), 'reason' => (string) $row['reason']] + ($items[$id] ?? []);
        }

        return $refunds;
    }

    /**
     * Las notas de varios pedidos.
     *
     * @param int[] $orderIds
     *
     * @return array<int,array<int,array<string,mixed>>>
     */
    private function notesFor(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }
        $rows = $this->db->rows(
            'SELECT comment_ID, comment_post_ID, comment_author, comment_date_gmt, comment_content FROM ' . $this->db->t('comments') . " WHERE comment_type = 'order_note' AND comment_post_ID IN (" . $this->db->marks(count($orderIds)) . ') ORDER BY comment_ID',
            $orderIds
        );
        $meta = $this->db->meta('commentmeta', 'comment_id', array_map(static function (array $r): int {
            return (int) $r['comment_ID'];
        }, $rows), ['is_customer_note']);
        $notes = [];
        foreach ($rows as $row) {
            $id = (int) $row['comment_ID'];
            $notes[(int) $row['comment_post_ID']][] = [
                'id' => $id, 'author' => (string) $row['comment_author'] === 'WooCommerce' ? 'system' : (string) $row['comment_author'], 'date_created_gmt' => (string) $row['comment_date_gmt'],
                'note' => (string) $row['comment_content'], 'customer_note' => ($meta[$id]['is_customer_note'] ?? '') === '1',
            ];
        }

        return $notes;
    }

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readReviews(string $cursor, int $limit): array
    {
        list($rows, $next) = $this->db->page(
            'SELECT comment_ID, comment_post_ID, comment_author, comment_date_gmt, comment_content, comment_approved FROM ' . $this->db->t('comments'),
            "comment_type = 'review'",
            [],
            'comment_ID',
            'comment_ID',
            $cursor,
            $limit
        );
        $meta = $this->db->meta('commentmeta', 'comment_id', array_map(static function (array $r): int {
            return (int) $r['comment_ID'];
        }, $rows), ['rating', 'verified']);

        return $this->fromRows($rows, $next, function (array $row) use ($meta): array {
            $id = (int) $row['comment_ID'];
            $approved = (string) $row['comment_approved'];

            return $this->mapper()->review([
                'id' => $id, 'product_id' => (int) $row['comment_post_ID'], 'status' => $approved === '1' ? 'approved' : ($approved === 'spam' ? 'spam' : ($approved === 'trash' ? 'trash' : 'hold')),
                'reviewer' => (string) $row['comment_author'], 'review' => (string) $row['comment_content'], 'rating' => (int) ($meta[$id]['rating'] ?? 0),
                'verified' => ($meta[$id]['verified'] ?? '') === '1', 'date_created_gmt' => (string) $row['comment_date_gmt'],
            ]);
        }, 'comment_ID');
    }

    // ------------------------------------------------------------------ contenidos

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readMedia(string $cursor, int $limit): array
    {
        list($rows, $next) = $this->db->page(
            'SELECT ID, post_title, post_mime_type FROM ' . $this->db->t('posts'),
            "post_type = 'attachment'",
            [],
            'ID',
            'ID',
            $cursor,
            $limit
        );
        $ids = array_map(static function (array $r): int {
            return (int) $r['ID'];
        }, $rows);
        $images = $this->images($ids);

        return $this->fromRows($rows, $next, function (array $row) use ($images): array {
            $id = (int) $row['ID'];
            $image = $images[$id] ?? ['src' => '', 'alt' => ''];
            $media = ['id' => $id, 'title' => ['rendered' => (string) $row['post_title']], 'mime_type' => (string) $row['post_mime_type'], 'alt_text' => (string) $image['alt'], 'source_url' => (string) $image['src']];
            if (isset($image['path'])) {
                $media['path'] = $image['path'];
            }

            return $this->mapper()->media($media);
        }, 'ID');
    }

    /** La ruta jerárquica de una página: «padre/hija». */
    private function pagePath(int $id): string
    {
        $parts = [];
        for ($guard = 0; $id > 0 && $guard < 20; ++$guard) {
            if (!isset($this->pageIndex[$id])) {
                $row = $this->db->row('SELECT ID, post_name, post_parent FROM ' . $this->db->t('posts') . ' WHERE ID = ?', [$id]);
                if ($row === null) {
                    break;
                }
                $this->pageIndex[$id] = ['slug' => (string) $row['post_name'], 'parent' => (int) $row['post_parent']];
            }
            array_unshift($parts, $this->pageIndex[$id]['slug']);
            $id = (int) $this->pageIndex[$id]['parent'];
        }

        return implode('/', $parts);
    }

    /**
     * Páginas y entradas: los campos comunes con la forma de wp/v2.
     *
     * @param array<string,mixed>  $row
     * @param array<string,string> $m
     *
     * @return array<string,mixed>
     */
    private function wpContent(array $row, array $m): array
    {
        $seo = [];
        foreach (self::SEO_META as $key) {
            if (isset($m[$key])) {
                $seo[] = ['key' => $key, 'value' => $m[$key]];
            }
        }

        return [
            'id' => (int) $row['ID'], 'status' => (string) $row['post_status'], 'date' => (string) $row['post_date'], 'date_gmt' => (string) $row['post_date_gmt'],
            'modified' => (string) $row['post_modified'], 'modified_gmt' => (string) $row['post_modified_gmt'], 'slug' => (string) $row['post_name'], 'title' => (string) $row['post_title'],
            'content' => (string) $row['post_content'], 'excerpt' => (string) $row['post_excerpt'], 'meta_data' => $seo,
        ];
    }

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readPages(string $cursor, int $limit): array
    {
        list($rows, $next) = $this->db->page(
            'SELECT ID, post_title, post_name, post_content, post_excerpt, post_status, post_date, post_date_gmt, post_modified, post_modified_gmt, post_parent, menu_order FROM ' . $this->db->t('posts'),
            "post_type = 'page' AND post_status NOT IN ('trash', 'auto-draft', 'inherit')",
            [],
            'ID',
            'ID',
            $cursor,
            $limit
        );
        $meta = $this->db->meta('postmeta', 'post_id', array_map(static function (array $r): int {
            return (int) $r['ID'];
        }, $rows), self::SEO_META);

        return $this->fromRows($rows, $next, function (array $row) use ($meta): array {
            $page = $this->wpContent($row, $meta[(int) $row['ID']] ?? []) + ['parent' => (int) $row['post_parent'], 'menu_order' => (int) $row['menu_order']];
            $path = $this->permalinks()->page($this->pagePath((int) $row['ID']));
            $page['link'] = $path !== null ? $path : '';

            return $this->mapper()->page($page);
        }, 'ID');
    }

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readPostCategories(string $cursor, int $limit): array
    {
        list($rows, $next) = $this->db->page(
            'SELECT t.term_id, t.name, t.slug, tt.parent, tt.description FROM ' . $this->db->t('terms') . ' t JOIN ' . $this->db->t('term_taxonomy') . ' tt ON tt.term_id = t.term_id',
            "tt.taxonomy = 'category'",
            [],
            't.term_id',
            'term_id',
            $cursor,
            $limit
        );

        return $this->fromRows($rows, $next, function (array $r): array {
            $path = $this->permalinks()->postCategory($this->termPath('category', (int) $r['term_id']));

            return $this->mapper()->postCategory([
                'id' => (int) $r['term_id'], 'name' => (string) $r['name'], 'slug' => (string) $r['slug'], 'parent' => (int) $r['parent'], 'description' => (string) $r['description'], 'link' => $path !== null ? $path : '',
            ]);
        }, 'term_id');
    }

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readPosts(string $cursor, int $limit): array
    {
        list($rows, $next) = $this->db->page(
            'SELECT ID, post_title, post_name, post_content, post_excerpt, post_status, post_date, post_date_gmt, post_modified, post_modified_gmt, post_author FROM ' . $this->db->t('posts'),
            "post_type = 'post' AND post_status NOT IN ('trash', 'auto-draft', 'inherit')",
            [],
            'ID',
            'ID',
            $cursor,
            $limit
        );
        $ids = array_map(static function (array $r): int {
            return (int) $r['ID'];
        }, $rows);
        $meta = $this->db->meta('postmeta', 'post_id', $ids, array_merge(self::SEO_META, ['_thumbnail_id']));
        $terms = $this->termsFor($ids);
        $images = $this->images(array_map(static function (array $m): int {
            return (int) ($m['_thumbnail_id'] ?? 0);
        }, $meta));
        $authors = [];
        $authorIds = array_values(array_unique(array_map(static function (array $r): int {
            return (int) $r['post_author'];
        }, $rows)));
        if ($authorIds !== []) {
            foreach ($this->db->rows('SELECT ID, display_name, user_nicename FROM ' . $this->db->u('users') . ' WHERE ID IN (' . $this->db->marks(count($authorIds)) . ')', $authorIds) as $user) {
                $authors[(int) $user['ID']] = ['name' => (string) $user['display_name'], 'nicename' => (string) $user['user_nicename']];
            }
        }

        return $this->fromRows($rows, $next, function (array $row) use ($meta, $terms, $images, $authors): array {
            $id = (int) $row['ID'];
            $categories = $terms[$id]['category'] ?? [];
            $post = $this->wpContent($row, $meta[$id] ?? []);
            $image = $images[(int) ($meta[$id]['_thumbnail_id'] ?? 0)] ?? null;
            $post['categories'] = array_map(static function (array $t): int {
                return (int) $t['id'];
            }, $categories);
            $post['tag_names'] = array_map(static function (array $t): string {
                return (string) $t['name'];
            }, $terms[$id]['post_tag'] ?? []);
            $post['author_name'] = $authors[(int) $row['post_author']]['name'] ?? '';
            if ($image !== null) {
                $post['featured_image'] = isset($image['path']) ? ['path' => $image['path']] : ['url' => $image['src']];
            }
            $path = $this->permalinks()->post((string) $row['post_name'], $id, (string) $row['post_date'], $categories !== [] ? $this->termPath('category', (int) $categories[0]['id']) : '', $authors[(int) $row['post_author']]['nicename'] ?? '');
            $post['link'] = $path !== null ? $path : '';

            return $this->mapper()->post($post);
        }, 'ID');
    }

    /**
     * Los menús con sus elementos.
     *
     * @return array<int,array<string,mixed>>
     */
    private function menus(): array
    {
        $locations = [];
        $stylesheet = (string) $this->db->option('stylesheet');
        $mods = $stylesheet !== '' ? $this->db->optionArray('theme_mods_' . $stylesheet) : [];
        foreach (is_array($mods['nav_menu_locations'] ?? null) ? $mods['nav_menu_locations'] : [] as $location => $termId) {
            $locations[(int) $termId][] = (string) $location;
        }
        $menus = [];
        foreach ($this->termIndex('nav_menu') as $id => $term) {
            $rows = $this->db->rows(
                'SELECT p.ID, p.post_title, p.menu_order FROM ' . $this->db->t('posts') . ' p JOIN ' . $this->db->t('term_relationships') . ' tr ON tr.object_id = p.ID JOIN ' . $this->db->t('term_taxonomy')
                . " tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE p.post_type = 'nav_menu_item' AND p.post_status = 'publish' AND tt.term_id = ? ORDER BY p.menu_order, p.ID",
                [$id]
            );
            $meta = $this->db->meta('postmeta', 'post_id', array_map(static function (array $r): int {
                return (int) $r['ID'];
            }, $rows), ['_menu_item_type', '_menu_item_object', '_menu_item_object_id', '_menu_item_menu_item_parent', '_menu_item_url']);
            $items = [];
            foreach ($rows as $row) {
                $itemId = (int) $row['ID'];
                $m = $meta[$itemId] ?? [];
                $title = (string) $row['post_title'];
                if (trim($title) === '' && (int) ($m['_menu_item_object_id'] ?? 0) > 0) {
                    $title = $this->menuTitle((string) ($m['_menu_item_type'] ?? ''), (int) $m['_menu_item_object_id']);
                }
                $items[] = [
                    'id' => $itemId, 'title' => ['rendered' => $title], 'url' => $m['_menu_item_url'] ?? '', 'object' => $m['_menu_item_object'] ?? 'custom',
                    'object_id' => (int) ($m['_menu_item_object_id'] ?? 0), 'parent' => (int) ($m['_menu_item_menu_item_parent'] ?? 0), 'menu_order' => (int) $row['menu_order'],
                ];
            }
            $menus[] = ['key' => (string) $id, 'menu' => ['id' => $id, 'name' => $term['name'], 'slug' => $term['slug'], 'locations' => $locations[$id] ?? []], 'items' => $items];
        }

        return $menus;
    }

    /** El título de lo que enlaza un elemento de menú sin título propio. */
    private function menuTitle(string $type, int $objectId): string
    {
        if ($type === 'taxonomy') {
            $row = $this->db->row('SELECT name FROM ' . $this->db->t('terms') . ' WHERE term_id = ?', [$objectId]);

            return $row !== null ? (string) $row['name'] : '';
        }
        $row = $this->db->row('SELECT post_title FROM ' . $this->db->t('posts') . ' WHERE ID = ?', [$objectId]);

        return $row !== null ? (string) $row['post_title'] : '';
    }

    /**
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readTeam(string $cursor, int $limit): array
    {
        $capabilities = $this->db->sitePrefix() . 'capabilities';
        list($rows, $next) = $this->db->page(
            'SELECT ID, user_login, user_email, display_name FROM ' . $this->db->u('users'),
            'ID IN (SELECT user_id FROM ' . $this->db->u('usermeta') . ' WHERE meta_key = ? AND (meta_value LIKE ? OR meta_value LIKE ?))',
            [$capabilities, '%"administrator"%', '%"shop_manager"%'],
            'ID',
            'ID',
            $cursor,
            $limit
        );
        $meta = $this->db->meta('usermeta', 'user_id', array_map(static function (array $r): int {
            return (int) $r['ID'];
        }, $rows), [$capabilities, 'locale'], '', true);

        return $this->fromRows($rows, $next, function (array $row) use ($meta, $capabilities): array {
            $id = (int) $row['ID'];
            $roles = [];
            foreach (Database::unserialize($meta[$id][$capabilities] ?? '') as $role => $granted) {
                if (is_string($role) && $granted) {
                    $roles[] = $role;
                }
            }

            return $this->mapper()->teamMember([
                'id' => $id, 'email' => (string) $row['user_email'], 'name' => (string) $row['display_name'], 'username' => (string) $row['user_login'], 'roles' => $roles, 'locale' => $meta[$id]['locale'] ?? '',
            ]);
        }, 'ID');
    }

    // ------------------------------------------------------------------ redirecciones

    /**
     * Las de Redirection y las de Rank Math, si sus tablas existen. El cursor lleva la tabla y la clave: «r:12», «m:3».
     *
     * @return array{records:array<int,array<string,mixed>>,next:?string,read:int,skipped:array<int,array{id:string,reason:string}>}
     */
    private function readRedirects(string $cursor, int $limit): array
    {
        $source = 'r';
        $after = '';
        if ($cursor !== '') {
            if (preg_match('/^([rm]):(\d+)$/', $cursor, $m) !== 1) {
                throw new SourceError('Invalid cursor');
            }
            $source = $m[1];
            $after = $m[2];
        }
        $items = [];
        $next = null;
        if ($source === 'r') {
            if ($this->db->tableExists('redirection_items')) {
                list($rows, $nx) = $this->db->page('SELECT id, url, regex, action_type, action_code, action_data, status FROM ' . $this->db->t('redirection_items'), '', [], 'id', 'id', $after, $limit);
                foreach ($rows as $row) {
                    $items[] = ['id' => 'r' . (string) $row['id'], 'source' => (string) $row['url'], 'target' => (string) $row['action_data'], 'code' => (int) $row['action_code'], 'enabled' => (string) $row['status'] === 'enabled',
                        'plain' => (int) $row['regex'] === 0 && (string) $row['action_type'] === 'url'];
                }
                $next = $nx !== null ? 'r:' . $nx : null;
            }
            if ($next === null && $this->db->tableExists('rank_math_redirections')) {
                $next = 'm:0';
            }
        } else {
            if ($this->db->tableExists('rank_math_redirections')) {
                list($rows, $nx) = $this->db->page('SELECT id, sources, url_to, header_code, status FROM ' . $this->db->t('rank_math_redirections'), '', [], 'id', 'id', $after, $limit);
                foreach ($rows as $row) {
                    foreach (array_values(Database::unserialize((string) $row['sources'])) as $at => $s) {
                        $items[] = [
                            'id' => 'm' . (string) $row['id'] . '-' . $at, 'source' => is_array($s) ? (string) ($s['pattern'] ?? '') : '', 'target' => (string) $row['url_to'], 'code' => (int) $row['header_code'],
                            'enabled' => (string) $row['status'] === 'active', 'plain' => is_array($s) && (string) ($s['comparison'] ?? 'exact') === 'exact',
                        ];
                    }
                }
                $next = $nx !== null ? 'm:' . $nx : null;
            }
        }
        list($records, $skipped) = Batch::map($items, static function (array $i): array {
            if (!$i['plain']) {
                throw new \InvalidArgumentException('redirect by pattern: there is no equivalent');
            }
            $path = static function (string $url): string {
                $url = trim($url);
                if (preg_match('#^https?://#i', $url) === 1) {
                    $p = (string) parse_url($url, PHP_URL_PATH);
                    $q = parse_url($url, PHP_URL_QUERY);

                    return ($p !== '' ? $p : '/') . (is_string($q) && $q !== '' ? '?' . $q : '');
                }

                return $url !== '' && $url[0] !== '/' ? '/' . $url : $url;
            };
            $source = $path((string) $i['source']);
            if ($source === '' || $source === '/') {
                throw new \InvalidArgumentException('redirect without a source path');
            }
            $target = trim((string) $i['target']);
            if ($target === '') {
                throw new \InvalidArgumentException('redirect without a target');
            }

            return ['id' => (string) $i['id'], 'source' => $source, 'target' => preg_match('#^https?://#i', $target) === 1 ? $target : $path($target), 'code' => in_array($i['code'], [301, 302, 307, 308, 410], true) ? (int) $i['code'] : 301, 'active' => (bool) $i['enabled']];
        });

        return Batch::result($records, $next, count($items), $skipped);
    }
}
