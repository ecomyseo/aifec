<?php
/**
 * AIFEC — una sección de la portada de despensa con productos (destacados, novedades, ofertas): una fila que se desliza
 * con el dedo, con anclaje de cada tarjeta (scroll-snap, sin guion). Con la opción «season_row» en «grid», una rejilla.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $id    identificador de la sección (sirve para enlazar el título)
 * @var string                         $title
 * @var array<int,array<string,mixed>> $items tarjetas de producto
 */

$ds_title_id = 'af-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $id)) . '-title';
$ds_grid = theme_option('season_row', 'swipe') === 'grid';
?>
<section aria-labelledby="<?= e($ds_title_id) ?>" class="ds-season af-section">
    <h2 class="ds-season-title" id="<?= e($ds_title_id) ?>"><?= e((string) $title) ?></h2>
    <?php if ($ds_grid) : ?>
        <div class="ds-grid af-grid ds-cols-4">
            <?php foreach ($items as $ds_card) : ?>
                <?= $this->partial('partials/product-card', ['product' => $ds_card]) ?>
            <?php endforeach; ?>
        </div>
    <?php else : ?>
        <div class="ds-rail">
            <div class="ds-rail-track" role="region" aria-label="<?= e((string) $title) ?>" tabindex="0">
                <?php foreach ($items as $ds_card) : ?>
                    <div class="ds-rail-item"><?= $this->partial('partials/product-card', ['product' => $ds_card]) ?></div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</section>
