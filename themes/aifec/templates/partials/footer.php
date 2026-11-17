<?php
/**
 * AIFEC — pie del tema aifec: los seis principios en una línea, los bloques de la tienda (P100: categorías principales, el
 * menú «footer», la atención al cliente de «Ajustes → General» y las formas de pago encendidas, cada uno solo con datos),
 * el hueco de columnas de los módulos y la franja legal. En su versión compacta, solo la línea de principios y la franja legal.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$af_app = app();
$af_shop = $af_app->settings()->str('store.name', 'AIFEC');
// Una sola entrada de la caché del sitio (StoreInfo::data): el pie de cada página no cuesta consultas.
$af_info = \Aifec\Front\StoreInfo::data($af_app);
$af_contact = $af_info['contact'];
$af_footer = menu('footer');
$af_legal = menu('legal');
$af_year = gmdate('Y', $af_app->clock()->now());
$af_columns = hook('footer.columns');
$af_compact = theme_option('footer_style', 'columns') === 'compact';
$af_principles = (int) theme_option('footer_principles', 1) === 1;
$af_categories = [];
$af_payments = [];
if (!$af_compact) {
    foreach ($af_info['categories'] as $af_category) {
        $af_categories[] = ['label' => $af_category['name'], 'url' => $af_category['url']];
    }
    $af_payments = $af_info['payments'];
}
?>
<footer class="af-footer af-footer-<?= $af_compact ? 'compact' : 'columns' ?>">
    <div class="af-container">
        <?php if ($af_principles) : ?>
            <ul class="af-principles" aria-label="<?= e(t('The six AIFEC principles')) ?>">
                <?php foreach (aifec_theme_principles() as $af_principle) : ?>
                    <li><?= e($af_principle) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
        <?php if (!$af_compact) : ?>
            <div class="af-footer-grid">
                <div class="af-footer-col af-footer-about">
                    <p class="af-footer-title"><?= e($af_shop) ?></p>
                    <?php if ($af_contact['email'] !== '') : ?>
                        <p><a href="mailto:<?= e($af_contact['email']) ?>"><?= e($af_contact['email']) ?></a></p>
                    <?php endif; ?>
                    <?php if ($af_contact['phone'] !== '') : ?>
                        <p><a href="tel:<?= e($af_contact['phone_href']) ?>"><?= e($af_contact['phone']) ?></a></p>
                    <?php endif; ?>
                    <?php if ($af_contact['address'] !== '') : ?>
                        <p class="af-muted"><?= nl2br(e($af_contact['address']), false) ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($af_categories !== []) : ?>
                    <nav class="af-footer-col af-footer-cats" aria-label="<?= e(t('Shop by category')) ?>">
                        <p class="af-footer-title"><?= e(t('Shop by category')) ?></p>
                        <?= $this->partial('partials/nav', ['items' => $af_categories]) ?>
                    </nav>
                <?php endif; ?>
                <?php if ($af_footer !== []) : ?>
                    <nav class="af-footer-col af-footer-nav" aria-label="<?= e(t('Footer')) ?>">
                        <p class="af-footer-title"><?= e(t('Information')) ?></p>
                        <?= $this->partial('partials/nav', ['items' => $af_footer]) ?>
                    </nav>
                <?php endif; ?>
                <?php if ($af_payments !== []) : ?>
                    <div class="af-footer-col af-footer-pay">
                        <p class="af-footer-title"><?= e(t('Payment methods')) ?></p>
                        <ul class="af-nav-list">
                            <?php foreach ($af_payments as $af_payment) : ?>
                                <li class="af-nav-item"><span class="af-nav-link"><?= e($af_payment) ?></span></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                <?= $af_columns ?>
            </div>
        <?php endif; ?>
        <div class="af-footer-bottom">
            <p class="af-small af-muted">&copy; <?= e($af_year) ?> <?= e($af_shop) ?></p>
            <?php if ($af_legal !== []) : ?>
                <nav class="af-footer-legal" aria-label="<?= e(t('Legal links')) ?>">
                    <?= $this->partial('partials/nav', ['items' => $af_legal]) ?>
                </nav>
            <?php endif; ?>
        </div>
    </div>
</footer>
