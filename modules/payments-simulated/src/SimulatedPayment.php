<?php
/**
 * AIFEC — la forma de pago simulada: no habla con ningún banco, sirve para probar la tienda y para las demos.
 *
 * Tres modos (ajuste payments.simulated.mode): «approve» da todos los cobros por buenos, «decline» los rechaza todos y
 * «ask» (el de serie) lleva al cliente a una página de prueba donde elige. Solo se ofrece en un sitio de demostración,
 * en modo de depuración o con el ajuste payments.simulated.allow_live encendido. Un cobro simulado es siempre de pruebas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsSimulated;

use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;

final class SimulatedPayment implements PaymentMethod
{
    public const CODE = 'simulated';
    public const MODE_ASK = 'ask';
    public const MODE_APPROVE = 'approve';
    public const MODE_DECLINE = 'decline';

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
        $title = trim($settings->str('payments.simulated.title'));
        $description = trim($settings->str('payments.simulated.description'));

        return [
            'title' => $title !== '' ? $title : t('Test payment'),
            'description' => $description !== '' ? $description : t('A payment that does not charge anything. Only for tests and demos.'),
            'icon' => '',
        ];
    }

    public function available(array $context): bool
    {
        $settings = $this->app->settings();
        if (!$settings->bool('payments.simulated.enabled', true)) {
            return false;
        }

        return $this->app->site()->isDemo() || $this->app->debug() || $settings->bool('payments.simulated.allow_live', false);
    }

    public function fee(array $context): int
    {
        return 0;
    }

    public function isTest(): bool
    {
        return true;
    }

    public function start(array $order): PaymentAction
    {
        $number = isset($order['number']) ? (string) $order['number'] : '';
        $mode = $this->mode();
        if ($mode === self::MODE_APPROVE) {
            return PaymentAction::paid(self::reference($number));
        }
        if ($mode === self::MODE_DECLINE) {
            return PaymentAction::failed(t('The simulated payment was declined.'));
        }

        // La ficha del pedido en claro solo la lleva el pedido recién creado: sin ella no hay página a la que llevar al cliente.
        $secret = isset($order['secret']) && is_string($order['secret']) ? $order['secret'] : '';
        if ($number === '' || $secret === '') {
            throw new \RuntimeException('The simulated payment needs the order number and its secret');
        }

        return PaymentAction::redirect($this->app->url()->route('payment.simulated.pay', ['number' => $number, 'secret' => $secret]));
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function refund(array $order, int $amount, string $reason): string
    {
        // Una referencia distinta en cada devolución: la cifra acumulada devuelta crece siempre.
        $cumulative = isset($order['refunded_total']) ? (int) $order['refunded_total'] : 0;
        if ($cumulative < $amount) {
            $cumulative = $amount;
        }

        return 'SIMR-' . (isset($order['number']) ? (string) $order['number'] : '') . '-' . $cumulative;
    }

    /** La referencia del cobro simulado de un pedido: siempre la misma, así un segundo envío no cobra dos veces. */
    public static function reference(string $number): string
    {
        return 'SIM-' . $number;
    }

    private function mode(): string
    {
        $mode = $this->app->settings()->str('payments.simulated.mode', self::MODE_ASK);

        return in_array($mode, [self::MODE_APPROVE, self::MODE_DECLINE], true) ? $mode : self::MODE_ASK;
    }
}
