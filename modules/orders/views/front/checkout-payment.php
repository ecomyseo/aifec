<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Las formas de pago de la caja, cada una con su descripción y su recargo si lo tiene; la elegida queda desplegada con
 * lo suyo (su descripción). Es un fragmento: la caja lo pinta dentro de su sección y, con JavaScript, lo sustituye por el
 * que devuelve la ruta de actualización.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $c lo que prepara CheckoutController::context()
 */

declare(strict_types=1);

$af_error = isset($c['errors']['payment']) ? (string) $c['errors']['payment'] : '';
?>
<fieldset class="af-fieldset af-stack">
    <legend class="af-sr"><?= e(t('Payment')) ?></legend>
<?php if ($af_error !== '') : ?>
    <p class="af-error" role="alert"><?= e($af_error) ?></p>
<?php endif; ?>
<?php if ($c['methods'] === []) : ?>
    <p class="af-alert af-alert-warn" role="alert"><?= e(t('No payment method is available for this order.')) ?></p>
<?php endif; ?>
<?php foreach ($c['methods'] as $af_method) : ?>
    <label class="af-radio-card" data-af-payment-kind="<?= e($af_method['kind']) ?>">
        <input type="radio" name="payment_method" value="<?= e($af_method['id']) ?>"<?= $af_method['selected'] ? ' checked' : '' ?> data-af-update>
        <span class="af-radio-card-body">
            <strong><?= e($af_method['name']) ?></strong>
<?php if ($af_method['description'] !== '') : ?>
            <span class="af-muted af-co-method-detail"><?= e($af_method['description']) ?></span>
<?php endif; ?>
<?php if ($af_method['fee_formatted'] !== '') : ?>
            <span class="af-radio-card-price"><?= e('+ ' . $af_method['fee_formatted']) ?></span>
<?php endif; ?>
        </span>
    </label>
<?php endforeach; ?>
</fieldset>
