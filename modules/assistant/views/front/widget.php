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
     data-design="<?= e($app->settings()->str('assistant.design', 'standard')) ?>"
     data-side="<?= $app->settings()->str('assistant.position') === 'left' ? 'left' : 'right' ?>"
     style="--as-offset-desktop:<?= max(-100, min(500, $app->settings()->int('assistant.offset_desktop'))) ?>px;--as-offset-mobile:<?= max(-100, min(500, $app->settings()->int('assistant.offset_mobile'))) ?>px"
     data-af-preferences-url="<?= e($url->route('assistant.preferences')) ?>"
     data-t-remove-pref="<?= e(t('Remove filter')) ?>"
     data-af-starters="<?= e($url->route('assistant.starters')) ?>"
     data-af-panel-script="<?= e($url->asset('m', 'assistant', 'assistant.js')) ?>"
     data-af-panel-style="<?= e($url->asset('m', 'assistant', 'panel.css')) ?>"
     data-af-assistant-rate-url="<?= e($url->route('assistant.rate')) ?>"
     data-af-assistant-restart-url="<?= e($url->route('assistant.restart')) ?>"
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
     data-t-discount="<?= e(t('{percent}% off')) ?>"
     data-t-featured="<?= e(t('Shop pick')) ?>"
     data-t-rating="<?= e(t('{score}/5 ({count} reviews)')) ?>"
     data-t-retry="<?= e(t('Try again in {seconds} seconds.')) ?>"
     data-t-busy="<?= e(t('The assistant is writing')) ?>"
     data-t-you="<?= e(t('You')) ?>"
     data-t-bot="<?= e($name !== '' ? $name : t('Assistant')) ?>">
    <button type="button" class="af-assistant-fab" data-af-assistant-open aria-haspopup="dialog" aria-controls="af-assistant-panel" aria-expanded="false" aria-label="<?= e(t('Open the shop assistant')) ?>">
        <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M4 5h16v11H9.500L5 20v-4H4z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <span data-af-unread aria-live="polite" data-label="<?= e(t('Unread replies: {count}')) ?>" hidden></span>
        <span data-af-assistant-resume hidden><?= e(t('Your conversation is still open')) ?></span>
        <span class="af-assistant-fab-label"><?= e($name !== '' ? $name : t('Assistant')) ?></span>
    </button>
    <section class="af-assistant-panel" id="af-assistant-panel" role="dialog" aria-modal="false" aria-labelledby="af-assistant-title" aria-describedby="af-assistant-ai" hidden>
        <header class="af-assistant-head">
            <h2 id="af-assistant-title" class="af-assistant-title"><?= e($name !== '' ? $name : t('Shop assistant')) ?></h2>
            <details class="af-assistant-menu">
                <summary><?= e(t('Options')) ?></summary>
                <button type="button" data-af-layout="normal"><?= e(t('Compact chat')) ?></button>
                <button type="button" data-af-layout="large"><?= e(t('Large chat')) ?></button>
                <button type="button" data-af-layout="side"><?= e(t('Dock at the side')) ?></button>
                <a href="<?= e($url->route('assistant.window')) ?>" target="_blank" rel="noopener" data-af-chat-window><?= e(t('Open in a separate window')) ?></a>
                <?php $photoRoute = $app->hooks()->apply('assistant.photo_search_route', ''); ?>
                <?php if (is_string($photoRoute) && $photoRoute !== '' && $app->router()->has($photoRoute)) : ?><a href="<?= e($url->route($photoRoute)) ?>"><?= e(t('Search by photo')) ?></a><?php endif; ?>
                <button type="button" data-af-assistant-chip="<?= e(t('Help me choose a gift')) ?>"><?= e(t('Help me choose a gift')) ?></button>
                <?php if ($app->settings()->str('search.sector') === 'fashion') : ?><button type="button" data-af-assistant-chip="<?= e(t('Style advisor')) ?>"><?= e(t('Style advisor')) ?></button><?php endif; ?>
                <form id="af-assistant-budget-preference" data-af-budget hidden>
                    <label><?= e(t('Maximum price per product')) ?> (<?= e($app->settings()->str('shop.currency', 'EUR')) ?>)
                        <input type="number" name="budget" min="0.01" max="1000000" step="0.01" required>
                    </label>
                    <button type="submit"><?= e(t('Remember this budget in my account')) ?></button>
                </form>
                <button type="button" data-af-assistant-rate><?= e(t('Rate conversation')) ?></button>
                <button type="button" data-af-assistant-restart><?= e(t('Start a new conversation')) ?></button>
            </details>
            <button type="button" class="af-assistant-close" data-af-assistant-close aria-label="<?= e(t('Close the assistant')) ?>">
                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>
            </button>
        </header>
        <?php if ($assistant->aiAvailable()) : ?>
        <p class="af-assistant-ai" id="af-assistant-ai"><strong class="af-assistant-ai-badge"><?= e(t('AI')) ?></strong> <?= e(t('You are chatting with an artificial intelligence, not a person. It can make mistakes: check the price and the details before you pay.')) ?></p>
        <?php else : ?>
        <p class="af-assistant-ai" id="af-assistant-ai"><?= e(t('Check the product details and the total before paying.')) ?></p>
        <?php endif; ?>
        <div class="af-assistant-log" data-af-assistant-log role="log" aria-live="polite" aria-relevant="additions">
            <div class="af-assistant-msg is-bot"><p><?= e($assistant->intro()) ?></p></div>
        </div>
        <div class="af-assistant-chips" data-af-criteria hidden></div>
        <div class="af-assistant-chips" data-af-assistant-chips></div>
        <form class="af-assistant-form" data-af-assistant-form autocomplete="off"<?= hook('webmcp.form', 'ask_shop_assistant') ?>>
            <label class="af-sr" for="af-assistant-input"><?= e(t('Write your message')) ?></label>
            <input type="text" id="af-assistant-input" class="af-input" name="message"<?= hook('webmcp.param', 'ask_shop_assistant', 'message') ?> maxlength="1000" placeholder="<?= e(t('Write your message')) ?>" enterkeyhint="send">
            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Send')) ?></button>
        </form>
    </section>
    <form id="af-assistant-personal-rating" class="af-assistant-survey" data-af-assistant-survey hidden>
        <fieldset><legend><?= e(t('Was the conversation helpful?')) ?></legend>
            <label><?= e(t('Reason (optional)')) ?>
                <select name="reason"><option value="">—</option>
                    <option value="products"><?= e(t('Product suggestions')) ?></option>
                    <option value="clarity"><?= e(t('Clarity')) ?></option>
                    <option value="speed"><?= e(t('Response time')) ?></option>
                </select>
            </label>
            <button type="submit" name="vote" value="up"><?= e(t('Useful')) ?></button>
            <button type="submit" name="vote" value="down"><?= e(t('Not useful')) ?></button>
            <button type="button" data-af-survey-close><?= e(t('Close')) ?></button>
            <span role="status" data-af-survey-status></span>
        </fieldset>
    </form>
</div>
<script src="<?= e($url->asset('m', 'assistant', 'launcher.js')) ?>" defer></script>
