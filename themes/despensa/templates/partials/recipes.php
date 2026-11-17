<?php
/**
 * AIFEC — recetas de la portada: las últimas entradas del blog como tarjetas redondeadas con imagen, título y extracto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $posts entradas del blog: title, url, image, excerpt
 */

$ds_posts = isset($posts) && is_array($posts) ? $posts : [];
if ($ds_posts === []) {
    return;
}
?>
<section class="ds-recipes af-container af-section" aria-labelledby="ds-recipes-title">
    <h2 class="ds-recipes-title" id="ds-recipes-title"><?= e(t('Recipes from the pantry')) ?></h2>
    <div class="ds-recipes-grid">
        <?php foreach ($ds_posts as $ds_post) : ?>
            <article class="ds-recipe">
                <?php if (isset($ds_post['image']) && is_array($ds_post['image']) && !empty($ds_post['image']['url'])) : ?>
                    <a class="ds-recipe-media" href="<?= e((string) $ds_post['url']) ?>" tabindex="-1" aria-hidden="true"><?= image_tag($ds_post['image'], 'card', ['alt' => '']) ?></a>
                <?php endif; ?>
                <div class="ds-recipe-body">
                    <h3 class="ds-recipe-name"><a href="<?= e((string) $ds_post['url']) ?>"><?= e((string) $ds_post['title']) ?></a></h3>
                    <?php if (isset($ds_post['excerpt']) && (string) $ds_post['excerpt'] !== '') : ?>
                        <p class="ds-recipe-text"><?= e((string) $ds_post['excerpt']) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
