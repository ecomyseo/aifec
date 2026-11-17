<?php
/**
 * AIFEC — tarjeta de una entrada de gmartos: imagen grande arriba y, debajo, la fecha, el título, el extracto y el enlace
 * «Leer artículo ↗».
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $post lo que da Cms::posts()
 */

use Aifec\Modules\Cms\Front\Support;

$gm_date = Support::date(app(), isset($post['published_at']) ? (string) $post['published_at'] : null);
$gm_categories = isset($post['categories']) && is_array($post['categories']) ? $post['categories'] : [];
?>
<article class="gm-article af-post-card">
    <?php if (is_array($post['image']) && !empty($post['image']['url'])) : ?>
        <a class="gm-article-image af-post-card-image" href="<?= e($post['url']) ?>" tabindex="-1" aria-hidden="true">
            <?= image_tag($post['image'], 'card', ['alt' => '']) ?>
        </a>
    <?php endif; ?>
    <div class="gm-article-body">
        <?php if ($gm_date['iso'] !== '') : ?>
            <time datetime="<?= e($gm_date['iso']) ?>"><?= e($gm_date['label']) ?></time>
        <?php endif; ?>
        <h2 class="af-post-card-title"><a href="<?= e($post['url']) ?>"><?= e($post['title']) ?></a></h2>
        <?php if ($gm_categories !== []) : ?>
            <p class="af-small af-muted"><a href="<?= e($gm_categories[0]['url']) ?>"><?= e($gm_categories[0]['name']) ?></a></p>
        <?php endif; ?>
        <?php if ((string) $post['excerpt'] !== '') : ?>
            <p class="gm-article-excerpt"><?= e($post['excerpt']) ?></p>
        <?php endif; ?>
        <a class="gm-text-link" href="<?= e($post['url']) ?>"><?= e(t('Read article')) ?> <?= gmartos_svg('arrow', 20) ?><span class="af-sr">: <?= e($post['title']) ?></span></a>
    </div>
</article>
