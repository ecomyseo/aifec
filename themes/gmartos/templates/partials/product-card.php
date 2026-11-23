<?php
/**
 * AIFEC — tarjeta de producto de gmartos. En los listados: imagen sobre fondo suave con la insignia «¡Oferta!», nombre,
 * extracto de unas dieciocho palabras, precio y el botón azul «Añadir al carrito». Con $home (la portada): la imagen en un
 * recuadro de color, la marca en letra monoespaciada, el nombre grande y, abajo, el precio y «Ver producto ↗». En las dos,
 * todo el bloque enlaza a la ficha.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $product ProductCard: id, name, url, image, price, availability, brand, rating, badges, has_variants
 * @var bool                $home    true en la portada
 */

$gm_p = isset($product) && is_array($product) ? $product : [];
$gm_home = !empty($home);
$gm_url = isset($gm_p['url']) ? (string) $gm_p['url'] : '';
$gm_name = isset($gm_p['name']) ? (string) $gm_p['name'] : '';
$gm_id = isset($gm_p['id']) ? (int) $gm_p['id'] : 0;
$gm_brand = isset($gm_p['brand']) && is_string($gm_p['brand']) ? trim($gm_p['brand']) : '';
$gm_image = isset($gm_p['image']) && is_array($gm_p['image']) ? $gm_p['image'] : null;
$gm_price = isset($gm_p['price']) && is_array($gm_p['price']) ? $gm_p['price'] : [];
$gm_avail = isset($gm_p['availability']) && is_array($gm_p['availability']) ? $gm_p['availability'] : [];
$gm_rating = isset($gm_p['rating']) && is_array($gm_p['rating']) ? $gm_p['rating'] : null;
$gm_have = isset($gm_p['badges']) && is_array($gm_p['badges']) ? $gm_p['badges'] : [];
$gm_badges = array_values(array_intersect(['sale', 'new', 'out'], $gm_have));
$gm_out = in_array('out', $gm_badges, true) || (isset($gm_avail['status']) && $gm_avail['status'] === 'out_of_stock');
$gm_variants = !empty($gm_p['has_variants']);
$gm_buyable = !isset($gm_avail['purchasable']) || !empty($gm_avail['purchasable']);
$gm_show_brand = $gm_brand !== '' && (int) theme_option('show_brand_in_card', 1) === 1;
$gm_avg = $gm_rating !== null && isset($gm_rating['average']) ? max(0.0, min(5.0, (float) $gm_rating['average'])) : 0.0;
$gm_count = $gm_rating !== null && isset($gm_rating['count']) ? (int) $gm_rating['count'] : 0;
$gm_excerpt = gmartos_excerpt($gm_p, $gm_home ? 12 : 18);
$gm_can_add = gmartos_can_add() && $gm_id > 0;
// Comprar desde la tarjeta (elegir combinación y unidades): lo trae el dato «buy» si la tienda lo tiene encendido. La tarjeta
// de la portada es un escaparate y no lo lleva.
$gm_buy = !$gm_home && $gm_can_add && isset($gm_p['buy']) && is_array($gm_p['buy']) ? $gm_p['buy'] : null;
$gm_live = $gm_buy !== null && !empty($gm_buy['variants']);
?>
<?php if ($gm_home) : ?>
<article class="gm-card gm-card-home af-product-card<?= $gm_out ? ' is-out' : '' ?>">
    <span class="af-product-card-media">
        <?= image_tag($gm_image, 'card', ['class' => 'gm-card-img af-product-card-img', 'alt' => '']) ?>
    </span>
    <div class="af-product-card-body">
        <?php if ($gm_show_brand) : ?>
            <span class="gm-card-kicker af-product-card-brand"><?= e($gm_brand) ?></span>
        <?php endif; ?>
        <h3 class="af-product-card-name"><?= e($gm_name) ?></h3>
        <?php if ($gm_excerpt !== '') : ?>
            <p class="gm-card-excerpt"><?= e($gm_excerpt) ?></p>
        <?php endif; ?>
        <div class="gm-card-bottom">
            <?= $this->partial('partials/price', ['price' => $gm_price, 'size' => 'md']) ?>
            <?php if ($gm_url !== '') : ?>
                <a class="gm-card-more af-product-card-link" href="<?= e($gm_url) ?>"><span><?= e(t('View product')) ?><span class="af-sr">: <?= e($gm_name) ?></span></span><?= gmartos_svg('arrow', 17) ?></a>
            <?php endif; ?>
        </div>
    </div>
</article>
<?php else : ?>
<article class="gm-card af-product-card<?= $gm_out ? ' is-out' : '' ?>"<?= $gm_buy !== null ? ' data-af-card data-af-card-url="' . e($gm_url) . '"' . ($gm_live ? ' data-af-variants' : '') : '' ?>>
    <figure class="gm-card-fig">
        <a class="af-product-card-media" href="<?= e($gm_url !== '' ? $gm_url : '#') ?>" tabindex="-1" aria-hidden="true">
            <?= image_tag($gm_image, 'card', ['class' => 'gm-card-img af-product-card-img', 'alt' => '']) ?>
        </a>
        <?= $this->partial('partials/badges', ['product' => $gm_p, 'tag' => 'ul', 'item' => 'li', 'sale_label' => t('Sale')]) ?>
    </figure>
    <div class="af-product-card-body">
        <?php if ($gm_show_brand) : ?>
            <p class="gm-card-kicker af-product-card-brand"><?= e($gm_brand) ?></p>
        <?php endif; ?>
        <h3 class="af-product-card-name">
            <?php if ($gm_url !== '') : ?>
                <a class="gm-card-link af-product-card-link" href="<?= e($gm_url) ?>"><?= e($gm_name) ?></a>
            <?php else : ?>
                <?= e($gm_name) ?>
            <?php endif; ?>
        </h3>
        <?php if ($gm_count > 0) : ?>
            <p class="af-product-card-rating">
                <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($gm_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($gm_avg, 1, '.', '')) ?>"></span>
                <span class="af-small af-muted">(<?= e($gm_count) ?>)</span>
            </p>
        <?php endif; ?>
        <?php if ($gm_excerpt !== '') : ?>
            <div class="gm-card-excerpt"><?= e($gm_excerpt) ?></div>
        <?php endif; ?>
        <footer class="gm-card-buy">
            <?= $this->partial('partials/price', ['price' => $gm_price, 'size' => 'md', 'live' => $gm_live]) ?>
            <?php if ($gm_out) : ?>
                <span class="gm-add af-btn af-btn-primary af-btn-sm is-disabled" aria-disabled="true"><?= e(t('Sold out')) ?></span>
            <?php elseif (!$gm_buyable) : ?>
                <?php /* un plugin dejó el producto como «no se puede comprar»: la tarjeta no lleva botón */ ?>
            <?php elseif ($gm_buy !== null) : ?>
                <?= $this->partial('catalog::front/partials/card-options', [
                    'product' => $gm_p, 'class' => 'gm-add-form', 'action' => gmartos_add_action(),
                    'button_class' => 'gm-add af-btn af-btn-primary af-btn-sm',
                    'button_html' => e(t('Add to cart')),
                ]) ?>
            <?php elseif ($gm_variants && $gm_url !== '') : ?>
                <a class="gm-add af-btn af-btn-primary af-btn-sm" href="<?= e($gm_url) ?>"><?= e(t('Choose options')) ?></a>
            <?php elseif ($gm_can_add) : ?>
                <form class="gm-add-form" method="post" action="<?= e(gmartos_add_action()) ?>" data-af-cart-add<?= hook('webmcp.form', 'add_to_cart', ['n' => (int) $gm_id, 'name' => $gm_name]) ?>>
                    <input type="hidden" name="product_id" value="<?= e($gm_id) ?>"<?= hook('webmcp.param', 'add_to_cart', 'product_id') ?>>
                    <input type="hidden" name="variant_id" value="0"<?= hook('webmcp.param', 'add_to_cart', 'variant_id') ?>>
                    <input type="hidden" name="qty" value="1"<?= hook('webmcp.param', 'add_to_cart', 'qty') ?>>
                    <button type="submit" class="gm-add af-btn af-btn-primary af-btn-sm" data-af-add><?= e(t('Add to cart')) ?></button>
                </form>
            <?php endif; ?>
        </footer>
    </div>
</article>
<?php endif; ?>
