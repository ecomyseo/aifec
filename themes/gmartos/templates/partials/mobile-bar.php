<?php
/**
 * AIFEC — barra de acciones fija en la parte inferior del móvil, con la forma de gmartos: una tira blanca con una línea
 * azul sobre el botón del carrito. Buscar, Filtros (solo donde hay filtros que funcionan), Carrito con contador y Cuenta;
 * cada acceso solo sale si existe de verdad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var bool $filters
 */

$gm_filters = !empty($filters);
?>
<nav class="gm-mbar af-mobile-bar af-show-sm" aria-label="<?= e(t('Quick actions')) ?>">
    <?php if (base_theme_can('search')) : ?>
        <a class="af-mobile-bar-item" href="<?= e(base_theme_link('search')) ?>" data-af-drawer-open="af-search" aria-controls="af-search">
            <span class="af-mobile-bar-icon"><?= gmartos_svg('search', 24) ?></span>
            <span class="af-mobile-bar-label"><?= e(t('Search')) ?></span>
        </a>
    <?php endif; ?>
    <?php if ($gm_filters) : ?>
        <button type="button" class="af-mobile-bar-item" data-af-drawer-open="af-filters" aria-controls="af-filters" aria-expanded="false">
            <span class="af-mobile-bar-icon"><?= gmartos_svg('sliders', 24) ?></span>
            <span class="af-mobile-bar-label"><?= e(t('Filters')) ?></span>
        </button>
    <?php endif; ?>
    <?php if (base_theme_can('cart')) : ?>
        <a class="gm-mbar-cart af-mobile-bar-item" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart">
            <span class="af-mobile-bar-icon">
                <?= gmartos_svg('bag', 24) ?>
                <span class="af-cart-count" data-af-cart-count hidden></span>
            </span>
            <span class="af-mobile-bar-label"><?= e(t('Cart')) ?></span>
        </a>
    <?php endif; ?>
    <?php if (base_theme_can('account')) : ?>
        <a class="af-mobile-bar-item" href="<?= e(base_theme_link('account')) ?>">
            <span class="af-mobile-bar-icon"><?= gmartos_svg('user', 24) ?></span>
            <span class="af-mobile-bar-label"><?= e(t('Account')) ?></span>
        </a>
    <?php endif; ?>
</nav>
