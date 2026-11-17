<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsPaypal;

use Aifec\Kernel\App;
use Aifec\Payments\PayPal\PayPalClient;
use Aifec\Payments\PayPal\PayPalFundingSources;
use Aifec\Payments\PayPal\PayPalSdk;

/**
 * Lo que comparten la vuelta de PayPal (PayPalController) y la página de pago del guion (PayPalSdkController) al resolver un
 * cobro: dar el pedido por pagado una sola vez y decir con qué se pagó, dejarlo en espera con una nota, y mirar 3-D Secure
 * antes de capturar.
 */
final class PayPalOutcome
{
    /**
     * Da el pedido por pagado con la forma de pago del pedido y, la primera vez, apunta con qué se pagó («Pagado con iDEAL por
     * PayPal»). markPaid solo cuenta la primera vez: la misma captura repetida no cobra dos veces.
     *
     * @param array<string,mixed> $order
     * @param array<string,mixed> $raw
     */
    public static function paid(App $app, bool $test, array $order, string $captureId, int $amount, array $raw): bool
    {
        $orders = $app->service('orders');
        $code = (string) $order['payment_method'];
        if (!$orders->markPaid((int) $order['id'], $code, $captureId, $amount, $raw, $test)) {
            return false;
        }
        // La cartera no lo necesita: su título ya es «PayPal».
        $source = (string) PayPalSources::source($code);
        if ($source === 'card') {
            $orders->addNote((int) $order['id'], t('Paid by card through PayPal.'), false);
        } elseif ($source !== PayPalSources::WALLET) {
            $orders->addNote((int) $order['id'], t('Paid with {method} through PayPal.', ['method' => PayPalSources::name($source)]), false);
        }

        return true;
    }

    /**
     * Deja el pedido en espera con una nota solo para quien lleva la tienda. Si el pedido ya estaba en espera, o ya no se puede
     * poner (pagado, cancelado), no cambia nada y tampoco repite la nota.
     */
    public static function hold(App $app, int $orderId, string $note): void
    {
        $orders = $app->service('orders');
        if ($orders->setStatus($orderId, 'on_hold', '', null, false)) {
            $orders->addNote($orderId, $note, false);
        }
    }

    /**
     * Con las formas que pasan por 3-D Secure (la tarjeta en la página y Google Pay), consulta la orden antes de capturar y
     * dice por qué NO se debe capturar (el resultado que dio el banco), o '' si se puede seguir. Las demás formas, ''.
     *
     * @throws \Aifec\Payments\PayPal\PayPalException si PayPal no contesta
     */
    public static function threeDsRefused(PayPalClient $client, string $source, string $paypalOrder, int $now): string
    {
        $known = PayPalFundingSources::get($source);
        if ($known === null || empty($known['three_ds'])) {
            return '';
        }
        $order = $client->getOrder($paypalOrder, $now);
        $liability = isset($order['liability']) && is_array($order['liability']) ? $order['liability'] : [];
        if ($liability === []) {
            return '';
        }
        $shift = isset($liability['shift']) ? (string) $liability['shift'] : '';
        $enrollment = isset($liability['enrollment']) ? (string) $liability['enrollment'] : '';
        $authentication = isset($liability['authentication']) ? (string) $liability['authentication'] : '';
        if (PayPalSdk::liabilityAccepted($shift, $enrollment, $authentication)) {
            return '';
        }

        return ($shift !== '' ? $shift : '?') . '/' . ($enrollment !== '' ? $enrollment : '-') . '/' . ($authentication !== '' ? $authentication : '-');
    }
}
