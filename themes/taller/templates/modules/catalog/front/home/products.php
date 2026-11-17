<?php
/**
 * AIFEC — sección de productos de la portada de taller. «Ofertas de la semana» (catalog.sale) lleva en cada tarjeta la
 * insignia grande del porcentaje; las demás (destacados, novedades) son estantes de cinco tarjetas compactas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $id    catalog.featured, catalog.new o catalog.sale
 * @var string                         $title título de la sección
 * @var array<int,array<string,mixed>> $items tarjetas
 */

$tl_title_id = 'tl-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $id)) . '-title';
$tl_deals = (string) $id === 'catalog.sale';
?>
<section class="tl-shelf<?= $tl_deals ? ' tl-deals' : '' ?>" aria-labelledby="<?= e($tl_title_id) ?>">
    <h2 class="tl-shelf-title" id="<?= e($tl_title_id) ?>"><?= e($tl_deals ? t('Weekly deals') : (string) $title) ?></h2>
    <div class="tl-grid af-grid tl-cols-<?= $tl_deals ? '4' : '5' ?>">
        <?php foreach ($items as $tl_card) : ?>
            <?= $this->partial('partials/product-card', ['product' => $tl_card, 'deal' => $tl_deals]) ?>
        <?php endforeach; ?>
    </div>
</section>
