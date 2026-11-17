<?php
/**
 * AIFEC — barra de acciones fija en la parte inferior del móvil del tema aifec: Menú, Demos, Documentación, Buscar y Carrito.
 * Cada acceso solo sale si existe de verdad; el menú va siempre. Todo es real: abre el panel del menú, el del buscador o el del
 * carrito, o lleva a la página.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var bool $filters sin uso en este tema (no hay listados con filtros)
 */

$af_items = aifec_theme_bar_items();
?>
<nav class="af-mobile-bar af-show-sm" aria-label="<?= e(t('Quick actions')) ?>">
    <?php foreach ($af_items as $af_item) : ?>
        <?php if ($af_item['url'] === '') : ?>
            <button type="button" class="af-mobile-bar-item" data-af-drawer-open="<?= e($af_item['drawer']) ?>" aria-controls="<?= e($af_item['drawer']) ?>" aria-expanded="false">
                <span class="af-mobile-bar-icon"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><?= $af_item['icon'] ?></svg></span>
                <span class="af-mobile-bar-label"><?= e($af_item['label']) ?></span>
            </button>
        <?php else : ?>
            <a class="af-mobile-bar-item" href="<?= e($af_item['url']) ?>"<?= $af_item['drawer'] !== '' ? ' data-af-drawer-open="' . e($af_item['drawer']) . '" aria-controls="' . e($af_item['drawer']) . '"' : '' ?>>
                <span class="af-mobile-bar-icon">
                    <svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true" focusable="false"><?= $af_item['icon'] ?></svg>
                    <?php if ($af_item['key'] === 'cart') : ?>
                        <span class="af-cart-count" data-af-cart-count hidden></span>
                    <?php endif; ?>
                </span>
                <span class="af-mobile-bar-label"><?= e($af_item['label']) ?></span>
            </a>
        <?php endif; ?>
    <?php endforeach; ?>
</nav>
