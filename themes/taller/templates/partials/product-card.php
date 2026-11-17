<?php
/**
 * AIFEC — tarjeta de producto de taller: compacta y con borde. Imagen cuadrada pequeña, referencia, nombre a dos líneas,
 * tres características en lista, disponibilidad con punto de color, precio grande y botón «Añadir» siempre visible (o
 * «Ver opciones» si el producto tiene variantes). Con «deal» lleva además la insignia grande del porcentaje.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $product ProductCard: name, url, image, price, availability, brand, rating, badges, has_variants
 * @var bool                $deal    true en «Ofertas de la semana»
 */

$tl_p = isset($product) && is_array($product) ? $product : [];
$tl_url = isset($tl_p['url']) ? (string) $tl_p['url'] : '';
$tl_name = isset($tl_p['name']) ? (string) $tl_p['name'] : '';
$tl_id = isset($tl_p['id']) ? (int) $tl_p['id'] : 0;
$tl_brand = isset($tl_p['brand']) && is_string($tl_p['brand']) ? trim($tl_p['brand']) : '';
$tl_image = isset($tl_p['image']) && is_array($tl_p['image']) ? $tl_p['image'] : null;
$tl_price = isset($tl_p['price']) && is_array($tl_p['price']) ? $tl_p['price'] : [];
$tl_avail = isset($tl_p['availability']) && is_array($tl_p['availability']) ? $tl_p['availability'] : [];
$tl_have = isset($tl_p['badges']) && is_array($tl_p['badges']) ? $tl_p['badges'] : [];
$tl_badges = array_values(array_intersect(['sale', 'new', 'out'], $tl_have));
$tl_out = in_array('out', $tl_badges, true) || (isset($tl_avail['status']) && $tl_avail['status'] === 'out_of_stock');
$tl_low = isset($tl_avail['status']) && $tl_avail['status'] === 'low_stock';
$tl_stock_class = $tl_out ? 'is-out' : ($tl_low ? 'is-low' : 'is-ok');
$tl_variants = !empty($tl_p['has_variants']);
$tl_buyable = !isset($tl_avail['purchasable']) || !empty($tl_avail['purchasable']);
$tl_deal = !empty($deal);
$tl_show_brand = $tl_brand !== '' && (int) theme_option('show_brand_in_card', 1) === 1;
$tl_limit = (int) theme_option('card_specs', '3');
$tl_info = taller_card_info($tl_p, $tl_limit);
$tl_compare = isset($tl_price['compare']) ? (int) $tl_price['compare'] : 0;
$tl_amount = isset($tl_price['amount']) ? (int) $tl_price['amount'] : 0;
$tl_percent = 0;
if ($tl_compare > $tl_amount && $tl_compare > 0) {
    $tl_percent = isset($tl_price['discount_percent']) && (int) $tl_price['discount_percent'] > 0
        ? (int) $tl_price['discount_percent']
        : intdiv(($tl_compare - $tl_amount) * 100 + intdiv($tl_compare, 2), $tl_compare);
}
$tl_can_add = taller_can_add() && $tl_id > 0;
// Comprar desde la tarjeta (elegir combinación y unidades): lo trae el dato «buy» si la tienda lo tiene encendido.
$tl_buy = $tl_can_add && isset($tl_p['buy']) && is_array($tl_p['buy']) ? $tl_p['buy'] : null;
$tl_live = $tl_buy !== null && !empty($tl_buy['variants']);
?>
<article class="tl-card af-product-card<?= $tl_out ? ' is-out' : '' ?><?= $tl_deal ? ' tl-card-deal' : '' ?>"<?= $tl_buy !== null ? ' data-af-card data-af-card-url="' . e($tl_url) . '"' . ($tl_live ? ' data-af-variants' : '') : '' ?>>
<?php if ($tl_url !== '') : ?>
    <a class="tl-card-link af-product-card-link" href="<?= e($tl_url) ?>">
<?php else : ?>
    <div class="tl-card-link af-product-card-link">
<?php endif; ?>
        <div class="tl-card-media af-product-card-media">
            <?= image_tag($tl_image, 'card', ['class' => 'tl-card-img af-product-card-img', 'alt' => '']) ?>
            <?= $this->partial('partials/badges', ['product' => $tl_p, 'tag' => 'p', 'class' => 'tl-card-badges']) ?>
            <?php if ($tl_deal && $tl_percent > 0) : ?>
                <strong class="tl-deal-off"><span class="af-sr"><?= e(t('Discount')) ?> </span><?= e('−' . $tl_percent . "\u{a0}%") ?></strong>
            <?php endif; ?>
        </div>
        <div class="tl-card-info af-product-card-body">
            <?php if ($tl_info['ref'] !== '' || $tl_show_brand) : ?>
                <p class="tl-card-ref">
                    <?php if ($tl_show_brand) : ?>
                        <span class="tl-card-brand af-product-card-brand"><?= e($tl_brand) ?></span>
                    <?php endif; ?>
                    <?php if ($tl_info['ref'] !== '') : ?>
                        <span class="tl-card-sku"><?= e(t('Ref.')) ?> <?= e($tl_info['ref']) ?></span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <h3 class="tl-card-name af-product-card-name"><?= e($tl_name) ?></h3>
            <?php if ($tl_info['specs'] !== []) : ?>
                <ul class="tl-card-specs">
                    <?php foreach ($tl_info['specs'] as $tl_spec) : ?>
                        <li><span class="tl-k"><?= e($tl_spec['label']) ?></span> <span class="tl-v"><?= e($tl_spec['value']) ?></span></li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
<?php if ($tl_url !== '') : ?>
    </a>
<?php else : ?>
    </div>
<?php endif; ?>
    <div class="tl-card-buy">
        <?php if (isset($tl_avail['label']) && (string) $tl_avail['label'] !== '') : ?>
            <p class="tl-stock af-availability <?= e($tl_stock_class) ?>"><?= e((string) $tl_avail['label']) ?></p>
        <?php endif; ?>
        <?= $this->partial('partials/price', ['price' => $tl_price, 'size' => 'md', 'live' => $tl_live]) ?>
        <?php if ($tl_out) : ?>
            <span class="tl-add-btn af-btn af-btn-sm is-disabled" aria-disabled="true"><?= e(t('Sold out')) ?></span>
        <?php elseif (!$tl_buyable) : ?>
            <?php /* un plugin dejó el producto como «no se puede comprar»: la tarjeta no lleva botón */ ?>
        <?php elseif ($tl_buy !== null) : ?>
            <?= $this->partial('catalog::front/partials/card-options', [
                'product' => $tl_p, 'class' => 'tl-add', 'action' => taller_add_action(),
                'button_class' => 'tl-add-btn af-btn af-btn-primary af-btn-sm',
                'button_html' => taller_svg('cart', 18) . '<span>' . e(t('Add')) . '</span>',
            ]) ?>
        <?php elseif ($tl_variants && $tl_url !== '') : ?>
            <a class="tl-add-btn af-btn af-btn-sm" href="<?= e($tl_url) ?>"><?= e(t('Choose options')) ?></a>
        <?php elseif ($tl_can_add) : ?>
            <form class="tl-add" method="post" action="<?= e(taller_add_action()) ?>" data-af-cart-add>
                <input type="hidden" name="product_id" value="<?= e($tl_id) ?>">
                <input type="hidden" name="variant_id" value="0">
                <input type="hidden" name="qty" value="1">
                <button type="submit" class="tl-add-btn af-btn af-btn-primary af-btn-sm" data-af-add><?= taller_svg('cart', 18) ?><span><?= e(t('Add')) ?></span></button>
            </form>
        <?php endif; ?>
    </div>
</article>
