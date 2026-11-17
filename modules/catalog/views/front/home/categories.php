<?php
/**
 * AIFEC — la sección «Categorías» de la portada: las de primer nivel que tienen imagen, como accesos grandes.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var string                         $title
 * @var array<int,array<string,mixed>> $categories name, url, image
 */
?>
<section aria-labelledby="af-catalog-categories-title" class="af-section">
    <h2 id="af-catalog-categories-title"><?= e((string) $title) ?></h2>
    <div class="af-grid af-cols-4">
        <?php foreach ($categories as $af_category) : ?>
            <a class="af-card af-stack" href="<?= e((string) $af_category['url']) ?>">
                <?= image_tag($af_category['image'], 'card', ['alt' => '']) ?>
                <span><?= e((string) $af_category['name']) ?></span>
            </a>
        <?php endforeach; ?>
    </div>
</section>
