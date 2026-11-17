<?php
/**
 * AIFEC — una sección de productos de la portada en Métrica (destacados, novedades, ofertas): un estante con su rótulo y la
 * rejilla de etiquetas de cajón, a cinco por fila en el escritorio.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $id    identificador de la sección (sirve para enlazar el título)
 * @var string                         $title
 * @var array<int,array<string,mixed>> $items tarjetas de producto
 */

$mt_title_id = 'mt-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $id)) . '-title';
?>
<section class="mt-shelf" aria-labelledby="<?= e($mt_title_id) ?>">
    <h2 id="<?= e($mt_title_id) ?>"><?= e((string) $title) ?></h2>
    <ul class="mt-grid mt-cols-5 mt-grid-shelf">
        <?php foreach ($items as $mt_card) : ?>
            <li><?= $this->partial('partials/product-card', ['product' => $mt_card]) ?></li>
        <?php endforeach; ?>
    </ul>
</section>
