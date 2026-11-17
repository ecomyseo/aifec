<?php
/**
 * AIFEC — sección de productos de la portada de gmartos: una etiqueta en letra monoespaciada y las tarjetas de la portada
 * (imagen en recuadro de color, marca, nombre grande, extracto, precio y «Ver producto»).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                           $id    identificador de la sección (catalog.featured, catalog.new…)
 * @var string                           $title título de la sección
 * @var array<int,array<string,mixed>>   $items tarjetas de producto
 */

$gm_title_id = 'gm-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $id)) . '-title';
?>
<section aria-labelledby="<?= e($gm_title_id) ?>" class="gm-block">
    <h3 id="<?= e($gm_title_id) ?>" class="gm-kicker gm-block-label"><?= e((string) $title) ?></h3>
    <ul class="af-grid af-cols-4 gm-grid gm-grid-home" role="list">
        <?php foreach ($items as $gm_card) : ?>
            <li><?= $this->partial('partials/product-card', ['product' => $gm_card, 'home' => true]) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
