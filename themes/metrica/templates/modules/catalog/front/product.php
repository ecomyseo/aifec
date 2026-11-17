<?php
/**
 * AIFEC — ficha de producto de Métrica (sustituye `catalog::front/product`), «pieza y tabla de medidas». A la izquierda, las
 * vistas en un carril vertical junto a la foto cuadrada y, debajo, la tabla de medidas: una fila por variante con su
 * referencia, sus existencias y su precio (los datos son los de cada variante; sin variantes no hay tabla), y pulsar una
 * medida la elige. A la derecha: referencia, nombre, entradilla, precio y compra. Debajo, la descripción, los datos y las
 * preguntas, cada bloque con su rótulo a un lado. Conserva todos los datos, los formularios y los huecos `product.*`.
 *
 * El HTML va con poca sangría a propósito: la ficha tiene un presupuesto de peso y cada fila de la tabla se repite.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed>                  $page             datos de cabecera (ver Aifec\Front\Page)
 * @var array<string,mixed>                  $product          ProductData
 * @var array<int,array<string,mixed>>       $crumbs
 * @var array<int,array<string,mixed>>       $related          tarjetas de «También te puede interesar»
 * @var array<int,array<string,string>>      $trust            líneas de confianza de debajo del botón
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
$mt_p = $product;
$mt_has_variants = $initial !== null && is_array($variants_data);
$mt_images = isset($mt_p['images']) && is_array($mt_p['images']) ? array_values($mt_p['images']) : [];
$mt_rating = isset($mt_p['rating']) && is_array($mt_p['rating']) ? $mt_p['rating'] : null;
$mt_avg = $mt_rating !== null ? max(0.0, min(5.0, (float) $mt_rating['average'])) : 0.0;
$mt_votes = $mt_rating !== null ? (int) $mt_rating['count'] : 0;
$mt_min = max(1, (int) $mt_p['min_qty']);
$mt_max = (int) $mt_p['max_qty'] > 0 ? (int) $mt_p['max_qty'] : null;
$mt_selected = $mt_has_variants && isset($initial['values']) && is_array($initial['values']) ? $initial['values'] : [];
$mt_rail = theme_option('gallery_rail', 'side') === 'below' ? 'below' : 'side';
$mt_first = $mt_images !== [] ? $mt_images[0] : null;
if ($mt_first !== null && (string) $mt_first['alt'] === '') {
    $mt_first['alt'] = (string) $mt_p['name'];
}

// La tabla de características: los atributos visibles, los datos públicos que añaden los plugins y las referencias.
$mt_specs = [];
foreach (is_array($mt_p['attributes']) ? $mt_p['attributes'] : [] as $mt_attr) {
    $mt_specs[] = [(string) $mt_attr['name'], (string) $mt_attr['value']];
}
foreach (is_array($mt_p['extra']) ? $mt_p['extra'] : [] as $mt_extra) {
    if (is_array($mt_extra) && !empty($mt_extra['public']) && isset($mt_extra['label']) && isset($mt_extra['value']) && is_scalar($mt_extra['value']) && (string) $mt_extra['value'] !== '') {
        $mt_specs[] = [(string) $mt_extra['label'], (string) $mt_extra['value']];
    }
}
foreach ([[t('GTIN'), $mt_p['gtin']], [t('MPN'), $mt_p['mpn']]] as $mt_ref) {
    if ($mt_ref[1] !== null && (string) $mt_ref[1] !== '') {
        $mt_specs[] = [$mt_ref[0], (string) $mt_ref[1]];
    }
}
$mt_facts = array_slice(is_array($mt_p['facts']) ? $mt_p['facts'] : [], 0, 6);
// Un dato que ya sale en la tabla de características no se repite arriba: se enseña una sola vez.
$mt_spec_names = [];
foreach ($mt_specs as $mt_spec_row) {
    $mt_spec_names[mb_strtolower(trim((string) $mt_spec_row[0]), 'UTF-8')] = true;
}
$mt_facts = array_values(array_filter($mt_facts, static function ($mt_f) use ($mt_spec_names): bool {
    return !is_array($mt_f) || !isset($mt_f['label']) || !isset($mt_spec_names[mb_strtolower(trim((string) $mt_f['label']), 'UTF-8')]);
}));

// La tabla de medidas: una fila por variante, con lo que el catálogo dice de ella. Con muchas, las primeras (el tope evita
// una tabla interminable; todas se pueden elegir en las opciones de la compra).
$mt_rows = [];
$mt_scale = false;
if ($mt_has_variants && (int) theme_option('sizes_table', 1) === 1) {
    foreach (isset($mt_p['variants']) && is_array($mt_p['variants']) ? $mt_p['variants'] : [] as $mt_variant) {
        if (!is_array($mt_variant) || !isset($mt_variant['id'])) {
            continue;
        }
        $mt_vprice = isset($mt_variant['price']) && is_array($mt_variant['price']) ? $mt_variant['price'] : [];
        $mt_vstock = isset($mt_variant['availability']) && is_array($mt_variant['availability']) ? $mt_variant['availability'] : [];
        $mt_vlabel = isset($mt_variant['label']) ? (string) $mt_variant['label'] : '';
        $mt_scale = $mt_scale || preg_match('/\d/', $mt_vlabel) === 1;
        $mt_rows[] = [
            'id' => (int) $mt_variant['id'],
            'label' => $mt_vlabel,
            'sku' => isset($mt_variant['sku']) ? (string) $mt_variant['sku'] : '',
            'stock' => isset($mt_vstock['label']) ? (string) $mt_vstock['label'] : '',
            'class' => \Aifec\Modules\Catalog\Front\Support::availabilityClass(isset($mt_vstock['status']) ? (string) $mt_vstock['status'] : 'in_stock'),
            'price' => !empty($mt_vprice['hidden']) || !isset($mt_vprice['amount']) ? '' : money((int) $mt_vprice['amount'], isset($mt_vprice['currency']) && is_string($mt_vprice['currency']) && $mt_vprice['currency'] !== '' ? $mt_vprice['currency'] : null),
        ];
    }
}
$mt_hidden_rows = max(0, count($mt_rows) - 48);
$mt_rows = array_slice($mt_rows, 0, 48);
?>
<div class="af-container af-section">
<?= $this->partial('partials/breadcrumbs', ['crumbs' => $crumbs]) ?>
<article class="af-product mt-product"<?= ($mt_has_variants ? ' data-af-variants' : '') ?>>
<div class="af-product-gallery">
 <div class="mt-stage">
  <div class="af-gallery mt-gallery mt-rail-<?= e($mt_rail) ?><?= (count($mt_images) > 1 ? '' : ' mt-gallery-one') ?>" data-af-gallery>
<?php if (count($mt_images) > 1) : ?>
   <ul class="af-gallery-thumbs">
<?php foreach ($mt_images as $mt_i => $mt_image) : ?>
    <li><a href="<?= e((string) $mt_image['large']) ?>" data-src="<?= e((string) $mt_image['large']) ?>" data-alt="<?= e((string) $mt_image['alt'] !== '' ? (string) $mt_image['alt'] : (string) $mt_p['name']) ?>" data-image-id="<?= e($mt_image['id']) ?>" aria-label="<?= e(t('Image {n}', ['n' => $mt_i + 1])) ?>"<?= ($mt_i === 0 ? ' aria-current="true"' : '') ?>><?= image_tag($mt_image, 'thumb', ['alt' => '']) ?></a></li>
<?php endforeach; ?>
   </ul>
<?php endif; ?>
   <figure class="af-gallery-main">
    <?= hook('product.gallery.before', $mt_p) ?>
    <?= image_tag($mt_first, 'large', ['loading' => 'eager', 'fetchpriority' => 'high', 'data-af-gallery-main' => true]) ?>
   </figure>
  </div>
  <?= hook('product.gallery.after', $mt_p) ?>
 </div>
<?php if ($mt_rows !== []) : ?>
 <section class="mt-sizes" aria-labelledby="mt-sizes-title">
  <h2 id="mt-sizes-title"><?= e($mt_scale ? t('Measures table') : t('Options table')) ?></h2>
  <div class="af-table-wrap">
   <table class="af-table mt-sizes-table">
    <thead><tr><th scope="col"><?= e($mt_scale ? t('Measure') : t('Option')) ?></th><th scope="col"><?= e(t('Reference')) ?></th><th scope="col"><?= e(t('Stock')) ?></th><th scope="col"><?= e(t('Price')) ?></th></tr></thead>
    <tbody>
<?php foreach ($mt_rows as $mt_row) : ?>
<?php $mt_on = (int) $initial['id'] === $mt_row['id']; ?>
<tr data-mt-row="<?= e($mt_row['id']) ?>"<?= ($mt_on ? ' class="is-on"' : '') ?>><th scope="row"><a href="?variante=<?= e($mt_row['id']) ?>"<?= ($mt_on ? ' aria-current="true"' : '') ?>><?= e($mt_row['label']) ?></a></th><td><?= e($mt_row['sku']) ?></td><td class="<?= e($mt_row['class']) ?>"><?= e($mt_row['stock']) ?></td><td><?= e($mt_row['price']) ?></td></tr>
<?php endforeach; ?>
    </tbody>
   </table>
  </div>
<?php if ($mt_hidden_rows > 0) : ?>
  <p class="af-small af-muted"><?= e(tn('{n} more option: choose it in the purchase options.', '{n} more options: choose them in the purchase options.', $mt_hidden_rows)) ?></p>
<?php endif; ?>
 </section>
<?php endif; ?>
</div>
<div class="af-product-buy">
 <?= hook('product.summary.before', $mt_p) ?>
 <ul class="mt-ref">
<?php if (is_array($mt_p['brand'])) : ?>
  <li><a href="<?= e((string) $mt_p['brand']['url']) ?>"><?= e((string) $mt_p['brand']['name']) ?></a></li>
<?php endif; ?>
<?php if ($sku !== '' || $mt_has_variants) : ?>
  <li><?= e(t('Ref.')) ?> <b data-af-sku><?= e($sku) ?></b></li>
<?php endif; ?>
<?php if ($mt_votes > 0) : ?>
  <li><span class="af-stars" role="img" aria-label="<?= e(t('{rating} out of 5', ['rating' => number_format($mt_avg, 1, '.', '')])) ?>" style="--rating:<?= e(number_format($mt_avg, 1, '.', '')) ?>"></span> (<?= e($mt_votes) ?>)</li>
<?php endif; ?>
 </ul>
 <h1 class="af-title"><?= e((string) $mt_p['name']) ?></h1>
<?php if ($summary_html !== '') : ?>
 <div class="af-prose mt-lead"><?= $summary_html ?></div>
<?php endif; ?>
 <div class="mt-price">
  <?= $this->partial('partials/price', ['price' => $price, 'size' => 'lg', 'live' => $mt_has_variants]) ?>
  <?= hook('product.price.after', $mt_p) ?>
 </div>
<?php if ($can_buy) : ?>
 <form class="mt-buy" method="post" action="<?= e(route('cart.add')) ?>" data-af-cart-add<?= hook('webmcp.form', 'add_to_cart') ?>>
  <input type="hidden" name="product_id" value="<?= e($mt_p['id']) ?>"<?= hook('webmcp.param', 'add_to_cart', 'product_id') ?>>
  <input type="hidden" name="variant_id" value="<?= e($mt_has_variants ? $initial['id'] : 0) ?>" data-af-variant-id<?= hook('webmcp.param', 'add_to_cart', 'variant_id') ?>>
<?php if ($mt_has_variants) : ?>
  <?= $this->partial('catalog::front/partials/options', ['options' => $mt_p['options'], 'selected' => $mt_selected, 'tool' => 'add_to_cart']) ?>
  <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
<?php endif; ?>
  <div class="af-buy-row mt-buy-row">
   <?= $this->partial('partials/qty', ['name' => 'qty', 'value' => $mt_min, 'min' => $mt_min, 'max' => $mt_max, 'tool' => 'add_to_cart']) ?>
   <button type="submit" class="af-btn af-btn-primary af-btn-lg" data-af-add<?= ($mt_has_variants && $choose_action !== '' ? ' formaction="' . e($choose_action) . '"' : '') ?><?= ($sold_out ? ' disabled' : '') ?>><?= e($sold_out && !$mt_has_variants ? t('Sold out') : t('Add to cart')) ?></button>
  </div>
 </form>
<?php elseif ($mt_has_variants) : ?>
 <div class="mt-buy">
  <?= $this->partial('catalog::front/partials/options', ['options' => $mt_p['options'], 'selected' => $mt_selected]) ?>
  <script type="application/json" data-af-variants-data><?= json_html($variants_data) ?></script>
 </div>
<?php endif; ?>
 <p class="af-availability <?= e($stock_class) ?>" data-af-availability><?= e((string) $stock['label']) ?></p>
<?php if ($delivery !== '') : ?>
 <p class="af-small af-muted"><?= e($delivery) ?></p>
<?php endif; ?>
 <?= ($can_buy ? $this->partial('catalog::front/partials/trust', ['lines' => isset($trust) && is_array($trust) ? $trust : null]) : '') ?>
 <?= hook('product.buy.after', $mt_p) ?>
<?php if ($mt_facts !== []) : ?>
 <dl class="af-facts">
<?php foreach ($mt_facts as $mt_fact) : ?>
  <div><dt><?= e((string) $mt_fact['label']) ?></dt><dd><?= e((string) $mt_fact['value']) ?></dd></div>
<?php endforeach; ?>
 </dl>
<?php endif; ?>
 <?= hook('product.summary.after', $mt_p) ?>
</div>
</article>

<div class="mt-below">
<?php if ($description_html !== '') : ?>
 <section class="mt-below-row" id="af-description">
  <h2><?= e(t('Description')) ?></h2>
  <div class="af-prose"><?= $description_html ?></div>
 </section>
<?php endif; ?>
 <?= hook('product.tabs', $mt_p) ?>
<?php if ($mt_specs !== []) : ?>
 <section class="mt-below-row" id="af-specs">
  <h2><?= e(t('Specifications')) ?></h2>
  <div class="af-table-wrap">
   <table class="af-table">
    <tbody>
<?php foreach ($mt_specs as $mt_spec) : ?>
     <tr><th scope="row"><?= e($mt_spec[0]) ?></th><td><?= e($mt_spec[1]) ?></td></tr>
<?php endforeach; ?>
    </tbody>
   </table>
  </div>
 </section>
<?php endif; ?>
<?php if ($faqs !== []) : ?>
 <section class="mt-below-row" id="af-faq">
  <h2><?= e(t('Frequently asked questions')) ?></h2>
  <div class="mt-faqs">
<?php foreach ($faqs as $mt_faq) : ?>
   <details>
    <summary><?= e($mt_faq['question']) ?></summary>
    <div class="af-prose"><?= $mt_faq['answer'] ?></div>
   </details>
<?php endforeach; ?>
  </div>
 </section>
<?php endif; ?>
<?php if ($related !== []) : ?>
 <section class="mt-related" id="af-related">
  <h2><?= e(t('You may also like')) ?></h2>
  <ul class="mt-grid mt-cols-5">
<?php foreach ($related as $mt_card) : ?>
<li><?= $this->partial('partials/product-card', ['product' => $mt_card]) ?></li>
<?php endforeach; ?>
  </ul>
 </section>
<?php endif; ?>
 <?= hook('product.bottom', $mt_p) ?>
</div>
</div>
