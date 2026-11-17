<?php
/**
 * AIFEC — sección de productos de la portada de Café (sustituye `catalog::front/home/products`): un titular con su
 * filete y la rejilla de cuatro como lista ordenada, con la tarjeta del tema.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $id    identificador de la sección (catalog.featured, catalog.new, catalog.sale)
 * @var string                         $title
 * @var array<int,array<string,mixed>> $items tarjetas
 */

$cf_title_id = 'af-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $id)) . '-title';
?>
<section class="cf-fresh" aria-labelledby="<?= e($cf_title_id) ?>">
    <h2 id="<?= e($cf_title_id) ?>"><?= e((string) $title) ?></h2>
    <ol class="af-grid af-cols-4 cf-shelf-grid">
        <?php foreach ($items as $cf_card) : ?>
            <li><?= $this->partial('partials/product-card', ['product' => $cf_card]) ?></li>
        <?php endforeach; ?>
    </ol>
</section>
