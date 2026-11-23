<?php
/**
 * AIFEC — paneles laterales del tema aifec: el menú siempre (con las categorías de la tienda plegadas dentro de «Tienda»), y el buscador y
 * el carrito de la tienda solo si el sitio vende algo o el tema los muestra, y existen (un sitio de producto no los pinta). El telón que los cubre y la zona de avisos breves van siempre.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$af_main = aifec_theme_main_menu();
$af_legal = menu('legal');
$af_cta = aifec_theme_cta();
?>
<div class="af-drawer-backdrop" data-af-drawer-close></div>
<aside class="af-drawer" id="af-menu" aria-label="<?= e(t('Menu')) ?>" tabindex="-1">
    <div class="af-drawer-head">
        <p class="af-drawer-title"><?= e(t('Menu')) ?></p>
        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>
    <div class="af-drawer-body">
        <?php if ($af_main !== []) : ?>
            <nav aria-label="<?= e(t('Main menu')) ?>">
                <?= $this->partial('partials/nav', ['items' => $af_main, 'idp' => 'd']) ?>
            </nav>
        <?php endif; ?>
        <?php if ($af_cta !== null) : ?>
            <p><a class="af-btn af-btn-fill af-btn-block" href="<?= e($af_cta['url']) ?>"><?= e($af_cta['text']) ?></a></p>
        <?php endif; ?>
        <?php if ($af_legal !== []) : ?>
            <nav class="af-small" aria-label="<?= e(t('Legal links')) ?>">
                <?= $this->partial('partials/nav', ['items' => $af_legal]) ?>
            </nav>
        <?php endif; ?>
    </div>
</aside>
<?php if (aifec_theme_shop('search')) : ?>
<aside class="af-drawer af-drawer-top" id="af-search" aria-label="<?= e(t('Search')) ?>" tabindex="-1">
    <div class="af-drawer-head">
        <p class="af-drawer-title"><?= e(t('Search')) ?></p>
        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>
    <form class="af-drawer-body af-search-form" role="search" action="<?= e(route('search')) ?>" method="get"<?= hook('webmcp.form', 'search_products') ?>>
        <label class="af-sr" for="af-q-drawer"><?= e(t('Search')) ?></label>
        <input id="af-q-drawer" class="af-input" type="search" name="q" placeholder="<?= e(t('Search…')) ?>" autocomplete="off"<?= hook('webmcp.param', 'search_products', 'q') ?>>
        <button type="submit" class="af-btn af-btn-primary"><?= e(t('Search')) ?></button>
    </form>
</aside>
<?php endif; ?>
<?php if (aifec_theme_shop('cart')) : ?>
<aside class="af-drawer af-drawer-right" id="af-cart" aria-label="<?= e(t('Cart')) ?>" tabindex="-1">
    <div class="af-drawer-head">
        <p class="af-drawer-title"><?= e(t('Cart')) ?></p>
        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>">
            <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 6l12 12M18 6 6 18"/></svg>
        </button>
    </div>
    <div class="af-drawer-body">
        <p class="af-muted" data-af-cart-empty><?= e(t('Your cart is empty.')) ?></p>
        <ul class="af-mini-cart" data-af-cart-lines></ul>
    </div>
    <div class="af-drawer-foot" data-af-cart-actions hidden>
        <p class="af-totals-row" data-af-cart-total-row hidden><span><?= e(t('Total')) ?></span> <strong data-af-cart-total></strong></p>
        <a class="af-btn af-btn-block" href="<?= e(route('cart')) ?>"><?= e(t('View cart')) ?></a>
        <?php if (app()->router()->has('checkout')) : ?>
            <a class="af-btn af-btn-primary af-btn-block" href="<?= e(route('checkout')) ?>"><?= e(t('Checkout')) ?></a>
        <?php endif; ?>
    </div>
</aside>
<?php endif; ?>
<div class="af-toasts" id="af-toasts" aria-live="polite"></div>
