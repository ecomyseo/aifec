<?php
/**
 * AIFEC — una sección de la portada con productos (destacados, novedades, ofertas): su título y la rejilla de tarjetas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $id    identificador de la sección (sirve para enlazar el título)
 * @var string                         $title
 * @var array<int,array<string,mixed>> $items tarjetas de producto
 */

$af_title_id = 'af-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $id)) . '-title';
?>
<section aria-labelledby="<?= e($af_title_id) ?>" class="af-section">
    <h2 id="<?= e($af_title_id) ?>"><?= e((string) $title) ?></h2>
    <div class="af-grid af-cols-4">
        <?php foreach ($items as $af_card) : ?>
            <?= $this->partial('partials/product-card', ['product' => $af_card]) ?>
        <?php endforeach; ?>
    </div>
</section>
