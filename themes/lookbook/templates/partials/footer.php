<?php
/**
 * AIFEC — pie de Lookbook, el colofón de la revista: blanco, con la marca en serifa cursiva grande; debajo, los menús
 * como un índice escrito (cada fila, su rótulo y sus enlaces en línea: colecciones, información, atención al cliente y
 * formas de pago, cada una solo si la tienda tiene el dato); y una línea legal fina con el © y la navegación legal.
 * Respeta el hueco de columnas de los módulos. Los datos los junta Aifec\Front\StoreInfo::data (en caché).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$lb_app = app();
$lb_shop = $lb_app->settings()->str('store.name', 'AIFEC');
$lb_info = \Aifec\Front\StoreInfo::data($lb_app);
$lb_contact = $lb_info['contact'];
$lb_has_contact = $lb_contact['email'] !== '' || $lb_contact['phone'] !== '' || $lb_contact['address'] !== '';
$lb_categories = $lb_info['categories'];
$lb_payments = $lb_info['payments'];
$lb_legal = menu('legal');
$lb_year = gmdate('Y', $lb_app->clock()->now());

// Los enlaces del menú «footer» que se pueden pintar: con texto y con dirección (los de sus submenús, a continuación).
$lb_links = [];
foreach (menu('footer') as $lb_item) {
    if (!is_array($lb_item)) {
        continue;
    }
    $lb_group = array_merge([$lb_item], isset($lb_item['children']) && is_array($lb_item['children']) ? array_values(array_filter($lb_item['children'], 'is_array')) : []);
    foreach ($lb_group as $lb_one) {
        if (isset($lb_one['label'], $lb_one['url']) && (string) $lb_one['label'] !== '' && (string) $lb_one['url'] !== '') {
            $lb_links[] = ['label' => (string) $lb_one['label'], 'url' => (string) $lb_one['url'], 'current' => !empty($lb_one['current'])];
        }
    }
}
?>
<footer class="af-footer lb-colophon">
    <p class="lb-colophon-brand"><a href="<?= e(url('/')) ?>"><?= e($lb_shop) ?></a></p>
    <dl class="lb-colophon-index">
        <?php if ($lb_categories !== []) : ?>
            <dt><?= e(t('Collections')) ?></dt>
            <dd>
                <?php foreach ($lb_categories as $lb_category) : ?>
                    <a href="<?= e((string) $lb_category['url']) ?>"><?= e((string) $lb_category['name']) ?></a>
                <?php endforeach; ?>
            </dd>
        <?php endif; ?>
        <?php if ($lb_links !== []) : ?>
            <dt><?= e(t('Information')) ?></dt>
            <dd>
                <?php foreach ($lb_links as $lb_link) : ?>
                    <a href="<?= e($lb_link['url']) ?>"<?= ($lb_link['current'] ? ' aria-current="page"' : '') ?>><?= e($lb_link['label']) ?></a>
                <?php endforeach; ?>
            </dd>
        <?php endif; ?>
        <?php if ($lb_has_contact) : ?>
            <dt><?= e(t('Customer service')) ?></dt>
            <dd>
                <address>
                    <?php if ($lb_contact['phone'] !== '') : ?>
                        <a href="tel:<?= e($lb_contact['phone_href']) ?>"><?= e($lb_contact['phone']) ?></a>
                    <?php endif; ?>
                    <?php if ($lb_contact['email'] !== '') : ?>
                        <a href="mailto:<?= e($lb_contact['email']) ?>"><?= e($lb_contact['email']) ?></a>
                    <?php endif; ?>
                    <?php if ($lb_contact['address'] !== '') : ?>
                        <span><?= e(implode(', ', array_filter(array_map('trim', preg_split('/\R/', $lb_contact['address']) ?: [$lb_contact['address']]), 'strlen'))) ?></span>
                    <?php endif; ?>
                </address>
            </dd>
        <?php endif; ?>
        <?php if ($lb_payments !== []) : ?>
            <dt><?= e(t('Payment methods')) ?></dt>
            <dd><?= e(implode(' · ', array_map('strval', $lb_payments))) ?></dd>
        <?php endif; ?>
    </dl>
    <?= hook('footer.columns') ?>
    <div class="lb-colophon-legal">
        <small>&copy; <?= e($lb_year) ?> <?= e($lb_shop) ?></small>
        <?php if ($lb_legal !== []) : ?>
            <nav class="af-footer-legal" aria-label="<?= e(t('Legal links')) ?>">
                <?= $this->partial('partials/nav', ['items' => $lb_legal]) ?>
            </nav>
        <?php endif; ?>
    </div>
</footer>
