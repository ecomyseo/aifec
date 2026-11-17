<?php
/**
 * AIFEC — la sección «Categorías» de la portada en atelier: mosaico asimétrico (una categoría grande y dos pequeñas) y,
 * si hay más, el resto como enlaces de texto debajo.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $title
 * @var array<int,array<string,mixed>> $categories name, url, image
 */

$at_all = is_array($categories) ? array_values($categories) : [];
$at_tiles = array_slice($at_all, 0, 3);
$at_more = array_slice($at_all, 3, 9);
$at_flip = theme_option('mosaic_side', 'left') === 'right';
?>
<section aria-labelledby="af-catalog-categories-title" class="at-mosaic-section af-section">
    <header class="at-row-head">
        <h2 id="af-catalog-categories-title"><?= e((string) $title) ?></h2>
    </header>
    <?php if ($at_tiles !== []) : ?>
        <div class="at-mosaic at-mosaic-n<?= e(count($at_tiles)) ?><?= ($at_flip ? ' at-mosaic-flip' : '') ?>">
            <?php foreach ($at_tiles as $at_n => $at_category) : ?>
                <a class="at-tile<?= ($at_n === 0 ? ' at-tile-big' : '') ?>" href="<?= e((string) $at_category['url']) ?>">
                    <?= image_tag($at_category['image'], $at_n === 0 ? 'large' : 'card', ['alt' => '', 'class' => 'at-tile-img']) ?>
                    <span class="at-tile-name"><?= e((string) $at_category['name']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <?php if ($at_more !== []) : ?>
        <ul class="at-more">
            <?php foreach ($at_more as $at_category) : ?>
                <li><a href="<?= e((string) $at_category['url']) ?>"><?= e((string) $at_category['name']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</section>
