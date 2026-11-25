<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders;

use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;

/**
 * «Sin pago»: lo único que se ofrece cuando lo que hay que pagar es cero (un producto gratuito, o un cupón que lo cubre
 * todo). Ninguna pasarela cobra cero, así que sin esto la caja se quedaba sin forma de pago y no se podía terminar.
 *
 * Lo ofrece y lo resuelve el servicio «payments» por sí mismo: no es un módulo, no se configura y no sale en la lista de
 * formas de pago del panel. El pedido nace pagado, con un apunte de cobro de cero.
 */
final class FreePayment implements PaymentMethod
{
    public const CODE = 'free';

    public function code(): string
    {
        return self::CODE;
    }

    public function info(): array
    {
        return [
            'title' => t('No payment needed'),
            'description' => t('There is nothing to pay for this order.'),
            'icon' => '',
        ];
    }

    public function available(array $context): bool
    {
        // Un carrito vacío también suma cero, y ahí no hay pedido que dejar sin pago.
        return array_key_exists('total', $context) && (int) $context['total'] === 0
            && isset($context['lines']) && is_array($context['lines']) && $context['lines'] !== [];
    }

    public function fee(array $context): int
    {
        return 0;
    }

    public function isTest(): bool
    {
        return false;
    }

    public function start(array $order): PaymentAction
    {
        if ((int) ($order['total'] ?? -1) !== 0) {
            return PaymentAction::failed(t('The payment could not be started. Try again or choose another payment method.'));
        }

        return PaymentAction::paid('free-' . (string) ($order['number'] ?? ''));
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(array $order, int $amount, string $reason): string
    {
        throw new \RuntimeException('There is nothing to refund through the gateway: the order was free');
    }
}
