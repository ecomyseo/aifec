<?php
/**
 * AIFEC — cabecera de despensa: franja con el mensaje de envío; logotipo a la izquierda con un emblema dibujado; menú
 * centrado con desplegables redondeados; y a la derecha el buscador desplegable, la cuenta y el carrito como botón
 * redondeado con el número. Solo pinta los accesos cuya ruta existe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$ds_main = despensa_menu(menu('main'));
$ds_langs = app()->languages();
$ds_path = app()->request()->path;
$ds_strip = (int) theme_option('strip_show', 1) === 1;
$ds_strip_text = trim((string) theme_option('strip_text', ''));
$ds_strip_text = $ds_strip_text !== '' ? $ds_strip_text : t('Packed by hand and shipped with care');
$ds_open_search = theme_option('search_style', 'drop') === 'bar';
$ds_style = theme_option('header_style', 'centered') === 'classic' ? 'classic' : 'centered';
?>
<?php if ($ds_strip) : ?>
<div class="ds-strip"><p class="ds-strip-text"><?= e($ds_strip_text) ?></p></div>
<?php endif; ?>
<header class="ds-header af-header ds-header-<?= e($ds_style) ?>">
    <div class="ds-header-inner af-container">
        <?php if ($ds_main !== []) : ?>
            <button type="button" class="ds-burger af-icon-btn af-show-sm" data-af-drawer-open="af-menu" aria-controls="af-menu" aria-expanded="false" aria-label="<?= e(t('Open the menu')) ?>"><?= despensa_svg('menu') ?></button>
        <?php endif; ?>
        <a class="ds-brand af-logo" href="<?= e(url('/')) ?>">
            <span class="ds-emblem"><?= despensa_emblem(26) ?></span>
            <span class="ds-brand-name"><?= shop_logo() ?></span>
        </a>
        <?php if ($ds_main !== []) : ?>
            <nav class="ds-menu af-nav af-hide-sm" aria-label="<?= e(t('Main menu')) ?>">
                <ul class="ds-menu-list">
                    <?php foreach ($ds_main as $ds_item) : ?>
                        <li class="ds-menu-item<?= $ds_item['children'] !== [] ? ' has-sub' : '' ?>">
                            <?php if ($ds_item['url'] !== '') : ?>
                                <a class="ds-menu-link" href="<?= e($ds_item['url']) ?>"<?= $ds_item['current'] ? ' aria-current="page"' : '' ?>><?= e($ds_item['label']) ?></a>
                            <?php else : ?>
                                <span class="ds-menu-link ds-menu-label"><?= e($ds_item['label']) ?></span>
                            <?php endif; ?>
                            <?php if ($ds_item['children'] !== []) : ?>
                                <div class="ds-sub">
                                    <?php foreach ($ds_item['children'] as $ds_kid) : ?>
                                        <?php if ($ds_kid['url'] !== '') : ?>
                                            <a class="ds-sub-link" href="<?= e($ds_kid['url']) ?>"><?= e($ds_kid['label']) ?></a>
                                        <?php else : ?>
                                            <span class="ds-sub-label"><?= e($ds_kid['label']) ?></span>
                                        <?php endif; ?>
                                        <?php foreach ($ds_kid['children'] as $ds_leaf) : ?>
                                            <?php if ($ds_leaf['url'] !== '') : ?>
                                                <a class="ds-sub-leaf" href="<?= e($ds_leaf['url']) ?>"><?= e($ds_leaf['label']) ?></a>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        <?php endif; ?>
        <div class="ds-tools">
            <?= hook('header.actions') ?>
            <?php if (count($ds_langs) > 1) : ?>
                <ul class="ds-langs af-hide-sm" aria-label="<?= e(t('Language')) ?>">
                    <?php foreach ($ds_langs as $ds_code) : ?>
                        <li><a href="<?= e(app()->url()->to($ds_path, [], $ds_code)) ?>" hreflang="<?= e($ds_code) ?>" lang="<?= e($ds_code) ?>"<?= $ds_code === app()->lang() ? ' aria-current="true"' : '' ?>><?= e(strtoupper($ds_code)) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (base_theme_can('search')) : ?>
                <?php if ($ds_open_search) : ?>
                    <form class="ds-search-bar af-search af-hide-sm" role="search" action="<?= e(base_theme_link('search')) ?>" method="get">
                        <label class="af-sr" for="af-q-header"><?= e(t('Search')) ?></label>
                        <input id="af-q-header" class="ds-search-input af-input" type="search" name="q" placeholder="<?= e(t('Search…')) ?>" autocomplete="off">
                        <button type="submit" class="ds-tool" aria-label="<?= e(t('Search')) ?>"><?= despensa_svg('search') ?></button>
                    </form>
                <?php else : ?>
                    <details class="ds-search af-hide-sm">
                        <summary class="ds-tool" aria-label="<?= e(t('Search')) ?>"><?= despensa_svg('search') ?></summary>
                        <form class="ds-search-pop" role="search" action="<?= e(base_theme_link('search')) ?>" method="get">
                            <label class="af-sr" for="af-q-header"><?= e(t('Search')) ?></label>
                            <input id="af-q-header" class="ds-search-input af-input" type="search" name="q" placeholder="<?= e(t('Search…')) ?>" autocomplete="off">
                            <button type="submit" class="af-btn af-btn-primary"><?= e(t('Search')) ?></button>
                        </form>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
            <?php if (base_theme_can('account')) : ?>
                <a class="ds-tool af-hide-sm" href="<?= e(base_theme_link('account')) ?>" aria-label="<?= e(t('My account')) ?>" data-af-account><?= despensa_svg('user') ?></a>
            <?php endif; ?>
            <?php if (base_theme_can('cart')) : ?>
                <a class="ds-cart af-hide-sm" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart" aria-label="<?= e(t('Cart')) ?>">
                    <?= despensa_svg('basket', 20) ?>
                    <span class="ds-cart-label"><?= e(t('Cart')) ?></span>
                    <span class="af-cart-count" data-af-cart-count hidden></span>
                </a>
            <?php endif; ?>
        </div>
    </div>
</header>
