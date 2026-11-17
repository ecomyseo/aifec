<?php
/**
 * AIFEC — ficha de producto de Café, «la taza y su cafetera» (sustituye `catalog::front/product`). A la izquierda, la
 * foto cuadrada grande con las vistas en una columna a su derecha y, debajo, la ficha de cata (intensidad en escala,
 * tueste en cuatro granos, tazas y notas) con los datos clave. A la derecha, la compra: sección y marca, nombre,
 * entradilla, para qué cafeteras vale, precio con el precio por unidad o por kilo, variantes, cantidad y botón, y la
 * disponibilidad. Debajo, la descripción junto a la tabla de datos. La ficha de cata, las cafeteras y el precio por
 * unidad salen de los atributos del producto: sin dato no se pintan. Conserva todos los datos, los formularios y los
 * huecos `product.*` de la plantilla original.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                  $page             datos de cabecera (ver Aifec\Front\Page)
 * @var array<string,mixed>                  $product          ProductData
 * @var array<int,array<string,mixed>>       $crumbs
 * @var array<int,array<string,mixed>>       $related          tarjetas de los productos relacionados
 * @var bool                                 $can_buy          existe la ruta cart.add
 * @var array<string,mixed>|null             $initial          variante elegida de serie (solo con variantes)
 * @var array<string,mixed>                  $price            bloque de precio de lo que se enseña
 * @var string                               $sku
 * @var array<string,mixed>                  $stock            disponibilidad de lo que se enseña
 * @var string                               $stock_class      is-ok, is-low o is-out
 * @var string                               $delivery         plazo de entrega en palabras (puede ir vacío)
 * @var bool                                 $sold_out
 * @var string                               $summary_html
 * @var string                               $description_html
 * @var array<int,array<string,string>>      $faqs             question, answer (HTML limpio)
 * @var array<string,mixed>|null             $variants_data    lo que lee theme.js (solo con variantes)
 * @var string                               $choose_action    dirección del paso que resuelve la variante sin JavaScript
 */

$this->layout('layout', ['page' => $page]);
$cf_p = $product;
$cf_has_variants = $initial !== null && is_array($variants_data);
$cf_images = isset($cf_p['images']) && is_array($cf_p['images']) ? array_values($cf_p['images']) : [];
$cf_rating = isset($cf_p['rating']) && is_array($cf_p['rating']) ? $cf_p['rating'] : null;
$cf_avg = $cf_rating !== null ? max(0.0, min(5.0, (float) $cf_rating['average'])) : 0.0;
$cf_count = $cf_rating !== null ? (int) $cf_rating['count'] : 0;
$cf_min = max(1, (int) $cf_p['min_qty']);
$cf_max_qty = (int) $cf_p['max_qty'] > 0 ? (int) $cf_p['max_qty'] : null;
$cf_selected = $cf_has_variants && isset($initial['values']) && is_array($initial['values']) ? $initial['values'] : [];
$cf_main = isset($cf_p['main_category']) && is_array($cf_p['main_category']) ? $cf_p['main_category'] : null;

// La tabla de datos: los atributos visibles, los datos públicos que añaden los plugins y las referencias.
$cf_specs = [];
foreach (is_array($cf_p['attributes']) ? $cf_p['attributes'] : [] as $cf_attr) {
    $cf_specs[] = [(string) $cf_attr['name'], (string) $cf_attr['value']];
}
foreach (is_array($cf_p['extra']) ? $cf_p['extra'] : [] as $cf_extra) {
    if (is_array($cf_extra) && !empty($cf_extra['public']) && isset($cf_extra['label']) && isset($cf_extra['value']) && is_scalar($cf_extra['value']) && (string) $cf_extra['value'] !== '') {
        $cf_specs[] = [(string) $cf_extra['label'], (string) $cf_extra['value']];
    }
}
foreach ([[t('GTIN'), $cf_p['gtin']], [t('MPN'), $cf_p['mpn']]] as $cf_ref) {
    if ($cf_ref[1] !== null && (string) $cf_ref[1] !== '') {
        $cf_specs[] = [$cf_ref[0], (string) $cf_ref[1]];
    }
}
$cf_facts = array_slice(is_array($cf_p['facts']) ? $cf_p['facts'] : [], 0, 6);
// Lo que este tema sabe del café, de sus atributos.
$cf_info = cafe_info($cf_p, $price);
$cf_scale_max = cafe_scale_max();
$cf_sys = implode(' ', array_filter(array_column($cf_info['systems'], 'code'), 'strlen'));
$cf_tasting = $cf_info['intensity'] > 0 || $cf_info['roast'] !== '' || $cf_info['cups'] !== [] || $cf_info['notes'] !== '';
$cf_first = $cf_images !== [] ? $cf_images[0] : null;
if ($cf_first !== null && (string) $cf_first['alt'] === '') {
    $cf_first['alt'] = (string) $cf_p['name'];
}
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <article class="af-product cf-pour"<?= $cf_has_variants ? ' data-af-variants' : '' ?>>
        <div class="af-product-gallery">
            <div class="af-gallery cf-views<?= count($cf_images) > 1 ? ' has-thumbs' : '' ?>" data-af-gallery>
                <figure class="af-gallery-main">
                    <?= image_tag($cf_first, 'large', ['loading' => 'eager', 'fetchpriority' => 'high', 'data-af-gallery-main' => true]) ?>
                </figure>
                <?php if (count($cf_images) > 1) : ?>
                    <ol class="af-gallery-thumbs">
                        <?php foreach ($cf_images as $cf_i => $cf_image) : ?>
                            <li><a href="<?= e((string) $cf_image['large']) ?>" data-src="<?= e((string) $cf_image['large']) ?>" data-alt="<?= e((string) $cf_image['alt'] !== '' ? (string) $cf_image['alt'] : (string) $cf_p['name']) ?>" data-image-id="<?= e($cf_image['id']) ?>" aria-label="<?= e(t('Image {n}', ['n' => $cf_i + 1])) ?>"<?= $cf_i === 0 ? ' aria-current="true"' : '' ?>>
                                <?= image_tag($cf_image, 'thumb', ['alt' => '']) ?>
                            </a></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
            <?= hook('product.gallery.after', $cf_p) ?>
            <?php if ($cf_tasting || $cf_facts !== []) : ?>
                <aside class="cf-sheet" aria-labelledby="cf-sheet-title">
                    <h2 id="cf-sheet-title"><?= e($cf_tasting ? t('Tasting sheet') : t('Key facts')) ?></h2>
                    <?php if ($cf_tasting) : ?>
                        <table class="cf-sheet-cata">
                            <tbody>
                                <?php if ($cf_info['intensity'] > 0) : ?>
                                    <tr>
                                        <th scope="row"><?= e(t('Intensity')) ?></th>
                                        <td><span class="cf-scale cf-scale-lg" style="--cf-v:<?= e($cf_info['intensity']) ?>;--cf-max:<?= e($cf_scale_max) ?>"><b><?= e($cf_info['intensity']) ?></b></span> <small class="af-muted"><?= e(t('of {max}', ['max' => $cf_scale_max])) ?></small></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ($cf_info['roast'] !== '') : ?>
                                    <tr>
                                        <th scope="row"><?= e(t('Roast')) ?></th>
                                        <td>
                                            <?php if ($cf_info['roast_step'] > 0) : ?>
                                                <span class="cf-roast" data-step="<?= e($cf_info['roast_step']) ?>" aria-hidden="true"><?= cafe_svg('bean', 20) ?><?= cafe_svg('bean', 20) ?><?= cafe_svg('bean', 20) ?><?= cafe_svg('bean', 20) ?></span>
                                            <?php endif; ?>
                                            <?= e($cf_info['roast']) ?>
                                        </td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ($cf_info['cups'] !== []) : ?>
                                    <tr>
                                        <th scope="row"><?= e(t('Cup')) ?></th>
                                        <td><?= e(implode(' · ', $cf_info['cups'])) ?></td>
                                    </tr>
                                <?php endif; ?>
                                <?php if ($cf_info['notes'] !== '') : ?>
                                    <tr>
                                        <th scope="row"><?= e(t('Notes')) ?></th>
                                        <td><?= e($cf_info['notes']) ?></td>
                                    </tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                    <?php if ($cf_facts !== []) : ?>
                        <dl class="af-facts">
                            <?php foreach ($cf_facts as $cf_fact) : ?>
                                <div><dt><?= e((string) $cf_fact['label']) ?></dt><dd><?= e((string) $cf_fact['value']) ?></dd></div>
                            <?php endforeach; ?>
                        </dl>
                    <?php endif; ?>
                </aside>
            <?php endif; ?>
        </div>
        <div class="af-product-buy">
            <?= hook('product.summary.before', $cf_p) ?>
            <?php if ($cf_main !== null || is_array($cf_p['brand'])) : ?>
                <p class="cf-pour-kicker">
                    <?php if ($cf_main !== null) : ?>
                        <a href="<?= e((string) $cf_main['url']) ?>"><?= e((string) $cf_main['name']) ?></a>
                    <?php endif; ?>
                    <?php if (is_array($cf_p['brand'])) : ?>
                        <a href="<?= e((string) $cf_p['brand']['url']) ?>"><?= e((string) $cf_p['brand']['name']) ?></a>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
            <h1 class="af-title"><?= e((string) $cf_p['name']) ?></h1>
            <?php if ($cf_count > 0) : ?>
                <p class="cf-pour-rating">
                    <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($cf_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($cf_avg, 1, '.', '')) ?>"></span>
                    <small class="af-muted">(<?= e($cf_count) ?>)</small>
                </p>
            <?php endif; ?>
            <?php if ($summary_html !== '') : ?>
                <div class="cf-pour-lead af-prose"><?= $summary_html ?></div>
            <?php endif; ?>
            <?php if ($cf_info['systems'] !== []) : ?>
                <nav class="cf-verdict" aria-label="<?= e(t('Compatible with')) ?>"<?= $cf_sys !== '' ? ' data-cf-sys="' . e($cf_sys) . '"' : '' ?>>
                    <b class="cf-verdict-title" data-cf-ribbon data-cf-yes="<?= e(t('Fits your coffee maker')) ?>" data-cf-no="<?= e(t('Does not fit your coffee maker')) ?>"><?= e(t('Compatible with')) ?></b>
                    <?php foreach ($cf_info['systems'] as $cf_system) : ?>
                        <?php if ($cf_system['url'] !== '') : ?>
                            <a href="<?= e($cf_system['url']) ?>" data-cf-pick="<?= e($cf_system['code']) ?>" data-cf-label="<?= e($cf_system['label']) ?>"><?= cafe_svg($cf_system['pict'], 18) ?><?= e($cf_system['label']) ?></a>
                        <?php else : ?>
                            <span><?= cafe_svg($cf_system['pict'], 18) ?><?= e($cf_system['label']) ?></span>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </nav>
            <?php endif; ?>
            <p class="cf-pour-price">
                <?= $this->partial('partials/price', ['price' => $price, 'size' => 'lg', 'live' => $cf_has_variants]) ?>
                <?php if ($cf_info['unit_price'] !== '') : ?>
                    <small class="cf-unit"><?= e($cf_info['unit_price']) ?></small>
                <?php endif; ?>
            </p>
            <?= hook('product.price.after', $cf_p) ?>
            <?php if ($can_buy) : ?>
                <form class="cf-pour-form" method="post" action="<?= e(route('cart.add')) ?>" data-af-cart-add<?= hook('webmcp.form', 'add_to_cart') ?>>
                    <input type="hidden" name="product_id" value="<?= e($cf_p['id']) ?>"<?= hook('webmcp.param', 'add_to_cart', 'product_id') ?>>
                    <input type="hidden" name="variant_id" value="<?= e($cf_has_variants ? $initial['id'] : 0) ?>" data-af-variant-id<?= hook('webmcp.param', 'add_to_cart', 'variant_id') ?>>
                    <?php if ($cf_has_variants) : ?>
                        <?= $this->partial('catalog::front/partials/options', ['options' => $cf_p['options'], 'selected' => $cf_selected, 'tool' => 'add_to_cart']) ?>
                        <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                    <?php endif; ?>
                    <div class="af-buy-row">
                        <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => $cf_min, 'min' => $cf_min, 'max' => $cf_max_qty, 'tool' => 'add_to_cart']) ?>
                        <button type="submit" class="af-btn af-btn-primary af-btn-lg" data-af-add<?= $cf_has_variants && $choose_action !== '' ? ' formaction="' . e($choose_action) . '"' : '' ?><?= $sold_out ? ' disabled' : '' ?>><?= e($sold_out && !$cf_has_variants ? t('Sold out') : t('Add to cart')) ?></button>
                    </div>
                </form>
            <?php elseif ($cf_has_variants) : ?>
                <div class="cf-pour-form">
                    <?= $this->partial('catalog::front/partials/options', ['options' => $cf_p['options'], 'selected' => $cf_selected]) ?>
                    <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                </div>
            <?php endif; ?>
            <ul class="cf-pour-status">
                <li class="af-availability <?= e($stock_class) ?>" data-af-availability><?= e((string) $stock['label']) ?></li>
                <?php if ($delivery !== '') : ?>
                    <li class="af-small af-muted"><?= e($delivery) ?></li>
                <?php endif; ?>
                <?php if ($sku !== '' || $cf_has_variants) : ?>
                    <li class="af-small af-muted"><?= e(t('SKU')) ?>: <span data-af-sku><?= e($sku) ?></span></li>
                <?php endif; ?>
            </ul>
            <?= hook('product.buy.after', $cf_p) ?>
            <?= hook('product.summary.after', $cf_p) ?>
        </div>
    </article>

    <div class="cf-pour-more">
        <?php if ($description_html !== '' || $cf_specs !== []) : ?>
            <div class="cf-pour-about">
                <?php if ($description_html !== '') : ?>
                    <section id="af-description">
                        <h2><?= e(t('Description')) ?></h2>
                        <div class="af-prose"><?= $description_html ?></div>
                    </section>
                <?php endif; ?>
                <?php if ($cf_specs !== []) : ?>
                    <section id="af-specs">
                        <h2><?= e(t('Specifications')) ?></h2>
                        <div class="af-table-wrap">
                            <table class="af-table">
                                <tbody>
                                    <?php foreach ($cf_specs as $cf_spec) : ?>
                                        <tr><th scope="row"><?= e($cf_spec[0]) ?></th><td><?= e($cf_spec[1]) ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </section>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?= hook('product.tabs', $cf_p) ?>
        <?php if ($faqs !== []) : ?>
            <section class="af-section" id="af-faq">
                <h2><?= e(t('Frequently asked questions')) ?></h2>
                <?php foreach ($faqs as $cf_faq) : ?>
                    <details class="af-card">
                        <summary><?= e($cf_faq['question']) ?></summary>
                        <div class="af-prose"><?= $cf_faq['answer'] ?></div>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <?php if ($related !== []) : ?>
            <section class="af-section" id="af-related">
                <h2><?= e(t('Related products')) ?></h2>
                <ol class="af-grid af-cols-4 cf-shelf-grid">
                    <?php foreach ($related as $cf_card) : ?>
                        <li><?= $this->partial('partials/product-card', ['product' => $cf_card]) ?></li>
                    <?php endforeach; ?>
                </ol>
            </section>
        <?php endif; ?>
        <?= hook('product.bottom', $cf_p) ?>
    </div>
</div>
