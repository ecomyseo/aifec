<?php
/**
 * AIFEC — barra de acciones fija abajo en el móvil, con la forma de despensa: píldora de buscar y de filtros, el carrito
 * como botón redondeado de color con su número y la cuenta. Cada acceso solo sale si existe de verdad; Filtros solo
 * donde la página trae filtros que funcionan.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var bool $filters
 */

$ds_filters = !empty($filters);
?>
<nav class="ds-bar af-mobile-bar af-show-sm" aria-label="<?= e(t('Quick actions')) ?>">
    <?php if (base_theme_can('search')) : ?>
        <a class="ds-bar-item af-mobile-bar-item" href="<?= e(base_theme_link('search')) ?>" data-af-drawer-open="af-search" aria-controls="af-search">
            <span class="ds-bar-icon af-mobile-bar-icon"><?= despensa_svg('search', 24) ?></span>
            <span class="ds-bar-label af-mobile-bar-label"><?= e(t('Search')) ?></span>
        </a>
    <?php endif; ?>
    <?php if ($ds_filters) : ?>
        <button type="button" class="ds-bar-item af-mobile-bar-item" data-af-drawer-open="af-filters" aria-controls="af-filters" aria-expanded="false">
            <span class="ds-bar-icon af-mobile-bar-icon"><?= despensa_svg('filter', 24) ?></span>
            <span class="ds-bar-label af-mobile-bar-label"><?= e(t('Filters')) ?></span>
        </button>
    <?php endif; ?>
    <?php if (base_theme_can('cart')) : ?>
        <a class="ds-bar-item ds-bar-cart af-mobile-bar-item" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart">
            <span class="ds-bar-icon af-mobile-bar-icon">
                <?= despensa_svg('basket', 24) ?>
                <span class="af-cart-count" data-af-cart-count hidden></span>
            </span>
            <span class="ds-bar-label af-mobile-bar-label"><?= e(t('Cart')) ?></span>
        </a>
    <?php endif; ?>
    <?php if (base_theme_can('account')) : ?>
        <a class="ds-bar-item af-mobile-bar-item" href="<?= e(base_theme_link('account')) ?>">
            <span class="ds-bar-icon af-mobile-bar-icon"><?= despensa_svg('user', 24) ?></span>
            <span class="ds-bar-label af-mobile-bar-label"><?= e(t('Account')) ?></span>
        </a>
    <?php endif; ?>
</nav>
