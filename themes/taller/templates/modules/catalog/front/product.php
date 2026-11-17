<?php
/**
 * AIFEC — ficha de producto en taller: galería a la izquierda con las miniaturas en vertical y la compra a la derecha en
 * una caja con borde (referencia y marca, precio con y sin IVA a la vez, disponibilidad y plazo, cantidad y compra). Debajo,
 * la tabla de características a todo el ancho antes que la descripción, los documentos descargables si los hay, las
 * preguntas y los accesorios relacionados. Conserva todos los huecos product.* de la plantilla original del catálogo.
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
$tl_p = $product;
$tl_has_variants = $initial !== null && is_array($variants_data);
$tl_images = isset($tl_p['images']) && is_array($tl_p['images']) ? array_values($tl_p['images']) : [];
$tl_rating = isset($tl_p['rating']) && is_array($tl_p['rating']) ? $tl_p['rating'] : null;
$tl_avg = $tl_rating !== null ? max(0.0, min(5.0, (float) $tl_rating['average'])) : 0.0;
$tl_count = $tl_rating !== null ? (int) $tl_rating['count'] : 0;
$tl_min = max(1, (int) $tl_p['min_qty']);
$tl_max = (int) $tl_p['max_qty'] > 0 ? (int) $tl_p['max_qty'] : null;
$tl_selected = $tl_has_variants && isset($initial['values']) && is_array($initial['values']) ? $initial['values'] : [];
$tl_below = theme_option('gallery_thumbs', 'side') === 'below';

// La tabla de características: atributos visibles, datos públicos de los plugins (menos los documentos) y referencias.
$tl_specs = [];
$tl_docs = [];
foreach (is_array($tl_p['attributes']) ? $tl_p['attributes'] : [] as $tl_attr) {
    $tl_specs[] = [(string) $tl_attr['name'], (string) $tl_attr['value']];
}
foreach (is_array($tl_p['extra']) ? $tl_p['extra'] : [] as $tl_extra) {
    $tl_doc = taller_doc($tl_extra);
    if ($tl_doc !== null) {
        $tl_docs[] = $tl_doc;
    } elseif (is_array($tl_extra) && !empty($tl_extra['public']) && isset($tl_extra['label']) && isset($tl_extra['value']) && is_scalar($tl_extra['value']) && (string) $tl_extra['value'] !== '') {
        $tl_specs[] = [(string) $tl_extra['label'], (string) $tl_extra['value']];
    }
}
foreach ([[t('GTIN'), $tl_p['gtin']], [t('MPN'), $tl_p['mpn']]] as $tl_ref) {
    if ($tl_ref[1] !== null && (string) $tl_ref[1] !== '') {
        $tl_specs[] = [$tl_ref[0], (string) $tl_ref[1]];
    }
}
$tl_facts = array_slice(is_array($tl_p['facts']) ? $tl_p['facts'] : [], 0, 6);
?>
<div class="tl-pdp-wrap af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <div class="tl-pdp af-product<?= $tl_below ? ' tl-pdp-below' : '' ?>"<?= $tl_has_variants ? ' data-af-variants' : '' ?>>
        <div class="tl-pmedia af-product-gallery">
            <div class="tl-gal af-gallery" data-af-gallery>
                <div class="tl-gal-stage af-gallery-main">
                    <?= hook('product.gallery.before', $tl_p) ?>
                    <?php
                    $tl_first = $tl_images !== [] ? $tl_images[0] : null;
                    if ($tl_first !== null && (string) $tl_first['alt'] === '') {
                        $tl_first['alt'] = (string) $tl_p['name'];
                    }
                    ?>
                    <?= image_tag($tl_first, 'large', ['loading' => 'eager', 'fetchpriority' => 'high', 'data-af-gallery-main' => true]) ?>
                </div>
                <?php if (count($tl_images) > 1) : ?>
                    <ol class="tl-gal-rail af-gallery-thumbs">
                        <?php foreach ($tl_images as $tl_i => $tl_image) : ?>
                            <li><a href="<?= e((string) $tl_image['large']) ?>" data-src="<?= e((string) $tl_image['large']) ?>" data-alt="<?= e((string) $tl_image['alt'] !== '' ? (string) $tl_image['alt'] : (string) $tl_p['name']) ?>" data-image-id="<?= e($tl_image['id']) ?>" aria-label="<?= e(t('Image {n}', ['n' => $tl_i + 1])) ?>"<?= $tl_i === 0 ? ' aria-current="true"' : '' ?>>
                                <?= image_tag($tl_image, 'thumb', ['alt' => '']) ?>
                            </a></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
            <?= hook('product.gallery.after', $tl_p) ?>
        </div>
        <div class="tl-buy af-product-buy">
            <?= hook('product.summary.before', $tl_p) ?>
            <p class="tl-buy-ref af-small">
                <?php if ($sku !== '' || $tl_has_variants) : ?>
                    <span class="tl-ref"><?= e(t('SKU')) ?>: <strong data-af-sku><?= e($sku) ?></strong></span>
                <?php endif; ?>
                <?php if (is_array($tl_p['brand'])) : ?>
                    <span class="tl-brand"><?= e(t('Brand')) ?>: <a href="<?= e((string) $tl_p['brand']['url']) ?>"><?= e((string) $tl_p['brand']['name']) ?></a></span>
                <?php endif; ?>
            </p>
            <h1 class="tl-pdp-title af-title"><?= e((string) $tl_p['name']) ?></h1>
            <?php if ($tl_count > 0) : ?>
                <p class="tl-rating af-small af-muted">
                    <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($tl_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($tl_avg, 1, '.', '')) ?>"></span>
                    <span>(<?= e($tl_count) ?>)</span>
                </p>
            <?php endif; ?>
            <div class="tl-pricebox">
                <?= $this->partial('partials/price', ['price' => $price, 'size' => 'lg', 'live' => $tl_has_variants, 'both' => true]) ?>
                <?= hook('product.price.after', $tl_p) ?>
            </div>
            <div class="tl-stockbox">
                <p class="tl-stock af-availability <?= e($stock_class) ?>" data-af-availability><?= e((string) $stock['label']) ?></p>
                <?php if ($delivery !== '') : ?>
                    <p class="tl-delivery af-small"><?= taller_svg('truck', 18) ?><span><?= e($delivery) ?></span></p>
                <?php endif; ?>
            </div>
            <?php if ($can_buy) : ?>
                <form class="tl-buyform" method="post" action="<?= e(route('cart.add')) ?>" data-af-cart-add>
                    <input type="hidden" name="product_id" value="<?= e($tl_p['id']) ?>">
                    <input type="hidden" name="variant_id" value="<?= e($tl_has_variants ? $initial['id'] : 0) ?>" data-af-variant-id>
                    <?php if ($tl_has_variants) : ?>
                        <?= $this->partial('catalog::front/partials/options', ['options' => $tl_p['options'], 'selected' => $tl_selected]) ?>
                        <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                    <?php endif; ?>
                    <div class="tl-buy-row af-row af-buy-row">
                        <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => $tl_min, 'min' => $tl_min, 'max' => $tl_max]) ?>
                        <button type="submit" class="tl-buy-btn af-btn af-btn-primary af-btn-lg" data-af-add<?= $tl_has_variants && $choose_action !== '' ? ' formaction="' . e($choose_action) . '"' : '' ?><?= $sold_out ? ' disabled' : '' ?>><?= taller_svg('cart', 22) ?><span><?= e($sold_out && !$tl_has_variants ? t('Sold out') : t('Add to cart')) ?></span></button>
                    </div>
                </form>
            <?php elseif ($tl_has_variants) : ?>
                <div class="tl-buyform">
                    <?= $this->partial('catalog::front/partials/options', ['options' => $tl_p['options'], 'selected' => $tl_selected]) ?>
                    <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                </div>
            <?php endif; ?>
            <?= $can_buy ? $this->partial('catalog::front/partials/trust', ['lines' => isset($trust) && is_array($trust) ? $trust : null, 'class' => 'tl-trust']) : '' ?>
            <?= hook('product.buy.after', $tl_p) ?>
            <?php if ($tl_facts !== []) : ?>
                <dl class="tl-keys af-facts">
                    <?php foreach ($tl_facts as $tl_fact) : ?>
                        <div><dt><?= e((string) $tl_fact['label']) ?></dt><dd><?= e((string) $tl_fact['value']) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <?php if ($summary_html !== '') : ?>
                <div class="tl-summary af-prose"><?= $summary_html ?></div>
            <?php endif; ?>
            <?= hook('product.summary.after', $tl_p) ?>
        </div>
    </div>

    <div class="tl-below">
        <?php if ($tl_specs !== []) : ?>
            <section class="tl-block tl-specs" id="af-specs" aria-labelledby="tl-specs-title">
                <h2 class="tl-block-title" id="tl-specs-title"><?= e(t('Specifications')) ?></h2>
                <div class="af-table-wrap">
                    <table class="tl-table af-table">
                        <tbody>
                            <?php foreach ($tl_specs as $tl_spec) : ?>
                                <tr><th scope="row"><?= e($tl_spec[0]) ?></th><td><?= e($tl_spec[1]) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
        <?php if ($tl_docs !== []) : ?>
            <section class="tl-block tl-docs" id="af-docs" aria-labelledby="tl-docs-title">
                <h2 class="tl-block-title" id="tl-docs-title"><?= e(t('Documents')) ?></h2>
                <ul class="tl-docs-list">
                    <?php foreach ($tl_docs as $tl_doc) : ?>
                        <li><a class="tl-doc" href="<?= e($tl_doc['url']) ?>"><?= taller_svg('doc', 20) ?><span><?= e($tl_doc['label']) ?></span></a></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <?php if ($description_html !== '') : ?>
            <section class="tl-block" id="af-description" aria-labelledby="tl-desc-title">
                <h2 class="tl-block-title" id="tl-desc-title"><?= e(t('Description')) ?></h2>
                <div class="af-prose"><?= $description_html ?></div>
            </section>
        <?php endif; ?>
        <?= hook('product.tabs', $tl_p) ?>
        <?php if ($faqs !== []) : ?>
            <section class="tl-block" id="af-faq" aria-labelledby="tl-faq-title">
                <h2 class="tl-block-title" id="tl-faq-title"><?= e(t('Frequently asked questions')) ?></h2>
                <?php foreach ($faqs as $tl_faq) : ?>
                    <details class="tl-faq af-card">
                        <summary><?= e($tl_faq['question']) ?></summary>
                        <div class="af-prose"><?= $tl_faq['answer'] ?></div>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <?php if ($related !== []) : ?>
            <section class="tl-block" id="af-related" aria-labelledby="tl-rel-title">
                <h2 class="tl-block-title" id="tl-rel-title"><?= e(t('You may also like')) ?></h2>
                <div class="tl-grid af-grid tl-cols-5">
                    <?php foreach ($related as $tl_card) : ?>
                        <?= $this->partial('partials/product-card', ['product' => $tl_card]) ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <?= hook('product.bottom', $tl_p) ?>
    </div>
</div>
