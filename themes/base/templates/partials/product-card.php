<?php
/**
 * AIFEC — tarjeta de producto: imagen completa, nombre, marca, valoración, precio e insignias. Todo enlaza a la ficha.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $product ProductCard: name, url, image, price, availability, brand, rating, badges
 */

$af_p = isset($product) && is_array($product) ? $product : [];
$af_url = isset($af_p['url']) ? (string) $af_p['url'] : '';
$af_name = isset($af_p['name']) ? (string) $af_p['name'] : '';
$af_brand = isset($af_p['brand']) && is_string($af_p['brand']) ? trim($af_p['brand']) : '';
$af_image = isset($af_p['image']) && is_array($af_p['image']) ? $af_p['image'] : null;
$af_price = isset($af_p['price']) && is_array($af_p['price']) ? $af_p['price'] : [];
$af_rating = isset($af_p['rating']) && is_array($af_p['rating']) ? $af_p['rating'] : null;
$af_have = isset($af_p['badges']) && is_array($af_p['badges']) ? $af_p['badges'] : [];
$af_badges = array_values(array_intersect(['sale', 'new', 'out'], $af_have));
$af_portrait = theme_option('card_ratio', 'square') === 'portrait';
$af_show_brand = $af_brand !== '' && (int) theme_option('show_brand_in_card', 1) === 1;
$af_compare = isset($af_price['compare']) ? (int) $af_price['compare'] : 0;
$af_amount = isset($af_price['amount']) ? (int) $af_price['amount'] : 0;
$af_percent = 0;
if ($af_compare > $af_amount && $af_compare > 0) {
    $af_percent = isset($af_price['discount_percent']) && (int) $af_price['discount_percent'] > 0
        ? (int) $af_price['discount_percent']
        : intdiv(($af_compare - $af_amount) * 100 + intdiv($af_compare, 2), $af_compare);
}
$af_avg = $af_rating !== null && isset($af_rating['average']) ? max(0.0, min(5.0, (float) $af_rating['average'])) : 0.0;
$af_count = $af_rating !== null && isset($af_rating['count']) ? (int) $af_rating['count'] : 0;
// Comprar desde la tarjeta (elegir combinación y unidades): lo trae el dato «buy» si la tienda lo tiene encendido.
$af_buy = isset($af_p['buy']) && is_array($af_p['buy']) ? $af_p['buy'] : null;
$af_live = $af_buy !== null && !empty($af_buy['variants']);
?>
<article class="af-product-card<?= $af_portrait ? ' af-ratio-portrait' : '' ?><?= in_array('out', $af_badges, true) ? ' is-out' : '' ?>"<?= $af_buy !== null ? ' data-af-card data-af-card-url="' . e($af_url) . '"' . ($af_live ? ' data-af-variants' : '') : '' ?>>
<?php if ($af_url !== '') : ?>
    <a class="af-product-card-link" href="<?= e($af_url) ?>">
<?php else : ?>
    <div class="af-product-card-link">
<?php endif; ?>
        <span class="af-product-card-media">
            <?= image_tag($af_image, 'card', ['class' => 'af-product-card-img', 'alt' => $af_name]) ?>
            <?= $this->partial('partials/badges', ['product' => $af_p, 'tag' => 'span']) ?>
        </span>
        <span class="af-product-card-body">
            <?php if ($af_show_brand) : ?>
                <span class="af-product-card-brand"><?= e($af_brand) ?></span>
            <?php endif; ?>
            <h3 class="af-product-card-name"><?= e($af_name) ?></h3>
            <?php if ($af_count > 0) : ?>
                <span class="af-product-card-rating">
                    <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($af_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($af_avg, 1, '.', '')) ?>"></span>
                    <span class="af-small af-muted">(<?= e($af_count) ?>)</span>
                </span>
            <?php endif; ?>
            <?= $this->partial('partials/price', ['price' => $af_price, 'size' => 'sm', 'live' => $af_live]) ?>
        </span>
<?php if ($af_url !== '') : ?>
    </a>
<?php else : ?>
    </div>
<?php endif; ?>
<?php if ($af_buy !== null) : ?>
    <?= $this->partial('catalog::front/partials/card-options', ['product' => $af_p]) ?>
<?php endif; ?>
</article>
