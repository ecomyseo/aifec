<?php
/**
 * AIFEC — consejos de la portada de taller: las últimas entradas del blog en tres tarjetas con imagen, fecha y extracto.
 *
 * @author    Ecom Experts <ecomyseo@gmail.com>
 * @copyright 2026 Ecom Experts
 * @license   Ver LICENSE.md
 *
 * @var array<int,array<string,mixed>> $tips entradas del blog: title, url, image, excerpt, published_at
 */

$tl_tips = isset($tips) && is_array($tips) ? $tips : [];
if ($tl_tips === []) {
    return;
}
?>
<section class="tl-tips af-container" aria-labelledby="tl-tips-title">
    <h2 class="tl-tips-title" id="tl-tips-title"><?= e(t('Tips and guides')) ?></h2>
    <div class="tl-tips-grid">
        <?php foreach ($tl_tips as $tl_tip) : ?>
            <article class="tl-tip">
                <?php if (isset($tl_tip['image']) && is_array($tl_tip['image']) && !empty($tl_tip['image']['url'])) : ?>
                    <a class="tl-tip-media" href="<?= e((string) $tl_tip['url']) ?>" tabindex="-1" aria-hidden="true"><?= image_tag($tl_tip['image'], 'card', ['alt' => '']) ?></a>
                <?php endif; ?>
                <div class="tl-tip-body">
                    <h3 class="tl-tip-title"><a href="<?= e((string) $tl_tip['url']) ?>"><?= e((string) $tl_tip['title']) ?></a></h3>
                    <?php if (isset($tl_tip['excerpt']) && (string) $tl_tip['excerpt'] !== '') : ?>
                        <p class="tl-tip-text"><?= e((string) $tl_tip['excerpt']) ?></p>
                    <?php endif; ?>
                </div>
            </article>
        <?php endforeach; ?>
    </div>
</section>
