<?php
/**
 * AIFEC — la forma de pago contra reembolso: el cliente paga al recibir el pedido, y la tienda puede cobrar un recargo.
 *
 * Solo se ofrece si el pedido necesita envío, el país de entrega está en payments.cod.countries (por defecto ES) y el
 * total no pasa de payments.cod.max_total (0 = sin tope). El recargo es un importe fijo (payments.cod.fee) más un
 * porcentaje del total (payments.cod.percent, en centésimas de punto: 150 = 1,5 %), con un mínimo (payments.cod.min_fee).
 * Los importes se escriben «tal como se escriben los precios» y el carrito les pone el impuesto como línea aparte.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsCod;

use Aifec\Commerce\Money;
use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;

final class CodPayment implements PaymentMethod
{
    public const CODE = 'cod';

    /** @var App */
    private $app;

    public function __construct(App $app)
    {
        $this->app = $app;
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function info(): array
    {
        $settings = $this->app->settings();
        $title = trim($settings->str('payments.cod.title'));
        $description = trim($settings->str('payments.cod.description'));

        return [
            'title' => $title !== '' ? $title : t('Cash on delivery'),
            'description' => $description !== '' ? $description : t('You pay when you receive your order.'),
            'icon' => '',
        ];
    }

    public function available(array $context): bool
    {
        if (empty($context['needs_shipping'])) {
            return false;
        }
        $country = isset($context['country']) && is_string($context['country']) ? strtoupper(trim($context['country'])) : '';
        if ($country === '' || !in_array($country, $this->countries(), true)) {
            return false;
        }
        $max = max(0, $this->app->settings()->int('payments.cod.max_total', 0));

        return $max === 0 || (isset($context['total']) ? (int) $context['total'] : 0) <= $max;
    }

    public function fee(array $context): int
    {
        $settings = $this->app->settings();
        $total = isset($context['total']) ? max(0, (int) $context['total']) : 0;
        $fee = max(0, $settings->int('payments.cod.fee', 0)) + Money::percent($total, max(0, $settings->int('payments.cod.percent', 0)));

        return max($fee, max(0, $settings->int('payments.cod.min_fee', 0)));
    }

    public function isTest(): bool
    {
        return false;
    }

    public function start(array $order): PaymentAction
    {
        $lang = isset($order['lang']) && is_string($order['lang']) && $order['lang'] !== '' ? $order['lang'] : null;
        $i18n = $this->app->i18n();
        $html = '<p>' . e($i18n->t('You will pay when you receive the order.', [], $lang)) . '</p>';
        if (isset($order['total'])) {
            $currency = isset($order['currency']) && is_string($order['currency']) && $order['currency'] !== '' ? $order['currency'] : $this->app->settings()->str('shop.currency', 'EUR');
            $html .= '<p>' . e($i18n->t('Amount to pay on delivery: {amount}', ['amount' => $i18n->money((int) $order['total'], $currency, $lang)], $lang)) . '</p>';
        }

        return PaymentAction::instructions($html);
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(array $order, int $amount, string $reason): string
    {
        throw new \RuntimeException('A cash on delivery payment cannot be refunded through the gateway');
    }

    /**
     * Los países donde se ofrece, en mayúsculas y sin repetir (vacío: en cualquiera). Público: la pantalla «Formas de pago»
     * del panel lo enseña en «A quién se ofrece».
     *
     * @return string[]
     */
    public function countries(): array
    {
        $out = [];
        foreach ($this->app->settings()->arr('payments.cod.countries') as $code) {
            $code = is_scalar($code) ? strtoupper(trim((string) $code)) : '';
            if ($code !== '' && !in_array($code, $out, true)) {
                $out[] = $code;
            }
        }

        return $out;
    }
}
