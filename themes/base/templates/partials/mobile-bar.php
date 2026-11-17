<?php
/**
 * AIFEC — barra de acciones fija en la parte inferior del móvil: Buscar, Carrito (con contador), Filtros y Cuenta.
 * Cada acceso solo sale si existe de verdad; Filtros solo donde la página trae filtros que funcionan.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var bool $filters
 */

$af_filters = !empty($filters);
?>
<nav class="af-mobile-bar af-show-sm" aria-label="<?= e(t('Quick actions')) ?>">
    <?php if (base_theme_can('search')) : ?>
        <a class="af-mobile-bar-item" href="<?= e(base_theme_link('search')) ?>" data-af-drawer-open="af-search" aria-controls="af-search">
            <span class="af-mobile-bar-icon"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg></span>
            <span class="af-mobile-bar-label"><?= e(t('Search')) ?></span>
        </a>
    <?php endif; ?>
    <?php if ($af_filters) : ?>
        <button type="button" class="af-mobile-bar-item" data-af-drawer-open="af-filters" aria-controls="af-filters" aria-expanded="false">
            <span class="af-mobile-bar-icon"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M4 6h8M18 6h2M4 12h2M12 12h8M4 18h10M20 18h0"/><circle cx="15" cy="6" r="2"/><circle cx="9" cy="12" r="2"/><circle cx="17" cy="18" r="2"/></svg></span>
            <span class="af-mobile-bar-label"><?= e(t('Filters')) ?></span>
        </button>
    <?php endif; ?>
    <?php if (base_theme_can('cart')) : ?>
        <a class="af-mobile-bar-item" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart">
            <span class="af-mobile-bar-icon">
                <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 10V6a3 3 0 0 1 6 0v4"/></svg>
                <span class="af-cart-count" data-af-cart-count hidden></span>
            </span>
            <span class="af-mobile-bar-label"><?= e(t('Cart')) ?></span>
        </a>
    <?php endif; ?>
    <?php if (base_theme_can('account')) : ?>
        <a class="af-mobile-bar-item" href="<?= e(base_theme_link('account')) ?>">
            <span class="af-mobile-bar-icon"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.5-6 8-6s8 2 8 6"/></svg></span>
            <span class="af-mobile-bar-label"><?= e(t('Account')) ?></span>
        </a>
    <?php endif; ?>
</nav>
