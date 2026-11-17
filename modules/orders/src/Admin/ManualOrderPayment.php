<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\Orders\Admin;

use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;

/**
 * «Ya está pagado» del pedido a mano: el dinero se cobró fuera de la web (efectivo, tarjeta en el mostrador, Bizum…) y el
 * pedido nace pagado, con su apunte de cobro de siempre (Orders::markPaid(), referencia «panel-<número>»).
 *
 * Solo se registra en las peticiones de la pantalla «Pedido nuevo» y solo se ofrece mientras esa pantalla la tiene armada
 * (arm() … disarm()): la caja de la tienda no la ve nunca, ni siquiera en una aplicación que atiende varias peticiones seguidas.
 */
final class ManualOrderPayment implements PaymentMethod
{
    public const CODE = 'in_person';

    /** Largo máximo de «con qué se pagó». */
    public const MAX_HOW = 60;

    private bool $armed = false;

    private string $how = '';

    /** La de esta aplicación: se registra la primera vez que hace falta. */
    public static function forApp(App $app): self
    {
        $payments = $app->service('payments');
        $method = $payments->get(self::CODE);
        if ($method instanceof self) {
            return $method;
        }
        $method = new self();
        $payments->register($method);

        return $method;
    }

    /** Se ofrece (y cobra) mientras la pantalla del pedido a mano está trabajando. $how: con qué se pagó. */
    public function arm(string $how): void
    {
        $this->armed = true;
        $this->how = mb_substr(trim((string) preg_replace('/\s+/u', ' ', $how)), 0, self::MAX_HOW, 'UTF-8');
    }

    public function disarm(): void
    {
        $this->armed = false;
        $this->how = '';
    }

    public function code(): string
    {
        return self::CODE;
    }

    public function info(): array
    {
        return [
            'title' => $this->how !== '' ? t('Paid in person: {how}', ['how' => $this->how]) : t('Paid in person'),
            'description' => t('The money was taken outside the website: cash, card at the counter, Bizum…'),
            'icon' => '',
        ];
    }

    public function available(array $context): bool
    {
        return $this->armed;
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
        if (!$this->armed) {
            return PaymentAction::failed(t('A payment taken in person can only be recorded from the panel.'));
        }

        return PaymentAction::paid('panel-' . (string) $order['number']);
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(array $order, int $amount, string $reason): string
    {
        throw new \RuntimeException('This payment was taken in person: give the money back by hand');
    }
}
