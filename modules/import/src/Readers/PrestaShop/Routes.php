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

/**
 * Las direcciones antiguas de una tienda de PrestaShop. Se arman igual que el Dispatcher del núcleo: la regla de la
 * configuración («PS_ROUTE_product_rule») o la de serie, con sus palabras clave («{category:/}», «{-:ean13}», «{id}»…), una
 * por idioma y con el prefijo del idioma cuando hay más de uno activo.
 */
final class Routes
{
    /** Reglas de serie de Dispatcher::$default_routes (1.7 y 8.x). */
    private const DEFAULTS = [
        'product_rule' => '{category:/}{id}{-:id_product_attribute}-{rewrite}{-:ean13}.html',
        'category_rule' => '{id}-{rewrite}',
        'cms_rule' => 'content/{id}-{rewrite}',
        'manufacturer_rule' => 'brand/{id}-{rewrite}',
    ];

    /** La regla de producto de serie de PrestaShop 9: sin categoría delante ni código de barras detrás. */
    private const PRODUCT_9 = '{id}{-:id_product_attribute}-{rewrite}.html';

    /** @var array<string,string> letras con tilde a su letra sin ella (como hace Tools::str2url) */
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a', 'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e', 'í' => 'i', 'ì' => 'i',
        'î' => 'i', 'ï' => 'i', 'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'ç' => 'c', 'ß' => 'ss', 'æ' => 'ae', 'œ' => 'oe', 'ø' => 'o',
    ];

    /** La regla que usa la tienda para un tipo de página. */
    public static function rule(Env $env, string $route): string
    {
        $custom = trim($env->cfg('PS_ROUTE_' . $route));
        if ($custom !== '') {
            return $custom;
        }
        if ($route === 'product_rule' && self::major($env->version) >= 9) {
            return self::PRODUCT_9;
        }

        return isset(self::DEFAULTS[$route]) ? self::DEFAULTS[$route] : '{id}-{rewrite}';
    }

    /** ¿La regla lleva esta palabra clave? */
    public static function hasKeyword(string $rule, string $keyword): bool
    {
        return preg_match('#\{([^{}]*:)?' . preg_quote($keyword, '#') . '(:[^{}]*)?\}#', $rule) === 1;
    }

    /**
     * Las direcciones de una página, una por idioma (la del idioma de la tienda primero).
     *
     * @param array<string,mixed> $params palabra clave => texto, o [id de idioma => texto] si cambia con el idioma
     *
     * @return string[]
     */
    public static function paths(Env $env, string $route, array $params): array
    {
        $rule = self::rule($env, $route);
        $basePath = (string) parse_url($env->baseUrl, PHP_URL_PATH);
        $basePath = rtrim($basePath, '/');
        $paths = [];
        foreach ($env->orderedLangs() as $langId => $iso) {
            $url = $rule;
            foreach ($params as $key => $value) {
                if (is_array($value)) {
                    $value = isset($value[$langId]) ? $value[$langId] : (isset($value[$env->defaultLang]) ? $value[$env->defaultLang] : '');
                }
                $value = (string) $value;
                $url = (string) preg_replace_callback(
                    '#\{(([^{}]*):)?' . preg_quote((string) $key, '#') . '(:([^{}]*))?\}#',
                    static function (array $m) use ($value): string {
                        if ($value === '' || $value === '0') {
                            return '';
                        }

                        return (isset($m[2]) ? $m[2] : '') . $value . (isset($m[4]) ? $m[4] : '');
                    },
                    $url
                );
            }
            $url = (string) preg_replace('#\{([^{}]*:)?[a-z0-9_]+?(:[^{}]*)?\}#', '', $url);
            if ($url === '') {
                continue;
            }
            $path = $basePath . '/' . ($env->multiLang() ? $iso . '/' : '') . ltrim($url, '/');
            if (!in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Las palabras clave de la dirección de un producto.
     *
     * @param array<string,mixed> $product una fila de producto con sus textos por idioma
     *
     * @return array<string,mixed>
     */
    public static function productParams(Env $env, array $product): array
    {
        $rule = self::rule($env, 'product_rule');
        $categoryId = (int) (isset($product['id_category_default']) ? $product['id_category_default'] : 0);
        $params = [
            'id' => (string) $product['id'],
            'rewrite' => isset($product['link_rewrite']) && is_array($product['link_rewrite']) ? $product['link_rewrite'] : [],
            'ean13' => isset($product['ean13']) ? (string) $product['ean13'] : '',
            'id_product_attribute' => '',
        ];
        $categories = $env->lookup('categories');
        if (self::hasKeyword($rule, 'category')) {
            $params['category'] = self::perLang($env, static function (int $lang) use ($categories, $categoryId): string {
                return isset($categories[$categoryId]['rewrite'][$lang]) ? (string) $categories[$categoryId]['rewrite'][$lang] : '';
            });
        }
        if (self::hasKeyword($rule, 'categories')) {
            $params['categories'] = self::perLang($env, static function (int $lang) use ($categories, $categoryId, $env): string {
                $chain = [];
                $seen = [];
                $id = $categoryId;
                while ($id > 0 && isset($categories[$id]) && !isset($seen[$id])) {
                    $seen[$id] = true;
                    if (!$categories[$id]['root'] && $id !== $env->cfgInt('PS_HOME_CATEGORY') && $id !== $env->cfgInt('PS_ROOT_CATEGORY')) {
                        array_unshift($chain, isset($categories[$id]['rewrite'][$lang]) ? (string) $categories[$id]['rewrite'][$lang] : '');
                    }
                    $id = (int) $categories[$id]['parent'];
                }

                return implode('/', array_filter($chain, static function (string $part): bool {
                    return $part !== '';
                }));
            });
        }
        if (self::hasKeyword($rule, 'reference')) {
            $params['reference'] = self::str2url(isset($product['reference']) ? (string) $product['reference'] : '');
        }
        foreach (['meta_keywords', 'meta_title'] as $key) {
            if (self::hasKeyword($rule, $key)) {
                $source = isset($product[$key]) && is_array($product[$key]) ? $product[$key] : [];
                $params[$key] = self::perLang($env, static function (int $lang) use ($source): string {
                    return self::str2url(isset($source[$lang]) ? (string) $source[$lang] : '');
                });
            }
        }
        if (self::hasKeyword($rule, 'manufacturer')) {
            $names = $env->lookup('manufacturers');
            $id = (int) (isset($product['id_manufacturer']) ? $product['id_manufacturer'] : 0);
            $params['manufacturer'] = self::str2url(isset($names[$id]) ? (string) $names[$id] : '');
        }
        if (self::hasKeyword($rule, 'supplier')) {
            $names = $env->lookup('suppliers');
            $id = (int) (isset($product['id_supplier']) ? $product['id_supplier'] : 0);
            $params['supplier'] = self::str2url(isset($names[$id]) ? (string) $names[$id] : '');
        }
        if (self::hasKeyword($rule, 'price')) {
            $params['price'] = isset($product['price']) ? (string) $product['price'] : '';
        }

        return $params;
    }

    /**
     * @return array<int,string>
     */
    private static function perLang(Env $env, callable $value): array
    {
        $out = [];
        foreach ($env->orderedLangs() as $id => $iso) {
            $out[$id] = $value($id);
        }

        return $out;
    }

    /** Como Tools::str2url: sin tildes, en minúsculas y con guiones en lugar de lo que no sea una letra o una cifra. */
    public static function str2url(string $text): string
    {
        $text = strtr(mb_strtolower(trim($text), 'UTF-8'), self::ACCENTS);
        $text = (string) preg_replace('/[^a-z0-9]+/', '-', $text);

        return trim($text, '-');
    }

    private static function major(string $version): int
    {
        return (int) $version;
    }
}
