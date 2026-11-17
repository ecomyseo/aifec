<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * El botón flotante del asistente y su ventana de conversación (hueco «body.end» de la tienda). No lleva nada personal:
 * es el mismo HTML para todo el mundo, así que la página sigue siendo cacheable. Empieza oculto y el guion lo enseña, así que
 * sin JavaScript no se ve un botón que no hace nada. Todos los textos que pinta el guion vienen en atributos data-t-*.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var \Aifec\Modules\Assistant\Assistant $assistant
 */

declare(strict_types=1);

$app = app();
$url = $app->url();
$name = $assistant->name();

?><?= \Aifec\Front\ThemeVars::styleTag($app, 'm', 'assistant', 'assistant.css') ?>
<div class="af-assistant" data-af-assistant hidden
     data-af-assistant-url="<?= e($url->route('assistant.message')) ?>"
<?php if ($assistant->checkoutEnabled()) : ?>
     data-af-assistant-confirm-url="<?= e($url->route('assistant.confirm')) ?>"
<?php endif; ?>
     data-t-confirm="<?= e(t('Confirm order')) ?>"
     data-t-summary="<?= e(t('Your order')) ?>"
     data-t-total="<?= e(t('Total')) ?>"
     data-t-shipping="<?= e(t('Delivery method')) ?>"
     data-t-payment="<?= e(t('Payment method')) ?>"
     data-t-deliver="<?= e(t('Deliver to')) ?>"
     data-t-invoice="<?= e(t('Invoice to')) ?>"
     data-t-taxid="<?= e(t('Tax ID')) ?>"
     data-t-terms="<?= e(t('Terms and conditions')) ?>"
     data-t-accept="<?= e(t('By confirming you accept the terms and conditions.')) ?>"
     data-t-pay="<?= e(t('Pay now')) ?>"
     data-t-vieworder="<?= e(t('View my order')) ?>"
     data-t-add="<?= e(t('Add to cart')) ?>"
     data-t-view="<?= e(t('View')) ?>"
     data-t-options="<?= e(t('Choose options')) ?>"
     data-t-checkout="<?= e(t('Go to checkout')) ?>"
     data-t-subscriptions="<?= e(t('Review monthly plans')) ?>"
     data-t-from="<?= e(t('From')) ?>"
     data-t-error="<?= e(t('Something went wrong. Please try again.')) ?>"
     data-t-busy="<?= e(t('The assistant is writing')) ?>"
     data-t-you="<?= e(t('You')) ?>"
     data-t-bot="<?= e($name !== '' ? $name : t('Assistant')) ?>">
    <button type="button" class="af-assistant-fab" data-af-assistant-open aria-haspopup="dialog" aria-controls="af-assistant-panel" aria-expanded="false" aria-label="<?= e(t('Open the shop assistant')) ?>">
        <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M4 5h16v11H9.500L5 20v-4H4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span class="af-assistant-fab-label"><?= e($name !== '' ? $name : t('Assistant')) ?></span>
    </button>
    <section class="af-assistant-panel" id="af-assistant-panel" role="dialog" aria-modal="false" aria-labelledby="af-assistant-title" hidden>
        <header class="af-assistant-head">
            <h2 id="af-assistant-title" class="af-assistant-title"><?= e($name !== '' ? $name : t('Shop assistant')) ?></h2>
            <button type="button" class="af-assistant-close" data-af-assistant-close aria-label="<?= e(t('Close the assistant')) ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
        </header>
        <div class="af-assistant-log" data-af-assistant-log role="log" aria-live="polite" aria-relevant="additions">
            <div class="af-assistant-msg is-bot"><p><?= e($assistant->intro()) ?></p></div>
        </div>
        <div class="af-assistant-chips" data-af-assistant-chips></div>
        <form class="af-assistant-form" data-af-assistant-form autocomplete="off">
            <label class="af-sr" for="af-assistant-input"><?= e(t('Write your message')) ?></label>
            <input type="text" id="af-assistant-input" class="af-input" name="message" maxlength="1000" placeholder="<?= e(t('Write your message')) ?>" enterkeyhint="send">
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Send')) ?></button>
        </form>
    </section>
</div>
<script src="<?= e($url->asset('m', 'assistant', 'assistant.js')) ?>" defer></script>
