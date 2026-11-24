<?php
/**
 * AIFEC — tarjeta de producto de despensa: la imagen sobre un fondo de color suave que cambia con la categoría, esquinas
 * muy redondeadas, etiqueta de origen, marca y formato bajo el nombre, el precio y, si el producto trae peso o volumen,
 * el precio por kilo o por litro en pequeño. Un botón redondo «+» añade al carrito (con variantes, lleva a la ficha).
 * Todo el bloque enlaza a la ficha con un solo enlace, el del nombre, que se estira sobre la tarjeta.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $product ProductCard: name, url, image, price, availability, brand, rating, badges, has_variants;
 *                                   opcionales: weight, attributes, facts y main_category (si faltan se piden a la ficha)
 */

$ds_p = isset($product) && is_array($product) ? $product : [];
$ds_url = isset($ds_p['url']) ? (string) $ds_p['url'] : '';
$ds_name = isset($ds_p['name']) ? (string) $ds_p['name'] : '';
$ds_id = isset($ds_p['id']) ? (int) $ds_p['id'] : 0;
$ds_brand = isset($ds_p['brand']) && is_string($ds_p['brand']) ? trim($ds_p['brand']) : '';
$ds_image = isset($ds_p['image']) && is_array($ds_p['image']) ? $ds_p['image'] : null;
$ds_price = isset($ds_p['price']) && is_array($ds_p['price']) ? $ds_p['price'] : [];
$ds_rating = isset($ds_p['rating']) && is_array($ds_p['rating']) ? $ds_p['rating'] : null;
$ds_have = isset($ds_p['badges']) && is_array($ds_p['badges']) ? $ds_p['badges'] : [];
$ds_badges = array_values(array_intersect(['sale', 'new', 'out'], $ds_have));
$ds_out = in_array('out', $ds_badges, true);
$ds_variants = !empty($ds_p['has_variants']);
$ds_portrait = theme_option('card_ratio', 'square') === 'portrait';
$ds_show_brand = $ds_brand !== '' && (int) theme_option('show_brand_in_card', 1) === 1;
$ds_info = despensa_card_info($ds_p);
$ds_unit_on = (int) theme_option('unit_price_show', 1) === 1;
$ds_buyable = !isset($ds_p['availability']['purchasable']) || !empty($ds_p['availability']['purchasable']);
$ds_add_on = (int) theme_option('card_add', 1) === 1 && !$ds_out && $ds_buyable;
// Comprar desde la tarjeta (elegir formato y unidades): lo trae el dato «buy» si la tienda lo tiene encendido. Con él, el
// botón redondo de siempre deja su sitio a la fila de opciones, unidades y «Añadir».
$ds_buy = $ds_add_on && $ds_id > 0 && despensa_can_add() && isset($ds_p['buy']) && is_array($ds_p['buy']) ? $ds_p['buy'] : null;
$ds_live = $ds_buy !== null && !empty($ds_buy['variants']);
$ds_add_link = $ds_buy === null && $ds_add_on && $ds_variants && $ds_url !== '';
$ds_add_form = $ds_buy === null && $ds_add_on && !$ds_add_link && $ds_id > 0 && despensa_can_add();
$ds_compare = isset($ds_price['compare']) ? (int) $ds_price['compare'] : 0;
$ds_amount = isset($ds_price['amount']) ? (int) $ds_price['amount'] : 0;
$ds_percent = 0;
if ($ds_compare > $ds_amount && $ds_compare > 0) {
    $ds_percent = isset($ds_price['discount_percent']) && (int) $ds_price['discount_percent'] > 0
        ? (int) $ds_price['discount_percent']
        : intdiv(($ds_compare - $ds_amount) * 100 + intdiv($ds_compare, 2), $ds_compare);
}
$ds_avg = $ds_rating !== null && isset($ds_rating['average']) ? max(0.0, min(5.0, (float) $ds_rating['average'])) : 0.0;
$ds_count = $ds_rating !== null && isset($ds_rating['count']) ? (int) $ds_rating['count'] : 0;
?>
<article class="ds-card af-product-card<?= $ds_portrait ? ' ds-ratio-portrait' : '' ?><?= $ds_out ? ' is-out' : '' ?><?= $ds_add_link || $ds_add_form ? ' ds-has-add' : '' ?>" data-tone="<?= e($ds_info['tone']) ?>"<?= $ds_buy !== null ? ' data-af-card data-af-card-url="' . e($ds_url) . '"' . ($ds_live ? ' data-af-variants' : '') : '' ?>>
    <figure class="ds-card-media af-product-card-media">
        <?= image_tag($ds_image, 'card', ['class' => 'ds-card-img af-product-card-img', 'alt' => $ds_name]) ?>
        <?php $ds_badge_items = trim($this->partial('partials/badges', ['product' => $ds_p, 'tag' => 'none', 'item' => 'li'])); ?>
        <?php if ($ds_badge_items !== '') : ?>
            <ul class="ds-badges af-badges"><?= $ds_badge_items ?></ul>
        <?php endif; ?>
        <?php if ($ds_info['origin'] !== '') : ?>
            <small class="ds-origin"><?= despensa_svg('pin', 14) ?><span class="af-sr"><?= e(t('Origin')) ?>: </span><?= e($ds_info['origin']) ?></small>
        <?php endif; ?>
    </figure>
    <div class="ds-card-body af-product-card-body">
        <?php if ($ds_show_brand) : ?>
            <p class="ds-card-brand af-product-card-brand"><?= e($ds_brand) ?></p>
        <?php endif; ?>
        <h3 class="ds-card-name af-product-card-name">
            <?php if ($ds_url !== '') : ?>
                <a class="ds-card-link af-product-card-link" href="<?= e($ds_url) ?>"><?= e($ds_name) ?></a>
            <?php else : ?>
                <?= e($ds_name) ?>
            <?php endif; ?>
        </h3>
        <?php if ($ds_info['format'] !== '') : ?>
            <p class="ds-card-format"><?= e($ds_info['format']) ?></p>
        <?php endif; ?>
        <?php if ($ds_count > 0) : ?>
            <p class="ds-card-rating af-product-card-rating">
                <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($ds_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($ds_avg, 1, '.', '')) ?>"></span>
                <span class="af-small af-muted">(<?= e($ds_count) ?>)</span>
            </p>
        <?php endif; ?>
        <footer class="ds-card-foot">
            <?= $this->partial('partials/price', ['price' => $ds_price, 'size' => 'sm', 'live' => $ds_live]) ?>
            <?php if ($ds_unit_on && $ds_info['unit'] !== '') : ?>
                <small class="ds-unit"><?= e($ds_info['unit']) ?></small>
            <?php endif; ?>
        </footer>
    </div>
<?php if ($ds_buy !== null) : ?>
    <?= $this->partial('catalog::front/partials/card-options', ['product' => $ds_p, 'class' => 'ds-buy-row', 'action' => despensa_add_action()]) ?>
<?php elseif ($ds_add_link) : ?>
    <a class="ds-add ds-add-options" href="<?= e($ds_url) ?>" aria-label="<?= e(t('Choose a format')) ?>"><?= despensa_svg('plus', 22) ?></a>
<?php elseif ($ds_add_form) : ?>
    <form class="ds-add-form" method="post" action="<?= e(despensa_add_action()) ?>" data-af-cart-add<?= hook('webmcp.form', 'add_to_cart', ['n' => (int) $ds_id, 'name' => $ds_name]) ?>>
        <input type="hidden" name="product_id" value="<?= e($ds_id) ?>"<?= hook('webmcp.param', 'add_to_cart', 'product_id') ?>>
        <input type="hidden" name="variant_id" value="0"<?= hook('webmcp.param', 'add_to_cart', 'variant_id') ?>>
        <input type="hidden" name="qty" value="1"<?= hook('webmcp.param', 'add_to_cart', 'qty') ?>>
        <button type="submit" class="ds-add" data-af-add aria-label="<?= e(t('Add to cart')) ?>"><?= despensa_svg('plus', 22) ?></button>
    </form>
<?php endif; ?>
</article>
