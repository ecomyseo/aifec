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

/**
 * Lee una tienda de WooCommerce por su API REST (wc/v3) con clave y secreto por HTTPS con autenticación básica, y los contenidos
 * por la de WordPress (wp/v2). Los menús y el equipo piden además una contraseña de aplicación de WordPress (wp_user y
 * wp_password); sin ella no se ofrecen. La API no da la contraseña de los clientes (password_hash = null).
 */
final class ApiReader extends WpRestReader
{
    private const WC = '/wp-json/wc/v3';

    /** @var array{options:array<string,mixed>,site:array<string,mixed>,status:array<string,mixed>}|null */
    private $shop;

    /** @var array<int,array<int,array<string,mixed>>> */
    private $terms = [];

    /** @var array<string,array<int,mixed>> */
    private $lists = [];

    /** @var array<int,string[]>|null */
    private $categoryPaths;

    public static function describe(): array
    {
        return [
            'source' => 'woocommerce', 'mode' => 'api', 'label' => 'WooCommerce (REST API with key)',
            'help' => 'Reads the whole shop through its REST API. Create a read-only key in WooCommerce > Settings > Advanced > REST API. The API does not give customers\' passwords.',
            'fields' => [
                'url' => ['type' => 'url', 'label' => 'Shop address', 'required' => true, 'hint' => 'https://shop.example'],
                'key' => ['type' => 'text', 'label' => 'Consumer key', 'required' => true, 'hint' => 'ck_…'],
                'secret' => ['type' => 'secret', 'label' => 'Consumer secret', 'required' => true, 'hint' => 'cs_…'],
                'wp_user' => ['type' => 'text', 'label' => 'WordPress user (optional)', 'required' => false, 'hint' => 'Needed for menus, the team and drafts'],
                'wp_password' => ['type' => 'secret', 'label' => 'WordPress application password (optional)', 'required' => false, 'hint' => ''],
            ],
        ];
    }

    public function mode(): string
    {
        return 'api';
    }

    private function hasWordPressLogin(): bool
    {
        return trim((string) ($this->config['wp_user'] ?? '')) !== '' && (string) ($this->config['wp_password'] ?? '') !== '';
    }

    protected function authorization(string $path): ?string
    {
        if (strpos($path, '/wc/v3/') !== false) {
            return $this->basic((string) ($this->config['key'] ?? ''), (string) ($this->config['secret'] ?? ''));
        }
        if (strpos($path, '/wp/v2/') !== false && $this->hasWordPressLogin()) {
            return $this->basic((string) $this->config['wp_user'], (string) $this->config['wp_password']);
        }

        return null;
    }

    protected function contentQuery(): array
    {
        return $this->hasWordPressLogin() ? ['context' => 'edit', 'status' => 'any'] : [];
    }

    public function types(): array
    {
        $types = [
            'settings', 'tax_classes', 'tax_rates', 'shipping_zones', 'carriers', 'payment_methods', 'brands', 'categories', 'attributes', 'products',
            'customers', 'coupons', 'orders', 'reviews', 'media', 'pages', 'post_categories', 'posts',
        ];
        if ($this->hasWordPressLogin()) {
            $types[] = 'menus';
            $types[] = 'team';
        }

        return $types;
    }

    // ------------------------------------------------------------------ la tienda: ajustes, zona horaria, unidades

    /**
     * Ajustes de WooCommerce, datos del sitio y estado del sistema (una vez por lector).
     *
     * @return array{options:array<string,mixed>,site:array<string,mixed>,status:array<string,mixed>}
     */
    private function shop(): array
    {
        if ($this->shop !== null) {
            return $this->shop;
        }
        $options = [];
        foreach (['general', 'products', 'tax', 'account', 'email', 'advanced'] as $group) {
            foreach ($this->listOf($this->request(self::WC . '/settings/' . $group)) as $setting) {
                if (is_array($setting) && isset($setting['id']) && is_string($setting['id']) && array_key_exists('value', $setting)) {
                    $options[$setting['id']] = $setting['value'];
                }
            }
        }
        $root = $this->request('/wp-json/');
        $site = $root->status === 404 ? [] : $this->objectOf($root);
        $status = $this->request(self::WC . '/system_status');
        $statusData = $status->status === 404 ? [] : $this->objectOf($status);
        $this->shop = ['options' => $options, 'site' => $site, 'status' => $statusData];

        return $this->shop;
    }

    protected function buildMapper(): Mapper
    {
        $shop = $this->shop();
        $o = $shop['options'];
        $legal = [];
        foreach (['woocommerce_terms_page_id' => 'terms', 'woocommerce_refund_returns_page_id' => 'returns', 'wp_page_for_privacy_policy' => 'privacy'] as $option => $kind) {
            if ((int) ($o[$option] ?? 0) > 0) {
                $legal[(int) $o[$option]] = $kind;
            }
        }
        $env = is_array($shop['status']['environment'] ?? null) ? $shop['status']['environment'] : [];

        return new Mapper([
            'locale' => (string) (($env['language'] ?? '') !== '' ? $env['language'] : ($this->config['locale'] ?? '')),
            'timezone' => Mapper::timezone((string) ($shop['site']['timezone_string'] ?? ''), $shop['site']['gmt_offset'] ?? 0),
            'weight_unit' => (string) ($o['woocommerce_weight_unit'] ?? 'kg'), 'dimension_unit' => (string) ($o['woocommerce_dimension_unit'] ?? 'cm'),
            'prices_include_tax' => ($o['woocommerce_prices_include_tax'] ?? 'no') === 'yes', 'legal_pages' => $legal, 'site_url' => $this->base,
        ]);
    }

    /**
     * @return array<int,array{slug:string,name:string}>
     */
    private function plugins(): array
    {
        $out = [];
        $plugins = $this->shop()['status']['active_plugins'] ?? [];
        foreach (is_array($plugins) ? $plugins : [] as $plugin) {
            if (!is_array($plugin)) {
                continue;
            }
            $path = (string) ($plugin['plugin'] ?? '');
            $slug = strpos($path, '/') !== false ? substr($path, 0, (int) strpos($path, '/')) : (string) preg_replace('/\.php$/', '', $path);
            $out[] = ['slug' => $slug, 'name' => (string) ($plugin['name'] ?? $slug)];
        }

        return $out;
    }

    // ------------------------------------------------------------------ comprobar, contar

    public function probe(): array
    {
        $result = ['ok' => false, 'message' => '', 'version' => '', 'shops' => [], 'languages' => [], 'currency' => '', 'warnings' => [], 'counts' => []];
        try {
            $shop = $this->shop();
            $products = $this->total(self::WC . '/products', ['status' => 'any']);
        } catch (SourceDenied $e) {
            $result['message'] = 'Access was denied: check the API key and secret';

            return $result;
        } catch (SourceError $e) {
            $result['message'] = 'The shop could not be reached';

            return $result;
        }
        $env = is_array($shop['status']['environment'] ?? null) ? $shop['status']['environment'] : [];
        $settings = is_array($shop['status']['settings'] ?? null) ? $shop['status']['settings'] : [];
        $hpos = !empty($settings['HPOS_enabled']);
        $result['version'] = 'WooCommerce ' . (string) ($env['version'] ?? '?') . ', WordPress ' . (string) ($env['wp_version'] ?? '?') . ', HPOS ' . ($hpos ? 'on' : 'off');
        $result['currency'] = (string) ($shop['options']['woocommerce_currency'] ?? ($settings['currency'] ?? ''));
        $result['languages'] = [$this->mapper()->language()];
        $result['counts']['products'] = (int) $products;
        foreach (Mapper::unmappedPlugins($this->plugins()) as $entry) {
            if (strpos($entry['note'], 'Multilingual') === 0) {
                $result['warnings'][] = 'WPML or Polylang found: only the main language is brought and the other languages are not';
            }
        }
        if ($products === null || $products < 1) {
            $result['message'] = 'The shop answered but it has no products to read';

            return $result;
        }
        if (!$this->hasWordPressLogin()) {
            $result['warnings'][] = 'Without a WordPress login the menus, the team and the drafts of pages and posts are not read';
        }
        $result['ok'] = true;
        $result['message'] = 'Connected to the shop';

        return $result;
    }

    /** @return array{0:string,1:array<string,string|int>}|null */
    private function countRoute(string $type): ?array
    {
        $routes = [
            'products' => [self::WC . '/products', ['status' => 'any']], 'categories' => [self::WC . '/products/categories', []],
            'customers' => [self::WC . '/customers', []], 'coupons' => [self::WC . '/coupons', []], 'orders' => [self::WC . '/orders', ['status' => 'any']],
            'reviews' => [self::WC . '/products/reviews', []], 'tax_rates' => [self::WC . '/taxes', []], 'media' => ['/wp-json/wp/v2/media', []],
            'pages' => ['/wp-json/wp/v2/pages', $this->contentQuery()], 'posts' => ['/wp-json/wp/v2/posts', $this->contentQuery()],
            'post_categories' => ['/wp-json/wp/v2/categories', []], 'brands' => [self::WC . '/products/brands', []],
        ];

        return $routes[$type] ?? null;
    }

    public function count(string $type): ?int
    {
        $route = $this->countRoute($type);

        return $route === null ? null : $this->total($route[0], $route[1]);
    }

    public function checksums(): array
    {
        $sums = [];
        $names = [
            'products' => 'products_count', 'categories' => 'categories_count', 'customers' => 'customers_count', 'orders' => 'orders_count',
            'coupons' => 'coupons_count', 'tax_rates' => 'tax_rates_count', 'pages' => 'pages_count', 'posts' => 'posts_count',
        ];
        foreach ($names as $type => $name) {
            $count = $this->count($type);
            if ($count !== null) {
                $sums[$name] = $count;
            }
        }

        return $sums;
    }

    public function unmapped(): array
    {
        $out = Mapper::unmappedPlugins($this->plugins());
        $grouped = $this->total(self::WC . '/products', ['type' => 'grouped']);
        if ($grouped !== null && $grouped > 0) {
            $out[] = ['kind' => 'feature', 'name' => 'grouped products', 'count' => $grouped, 'note' => 'Brought as simple products; their grouping is not'];
        }

        return $out;
    }

    // ------------------------------------------------------------------ leer

    public function read(string $type, string $cursor, int $limit): array
    {
        $mapper = $this->mapper();
        switch ($type) {
            case 'settings':
                list($items, $next) = Batch::slice([['id' => 'main']], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, function () use ($mapper): array {
                    return $mapper->settings($this->shop()['options'], $this->siteData());
                });
                break;
            case 'tax_classes':
                list($items, $next) = Batch::slice($this->taxClasses(), $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $c) use ($mapper): array {
                    return $mapper->taxClass((string) ($c['slug'] ?? ''), (string) ($c['name'] ?? ''));
                }, 'slug');
                break;
            case 'tax_rates':
                list($items, $next) = $this->page(self::WC . '/taxes', ['orderby' => 'id', 'order' => 'asc'], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $t) use ($mapper): array {
                    return $mapper->taxRate($t);
                });
                break;
            case 'shipping_zones':
                list($items, $next) = Batch::slice($this->zones(), $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $z) use ($mapper): array {
                    return $mapper->zone($z['zone'], $z['locations']);
                }, 'key');
                break;
            case 'carriers':
                list($items, $next) = Batch::slice($this->methods(), $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $m) use ($mapper): array {
                    return $mapper->carrier($m['zone'], $m['method']);
                }, 'key');
                break;
            case 'payment_methods':
                list($items, $next) = Batch::slice($this->listOf($this->request(self::WC . '/payment_gateways')), $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $g) use ($mapper): array {
                    return $mapper->paymentMethod($g);
                });
                break;
            case 'brands':
                list($items, $next) = $this->page(self::WC . '/products/brands', [], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $b) use ($mapper): array {
                    return $mapper->brand($b);
                });
                break;
            case 'categories':
                list($items, $next) = $this->page(self::WC . '/products/categories', ['orderby' => 'id', 'order' => 'asc'], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, function (array $c) use ($mapper): array {
                    return $mapper->category($c, $this->categoryPaths()[(int) ($c['id'] ?? 0)] ?? []);
                });
                break;
            case 'attributes':
                list($items, $next) = Batch::slice($this->listOf($this->request(self::WC . '/products/attributes')), $cursor, $limit);
                list($records, $skipped) = Batch::map($items, function (array $a) use ($mapper): array {
                    return $mapper->attribute($a, $this->termsOf((int) ($a['id'] ?? 0)));
                });
                break;
            case 'products':
                list($items, $next) = $this->page(self::WC . '/products', ['status' => 'any', 'orderby' => 'id', 'order' => 'asc'], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, function (array $p) use ($mapper): array {
                    return $this->product($mapper, $p);
                });
                break;
            case 'customers':
                list($items, $next) = $this->page(self::WC . '/customers', ['orderby' => 'id', 'order' => 'asc'], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $c) use ($mapper): array {
                    return $mapper->customer($c, null);
                });
                break;
            case 'coupons':
                list($items, $next) = $this->page(self::WC . '/coupons', ['orderby' => 'id', 'order' => 'asc'], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $c) use ($mapper): array {
                    return $mapper->coupon($c);
                });
                break;
            case 'orders':
                list($items, $next) = $this->page(self::WC . '/orders', ['status' => 'any', 'orderby' => 'id', 'order' => 'asc'], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, function (array $o) use ($mapper): array {
                    $id = (int) ($o['id'] ?? 0);
                    $refunds = !empty($o['refunds']) ? $this->listOf($this->request(self::WC . '/orders/' . $id . '/refunds')) : [];
                    $notes = $this->listOf($this->request(self::WC . '/orders/' . $id . '/notes'));

                    return $mapper->order($o, $refunds, $notes);
                });
                break;
            case 'reviews':
                list($items, $next) = $this->page(self::WC . '/products/reviews', ['status' => 'all', 'orderby' => 'id', 'order' => 'asc'], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $r) use ($mapper): array {
                    return $mapper->review($r);
                });
                break;
            case 'media':
            case 'pages':
            case 'post_categories':
            case 'posts':
                return $this->readContent($type, $cursor, $limit);
            case 'menus':
                list($items, $next) = Batch::slice($this->listOf($this->request('/wp-json/wp/v2/menus')), $cursor, $limit);
                list($records, $skipped) = Batch::map($items, function (array $m) use ($mapper): array {
                    return $mapper->menu($m, $this->all('/wp-json/wp/v2/menu-items', ['menus' => (int) ($m['id'] ?? 0), 'context' => 'view']));
                });
                break;
            case 'team':
                list($items, $next) = $this->page('/wp-json/wp/v2/users', ['context' => 'edit', 'roles' => 'administrator,shop_manager', 'orderby' => 'id', 'order' => 'asc'], $cursor, $limit);
                list($records, $skipped) = Batch::map($items, static function (array $u) use ($mapper): array {
                    return $mapper->teamMember($u);
                });
                break;
            default:
                throw new \InvalidArgumentException('This reader cannot read ' . $type);
        }

        return Batch::result($records, $next, count($items), $skipped);
    }

    // ------------------------------------------------------------------ piezas

    /** @return array<string,mixed> */
    private function siteData(): array
    {
        $shop = $this->shop();
        $site = $shop['site'];
        $data = [
            'title' => (string) ($site['name'] ?? ''), 'tagline' => (string) ($site['description'] ?? ''), 'timezone_string' => (string) ($site['timezone_string'] ?? ''),
            'gmt_offset' => $site['gmt_offset'] ?? 0, 'admin_email' => (string) ($shop['options']['woocommerce_email_from_address'] ?? ''),
        ];
        foreach (['logo' => 'site_logo', 'icon' => 'site_icon'] as $field => $key) {
            $image = (int) ($site[$key] ?? 0) > 0 ? $this->mediaImage((int) $site[$key]) : null;
            if ($image !== null) {
                $data[$field] = $image;
            }
        }
        $last = $this->listOf($this->request(self::WC . '/orders', ['orderby' => 'id', 'order' => 'desc', 'per_page' => 1, 'status' => 'any']));
        if (isset($last[0]['id']) && (int) $last[0]['id'] > 0) {
            $data['next_order_number'] = (int) $last[0]['id'] + 1;
        }

        return $data;
    }

    /** @return ?array<string,string> */
    private function mediaImage(int $id): ?array
    {
        $response = $this->request('/wp-json/wp/v2/media/' . $id);
        if ($response->status === 404) {
            return null;
        }
        $media = $this->objectOf($response);

        return isset($media['source_url']) && is_string($media['source_url']) && $media['source_url'] !== '' ? ['url' => $media['source_url']] : null;
    }

    /**
     * Las clases de impuesto: la estándar (que la API no lista) y las demás.
     *
     * @return array<int,array<string,string>>
     */
    private function taxClasses(): array
    {
        $classes = [['slug' => 'standard', 'name' => 'Standard']];
        foreach ($this->listOf($this->request(self::WC . '/taxes/classes')) as $class) {
            if (is_array($class) && (string) ($class['slug'] ?? '') !== '' && $class['slug'] !== 'standard') {
                $classes[] = ['slug' => (string) $class['slug'], 'name' => (string) ($class['name'] ?? '')];
            }
        }

        return $classes;
    }

    /**
     * Las zonas con sus ubicaciones, la zona 0 («resto del mundo») incluida si tiene métodos.
     *
     * @return array<int,array<string,mixed>>
     */
    private function zones(): array
    {
        if (!isset($this->lists['zones'])) {
            $zones = [];
            foreach ($this->listOf($this->request(self::WC . '/shipping/zones')) as $zone) {
                if (is_array($zone) && isset($zone['id'])) {
                    $zones[] = ['key' => (string) $zone['id'], 'zone' => $zone, 'locations' => $this->listOf($this->request(self::WC . '/shipping/zones/' . (int) $zone['id'] . '/locations'))];
                }
            }
            if ($this->listOf($this->request(self::WC . '/shipping/zones/0/methods')) !== []) {
                $zones[] = ['key' => '0', 'zone' => ['id' => 0, 'name' => 'Locations not covered by your other zones', 'order' => 9999], 'locations' => []];
            }
            $this->lists['zones'] = $zones;
        }

        return $this->lists['zones'];
    }

    /**
     * Los métodos de envío de cada zona.
     *
     * @return array<int,array<string,mixed>>
     */
    private function methods(): array
    {
        if (!isset($this->lists['methods'])) {
            $methods = [];
            foreach ($this->zones() as $zone) {
                $zoneId = (int) $zone['zone']['id'];
                foreach ($this->listOf($this->request(self::WC . '/shipping/zones/' . $zoneId . '/methods')) as $method) {
                    if (is_array($method)) {
                        $methods[] = ['key' => (string) ($method['instance_id'] ?? '?'), 'zone' => $zone['zone'], 'method' => $method];
                    }
                }
            }
            $this->lists['methods'] = $methods;
        }

        return $this->lists['methods'];
    }

    /**
     * @return array<int,array<string,mixed>> términos de un atributo global
     */
    private function termsOf(int $attributeId): array
    {
        if ($attributeId > 0 && !isset($this->terms[$attributeId])) {
            $this->terms[$attributeId] = array_values(array_filter($this->all(self::WC . '/products/attributes/' . $attributeId . '/terms'), 'is_array'));
        }

        return $attributeId > 0 ? $this->terms[$attributeId] : [];
    }

    /**
     * Las rutas antiguas de cada categoría, de la API pública de la tienda (la de wc/v3 no trae el enlace). Si no responde, vacío.
     *
     * @return array<int,string[]>
     */
    private function categoryPaths(): array
    {
        if ($this->categoryPaths === null) {
            $this->categoryPaths = [];
            try {
                foreach ($this->all('/wp-json/wc/store/v1/products/categories') as $category) {
                    if (is_array($category) && isset($category['id'])) {
                        $this->categoryPaths[(int) $category['id']] = $this->mapper()->paths($category['permalink'] ?? '');
                    }
                }
            } catch (SourceDenied $e) {
                $this->categoryPaths = [];
            } catch (SourceError $e) {
                $this->categoryPaths = [];
            }
        }

        return $this->categoryPaths;
    }

    /**
     * @param array<string,mixed> $p
     *
     * @return array<string,mixed>
     */
    private function product(Mapper $mapper, array $p): array
    {
        $id = (int) ($p['id'] ?? 0);
        $variations = [];
        if ((string) ($p['type'] ?? '') === 'variable') {
            $variations = array_values(array_filter($this->all(self::WC . '/products/' . $id . '/variations'), 'is_array'));
        }
        $terms = [];
        foreach (is_array($p['attributes'] ?? null) ? $p['attributes'] : [] as $attribute) {
            if (is_array($attribute) && (int) ($attribute['id'] ?? 0) > 0) {
                $terms[(int) $attribute['id']] = $this->termsOf((int) $attribute['id']);
            }
        }

        return $mapper->product($p, $variations, $terms);
    }
}
