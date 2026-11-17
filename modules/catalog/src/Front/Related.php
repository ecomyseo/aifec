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

use Aifec\Kernel\App;
use Aifec\Modules\Catalog\Catalog;

/**
 * «También te puede interesar»: hasta cuatro tarjetas al final de la ficha y debajo del carrito.
 *
 * Los relacionados que el comerciante eligió a mano mandan; sin ellos, los de la misma categoría principal (los más
 * recientes primero) y, si el producto no tiene categoría, los más recientes de la tienda. En cualquier caso, nunca el
 * propio producto, y los que se pueden comprar van delante de los agotados. El ajuste catalog.related (encendido de serie)
 * lo apaga entero. Las tarjetas son las del catálogo (con su compra desde la tarjeta, P098); la selección ya hecha se guarda
 * en la caché del sitio (una entrada por producto) y la ficha, en la de página con la etiqueta de cada una y la de «catalog».
 */
final class Related
{
    /** Tarjetas que se enseñan como mucho. */
    public const LIMIT = 4;

    /** Candidatos que se guardan antes de ordenar (los agotados pueden ocupar los primeros puestos). */
    private const POOL = 12;

    public static function enabled(App $app): bool
    {
        return $app->settings()->bool('catalog.related', true);
    }

    /**
     * Las tarjetas relacionadas de un producto (ProductData).
     *
     * @param array<string,mixed> $product
     *
     * @return array<int,array<string,mixed>>
     */
    public static function cards(App $app, array $product, int $limit = self::LIMIT): array
    {
        if ($limit < 1 || !self::enabled($app) || !$app->has('catalog') || !isset($product['id'])) {
            return [];
        }
        /** @var Catalog $catalog */
        $catalog = $app->service('catalog');
        $self = (int) $product['id'];

        // Las tarjetas ya elegidas y ordenadas salen de UNA entrada de la caché del sitio por producto, idioma y número, sin
        // caducidad por tiempo: la vacían «catalog», la etiqueta del propio producto y la de cada candidato (un cambio de
        // existencias o de precio de cualquiera de ellos la rehace). Con ella hecha, la ficha no hace ninguna consulta por esto.
        $lang = $app->lang();
        $cache = $app->cache();
        $key = 'related:' . $self . ':' . $lang . ':' . $limit;
        $hit = $cache->get($key);
        if (is_array($hit)) {
            return $hit;
        }
        $ids = [];
        foreach (isset($product['related']) && is_array($product['related']) ? $product['related'] : [] as $id) {
            if ((int) $id > 0 && (int) $id !== $self && !in_array((int) $id, $ids, true)) {
                $ids[] = (int) $id;
            }
        }
        $ids = array_slice($ids, 0, self::POOL);
        if ($ids === []) {
            $query = ['sort' => 'newest', 'per_page' => self::POOL, 'lang' => $lang];
            $category = isset($product['main_category']['id']) ? (int) $product['main_category']['id'] : 0;
            if ($category > 0) {
                $query['category_id'] = $category;
            }
            $result = $catalog->listing($query);
            foreach (isset($result['items']) && is_array($result['items']) ? $result['items'] : [] as $card) {
                if (is_array($card) && isset($card['id'])) {
                    $ids[] = (int) $card['id'];
                }
            }
        }
        $cards = [];
        $loaded = $ids !== [] ? $catalog->cards($ids) : [];
        $tags = ['catalog', 'product:' . $self];
        foreach ($ids as $id) {
            $tags[] = 'product:' . $id;
            if (isset($loaded[$id])) {
                $cards[] = $loaded[$id];
            }
        }

        $buyable = [];
        $rest = [];
        foreach ($cards as $card) {
            if (!is_array($card) || !isset($card['id']) || (int) $card['id'] === $self) {
                continue;
            }
            if (self::buyable($card)) {
                $buyable[] = $card;
            } else {
                $rest[] = $card;
            }
        }

        $final = array_slice(array_merge($buyable, $rest), 0, $limit);
        $cache->set($key, $final, 0, array_values(array_unique($tags)));

        return $final;
    }

    /**
     * Las relacionadas de un producto por su identificador (las usa el carrito con su primera línea).
     *
     * @return array<int,array<string,mixed>>
     */
    public static function forId(App $app, int $productId, int $limit = self::LIMIT): array
    {
        if ($productId <= 0 || !self::enabled($app) || !$app->has('catalog')) {
            return [];
        }
        $product = $app->service('catalog')->product($productId);

        return is_array($product) ? self::cards($app, $product, $limit) : [];
    }

    /**
     * ¿Se puede comprar ya? Lo que dice su disponibilidad (un plugin puede haberlo dejado como «no se compra») y no agotado.
     *
     * @param array<string,mixed> $card
     */
    private static function buyable(array $card): bool
    {
        $availability = isset($card['availability']) && is_array($card['availability']) ? $card['availability'] : [];
        if (array_key_exists('purchasable', $availability) && empty($availability['purchasable'])) {
            return false;
        }

        return !isset($availability['status']) || (string) $availability['status'] !== 'out_of_stock';
    }
}
