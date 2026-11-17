<?php
/**
 * AIFEC — ficha de producto de Lookbook (sustituye `catalog::front/product`). A la izquierda, todas las fotos a la vista:
 * la portada a todo el ancho de la columna y las demás en parejas. A la derecha, la compra, estrecha y pegajosa: la
 * colección y la referencia en pequeño, el nombre en serifa cursiva, el precio, la entradilla, las variantes, la
 * disponibilidad y el botón. Debajo, a dos columnas, la descripción junto a los detalles de la prenda; después, las
 * preguntas y las propuestas. Conserva todos los datos, los formularios y los huecos `product.*` de la plantilla original.
 *
 * Las fotos: la que se está viendo en grande es la portada; las demás van en la pared de parejas, y pulsar una la sube a
 * la portada (lo hace theme.js con `data-src`; al elegir un color, también).
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
$lb_p = $product;
$lb_has_variants = $initial !== null && is_array($variants_data);
$lb_images = isset($lb_p['images']) && is_array($lb_p['images']) ? array_values($lb_p['images']) : [];
$lb_rating = isset($lb_p['rating']) && is_array($lb_p['rating']) ? $lb_p['rating'] : null;
$lb_avg = $lb_rating !== null ? max(0.0, min(5.0, (float) $lb_rating['average'])) : 0.0;
$lb_count = $lb_rating !== null ? (int) $lb_rating['count'] : 0;
$lb_min = max(1, (int) $lb_p['min_qty']);
$lb_max = (int) $lb_p['max_qty'] > 0 ? (int) $lb_p['max_qty'] : null;
$lb_selected = $lb_has_variants && isset($initial['values']) && is_array($initial['values']) ? $initial['values'] : [];
$lb_column = theme_option('gallery_layout', 'pairs') === 'column';
$lb_sticky = (int) theme_option('buy_sticky', 1) === 1;

// Los detalles de la prenda: los atributos visibles, los datos públicos que añaden los plugins y las referencias.
$lb_specs = [];
foreach (is_array($lb_p['attributes']) ? $lb_p['attributes'] : [] as $lb_attr) {
    $lb_specs[] = [(string) $lb_attr['name'], (string) $lb_attr['value']];
}
foreach (is_array($lb_p['extra']) ? $lb_p['extra'] : [] as $lb_extra) {
    if (is_array($lb_extra) && !empty($lb_extra['public']) && isset($lb_extra['label']) && isset($lb_extra['value']) && is_scalar($lb_extra['value']) && (string) $lb_extra['value'] !== '') {
        $lb_specs[] = [(string) $lb_extra['label'], (string) $lb_extra['value']];
    }
}
foreach ([[t('GTIN'), $lb_p['gtin']], [t('MPN'), $lb_p['mpn']]] as $lb_ref) {
    if ($lb_ref[1] !== null && (string) $lb_ref[1] !== '') {
        $lb_specs[] = [$lb_ref[0], (string) $lb_ref[1]];
    }
}
// Los datos clave van delante; uno que ya sale entre los detalles (Color, Marca…) no se repite.
$lb_seen = [];
foreach ($lb_specs as $lb_row) {
    $lb_seen[mb_strtolower(trim($lb_row[0]), 'UTF-8')] = true;
}
$lb_facts = [];
foreach (array_slice(is_array($lb_p['facts']) ? $lb_p['facts'] : [], 0, 6) as $lb_fact) {
    if (is_array($lb_fact) && isset($lb_fact['label'], $lb_fact['value']) && !isset($lb_seen[mb_strtolower(trim((string) $lb_fact['label']), 'UTF-8')])) {
        $lb_facts[] = [(string) $lb_fact['label'], (string) $lb_fact['value']];
    }
}
$lb_first = $lb_images !== [] ? $lb_images[0] : null;
if ($lb_first !== null && (string) $lb_first['alt'] === '') {
    $lb_first['alt'] = (string) $lb_p['name'];
}
// La portada ocupa media página en escritorio y toda en móvil: se lo dice al navegador para que elija bien el tamaño.
$lb_cover = ['loading' => 'eager', 'fetchpriority' => 'high', 'data-af-gallery-main' => true];
if ($lb_first !== null && !empty($lb_first['srcset'])) {
    $lb_cover['sizes'] = '(max-width: 899px) 100vw, 50vw';
}
?>
<div class="af-container lb-sheet-wrap">
    <?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
    <article class="af-product lb-sheet<?= ($lb_sticky ? ' lb-sheet-pinned' : '') ?>"<?= ($lb_has_variants ? ' data-af-variants' : '') ?>>
        <section class="af-product-gallery lb-plates" aria-label="<?= e(t('Product photos')) ?>">
            <ul class="af-gallery lb-wall<?= ($lb_column ? ' lb-wall-column' : '') ?>" data-af-gallery>
                <li class="af-gallery-main lb-cover">
                    <?= hook('product.gallery.before', $lb_p) ?>
                    <?= image_tag($lb_first, 'large', $lb_cover) ?>
                </li>
                <?php foreach (count($lb_images) > 1 ? $lb_images : [] as $lb_i => $lb_image) : ?>
                    <li class="lb-plate"><a href="<?= e((string) $lb_image['large']) ?>" data-src="<?= e((string) $lb_image['large']) ?>" data-alt="<?= e((string) $lb_image['alt'] !== '' ? (string) $lb_image['alt'] : (string) $lb_p['name']) ?>" data-image-id="<?= e($lb_image['id']) ?>" aria-label="<?= e(t('Image {n}', ['n' => $lb_i + 1])) ?>"<?= ($lb_i === 0 ? ' aria-current="true"' : '') ?>><?= image_tag($lb_image, 'large', ['alt' => '']) ?></a></li>
                <?php endforeach; ?>
            </ul>
            <?= hook('product.gallery.after', $lb_p) ?>
        </section>
        <aside class="af-product-buy lb-buy" aria-label="<?= e(t('Purchase')) ?>">
            <?= hook('product.summary.before', $lb_p) ?>
            <hgroup class="lb-buy-head">
                <p class="lb-over">
                    <?php if (is_array($lb_p['brand'])) : ?>
                        <a href="<?= e((string) $lb_p['brand']['url']) ?>"><?= e((string) $lb_p['brand']['name']) ?></a>
                    <?php endif; ?>
                    <?php if ($sku !== '' || $lb_has_variants) : ?>
                        <span><?= e(t('SKU')) ?> <b data-af-sku><?= e($sku) ?></b></span>
                    <?php endif; ?>
                </p>
                <h1 class="af-title lb-buy-name"><?= e((string) $lb_p['name']) ?></h1>
            </hgroup>
            <?php if ($lb_count > 0) : ?>
                <p class="lb-buy-rating">
                    <span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($lb_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($lb_avg, 1, '.', '')) ?>"></span>
                    <small>(<?= e($lb_count) ?>)</small>
                </p>
            <?php endif; ?>
            <?= $this->partial('partials/price', ['price' => $price, 'size' => 'lg', 'live' => $lb_has_variants]) ?>
            <?= hook('product.price.after', $lb_p) ?>
            <?php if ($summary_html !== '') : ?>
                <div class="lb-buy-lead af-prose"><?= ($summary_html) ?></div>
            <?php endif; ?>
            <?php if ($can_buy) : ?>
                <form class="lb-buy-form" method="post" action="<?= e(route('cart.add')) ?>" data-af-cart-add<?= hook('webmcp.form', 'add_to_cart') ?>>
                    <input type="hidden" name="product_id" value="<?= e($lb_p['id']) ?>"<?= hook('webmcp.param', 'add_to_cart', 'product_id') ?>>
                    <input type="hidden" name="variant_id" value="<?= e($lb_has_variants ? $initial['id'] : 0) ?>" data-af-variant-id<?= hook('webmcp.param', 'add_to_cart', 'variant_id') ?>>
                    <?php if ($lb_has_variants) : ?>
                        <?= $this->partial('catalog::front/partials/options', ['options' => $lb_p['options'], 'selected' => $lb_selected, 'tool' => 'add_to_cart']) ?>
                        <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                    <?php endif; ?>
                    <output class="af-availability <?= e($stock_class) ?>" data-af-availability><?= e((string) $stock['label']) ?></output>
                    <?php if ($delivery !== '') : ?>
                        <small class="lb-buy-delivery"><?= e($delivery) ?></small>
                    <?php endif; ?>
                    <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => $lb_min, 'min' => $lb_min, 'max' => $lb_max, 'tool' => 'add_to_cart']) ?>
                    <button type="submit" class="af-btn af-btn-primary af-btn-lg af-btn-block" data-af-add<?= ($lb_has_variants && $choose_action !== '' ? ' formaction="' . e($choose_action) . '"' : '') ?><?= ($sold_out ? ' disabled' : '') ?>><?= e($sold_out && !$lb_has_variants ? t('Sold out') : t('Add to cart')) ?></button>
                </form>
            <?php else : ?>
                <?php if ($lb_has_variants) : ?>
                    <div class="lb-buy-form">
                        <?= $this->partial('catalog::front/partials/options', ['options' => $lb_p['options'], 'selected' => $lb_selected]) ?>
                        <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
                    </div>
                <?php endif; ?>
                <output class="af-availability <?= e($stock_class) ?>" data-af-availability><?= e((string) $stock['label']) ?></output>
                <?php if ($delivery !== '') : ?>
                    <small class="lb-buy-delivery"><?= e($delivery) ?></small>
                <?php endif; ?>
            <?php endif; ?>
            <?= ($can_buy ? $this->partial('catalog::front/partials/trust', ['lines' => isset($trust) && is_array($trust) ? $trust : null, 'class' => 'lb-trust']) : '') ?>
            <?= hook('product.buy.after', $lb_p) ?>
            <?= hook('product.summary.after', $lb_p) ?>
        </aside>
    </article>

    <?php if ($description_html !== '' || $lb_facts !== [] || $lb_specs !== []) : ?>
        <div class="lb-notes">
            <?php if ($description_html !== '') : ?>
                <section class="lb-notes-text" id="af-description">
                    <h2 class="lb-notes-title"><?= e(t('Description')) ?></h2>
                    <div class="af-prose"><?= ($description_html) ?></div>
                </section>
            <?php endif; ?>
            <?php if ($lb_facts !== [] || $lb_specs !== []) : ?>
                <section class="lb-notes-facts" id="af-specs">
                    <h2 class="lb-notes-title"><?= e(t('Details')) ?></h2>
                    <dl class="af-facts">
                        <?php foreach (array_merge($lb_facts, $lb_specs) as $lb_row) : ?>
                            <div><dt><?= e($lb_row[0]) ?></dt><dd><?= e($lb_row[1]) ?></dd></div>
                        <?php endforeach; ?>
                    </dl>
                </section>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?= hook('product.tabs', $lb_p) ?>
    <?php if ($faqs !== []) : ?>
        <section class="lb-faq" id="af-faq">
            <h2 class="lb-notes-title"><?= e(t('Frequently asked questions')) ?></h2>
            <?php foreach ($faqs as $lb_faq) : ?>
                <details class="lb-faq-item">
                    <summary><?= e($lb_faq['question']) ?></summary>
                    <div class="af-prose"><?= ($lb_faq['answer']) ?></div>
                </details>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>
    <?php if ($related !== []) : ?>
        <section class="lb-suite" id="af-related">
            <h2 class="lb-notes-title"><?= e(t('You may also like')) ?></h2>
            <ul class="lb-rail">
                <?php foreach ($related as $lb_card) : ?>
                    <li><?= $this->partial('partials/product-card', ['product' => $lb_card]) ?></li>
                <?php endforeach; ?>
            </ul>
        </section>
    <?php endif; ?>
    <?= hook('product.bottom', $lb_p) ?>
</div>
