<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Datalayer;

use Aifec\Commerce\Money;
use Aifec\Kernel\App;

/**
 * Arma los eventos de comercio de GA4 con sus nombres y parámetros oficiales a partir de los datos de AIFEC (ficha, carrito, caja,
 * pedido). Todo importe sale de céntimos enteros convertidos con Money::decimal(): el número que va al JSON es el decimal exacto
 * («19.99»), nunca el resultado de una cuenta con decimales. Con el ajuste datalayer.value_with_tax apagado (lo de serie, como
 * pide GA4) los importes van SIN impuestos y el impuesto va aparte en «tax».
 *
 * Los eventos de la ficha, el carrito y la caja salen iguales para todos los visitantes que ven esas páginas; el de compra solo se
 * arma en la página del pedido, que es privada y no se guarda en ninguna caché.
 */
final class EventBuilder
{
    /** @var App */
    private $app;

    /** @var Config */
    private $config;

    public function __construct(App $app, Config $config)
    {
        $this->app = $app;
        $this->config = $config;
    }

    /** Céntimos → número exacto para el JSON (viene de la cadena decimal, no de una división). */
    public static function num(int $cents): float
    {
        return (float) Money::decimal($cents);
    }

    // ------------------------------------------------------------------ ficha

    /**
     * view_item de la ficha de un producto (ProductData).
     *
     * @param array<string,mixed> $product
     *
     * @return array<string,mixed>
     */
    public function viewItem(array $product): array
    {
        $price = isset($product['price']) && is_array($product['price']) ? $product['price'] : [];
        $amount = $this->shown($price);
        $item = $this->item([
            'id' => (string) $product['id'],
            'name' => (string) $product['name'], 'variant' => '', 'amount' => $amount, 'qty' => 1, 'pid' => (int) $product['id'],
        ], $product);

        return [
            'event' => 'view_item',
            'ecommerce' => ['currency' => isset($price['currency']) ? (string) $price['currency'] : (string) $this->app->settings()->str('shop.currency', 'EUR'), 'value' => self::num($amount), 'items' => [$item]],
        ];
    }

    // ------------------------------------------------------------------ carrito y caja

    /**
     * view_cart: el carrito con precios (Cart::summary()). «keys» enlaza la clave de cada línea con su posición, para el evento
     * remove_from_cart que el navegador empuja al quitar una línea.
     *
     * @param array<string,mixed> $summary
     *
     * @return array<string,mixed>
     */
    public function viewCart(array $summary): array
    {
        $built = $this->cartItems($summary);

        return [
            'event' => 'view_cart',
            'ecommerce' => ['currency' => (string) $summary['currency'], 'value' => self::num($built['value']), 'items' => $built['items']],
            'keys' => $built['keys'],
        ];
    }

    /**
     * begin_checkout, con lo que hace falta para add_shipping_info y add_payment_info (las opciones por su identificador).
     *
     * @param array<string,mixed> $summary
     *
     * @return array<string,mixed>
     */
    public function beginCheckout(array $summary): array
    {
        $built = $this->cartItems($summary);
        $ecommerce = ['currency' => (string) $summary['currency'], 'value' => self::num($built['value']), 'items' => $built['items']];
        $coupon = $this->coupons($summary);
        if ($coupon !== '') {
            $ecommerce['coupon'] = $coupon;
        }
        $shipping = [];
        foreach (isset($summary['shipping_options']) && is_array($summary['shipping_options']) ? $summary['shipping_options'] : [] as $option) {
            $shipping[(string) $option['carrier_id']] = (string) $option['name'];
        }
        $payment = [];
        foreach (isset($summary['payment_methods']) && is_array($summary['payment_methods']) ? $summary['payment_methods'] : [] as $method) {
            $payment[(string) $method['code']] = (string) $method['title'];
        }

        return ['event' => 'begin_checkout', 'ecommerce' => $ecommerce, 'shipping' => $shipping, 'payment' => $payment];
    }

    /**
     * @param array<string,mixed> $summary
     *
     * @return array{items:array<int,array<string,mixed>>,keys:array<string,int>,value:int}
     */
    private function cartItems(array $summary): array
    {
        $lines = isset($summary['lines']) && is_array($summary['lines']) ? $summary['lines'] : [];
        $ids = [];
        foreach ($lines as $line) {
            $ids[] = (int) $line['product_id'];
        }
        $products = $this->products($ids);
        $items = [];
        $keys = [];
        $value = 0;
        $withTax = $this->config->withTax();
        foreach ($lines as $key => $line) {
            $amount = $withTax ? (int) $line['gross'] : (int) $line['net'];
            $keys[(string) (isset($line['key']) ? $line['key'] : $key)] = count($items);
            $items[] = $this->item([
                'id' => (string) $line['product_id'],
                'name' => (string) $line['name'], 'variant' => (string) $line['variant_label'], 'amount' => $amount, 'qty' => max(1, (int) $line['qty']),
                'pid' => (int) $line['product_id'],
            ], isset($products[(int) $line['product_id']]) ? $products[(int) $line['product_id']] : null);
            $value += $amount;
        }

        return ['items' => $items, 'keys' => $keys, 'value' => $value];
    }

    /** @param array<string,mixed> $summary */
    private function coupons(array $summary): string
    {
        $codes = [];
        foreach (isset($summary['coupons']) && is_array($summary['coupons']) ? $summary['coupons'] : [] as $coupon) {
            $codes[] = (string) $coupon['code'];
        }

        return implode(',', $codes);
    }

    // ------------------------------------------------------------------ pedido

    /**
     * Los eventos de la compra: purchase y, si el pedido lo hizo el asistente, assistant_order. El mismo identificador de evento se
     * usa en el navegador y en el servidor, para que Meta y Google no cuenten la compra dos veces.
     *
     * @param array<string,mixed>      $order
     * @param array<string,mixed>      $mark      fila de datalayer_orders
     * @param array<string,string>|null $userData correo y teléfono ya resumidos (solo con consentimiento de marketing)
     *
     * @return array<int,array<string,mixed>>
     */
    public function purchase(array $order, array $mark, ?array $userData): array
    {
        $ecommerce = $this->orderEcommerce($order);
        $event = ['event' => 'purchase', 'event_id' => (string) $mark['event_id'], 'ecommerce' => $ecommerce];
        if ($userData !== null && $userData !== []) {
            $event['user_data'] = $userData;
        }
        if (!empty($mark['ai_source'])) {
            $event['ai_source'] = (string) $mark['ai_source'];
        }
        $events = [$event];
        if (!empty($mark['assistant'])) {
            $events[] = [
                'event' => 'assistant_order', 'event_id' => 'assistant_' . (string) $mark['event_id'],
                'transaction_id' => (string) $order['number'], 'value' => $ecommerce['value'], 'currency' => $ecommerce['currency'],
            ];
        }

        return $events;
    }

    /**
     * El bloque «ecommerce» de un pedido (compra y reembolso).
     *
     * @param array<string,mixed> $order
     *
     * @return array<string,mixed>
     */
    public function orderEcommerce(array $order): array
    {
        $withTax = $this->config->withTax();
        $rows = isset($order['items']) && is_array($order['items']) ? $order['items'] : [];
        $ids = [];
        foreach ($rows as $row) {
            if ((string) $row['kind'] === 'product') {
                $ids[] = (int) $row['product_id'];
            }
        }
        $products = $this->products($ids);
        $items = [];
        $value = 0;
        $shipping = 0;
        foreach ($rows as $row) {
            $amount = (int) $row['line_net'] + ($withTax ? (int) $row['line_tax'] : 0);
            if ((string) $row['kind'] === 'shipping') {
                $shipping += $amount;
                continue;
            }
            if ((string) $row['kind'] !== 'product') {
                continue;
            }
            $items[] = $this->item([
                'id' => (string) $row['product_id'],
                'name' => (string) $row['name'], 'variant' => $row['variant_label'] !== null ? (string) $row['variant_label'] : '', 'amount' => $amount,
                'qty' => max(1, (int) $row['qty']), 'pid' => (int) $row['product_id'],
            ], isset($products[(int) $row['product_id']]) ? $products[(int) $row['product_id']] : null);
            $value += $amount;
        }
        $ecommerce = [
            'transaction_id' => (string) $order['number'], 'value' => self::num($value), 'tax' => self::num((int) $order['total_tax']),
            'shipping' => self::num($shipping), 'currency' => (string) $order['currency'],
        ];
        $coupon = $this->orderCoupons($order);
        if ($coupon !== '') {
            $ecommerce['coupon'] = $coupon;
        }
        $ecommerce['items'] = $items;

        return $ecommerce;
    }

    /** @param array<string,mixed> $order */
    private function orderCoupons(array $order): string
    {
        $codes = [];
        foreach (isset($order['coupons']) && is_array($order['coupons']) ? $order['coupons'] : [] as $coupon) {
            if (is_array($coupon) && isset($coupon['code']) && (string) $coupon['code'] !== '') {
                $codes[] = (string) $coupon['code'];
            }
        }

        return implode(',', $codes);
    }

    /**
     * refund: el pedido y el importe devuelto (el de la devolución, no el del pedido entero).
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $refund
     *
     * @return array<string,mixed>
     */
    public function refund(array $order, array $refund): array
    {
        return [
            'event' => 'refund', 'event_id' => 'refund_' . (string) $order['number'] . '_' . (int) $refund['id'],
            'ecommerce' => ['transaction_id' => (string) $order['number'], 'value' => self::num((int) $refund['amount']), 'currency' => (string) $order['currency']],
        ];
    }

    // ------------------------------------------------------------------ piezas

    /**
     * El importe de un bloque de precio, con o sin impuestos según el ajuste.
     *
     * @param array<string,mixed> $price
     */
    private function shown(array $price): int
    {
        if (isset($price['net'], $price['gross'])) {
            return $this->config->withTax() ? (int) $price['gross'] : (int) $price['net'];
        }

        return isset($price['amount']) ? (int) $price['amount'] : 0;
    }

    /**
     * Un artículo de GA4. El precio es el de una unidad (el importe de la línea entre la cantidad, redondeado a céntimo).
     *
     * @param array{id:string,name:string,variant:string,amount:int,qty:int,pid:int} $row
     * @param array<string,mixed>|null                                                $product ProductData, para la marca y la categoría
     *
     * @return array<string,mixed>
     */
    private function item(array $row, ?array $product): array
    {
        $qty = max(1, $row['qty']);
        $unit = $qty === 1 ? $row['amount'] : intdiv($row['amount'] * 2 + $qty, 2 * $qty);
        $item = ['item_id' => $row['id'], 'item_name' => $row['name']];
        if ($row['variant'] !== '') {
            $item['item_variant'] = $row['variant'];
        }
        if ($product !== null) {
            if (isset($product['brand']) && is_array($product['brand']) && (string) $product['brand']['name'] !== '') {
                $item['item_brand'] = (string) $product['brand']['name'];
            }
            if (isset($product['main_category']) && is_array($product['main_category']) && (string) $product['main_category']['name'] !== '') {
                $item['item_category'] = (string) $product['main_category']['name'];
            }
        }
        $item['price'] = self::num($unit);
        $item['quantity'] = $qty;

        return $item;
    }

    /**
     * Los productos (marca y categoría) en una sola consulta.
     *
     * @param int[] $ids
     *
     * @return array<int,array<string,mixed>>
     */
    private function products(array $ids): array
    {
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === [] || !$this->app->has('catalog')) {
            return [];
        }
        $found = $this->app->service('catalog')->products($ids, null);

        return is_array($found) ? $found : [];
    }
}
