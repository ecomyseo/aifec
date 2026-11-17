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

use Aifec\Geo\Countries;
use Aifec\Kernel\App;

/**
 * La página del pedido en palabras claras: el estado, las instrucciones de pago, el seguimiento, las direcciones y las
 * líneas, ya preparados para la plantilla orders::front/order.
 */
final class OrderView
{
    /** Veces que la página se refresca sola mientras el pago de una pasarela no se confirma. */
    public const MAX_REFRESHES = 12;

    /** Segundos entre refrescos. */
    public const REFRESH_SECONDS = 5;

    /**
     * El estado del pedido para el cliente: título, clase del aviso (ok, warn, bad o info) y una frase de apoyo.
     *
     * @param array<string,mixed> $order
     *
     * @return array{title:string,class:string,text:string,pending:bool}
     */
    public static function status(array $order, string $instructions): array
    {
        $status = (string) $order['status'];
        $paid = in_array((string) $order['payment_status'], ['paid', 'partially_refunded', 'authorized'], true);
        $number = (string) $order['number'];
        if ($status === 'cancelled' || $status === 'failed') {
            return ['title' => t($status === 'failed' ? 'Payment failed' : 'Cancelled'), 'class' => 'bad', 'text' => t('Your order {number} has been cancelled. You have not been charged.', ['number' => $number]), 'pending' => false];
        }
        if ($status === 'refunded') {
            return ['title' => t('Refunded'), 'class' => 'bad', 'text' => '', 'pending' => false];
        }
        if ($status === 'completed') {
            return ['title' => t('Completed'), 'class' => 'ok', 'text' => t('Your order {number} is complete. Thank you for shopping with us.', ['number' => $number]), 'pending' => false];
        }
        if ($status === 'shipped') {
            return ['title' => t('Shipped'), 'class' => 'ok', 'text' => t('Your order {number} has been shipped.', ['number' => $number]), 'pending' => false];
        }
        if ($paid) {
            return ['title' => t('Payment received'), 'class' => 'ok', 'text' => t('Thank you for your order {number}. We have received your payment and we are getting it ready.', ['number' => $number]), 'pending' => false];
        }
        if ($status === 'on_hold') {
            $method = (string) $order['payment_method'];
            if ($method === 'cod') {
                return ['title' => t('Order received'), 'class' => 'info', 'text' => '', 'pending' => false];
            }
            $title = $method === 'transfer' ? t('Waiting for your transfer') : t('Waiting for your payment');

            return ['title' => $title, 'class' => 'warn', 'text' => t('We have received your order {number}. We will process it as soon as we receive your payment.', ['number' => $number]), 'pending' => false];
        }
        if ($status === 'pending_payment' && $instructions === '') {
            return ['title' => t('Waiting for the payment confirmation…'), 'class' => 'warn', 'text' => '', 'pending' => true];
        }
        $state = isset($order['state']) && is_array($order['state']) ? $order['state'] : null;

        return ['title' => $state !== null ? t((string) $state['name']) : $status, 'class' => 'info', 'text' => '', 'pending' => false];
    }

    /**
     * Las instrucciones de pago que dejó la forma de pago, con solo las etiquetas de texto corrientes y sin atributos.
     *
     * @param array<string,mixed> $order
     */
    public static function instructions(array $order): string
    {
        $html = '';
        foreach (is_array($order['history']) ? $order['history'] : [] as $entry) {
            if ((string) $entry['kind'] === 'payment' && (string) $entry['to_value'] === 'instructions' && is_array($entry['data']) && isset($entry['data']['instructions']) && is_string($entry['data']['instructions'])) {
                $html = $entry['data']['instructions'];
            }
        }

        return self::cleanHtml($html);
    }

    /**
     * Deja solo p, br, strong, b, em, i, ul, ol, li, dl, dt, dd y span, sin atributos: unas instrucciones de pago no
     * necesitan más y nada de lo guardado puede ejecutar nada en la página.
     */
    public static function cleanHtml(string $html): string
    {
        $noCode = preg_replace('#<(script|style)\b.*?</\1\s*>#is', '', $html);
        $stripped = strip_tags(is_string($noCode) ? $noCode : '', '<p><br><strong><b><em><i><ul><ol><li><dl><dt><dd><span>');
        $clean = preg_replace('/<(\/?)(p|br|strong|b|em|i|ul|ol|li|dl|dt|dd|span)\b[^>]*?(\/?)>/i', '<$1$2$3>', $stripped);

        return trim(is_string($clean) ? $clean : '');
    }

    /**
     * El enlace de seguimiento del transportista, solo si es http o https. Cadena vacía si no hay.
     *
     * @param array<string,mixed> $order
     */
    public static function trackingUrl(App $app, array $order): string
    {
        $code = trim((string) ($order['tracking_code'] !== null ? $order['tracking_code'] : ''));
        if ($code === '' || $order['carrier_id'] === null || !$app->has('shipping')) {
            return '';
        }
        $carrier = $app->service('shipping')->carrier((int) $order['carrier_id']);
        $template = $carrier !== null && isset($carrier['tracking_url']) ? trim((string) $carrier['tracking_url']) : '';
        if ($template === '') {
            return '';
        }
        $url = str_replace('{code}', rawurlencode($code), $template);

        return preg_match('#^https?://#i', $url) === 1 ? $url : '';
    }

    /**
     * Una dirección del pedido como líneas de texto.
     *
     * @param array<string,mixed> $address
     *
     * @return string[]
     */
    public static function addressLines(App $app, array $address, bool $withTaxId): array
    {
        return \Aifec\Geo\Address::lines($address, $app->lang(), ['tax_id' => $withTaxId]);
    }

    /**
     * Las líneas de producto del pedido, con su importe tal como se cobró.
     *
     * @param array<string,mixed> $order
     *
     * @return array<int,array{name:string,variant:string,qty:int,total:string}>
     */
    public static function lines(App $app, array $order): array
    {
        $currency = (string) $order['currency'];
        $out = [];
        foreach (is_array($order['items']) ? $order['items'] : [] as $item) {
            if ((string) $item['kind'] !== 'product') {
                continue;
            }
            $out[] = [
                'name' => (string) $item['name'],
                'variant' => $item['variant_label'] !== null ? (string) $item['variant_label'] : '',
                'qty' => (int) $item['qty'],
                'total' => $app->i18n()->money((int) $item['line_total'], $currency),
            ];
        }

        return $out;
    }
}
