<?php
/**
 * AIFEC — ficha de producto de gmartos: migas; a la izquierda la galería con su marco y las miniaturas en una fila de cinco
 * debajo; a la derecha la compra (título, precio, resumen, cantidad y botón azul). Debajo, la tira de tres razones, las
 * pestañas (descripción, características, preguntas), los relacionados y la llamada a la acción oscura. Conserva todos los
 * huecos product.* de la plantilla original del catálogo.
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
$gm_p = $product;
$gm_has_variants = $initial !== null && is_array($variants_data);
$gm_images = isset($gm_p['images']) && is_array($gm_p['images']) ? array_values($gm_p['images']) : [];
$gm_rating = isset($gm_p['rating']) && is_array($gm_p['rating']) ? $gm_p['rating'] : null;
$gm_avg = $gm_rating !== null ? max(0.0, min(5.0, (float) $gm_rating['average'])) : 0.0;
$gm_count = $gm_rating !== null ? (int) $gm_rating['count'] : 0;
$gm_min = max(1, (int) $gm_p['min_qty']);
$gm_max = (int) $gm_p['max_qty'] > 0 ? (int) $gm_p['max_qty'] : null;
$gm_selected = $gm_has_variants && isset($initial['values']) && is_array($initial['values']) ? $initial['values'] : [];

// La tabla de características: los atributos visibles, los datos públicos que añaden los plugins y las referencias.
$gm_specs = [];
foreach (is_array($gm_p['attributes']) ? $gm_p['attributes'] : [] as $gm_attr) {
    $gm_specs[] = [(string) $gm_attr['name'], (string) $gm_attr['value']];
}
foreach (is_array($gm_p['extra']) ? $gm_p['extra'] : [] as $gm_extra) {
    if (is_array($gm_extra) && !empty($gm_extra['public']) && isset($gm_extra['label']) && isset($gm_extra['value']) && is_scalar($gm_extra['value']) && (string) $gm_extra['value'] !== '') {
        $gm_specs[] = [(string) $gm_extra['label'], (string) $gm_extra['value']];
    }
}
foreach ([[t('GTIN'), $gm_p['gtin']], [t('MPN'), $gm_p['mpn']]] as $gm_ref) {
    if ($gm_ref[1] !== null && (string) $gm_ref[1] !== '') {
        $gm_specs[] = [$gm_ref[0], (string) $gm_ref[1]];
    }
}
$gm_facts = array_slice(is_array($gm_p['facts']) ? $gm_p['facts'] : [], 0, 6);

// Las pestañas que hay: descripción, características y preguntas.
$gm_tabs = [];
if ($description_html !== '') {
    $gm_tabs[] = ['af-description', t('Description')];
}
if ($gm_specs !== []) {
    $gm_tabs[] = ['af-specs', t('Specifications')];
}
if ($faqs !== []) {
    $gm_tabs[] = ['af-faq', t('Frequently asked questions')];
}
$gm_cta_main = gmartos_link((string) theme_option('cta_link', ''));
$gm_cta_second = gmartos_link((string) theme_option('cta_link2', ''));
?>
<div class="af-container af-section gm-pdp">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <div class="af-product"<?= $gm_has_variants ? ' data-af-variants' : '' ?>>
        <div class="af-product-gallery">
            <div class="af-gallery" data-af-gallery>
                <div class="af-gallery-main">
                    <?= hook('product.gallery.before', $gm_p) ?>
                    <?php
                    $gm_first = $gm_images !== [] ? $gm_images[0] : null;
                    if ($gm_first !== null && (string) $gm_first['alt'] === '') {
                        $gm_first['alt'] = (string) $gm_p['name'];
                    }
                    ?>
                    <?= image_tag($gm_first, 'large', ['loading' => 'eager', 'fetchpriority' => 'high', 'data-af-gallery-main' => true]) ?>
                </div>
                <?php if (count($gm_images) > 1) : ?>
                    <ul class="af-gallery-thumbs">
                        <?php foreach ($gm_images as $gm_i => $gm_image) : ?>
                            <li><a href="<?= e((string) $gm_image['large']) ?>" data-src="<?= e((string) $gm_image['large']) ?>" data-alt="<?= e((string) $gm_image['alt'] !== '' ? (string) $gm_image['alt'] : (string) $gm_p['name']) ?>" data-image-id="<?= e($gm_image['id']) ?>" aria-label="<?= e(t('Image {n}', ['n' => $gm_i + 1])) ?>"<?= $gm_i === 0 ? ' aria-current="true"' : '' ?>>
                                <?= image_tag($gm_image, 'thumb', ['alt' => '']) ?>
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?= hook('product.gallery.after', $gm_p) ?>
        </div>
        <div class="af-product-buy">
            <?= hook('product.summary.before', $gm_p) ?>
            <h1 class="gm-pdp-title af-title"><?= e((string) $gm_p['name']) ?></h1>
            <?= $this->partial('partials/price', ['price' => $price, 'size' => 'lg', 'live' => $gm_has_variants]) ?>
            <?= hook('product.price.after', $gm_p) ?>
            <?php if ($summary_html !== '') : ?>
                <div class="gm-pdp-summary af-prose"><?= $summary_html ?></div>
            <?php endif; ?>
            <p class="af-availability <?= e($stock_class) ?>" data-af-availability><?= e((string) $stock['label']) ?></p>
            <?php if ($delivery !== '') : ?>
                <p class="af-small af-muted"><?= e($delivery) ?></p>
            <?php endif; ?>
            <?php if ($can_buy) : ?>
                <form method="post" action="<?= e(route('cart.add')) ?>" data-af-cart-add<?= hook('webmcp.form', 'add_to_cart') ?>>
                    <input type="hidden" name="product_id" value="<?= e($gm_p['id']) ?>"<?= hook('webmcp.param', 'add_to_cart', 'product_id') ?>>
                    <input type="hidden" name="variant_id" value="<?= e($gm_has_variants ? $initial['id'] : 0) ?>" data-af-variant-id<?= hook('webmcp.param', 'add_to_cart', 'variant_id') ?>>
                    <?php if ($gm_has_variants) : ?>
                        <?= $this->partial('catalog::front/partials/options', ['options' => $gm_p['options'], 'selected' => $gm_selected, 'tool' => 'add_to_cart']) ?>
                        <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                    <?php endif; ?>
                    <div class="af-row af-buy-row gm-buy-row">
                        <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => $gm_min, 'min' => $gm_min, 'max' => $gm_max, 'tool' => 'add_to_cart']) ?>
                        <button type="submit" class="af-btn af-btn-primary gm-buy-btn" data-af-add<?= $gm_has_variants && $choose_action !== '' ? ' formaction="' . e($choose_action) . '"' : '' ?><?= $sold_out ? ' disabled' : '' ?>><?= e($sold_out && !$gm_has_variants ? t('Sold out') : t('Add to cart')) ?></button>
                    </div>
                </form>
            <?php elseif ($gm_has_variants) : ?>
                <div>
                    <?= $this->partial('catalog::front/partials/options', ['options' => $gm_p['options'], 'selected' => $gm_selected]) ?>
                    <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                </div>
            <?php endif; ?>
            <?= $can_buy ? $this->partial('catalog::front/partials/trust', ['lines' => isset($trust) && is_array($trust) ? $trust : null, 'class' => 'gm-trust']) : '' ?>
            <?= hook('product.buy.after', $gm_p) ?>
            <p class="gm-meta af-small af-muted">
                <?php if (is_array($gm_p['brand'])) : ?>
                    <span><?= e(t('Brand')) ?>: <a href="<?= e((string) $gm_p['brand']['url']) ?>"><?= e((string) $gm_p['brand']['name']) ?></a></span>
                <?php endif; ?>
                <?php if ($sku !== '' || $gm_has_variants) : ?>
                    <span><?= e(t('SKU')) ?>: <span data-af-sku><?= e($sku) ?></span></span>
                <?php endif; ?>
                <?php if ($gm_count > 0) : ?>
                    <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($gm_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($gm_avg, 1, '.', '')) ?>"></span>
                    <span>(<?= e($gm_count) ?>)</span>
                <?php endif; ?>
            </p>
            <?php if ($gm_facts !== []) : ?>
                <dl class="af-facts">
                    <?php foreach ($gm_facts as $gm_fact) : ?>
                        <div><dt><?= e((string) $gm_fact['label']) ?></dt><dd><?= e((string) $gm_fact['value']) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <?= hook('product.summary.after', $gm_p) ?>
        </div>
    </div>

    <?php if ((int) theme_option('proof_show', 1) === 1) : ?>
        <section class="gm-proof" aria-label="<?= e(t('Why this product')) ?>">
            <div class="gm-proof-item"><strong><?= e(t('Specialised development')) ?></strong><span><?= e(t('Technical product built for real e-commerce.')) ?></span></div>
            <div class="gm-proof-item"><strong><?= e(t('Clear documentation')) ?></strong><span><?= e(t('Compatibility, limits and how it works, explained before you buy.')) ?></span></div>
            <div class="gm-proof-item"><strong><?= e(t('Support available')) ?></strong><span><?= e(t('If you need an adaptation or help, you can hire specialised support.')) ?></span></div>
        </section>
    <?php endif; ?>

    <div class="gm-info">
        <?php if ($gm_tabs !== []) : ?>
            <div class="af-tabs" data-af-tabs>
                <div role="tablist" aria-label="<?= e(t('Product information')) ?>">
                    <?php foreach ($gm_tabs as $gm_n => $gm_tab) : ?>
                        <button type="button" role="tab" id="gm-tab-<?= e($gm_tab[0]) ?>" aria-controls="<?= e($gm_tab[0]) ?>" aria-selected="<?= $gm_n === 0 ? 'true' : 'false' ?>"<?= $gm_n === 0 ? '' : ' tabindex="-1"' ?>><?= e($gm_tab[1]) ?></button>
                    <?php endforeach; ?>
                </div>
                <?php if ($description_html !== '') : ?>
                    <div role="tabpanel" id="af-description" aria-labelledby="gm-tab-af-description" class="gm-panel">
                        <h2><?= e(t('Description')) ?></h2>
                        <div class="af-prose"><?= $description_html ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($gm_specs !== []) : ?>
                    <div role="tabpanel" id="af-specs" aria-labelledby="gm-tab-af-specs" class="gm-panel">
                        <h2><?= e(t('Specifications')) ?></h2>
                        <div class="af-table-wrap">
                            <table class="af-table">
                                <tbody>
                                    <?php foreach ($gm_specs as $gm_spec) : ?>
                                        <tr><th scope="row"><?= e($gm_spec[0]) ?></th><td><?= e($gm_spec[1]) ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
                <?php if ($faqs !== []) : ?>
                    <div role="tabpanel" id="af-faq" aria-labelledby="gm-tab-af-faq" class="gm-panel">
                        <h2><?= e(t('Frequently asked questions')) ?></h2>
                        <?php foreach ($faqs as $gm_faq) : ?>
                            <details class="af-card gm-faq">
                                <summary><?= e($gm_faq['question']) ?></summary>
                                <div class="af-prose"><?= $gm_faq['answer'] ?></div>
                            </details>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
        <?= hook('product.tabs', $gm_p) ?>
        <?php if ($related !== []) : ?>
            <section class="gm-related" id="af-related">
                <h2><?= e(t('You may also like')) ?></h2>
                <ul class="af-grid af-cols-4 gm-grid" role="list">
                    <?php foreach ($related as $gm_card) : ?>
                        <li><?= $this->partial('partials/product-card', ['product' => $gm_card]) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <?= hook('product.bottom', $gm_p) ?>
    </div>

    <?php if ((int) theme_option('cta_show', 1) === 1) : ?>
        <section class="gm-cta" aria-labelledby="gm-cta-title">
            <div>
                <p class="gm-kicker"><?= e(t('Need something different?')) ?></p>
                <h2 id="gm-cta-title"><?= e(t('This product can be the starting point, not the limit.')) ?></h2>
                <p><?= e(t('If your shop needs its own rules, integrations or processes, an adaptation or a specific development can be studied.')) ?></p>
            </div>
            <?php if ($gm_cta_main !== '' || $gm_cta_second !== '') : ?>
                <div class="gm-cta-actions">
                    <?php if ($gm_cta_main !== '') : ?>
                        <a class="af-btn af-btn-primary" href="<?= e($gm_cta_main) ?>"><?= e(t('Custom development')) ?></a>
                    <?php endif; ?>
                    <?php if ($gm_cta_second !== '') : ?>
                        <a class="af-btn gm-btn-dark-ghost" href="<?= e($gm_cta_second) ?>"><?= e(t('Ask us')) ?></a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
