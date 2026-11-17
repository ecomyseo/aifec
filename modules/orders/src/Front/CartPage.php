<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Front;

use Aifec\Kernel\App;
use Aifec\Modules\Orders\CartView;

/**
 * Lo que necesita la plantilla orders::front/cart además del carrito: líneas ya formateadas, desglose, la estimación del
 * envío y si el botón de finalizar la compra se puede pulsar.
 */
final class CartPage
{
    /**
     * @param array<string,mixed> $cart Cart::summary()
     *
     * @return array<string,mixed>
     */
    public static function data(App $app, array $cart): array
    {
        $lines = [];
        foreach ($cart['lines'] as $line) {
            $lines[] = CartView::line($app, $line, $cart);
        }
        $destination = is_array($cart['destination']) ? $cart['destination'] : [];
        $known = isset($destination['postcode']) && (string) $destination['postcode'] !== '';
        $shipping = $app->has('shipping') ? $app->service('shipping') : null;
        $estimate = !empty($cart['needs_shipping']) && $shipping !== null && $shipping->estimateInCart();

        $options = [];
        if ($estimate && $known) {
            $currency = (string) $cart['currency'];
            foreach ($cart['shipping_options'] as $option) {
                $price = TotalsView::optionPrice($app, $cart, $option);
                $options[] = [
                    'name' => (string) $option['name'],
                    'delay' => isset($option['delay']) ? (string) $option['delay'] : '',
                    'price' => $price['free'] ? t('Free') : $app->i18n()->money($price['cents'], $currency),
                    'selected' => (int) $option['carrier_id'] === (int) $cart['carrier_id'],
                ];
            }
        }

        return [
            'lines' => $lines,
            'view' => TotalsView::forCartPage($app, $cart),
            'estimate' => $estimate,
            'estimated' => $estimate && $known,
            'countries' => $estimate ? Destinations::countries($app, true) : [],
            'destination' => $destination,
            'options' => $options,
            'blocked' => $cart['problems'] !== [] || (int) $cart['count'] < 1,
            'coupons_enabled' => $app->has('coupons'),
            'checkout_url' => route('checkout'),
            'shop_url' => url('/'),
        ];
    }
}
