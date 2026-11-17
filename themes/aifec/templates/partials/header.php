<?php
/**
 * AIFEC — cabecera del tema aifec: el nombre (o el logotipo), el menú en UNA sola línea (con «Tienda» desplegando las categorías del
 * sitio), el buscador, la cuenta y el carrito (solo si el sitio vende algo y existen esas rutas) y el botón «Ver demos». Dos
 * disposiciones: nombre a la izquierda con el menú al lado, o nombre centrado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$af_style = theme_option('header_style', 'bar') === 'centered' ? 'centered' : 'bar';
$af_main = aifec_theme_main_menu();
$af_langs = app()->languages();
$af_path = app()->request()->path;
$af_cta = aifec_theme_cta();
$af_tools = [];
foreach (['search', 'account', 'cart'] as $af_tool) {
    if (aifec_theme_shop($af_tool)) {
        $af_tools[] = $af_tool;
    }
}
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
                <?= $this->partial('partials/nav', ['items' => $af_main, 'idp' => 'h']) ?>
            </nav>
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
            <?php if ($af_tools !== []) : ?>
                <ul class="af-header-tools af-hide-sm">
                    <?php if (in_array('search', $af_tools, true)) : ?>
                        <li class="af-header-tool">
                            <a class="af-icon-btn" href="<?= e(route('search')) ?>" data-af-drawer-open="af-search" aria-controls="af-search" aria-label="<?= e(t('Search')) ?>">
                                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (in_array('account', $af_tools, true)) : ?>
                        <li class="af-header-tool">
                            <a class="af-icon-btn" href="<?= e(route('account')) ?>" aria-label="<?= e(t('My account')) ?>" data-af-account>
                                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-6 8-6s8 2 8 6"/></svg>
                            </a>
                        </li>
                    <?php endif; ?>
                    <?php if (in_array('cart', $af_tools, true)) : ?>
                        <li class="af-header-tool">
                            <a class="af-icon-btn" href="<?= e(route('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart" aria-label="<?= e(t('Cart')) ?>">
                                <svg viewBox="0 0 24 24" width="22" height="22" aria-hidden="true" focusable="false"><path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/></svg>
                                <span class="af-cart-count" data-af-cart-count hidden></span>
                            </a>
                        </li>
                    <?php endif; ?>
                </ul>
            <?php endif; ?>
            <?php if ($af_cta !== null) : ?>
                <div class="af-header-cta">
                    <a class="af-btn af-btn-fill af-btn-sm" href="<?= e($af_cta['url']) ?>"><?= e($af_cta['text']) ?></a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</header>
