<?php
/**
 * AIFEC — pieza (tarjeta de producto) de Lookbook: la foto vertical, sin caja ni marco, con la segunda vista al pasar el
 * ratón; debajo, una sola línea con el nombre a un lado y el precio al otro. Los colores, las tallas y los avisos
 * («Nuevo», «−20 %», «Agotado») solo salen si el producto los trae, escritos en pequeño. No lleva botón de carrito: el
 * nombre es el enlace y cubre toda la pieza.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $product ProductCard: name, url, image, image2, price, brand, badges, badge_list, buy
 */

$lb_p = isset($product) && is_array($product) ? $product : [];
$lb_url = isset($lb_p['url']) ? (string) $lb_p['url'] : '';
$lb_name = isset($lb_p['name']) ? (string) $lb_p['name'] : '';
$lb_brand = isset($lb_p['brand']) && is_string($lb_p['brand']) ? trim($lb_p['brand']) : '';
$lb_image = isset($lb_p['image']) && is_array($lb_p['image']) ? $lb_p['image'] : null;
$lb_price = isset($lb_p['price']) && is_array($lb_p['price']) ? $lb_p['price'] : [];
$lb_have = isset($lb_p['badges']) && is_array($lb_p['badges']) ? $lb_p['badges'] : [];
$lb_shape = (string) theme_option('card_ratio', 'portrait');
$lb_shape = in_array($lb_shape, ['tall', 'square'], true) ? ' lb-look-' . $lb_shape : '';
$lb_show_brand = $lb_brand !== '' && (int) theme_option('show_brand_in_card', 0) === 1;

// La segunda vista: solo si el producto la trae y no es la misma foto.
$lb_back = null;
if ((int) theme_option('card_second', 1) === 1 && isset($lb_p['image2']) && is_array($lb_p['image2']) && !empty($lb_p['image2']['url'])
    && ($lb_image === null || (string) $lb_p['image2']['url'] !== (string) $lb_image['url'])) {
    $lb_back = $lb_p['image2'];
}

// Colores (los valores que traen su color) y tallas (la primera opción sin colores), tal como los da el catálogo.
$lb_tones = [];
$lb_sizes = [];
$lb_tones_name = '';
$lb_sizes_name = '';
$lb_buy =isset($lb_p['buy']) && is_array($lb_p['buy']) ? $lb_p['buy'] : [];
foreach (isset($lb_buy['options']) && is_array($lb_buy['options']) ? $lb_buy['options'] : [] as $lb_option) {
    $lb_plain = [];
    $lb_dots = [];
    foreach (is_array($lb_option) && isset($lb_option['values']) && is_array($lb_option['values']) ? $lb_option['values'] : [] as $lb_value) {
        if (!is_array($lb_value) || !isset($lb_value['label']) || trim((string) $lb_value['label']) === '') {
            continue;
        }
        if (isset($lb_value['color']) && is_string($lb_value['color']) && preg_match('/^#[0-9a-fA-F]{6}$/', $lb_value['color']) === 1) {
            $lb_dots[] = ['label' => (string) $lb_value['label'], 'color' => strtolower($lb_value['color'])];
        } else {
            $lb_plain[] = (string) $lb_value['label'];
        }
    }
    $lb_title = is_array($lb_option) && isset($lb_option['name']) ? (string) $lb_option['name'] : '';
    if ($lb_dots !== [] && $lb_tones === []) {
        $lb_tones = $lb_dots;
        $lb_tones_name = $lb_title;
    } elseif ($lb_dots === [] && $lb_plain !== [] && $lb_sizes === []) {
        $lb_sizes = $lb_plain;
        $lb_sizes_name = $lb_title;
    }
}
$lb_more = max(0, count($lb_tones) - 5);
$lb_tones = array_slice($lb_tones, 0, 5);
?>
<article class="af-product-card lb-look<?= e($lb_shape) ?><?= in_array('out', $lb_have, true) ? ' is-out' : '' ?>">
    <figure class="lb-look-shot">
        <?= image_tag($lb_image, 'card', ['class' => 'lb-look-img', 'alt' => $lb_name]) ?>
        <?php if ($lb_back !== null) : ?>
            <?= image_tag($lb_back, 'card', ['class' => 'lb-look-img lb-look-back', 'alt' => $lb_name]) ?>
        <?php endif; ?>
    </figure>
    <?php if ($lb_show_brand) : ?>
        <p class="lb-look-brand"><?= e($lb_brand) ?></p>
    <?php endif; ?>
    <div class="lb-look-line">
        <h3 class="lb-look-name">
            <?php if ($lb_url !== '') : ?>
                <a class="lb-look-link" href="<?= e($lb_url) ?>"><?= e($lb_name) ?></a>
            <?php else : ?>
                <?= e($lb_name) ?>
            <?php endif; ?>
        </h3>
        <?= $this->partial('partials/price', ['price' => $lb_price, 'size' => 'sm']) ?>
    </div>
    <?php if ($lb_tones !== [] || $lb_sizes !== []) : ?>
        <dl class="lb-look-range">
            <?php if ($lb_tones !== []) : ?>
                <dt class="af-sr"><?= e($lb_tones_name !== '' ? $lb_tones_name : t('Colours')) ?></dt>
                <dd class="lb-look-tones">
                    <?php foreach ($lb_tones as $lb_tone) : ?>
                        <span class="lb-tone" style="--tone:<?= e($lb_tone['color']) ?>" title="<?= e($lb_tone['label']) ?>"><span class="af-sr"><?= e($lb_tone['label']) ?></span></span>
                    <?php endforeach; ?>
                    <?php if ($lb_more > 0) : ?>
                        <small class="lb-look-more">+<?= e($lb_more) ?></small>
                    <?php endif; ?>
                </dd>
            <?php endif; ?>
            <?php if ($lb_sizes !== []) : ?>
                <dt class="af-sr"><?= e($lb_sizes_name !== '' ? $lb_sizes_name : t('Options')) ?></dt>
                <dd class="lb-look-sizes"><?= e(implode(' · ', $lb_sizes)) ?></dd>
            <?php endif; ?>
        </dl>
    <?php endif; ?>
    <?= $this->partial('partials/badges', ['product' => $lb_p, 'tag' => 'p', 'class' => 'lb-look-notes']) ?>
</article>
