<?php
/**
 * AIFEC — pie de gmartos: fondo azul noche con el logotipo y una frase, las columnas de enlaces (categorías principales, el
 * menú «footer», la cuenta, los datos de contacto y las formas de pago encendidas, cada una solo con datos) y, debajo, el © y
 * los enlaces legales en pequeño.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$gm_settings = app()->settings();
$gm_shop = $gm_settings->str('store.name', 'AIFEC');
$gm_email = trim($gm_settings->str('store.email', ''));
$gm_phone = trim($gm_settings->str('store.phone', ''));
$gm_address = trim($gm_settings->str('store.address', ''));
$gm_legal = gmartos_clean_menu(menu('legal'));
$gm_year = gmdate('Y', app()->clock()->now());
$gm_tagline = gmartos_text('footer_tagline', t('Tools for shops|that want to go further.'));
$gm_cols = gmartos_footer_columns();
$gm_columns = hook('footer.columns');
// P100: las categorías principales de la tienda y las formas de pago encendidas, cada bloque solo si hay datos.
$gm_info = \Aifec\Front\StoreInfo::data(app());
$gm_categories = $gm_info['categories'];
$gm_payments = $gm_info['payments'];
?>
<footer class="af-footer">
    <div class="af-container">
        <div class="af-footer-grid">
            <div class="af-footer-col gm-footer-brand">
                <a class="gm-footer-logo" href="<?= e(url('/')) ?>"><?= gmartos_logo(true) ?></a>
                <p class="gm-footer-tagline"><?= gmartos_lines($gm_tagline) ?></p>
            </div>
            <?php if ($gm_categories !== []) : ?>
                <nav class="af-footer-col gm-footer-col" aria-label="<?= e(t('Shop by category')) ?>">
                    <p class="af-footer-title"><?= e(t('Shop by category')) ?></p>
                    <?php foreach ($gm_categories as $gm_category) : ?>
                        <a href="<?= e($gm_category['url']) ?>"><?= e($gm_category['name']) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <?php foreach ($gm_cols as $gm_col) : ?>
                <nav class="af-footer-col gm-footer-col" aria-label="<?= e($gm_col['title']) ?>">
                    <p class="af-footer-title"><?= e($gm_col['title']) ?></p>
                    <?php foreach ($gm_col['links'] as $gm_link) : ?>
                        <a href="<?= e($gm_link['url']) ?>"><?= e($gm_link['label']) ?></a>
                    <?php endforeach; ?>
                </nav>
            <?php endforeach; ?>
            <?php if (base_theme_can('account') || base_theme_can('cart')) : ?>
                <nav class="af-footer-col gm-footer-col" aria-label="<?= e(t('Your account')) ?>">
                    <p class="af-footer-title"><?= e(t('Your account')) ?></p>
                    <?php if (base_theme_can('account')) : ?>
                        <a href="<?= e(base_theme_link('account')) ?>"><?= e(t('My account')) ?></a>
                    <?php endif; ?>
                    <?php if (base_theme_can('cart')) : ?>
                        <a href="<?= e(base_theme_link('cart')) ?>"><?= e(t('Cart')) ?></a>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>
            <?php if ($gm_email !== '' || $gm_phone !== '' || $gm_address !== '') : ?>
                <div class="af-footer-col gm-footer-col">
                    <p class="af-footer-title"><?= e(t('Contact')) ?></p>
                    <?php if ($gm_email !== '') : ?>
                        <a href="mailto:<?= e($gm_email) ?>"><?= e($gm_email) ?></a>
                    <?php endif; ?>
                    <?php if ($gm_phone !== '') : ?>
                        <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $gm_phone)) ?>"><?= e($gm_phone) ?></a>
                    <?php endif; ?>
                    <?php if ($gm_address !== '') : ?>
                        <span class="gm-footer-address"><?= nl2br(e($gm_address), false) ?></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <?php if ($gm_payments !== []) : ?>
                <div class="af-footer-col gm-footer-col">
                    <p class="af-footer-title"><?= e(t('Payment methods')) ?></p>
                    <?php foreach ($gm_payments as $gm_payment) : ?>
                        <span class="gm-footer-address"><?= e($gm_payment) ?></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?= $gm_columns ?>
        </div>
        <div class="af-footer-bottom">
            <p>&copy; <?= e($gm_year) ?> <?= e($gm_shop) ?></p>
            <?php if ($gm_legal !== []) : ?>
                <nav class="af-footer-legal" aria-label="<?= e(t('Legal links')) ?>">
                    <?= $this->partial('partials/nav', ['items' => $gm_legal]) ?>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</footer>
