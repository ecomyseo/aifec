<?php
/**
 * AIFEC — ficha de producto en atelier: a la izquierda la galería pegajosa, con una foto principal (las insignias encima) y
 * una columna de miniaturas que la cambian, también con el teclado; a la derecha la compra (marca, nombre, precio, tallas
 * como botones cuadrados, guía de tallas desplegable, las líneas de confianza) y la descripción, la composición y las
 * preguntas en acordeones. Debajo, «También te puede interesar».
 * Conserva todos los datos y todos los huecos product.* de la plantilla original del catálogo.
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
$at_p = $product;
$at_has_variants = $initial !== null && is_array($variants_data);
$at_images = isset($at_p['images']) && is_array($at_p['images']) ? array_values($at_p['images']) : [];
$at_rating = isset($at_p['rating']) && is_array($at_p['rating']) ? $at_p['rating'] : null;
$at_avg = $at_rating !== null ? max(0.0, min(5.0, (float) $at_rating['average'])) : 0.0;
$at_count = $at_rating !== null ? (int) $at_rating['count'] : 0;
$at_min = max(1, (int) $at_p['min_qty']);
$at_max = (int) $at_p['max_qty'] > 0 ? (int) $at_p['max_qty'] : null;
$at_selected = $at_has_variants && isset($initial['values']) && is_array($initial['values']) ? $initial['values'] : [];
$at_column = theme_option('gallery_style', 'grid') === 'column';

// La tabla de características («composición y cuidados»): atributos visibles, datos públicos de los plugins y referencias.
$at_specs = [];
foreach (is_array($at_p['attributes']) ? $at_p['attributes'] : [] as $at_attr) {
    $at_specs[] = [(string) $at_attr['name'], (string) $at_attr['value']];
}
foreach (is_array($at_p['extra']) ? $at_p['extra'] : [] as $at_extra) {
    if (is_array($at_extra) && !empty($at_extra['public']) && isset($at_extra['label']) && isset($at_extra['value']) && is_scalar($at_extra['value']) && (string) $at_extra['value'] !== '') {
        $at_specs[] = [(string) $at_extra['label'], (string) $at_extra['value']];
    }
}
foreach ([[t('GTIN'), $at_p['gtin']], [t('MPN'), $at_p['mpn']]] as $at_ref) {
    if ($at_ref[1] !== null && (string) $at_ref[1] !== '') {
        $at_specs[] = [$at_ref[0], (string) $at_ref[1]];
    }
}
$at_facts = array_slice(is_array($at_p['facts']) ? $at_p['facts'] : [], 0, 6);

// ¿Tiene una opción de talla? Entonces sale la guía de tallas desplegable (su contenido lo pone el hueco product.size_guide).
$at_has_size = false;
foreach (isset($at_p['options']) && is_array($at_p['options']) ? $at_p['options'] : [] as $at_option) {
    if (is_array($at_option) && preg_match('/\b(size|talla|tamano|tamaño|taille|tg)\b/iu', (string) $at_option['code'] . ' ' . (string) $at_option['name']) === 1) {
        $at_has_size = true;
    }
}
$at_guide = hook('product.size_guide', $at_p);
?>
<div class="at-pdp-wrap af-container af-section">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <div class="at-pdp af-product<?= ($at_column ? ' at-pdp-column' : '') ?>"<?= ($at_has_variants ? ' data-af-variants' : '') ?>>
        <div class="at-pgallery af-product-gallery">
            <div class="at-pgal af-gallery" data-af-gallery>
                <?php
                $at_first = $at_images !== [] ? $at_images[0] : null;
                if ($at_first !== null && (string) $at_first['alt'] === '') {
                    $at_first['alt'] = (string) $at_p['name'];
                }
                ?>
                <figure class="at-pg-main af-gallery-main">
                    <?= hook('product.gallery.before', $at_p) ?>
                    <?= image_tag($at_first, 'large', ['loading' => 'eager', 'fetchpriority' => 'high', 'data-af-gallery-main' => true]) ?>
                </figure>
                <?php if (count($at_images) > 1) : ?>
                    <ol class="af-gallery-thumbs" aria-label="<?= e(t('Product photos')) ?>">
                        <?php foreach ($at_images as $at_i => $at_image) : ?>
                            <li><button type="button" class="at-pg-thumb" data-src="<?= e((string) $at_image['large']) ?>" data-alt="<?= e((string) $at_image['alt'] !== '' ? (string) $at_image['alt'] : (string) $at_p['name']) ?>" data-image-id="<?= e($at_image['id']) ?>" aria-label="<?= e(t('Image {n}', ['n' => $at_i + 1])) ?>"<?= ($at_i === 0 ? ' aria-current="true"' : '') ?>><?= image_tag($at_image, 'thumb', ['alt' => '']) ?></button></li>
                        <?php endforeach; ?>
                    </ol>
                <?php endif; ?>
            </div>
            <?= hook('product.gallery.after', $at_p) ?>
        </div>
        <div class="at-buy af-product-buy">
            <?= hook('product.summary.before', $at_p) ?>
            <?php if (is_array($at_p['brand'])) : ?>
                <p class="at-pdp-brand"><a href="<?= e((string) $at_p['brand']['url']) ?>"><?= e((string) $at_p['brand']['name']) ?></a></p>
            <?php endif; ?>
            <h1 class="at-pdp-title af-title"><?= e((string) $at_p['name']) ?></h1>
            <p class="at-pdp-meta af-row af-small af-muted">
                <?php if ($at_count > 0) : ?>
                    <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($at_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($at_avg, 1, '.', '')) ?>"></span>
                    <span>(<?= e($at_count) ?>)</span>
                <?php endif; ?>
                <?php if ($sku !== '' || $at_has_variants) : ?>
                    <span><?= e(t('SKU')) ?>: <span data-af-sku><?= e($sku) ?></span></span>
                <?php endif; ?>
            </p>
            <?= $this->partial('partials/price', ['price' => $price, 'size' => 'lg', 'live' => $at_has_variants]) ?>
            <?= hook('product.price.after', $at_p) ?>
            <p class="af-availability <?= e($stock_class) ?>" data-af-availability><?= e((string) $stock['label']) ?></p>
            <?php if ($delivery !== '') : ?>
                <p class="af-small af-muted"><?= e($delivery) ?></p>
            <?php endif; ?>
            <?php if ($can_buy) : ?>
                <form method="post" action="<?= e(route('cart.add')) ?>" data-af-cart-add>
                    <input type="hidden" name="product_id" value="<?= e($at_p['id']) ?>">
                    <input type="hidden" name="variant_id" value="<?= e($at_has_variants ? $initial['id'] : 0) ?>" data-af-variant-id>
                    <?php if ($at_has_variants) : ?>
                        <?= $this->partial('catalog::front/partials/options', ['options' => $at_p['options'], 'selected' => $at_selected]) ?>
                        <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                    <?php endif; ?>
                    <?php if ($at_has_size || trim($at_guide) !== '') : ?>
                        <details class="at-sizeguide">
                            <summary><?= e(t('Size guide')) ?></summary>
                            <div class="at-sizeguide-body af-prose"><?= trim($at_guide) !== '' ? $at_guide : '<p>' . e(t('Compare the garment measurements in the description with a piece you already own to choose your size.')) . '</p>' ?></div>
                        </details>
                    <?php endif; ?>
                    <div class="at-buy-row af-row af-buy-row">
                        <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => $at_min, 'min' => $at_min, 'max' => $at_max]) ?>
                        <button type="submit" class="af-btn af-btn-primary af-btn-lg" data-af-add<?= ($at_has_variants && $choose_action !== '' ? ' formaction="' . e($choose_action) . '"' : '') ?><?= ($sold_out ? ' disabled' : '') ?>><?= e($sold_out && !$at_has_variants ? t('Sold out') : t('Add to cart')) ?></button>
                    </div>
                </form>
            <?php elseif ($at_has_variants) : ?>
                <div>
                    <?= $this->partial('catalog::front/partials/options', ['options' => $at_p['options'], 'selected' => $at_selected]) ?>
                    <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                </div>
                <?php if ($at_has_size || trim($at_guide) !== '') : ?>
                    <details class="at-sizeguide">
                        <summary><?= e(t('Size guide')) ?></summary>
                        <div class="at-sizeguide-body af-prose"><?= trim($at_guide) !== '' ? $at_guide : '<p>' . e(t('Compare the garment measurements in the description with a piece you already own to choose your size.')) . '</p>' ?></div>
                    </details>
                <?php endif; ?>
            <?php endif; ?>
            <?= ($can_buy ? $this->partial('catalog::front/partials/trust', ['lines' => isset($trust) && is_array($trust) ? $trust : null, 'class' => 'at-trust']) : '') ?>
            <?= hook('product.buy.after', $at_p) ?>
            <?php if ($at_facts !== []) : ?>
                <dl class="af-facts">
                    <?php foreach ($at_facts as $at_fact) : ?>
                        <div><dt><?= e((string) $at_fact['label']) ?></dt><dd><?= e((string) $at_fact['value']) ?></dd></div>
                    <?php endforeach; ?>
                </dl>
            <?php endif; ?>
            <?php if ($summary_html !== '') : ?>
                <div class="at-summary af-prose"><?= ($summary_html) ?></div>
            <?php endif; ?>
            <div class="at-acc">
                <?php if ($description_html !== '') : ?>
                    <details class="at-acc-item" id="af-description" open>
                        <summary><?= e(t('Description')) ?></summary>
                        <div class="at-acc-body af-prose"><?= ($description_html) ?></div>
                    </details>
                <?php endif; ?>
                <?= hook('product.tabs', $at_p) ?>
                <?php if ($at_specs !== []) : ?>
                    <details class="at-acc-item" id="af-specs">
                        <summary><?= e(t('Composition and care')) ?></summary>
                        <div class="at-acc-body af-table-wrap">
                            <table class="af-table">
                                <tbody>
                                    <?php foreach ($at_specs as $at_spec) : ?>
                                        <tr><th scope="row"><?= e($at_spec[0]) ?></th><td><?= e($at_spec[1]) ?></td></tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </details>
                <?php endif; ?>
                <?php if ($faqs !== []) : ?>
                    <details class="at-acc-item" id="af-faq">
                        <summary><?= e(t('Frequently asked questions')) ?></summary>
                        <div class="at-acc-body">
                            <?php foreach ($faqs as $at_faq) : ?>
                                <details class="at-acc-sub">
                                    <summary><?= e($at_faq['question']) ?></summary>
                                    <div class="af-prose"><?= ($at_faq['answer']) ?></div>
                                </details>
                            <?php endforeach; ?>
                        </div>
                    </details>
                <?php endif; ?>
            </div>
            <?= hook('product.summary.after', $at_p) ?>
        </div>
    </div>

    <?php if ($related !== []) : ?>
        <section class="at-related af-section" id="af-related">
            <header class="at-row-head"><h2><?= e(t('You may also like')) ?></h2></header>
            <div class="at-grid at-row-4 af-grid">
                <?php foreach ($related as $at_card) : ?>
                    <?= $this->partial('partials/product-card', ['product' => $at_card]) ?>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>
    <?= hook('product.bottom', $at_p) ?>
</div>
