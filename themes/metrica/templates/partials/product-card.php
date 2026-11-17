<?php
/**
 * AIFEC — tarjeta de producto de Métrica, «etiqueta de cajón»: caja con filete. Arriba, la foto cuadrada, que llena su
 * hueco. Debajo, la etiqueta: la línea de medidas en letra de máquina (solo si el producto la tiene: sale de sus variantes o
 * de sus atributos numéricos), el nombre a todo el ancho y el precio en su fila, con el anterior tachado y el descuento. Al
 * pie, la compra: en una pieza sin medidas, cantidad y «Añadir»; en una con medidas, «Elegir medida» con cuántas hay.
 *
 * La tarjeta se repite muchas veces por página: su HTML va sin sangría a propósito (cada espacio se multiplica por veinte).
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $product ProductCard: id, name, url, image, price, availability, brand, rating, badges, has_variants, buy
 */

$mt_p = isset($product) && is_array($product) ? $product : [];
$mt_id = isset($mt_p['id']) ? (int) $mt_p['id'] : 0;
$mt_url = isset($mt_p['url']) ? (string) $mt_p['url'] : '';
$mt_name = isset($mt_p['name']) ? (string) $mt_p['name'] : '';
$mt_brand = isset($mt_p['brand']) && is_string($mt_p['brand']) && (int) theme_option('show_brand_in_card', 1) === 1 ? trim($mt_p['brand']) : '';
$mt_image = isset($mt_p['image']) && is_array($mt_p['image']) ? $mt_p['image'] : null;
$mt_price = isset($mt_p['price']) && is_array($mt_p['price']) ? $mt_p['price'] : [];
$mt_rating = isset($mt_p['rating']) && is_array($mt_p['rating']) ? $mt_p['rating'] : null;
$mt_avg = $mt_rating !== null && isset($mt_rating['average']) ? max(0.0, min(5.0, (float) $mt_rating['average'])) : 0.0;
$mt_votes = $mt_rating !== null && isset($mt_rating['count']) ? (int) $mt_rating['count'] : 0;
$mt_avail = isset($mt_p['availability']) && is_array($mt_p['availability']) ? $mt_p['availability'] : [];
$mt_codes = isset($mt_p['badges']) && is_array($mt_p['badges']) ? $mt_p['badges'] : [];
$mt_out = in_array('out', $mt_codes, true) || (isset($mt_avail['status']) && $mt_avail['status'] === 'out_of_stock');
$mt_buyable = !isset($mt_avail['purchasable']) || !empty($mt_avail['purchasable']);
$mt_variants = !empty($mt_p['has_variants']);
$mt_show_line = (int) theme_option('card_measures', 1) === 1;
$mt_measures = $mt_variants || $mt_show_line ? metrica_measures($mt_p) : ['line' => '', 'count' => 0];
$mt_line = $mt_show_line ? $mt_measures['line'] : '';
$mt_scale = preg_match('/\d/', $mt_measures['line']) === 1;
// Las marcas que no son la oferta (esa va en la fila del precio) ni «Agotado» (que va al pie): «Nuevo» y las de los plugins.
$mt_flags = [];
if (isset($mt_p['badge_list']) && is_array($mt_p['badge_list']) && $mt_p['badge_list'] !== []) {
    foreach ($mt_p['badge_list'] as $mt_badge) {
        if (is_array($mt_badge) && isset($mt_badge['label']) && is_scalar($mt_badge['label']) && trim((string) $mt_badge['label']) !== ''
            && (!isset($mt_badge['code']) || !in_array($mt_badge['code'], ['sale', 'out'], true))) {
            $mt_flags[] = (string) $mt_badge['label'];
        }
    }
} elseif (in_array('new', $mt_codes, true)) {
    $mt_flags[] = t('New');
}
$mt_can_add = metrica_can_add() && $mt_id > 0;
$mt_max = isset($mt_p['buy']['max_qty']) && (int) $mt_p['buy']['max_qty'] > 0 ? (int) $mt_p['buy']['max_qty'] : 0;
?>
<article class="af-product-card mt-card<?= ($mt_out ? ' is-out' : '') ?>">
<?php if ($mt_url !== '') : ?>
<a class="mt-card-shot" href="<?= e($mt_url) ?>" tabindex="-1" aria-hidden="true"><?= image_tag($mt_image, 'card', ['class' => 'mt-card-img', 'alt' => '']) ?></a>
<?php else : ?>
<span class="mt-card-shot"><?= image_tag($mt_image, 'card', ['class' => 'mt-card-img', 'alt' => '']) ?></span>
<?php endif; ?>
<div class="mt-card-label">
<?php if ($mt_line !== '') : ?>
<p class="mt-card-sizes"><?= e($mt_line) ?></p>
<?php endif; ?>
<?php if ($mt_url !== '') : ?>
<h3 class="mt-card-name"><a href="<?= e($mt_url) ?>"><?= e($mt_name) ?></a></h3>
<?php else : ?>
<h3 class="mt-card-name"><?= e($mt_name) ?></h3>
<?php endif; ?>
<?php if ($mt_brand !== '' || $mt_votes > 0) : ?>
<p class="mt-card-meta">
<?php if ($mt_brand !== '') : ?>
<b><?= e($mt_brand) ?></b>
<?php endif; ?>
<?php if ($mt_votes > 0) : ?>
<span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($mt_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($mt_avg, 1, '.', '')) ?>"></span>
<small>(<?= e($mt_votes) ?>)</small>
<?php endif; ?>
</p>
<?php endif; ?>
<p class="mt-card-price">
<?= $this->partial('partials/price', ['price' => $mt_price, 'size' => 'sm']) ?>
<?php foreach ($mt_flags as $mt_flag) : ?>
<em class="mt-flag"><?= e($mt_flag) ?></em>
<?php endforeach; ?>
</p>
</div>
<?php if ($mt_out) : ?>
<p class="mt-card-buy"><span class="mt-card-pick is-off"><?= e(t('Sold out')) ?></span></p>
<?php elseif (!$mt_buyable) : ?>
<?php /* un plugin dejó el producto como «no se puede comprar»: la tarjeta no lleva pie de compra */ ?>
<?php elseif ($mt_variants && $mt_url !== '') : ?>
<p class="mt-card-buy"><a class="mt-card-pick" href="<?= e($mt_url) ?>"><span><?= e($mt_scale ? t('Choose measure') : t('Choose options')) ?></span><?php if ($mt_measures['count'] > 0) : ?> <small><?= e($mt_scale ? tn('{n} measure', '{n} measures', $mt_measures['count']) : tn('{n} option', '{n} options', $mt_measures['count'])) ?></small><?php endif; ?></a></p>
<?php elseif ($mt_can_add) : ?>
<form class="mt-card-buy" method="post" action="<?= e(metrica_add_action()) ?>" data-af-cart-add>
<input type="hidden" name="product_id" value="<?= e($mt_id) ?>">
<input type="hidden" name="variant_id" value="0">
<?php if ((int) theme_option('card_qty', 1) === 1) : ?>
<input class="mt-card-qty" type="number" name="qty" value="1" min="1"<?= ($mt_max > 0 ? ' max="' . e($mt_max) . '"' : '') ?> step="1" inputmode="numeric" aria-label="<?= e(t('Quantity of {name}', ['name' => $mt_name])) ?>">
<?php else : ?>
<input type="hidden" name="qty" value="1">
<?php endif; ?>
<button type="submit" class="mt-card-btn" data-af-add aria-label="<?= e(t('Add {name} to the cart', ['name' => $mt_name])) ?>"><?= e(t('Add')) ?></button>
</form>
<?php endif; ?>
</article>
