<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * Las opciones de envío de la caja. Aparecen en cuanto hay código postal (o una dirección guardada elegida): antes se
 * enseña una frase que lo dice. Es un fragmento: la caja lo pinta dentro de su sección y, con JavaScript, lo sustituye
 * por el que devuelve la ruta de actualización.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $c lo que prepara CheckoutController::context()
 */

declare(strict_types=1);

$af_error = isset($c['errors']['carrier']) ? (string) $c['errors']['carrier'] : '';
?>
<?php if (empty($c['shipping_ready'])) : ?>
<p class="af-muted af-co-wait" data-af-shipping-wait><?= e(t('The shipping in the summary is an estimate. Enter your postcode and the shipping options will appear here.')) ?></p>
<noscript><p class="af-hint"><?= e(t('Without JavaScript, press Update after writing your postcode to see the shipping options.')) ?></p></noscript>
<?php else : ?>
<fieldset class="af-fieldset af-stack">
    <legend class="af-sr"><?= e(t('Shipping')) ?></legend>
<?php if ($af_error !== '') : ?>
    <p class="af-error" role="alert"><?= e($af_error) ?></p>
<?php endif; ?>
<?php if ($c['carriers'] === []) : ?>
    <p class="af-alert af-alert-warn" role="alert"><?= e(!empty($c['delivery_saved'])
        ? t('We cannot deliver to the saved address selected ({place}). Choose another saved address, or choose "Use another address", write the new one and press Update.', ['place' => (string) $c['delivery_label']])
        : t('We cannot deliver to {place}. Change the country or the postcode and press Update.', ['place' => (string) $c['delivery_label']])) ?></p>
<?php endif; ?>
<?php foreach ($c['carriers'] as $af_carrier) : ?>
    <label class="af-radio-card">
        <input type="radio" name="carrier_id" value="<?= e($af_carrier['id']) ?>"<?= $af_carrier['selected'] ? ' checked' : '' ?> data-af-update>
        <span class="af-radio-card-body">
            <strong><?= e($af_carrier['name']) ?></strong>
<?php if ($af_carrier['delay'] !== '') : ?>
            <span class="af-muted"><?= e($af_carrier['delay']) ?></span>
<?php endif; ?>
            <span class="af-radio-card-price"><?= e($af_carrier['price_formatted']) ?></span>
        </span>
    </label>
<?php endforeach; ?>
</fieldset>
<?php endif; ?>
