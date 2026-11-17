<?php
/**
 * AIFEC — AI-First E-Commerce.
 *
 * La página del carrito: líneas con su cantidad, «Seguir comprando» (a la última categoría vista, ver catalog/assets/continue.js,
 * o a la portada), cupón, estimación del envío, cuánto falta para el envío gratis, desglose, el botón de finalizar la compra con
 * las líneas de confianza debajo y «También te puede interesar». Vacío: un mensaje, el buscador y las categorías principales.
 * Es una página privada: no se indexa y no se guarda en ninguna caché. Los formularios van a las rutas cart.update,
 * cart.remove, cart.coupon y cart.destination y funcionan igual sin JavaScript.
 *
 * Huecos: cart.before, cart.lines.after, cart.totals.after y cart.after.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $page
 * @var array<string,mixed> $cart  Cart::summary()
 * @var array<string,mixed> $flash avisos de un solo uso de la sesión del cliente (cart_error)
 */

declare(strict_types=1);

use Aifec\Modules\Orders\Front\CartPage;

$this->layout('layout', ['page' => $page]);
$af_v = CartPage::data(app(), $cart);
$af_messages = [];
if (isset($flash['cart_error']) && is_string($flash['cart_error']) && $flash['cart_error'] !== '') {
    $af_messages[] = ['bad', $flash['cart_error']];
}
foreach ($cart['problems'] as $af_problem) {
    $af_messages[] = ['bad', (string) $af_problem];
}
$af_dest = $af_v['destination'];

// P100: lo que una tienda de las de siempre pone alrededor del carrito, con datos de la tienda (Aifec\Front\StoreInfo):
// cuánto falta para el envío gratis, las líneas de confianza bajo «Finalizar compra», «También te puede interesar» de la
// primera línea (sin lo que ya está en el carrito) y, con el carrito vacío, el buscador y las categorías principales.
$af_app = app();
$af_free = \Aifec\Front\StoreInfo::freeShipping($af_app, $cart);
$af_info = \Aifec\Front\StoreInfo::data($af_app, true);
$af_trust = $af_app->view()->exists('catalog::front/partials/trust') ? $af_info['trust'] : [];
$af_in_cart = [];
foreach ($cart['lines'] as $af_cart_line) {
    $af_in_cart[(int) $af_cart_line['product_id']] = true;
}
$af_related = [];
if ($af_in_cart !== [] && $af_app->has('catalog')) {
    foreach (\Aifec\Modules\Catalog\Front\Related::forId($af_app, (int) array_key_first($af_in_cart), 4 + count($af_in_cart)) as $af_card) {
        if (!isset($af_in_cart[(int) $af_card['id']]) && count($af_related) < 4) {
            $af_related[] = $af_card;
        }
    }
}
$af_categories = $af_v['lines'] === [] ? $af_info['categories'] : [];
$af_search = $af_v['lines'] === [] && $af_app->router()->has('search') ? route('search') : '';
?>
<div class="af-container af-section" data-af-cart-page>
    <h1 class="af-title"><?= e(t('Your cart')) ?></h1>
    <?= $this->partial('partials/messages', ['messages' => $af_messages]) ?>
    <?= hook('cart.before', $cart) ?>
<?php if ($af_v['lines'] === []) : ?>
    <div class="af-card af-empty af-cart-empty">
        <p class="af-empty-title"><?= e(t('Your cart is empty')) ?></p>
        <p class="af-muted"><?= e(t('Browse the shop and add something you like.')) ?></p>
<?php if ($af_search !== '') : ?>
        <form class="af-cart-search" method="get" action="<?= e($af_search) ?>" role="search">
            <label class="af-sr" for="af-cart-q"><?= e(t('Search the shop')) ?></label>
            <input id="af-cart-q" class="af-input" type="search" name="q" placeholder="<?= e(t('What are you looking for?')) ?>" autocomplete="off">
            <button type="submit" class="af-btn"><?= e(t('Search')) ?></button>
        </form>
<?php endif; ?>
<?php if ($af_categories !== []) : ?>
        <nav aria-label="<?= e(t('Shop by category')) ?>">
            <ul class="af-cart-empty-cats">
<?php foreach ($af_categories as $af_category) : ?>
                <li><a class="af-btn af-btn-ghost af-btn-sm" href="<?= e($af_category['url']) ?>"><?= e($af_category['name']) ?></a></li>
<?php endforeach; ?>
            </ul>
        </nav>
<?php endif; ?>
        <p><a class="af-btn af-btn-primary" href="<?= e($af_v['shop_url']) ?>"><?= e(t('Back to the shop')) ?></a></p>
    </div>
<?php else : ?>
    <div class="af-split">
        <div class="af-stack">
            <div class="af-cart">
                <ul class="af-cart-lines">
<?php foreach ($af_v['lines'] as $af_n => $af_line) : ?>
                    <li class="af-cart-line" data-af-cart-line="<?= e($af_line['key']) ?>">
                        <a class="af-cart-line-image" href="<?= e($af_line['url']) ?>" tabindex="-1" aria-hidden="true"><?= image_tag($af_line['image'], 'thumb', ['alt' => '']) ?></a>
                        <div class="af-cart-line-info">
                            <a class="af-cart-line-name" href="<?= e($af_line['url']) ?>"><?= e($af_line['name']) ?></a>
<?php if ($af_line['variant_label'] !== '') : ?>
                            <p class="af-small af-muted"><?= e($af_line['variant_label']) ?></p>
<?php endif; ?>
                            <p class="af-small af-muted"><?= e($af_line['unit_price_formatted']) ?></p>
<?php foreach ($af_line['warnings'] as $af_warning) : ?>
                            <p class="af-small af-error" role="alert"><?= e((string) $af_warning) ?></p>
<?php endforeach; ?>
                            <div class="af-row">
                                <form class="af-row" method="post" action="<?= e(route('cart.update')) ?>"<?= hook('webmcp.form', 'update_cart_line', ['n' => (int) $af_n + 1, 'name' => $af_line['name']]) ?>>
                                    <input type="hidden" name="key" value="<?= e($af_line['key']) ?>"<?= hook('webmcp.param', 'update_cart_line', 'key') ?>>
                                    <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => $af_line['qty'], 'min' => 1, 'max' => (int) $af_line['max_qty'] > 0 ? (int) $af_line['max_qty'] : null, 'tool' => 'update_cart_line', 'of' => (string) $af_line['name']]) ?>
                                    <button type="submit" class="af-btn af-btn-sm"><?= e(t('Update')) ?></button>
                                </form>
                                <form method="post" action="<?= e(route('cart.remove')) ?>">
                                    <input type="hidden" name="key" value="<?= e($af_line['key']) ?>">
                                    <button type="submit" class="af-btn af-btn-ghost af-btn-sm"><?= e(t('Remove')) ?></button>
                                </form>
                            </div>
                        </div>
                        <p class="af-cart-line-total"><?= e($af_line['total_formatted']) ?></p>
                    </li>
<?php endforeach; ?>
                </ul>
            </div>
            <div class="af-cart-tools">
                <a class="af-btn af-btn-ghost" href="<?= e($af_v['shop_url']) ?>" data-af-continue><?= e(t('Continue shopping')) ?></a>
            </div>
            <?= hook('cart.lines.after', $cart) ?>
<?php if ($af_v['coupons_enabled']) : ?>
            <section class="af-card af-stack" aria-labelledby="af-cart-coupon-h">
                <h2 id="af-cart-coupon-h"><?= e(t('Coupon')) ?></h2>
                <form class="af-row" method="post" action="<?= e(route('cart.coupon')) ?>"<?= hook('webmcp.form', 'apply_coupon') ?>>
                    <label class="af-sr" for="af-cart-coupon"><?= e(t('Coupon code')) ?></label>
                    <input id="af-cart-coupon" class="af-input" type="text" name="code" maxlength="40" autocomplete="off" placeholder="<?= e(t('Coupon code')) ?>"<?= hook('webmcp.param', 'apply_coupon', 'code') ?>>
                    <button type="submit" class="af-btn"><?= e(t('Apply')) ?></button>
                </form>
<?php if ($cart['coupons'] !== []) : ?>
                <ul class="af-stack">
<?php foreach ($cart['coupons'] as $af_coupon) : ?>
                    <li class="af-row" data-af-coupon="<?= e($af_coupon['code']) ?>">
                        <span class="af-badge"><?= e($af_coupon['code']) ?></span>
                        <span><?= e($af_coupon['label']) ?></span>
<?php if ((int) $af_coupon['amount'] > 0) : ?>
                        <strong>-<?= e(money((int) $af_coupon['amount'], (string) $cart['currency'])) ?></strong>
<?php endif; ?>
                        <form method="post" action="<?= e(route('cart.coupon')) ?>">
                            <input type="hidden" name="remove" value="<?= e($af_coupon['code']) ?>">
                            <button type="submit" class="af-btn af-btn-ghost af-btn-sm"><?= e(t('Remove')) ?></button>
                        </form>
                    </li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
            </section>
<?php endif; ?>
<?php if ($af_v['estimate']) : ?>
            <section class="af-card af-stack" aria-labelledby="af-cart-ship-h">
                <h2 id="af-cart-ship-h"><?= e(t('Calculate shipping')) ?></h2>
                <form class="af-stack" method="post" action="<?= e(route('cart.destination')) ?>"<?= hook('webmcp.form', 'calculate_shipping') ?>>
                    <input type="hidden" name="region" value=""<?= hook('webmcp.param', 'calculate_shipping', 'region') ?>>
                    <div class="af-grid af-cols-2">
                        <div class="af-field">
                            <label for="af-cart-country"><?= e(t('Country')) ?></label>
                            <select id="af-cart-country" class="af-select" name="country" autocomplete="country"<?= hook('webmcp.param', 'calculate_shipping', 'country') ?>>
<?php foreach ($af_v['countries'] as $af_iso => $af_name) : ?>
                                <option value="<?= e($af_iso) ?>"<?= (string) $af_iso === (string) $af_dest['country'] ? ' selected' : '' ?>><?= e($af_name) ?></option>
<?php endforeach; ?>
                            </select>
                        </div>
                        <div class="af-field">
                            <label for="af-cart-postcode"><?= e(t('Postcode')) ?></label>
                            <input id="af-cart-postcode" class="af-input" type="text" name="postcode" value="<?= e($af_dest['postcode']) ?>" maxlength="12" autocomplete="postal-code"<?= hook('webmcp.param', 'calculate_shipping', 'postcode') ?>>
                        </div>
                    </div>
                    <div><button type="submit" class="af-btn"><?= e(t('Calculate')) ?></button></div>
                </form>
<?php if ($af_v['estimated']) : ?>
<?php if ($af_v['options'] === []) : ?>
                <p class="af-alert af-alert-warn" role="alert"><?= e(t('We cannot deliver to this destination')) ?></p>
<?php else : ?>
                <ul class="af-stack" data-af-shipping-options>
<?php foreach ($af_v['options'] as $af_option) : ?>
                    <li class="af-row"><strong><?= e($af_option['name']) ?></strong><?php if ($af_option['delay'] !== '') : ?> <span class="af-muted"><?= e($af_option['delay']) ?></span><?php endif; ?> <span><?= e($af_option['price']) ?></span></li>
<?php endforeach; ?>
                </ul>
<?php endif; ?>
<?php endif; ?>
            </section>
<?php endif; ?>
        </div>
        <aside class="af-summary af-card af-stack" aria-labelledby="af-cart-summary-h">
            <h2 id="af-cart-summary-h"><?= e(t('Order summary')) ?></h2>
<?php if ($af_free !== null) : ?>
            <div class="af-free" data-af-free-shipping data-missing="<?= e($af_free['missing']) ?>">
<?php if ($af_free['reached']) : ?>
                <p><strong><?= e(t('Your order ships for free')) ?></strong></p>
<?php else : ?>
                <p><?= e(t('Add {amount} more to get free shipping', ['amount' => money($af_free['missing'], (string) $cart['currency'])])) ?></p>
<?php endif; ?>
                <progress max="<?= e($af_free['threshold']) ?>" value="<?= e(min($af_free['total'], $af_free['threshold'])) ?>" aria-label="<?= e(t('Progress towards free shipping')) ?>"><?= e($af_free['percent']) ?> %</progress>
            </div>
<?php endif; ?>
            <?= $this->partial('orders::front/totals', ['v' => $af_v['view']]) ?>
            <?= hook('cart.totals.after', $cart) ?>
<?php if ($af_v['blocked']) : ?>
            <button type="button" class="af-btn af-btn-primary af-btn-block af-btn-lg" disabled aria-describedby="af-cart-blocked"><?= e(t('Checkout')) ?></button>
            <p class="af-small af-muted" id="af-cart-blocked"><?= e(t('Solve the problems above to continue.')) ?></p>
<?php else : ?>
            <a class="af-btn af-btn-primary af-btn-block af-btn-lg" href="<?= e($af_v['checkout_url']) ?>"><?= e(t('Checkout')) ?></a>
<?php endif; ?>
<?php if ($af_trust !== []) : ?>
            <?= $this->partial('catalog::front/partials/trust', ['lines' => $af_trust, 'class' => 'af-cart-trust']) ?>
<?php endif; ?>
        </aside>
    </div>
<?php if ($af_related !== []) : ?>
    <section class="af-section af-cart-more" id="af-related" aria-labelledby="af-cart-related-h">
        <h2 id="af-cart-related-h"><?= e(t('You may also like')) ?></h2>
        <div class="af-grid af-cols-4">
<?php foreach ($af_related as $af_card) : ?>
            <?= $this->partial('partials/product-card', ['product' => $af_card]) ?>
<?php endforeach; ?>
        </div>
    </section>
<?php endif; ?>
<?php endif; ?>
    <?= hook('cart.after', $cart) ?>
</div>
