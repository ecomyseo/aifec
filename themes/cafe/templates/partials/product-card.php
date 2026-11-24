<?php
/**
 * AIFEC — tarjeta de producto de Café, «cápsula y su veredicto». Sin caja: una figura con la foto cuadrada sobre el tono
 * leche, que llega al borde de su marco, y pegada a su borde inferior la cinta a todo el ancho con el sistema para el que
 * vale. Debajo, el nombre a todo el ancho, la intensidad como escala, la línea de taza y tueste, y un pie con el precio,
 * el precio por unidad o por kilo y el botón de añadir (con variantes, lleva a la ficha). La cinta, la escala, la línea y
 * el precio por unidad salen de los atributos del producto: sin dato no se pintan.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $product ProductCard: id, name, url, image, price, availability, brand, rating, badges, badge_list, has_variants
 */

$cf_p = isset($product) && is_array($product) ? $product : [];
$cf_id = isset($cf_p['id']) ? (int) $cf_p['id'] : 0;
$cf_url = isset($cf_p['url']) ? (string) $cf_p['url'] : '';
$cf_name = isset($cf_p['name']) ? (string) $cf_p['name'] : '';
$cf_brand = isset($cf_p['brand']) && is_string($cf_p['brand']) ? trim($cf_p['brand']) : '';
$cf_image = isset($cf_p['image']) && is_array($cf_p['image']) ? $cf_p['image'] : null;
$cf_price = isset($cf_p['price']) && is_array($cf_p['price']) ? $cf_p['price'] : [];
$cf_rating = isset($cf_p['rating']) && is_array($cf_p['rating']) ? $cf_p['rating'] : null;
$cf_avg = $cf_rating !== null && isset($cf_rating['average']) ? max(0.0, min(5.0, (float) $cf_rating['average'])) : 0.0;
$cf_count = $cf_rating !== null && isset($cf_rating['count']) ? (int) $cf_rating['count'] : 0;
$cf_codes = isset($cf_p['badges']) && is_array($cf_p['badges']) ? $cf_p['badges'] : [];
$cf_out = in_array('out', $cf_codes, true);
// Las insignias de la foto: nuevo, agotado y las que añada un plugin. La oferta se dice en la fila del precio.
$cf_flags = [];
foreach (isset($cf_p['badge_list']) && is_array($cf_p['badge_list']) ? $cf_p['badge_list'] : [] as $cf_badge) {
    if (is_array($cf_badge) && isset($cf_badge['code'], $cf_badge['label']) && (string) $cf_badge['code'] !== 'sale' && (string) $cf_badge['label'] !== '') {
        $cf_flags[] = [(string) $cf_badge['code'], (string) $cf_badge['label']];
    }
}
if ($cf_flags === []) {
    foreach (array_intersect(['new', 'out'], $cf_codes) as $cf_code) {
        $cf_flags[] = [$cf_code, $cf_code === 'new' ? t('New') : t('Sold out')];
    }
}
$cf_info = cafe_info($cf_p);
$cf_ribbon = cafe_ribbon($cf_info['systems']);
$cf_sys = [];
foreach ($cf_info['systems'] as $cf_system) {
    if ($cf_system['code'] !== '') {
        $cf_sys[] = $cf_system['code'];
    }
}
$cf_max = cafe_scale_max();
$cf_scale = (int) theme_option('card_scale', 1) === 1 ? $cf_info['intensity'] : 0;
$cf_line = array_filter([implode(', ', array_slice($cf_info['cups'], 0, 2)), $cf_info['roast']], 'strlen');
$cf_unit = (int) theme_option('unit_price_show', 1) === 1 ? $cf_info['unit_price'] : '';
$cf_buyable = !$cf_out && (!isset($cf_p['availability']['purchasable']) || !empty($cf_p['availability']['purchasable']));
$cf_add = (int) theme_option('card_add', 1) === 1 && $cf_buyable && $cf_id > 0 && cafe_can_add();
$cf_choose = $cf_add && !empty($cf_p['has_variants']) && $cf_url !== '';
?>
<article class="af-product-card cf-card<?php if ($cf_out) : ?> is-out<?php endif; ?>"<?php if ($cf_sys !== []) : ?> data-cf-sys="<?= e(implode(' ', $cf_sys)) ?>"<?php endif; ?>>
    <figure class="cf-shot">
        <?= image_tag($cf_image, 'card', ['class' => 'cf-shot-img', 'alt' => $cf_name]) ?>
        <?php if ($cf_ribbon !== '') : ?>
            <figcaption class="cf-ribbon" data-cf-ribbon data-cf-yes="<?= e(t('Fits your coffee maker')) ?>" data-cf-no="<?= e(t('Does not fit your coffee maker')) ?>"><?= e($cf_ribbon) ?></figcaption>
        <?php endif; ?>
    </figure>
    <h3 class="cf-card-name">
        <?php if ($cf_url !== '') : ?>
            <a class="cf-card-link" href="<?= e($cf_url) ?>"><?= e($cf_name) ?></a>
        <?php else : ?>
            <?= e($cf_name) ?>
        <?php endif; ?>
    </h3>
    <?php if ($cf_flags !== [] || ($cf_brand !== '' && (int) theme_option('show_brand_in_card', 1) === 1)) : ?>
        <p class="cf-card-flags">
            <?php foreach ($cf_flags as $cf_flag) : ?>
                <mark class="af-badge af-badge-<?= e($cf_flag[0]) ?>"><?= e($cf_flag[1]) ?></mark>
            <?php endforeach; ?>
            <?php if ($cf_brand !== '' && (int) theme_option('show_brand_in_card', 1) === 1) : ?>
                <span class="cf-card-brand"><?= e($cf_brand) ?></span>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ($cf_scale > 0) : ?>
        <p class="cf-scale" style="--cf-v:<?= e($cf_scale) ?>;--cf-max:<?= e($cf_max) ?>"><span class="af-sr"><?= e(t('Intensity {n} of {max}', ['n' => $cf_scale, 'max' => $cf_max])) ?></span><b aria-hidden="true"><?= e($cf_scale) ?></b></p>
    <?php endif; ?>
    <?php if ($cf_line !== []) : ?>
        <p class="cf-card-cup"><?= e(implode(' · ', $cf_line)) ?></p>
    <?php endif; ?>
    <?php if ($cf_count > 0) : ?>
        <p class="cf-card-rating">
            <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($cf_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($cf_avg, 1, '.', '')) ?>"></span>
            <small class="af-muted">(<?= e($cf_count) ?>)</small>
        </p>
    <?php endif; ?>
    <footer class="cf-card-buy">
        <?= $this->partial('partials/price', ['price' => $cf_price, 'size' => 'sm']) ?>
        <?php if ($cf_unit !== '') : ?>
            <small class="cf-unit"><?= e($cf_unit) ?></small>
        <?php endif; ?>
        <?php if ($cf_choose) : ?>
            <a class="cf-add" href="<?= e($cf_url) ?>"><?= e(t('Choose')) ?><span class="af-sr">: <?= e($cf_name) ?></span></a>
        <?php elseif ($cf_add) : ?>
            <form class="cf-add-form" method="post" action="<?= e(cafe_add_action()) ?>" data-af-cart-add<?= hook('webmcp.form', 'add_to_cart', ['n' => (int) $cf_id, 'name' => $cf_name]) ?>>
                <input type="hidden" name="product_id" value="<?= e($cf_id) ?>"<?= hook('webmcp.param', 'add_to_cart', 'product_id') ?>>
                <input type="hidden" name="variant_id" value="0"<?= hook('webmcp.param', 'add_to_cart', 'variant_id') ?>>
                <input type="hidden" name="qty" value="1"<?= hook('webmcp.param', 'add_to_cart', 'qty') ?>>
                <button type="submit" class="cf-add" data-af-add><?= cafe_svg('plus', 16) ?><?= e(t('Add')) ?><span class="af-sr">: <?= e($cf_name) ?></span></button>
            </form>
        <?php endif; ?>
    </footer>
</article>
