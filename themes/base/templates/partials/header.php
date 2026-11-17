<?php
/**
 * AIFEC — cabecera: logotipo, menú, buscador, cuenta y carrito. Solo pinta los accesos cuya ruta existe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$af_shop = app()->settings()->str('store.name', 'AIFEC');
$af_style = theme_option('header_style', 'classic') === 'centered' ? 'centered' : 'classic';
$af_main = menu('main');
$af_langs = app()->languages();
$af_path = app()->request()->path;
?>
<header class="af-header af-header-<?= e($af_style) ?>">
    <div class="af-container af-header-inner">
        <?php if ($af_main !== []) : ?>
            <button type="button" class="af-icon-btn af-show-sm af-header-burger" data-af-drawer-open="af-menu" aria-controls="af-menu" aria-expanded="false" aria-label="<?= e(t('Open the menu')) ?>">
                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M4 6h16M4 12h16M4 18h16"/></svg>
            </button>
        <?php endif; ?>
        <a class="af-logo" href="<?= e(url('/')) ?>"><?= shop_logo() ?></a>
        <?php if ($af_main !== []) : ?>
            <nav class="af-nav af-hide-sm" aria-label="<?= e(t('Main menu')) ?>">
                <?= $this->partial('partials/nav', ['items' => $af_main]) ?>
            </nav>
        <?php endif; ?>
        <?php if (base_theme_can('search')) : ?>
            <form class="af-search af-hide-sm" role="search" action="<?= e(base_theme_link('search')) ?>" method="get"<?= hook('webmcp.form', 'search_products') ?>>
                <label class="af-sr" for="af-q-header"><?= e(t('Search')) ?></label>
                <input id="af-q-header" class="af-input" type="search" name="q" placeholder="<?= e(t('Search…')) ?>" autocomplete="off"<?= hook('webmcp.param', 'search_products', 'q') ?>>
                <button type="submit" class="af-icon-btn" aria-label="<?= e(t('Search')) ?>">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                </button>
            </form>
        <?php endif; ?>
        <div class="af-header-actions">
            <?= hook('header.actions') ?>
            <?php if (count($af_langs) > 1) : ?>
                <ul class="af-langs af-hide-sm" aria-label="<?= e(t('Language')) ?>">
                    <?php foreach ($af_langs as $af_code) : ?>
                        <li><a href="<?= e(app()->url()->to($af_path, [], $af_code)) ?>" hreflang="<?= e($af_code) ?>" lang="<?= e($af_code) ?>"<?= $af_code === app()->lang() ? ' aria-current="true"' : '' ?>><?= e(strtoupper($af_code)) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (base_theme_can('account')) : ?>
                <a class="af-icon-btn af-hide-sm" href="<?= e(base_theme_link('account')) ?>" aria-label="<?= e(t('My account')) ?>" data-af-account>
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-6 8-6s8 2 8 6"/></svg>
                </a>
            <?php endif; ?>
            <?php if (base_theme_can('cart')) : ?>
                <a class="af-icon-btn af-hide-sm" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart" aria-label="<?= e(t('Cart')) ?>">
                    <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/></svg>
                    <span class="af-cart-count" data-af-cart-count hidden></span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
