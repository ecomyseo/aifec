<?php
/**
 * AIFEC — paneles laterales de taller: el de «Todas las categorías» (también es el menú del móvil), el buscador y el
 * carrito (vacío: lo rellena theme.js), el telón que los cubre y la zona de avisos breves. El panel de filtros lo pinta la
 * propia página de listado (id «af-filters»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$tl_main = taller_clean_menu(menu('main'));
$tl_legal = menu('legal');
?>
<div class="af-drawer-backdrop" data-af-drawer-close></div>
<aside class="tl-cats-panel af-drawer" id="tl-cats" aria-label="<?= e(t('All categories')) ?>" tabindex="-1">
    <div class="af-drawer-head">
        <p class="af-drawer-title"><?= e(t('All categories')) ?></p>
        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= taller_svg('close') ?></button>
    </div>
    <div class="af-drawer-body">
        <?php if ($tl_main !== []) : ?>
            <nav aria-label="<?= e(t('Main menu')) ?>">
                <?= $this->partial('partials/cats', ['items' => $tl_main]) ?>
            </nav>
        <?php endif; ?>
        <?php if (base_theme_can('account')) : ?>
            <p><a class="af-btn af-btn-block" href="<?= e(base_theme_link('account')) ?>"><?= e(t('My account')) ?></a></p>
        <?php endif; ?>
        <?php if ($tl_legal !== []) : ?>
            <nav class="af-small" aria-label="<?= e(t('Legal links')) ?>">
                <?= $this->partial('partials/nav', ['items' => $tl_legal]) ?>
            </nav>
        <?php endif; ?>
    </div>
</aside>
<?php if (base_theme_can('search')) : ?>
<aside class="af-drawer af-drawer-top" id="af-search" aria-label="<?= e(t('Search')) ?>" tabindex="-1">
    <div class="af-drawer-head">
        <p class="af-drawer-title"><?= e(t('Search')) ?></p>
        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= taller_svg('close') ?></button>
    </div>
    <form class="af-drawer-body af-search-form" role="search" action="<?= e(base_theme_link('search')) ?>" method="get">
        <label class="af-sr" for="af-q-drawer"><?= e(t('Search')) ?></label>
        <input id="af-q-drawer" class="af-input" type="search" name="q" placeholder="<?= e(t('Search by product, brand or reference…')) ?>" autocomplete="off">
        <button type="submit" class="af-btn af-btn-primary"><?= e(t('Search')) ?></button>
    </form>
</aside>
<?php endif; ?>
<?php if (base_theme_can('cart')) : ?>
<aside class="af-drawer af-drawer-right" id="af-cart" aria-label="<?= e(t('Cart')) ?>" tabindex="-1">
    <div class="af-drawer-head">
        <p class="af-drawer-title"><?= e(t('Cart')) ?></p>
        <button type="button" class="af-icon-btn" data-af-drawer-close aria-label="<?= e(t('Close')) ?>"><?= taller_svg('close') ?></button>
    </div>
    <div class="af-drawer-body">
        <p class="af-muted" data-af-cart-empty><?= e(t('Your cart is empty.')) ?></p>
        <ul class="af-mini-cart" data-af-cart-lines></ul>
    </div>
    <div class="af-drawer-foot" data-af-cart-actions hidden>
        <p class="af-totals-row" data-af-cart-total-row hidden><span><?= e(t('Total')) ?></span> <strong data-af-cart-total></strong></p>
        <a class="af-btn af-btn-block" href="<?= e(base_theme_link('cart')) ?>"><?= e(t('View cart')) ?></a>
        <?php if (base_theme_can('checkout')) : ?>
            <a class="af-btn af-btn-primary af-btn-block" href="<?= e(base_theme_link('checkout')) ?>"><?= e(t('Checkout')) ?></a>
        <?php endif; ?>
    </div>
</aside>
<?php endif; ?>
<div class="af-toasts" id="af-toasts" aria-live="polite"></div>
