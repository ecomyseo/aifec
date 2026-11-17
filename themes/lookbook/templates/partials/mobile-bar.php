<?php
/**
 * AIFEC — barra de acciones fija en la parte inferior del móvil en Lookbook: pestañas escritas, sin iconos, igual que la
 * cabecera. Buscar, Filtros (solo donde la página trae filtros que funcionan), Carrito con su contador y Cuenta. Cada acceso
 * solo sale si existe de verdad.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var bool $filters
 */

$lb_filters = !empty($filters);
?>
<nav class="af-mobile-bar af-show-sm lb-dock" aria-label="<?= e(t('Quick actions')) ?>">
    <?php if (base_theme_can('search')) : ?>
        <a class="af-mobile-bar-item" href="<?= e(base_theme_link('search')) ?>" data-af-drawer-open="af-search" aria-controls="af-search"><?= e(t('Search')) ?></a>
    <?php endif; ?>
    <?php if ($lb_filters) : ?>
        <button type="button" class="af-mobile-bar-item" data-af-drawer-open="af-filters" aria-controls="af-filters" aria-expanded="false"><?= e(t('Filters')) ?></button>
    <?php endif; ?>
    <?php if (base_theme_can('cart')) : ?>
        <a class="af-mobile-bar-item lb-bag" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart"><?= e(t('Cart')) ?> <span class="lb-bag-n" data-af-cart-count hidden></span></a>
    <?php endif; ?>
    <?php if (base_theme_can('account')) : ?>
        <a class="af-mobile-bar-item" href="<?= e(base_theme_link('account')) ?>"><?= e(t('Account')) ?></a>
    <?php endif; ?>
</nav>
