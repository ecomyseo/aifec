<?php
/**
 * AIFEC — pie del tema base (y de atelier, despensa y taller, que lo heredan): hasta cuatro bloques, cada uno solo si la
 * tienda tiene el dato (P100): las categorías principales, la información (menú «footer»), la atención al cliente (correo,
 * teléfono y dirección de «Ajustes → General») y las formas de pago encendidas, por su nombre. Después, el hueco de columnas
 * de los módulos y, abajo, el © y la navegación legal (menú «legal», <nav class="af-footer-legal">: contrato del tema base).
 * Los datos los junta Aifec\Front\StoreInfo::data (en caché); aquí todo sale escapado.
 *
 * En móvil la rejilla queda en una columna (el contenido ya reserva el alto de la barra inferior).
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
$af_has_contact = $af_contact['email'] !== '' || $af_contact['phone'] !== '' || $af_contact['address'] !== '';
$af_categories = [];
foreach ($af_info['categories'] as $af_category) {
    $af_categories[] = ['label' => $af_category['name'], 'url' => $af_category['url']];
}
$af_payments = $af_info['payments'];
$af_footer = menu('footer');
$af_legal = menu('legal');
$af_year = gmdate('Y', $af_app->clock()->now());
$af_columns = hook('footer.columns');
?>
<footer class="af-footer">
    <div class="af-container">
        <div class="af-footer-grid">
            <?php if ($af_categories !== []) : ?>
                <nav class="af-footer-col af-footer-cats" aria-label="<?= e(t('Shop by category')) ?>">
                    <p class="af-footer-title"><?= e(t('Shop by category')) ?></p>
                    <?= $this->partial('partials/nav', ['items' => $af_categories]) ?>
                </nav>
            <?php endif; ?>
            <?php if ($af_footer !== []) : ?>
                <nav class="af-footer-col af-footer-nav" aria-label="<?= e(t('Information')) ?>">
                    <p class="af-footer-title"><?= e(t('Information')) ?></p>
                    <?= $this->partial('partials/nav', ['items' => $af_footer]) ?>
                </nav>
            <?php endif; ?>
            <?php if ($af_has_contact) : ?>
                <div class="af-footer-col af-footer-about">
                    <p class="af-footer-title"><?= e(t('Customer service')) ?></p>
                    <?php if ($af_contact['phone'] !== '') : ?>
                        <p><a href="tel:<?= e($af_contact['phone_href']) ?>"><?= e($af_contact['phone']) ?></a></p>
                    <?php endif; ?>
                    <?php if ($af_contact['email'] !== '') : ?>
                        <p><a href="mailto:<?= e($af_contact['email']) ?>"><?= e($af_contact['email']) ?></a></p>
                    <?php endif; ?>
                    <?php if ($af_contact['address'] !== '') : ?>
                        <p class="af-muted"><?= nl2br(e($af_contact['address']), false) ?></p>
                    <?php endif; ?>
                </div>
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
