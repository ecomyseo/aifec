<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Sites;

use Aifec\Cms\Blocks\BlockSchema;

/**
 * Comprueba un paquete de demostración antes de tocar ningún sitio. Cada problema dice el fichero, el elemento y el motivo
 * («products.json: product #3 («DEMO-003»): the price is required»). No escribe nada.
 */
final class PackageCheck
{
    private const CODE = '/^[a-z0-9][a-z0-9-]{0,62}$/';

    private const SKU = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,62}$/';

    private const STORE_KEYS = ['name', 'email', 'phone', 'address', 'legal_name', 'currency', 'currency_symbol', 'country', 'prices_include_tax', 'display_with_tax'];

    private const ATTRIBUTE_TYPES = ['select', 'multiselect', 'text', 'number', 'bool', 'color'];

    private const MENU_TYPES = ['home', 'page', 'post', 'category', 'product', 'url'];

    /** Zonas de envío que trae de serie el módulo de envíos. */
    private const SEEDED_ZONES = ['Peninsula', 'Balearic Islands', 'Canary Islands', 'Ceuta and Melilla'];

    /** @var Package */
    private $pkg;

    /** @var string */
    private $root;

    /** @var string[] */
    private $problems = [];

    /** @var array<string,?string> código de categoría => código de su madre */
    private $categories = [];

    /** @var array<string,bool> */
    private $brands = [];

    /** @var array<string,array<string,mixed>> código de atributo => ['type', 'variant', 'values' => [código => true]] */
    private $attributes = [];

    /** @var array<string,bool> */
    private $skus = [];

    /** @var array<string,bool> */
    private $pages = [];

    /** @var array<string,bool> */
    private $posts = [];

    private function __construct(Package $pkg, string $root)
    {
        $this->pkg = $pkg;
        $this->root = rtrim(str_replace('\\', '/', $root), '/');
    }

    /**
     * @return string[] problemas (vacío si el paquete vale)
     */
    public static function run(Package $pkg, string $root): array
    {
        $check = new self($pkg, $root);
        $check->all();

        return $check->problems;
    }

    private function all(): void
    {
        $this->meta();
        $this->categories();
        $this->brands();
        $this->attributes();
        $this->products();
        $this->pages();
        $this->posts();
        $this->menus();
        $this->carriers();
        $this->coupons();
        $this->reviews();
    }

    private function bad(string $where, string $message): void
    {
        $this->problems[] = $where . ': ' . $message;
    }

    // ------------------------------------------------------------------ package.json

    private function meta(): void
    {
        $m = $this->pkg->meta();
        $name = isset($m['name']) && is_string($m['name']) ? trim($m['name']) : '';
        if ($name === '' || mb_strlen($name) > 120) {
            $this->bad('package.json', '«name» is required (120 characters at most)');
        }
        if (!isset($m['description']) || !is_string($m['description']) || trim($m['description']) === '' || mb_strlen($m['description']) > 500) {
            $this->bad('package.json', '«description» is required (500 characters at most)');
        }
        if (!isset($m['sector']) || !is_string($m['sector']) || preg_match('/^[a-z0-9-]{2,40}$/', $m['sector']) !== 1) {
            $this->bad('package.json', '«sector» is required (lowercase letters, digits and hyphens)');
        }
        if (!isset($m['locale']) || !is_string($m['locale']) || preg_match('/^[a-z]{2}$/', $m['locale']) !== 1) {
            $this->bad('package.json', '«locale» is required: a two-letter language code such as «es»');
        }
        $theme = isset($m['theme']) && is_string($m['theme']) ? $m['theme'] : '';
        if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $theme) !== 1 || !is_file($this->root . '/themes/' . $theme . '/theme.json')) {
            $this->bad('package.json', '«theme» must be the slug of an installed theme' . ($theme !== '' ? ' (themes/' . $theme . ' does not exist)' : ''));
        }
        if (isset($m['theme_options']) && (!is_array($m['theme_options']) || ($m['theme_options'] !== [] && array_is_list($m['theme_options'])))) {
            $this->bad('package.json', '«theme_options» must be an object (option => value)');
        }
        if (isset($m['store'])) {
            $store = $m['store'];
            if (!is_array($store) || ($store !== [] && array_is_list($store))) {
                $this->bad('package.json', '«store» must be an object');
            } else {
                foreach ($store as $key => $value) {
                    if (!in_array($key, self::STORE_KEYS, true) || (!is_scalar($value) && $value !== null)) {
                        $this->bad('package.json', '«store.' . $key . '» is not a known shop setting');
                    }
                }
                if (isset($store['email']) && $store['email'] !== '' && (!is_string($store['email']) || filter_var($store['email'], FILTER_VALIDATE_EMAIL) === false)) {
                    $this->bad('package.json', '«store.email» is not a valid email address');
                }
                if (isset($store['country']) && (!is_string($store['country']) || preg_match('/^[A-Z]{2}$/', $store['country']) !== 1)) {
                    $this->bad('package.json', '«store.country» must be a two-letter country code such as «ES»');
                }
            }
        }
        if (isset($m['settings'])) {
            if (!is_array($m['settings']) || ($m['settings'] !== [] && array_is_list($m['settings']))) {
                $this->bad('package.json', '«settings» must be an object (key => value)');
            } else {
                foreach ($m['settings'] as $key => $value) {
                    $key = (string) $key;
                    if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,190}$/', $key) !== 1) {
                        $this->bad('package.json', '«settings» has an invalid key «' . $key . '»');
                        continue;
                    }
                    foreach (Package::FORBIDDEN_SETTINGS as $forbidden) {
                        if ($key === $forbidden || (substr($forbidden, -1) === '.' && strpos($key, $forbidden) === 0)) {
                            $this->bad('package.json', 'the setting «' . $key . '» cannot be set by a demo package (the system controls it)');
                        }
                    }
                    if (!is_scalar($value) && !is_array($value) && $value !== null) {
                        $this->bad('package.json', 'the setting «' . $key . '» has a value that cannot be stored');
                    }
                }
            }
        }
        if (isset($m['modules'])) {
            if (!is_array($m['modules']) || !array_is_list($m['modules'])) {
                $this->bad('package.json', '«modules» must be a list of module slugs');
            } else {
                foreach ($m['modules'] as $slug) {
                    $slug = is_string($slug) ? $slug : '';
                    if (preg_match('/^[a-z0-9][a-z0-9-]*$/', $slug) !== 1 || !is_file($this->root . '/modules/' . $slug . '/module.json')) {
                        $this->bad('package.json', '«modules» lists «' . $slug . '», which is not an installed module');
                    } elseif (in_array($slug, Package::FORBIDDEN_MODULES, true)) {
                        $this->bad('package.json', 'the module «' . $slug . '» cannot be turned on in a demo');
                    } elseif (strpos($slug, 'payments-') === 0 && $slug !== 'payments-simulated') {
                        $this->bad('package.json', 'the module «' . $slug . '» is a real payment: a demo only offers the simulated payment');
                    }
                }
            }
        }
    }

    // ------------------------------------------------------------------ catálogo

    private function categories(): void
    {
        $list = $this->list('categories.json');
        foreach ($list as $i => $c) {
            $where = 'categories.json: category #' . ($i + 1) . $this->label($c, 'code');
            if (!$this->isMap($c)) {
                $this->problems[] = $where . ': it must be an object';
                continue;
            }
            $code = $this->code($c, 'code', $where);
            if ($code === null) {
                continue;
            }
            if (array_key_exists($code, $this->categories)) {
                $this->problems[] = $where . ': the code is repeated';
            }
            $this->requireText($c, 'name', 191, $where);
            $parent = isset($c['parent']) && $c['parent'] !== null ? (string) $c['parent'] : null;
            $this->categories[$code] = $parent;
            $this->image($c, 'image', $where);
        }
        foreach ($this->categories as $code => $parent) {
            $where = 'categories.json: category «' . $code . '»';
            if ($parent !== null && !array_key_exists($parent, $this->categories)) {
                $this->problems[] = $where . ': the parent «' . $parent . '» does not exist';
                continue;
            }
            $seen = [$code => true];
            for ($up = $parent; $up !== null && isset($this->categories[$up]); $up = $this->categories[$up]) {
                if (isset($seen[$up])) {
                    $this->problems[] = $where . ': the parent chain goes round in a circle';
                    break;
                }
                $seen[$up] = true;
            }
        }
    }

    private function brands(): void
    {
        foreach ($this->list('brands.json') as $i => $b) {
            $where = 'brands.json: brand #' . ($i + 1) . $this->label($b, 'code');
            if (!$this->isMap($b)) {
                $this->problems[] = $where . ': it must be an object';
                continue;
            }
            $code = $this->code($b, 'code', $where);
            $this->requireText($b, 'name', 120, $where);
            if ($code !== null) {
                if (isset($this->brands[$code])) {
                    $this->problems[] = $where . ': the code is repeated';
                }
                $this->brands[$code] = true;
            }
            $this->image($b, 'image', $where);
        }
    }

    private function attributes(): void
    {
        foreach ($this->list('attributes.json') as $i => $a) {
            $where = 'attributes.json: attribute #' . ($i + 1) . $this->label($a, 'code');
            if (!$this->isMap($a)) {
                $this->problems[] = $where . ': it must be an object';
                continue;
            }
            $code = $this->code($a, 'code', $where);
            $this->requireText($a, 'name', 120, $where);
            $type = isset($a['type']) && is_string($a['type']) ? $a['type'] : 'select';
            if (!in_array($type, self::ATTRIBUTE_TYPES, true)) {
                $this->problems[] = $where . ': the type «' . $type . '» is not one of ' . implode(', ', self::ATTRIBUTE_TYPES);
            }
            $values = [];
            $rawValues = isset($a['values']) && is_array($a['values']) ? $a['values'] : [];
            foreach ($rawValues as $v) {
                $vcode = is_string($v) ? $v : (is_array($v) && isset($v['code']) && is_string($v['code']) ? $v['code'] : '');
                if (preg_match(self::CODE, $vcode) !== 1) {
                    $this->problems[] = $where . ': a value has no valid code (lowercase letters, digits and hyphens)';
                    continue;
                }
                if (isset($values[$vcode])) {
                    $this->problems[] = $where . ': the value «' . $vcode . '» is repeated';
                }
                $values[$vcode] = true;
                if (is_array($v) && isset($v['color']) && (!is_string($v['color']) || preg_match('/^#[0-9a-fA-F]{6}$/', $v['color']) !== 1)) {
                    $this->problems[] = $where . ': the colour of «' . $vcode . '» must look like #aabbcc';
                }
            }
            if ($code === null) {
                continue;
            }
            if (isset($this->attributes[$code])) {
                $this->problems[] = $where . ': the code is repeated';
            }
            $this->attributes[$code] = ['type' => $type, 'variant' => !empty($a['variant']), 'values' => $values];
        }
    }

    private function products(): void
    {
        $covers = [];
        foreach ($this->list('products.json') as $i => $p) {
            $where = 'products.json: product #' . ($i + 1) . $this->label($p, 'sku');
            if (!$this->isMap($p)) {
                $this->problems[] = $where . ': it must be an object';
                continue;
            }
            $sku = isset($p['sku']) && is_string($p['sku']) ? $p['sku'] : '';
            if (preg_match(self::SKU, $sku) !== 1) {
                $this->problems[] = $where . ': the reference («sku») is required (letters, digits, dots, hyphens)';
            } elseif (isset($this->skus[$sku])) {
                $this->problems[] = $where . ': the reference is repeated';
            } else {
                $this->skus[$sku] = true;
            }
            $this->requireText($p, 'name', 191, $where);
            if (!array_key_exists('price', $p) || !is_int($p['price']) || $p['price'] < 0) {
                $this->problems[] = $where . ': the price is required, in cents, as a whole number (for example 1999)';
            }
            foreach (['compare_price', 'sale_price'] as $key) {
                if (isset($p[$key]) && (!is_int($p[$key]) || $p[$key] < 0)) {
                    $this->problems[] = $where . ': «' . $key . '» must be a whole number of cents';
                }
            }
            if (isset($p['sale_price'], $p['price']) && is_int($p['sale_price']) && is_int($p['price']) && $p['sale_price'] >= $p['price']) {
                $this->problems[] = $where . ': the offer price must be lower than the price';
            }
            if (isset($p['stock']) && (!is_int($p['stock']) || $p['stock'] < 0)) {
                $this->problems[] = $where . ': «stock» must be a whole number, zero or more';
            }
            if (isset($p['kind']) && !in_array($p['kind'], ['physical', 'service'], true)) {
                $this->problems[] = $where . ': «kind» must be physical or service (a demo has no files to download)';
            }
            if (isset($p['status']) && !in_array($p['status'], ['published', 'draft'], true)) {
                $this->problems[] = $where . ': «status» must be published or draft';
            }
            if (isset($p['gtin']) && !$this->gtinOk($p['gtin'])) {
                $this->problems[] = $where . ': the barcode must be an invented EAN-13 starting with ' . Package::GTIN_PREFIX . ' and with a correct check digit';
            }
            foreach (isset($p['categories']) && is_array($p['categories']) ? $p['categories'] : [] as $cat) {
                if (!is_string($cat) || !array_key_exists($cat, $this->categories)) {
                    $this->problems[] = $where . ': the category «' . (is_string($cat) ? $cat : '?') . '» is not in categories.json';
                }
            }
            if (isset($p['brand']) && $p['brand'] !== null && (!is_string($p['brand']) || !isset($this->brands[$p['brand']]))) {
                $this->problems[] = $where . ': the brand «' . (is_string($p['brand']) ? $p['brand'] : '?') . '» is not in brands.json';
            }
            $this->productAttributes($p, $where);
            $this->productFacts($p, $where);
            $this->productVariants($p, $where);

            $images = isset($p['images']) && is_array($p['images']) ? array_values($p['images']) : [];
            if ($images === []) {
                $this->problems[] = $where . ': at least one image is required (the first one is the cover)';
            }
            foreach ($images as $n => $image) {
                $file = $this->imageName($image);
                if ($file === '') {
                    $this->problems[] = $where . ': image #' . ($n + 1) . ' needs a file name';
                } elseif ($this->pkg->mediaPath($file) === null) {
                    $this->problems[] = $where . ': the image «' . $file . '» does not exist in media/';
                } elseif ($n === 0) {
                    if (isset($covers[$file])) {
                        $this->problems[] = $where . ': the cover image «' . $file . '» is already the cover of another product';
                    }
                    $covers[$file] = true;
                }
            }
        }
    }

    /**
     * @param array<string,mixed> $p
     */
    private function productAttributes(array $p, string $where): void
    {
        if (!isset($p['attributes'])) {
            return;
        }
        if (!is_array($p['attributes']) || ($p['attributes'] !== [] && array_is_list($p['attributes']))) {
            $this->problems[] = $where . ': «attributes» must be an object (attribute code => value)';

            return;
        }
        foreach ($p['attributes'] as $code => $value) {
            $code = (string) $code;
            if (!isset($this->attributes[$code])) {
                $this->problems[] = $where . ': the attribute «' . $code . '» is not in attributes.json';
                continue;
            }
            $attribute = $this->attributes[$code];
            $type = (string) $attribute['type'];
            if ($type === 'select' || $type === 'color' || $type === 'multiselect') {
                foreach (is_array($value) ? $value : [$value] as $item) {
                    if (!is_string($item) || !isset($attribute['values'][$item])) {
                        $this->problems[] = $where . ': «' . (is_string($item) ? $item : '?') . '» is not a value of the attribute «' . $code . '»';
                    }
                }
            } elseif ($type === 'number' && !is_int($value) && !is_float($value)) {
                $this->problems[] = $where . ': the attribute «' . $code . '» needs a number';
            } elseif ($type === 'bool' && !is_bool($value)) {
                $this->problems[] = $where . ': the attribute «' . $code . '» needs true or false';
            } elseif ($type === 'text' && !is_string($value)) {
                $this->problems[] = $where . ': the attribute «' . $code . '» needs a text';
            }
        }
    }

    /**
     * @param array<string,mixed> $p
     */
    private function productFacts(array $p, string $where): void
    {
        foreach (isset($p['facts']) && is_array($p['facts']) ? $p['facts'] : [] as $fact) {
            if (!is_array($fact) || !isset($fact['label'], $fact['value']) || !is_string($fact['label']) || !is_scalar($fact['value']) || trim($fact['label']) === '') {
                $this->problems[] = $where . ': every key fact needs a «label» and a «value»';
            }
        }
        foreach (isset($p['faqs']) && is_array($p['faqs']) ? $p['faqs'] : [] as $faq) {
            if (!is_array($faq) || !isset($faq['question'], $faq['answer']) || !is_string($faq['question']) || !is_string($faq['answer']) || trim($faq['question']) === '' || trim($faq['answer']) === '') {
                $this->problems[] = $where . ': every question needs a «question» and an «answer»';
            }
        }
    }

    /**
     * @param array<string,mixed> $p
     */
    private function productVariants(array $p, string $where): void
    {
        foreach (isset($p['variants']) && is_array($p['variants']) ? array_values($p['variants']) : [] as $n => $v) {
            $vwhere = $where . ': variant #' . ($n + 1);
            if (!is_array($v)) {
                $this->problems[] = $vwhere . ' must be an object';
                continue;
            }
            $vsku = isset($v['sku']) && is_string($v['sku']) ? $v['sku'] : '';
            if (preg_match(self::SKU, $vsku) !== 1) {
                $this->problems[] = $vwhere . ' needs its own reference («sku»)';
            } elseif (isset($this->skus[$vsku])) {
                $this->problems[] = $vwhere . ': the reference «' . $vsku . '» is repeated';
            } else {
                $this->skus[$vsku] = true;
            }
            if (isset($v['price']) && (!is_int($v['price']) || $v['price'] < 0)) {
                $this->problems[] = $vwhere . ': «price» must be a whole number of cents';
            }
            if (isset($v['stock']) && (!is_int($v['stock']) || $v['stock'] < 0)) {
                $this->problems[] = $vwhere . ': «stock» must be a whole number, zero or more';
            }
            if (isset($v['gtin']) && !$this->gtinOk($v['gtin'])) {
                $this->problems[] = $vwhere . ': the barcode must be an invented EAN-13 starting with ' . Package::GTIN_PREFIX . ' and with a correct check digit';
            }
            $values = isset($v['values']) && is_array($v['values']) ? $v['values'] : [];
            if ($values === []) {
                $this->problems[] = $vwhere . ' needs «values» (attribute code => value code)';
            }
            foreach ($values as $code => $value) {
                $code = (string) $code;
                if (!isset($this->attributes[$code]) || empty($this->attributes[$code]['variant'])) {
                    $this->problems[] = $vwhere . ': «' . $code . '» is not an attribute marked as «variant» in attributes.json';
                } elseif (!is_string($value) || !isset($this->attributes[$code]['values'][$value])) {
                    $this->problems[] = $vwhere . ': «' . (is_string($value) ? $value : '?') . '» is not a value of the attribute «' . $code . '»';
                }
            }
            if (isset($v['image']) && (!is_string($v['image']) || $this->pkg->mediaPath($v['image']) === null)) {
                $this->problems[] = $vwhere . ': the image «' . (is_string($v['image']) ? $v['image'] : '?') . '» does not exist in media/';
            }
        }
    }

    // ------------------------------------------------------------------ contenidos

    private function pages(): void
    {
        $homes = 0;
        foreach ($this->list('pages.json') as $i => $page) {
            $where = 'pages.json: page #' . ($i + 1) . $this->label($page, 'code');
            if (!$this->isMap($page)) {
                $this->problems[] = $where . ': it must be an object';
                continue;
            }
            $code = $this->code($page, 'code', $where);
            if ($code !== null) {
                if (isset($this->pages[$code])) {
                    $this->problems[] = $where . ': the code is repeated';
                }
                $this->pages[$code] = true;
            }
            $this->requireText($page, 'title', 191, $where);
            if (!empty($page['home'])) {
                ++$homes;
            }
            $this->blocks($page, $where);
        }
        if ($homes > 1) {
            $this->problems[] = 'pages.json: only one page can be the home page («home»: true)';
        }
    }

    private function posts(): void
    {
        foreach ($this->list('posts.json') as $i => $post) {
            $where = 'posts.json: post #' . ($i + 1) . $this->label($post, 'code');
            if (!$this->isMap($post)) {
                $this->problems[] = $where . ': it must be an object';
                continue;
            }
            $code = $this->code($post, 'code', $where);
            if ($code !== null) {
                if (isset($this->posts[$code])) {
                    $this->problems[] = $where . ': the code is repeated';
                }
                $this->posts[$code] = true;
            }
            $this->requireText($post, 'title', 191, $where);
            if (isset($post['date']) && (!is_string($post['date']) || preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $post['date']) !== 1)) {
                $this->problems[] = $where . ': «date» must look like 2026-11-12 or 2026-11-12 09:30:00';
            }
            $this->image($post, 'image', $where);
            $this->blocks($post, $where);
        }
    }

    /**
     * @param array<string,mixed> $item
     */
    private function blocks(array $item, string $where): void
    {
        if (!isset($item['blocks']) || !is_array($item['blocks']) || !array_is_list($item['blocks'])) {
            $this->problems[] = $where . ': «blocks» must be a list of blocks';

            return;
        }
        try {
            $resolved = BlockRefs::resolve($item['blocks'], function (string $kind, string $code): ?int {
                if ($kind === 'category') {
                    return array_key_exists($code, $this->categories) ? 1 : null;
                }
                if ($kind === 'product') {
                    return isset($this->skus[$code]) ? 1 : null;
                }

                return $this->pkg->mediaPath($code) !== null ? 1 : null;
            });
        } catch (\InvalidArgumentException $e) {
            $this->problems[] = $where . ': ' . $e->getMessage();

            return;
        }
        foreach (BlockSchema::errors($resolved) as $error) {
            $this->problems[] = $where . ': ' . $error;
        }
    }

    private function menus(): void
    {
        $codes = [];
        foreach ($this->list('menus.json') as $i => $menu) {
            $where = 'menus.json: menu #' . ($i + 1) . $this->label($menu, 'code');
            if (!$this->isMap($menu)) {
                $this->problems[] = $where . ': it must be an object';
                continue;
            }
            $code = isset($menu['code']) && is_string($menu['code']) ? $menu['code'] : '';
            if (preg_match('/^[a-z][a-z0-9_-]{0,39}$/', $code) !== 1) {
                $this->problems[] = $where . ': the code is required (the place of the theme where it goes, for example «main»)';
            } elseif (isset($codes[$code])) {
                $this->problems[] = $where . ': the code is repeated';
            }
            $codes[$code] = true;
            $this->requireText($menu, 'name', 120, $where);
            $items = isset($menu['items']) && is_array($menu['items']) ? array_values($menu['items']) : null;
            if ($items === null) {
                $this->problems[] = $where . ': «items» must be a list';
                continue;
            }
            foreach ($items as $n => $item) {
                $this->menuItem($item, $where . ': item #' . ($n + 1), true);
            }
        }
    }

    /**
     * @param mixed $item
     */
    private function menuItem($item, string $where, bool $top): void
    {
        if (!is_array($item)) {
            $this->problems[] = $where . ' must be an object';

            return;
        }
        $type = isset($item['type']) && is_string($item['type']) ? $item['type'] : '';
        if (!in_array($type, self::MENU_TYPES, true)) {
            $this->problems[] = $where . ': «type» must be one of ' . implode(', ', self::MENU_TYPES);

            return;
        }
        if (!isset($item['label']) || !is_string($item['label']) || trim($item['label']) === '') {
            $this->problems[] = $where . ': «label» is required';
        }
        $ref = isset($item['ref']) && is_string($item['ref']) ? $item['ref'] : '';
        $known = ['page' => $this->pages, 'post' => $this->posts, 'product' => $this->skus, 'category' => $this->categories];
        if (isset($known[$type]) && ($ref === '' || !array_key_exists($ref, $known[$type]))) {
            $this->problems[] = $where . ': the ' . $type . ' «' . $ref . '» does not exist in the package';
        }
        if ($type === 'url' && (!isset($item['url']) || !is_string($item['url']) || trim($item['url']) === '')) {
            $this->problems[] = $where . ': an address item needs «url»';
        }
        if (isset($item['children'])) {
            if (!$top || !is_array($item['children']) || !array_is_list($item['children'])) {
                $this->problems[] = $where . ': «children» is only allowed on a first-level item and must be a list';

                return;
            }
            foreach (array_values($item['children']) as $n => $child) {
                $this->menuItem($child, $where . ' child #' . ($n + 1), false);
            }
        }
    }

    // ------------------------------------------------------------------ comercio

    private function carriers(): void
    {
        if (!$this->pkg->has('carriers.json')) {
            return;
        }
        $data = $this->pkg->data('carriers.json');
        if ($data !== [] && array_is_list($data)) {
            $this->problems[] = 'carriers.json: it must be an object with «carriers» (and optionally «zones»)';

            return;
        }
        $zones = self::SEEDED_ZONES;
        foreach (isset($data['zones']) && is_array($data['zones']) ? array_values($data['zones']) : [] as $i => $zone) {
            $where = 'carriers.json: zone #' . ($i + 1) . $this->label($zone, 'name');
            if (!is_array($zone) || !isset($zone['name']) || !is_string($zone['name']) || trim($zone['name']) === '') {
                $this->problems[] = $where . ': «name» is required';
                continue;
            }
            $zones[] = $zone['name'];
            $locations = isset($zone['locations']) && is_array($zone['locations']) ? $zone['locations'] : [];
            if ($locations === []) {
                $this->problems[] = $where . ': «locations» needs at least one {country, region, postcodes}';
            }
            foreach ($locations as $location) {
                if (!is_array($location) || !isset($location['country']) || !is_string($location['country']) || preg_match('/^([A-Z]{2}|\*)$/', $location['country']) !== 1) {
                    $this->problems[] = $where . ': every location needs a two-letter «country» (or «*»)';
                }
            }
        }
        $carriers = isset($data['carriers']) && is_array($data['carriers']) ? array_values($data['carriers']) : [];
        if ($carriers === []) {
            $this->problems[] = 'carriers.json: «carriers» must list at least one carrier';
        }
        $codes = [];
        foreach ($carriers as $i => $carrier) {
            $where = 'carriers.json: carrier #' . ($i + 1) . $this->label($carrier, 'code');
            if (!$this->isMap($carrier)) {
                $this->problems[] = $where . ': it must be an object';
                continue;
            }
            $code = $this->code($carrier, 'code', $where);
            if ($code !== null) {
                if (isset($codes[$code])) {
                    $this->problems[] = $where . ': the code is repeated';
                }
                $codes[$code] = true;
            }
            $this->requireText($carrier, 'name', 120, $where);
            $handler = isset($carrier['handler']) && is_string($carrier['handler']) ? $carrier['handler'] : 'rates';
            if (!in_array($handler, ['rates', 'pickup'], true)) {
                $this->problems[] = $where . ': «handler» must be rates or pickup';
            }
            foreach (['free_from', 'max_weight', 'min_days', 'max_days'] as $key) {
                if (isset($carrier[$key]) && (!is_int($carrier[$key]) || $carrier[$key] < 0)) {
                    $this->problems[] = $where . ': «' . $key . '» must be a whole number, zero or more';
                }
            }
            $rates = isset($carrier['rates']) && is_array($carrier['rates']) ? array_values($carrier['rates']) : [];
            if ($handler === 'rates' && $rates === []) {
                $this->problems[] = $where . ': a carrier with rates needs at least one rate';
            }
            foreach ($rates as $n => $rate) {
                $rwhere = $where . ': rate #' . ($n + 1);
                if (!is_array($rate) || !isset($rate['zone']) || !is_string($rate['zone']) || ($rate['zone'] !== '*' && !in_array($rate['zone'], $zones, true))) {
                    $this->problems[] = $rwhere . ': «zone» must be a zone of the package, one of the standard zones, or «*»';
                    continue;
                }
                if (!isset($rate['basis']) || !in_array($rate['basis'], ['weight', 'price'], true)) {
                    $this->problems[] = $rwhere . ': «basis» must be weight or price';
                }
                foreach (['min', 'max', 'price'] as $key) {
                    if (!isset($rate[$key]) || !is_int($rate[$key]) || $rate[$key] < 0) {
                        $this->problems[] = $rwhere . ': «' . $key . '» must be a whole number, zero or more';
                    }
                }
            }
        }
    }

    private function coupons(): void
    {
        $codes = [];
        foreach ($this->list('coupons.json') as $i => $coupon) {
            $where = 'coupons.json: coupon #' . ($i + 1) . $this->label($coupon, 'code');
            if (!$this->isMap($coupon)) {
                $this->problems[] = $where . ': it must be an object';
                continue;
            }
            $code = isset($coupon['code']) && is_string($coupon['code']) ? $coupon['code'] : '';
            if (preg_match('/^[A-Z0-9][A-Z0-9_-]{1,39}$/', $code) !== 1) {
                $this->problems[] = $where . ': «code» is required (capital letters, digits, hyphens)';
            } elseif (isset($codes[$code])) {
                $this->problems[] = $where . ': the code is repeated';
            }
            $codes[$code] = true;
            $kind = isset($coupon['kind']) && is_string($coupon['kind']) ? $coupon['kind'] : '';
            if ($kind === 'percent') {
                if (!isset($coupon['percent']) || !is_int($coupon['percent']) || $coupon['percent'] < 1 || $coupon['percent'] > 10000) {
                    $this->problems[] = $where . ': «percent» is required, in hundredths of a point (1000 = 10 %)';
                }
            } elseif ($kind === 'fixed') {
                if (!isset($coupon['amount']) || !is_int($coupon['amount']) || $coupon['amount'] < 1) {
                    $this->problems[] = $where . ': «amount» is required, in cents';
                }
            } else {
                $this->problems[] = $where . ': «kind» must be percent or fixed';
            }
        }
    }

    private function reviews(): void
    {
        foreach ($this->list('reviews.json') as $i => $review) {
            $where = 'reviews.json: review #' . ($i + 1);
            if (!$this->isMap($review)) {
                $this->problems[] = $where . ': it must be an object';
                continue;
            }
            if (!isset($review['sku']) || !is_string($review['sku']) || !isset($this->skus[$review['sku']])) {
                $this->problems[] = $where . ': the product «' . (isset($review['sku']) && is_string($review['sku']) ? $review['sku'] : '?') . '» is not in products.json';
            }
            $this->requireText($review, 'name', 120, $where);
            if (!isset($review['rating']) || !is_int($review['rating']) || $review['rating'] < 1 || $review['rating'] > 5) {
                $this->problems[] = $where . ': «rating» must be a whole number from 1 to 5';
            }
            $this->requireText($review, 'text', 2000, $where);
        }
    }

    // ------------------------------------------------------------------ utilidades

    /**
     * @return array<int,mixed>
     */
    private function list(string $file): array
    {
        if (!$this->pkg->has($file)) {
            return [];
        }
        $data = $this->pkg->data($file);
        if ($data !== [] && !array_is_list($data)) {
            $this->problems[] = $file . ': it must be a JSON list';

            return [];
        }

        return $data;
    }

    /**
     * @param mixed $value
     */
    private function isMap($value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }

    /**
     * Para el título de un problema: « («código»)» si el elemento lo tiene.
     *
     * @param mixed $item
     */
    private function label($item, string $key): string
    {
        return is_array($item) && isset($item[$key]) && is_string($item[$key]) && $item[$key] !== '' ? ' («' . mb_substr($item[$key], 0, 60) . '»)' : '';
    }

    /**
     * @param array<string,mixed> $item
     */
    private function code(array $item, string $key, string $where): ?string
    {
        $code = isset($item[$key]) && is_string($item[$key]) ? $item[$key] : '';
        if (preg_match(self::CODE, $code) !== 1) {
            $this->problems[] = $where . ': «' . $key . '» is required (lowercase letters, digits and hyphens)';

            return null;
        }

        return $code;
    }

    /**
     * @param array<string,mixed> $item
     */
    private function requireText(array $item, string $key, int $max, string $where): void
    {
        if (!isset($item[$key]) || !is_string($item[$key]) || trim($item[$key]) === '' || mb_strlen($item[$key]) > $max) {
            $this->problems[] = $where . ': «' . $key . '» is required (' . $max . ' characters at most)';
        }
    }

    /**
     * @param array<string,mixed> $item
     */
    private function image(array $item, string $key, string $where): void
    {
        if (isset($item[$key]) && $item[$key] !== null && (!is_string($item[$key]) || $this->pkg->mediaPath($item[$key]) === null)) {
            $this->problems[] = $where . ': the image «' . (is_string($item[$key]) ? $item[$key] : '?') . '» does not exist in media/';
        }
    }

    /**
     * @param mixed $image una imagen de un producto: «fichero.svg» o {file, alt}
     */
    private function imageName($image): string
    {
        if (is_string($image)) {
            return $image;
        }

        return is_array($image) && isset($image['file']) && is_string($image['file']) ? $image['file'] : '';
    }

    /**
     * @param mixed $gtin
     */
    private function gtinOk($gtin): bool
    {
        if (!is_string($gtin) || preg_match('/^\d{13}$/', $gtin) !== 1 || strpos($gtin, Package::GTIN_PREFIX) !== 0) {
            return false;
        }
        $sum = 0;
        for ($i = 0; $i < 12; ++$i) {
            $sum += (int) $gtin[$i] * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10 === (int) $gtin[12];
    }
}
