<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog\Front;

/**
 * Las variantes de una ficha: cuál va elegida de serie, cuál corresponde a unas opciones y qué datos lleva el JSON que lee
 * theme.js al cambiar de opción. Funciona con el ProductData del catálogo y no toca la base de datos.
 */
final class Variants
{
    /**
     * La variante que sale elegida de serie: la primera que se puede comprar y, si ninguna, la primera.
     *
     * @param array<string,mixed> $product ProductData
     *
     * @return array<string,mixed>|null
     */
    public static function initial(array $product): ?array
    {
        $variants = isset($product['variants']) && is_array($product['variants']) ? array_values($product['variants']) : [];
        foreach ($variants as $variant) {
            if (is_array($variant) && !self::soldOut($variant)) {
                return $variant;
            }
        }

        return isset($variants[0]) && is_array($variants[0]) ? $variants[0] : null;
    }

    /**
     * ¿Está agotada esta variante (o este producto: lo mismo vale para su disponibilidad)?
     *
     * @param array<string,mixed> $item una variante, o un ProductData, con su «availability»
     */
    public static function soldOut(array $item): bool
    {
        if (isset($item['availability']['purchasable']) && empty($item['availability']['purchasable'])) {
            return true;
        }

        return isset($item['availability']['status']) && $item['availability']['status'] === 'out_of_stock';
    }

    /**
     * La variante que corresponde a las opciones elegidas («opt[color]=rojo&opt[talla]=m»), o null si falta alguna opción
     * o no hay ninguna variante con esa combinación.
     *
     * @param array<string,mixed> $product ProductData
     * @param array<mixed,mixed>  $chosen  código de la opción => código del valor
     */
    public static function resolve(array $product, array $chosen): ?int
    {
        $variants = isset($product['variants']) && is_array($product['variants']) ? $product['variants'] : [];
        foreach ($variants as $variant) {
            if (!is_array($variant) || !isset($variant['values']) || !is_array($variant['values']) || $variant['values'] === []) {
                continue;
            }
            $match = true;
            foreach ($variant['values'] as $code => $value) {
                if (!isset($chosen[$code]) || !is_scalar($chosen[$code]) || (string) $chosen[$code] !== (string) $value) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return (int) $variant['id'];
            }
        }

        return null;
    }

    /**
     * Lo que lleva el JSON de la ficha: por variante, su identificador, referencia, valores, precio (en céntimos y ya
     * formateado, con el anterior), disponibilidad e imagen.
     *
     * @param array<string,mixed> $product ProductData
     *
     * @return array<string,mixed>
     */
    public static function data(array $product): array
    {
        $rows = [];
        $variants = isset($product['variants']) && is_array($product['variants']) ? $product['variants'] : [];
        foreach ($variants as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $price = isset($variant['price']) && is_array($variant['price']) ? $variant['price'] : [];
            $amount = isset($price['amount']) ? (int) $price['amount'] : 0;
            $compare = isset($price['compare']) && $price['compare'] !== null ? (int) $price['compare'] : null;
            $currency = isset($price['currency']) && is_string($price['currency']) && $price['currency'] !== '' ? $price['currency'] : null;
            $hidden = !empty($price['hidden']);
            $stock = isset($variant['availability']) && is_array($variant['availability']) ? $variant['availability'] : [];
            $rows[] = [
                'id' => (int) $variant['id'],
                'sku' => isset($variant['sku']) ? (string) $variant['sku'] : '',
                'label' => isset($variant['label']) ? (string) $variant['label'] : '',
                'values' => isset($variant['values']) && is_array($variant['values']) ? $variant['values'] : [],
                'price' => [
                    'amount' => $amount,
                    'hidden' => $hidden,
                    'formatted' => $hidden ? '' : money($amount, $currency),
                    'compare' => $compare,
                    'compare_formatted' => $compare !== null ? money($compare, $currency) : '',
                    'discount_percent' => isset($price['discount_percent']) && $price['discount_percent'] !== null ? (int) $price['discount_percent'] : null,
                    'with_tax' => !empty($price['with_tax']),
                ],
                'availability' => [
                    'status' => isset($stock['status']) ? (string) $stock['status'] : 'in_stock',
                    'label' => isset($stock['label']) ? (string) $stock['label'] : '',
                    'purchasable' => !isset($stock['purchasable']) || !empty($stock['purchasable']),
                ],
                'image_id' => isset($variant['image_id']) && $variant['image_id'] !== null ? (int) $variant['image_id'] : null,
            ];
        }

        return [
            'product_id' => isset($product['id']) ? (int) $product['id'] : 0,
            'name' => isset($product['name']) ? (string) $product['name'] : '',
            'variants' => $rows,
        ];
    }

    /**
     * Lo mismo para la TARJETA de un listado que deja elegir combinación («buy» del ProductCard): la lista de variantes con
     * la forma que ya entiende el guion del tema (id, values, price, availability) más lo que la tarjeta necesita y la ficha
     * no: la foto de la variante ya resuelta al tamaño de tarjeta («image»: src y srcset; null = la del producto) y cuántas
     * unidades se pueden pedir («max»; null = sin tope).
     *
     * @param array<string,mixed> $buy
     *
     * @return array<int,array<string,mixed>>
     */
    public static function cardData(array $buy): array
    {
        $rows = [];
        foreach (isset($buy['variants']) && is_array($buy['variants']) ? $buy['variants'] : [] as $variant) {
            if (!is_array($variant)) {
                continue;
            }
            $price = isset($variant['price']) && is_array($variant['price']) ? $variant['price'] : [];
            $image = isset($variant['image']) && is_array($variant['image']) && !empty($variant['image']['url']) ? $variant['image'] : null;
            $can = !empty($variant['purchasable']);
            $rows[] = [
                'id' => (int) $variant['id'],
                'sku' => '',
                'label' => isset($variant['label']) ? (string) $variant['label'] : '',
                'values' => isset($variant['values']) && is_array($variant['values']) ? $variant['values'] : [],
                'price' => !empty($price['hidden']) ? null : [
                    'amount' => isset($price['amount']) ? (int) $price['amount'] : 0,
                    'compare' => isset($price['compare']) && $price['compare'] !== null ? (int) $price['compare'] : null,
                    'discount_percent' => isset($price['discount_percent']) && $price['discount_percent'] !== null ? (int) $price['discount_percent'] : null,
                ],
                'availability' => [
                    'status' => $can ? 'in_stock' : 'out_of_stock',
                    'label' => isset($variant['stock_label']) ? (string) $variant['stock_label'] : '',
                ],
                'max' => isset($variant['max_qty']) && $variant['max_qty'] !== null && (int) $variant['max_qty'] > 0 ? (int) $variant['max_qty'] : null,
                'image' => $image === null ? null : [
                    'src' => !empty($image['card']) ? (string) $image['card'] : (string) $image['url'],
                    'srcset' => !empty($image['srcset']) ? (string) $image['srcset'] : '',
                ],
            ];
        }

        return $rows;
    }

    /**
     * La hoja de la compra desde la tarjeta, lista para ir dentro del <head>: el contenido de assets/card-options.css sin su
     * comentario, en una etiqueta <style>. Es fija (no lleva nada de quien mira ni de la tienda).
     */
    public static function cardStyle(): string
    {
        static $style = null;
        if ($style === null) {
            $css = (string) @file_get_contents(dirname(__DIR__, 2) . '/assets/card-options.css');
            $css = trim((string) preg_replace('#/\*.*?\*/#s', '', $css));
            // Va dentro de <style>: nada que pueda cerrarlo.
            $style = $css === '' || stripos($css, '</style') !== false ? '' : '<style data-af-co-style>' . str_replace(["\r\n", "\n"], '', $css) . '</style>';
        }

        return $style;
    }

    /**
     * La variante de la lista con ese identificador (la que pide la dirección «?variante=12» que pone la tarjeta del listado),
     * o null si no es de este producto.
     *
     * @param array<string,mixed> $product ProductData
     *
     * @return array<string,mixed>|null
     */
    public static function byId(array $product, int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }
        foreach (isset($product['variants']) && is_array($product['variants']) ? $product['variants'] : [] as $variant) {
            if (is_array($variant) && isset($variant['id']) && (int) $variant['id'] === $id) {
                return $variant;
            }
        }

        return null;
    }
}
