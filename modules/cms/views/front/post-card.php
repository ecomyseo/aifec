<?php
/**
 * AIFEC — tarjeta de una entrada en un listado: imagen, título, fecha, categoría y extracto. La imagen y el título enlazan a la entrada.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<string,mixed> $post lo que da Cms::post()
 */

use Aifec\Modules\Cms\Front\Support;

$af_date = Support::date(app(), isset($post['published_at']) ? (string) $post['published_at'] : null);
$af_categories = isset($post['categories']) && is_array($post['categories']) ? $post['categories'] : [];
?>
<article class="af-card af-post-card af-stack">
    <?php if (is_array($post['image']) && !empty($post['image']['url'])) : ?>
        <a class="af-post-card-image" href="<?= e($post['url']) ?>" tabindex="-1" aria-hidden="true">
            <?= image_tag($post['image'], 'card', ['alt' => '']) ?>
        </a>
    <?php endif; ?>
    <h2 class="af-post-card-title"><a href="<?= e($post['url']) ?>"><?= e($post['title']) ?></a></h2>
    <?php if ($af_date['iso'] !== '' || $af_categories !== []) : ?>
        <p class="af-muted af-small">
            <?php if ($af_date['iso'] !== '') : ?>
                <time datetime="<?= e($af_date['iso']) ?>"><?= e($af_date['label']) ?></time>
            <?php endif; ?>
            <?php if ($af_date['iso'] !== '' && $af_categories !== []) : ?>
                &middot;
            <?php endif; ?>
            <?php if ($af_categories !== []) : ?>
                <a href="<?= e($af_categories[0]['url']) ?>"><?= e($af_categories[0]['name']) ?></a>
            <?php endif; ?>
        </p>
    <?php endif; ?>
    <?php if ((string) $post['excerpt'] !== '') : ?>
        <p><?= e($post['excerpt']) ?></p>
    <?php endif; ?>
</article>
