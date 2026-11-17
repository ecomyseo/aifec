<?php
/**
 * AIFEC — la sección «Categorías» de la portada de despensa: «Lotes y regalos» en tres tarjetas grandes sobre un fondo de
 * color suave y, si hay más categorías, el resto como pastillas debajo. Sin imagen, la tarjeta lleva un dibujo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $title
 * @var array<int,array<string,mixed>> $categories name, url, image
 */

$ds_all = isset($categories) && is_array($categories) ? array_values(array_filter($categories, 'is_array')) : [];
$ds_heading = trim((string) theme_option('gifts_title', ''));
$ds_heading = $ds_heading !== '' ? $ds_heading : t('Gift sets and hampers');
$ds_big = array_slice($ds_all, 0, 3);
$ds_more = array_slice($ds_all, 3, 9);
$ds_icons = ['gift', 'jar', 'leaf'];
?>
<section aria-labelledby="af-catalog-categories-title" class="ds-gifts af-section">
    <h2 class="ds-gifts-title" id="af-catalog-categories-title"><?= e($ds_heading) ?></h2>
    <div class="ds-gifts-grid">
        <?php foreach ($ds_big as $ds_i => $ds_cat) : ?>
            <a class="ds-gift" href="<?= e((string) $ds_cat['url']) ?>" data-tone="<?= e($ds_i + 1) ?>">
                <span class="ds-gift-media">
                    <?php if (isset($ds_cat['image']) && is_array($ds_cat['image']) && !empty($ds_cat['image']['url'])) : ?>
                        <?= image_tag($ds_cat['image'], 'card', ['alt' => '']) ?>
                    <?php else : ?>
                        <span class="ds-gift-art"><?= despensa_svg($ds_icons[$ds_i % 3], 56) ?></span>
                    <?php endif; ?>
                </span>
                <span class="ds-gift-name"><?= e((string) $ds_cat['name']) ?></span>
                <span class="ds-gift-go"><?= e(t('Discover')) ?><?= despensa_svg('arrow', 18) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php if ($ds_more !== []) : ?>
        <ul class="ds-gifts-more">
            <?php foreach ($ds_more as $ds_cat) : ?>
                <li><a class="ds-pill" href="<?= e((string) $ds_cat['url']) ?>"><?= e((string) $ds_cat['name']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
