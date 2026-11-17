<?php
/**
 * AIFEC — categorías de la portada de gmartos: las mismas tarjetas con imagen y nombre que las subcategorías del listado.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $title      título de la sección
 * @var array<int,array<string,mixed>> $categories name, url, image
 */
?>
<section aria-labelledby="gm-catalog-categories-title" class="gm-block">
    <h3 id="gm-catalog-categories-title" class="gm-kicker gm-block-label"><?= e((string) $title) ?></h3>
    <div class="af-grid af-cols-4 gm-tiles">
        <?php foreach ($categories as $gm_category) : ?>
            <a class="gm-tile" href="<?= e((string) $gm_category['url']) ?>">
                <span class="gm-tile-media"><?= image_tag($gm_category['image'], 'card', ['alt' => '']) ?></span>
                <span class="gm-tile-name"><?= e((string) $gm_category['name']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
