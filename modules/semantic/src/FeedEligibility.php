<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Semantic;

use Aifec\Kernel\App;

/**
 * Qué productos entran en los feeds de producto (ACP, Google Merchant) y por qué los demás no. Es la única regla: los generadores
 * preguntan aquí, y el diagnóstico, llms.txt y /.well-known/aifec.json cuentan con ella.
 *
 *   price_hidden     el producto no enseña su precio
 *   no_image         no tiene ninguna imagen (Google y ACP la exigen)
 *   not_purchasable  ninguna de sus variantes se puede comprar
 *   free             cuesta 0: Google y ACP piden un precio mayor que cero. Entra solo con «semantic.feeds.include_free».
 */
final class FeedEligibility
{
    public const REASONS = ['price_hidden', 'no_image', 'not_purchasable', 'free'];

    /** Segundos que se guarda el recuento. */
    private const TTL = 1800;

    /** ¿Entran en los feeds los productos gratuitos? De serie no: lo decide la tienda. */
    public static function includeFree(App $app): bool
    {
        return $app->settings()->bool('semantic.feeds.include_free', false);
    }

    /** ¿Vale este importe (con impuestos, en céntimos) para un feed? */
    public static function priceAllowed(App $app, int $amount): bool
    {
        return $amount > 0 || ($amount === 0 && self::includeFree($app));
    }

    /**
     * Por qué un producto no entra en los feeds, o '' si entra.
     *
     * @param object              $catalog  el servicio «catalog»
     * @param array<string,mixed> $product
     */
    public static function reason(App $app, Semantic $semantic, $catalog, array $product): string
    {
        if (empty($product['availability']['show_price'])) {
            return 'price_hidden';
        }
        if ($semantic->images($product, 1) === []) {
            return 'no_image';
        }
        $variants = !empty($product['variants']) && is_array($product['variants']) ? $product['variants'] : [['id' => 0]];
        $purchasable = false;
        foreach ($variants as $variant) {
            $offer = $catalog->purchasable((int) $product['id'], (int) $variant['id']);
            if ($offer === null) {
                continue;
            }
            $purchasable = true;
            if (self::priceAllowed($app, $semantic->gross($catalog->price($offer)))) {
                return '';
            }
        }

        return $purchasable ? 'free' : 'not_purchasable';
    }

    /**
     * El recuento de todo el catálogo publicado: total, los que entran en los feeds y los que no con su motivo.
     *
     * @return array{total:int,eligible:int,excluded:array<int,array{id:int,name:string,reason:string}>}
     */
    public static function report(App $app): array
    {
        if (!$app->has('catalog')) {
            return ['total' => 0, 'eligible' => 0, 'excluded' => []];
        }

        return $app->cache()->remember('semantic:feed-eligibility:' . (self::includeFree($app) ? 'free' : 'paid'), self::TTL, static function () use ($app): array {
            $catalog = $app->service('catalog');
            $semantic = new Semantic($app);
            $total = 0;
            $excluded = [];
            $page = 1;
            do {
                $listing = $catalog->listing(['sort' => 'newest', 'page' => $page, 'per_page' => 96]);
                $items = isset($listing['items']) && is_array($listing['items']) ? $listing['items'] : [];
                $ids = array_map('intval', array_column($items, 'id'));
                foreach ($catalog->products($ids) as $product) {
                    ++$total;
                    $reason = self::reason($app, $semantic, $catalog, $product);
                    if ($reason !== '') {
                        $excluded[] = ['id' => (int) $product['id'], 'name' => (string) $product['name'], 'reason' => $reason];
                    }
                }
            } while ($page++ < (int) ($listing['pages'] ?? 1));

            return ['total' => $total, 'eligible' => $total - count($excluded), 'excluded' => $excluded];
        }, ['catalog', 'semantic', 'settings']);
    }

    /**
     * El motivo, con palabras.
     */
    public static function label(string $reason): string
    {
        switch ($reason) {
            case 'price_hidden':
                return t('the product does not show its price');
            case 'no_image':
                return t('it has no image');
            case 'free':
                return t('it is free (the feeds ask for a price above zero)');
        }

        return t('it cannot be bought (out of stock or unavailable)');
    }
}
