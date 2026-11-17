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

/**
 * Los bloques de una página de un paquete no pueden llevar identificadores (cada tienda numera lo suyo): enlazan por
 * código. Esta clase cambia las referencias por los identificadores de la tienda donde se carga el paquete.
 *
 *   atributo del bloque            se convierte en
 *   category_code: «camisetas»     category_id: 7
 *   product_skus: [«A», «B»]       ids: [3, 4]
 *   media_file: «portada.svg»      media_id: 12
 *
 * También dentro de las listas de imágenes de una galería ({media_file, alt}).
 */
final class BlockRefs
{
    /**
     * @param array<int|string,mixed>      $blocks
     * @param callable(string,string):?int $lookup  (tipo: category|product|media, código) → identificador o null si no existe
     *
     * @throws \InvalidArgumentException con el motivo si una referencia no existe
     *
     * @return array<int|string,mixed>
     */
    public static function resolve(array $blocks, callable $lookup): array
    {
        $out = [];
        foreach ($blocks as $key => $block) {
            if (!is_array($block)) {
                $out[$key] = $block;
                continue;
            }
            if (isset($block['attrs']) && is_array($block['attrs'])) {
                $block['attrs'] = self::attrs($block['attrs'], $lookup);
            }
            if (isset($block['children']) && is_array($block['children'])) {
                $block['children'] = self::resolve($block['children'], $lookup);
            }
            $out[$key] = $block;
        }

        return $out;
    }

    /**
     * @param array<int|string,mixed>      $attrs
     * @param callable(string,string):?int $lookup
     *
     * @return array<int|string,mixed>
     */
    private static function attrs(array $attrs, callable $lookup): array
    {
        if (isset($attrs['category_code'])) {
            $attrs['category_id'] = self::find($lookup, 'category', (string) $attrs['category_code']);
            unset($attrs['category_code']);
        }
        if (isset($attrs['product_skus']) && is_array($attrs['product_skus'])) {
            $ids = [];
            foreach ($attrs['product_skus'] as $sku) {
                $ids[] = self::find($lookup, 'product', is_string($sku) ? $sku : '');
            }
            $attrs['ids'] = $ids;
            unset($attrs['product_skus']);
        }
        if (isset($attrs['media_file'])) {
            $attrs['media_id'] = self::find($lookup, 'media', (string) $attrs['media_file']);
            unset($attrs['media_file']);
        }
        if (isset($attrs['images']) && is_array($attrs['images'])) {
            $images = [];
            foreach ($attrs['images'] as $image) {
                if (is_array($image) && isset($image['media_file'])) {
                    $image['media_id'] = self::find($lookup, 'media', (string) $image['media_file']);
                    unset($image['media_file']);
                }
                $images[] = $image;
            }
            $attrs['images'] = $images;
        }

        return $attrs;
    }

    /**
     * @param callable(string,string):?int $lookup
     */
    private static function find(callable $lookup, string $kind, string $code): int
    {
        $id = $lookup($kind, $code);
        if ($id === null) {
            throw new \InvalidArgumentException('unknown ' . $kind . ' «' . $code . '» in a block');
        }

        return $id;
    }
}
