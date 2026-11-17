<?php
/**
 * AIFEC — la forma de pago por transferencia bancaria: el pedido queda en espera y se le explica al cliente a qué cuenta
 * pagar y con qué concepto. No habla con ningún banco: quien confirma el cobro es la persona que lleva la tienda, a mano.
 *
 * Ajustes: payments.transfer.holder, .iban, .bank, .days (plazo en días, 0 = sin plazo) y .note. Sin un IBAN con los dígitos
 * de control buenos no se ofrece. Todo lo que se escribe en las instrucciones va escapado; el texto va en el idioma del pedido.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

declare(strict_types=1);

namespace Aifec\Modules\PaymentsTransfer;

use Aifec\Commerce\PaymentAction;
use Aifec\Commerce\PaymentMethod;
use Aifec\Kernel\App;

final class TransferPayment implements PaymentMethod
{
    public const CODE = 'transfer';

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
        $title = trim($settings->str('payments.transfer.title'));
        $description = trim($settings->str('payments.transfer.description'));

        return [
            'title' => $title !== '' ? $title : t('Bank transfer'),
            'description' => $description !== '' ? $description : t('You will get our bank details and we will prepare your order when the payment arrives.'),
            'icon' => '',
        ];
    }

    public function available(array $context): bool
    {
        return Iban::isValid($this->app->settings()->str(TransferSettings::IBAN));
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
        $settings = $this->app->settings();
        $iban = Iban::format($settings->str(TransferSettings::IBAN));
        if (!Iban::isValid($iban)) {
            throw new \RuntimeException('The bank transfer has no valid IBAN');
        }
        $lang = isset($order['lang']) && is_string($order['lang']) && $order['lang'] !== '' ? $order['lang'] : null;
        $i18n = $this->app->i18n();
        $number = isset($order['number']) ? (string) $order['number'] : '';
        $total = isset($order['total']) ? (int) $order['total'] : 0;
        $currency = isset($order['currency']) && is_string($order['currency']) && $order['currency'] !== '' ? $order['currency'] : $settings->str('shop.currency', 'EUR');

        $rows = [];
        $holder = trim($settings->str('payments.transfer.holder'));
        if ($holder !== '') {
            $rows[] = [$i18n->t('Account holder', [], $lang), $holder];
        }
        $rows[] = [$i18n->t('IBAN', [], $lang), $iban];
        $bank = trim($settings->str('payments.transfer.bank'));
        if ($bank !== '') {
            $rows[] = [$i18n->t('Bank', [], $lang), $bank];
        }
        $rows[] = [$i18n->t('Reference', [], $lang), $number];
        $rows[] = [$i18n->t('Amount', [], $lang), $i18n->money($total, $currency, $lang)];

        $html = '<p>' . e($i18n->t('Please transfer the amount of your order to this bank account:', [], $lang)) . '</p><ul>';
        foreach ($rows as $row) {
            $html .= '<li><strong>' . e($row[0]) . ':</strong> ' . e($row[1]) . '</li>';
        }
        $html .= '</ul><p>' . e($i18n->t('Write the order number as the reference so we can find your payment.', [], $lang)) . '</p>';

        $days = max(0, $settings->int('payments.transfer.days', 5));
        if ($days > 0) {
            $html .= '<p>' . e($i18n->tn('Please pay within {n} day.', 'Please pay within {n} days.', $days, [], $lang)) . '</p>';
        }
        $note = trim($settings->str('payments.transfer.note'));
        if ($note !== '') {
            $html .= '<p>' . nl2br(e($note), false) . '</p>';
        }

        return PaymentAction::instructions($html);
    }

    public function supportsRefund(): bool
    {
        return false;
    }

    public function refund(array $order, int $amount, string $reason): string
    {
        throw new \RuntimeException('A bank transfer cannot be refunded through the gateway');
    }
}
