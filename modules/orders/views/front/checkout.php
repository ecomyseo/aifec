<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La caja: una sola página y un solo formulario, sin pasos. Contacto, entrega, envío, pago y un botón con el total, con
 * el resumen del pedido a un lado (plegable arriba en móvil, con el total a la vista, y fijo en escritorio).
 *
 * Mejoras sobre una caja de una página corriente: «Rellénalo de una vez» (pega o dicta todos los datos en una frase),
 * el código postal pone la provincia y recalcula el envío y los impuestos, la empresa y el NIF solo salen si se piden, la
 * factura va a la dirección de entrega salvo que se marque otra, cada campo se comprueba al salir de él y lo escrito se
 * guarda en el carrito según se rellena.
 *
 * Funciona sin JavaScript (el botón «Actualizar» guarda lo escrito y vuelve a calcular); con JavaScript, checkout.js
 * sustituye los fragmentos de envío, pago y resumen por los que calcula el servidor. Es una página privada: no se indexa
 * y no se guarda en ninguna caché. El total del botón de pagar es el del resumen, y el formulario lo lleva escondido
 * (expected_total) para que no se cobre uno distinto del que se enseñó.
 *
 * Huecos: checkout.before, checkout.review.before_pay (encima del botón de pagar), checkout.review.after y checkout.after.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<string,mixed> $c    lo que prepara CheckoutController::context()
 */

declare(strict_types=1);

$this->layout('layout', ['page' => $page]);
$this->push('styles', '<link rel="stylesheet" href="' . e(app()->url()->asset('t', 'base', 'css/checkout.css')) . '">');
$this->push('scripts', '<script src="' . e(app()->url()->asset('t', 'base', 'js/checkout.js')) . '" defer></script>');

$af_state = $c['state'];
$af_errors = $c['errors'];
$af_customer = $c['customer'];
$af_needs = $c['needs_shipping'];
$af_main = $af_needs ? 'shipping' : 'billing';
$af_err = static function (string $key) use ($af_errors): string {
    return isset($af_errors[$key]) ? (string) $af_errors[$key] : '';
};
$af_summary_total = '<strong data-af-checkout-total>' . e($c['view']['total_formatted']) . '</strong>';
?>
<div class="af-container af-section af-co" data-af-checkout data-af-update-url="<?= e($c['urls']['update']) ?>" data-af-understand-url="<?= e($c['urls']['understand']) ?>">
    <h1 class="af-title"><?= e(t('Checkout')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $c['messages']]) ?>
<?php foreach ($c['problems'] as $af_problem) : ?>
    <p class="af-alert af-alert-bad" role="alert"><?= e((string) $af_problem) ?></p>
<?php endforeach; ?>
    <?= hook('checkout.before', $c['summary']) ?>
    <details class="af-card af-show-sm af-co-mobile-summary" open data-af-summary-details>
        <summary class="af-row"><span><?= e(t('Order summary')) ?></span> <?= $af_summary_total ?></summary>
        <div data-af-fragment="summary"><?= $this->partial('orders::front/checkout-summary', ['c' => $c]) ?></div>
    </details>
    <div class="af-split">
        <form class="af-stack af-co-form" method="post" action="<?= e($c['urls']['place']) ?>" data-af-checkout-form>
            <input type="hidden" name="expected_total" value="<?= e((int) $c['expected_total']) ?>" data-af-expected-total>

            <section class="af-card af-stack" id="af-co-contact" aria-labelledby="af-co-contact-h">
                <h2 id="af-co-contact-h"><?= e(t('Contact')) ?></h2>
<?php if ($af_customer !== null) : ?>
                <input type="hidden" name="email" value="<?= e((string) $af_customer['email']) ?>">
                <p class="af-row">
                    <span><?= e(t('You are signed in as {email}', ['email' => (string) $af_customer['email']])) ?></span>
                    <button type="submit" form="af-co-logout" class="af-btn af-btn-ghost af-btn-sm"><?= e(t('Not you?')) ?></button>
                </p>
<?php else : ?>
                <?= $this->partial('orders::front/checkout-field', ['id' => 'af-co-email', 'name' => 'email', 'label' => t('Email'), 'type' => 'email', 'value' => $af_state['email'], 'error' => $af_err('email'), 'autocomplete' => 'email', 'inputmode' => 'email', 'required' => true, 'max' => 191]) ?>
                <p class="af-small"><?= e(t('Already have an account?')) ?> <a href="<?= e($c['urls']['login']) ?>"><?= e(t('Sign in')) ?></a></p>
<?php endif; ?>
            </section>

            <section class="af-card af-stack" id="<?= $af_needs ? 'af-co-delivery' : 'af-co-billing' ?>" aria-labelledby="af-co-address-h" data-af-step="<?= $af_needs ? 'delivery' : 'billing' ?>">
                <h2 id="af-co-address-h"><?= e($af_needs ? t('Delivery') : t('Invoice details')) ?></h2>
                <div class="af-co-understand" data-af-understand hidden
                     data-af-group="<?= e($af_main) ?>"
                     data-af-done="<?= e(t('We have filled in what we understood. Check the fields marked and complete the rest.')) ?>"
                     data-af-none="<?= e(t('We could not find any details in that text. Write them in the fields below.')) ?>"
                     data-af-doubt-guessed="<?= e(t('We are not sure about this one. Please check it.')) ?>"
                     data-af-doubt-invalid="<?= e(t('This does not look right. Please check it.')) ?>"
                     data-af-doubt-ambiguous="<?= e(t('We found more than one. We used the first; check it.')) ?>">
                    <label for="af-co-understand-text"><?= e(t('Fill it in at once')) ?></label>
                    <p class="af-hint"><?= e(t('Optional: write or dictate your details in one sentence and we will fill in the fields for you.')) ?></p>
                    <div class="af-co-understand-row">
                        <textarea id="af-co-understand-text" class="af-textarea" rows="2" maxlength="2000" autocomplete="off" data-af-understand-text></textarea>
                        <button type="button" class="af-btn" data-af-understand-run><?= e(t('Fill in')) ?></button>
                    </div>
                    <p class="af-small af-muted" role="status" data-af-understand-status></p>
                </div>
<?php if ($af_needs) : ?>
<?php if ($c['addresses'] !== []) : ?>
                <fieldset class="af-fieldset af-stack"<?= $af_err('address') !== '' ? ' aria-describedby="af-co-address-error"' : '' ?>>
                    <legend class="af-sr"><?= e(t('Saved addresses')) ?></legend>
<?php if ($af_err('address') !== '') : ?>
                    <p class="af-error" id="af-co-address-error" role="alert"><?= e($af_err('address')) ?></p>
<?php endif; ?>
<?php foreach ($c['addresses'] as $af_address) : ?>
                    <label class="af-radio-card" data-af-saved-address="<?= e((int) $af_address['id']) ?>">
                        <input type="radio" name="address_id" value="<?= e((int) $af_address['id']) ?>"<?= (int) $af_state['address_id'] === (int) $af_address['id'] ? ' checked' : '' ?> data-af-update>
                        <span class="af-radio-card-body">
                            <strong><?= e(trim((string) $af_address['first_name'] . ' ' . (string) $af_address['last_name'])) ?></strong>
                            <span class="af-muted"><?= e((string) $af_address['line1'] . ', ' . (string) $af_address['postcode'] . ' ' . (string) $af_address['city']) ?></span>
                        </span>
                    </label>
<?php endforeach; ?>
                    <label class="af-radio-card">
                        <input type="radio" name="address_id" value="0"<?= (int) $af_state['address_id'] === 0 ? ' checked' : '' ?> data-af-update>
                        <span class="af-radio-card-body"><strong><?= e(t('Use another address')) ?></strong></span>
                    </label>
                </fieldset>
<?php endif; ?>
                <div data-af-new-address>
                    <?= $this->partial('orders::front/checkout-address', ['c' => $c, 'group' => 'shipping', 'full' => true, 'idp' => 'af-co-ship']) ?>
                </div>
                <div class="af-co-toggle">
                    <input id="af-co-billing-other" class="af-co-toggle-input" type="checkbox" name="billing_other" value="1"<?= !empty($af_state['billing_other']) ? ' checked' : '' ?>>
                    <label for="af-co-billing-other"><?= e(t('The invoice goes to another name or address')) ?></label>
                    <div id="af-co-billing-box" class="af-stack af-co-toggle-box" data-af-billing-box>
                        <h3 id="af-co-bill-h"><?= e(t('Invoice details')) ?></h3>
                        <?= $this->partial('orders::front/checkout-address', ['c' => $c, 'group' => 'billing', 'full' => true, 'idp' => 'af-co-bill', 'section' => 'billing ', 'labelledby' => 'af-co-bill-h']) ?>
                    </div>
                </div>
<?php else : ?>
                <?= $this->partial('orders::front/checkout-address', ['c' => $c, 'group' => 'billing', 'full' => false, 'idp' => 'af-co-bill']) ?>
<?php endif; ?>
            </section>

<?php if ($af_needs) : ?>
            <section class="af-card af-stack" id="af-co-shipping" aria-labelledby="af-co-shipping-h" data-af-step="shipping">
                <h2 id="af-co-shipping-h"><?= e(t('Shipping')) ?></h2>
                <div data-af-fragment="shipping"><?= $this->partial('orders::front/checkout-shipping', ['c' => $c]) ?></div>
            </section>
<?php endif; ?>

            <section class="af-card af-stack" id="af-co-payment" aria-labelledby="af-co-payment-h" data-af-step="payment">
                <h2 id="af-co-payment-h"><?= e(t('Payment')) ?></h2>
                <div data-af-fragment="payment"><?= $this->partial('orders::front/checkout-payment', ['c' => $c]) ?></div>
            </section>

            <section class="af-card af-stack" id="af-co-review" aria-labelledby="af-co-review-h" data-af-step="review">
                <h2 id="af-co-review-h" class="af-sr"><?= e(t('Review')) ?></h2>
                <div class="af-field">
                    <label for="af-co-note"><?= e(t('Note for the shop (optional)')) ?></label>
                    <textarea id="af-co-note" class="af-textarea" name="note" rows="2" maxlength="2000"><?= e($af_state['note']) ?></textarea>
                </div>
                <div class="af-field<?= $af_err('accept_terms') !== '' ? ' has-error' : '' ?>">
                    <label class="af-check" for="af-co-terms">
                        <input id="af-co-terms" type="checkbox" name="accept_terms" value="1"<?= !empty($af_state['accept_terms']) ? ' checked' : '' ?> required<?= $af_err('accept_terms') !== '' ? ' aria-invalid="true" aria-describedby="af-co-terms-error"' : '' ?>>
                        <span><?= e(t('I accept the terms and conditions')) ?></span>
                    </label>
<?php if ($c['terms_url'] !== '') : ?>
                    <a class="af-small" href="<?= e($c['terms_url']) ?>" target="_blank" rel="noopener"><?= e(t('Read the terms and conditions')) ?></a>
<?php endif; ?>
<?php if ($af_err('accept_terms') !== '') : ?>
                    <span class="af-error" id="af-co-terms-error" role="alert"><?= e($af_err('accept_terms')) ?></span>
<?php endif; ?>
                </div>
                <label class="af-check" for="af-co-marketing">
                    <input id="af-co-marketing" type="checkbox" name="marketing" value="1"<?= !empty($af_state['marketing']) ? ' checked' : '' ?>>
                    <span><?= e(t('Send me news and offers by email (optional)')) ?></span>
                </label>
                <?= hook('checkout.review.before_pay', $c['summary']) ?>
                <div class="af-stack af-co-pay">
                    <button type="submit" class="af-btn af-btn-primary af-btn-block af-btn-lg" data-af-checkout-submit data-total="<?= e((int) $c['expected_total']) ?>"<?= $c['blocked'] ? ' disabled' : '' ?>><?= e($c['button']) ?></button>
                    <button type="submit" class="af-btn af-btn-block" formaction="<?= e($c['urls']['update']) ?>" formnovalidate data-af-update-button><?= e(t('Update')) ?></button>
                    <p class="af-small af-muted" data-af-update-hint><?= e(t('If you change the country, the postcode, the shipping or the payment, press Update to see the new prices.')) ?></p>
                </div>
                <?= hook('checkout.review.after', $c['summary']) ?>
            </section>
        </form>
        <aside class="af-summary af-card af-stack af-hide-sm" aria-labelledby="af-co-summary-h">
            <h2 id="af-co-summary-h"><?= e(t('Order summary')) ?></h2>
            <div data-af-fragment="summary"><?= $this->partial('orders::front/checkout-summary', ['c' => $c]) ?></div>
        </aside>
    </div>
<?php if ($af_customer !== null) : ?>
    <form id="af-co-logout" method="post" action="<?= e($c['urls']['logout']) ?>"></form>
<?php endif; ?>
    <?= hook('checkout.after', $c['summary']) ?>
</div>
