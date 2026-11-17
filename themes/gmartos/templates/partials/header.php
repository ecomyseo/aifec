<?php
/**
 * AIFEC — cabecera de gmartos: franja de aviso, logotipo, menú, «Mi cuenta», carrito con contador y el botón oscuro de
 * contacto; en móvil, logotipo, carrito y menú. Solo pinta los accesos cuya ruta existe.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$gm_main = gmartos_clean_menu(menu('main'));
$gm_announce = (int) theme_option('announce_show', 1) === 1;
$gm_note = gmartos_text('announce_text', t('G. Martos evolves into Ecom Experts. Same contact, same domain.'));
$gm_label = gmartos_text('announce_label', t('NEW IDENTITY'));
$gm_aside = gmartos_text('announce_aside', app()->request()->host);
$gm_btn_url = gmartos_link((string) theme_option('header_button_link', ''));
$gm_btn_text = gmartos_text('header_button_text', t('Get in touch'));
$gm_langs = app()->languages();
$gm_path = app()->request()->path;
?>
<?php if ($gm_announce && trim($gm_note) !== '') : ?>
<div class="gm-announce">
    <div class="af-container gm-announce-in">
        <span class="gm-announce-label"><?= e($gm_label) ?></span>
        <span><?= e($gm_note) ?></span>
        <?php if ($gm_aside !== '') : ?>
            <span class="gm-announce-aside"><?= e($gm_aside) ?></span>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<header class="af-header gm-header">
    <div class="af-container af-header-inner">
        <a class="af-logo" href="<?= e(url('/')) ?>"><?= gmartos_logo() ?></a>
        <?php if ($gm_main !== []) : ?>
            <nav class="af-nav af-hide-sm" aria-label="<?= e(t('Main menu')) ?>">
                <?= $this->partial('partials/nav', ['items' => $gm_main]) ?>
            </nav>
        <?php endif; ?>
        <div class="af-header-actions gm-actions">
            <?= hook('header.actions') ?>
            <?php if (count($gm_langs) > 1) : ?>
                <ul class="af-langs af-hide-sm" aria-label="<?= e(t('Language')) ?>">
                    <?php foreach ($gm_langs as $gm_code) : ?>
                        <li><a href="<?= e(app()->url()->to($gm_path, [], $gm_code)) ?>" hreflang="<?= e($gm_code) ?>" lang="<?= e($gm_code) ?>"<?= $gm_code === app()->lang() ? ' aria-current="true"' : '' ?>><?= e(strtoupper($gm_code)) ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if (base_theme_can('account')) : ?>
                <a class="gm-account af-hide-sm" href="<?= e(base_theme_link('account')) ?>" data-af-account><?= e(t('My account')) ?></a>
            <?php endif; ?>
            <?php if (base_theme_can('cart')) : ?>
                <a class="af-icon-btn" href="<?= e(base_theme_link('cart')) ?>" data-af-drawer-open="af-cart" aria-controls="af-cart" aria-label="<?= e(t('Cart')) ?>">
                    <?= gmartos_svg('bag', 25) ?>
                    <span class="af-cart-count" data-af-cart-count hidden></span>
                </a>
            <?php endif; ?>
            <?php if ($gm_btn_url !== '') : ?>
                <a class="af-btn gm-contact af-hide-sm" href="<?= e($gm_btn_url) ?>"><?= e($gm_btn_text) ?></a>
            <?php endif; ?>
            <?php if ($gm_main !== []) : ?>
                <button type="button" class="af-icon-btn gm-burger af-show-sm" data-af-drawer-open="af-menu" aria-controls="af-menu" aria-expanded="false" aria-label="<?= e(t('Open the menu')) ?>">
                    <?= gmartos_svg('menu', 27) ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
</header>
