<?php
/**
 * AIFEC — cabecera de taller en dos filas. Arriba: logotipo, buscador grande con botón en el centro, teléfono de ayuda,
 * cuenta y carrito con su importe. Debajo: barra oscura con las categorías en horizontal y el botón «Todas las categorías»,
 * que abre un panel. En móvil queda una sola fila pegajosa (menú, logotipo, teléfono) y la barra de acciones inferior.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$tl_main = taller_clean_menu(menu('main'));
$tl_phone = (int) theme_option('header_phone', 1) === 1 ? taller_phone() : '';
$tl_tel = $tl_phone !== '' ? taller_tel($tl_phone) : '';
$tl_amount = (int) theme_option('cart_amount', 1) === 1;
$tl_yellow = theme_option('nav_bar', 'dark') === 'yellow';
$tl_langs = app()->languages();
$tl_path = app()->request()->path;
?>
<header class="tl-header af-header">
    <div class="tl-top af-container">
        <?php if ($tl_main !== []) : ?>
            <button type="button" class="tl-burger af-icon-btn af-show-sm" data-af-drawer-open="tl-cats" aria-controls="tl-cats" aria-expanded="false" aria-label="<?= e(t('Open the menu')) ?>">
                <?= taller_svg('menu') ?>
            </button>
        <?php endif; ?>
        <a class="tl-logo af-logo" href="<?= e(url('/')) ?>"><?= shop_logo() ?></a>
        <?php if (base_theme_can('search')) : ?>
            <form class="tl-search af-hide-sm" role="search" action="<?= e(base_theme_link('search')) ?>" method="get">
                <label class="af-sr" for="tl-q-header"><?= e(t('Search')) ?></label>
                <input id="tl-q-header" class="tl-search-input af-input" type="search" name="q" placeholder="<?= e(t('Search by product, brand or reference…')) ?>" autocomplete="off">
                <button type="submit" class="tl-search-btn af-btn af-btn-primary"><?= taller_svg('search', 20) ?><span><?= e(t('Search')) ?></span></button>
            </form>
        <?php endif; ?>
        <?php if ($tl_tel !== '') : ?>
            <a class="tl-phone" href="<?= e($tl_tel) ?>">
                <?= taller_svg('phone', 24) ?>
                <span class="tl-phone-text af-hide-sm"><small><?= e(t('Need help?')) ?></small><strong><?= e($tl_phone) ?></strong></span>
                <span class="af-sr af-show-sm"><?= e(t('Call us')) ?></span>
            </a>
        <?php endif; ?>
        <div class="tl-actions af-header-actions">
            <?= hook('header.actions') ?>
            <?php if (count($tl_langs) > 1) : ?>
                <ul class="af-langs af-hide-sm" aria-label="<?= e(t('Language')) ?>">
                    <?php foreach ($tl_langs as $tl_code) : ?>
                        <li><a href="<?= e(app()->url()->to($tl_path, [], $tl_code)) ?>" hreflang="<?= e($tl_code) ?>" lang="<?= e($tl_code) ?>"<?= $tl_code === app()->lang() ? ' aria-current="true"' : '' ?>><?= e(strtoupper($tl_code)) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (base_theme_can('account')) : ?>
                <a class="tl-act af-hide-sm" href="<?= e(base_theme_link('account')) ?>" data-af-account>
                    <?= taller_svg('user', 24) ?>
                    <span class="tl-act-label"><?= e(t('My account')) ?></span>
                </a>
            <?php endif; ?>
            <?php if (base_theme_can('cart')) : ?>
                <a class="tl-cart af-hide-sm" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart">
                    <span class="tl-cart-ico"><?= taller_svg('cart', 26) ?><span class="af-cart-count" data-af-cart-count hidden></span></span>
                    <span class="tl-cart-text"><small><?= e(t('Cart')) ?></small><?php if ($tl_amount) : ?><strong class="tl-cart-amount" data-tl-cart-total></strong><?php endif; ?></span>
                </a>
            <?php endif; ?>
        </div>
    </div>
    <?php if ($tl_main !== []) : ?>
        <div class="tl-bar<?= $tl_yellow ? ' tl-bar-yellow' : '' ?> af-hide-sm">
            <div class="tl-bar-inner af-container">
                <button type="button" class="tl-allcats" data-af-drawer-open="tl-cats" aria-controls="tl-cats" aria-expanded="false"><?= taller_svg('grid', 20) ?><span><?= e(t('All categories')) ?></span></button>
                <nav class="tl-nav" aria-label="<?= e(t('Main menu')) ?>">
                    <ul class="tl-menu">
                        <?php foreach ($tl_main as $tl_item) : ?>
                            <li class="tl-menu-item<?= $tl_item['children'] !== [] ? ' has-sub' : '' ?>">
                                <?php if ($tl_item['url'] !== '') : ?>
                                    <a class="tl-menu-link" href="<?= e($tl_item['url']) ?>"<?= $tl_item['current'] ? ' aria-current="page"' : '' ?>><?= e($tl_item['label']) ?></a>
                                <?php else : ?>
                                    <span class="tl-menu-link tl-menu-label" tabindex="0"><?= e($tl_item['label']) ?></span>
                                <?php endif; ?>
                                <?php if ($tl_item['children'] !== []) : ?>
                                    <ul class="tl-sub">
                                        <?php foreach ($tl_item['children'] as $tl_kid) : ?>
                                            <?php if ($tl_kid['url'] !== '') : ?>
                                                <li><a href="<?= e($tl_kid['url']) ?>"><?= e($tl_kid['label']) ?></a></li>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </nav>
            </div>
        </div>
    <?php endif; ?>
</header>
