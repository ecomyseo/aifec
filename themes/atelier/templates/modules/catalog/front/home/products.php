<?php
/**
 * AIFEC — una sección de la portada con productos en atelier: título fino con filete y una sola fila de cuatro tarjetas.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $id    identificador de la sección (sirve para enlazar el título)
 * @var string                         $title
 * @var array<int,array<string,mixed>> $items tarjetas de producto
 */

$at_title_id = 'af-' . preg_replace('/[^a-z0-9]+/', '-', strtolower((string) $id)) . '-title';
$at_items = array_slice(is_array($items) ? array_values($items) : [], 0, 4);
?>
<section aria-labelledby="<?= e($at_title_id) ?>" class="at-row-section af-section">
    <header class="at-row-head">
        <h2 id="<?= e($at_title_id) ?>"><?= e((string) $title) ?></h2>
    </header>
    <div class="at-grid at-row-4 af-grid">
        <?php foreach ($at_items as $at_card) : ?>
            <?= $this->partial('partials/product-card', ['product' => $at_card]) ?>
        <?php endforeach; ?>
    </div>
</section>
