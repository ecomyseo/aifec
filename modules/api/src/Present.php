<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Api;

use Aifec\Kernel\App;

/**
 * Cómo salen los datos por la API: importes en céntimos y, al lado, ya formateados; direcciones completas, con el dominio de la
 * tienda; y las fichas de producto con los campos públicos de ProductData.
 */
final class Present
{
    /**
     * Deja los importes como los da la API: «price» en céntimos (entero) y «price_formatted» con el texto.
     *
     * Las herramientas devuelven cada importe dos veces: «price» con el texto y «price_cents» con los céntimos. Aquí se juntan:
     * «price» pasa a ser el entero, el texto va a «price_formatted» y «price_cents» desaparece. Un «…_cents» sin texto al lado
     * («items_total_cents») pasa a llamarse como los demás importes («items_total»), también en céntimos.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    public static function amounts($value)
    {
        if (!is_array($value)) {
            return $value;
        }
        $out = [];
        foreach ($value as $key => $item) {
            $value[$key] = self::amounts($item);
        }
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                $out[$key] = $item;
                continue;
            }
            if (substr($key, -6) === '_cents') {
                $base = substr($key, 0, -6);
                if ($base !== '' && array_key_exists($base, $value) && self::pairs($value[$base], $item)) {
                    continue;
                }
                // Sin texto al lado, el importe sigue siendo en céntimos pero con el nombre de siempre: «items_total».
                if ($base !== '' && !array_key_exists($base, $value) && (is_int($item) || $item === null)) {
                    $out[$base] = $item;
                    continue;
                }
                $out[$key] = $item;
                continue;
            }
            if (array_key_exists($key . '_cents', $value) && self::pairs($item, $value[$key . '_cents'])) {
                $out[$key] = $value[$key . '_cents'];
                $out[$key . '_formatted'] = $item;
                continue;
            }
            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * ¿Son un texto y los céntimos de ese mismo importe?
     *
     * @param mixed $text
     * @param mixed $cents
     */
    private static function pairs($text, $cents): bool
    {
        return (is_string($text) && is_int($cents)) || ($text === null && $cents === null);
    }

    /** Una dirección de la propia tienda con su dominio completo (las imágenes y los enlaces vienen relativos a la raíz). */
    public static function abs(App $app, string $url): string
    {
        if ($url === '') {
            return '';
        }
        if ($url[0] === '/' && substr($url, 0, 2) !== '//') {
            return $app->url()->origin() . $url;
        }

        return $url;
    }

    /**
     * Una tarjeta de producto de un listado: los mismos campos que la herramienta search_products.
     *
     * @param array<string,mixed> $c una ProductCard
     *
     * @return array<string,mixed>
     */
    public static function card(App $app, array $c): array
    {
        $price = isset($c['price']) && is_array($c['price']) ? $c['price'] : [];
        $currency = isset($price['currency']) ? (string) $price['currency'] : self::currency($app);
        $amount = isset($price['amount']) ? (int) $price['amount'] : 0;
        $compare = isset($price['compare']) && $price['compare'] !== null ? (int) $price['compare'] : null;
        $image = isset($c['image']) && is_array($c['image']) ? $c['image'] : [];
        $imageUrl = isset($image['card']) ? (string) $image['card'] : (isset($image['url']) ? (string) $image['url'] : '');
        $brand = isset($c['brand']) ? $c['brand'] : '';
        if (is_array($brand)) {
            $brand = isset($brand['name']) ? $brand['name'] : '';
        }
        $availability = isset($c['availability']) && is_array($c['availability']) ? $c['availability'] : [];
        $money = $app->i18n();

        return [
            'id' => (int) $c['id'],
            'name' => (string) (isset($c['name']) ? $c['name'] : ''),
            'url' => self::abs($app, (string) (isset($c['url']) ? $c['url'] : '')),
            'image' => self::abs($app, $imageUrl),
            'price' => $amount,
            'price_formatted' => $money->money($amount, $currency),
            'currency' => $currency,
            'compare_price' => $compare,
            'compare_price_formatted' => $compare !== null ? $money->money($compare, $currency) : null,
            'from' => !empty($price['from']),
            'tax_included' => !empty($price['with_tax']),
            'price_detail' => \Aifec\Modules\Catalog\PriceDetail::of($app, $price),
            'availability' => [
                'status' => (string) (isset($availability['status']) ? $availability['status'] : ''),
                'label' => (string) (isset($availability['label']) ? $availability['label'] : ''),
            ],
            'brand' => (string) $brand,
            'has_variants' => !empty($c['has_variants']),
            'rating' => isset($c['rating']) && is_array($c['rating']) ? ['average' => (float) (isset($c['rating']['average']) ? $c['rating']['average'] : 0), 'count' => (int) (isset($c['rating']['count']) ? $c['rating']['count'] : 0)] : null,
        ];
    }

    /**
     * La ficha de un producto: lo que dice la herramienta get_product más los campos públicos de ProductData.
     *
     * @param array<string,mixed> $tool lo que devolvió get_product
     * @param array<string,mixed> $p    el ProductData del catálogo
     *
     * @return array<string,mixed>
     */
    public static function product(App $app, array $tool, array $p): array
    {
        $images = [];
        foreach (isset($p['images']) && is_array($p['images']) ? $p['images'] : [] as $image) {
            if (!is_array($image)) {
                continue;
            }
            $images[] = [
                'url' => self::abs($app, (string) (isset($image['large']) ? $image['large'] : (isset($image['url']) ? $image['url'] : ''))),
                'thumb' => self::abs($app, (string) (isset($image['thumb']) ? $image['thumb'] : '')),
                'alt' => (string) (isset($image['alt']) ? $image['alt'] : ''),
                'width' => (int) (isset($image['width']) ? $image['width'] : 0),
                'height' => (int) (isset($image['height']) ? $image['height'] : 0),
            ];
        }
        $categories = [];
        foreach (isset($p['categories']) && is_array($p['categories']) ? $p['categories'] : [] as $category) {
            if (is_array($category) && isset($category['id'], $category['name'])) {
                $categories[] = ['id' => (int) $category['id'], 'name' => (string) $category['name'], 'url' => self::abs($app, (string) (isset($category['url']) ? $category['url'] : ''))];
            }
        }
        $price = isset($p['price']) && is_array($p['price']) ? $p['price'] : [];
        $dimensions = isset($p['dimensions']) && is_array($p['dimensions']) ? $p['dimensions'] : [];
        $extra = [];
        foreach (isset($p['extra']) && is_array($p['extra']) ? $p['extra'] : [] as $key => $field) {
            if (is_array($field) && !empty($field['public'])) {
                $extra[(string) $key] = ['label' => (string) (isset($field['label']) ? $field['label'] : ''), 'value' => isset($field['value']) ? $field['value'] : null, 'type' => (string) (isset($field['type']) ? $field['type'] : 'text')];
            }
        }

        return array_merge($tool, [
            'categories' => $categories,
            'gtin' => isset($p['gtin']) && $p['gtin'] !== '' ? (string) $p['gtin'] : null,
            'mpn' => isset($p['mpn']) && $p['mpn'] !== '' ? (string) $p['mpn'] : null,
            'tags' => isset($p['tags']) && is_array($p['tags']) ? array_values(array_map('strval', $p['tags'])) : [],
            'images' => $images,
            'pricing' => [
                'with_tax' => !empty($price['with_tax']),
                'net' => (int) (isset($price['net']) ? $price['net'] : 0),
                'tax' => (int) (isset($price['tax']) ? $price['tax'] : 0),
                'gross' => (int) (isset($price['gross']) ? $price['gross'] : 0),
                'tax_rate' => (int) (isset($price['tax_rate']) ? $price['tax_rate'] : 0),
                'tax_name' => (string) (isset($price['tax_name']) ? $price['tax_name'] : ''),
                'detail' => \Aifec\Modules\Catalog\PriceDetail::of($app, $price),
                'on_sale' => !empty($price['on_sale']),
                'discount_percent' => isset($price['discount_percent']) && $price['discount_percent'] !== null ? (int) $price['discount_percent'] : null,
                'sale_until' => isset($price['sale_until']) && $price['sale_until'] !== null ? (string) $price['sale_until'] : null,
            ],
            'summary_html' => (string) (isset($p['summary']) ? $p['summary'] : ''),
            'description_html' => (string) (isset($p['description']) ? $p['description'] : ''),
            'weight' => (int) (isset($p['weight']) ? $p['weight'] : 0),
            'dimensions' => [
                'length' => (int) (isset($dimensions['length']) ? $dimensions['length'] : 0),
                'width' => (int) (isset($dimensions['width']) ? $dimensions['width'] : 0),
                'height' => (int) (isset($dimensions['height']) ? $dimensions['height'] : 0),
            ],
            'min_qty' => (int) (isset($p['min_qty']) ? $p['min_qty'] : 0),
            'max_qty' => (int) (isset($p['max_qty']) ? $p['max_qty'] : 0),
            'related' => isset($p['related']) && is_array($p['related']) ? array_values(array_map('intval', $p['related'])) : [],
            'seo' => isset($p['seo']) && is_array($p['seo']) ? ['title' => (string) (isset($p['seo']['title']) ? $p['seo']['title'] : ''), 'description' => (string) (isset($p['seo']['description']) ? $p['seo']['description'] : '')] : null,
            'published_at' => isset($p['published_at']) && $p['published_at'] !== null ? (string) $p['published_at'] : null,
            'updated_at' => isset($p['updated_at']) && $p['updated_at'] !== null ? (string) $p['updated_at'] : null,
            'extra' => $extra,
        ]);
    }

    /** La moneda de la tienda (tres letras). */
    public static function currency(App $app): string
    {
        $currency = strtoupper(trim($app->settings()->str('shop.currency', 'EUR')));

        return preg_match('/^[A-Z]{3}$/D', $currency) === 1 ? $currency : 'EUR';
    }
}
