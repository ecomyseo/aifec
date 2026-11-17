<?php
/**
 * AIFEC — pie de Métrica: banda grafito con un filete amarillo arriba. A la izquierda, la placa amarilla de la marca y, bajo
 * ella, los datos de contacto de la tienda (solo los que tiene); a la derecha, los menús en columnas: las categorías, los
 * trabajos (menú «jobs»), la información (menú «footer») y las formas de pago, más el hueco de columnas de los módulos.
 * Abajo, la franja legal en letra de máquina: el © y la navegación legal (menú «legal»). Cada bloque solo sale si hay dato.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 */

$mt_app = app();
$mt_shop = $mt_app->settings()->str('store.name', 'AIFEC');
// Una sola entrada de la caché del sitio (StoreInfo::data): el pie de cada página no cuesta consultas.
$mt_info = \Aifec\Front\StoreInfo::data($mt_app);
$mt_contact = $mt_info['contact'];
$mt_has_contact = $mt_contact['email'] !== '' || $mt_contact['phone'] !== '' || $mt_contact['address'] !== '';
$mt_categories = [];
foreach ($mt_info['categories'] as $mt_category) {
    $mt_categories[] = ['label' => $mt_category['name'], 'url' => $mt_category['url']];
}
$mt_payments = $mt_info['payments'];
$mt_jobs = [];
foreach (metrica_clean_menu(menu('jobs')) as $mt_job) {
    // En el pie los trabajos van sin su lista de material: solo el trabajo.
    $mt_jobs[] = ['label' => $mt_job['label'], 'url' => $mt_job['url'], 'current' => $mt_job['current']];
}
$mt_footer = menu('footer');
$mt_legal = menu('legal');
$mt_year = gmdate('Y', $mt_app->clock()->now());
?>
<footer class="af-footer mt-footer">
<div class="af-container mt-foot-body">
<section class="mt-foot-brand" aria-label="<?= e($mt_shop) ?>">
<a class="af-logo mt-foot-plate" href="<?= e(url('/')) ?>"><?= shop_logo() ?></a>
<?php if ($mt_has_contact) : ?>
<address>
<?php if ($mt_contact['address'] !== '') : ?>
<span><?= nl2br(e($mt_contact['address']), false) ?></span>
<?php endif; ?>
<?php if ($mt_contact['phone'] !== '') : ?>
<a href="tel:<?= e($mt_contact['phone_href']) ?>"><?= e($mt_contact['phone']) ?></a>
<?php endif; ?>
<?php if ($mt_contact['email'] !== '') : ?>
<a href="mailto:<?= e($mt_contact['email']) ?>"><?= e($mt_contact['email']) ?></a>
<?php endif; ?>
</address>
<?php endif; ?>
</section>
<div class="mt-foot-cols">
<?php if ($mt_categories !== []) : ?>
<nav aria-label="<?= e(t('Shop by category')) ?>">
<h2 class="mt-foot-title"><?= e(t('Shop by category')) ?></h2>
<?= $this->partial('partials/nav', ['items' => $mt_categories]) ?>
</nav>
<?php endif; ?>
<?php if ($mt_jobs !== []) : ?>
<nav aria-label="<?= e(t('Jobs')) ?>">
<h2 class="mt-foot-title"><?= e(t('Jobs')) ?></h2>
<?= $this->partial('partials/nav', ['items' => $mt_jobs]) ?>
</nav>
<?php endif; ?>
<?php if ($mt_footer !== []) : ?>
<nav aria-label="<?= e(t('Information')) ?>">
<h2 class="mt-foot-title"><?= e(t('Information')) ?></h2>
<?= $this->partial('partials/nav', ['items' => $mt_footer]) ?>
</nav>
<?php endif; ?>
<?php if ($mt_payments !== []) : ?>
<section aria-labelledby="mt-foot-pay">
<h2 class="mt-foot-title" id="mt-foot-pay"><?= e(t('Payment methods')) ?></h2>
<ul class="mt-foot-pay">
<?php foreach ($mt_payments as $mt_payment) : ?>
<li><?= e($mt_payment) ?></li>
<?php endforeach; ?>
</ul>
</section>
<?php endif; ?>
<?= hook('footer.columns') ?>
</div>
</div>
<div class="mt-foot-legal">
<div class="af-container">
<p>&copy; <?= e($mt_year) ?> <?= e($mt_shop) ?></p>
<?php if ($mt_legal !== []) : ?>
<nav class="af-footer-legal" aria-label="<?= e(t('Legal links')) ?>">
<?= $this->partial('partials/nav', ['items' => $mt_legal]) ?>
</nav>
<?php endif; ?>
</div>
</div>
</footer>
