<?php
/**
 * AIFEC — tarjeta de producto de atelier: fotografía vertical sin borde ni fondo, segunda imagen al pasar el ratón,
 * nombre y precio en una línea fina debajo, muestras de color si el producto las trae y ningún botón de compra.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $product ProductCard; opcionales: images (la segunda se usa al pasar el ratón) y swatches
 *                                   (lista de [label, color #rrggbb])
 */

$at_p = isset($product) && is_array($product) ? $product : [];
$at_url = isset($at_p['url']) ? (string) $at_p['url'] : '';
$at_name = isset($at_p['name']) ? (string) $at_p['name'] : '';
$at_brand = isset($at_p['brand']) && is_string($at_p['brand']) ? trim($at_p['brand']) : '';
$at_image = isset($at_p['image']) && is_array($at_p['image']) ? $at_p['image'] : null;
$at_price = isset($at_p['price']) && is_array($at_p['price']) ? $at_p['price'] : [];
$at_have = isset($at_p['badges']) && is_array($at_p['badges']) ? $at_p['badges'] : [];
$at_badges = array_values(array_intersect(['sale', 'new', 'out'], $at_have));
$at_shape = (string) theme_option('card_ratio', 'portrait');
$at_show_brand = $at_brand !== '' && (int) theme_option('show_brand_in_card', 0) === 1;
$at_compare = isset($at_price['compare']) ? (int) $at_price['compare'] : 0;
$at_amount = isset($at_price['amount']) ? (int) $at_price['amount'] : 0;
$at_percent = 0;
if ($at_compare > $at_amount && $at_compare > 0) {
    $at_percent = isset($at_price['discount_percent']) && (int) $at_price['discount_percent'] > 0
        ? (int) $at_price['discount_percent']
        : intdiv(($at_compare - $at_amount) * 100 + intdiv($at_compare, 2), $at_compare);
}
// La segunda imagen: solo si el dato la trae y no es la misma que la primera.
$at_second = null;
if (isset($at_p['images']) && is_array($at_p['images'])) {
    foreach (array_values($at_p['images']) as $at_n => $at_candidate) {
        if ($at_n > 0 && is_array($at_candidate) && !empty($at_candidate['url']) && ($at_image === null || (string) $at_candidate['url'] !== (string) $at_image['url'])) {
            $at_second = $at_candidate;
            break;
        }
    }
}
// Comprar desde la tarjeta (elegir talla o color y unidades): lo trae el dato «buy» si la tienda lo tiene encendido.
$at_buy = isset($at_p['buy']) && is_array($at_p['buy']) ? $at_p['buy'] : null;
$at_live = $at_buy !== null && !empty($at_buy['variants']);
$at_swatches = [];
foreach (isset($at_p['swatches']) && is_array($at_p['swatches']) ? array_slice(array_values($at_p['swatches']), 0, 6) : [] as $at_sw) {
    if (is_array($at_sw) && isset($at_sw['label']) && is_string($at_sw['label']) && $at_sw['label'] !== '') {
        $at_swatches[] = ['label' => $at_sw['label'], 'color' => atelier_hex(isset($at_sw['color']) ? $at_sw['color'] : '')];
    }
}
?>
<article class="at-card af-product-card<?= ($at_shape === 'tall' ? ' at-ratio-tall' : ($at_shape === 'square' ? ' at-ratio-square' : '')) ?><?= in_array('out', $at_badges, true) ? ' is-out' : '' ?>"<?= $at_buy !== null ? ' data-af-card data-af-card-url="' . e($at_url) . '"' . ($at_live ? ' data-af-variants' : '') : '' ?>>
<?php if ($at_url !== '') : ?>
    <a class="at-card-link af-product-card-link" href="<?= e($at_url) ?>">
<?php else : ?>
    <div class="at-card-link af-product-card-link">
<?php endif; ?>
        <figure class="at-card-fig">
            <div class="at-card-media af-product-card-media">
                <?= image_tag($at_image, 'card', ['class' => 'at-card-img af-product-card-img', 'alt' => '']) ?>
                <?php if ($at_second !== null) : ?>
                    <?= image_tag($at_second, 'card', ['class' => 'at-card-alt', 'alt' => '']) ?>
                <?php endif; ?>
                <?= $this->partial('partials/badges', ['product' => $at_p, 'tag' => 'p', 'class' => 'at-badges']) ?>
            </div>
            <figcaption class="at-card-info af-product-card-body">
                <?php if ($at_show_brand) : ?>
                    <small class="at-card-brand af-product-card-brand"><?= e($at_brand) ?></small>
                <?php endif; ?>
                <h3 class="at-card-name af-product-card-name"><?= e($at_name) ?></h3>
                <?= $this->partial('partials/price', ['price' => $at_price, 'size' => 'sm', 'live' => $at_live]) ?>
                <?php if ($at_swatches !== []) : ?>
                    <ul class="at-swatches" aria-label="<?= e(t('Colours')) ?>">
                        <?php foreach ($at_swatches as $at_sw) : ?>
                            <li class="at-swatch"<?= ($at_sw['color'] !== '' ? ' style="--sw:' . e($at_sw['color']) . '"' : '') ?> title="<?= e($at_sw['label']) ?>"><span class="af-sr"><?= e($at_sw['label']) ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </figcaption>
        </figure>
<?php if ($at_url !== '') : ?>
    </a>
<?php else : ?>
    </div>
<?php endif; ?>
<?php if ($at_buy !== null) : ?>
    <?= $this->partial('catalog::front/partials/card-options', ['product' => $at_p, 'class' => 'at-buy-row']) ?>
<?php endif; ?>
</article>
