<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Catalog;

use Aifec\Kernel\App;

/**
 * Las imágenes con la forma que el contrato de comercio fija: ['id','url','alt','width','height','thumb','card','large'].
 * Se piden al servicio «media»; si ese módulo no está, no hay imágenes.
 */
final class Images
{
    /**
     * Deja de una imagen del servicio «media» solo las claves del contrato.
     *
     * @param array<string,mixed> $image      lo que devuelve media->image() o media->images()
     * @param string              $fallbackAlt texto alternativo si la imagen no tiene
     *
     * @return array<string,mixed>
     */
    public static function shape(array $image, string $fallbackAlt = ''): array
    {
        return [
            'id' => (int) $image['id'],
            'url' => (string) $image['url'],
            'alt' => isset($image['alt']) && $image['alt'] !== '' ? (string) $image['alt'] : $fallbackAlt,
            'width' => isset($image['width']) ? $image['width'] : null,
            'height' => isset($image['height']) ? $image['height'] : null,
            'thumb' => (string) $image['thumb'],
            'card' => (string) $image['card'],
            'large' => (string) $image['large'],
        ];
    }

    /**
     * Varias imágenes con una sola consulta: id de la imagen => imagen. Vacío si no hay servicio «media».
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    public static function many(App $app, array $ids): array
    {
        if ($ids === [] || !$app->has('media')) {
            return [];
        }
        $media = $app->service('media');
        $images = is_callable([$media, 'images']) ? $media->images($ids) : [];
        $out = [];
        foreach (is_array($images) ? $images : [] as $id => $image) {
            $out[(int) $id] = self::shape($image);
        }

        return $out;
    }
}
