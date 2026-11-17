<?php
/**
 * AIFEC — pie de Café, sobre tinta café. A la izquierda, la tienda: su emblema y su nombre, el texto de su tostado (una
 * opción del tema) y sus datos de contacto. A su lado, los menús en columnas: cada elemento del menú del pie que tiene
 * hijos es una columna con su título; los sueltos van juntos en una. Debajo, la franja legal con el año, los enlaces
 * legales y las formas de pago que de verdad ofrece la tienda. Respeta el hueco de columnas de los módulos.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$cf_settings = app()->settings();
$cf_shop = $cf_settings->str('store.name', 'AIFEC');
$cf_email = trim($cf_settings->str('store.email', ''));
$cf_phone = trim($cf_settings->str('store.phone', ''));
$cf_address = trim($cf_settings->str('store.address', ''));
$cf_about = trim((string) theme_option('footer_text', ''));
$cf_legal = menu('legal');
$cf_year = gmdate('Y', app()->clock()->now());
// Las columnas: un elemento con hijos es una columna; los sueltos se juntan en «Información».
$cf_cols = [];
$cf_loose = [];
foreach (menu('footer') as $cf_item) {
    $cf_label = isset($cf_item['label']) ? (string) $cf_item['label'] : '';
    $cf_href = isset($cf_item['url']) ? (string) $cf_item['url'] : '';
    $cf_kids = isset($cf_item['children']) && is_array($cf_item['children']) ? array_values(array_filter($cf_item['children'], 'is_array')) : [];
    if ($cf_label === '') {
        continue;
    }
    if ($cf_kids !== []) {
        $cf_cols[] = [$cf_label, $cf_kids];
    } elseif ($cf_href !== '') {
        $cf_loose[] = $cf_item;
    }
}
if ($cf_loose !== []) {
    $cf_cols[] = [t('Information'), $cf_loose];
}
try {
    $cf_pay = \Aifec\Front\StoreInfo::data(app())['payments'];
} catch (\Throwable $cf_error) {
    $cf_pay = [];
}
$cf_pay = is_array($cf_pay) ? array_values(array_filter($cf_pay, 'is_string')) : [];
?>
<footer class="af-footer cf-foot">
    <div class="af-container cf-foot-main">
        <section class="cf-foot-shop" aria-label="<?= e($cf_shop) ?>">
            <p class="cf-foot-brand"><?= cafe_emblem(40) ?><strong><?= e($cf_shop) ?></strong></p>
            <?php if ($cf_about !== '') : ?>
                <p class="cf-foot-about"><?= e($cf_about) ?></p>
            <?php endif; ?>
            <?php if ($cf_address !== '' || $cf_email !== '' || $cf_phone !== '') : ?>
                <address>
                    <?php if ($cf_address !== '') : ?>
                        <span><?= nl2br(e($cf_address), false) ?></span>
                    <?php endif; ?>
                    <?php if ($cf_email !== '') : ?>
                        <a href="mailto:<?= e($cf_email) ?>"><?= e($cf_email) ?></a>
                    <?php endif; ?>
                    <?php if ($cf_phone !== '') : ?>
                        <a href="tel:<?= e(preg_replace('/[^0-9+]/', '', $cf_phone)) ?>"><?= e($cf_phone) ?></a>
                    <?php endif; ?>
                </address>
            <?php endif; ?>
        </section>
        <?php foreach ($cf_cols as $cf_col) : ?>
            <nav class="cf-foot-col" aria-label="<?= e($cf_col[0]) ?>">
                <h2 class="cf-foot-title"><?= e($cf_col[0]) ?></h2>
                <?= $this->partial('partials/nav', ['items' => $cf_col[1]]) ?>
            </nav>
        <?php endforeach; ?>
        <?= hook('footer.columns') ?>
    </div>
    <div class="cf-foot-legal">
        <dl class="af-container">
            <dt class="af-sr"><?= e(t('Copyright')) ?></dt>
            <dd>&copy; <?= e($cf_year) ?> <?= e($cf_shop) ?></dd>
            <?php if ($cf_legal !== []) : ?>
                <dt class="af-sr"><?= e(t('Legal links')) ?></dt>
                <dd class="cf-foot-links"><?= $this->partial('partials/nav', ['items' => $cf_legal]) ?></dd>
            <?php endif; ?>
            <?php if ($cf_pay !== []) : ?>
                <dt class="cf-foot-pay-title"><?= e(t('Payment methods')) ?></dt>
                <?php foreach ($cf_pay as $cf_method) : ?>
                    <dd class="cf-foot-pay"><?= e($cf_method) ?></dd>
                <?php endforeach; ?>
            <?php endif; ?>
        </dl>
    </div>
</footer>
