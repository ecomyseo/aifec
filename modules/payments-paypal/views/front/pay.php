<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La página de pago de las formas que necesitan el guion de PayPal: el importe, el sitio donde el guion pinta el botón (o los
 * campos de la tarjeta) y el aviso para quien no tiene JavaScript. Ningún guion escrito aquí: pay.js lee los datos de
 * data-config y los textos de los data-msg-*, y carga el guion de PayPal que dice el servidor.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var string              $number  número del pedido
 * @var string              $source  forma de pago del catálogo (paylater, card_fields, google_pay, apple_pay, venmo)
 * @var string              $title   nombre de la forma de pago, ya traducido
 * @var string              $total   importe ya formateado
 * @var bool                $message pintar el mensaje de financiación de Paga más tarde
 * @var string              $cancel  dirección para elegir otra forma de pago
 * @var string              $config  datos para pay.js (JSON)
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
$this->push('scripts', '<script src="' . e(app()->url()->asset('m', 'payments-paypal', 'pay.js')) . '" defer></script>');
?>
<div class="af-container af-section">
    <div class="af-card af-stack af-pp-pay" data-af-pp data-config="<?= e($config) ?>"
        data-msg-unavailable="<?= e(t('This payment method is not available for this payment. Choose another payment method.')) ?>"
        data-msg-unsupported="<?= e(t('This browser cannot pay with {method}. Choose another payment method.', ['method' => $title])) ?>"
        data-msg-failed="<?= e(t('The payment was not completed. Try again or choose another payment method.')) ?>"
        data-msg-cancelled="<?= e(t('You closed the payment window. You can try again.')) ?>"
        data-msg-error="<?= e(t('The payment of PayPal could not be loaded. Reload the page or choose another payment method.')) ?>"
        data-msg-paying="<?= e(t('Paying…')) ?>"
        data-msg-paid="<?= e(t('Payment received. Taking you to your order…')) ?>"
        data-msg-apple="<?= e(t('Pay with Apple Pay')) ?>">
        <h1 class="af-title"><?= e(t('Pay order {number}', ['number' => $number])) ?></h1>
        <p class="af-muted"><?= e($title) ?> · <strong><?= e($total) ?></strong></p>
<?php if ($message) : ?>
        <div data-af-pp-message></div>
<?php endif; ?>
        <p class="af-alert" role="status" aria-live="polite" data-af-pp-status><?= e(t('Loading the secure payment of PayPal…')) ?></p>
        <noscript>
            <p class="af-alert af-alert-warn"><?= e(t('This payment method needs JavaScript. Turn it on in your browser or choose another payment method.')) ?></p>
        </noscript>
        <div data-af-pp-button></div>
<?php if ($source === 'card_fields') : ?>
        <form class="af-stack" data-af-pp-card hidden>
            <p class="af-label"><?= e(t('Card number')) ?></p>
            <div data-af-pp-number></div>
            <p class="af-label"><?= e(t('Expiry date')) ?></p>
            <div data-af-pp-expiry></div>
            <p class="af-label"><?= e(t('Security code')) ?></p>
            <div data-af-pp-cvv></div>
            <button type="submit" class="af-btn af-btn-primary af-btn-lg"><?= e(t('Pay {amount}', ['amount' => $total])) ?></button>
        </form>
<?php endif; ?>
        <p><a class="af-link" href="<?= e($cancel) ?>"><?= e(t('Choose another payment method')) ?></a></p>
    </div>
</div>
