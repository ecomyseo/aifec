<?php
/**
 * AIFEC — ficha de producto de despensa: a la izquierda una imagen grande con las miniaturas debajo; a la derecha el nombre,
 * de dónde viene, el precio (con el precio por kilo o litro) y el formato como tarjetas elegibles, la cantidad y la compra.
 * Debajo: notas de cata como fichas (los datos clave), la descripción, las características, las preguntas, el maridaje
 * (los relacionados) y el bloque del productor. Conserva todos los huecos product.* de la plantilla original.
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
$ds_p = $product;
$ds_has_variants = $initial !== null && is_array($variants_data);
$ds_images = isset($ds_p['images']) && is_array($ds_p['images']) ? array_values($ds_p['images']) : [];
$ds_rating = isset($ds_p['rating']) && is_array($ds_p['rating']) ? $ds_p['rating'] : null;
$ds_avg = $ds_rating !== null ? max(0.0, min(5.0, (float) $ds_rating['average'])) : 0.0;
$ds_count = $ds_rating !== null ? (int) $ds_rating['count'] : 0;
$ds_min = max(1, (int) $ds_p['min_qty']);
$ds_max = (int) $ds_p['max_qty'] > 0 ? (int) $ds_p['max_qty'] : null;
$ds_selected = $ds_has_variants && isset($initial['values']) && is_array($initial['values']) ? $initial['values'] : [];
$ds_facts = array_slice(is_array($ds_p['facts']) ? $ds_p['facts'] : [], 0, 8);
$ds_origin = despensa_fact($ds_facts, '/origen|origin|procedencia|provenance|regi[oó]n|region|denominaci/iu');
// Disponibilidad, precio, referencia y categoría ya salen en la ficha (la disponibilidad real es $stock): repetirlos aquí
// como «notas» los contradice (decía «En stock» y «Agotado» a la vez). Lo que queda son las notas de verdad; sin ellas no se pinta.
$ds_facts = array_values(array_filter($ds_facts, static function ($ds_f): bool {
    return !is_array($ds_f) || !isset($ds_f['label']) || preg_match('/^\s*(disponibilidad|availability|stock|existencias|precio|price|referencia|reference|sku|categor[ií]a|category)\s*:?\s*$/iu', (string) $ds_f['label']) !== 1;
}));
$ds_unit = '';
if (!$ds_has_variants && (int) theme_option('unit_price_show', 1) === 1 && !empty($price['amount'])) {
    $ds_unit = despensa_unit_price((int) $price['amount'], despensa_measure($ds_p), isset($price['currency']) && is_string($price['currency']) && $price['currency'] !== '' ? $price['currency'] : null);
}
$ds_thumbs = theme_option('gallery_thumbs', 'below') !== 'hidden';
$ds_category = isset($ds_p['main_category']) && is_array($ds_p['main_category']) ? $ds_p['main_category'] : [];
$ds_tone = !empty($ds_category['id']) ? 1 + (((int) $ds_category['id'] - 1) % 6) : 1;

// La tabla de características: los atributos visibles, los datos públicos que añaden los plugins y las referencias.
$ds_specs = [];
foreach (is_array($ds_p['attributes']) ? $ds_p['attributes'] : [] as $ds_attr) {
    // «value» ya viene legible y con su unidad («130 kg»); «unit» y «number» son para quien necesita el dato suelto.
    $ds_specs[] = [(string) $ds_attr['name'], (string) $ds_attr['value']];
}
foreach (is_array($ds_p['extra']) ? $ds_p['extra'] : [] as $ds_extra) {
    if (is_array($ds_extra) && !empty($ds_extra['public']) && isset($ds_extra['label']) && isset($ds_extra['value']) && is_scalar($ds_extra['value']) && (string) $ds_extra['value'] !== '') {
        $ds_specs[] = [(string) $ds_extra['label'], (string) $ds_extra['value']];
    }
}
foreach ([[t('GTIN'), $ds_p['gtin']], [t('MPN'), $ds_p['mpn']]] as $ds_ref) {
    if ($ds_ref[1] !== null && (string) $ds_ref[1] !== '') {
        $ds_specs[] = [$ds_ref[0], (string) $ds_ref[1]];
    }
}
?>
<div class="ds-pdp-wrap af-container af-section">
    <?php if ($crumbs !== []) : ?>
        <nav class="ds-crumbs af-breadcrumbs" aria-label="<?= e(t('You are here')) ?>">
            <?php $ds_last = count($crumbs) - 1; ?>
            <?php foreach (array_values($crumbs) as $ds_n => $ds_crumb) : ?>
                <?php if ($ds_n === $ds_last || empty($ds_crumb['url'])) : ?>
                    <strong aria-current="page"><?= e(isset($ds_crumb['name']) ? (string) $ds_crumb['name'] : '') ?></strong>
                <?php else : ?>
                    <a href="<?= e((string) $ds_crumb['url']) ?>"><?= e(isset($ds_crumb['name']) ? (string) $ds_crumb['name'] : '') ?></a><i aria-hidden="true">&rsaquo;</i>
                <?php endif; ?>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <div class="ds-pdp af-product"<?= $ds_has_variants ? ' data-af-variants' : '' ?>>
        <div class="ds-pdp-gallery af-product-gallery">
            <div class="ds-gal af-gallery" data-af-gallery>
                <div class="ds-gal-main af-gallery-main" data-tone="<?= e($ds_tone) ?>">
                    <?= hook('product.gallery.before', $ds_p) ?>
                    <?php
                    $ds_first = $ds_images !== [] ? $ds_images[0] : null;
                    if ($ds_first !== null && (string) $ds_first['alt'] === '') {
                        $ds_first['alt'] = (string) $ds_p['name'];
                    }
                    ?>
                    <?= image_tag($ds_first, 'large', ['loading' => 'eager', 'fetchpriority' => 'high', 'data-af-gallery-main' => true]) ?>
                </div>
                <?php if (count($ds_images) > 1) : ?>
                    <div class="ds-gal-thumbs af-gallery-thumbs<?= $ds_thumbs ? '' : ' ds-gal-off' ?>">
                        <?php foreach ($ds_images as $ds_i => $ds_image) : ?>
                            <a class="ds-thumb" href="<?= e((string) $ds_image['large']) ?>" data-src="<?= e((string) $ds_image['large']) ?>" data-alt="<?= e((string) $ds_image['alt'] !== '' ? (string) $ds_image['alt'] : (string) $ds_p['name']) ?>" data-image-id="<?= e($ds_image['id']) ?>" aria-label="<?= e(t('Image {n}', ['n' => $ds_i + 1])) ?>"<?= $ds_i === 0 ? ' aria-current="true"' : '' ?>><?= image_tag($ds_image, 'thumb', ['alt' => '']) ?></a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?= hook('product.gallery.after', $ds_p) ?>
        </div>
        <div class="ds-pdp-buy af-product-buy">
            <?= hook('product.summary.before', $ds_p) ?>
            <header class="ds-pdp-head">
                <?php if ($ds_origin !== '') : ?>
                    <p class="ds-from"><?= despensa_svg('pin', 16) ?><span><?= e(t('Comes from')) ?> <strong><?= e($ds_origin) ?></strong></span></p>
                <?php endif; ?>
                <h1 class="ds-pdp-title af-title"><?= e((string) $ds_p['name']) ?></h1>
                <p class="ds-pdp-meta af-row af-small af-muted">
                    <?php if (is_array($ds_p['brand'])) : ?>
                        <a href="<?= e((string) $ds_p['brand']['url']) ?>"><?= e((string) $ds_p['brand']['name']) ?></a>
                    <?php endif; ?>
                    <?php if ($ds_count > 0) : ?>
                        <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($ds_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($ds_avg, 1, '.', '')) ?>"></span>
                        <span>(<?= e($ds_count) ?>)</span>
                    <?php endif; ?>
                    <?php if ($sku !== '' || $ds_has_variants) : ?>
                        <span><?= e(t('SKU')) ?>: <span data-af-sku><?= e($sku) ?></span></span>
                    <?php endif; ?>
                </p>
            </header>
            <p class="ds-pdp-price">
                <?= $this->partial('partials/price', ['price' => $price, 'size' => 'lg', 'live' => $ds_has_variants]) ?>
                <?php if ($ds_unit !== '') : ?>
                    <small class="ds-unit"><?= e($ds_unit) ?></small>
                <?php endif; ?>
            </p>
            <?= hook('product.price.after', $ds_p) ?>
            <ul class="ds-assure">
                <li class="ds-stock af-availability <?= e($stock_class) ?>" data-af-availability><?= e((string) $stock['label']) ?></li>
                <?php if ($delivery !== '') : ?>
                    <li class="ds-delivery af-small af-muted"><?= e($delivery) ?></li>
                <?php endif; ?>
            </ul>
            <?php if ($can_buy) : ?>
                <form class="ds-buy" method="post" action="<?= e(route('cart.add')) ?>" data-af-cart-add>
                    <input type="hidden" name="product_id" value="<?= e($ds_p['id']) ?>">
                    <input type="hidden" name="variant_id" value="<?= e($ds_has_variants ? $initial['id'] : 0) ?>" data-af-variant-id>
                    <?php if ($ds_has_variants) : ?>
                        <?= $this->partial('catalog::front/partials/options', ['options' => $ds_p['options'], 'selected' => $ds_selected]) ?>
                        <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                    <?php endif; ?>
                    <div class="ds-buy-row af-row af-buy-row">
                        <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => $ds_min, 'min' => $ds_min, 'max' => $ds_max]) ?>
                        <button type="submit" class="ds-buy-btn af-btn af-btn-primary af-btn-lg" data-af-add<?= $ds_has_variants && $choose_action !== '' ? ' formaction="' . e($choose_action) . '"' : '' ?><?= $sold_out ? ' disabled' : '' ?>><?= despensa_svg('basket', 22) ?><span><?= e($sold_out && !$ds_has_variants ? t('Sold out') : t('Add to cart')) ?></span></button>
                    </div>
                </form>
            <?php elseif ($ds_has_variants) : ?>
                <div>
                    <?= $this->partial('catalog::front/partials/options', ['options' => $ds_p['options'], 'selected' => $ds_selected]) ?>
                    <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                </div>
            <?php endif; ?>
            <?= $can_buy ? $this->partial('catalog::front/partials/trust', ['lines' => isset($trust) && is_array($trust) ? $trust : null, 'class' => 'ds-trust']) : '' ?>
            <?= hook('product.buy.after', $ds_p) ?>
            <?php if ($summary_html !== '') : ?>
                <div class="ds-summary af-prose"><?= $summary_html ?></div>
            <?php endif; ?>
            <?= hook('product.summary.after', $ds_p) ?>
        </div>
    </div>

    <div class="ds-more af-stack">
        <?php if ($ds_facts !== []) : ?>
            <section class="ds-notes af-section" id="af-notes" aria-labelledby="af-notes-title">
                <h2 id="af-notes-title"><?= e(t('Tasting notes')) ?></h2>
                <ul class="ds-notes-list af-facts">
                    <?php foreach ($ds_facts as $ds_fact) : ?>
                        <li class="ds-note"><span class="ds-note-k"><?= e((string) $ds_fact['label']) ?></span><strong class="ds-note-v"><?= e((string) $ds_fact['value']) ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
        <?php if ($description_html !== '') : ?>
            <section class="ds-desc af-section" id="af-description">
                <h2><?= e(t('Description')) ?></h2>
                <div class="af-prose"><?= $description_html ?></div>
            </section>
        <?php endif; ?>
        <?= hook('product.tabs', $ds_p) ?>
        <?php if ($ds_specs !== []) : ?>
            <section class="ds-specs af-section" id="af-specs">
                <h2><?= e(t('Specifications')) ?></h2>
                <dl class="ds-specs-list">
                    <?php foreach ($ds_specs as $ds_spec) : ?>
                        <div class="ds-spec"><dt><?= e($ds_spec[0]) ?></dt><dd><?= e($ds_spec[1]) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            </section>
        <?php endif; ?>
        <?php if ($faqs !== []) : ?>
            <section class="ds-faq af-section" id="af-faq">
                <h2><?= e(t('Frequently asked questions')) ?></h2>
                <?php foreach ($faqs as $ds_faq) : ?>
                    <details class="ds-faq-item af-card">
                        <summary><?= e($ds_faq['question']) ?></summary>
                        <div class="af-prose"><?= $ds_faq['answer'] ?></div>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <?php if ($related !== []) : ?>
            <section class="ds-pairs af-section" id="af-related">
                <h2><?= e(t('You may also like')) ?></h2>
                <div class="ds-rail">
                    <div class="ds-rail-track" role="region" aria-label="<?= e(t('You may also like')) ?>" tabindex="0">
                        <?php foreach ($related as $ds_card) : ?>
                            <div class="ds-rail-item"><?= $this->partial('partials/product-card', ['product' => $ds_card]) ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </section>
        <?php endif; ?>
        <?= $this->partial('partials/producer') ?>
        <?= hook('product.bottom', $ds_p) ?>
    </div>
</div>
