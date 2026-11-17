<?php
/**
 * AIFEC — ficha de producto: galería a la izquierda y compra a la derecha en escritorio; debajo, la descripción, las
 * características, las preguntas frecuentes y los productos relacionados. Sin el módulo de pedidos no lleva botón de compra.
 * Un tema puede sustituirla en templates/modules/catalog/front/product.php conservando los huecos product.*.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                  $page             datos de cabecera (ver Aifec\Front\Page)
 * @var array<string,mixed>                  $product          ProductData
 * @var array<int,array<string,mixed>>       $crumbs
 * @var array<int,array<string,mixed>>       $related          tarjetas de «También te puede interesar» (ver Front\Related)
 * @var array<int,array<string,string>>      $trust            líneas de confianza de debajo del botón (ver Aifec\Front\StoreInfo)
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
$af_p = $product;
$af_has_variants = $initial !== null && is_array($variants_data);
$af_images = isset($af_p['images']) && is_array($af_p['images']) ? array_values($af_p['images']) : [];
$af_rating = isset($af_p['rating']) && is_array($af_p['rating']) ? $af_p['rating'] : null;
$af_avg = $af_rating !== null ? max(0.0, min(5.0, (float) $af_rating['average'])) : 0.0;
$af_count = $af_rating !== null ? (int) $af_rating['count'] : 0;
$af_min = max(1, (int) $af_p['min_qty']);
$af_max = (int) $af_p['max_qty'] > 0 ? (int) $af_p['max_qty'] : null;
$af_selected = $af_has_variants && isset($initial['values']) && is_array($initial['values']) ? $initial['values'] : [];

// La tabla de características: los atributos visibles, los datos públicos que añaden los plugins y las referencias.
$af_specs = [];
foreach (is_array($af_p['attributes']) ? $af_p['attributes'] : [] as $af_attr) {
    // «value» ya viene legible y con su unidad («130 kg»); «unit» y «number» son para quien necesita el dato suelto.
    $af_specs[] = [(string) $af_attr['name'], (string) $af_attr['value']];
}
foreach (is_array($af_p['extra']) ? $af_p['extra'] : [] as $af_extra) {
    if (is_array($af_extra) && !empty($af_extra['public']) && isset($af_extra['label']) && isset($af_extra['value']) && is_scalar($af_extra['value']) && (string) $af_extra['value'] !== '') {
        $af_specs[] = [(string) $af_extra['label'], (string) $af_extra['value']];
    }
}
foreach ([[t('GTIN'), $af_p['gtin']], [t('MPN'), $af_p['mpn']]] as $af_ref) {
    if ($af_ref[1] !== null && (string) $af_ref[1] !== '') {
        $af_specs[] = [$af_ref[0], (string) $af_ref[1]];
    }
}
$af_facts = array_slice(is_array($af_p['facts']) ? $af_p['facts'] : [], 0, 6);
// Un dato que ya sale en la tabla de características (Color, Género, Marca…) no se repite arriba: se enseña una sola vez.
$af_spec_names = [];
foreach ($af_specs as $af_spec_row) {
    $af_spec_names[mb_strtolower(trim((string) $af_spec_row[0]), 'UTF-8')] = true;
}
$af_facts = array_values(array_filter($af_facts, static function ($af_f) use ($af_spec_names): bool {
    return !is_array($af_f) || !isset($af_f['label']) || !isset($af_spec_names[mb_strtolower(trim((string) $af_f['label']), 'UTF-8')]);
}));
?>
<div class="af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <div class="af-product"<?= $af_has_variants ? ' data-af-variants' : '' ?>>
        <div class="af-product-gallery">
            <div class="af-gallery" data-af-gallery>
                <div class="af-gallery-main">
                    <?= hook('product.gallery.before', $af_p) ?>
                    <?php
                    $af_first = $af_images !== [] ? $af_images[0] : null;
                    if ($af_first !== null && (string) $af_first['alt'] === '') {
                        $af_first['alt'] = (string) $af_p['name'];
                    }
                    ?>
                    <?= image_tag($af_first, 'large', ['loading' => 'eager', 'fetchpriority' => 'high', 'data-af-gallery-main' => true]) ?>
                </div>
                <?php if (count($af_images) > 1) : ?>
                    <ul class="af-gallery-thumbs">
                        <?php foreach ($af_images as $af_i => $af_image) : ?>
                            <li><a href="<?= e((string) $af_image['large']) ?>" data-src="<?= e((string) $af_image['large']) ?>" data-alt="<?= e((string) $af_image['alt'] !== '' ? (string) $af_image['alt'] : (string) $af_p['name']) ?>" data-image-id="<?= e($af_image['id']) ?>" aria-label="<?= e(t('Image {n}', ['n' => $af_i + 1])) ?>"<?= $af_i === 0 ? ' aria-current="true"' : '' ?>>
                                <?= image_tag($af_image, 'thumb', ['alt' => '']) ?>
                            </a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
            <?= hook('product.gallery.after', $af_p) ?>
        </div>
        <div class="af-product-buy">
            <?= hook('product.summary.before', $af_p) ?>
            <h1 class="af-title"><?= e((string) $af_p['name']) ?></h1>
            <p class="af-row af-small af-muted">
                <?php if (is_array($af_p['brand'])) : ?>
                    <a href="<?= e((string) $af_p['brand']['url']) ?>"><?= e((string) $af_p['brand']['name']) ?></a>
                <?php endif; ?>
                <?php if ($af_count > 0) : ?>
                    <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($af_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($af_avg, 1, '.', '')) ?>"></span>
                    <span>(<?= e($af_count) ?>)</span>
                <?php endif; ?>
                <?php if ($sku !== '' || $af_has_variants) : ?>
                    <span><?= e(t('SKU')) ?>: <span data-af-sku><?= e($sku) ?></span></span>
                <?php endif; ?>
            </p>
            <?= $this->partial('partials/price', ['price' => $price, 'size' => 'lg', 'live' => $af_has_variants]) ?>
            <?= hook('product.price.after', $af_p) ?>
            <p class="af-availability <?= e($stock_class) ?>" data-af-availability><?= e((string) $stock['label']) ?></p>
            <?php if ($delivery !== '') : ?>
                <p class="af-small af-muted"><?= e($delivery) ?></p>
            <?php endif; ?>
            <?php if ($can_buy) : ?>
                <form method="post" action="<?= e(route('cart.add')) ?>" data-af-cart-add<?= hook('webmcp.form', 'add_to_cart') ?>>
                    <input type="hidden" name="product_id" value="<?= e($af_p['id']) ?>"<?= hook('webmcp.param', 'add_to_cart', 'product_id') ?>>
                    <input type="hidden" name="variant_id" value="<?= e($af_has_variants ? $initial['id'] : 0) ?>" data-af-variant-id<?= hook('webmcp.param', 'add_to_cart', 'variant_id') ?>>
                    <?php if ($af_has_variants) : ?>
                        <?= $this->partial('catalog::front/partials/options', ['options' => $af_p['options'], 'selected' => $af_selected, 'tool' => 'add_to_cart']) ?>
                        <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                    <?php endif; ?>
                    <div class="af-row af-buy-row">
                        <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => $af_min, 'min' => $af_min, 'max' => $af_max, 'tool' => 'add_to_cart']) ?>
                        <button type="submit" class="af-btn af-btn-primary af-btn-lg" data-af-add<?= $af_has_variants && $choose_action !== '' ? ' formaction="' . e($choose_action) . '"' : '' ?><?= $sold_out ? ' disabled' : '' ?>><?= e($sold_out && !$af_has_variants ? t('Sold out') : t('Add to cart')) ?></button>
                    </div>
                </form>
            <?php elseif ($af_has_variants) : ?>
                <div>
                    <?= $this->partial('catalog::front/partials/options', ['options' => $af_p['options'], 'selected' => $af_selected]) ?>
                    <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                </div>
            <?php endif; ?>
            <?= $can_buy ? $this->partial('catalog::front/partials/trust', ['lines' => isset($trust) && is_array($trust) ? $trust : null]) : '' ?>
            <?= hook('product.buy.after', $af_p) ?>
            <?php if ($af_facts !== []) : ?>
                <dl class="af-facts">
                    <?php foreach ($af_facts as $af_fact) : ?>
                        <div><dt><?= e((string) $af_fact['label']) ?></dt><dd><?= e((string) $af_fact['value']) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <?php if ($summary_html !== '') : ?>
                <div class="af-prose"><?= $summary_html ?></div>
            <?php endif; ?>
            <?= hook('product.summary.after', $af_p) ?>
        </div>
    </div>

    <div class="af-stack">
        <?php if ($description_html !== '') : ?>
            <section class="af-section" id="af-description">
                <h2><?= e(t('Description')) ?></h2>
                <div class="af-prose"><?= $description_html ?></div>
            </section>
        <?php endif; ?>
        <?= hook('product.tabs', $af_p) ?>
        <?php if ($af_specs !== []) : ?>
            <section class="af-section" id="af-specs">
                <h2><?= e(t('Specifications')) ?></h2>
                <div class="af-table-wrap">
                    <table class="af-table">
                        <tbody>
                            <?php foreach ($af_specs as $af_spec) : ?>
                                <tr><th scope="row"><?= e($af_spec[0]) ?></th><td><?= e($af_spec[1]) ?></td></tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>
        <?php endif; ?>
        <?php if ($faqs !== []) : ?>
            <section class="af-section" id="af-faq">
                <h2><?= e(t('Frequently asked questions')) ?></h2>
                <?php foreach ($faqs as $af_faq) : ?>
                    <details class="af-card">
                        <summary><?= e($af_faq['question']) ?></summary>
                        <div class="af-prose"><?= $af_faq['answer'] ?></div>
                    </details>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>
        <?php if ($related !== []) : ?>
            <section class="af-section af-related" id="af-related">
                <h2><?= e(t('You may also like')) ?></h2>
                <div class="af-grid af-cols-4">
                    <?php foreach ($related as $af_card) : ?>
                        <?= $this->partial('partials/product-card', ['product' => $af_card]) ?>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>
        <?= hook('product.bottom', $af_p) ?>
    </div>
</div>
